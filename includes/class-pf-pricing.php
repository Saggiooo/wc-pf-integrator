<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PF_Pricing {

    public function __construct() {
        // ✅ 1) Prezzo riga (merce + stampa unit.)
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'calculate_tiered_price' ], 9999, 1 );

        // ✅ 2) Impianto come fee una tantum (per batch)
        add_action( 'woocommerce_cart_calculate_fees', [ $this, 'add_setup_fee' ], 20, 1 );
    }

    public function calculate_tiered_price( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        if ( ! $cart || $cart->is_empty() ) return;

        $markup_global = (float) get_option( 'pf_global_markup', 1.00 );
        $markup_print  = (float) get_option( 'pf_print_markup', 1.00 );

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {

            if ( empty($cart_item['data']) || ! is_a($cart_item['data'], 'WC_Product') ) {
                continue;
            }

            /** @var WC_Product $product */
            $product = $cart_item['data'];
            $product_id = $product->get_id();
            $qty = (int) ($cart_item['quantity'] ?? 0);
            if ( $qty <= 0 ) continue;

            // -------------------------------------------------
            // 1) PREZZO BASE MERCE (SCAGLIONI) + MARKUP
            // -------------------------------------------------
            $scales = get_post_meta( $product_id, '_pf_price_scales', true );

            // ✅ Patch: fallback più safe (evita loop su get_price già modificato)
            $unit_price = (float) $product->get_regular_price();
            if ( $unit_price <= 0 ) $unit_price = (float) $product->get_price();

            if ( ! empty( $scales ) && is_array( $scales ) ) {
                // Assicuriamoci che gli scaglioni siano in ordine crescente
                ksort($scales);

                // Trova lo scaglione corretto
                foreach ( $scales as $tier_qty => $tier_price ) {
                    $tier_qty = (int) $tier_qty;
                    $tier_price = (float) $tier_price;
                    if ( $tier_qty > 0 && $qty >= $tier_qty ) {
                        $unit_price = $tier_price * $markup_global;
                    }
                }
            } else {
                // Se non ho scaglioni, applico markup globale al prezzo base se serve
                // (se il tuo prezzo Woo è già "vendita" con markup, metti 1.00 qui o rimuovi)
                $unit_price = $unit_price * $markup_global;
            }

            // -------------------------------------------------
            // 2) STAMPA: usa prima i dati già calcolati dal frontend (PF_Ajax)
            // -------------------------------------------------
            if ( ! empty($cart_item['pf_customization']) ) {
                $cust = $cart_item['pf_customization'];

                // ✅ Patch: trigger corretto (non pf_order_type)
                $print_unit_cost = isset($cust['print_unit_cost']) ? (float) $cust['print_unit_cost'] : 0;

                // Applico markup stampa (se nel frontend hai già applicato markup, metti pf_print_markup=1.00)
                if ( $print_unit_cost > 0 ) {
                    $unit_price += ( $print_unit_cost * $markup_print );
                } else {
                    // -------------------------------------------------
                    // 2b) Fallback: calcolo stampa da DB se non arriva dal frontend
                    // -------------------------------------------------
                    $print_code = isset($cust['print_code']) ? sanitize_text_field($cust['print_code']) : '';
                    $colors     = isset($cust['colors']) ? absint($cust['colors']) : 1;

                    if ( $print_code ) {
                        $print_cost_data = $this->get_print_cost_from_db( $print_code, $qty, $colors );

                        if ( $print_cost_data ) {
                            $print_unit_raw = (float) $print_cost_data->unit_price;
                            // ⚠️ setup NON qui: lo gestiamo come fee in add_setup_fee()
                            $unit_price += ( $print_unit_raw * $markup_print );
                        }
                    }
                }
            }

            // -------------------------------------------------
            // 3) SETTA PREZZO FINALE RIGA
            // -------------------------------------------------
            $product->set_price( $unit_price );
        }
    }

    /**
     * ✅ Impianto come fee una tantum per batch_id
     */
    public function add_setup_fee( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        if ( ! $cart || $cart->is_empty() ) return;

        $markup_print = (float) get_option( 'pf_print_markup', 1.00 );

        $batches_processed = [];

        foreach ( $cart->get_cart() as $cart_item ) {
            if ( empty($cart_item['pf_customization']) ) continue;

            $cust = $cart_item['pf_customization'];

            $batch_id = isset($cust['batch_id']) ? (string) $cust['batch_id'] : '';
            if ( $batch_id === '' ) continue;

            // Se già applicato, skip
            if ( isset($batches_processed[$batch_id]) ) continue;

            // 1) Se arriva dal frontend (PF_Ajax)
            $setup_total = isset($cust['setup_cost_total']) ? (float) $cust['setup_cost_total'] : 0;

            // 2) Fallback DB: se non c'è setup dal frontend, prova a calcolarlo dal DB
            if ( $setup_total <= 0 ) {
                $qty_total_batch = 0;

                // calcolo qty totale del batch (per scegliere lo scaglione giusto del setup nel DB)
                foreach ( $cart->get_cart() as $ci ) {
                    if ( empty($ci['pf_customization']) ) continue;
                    $c2 = $ci['pf_customization'];
                    if ( (string)($c2['batch_id'] ?? '') !== $batch_id ) continue;
                    $qty_total_batch += (int) ($ci['quantity'] ?? 0);
                }

                $print_code = isset($cust['print_code']) ? sanitize_text_field($cust['print_code']) : '';
                $colors     = isset($cust['colors']) ? absint($cust['colors']) : 1;

                if ( $print_code && $qty_total_batch > 0 ) {
                    $row = $this->get_print_cost_from_db( $print_code, $qty_total_batch, $colors );
                    if ( $row ) {
                        $setup_total = (float) $row->setup_cost;
                    }
                }
            }

            if ( $setup_total > 0 ) {
                // Applico markup stampa (se nel frontend hai già messo markup, lascia 1.00)
                $setup_total = $setup_total * $markup_print;

                $cart->add_fee( 'Costo Impianto Stampa', $setup_total, true );
                $batches_processed[$batch_id] = true;
            }
        }
    }

    /**
     * Cerca costo stampa in tabella custom con fallback colori
     */
    private function get_print_cost_from_db( $code, $qty, $colors ) {
        global $wpdb;
        $table = $wpdb->prefix . 'pf_print_prices';

        $code = (string) $code;
        $qty = (int) $qty;
        $colors = (int) $colors;

        if ( $code === '' || $qty <= 0 ) return null;

        // 1) Tentativo esatto
        $sql = $wpdb->prepare(
            "SELECT unit_price, setup_cost
             FROM $table
             WHERE print_code = %s
               AND colors_count = %d
               AND quantity_start <= %d
             ORDER BY quantity_start DESC
             LIMIT 1",
            $code, $colors, $qty
        );
        $row = $wpdb->get_row( $sql );
        if ( $row ) return $row;

        // 2) Fallback 4 colori (full color)
        $sql = $wpdb->prepare(
            "SELECT unit_price, setup_cost
             FROM $table
             WHERE print_code = %s
               AND colors_count = 4
               AND quantity_start <= %d
             ORDER BY quantity_start DESC
             LIMIT 1",
            $code, $qty
        );
        $row = $wpdb->get_row( $sql );
        if ( $row ) return $row;

        // 3) Fallback 0 colori (laser ecc.)
        $sql = $wpdb->prepare(
            "SELECT unit_price, setup_cost
             FROM $table
             WHERE print_code = %s
               AND colors_count = 0
               AND quantity_start <= %d
             ORDER BY quantity_start DESC
             LIMIT 1",
            $code, $qty
        );
        return $wpdb->get_row( $sql );
    }
}
