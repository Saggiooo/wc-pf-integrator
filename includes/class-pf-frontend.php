<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PF_Frontend {

    public function __construct() {
        // Aggancia la funzione al pulsante "Aggiungi al carrello"
        // Questo hook funziona anche con il widget "Add to Cart" di Elementor
        add_action( 'woocommerce_before_add_to_cart_button', [ $this, 'render_print_options_field' ] );
        
        // Aggiungiamo anche i dati personalizzati al carrello quando si clicca "Acquista"
        add_filter( 'woocommerce_add_cart_item_data', [ $this, 'add_custom_data_to_cart' ], 10, 3 );
    }

    /**
     * 1. Mostra i campi nella pagina prodotto
     */
    public function render_print_options_field() {
        // Recupera i codici stampa disponibili dal DB (opzionale, per ora statico come il tuo esempio)
        // In futuro qui faremo una query: SELECT DISTINCT print_code FROM wp_pf_print_prices...
        
        echo '<div class="pf-options-box" style="margin-bottom:20px; padding:15px; background:#f9f9f9; border:1px solid #ddd; border-radius:5px;">';
        echo '<h4 style="margin-top:0;">Personalizzazione PF</h4>';
        
        // Select Codice Stampa
        echo '<p style="margin-bottom:10px;">';
        echo '<label style="display:block; font-weight:bold;">Tecnica Stampa:</label>';
        echo '<select id="pf_print_code" name="pf_print_option[print_code]" class="pf-print-option" style="width:100%;">';
        echo '<option value="">Nessuna Stampa (Neutro)</option>';
        
        // ESEMPIO: Qui potresti fare un ciclo PHP sui codici disponibili
        echo '<option value="GPE02">Serigrafia (GPE02)</option>';
        echo '<option value="LAS02">Incisione Laser (LAS02)</option>';
        echo '<option value="T1">Tampografia (T1)</option>';
        
        echo '</select>';
        echo '</p>';
        
        // Select Colori
        echo '<p style="margin-bottom:0;">';
        echo '<label style="display:block; font-weight:bold;">Numero Colori:</label>';
        echo '<input type="number" id="pf_print_colors" name="pf_print_option[colors]" value="0" min="0" max="4" class="pf-print-option" style="width:100%;">';
        echo '</p>';
        
        echo '</div>';
    }

    /**
     * 2. Salva le scelte dell'utente nel carrello
     * Fondamentale: senza questo, quando clicchi "Acquista", le scelte vengono perse!
     */
    public function add_custom_data_to_cart( $cart_item_data, $product_id, $variation_id ) {
        if ( isset( $_POST['pf_print_option'] ) ) {
            $options = $_POST['pf_print_option'];
            
            if ( ! empty( $options['print_code'] ) ) {
                $cart_item_data['pf_customization'] = [
                    'print_code' => sanitize_text_field( $options['print_code'] ),
                    'colors'     => intval( $options['colors'] )
                ];
                
                // Opzionale: Rende univoco l'item nel carrello (evita che si sommi ai neutri)
                $cart_item_data['unique_key'] = md5( microtime() . rand() );
            }
        }
        return $cart_item_data;
    }
}