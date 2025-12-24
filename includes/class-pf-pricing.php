<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PF_Pricing {

    public function __construct() {
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'calculate_tiered_price' ], 10, 1 );
    }

    public function calculate_tiered_price( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

        $markup_global = (float) get_option( 'pf_global_markup', 1.00 );
        $markup_print = (float) get_option( 'pf_print_markup', 1.00 );

        foreach ( $cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'];
            $product_id = $product->get_id();
            $qty = $cart_item['quantity'];
            
            // 1. CALCOLO PREZZO BASE (SCAGLIONI)
            $scales = get_post_meta( $product_id, '_pf_price_scales', true );
            $unit_price = (float) $product->get_price(); // Fallback

            if ( ! empty( $scales ) && is_array( $scales ) ) {
                // Trova lo scaglione corretto
                // Es: Scaglioni [1=>10€, 100=>8€]. Se Qty=50, prende 1. Se Qty=150, prende 100.
                foreach ( $scales as $tier_qty => $tier_price ) {
                    if ( $qty >= $tier_qty ) {
                        $unit_price = $tier_price * $markup_global; // Applica il tuo ricarico
                    }
                }
            }

            // 2. CALCOLO STAMPA (SE PERSONALIZZATO)
            if ( isset($cart_item['pf_order_type']) && $cart_item['pf_order_type'] === 'decorated' && !empty($cart_item['pf_customization']) ) {
                
                $print_code = $cart_item['pf_customization']['print_code'];
                $colors = $cart_item['pf_customization']['colors'];
                
                // Chiama la funzione di calcolo stampa (che legge dalla tabella DB custom)
                $print_cost_data = $this->get_print_cost_from_db( $print_code, $qty, $colors );
                
                if ( $print_cost_data ) {
                    $print_unit_raw = $print_cost_data->unit_price;
                    $setup_total = $print_cost_data->setup_cost;
                    
                    // Costo unitario stampa + (Setup / Quantità)
                    $extra_cost = $print_unit_raw + ( $setup_total / $qty );
                    
                    // Applica ricarico stampa
                    $unit_price += ( $extra_cost * $markup_print );
                }
            }

            // 3. SETTA IL PREZZO FINALE
            $cart_item['data']->set_price( $unit_price );
        }
    }

    private function get_print_cost_from_db( $code, $qty, $colors ) {
        global $wpdb;
        $table = $wpdb->prefix . 'pf_print_prices';
        
        // Logica SQL: Prendi la riga con quantity_start <= qty, ordinata desc
        $sql = $wpdb->prepare( "
            SELECT unit_price, setup_cost 
            FROM $table 
            WHERE print_code = %s 
            AND colors_count = %d 
            AND quantity_start <= %d 
            ORDER BY quantity_start DESC 
            LIMIT 1
        ", $code, $colors, $qty );

        return $wpdb->get_row( $sql );
    }
}