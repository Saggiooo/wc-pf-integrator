<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PF_Pricing {

    public function __construct() {
        // Ricalcola il prezzo nel carrello
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'calculate_cart_totals' ], 10, 1 );
    }

    /**
     * Logica principale per modificare il prezzo al volo
     */
    public function calculate_cart_totals( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

        $markup_print = (float) get_option( 'pf_print_markup', 1.00 );

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            
            // Verifica se l'utente ha selezionato una personalizzazione
            // (Questi dati devono arrivare dal frontend plugin, es. Product Add-ons)
            if ( ! empty( $cart_item['pf_customization'] ) ) {
                
                $product = $cart_item['data'];
                $qty = $cart_item['quantity'];
                
                $print_code = $cart_item['pf_customization']['print_code']; // Es. GPE02
                $colors = $cart_item['pf_customization']['colors']; // Es. 1
                
                // 1. Recupera costo stampa dal DB custom
                $print_cost_data = $this->get_print_cost( $print_code, $qty, $colors );
                
                if ( $print_cost_data ) {
                    $unit_print_cost = $print_cost_data->unit_price;
                    $setup_cost = $print_cost_data->setup_cost;

                    // 2. Calcola costo totale extra per unità
                    // (Setup cost va diviso per la quantità perché WC vuole il prezzo unitario)
                    $extra_cost_per_unit = $unit_print_cost + ( $setup_cost / $qty );
                    
                    // 3. Applica Markup Stampa
                    $extra_cost_per_unit = $extra_cost_per_unit * $markup_print;

                    // 4. Aggiorna prezzo prodotto
                    $original_price = $product->get_price();
                    $new_price = $original_price + $extra_cost_per_unit;
                    
                    $cart_item['data']->set_price( $new_price );
                }
            }
        }
    }

    /**
     * Interroga la tabella custom dei prezzi di stampa
     */
    private function get_print_cost( $code, $qty, $colors ) {
        global $wpdb;
        $table = $wpdb->prefix . 'pf_print_prices';
        
        // Cerca la fascia di prezzo corretta
        // Logica: Prendi la riga con quantity_start <= qty ordinata per quantity_start desc
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