<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PF_Ajax {

    public function __construct() {
        // LOG di bootstrap
        $this->log('PF_Ajax __construct ✅', [
            'doing_ajax' => (defined('DOING_AJAX') && DOING_AJAX),
        ]);

        // ✅ HOOK AJAX: gestiscono la richiesta dal frontend
        add_action( 'wp_ajax_pf_bulk_add_to_cart',        [ $this, 'bulk_add_to_cart' ] );
        add_action( 'wp_ajax_nopriv_pf_bulk_add_to_cart', [ $this, 'bulk_add_to_cart' ] );

        // Endpoint per il calcolo prezzi (se non gestito altrove)
        add_action( 'wp_ajax_pf_calculate_price_ajax',    'pf_calculate_price_ajax' );
        add_action( 'wp_ajax_nopriv_pf_calculate_price_ajax', 'pf_calculate_price_ajax' );
    }

    private function log( $msg, $context = [] ) {
        $line = '[PF] ' . $msg . ' ' . wp_json_encode($context);
        
        // 1) wp-content/debug.log
        if ( defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ) {
            error_log($line);
        }

        // 2) Woo logger (più pulito e persistente)
        if ( function_exists('wc_get_logger') ) {
            try {
                wc_get_logger()->info( $msg . ' ' . wp_json_encode($context), ['source' => 'pf-ajax'] );
            } catch (Exception $e) {
                // Silenzioso
            }
        }
    }

    public function bulk_add_to_cart() {
        $this->log('🔥 bulk_add_to_cart START', ['post' => $_POST]);

        // 1. Controllo base WooCommerce
        if ( ! function_exists('WC') || ! WC()->cart ) {
            $this->log('❌ WC cart missing');
            wp_send_json_error('WooCommerce cart non disponibile');
        }

        // 2. Verifica Nonce di sicurezza
        $nonce = isset($_POST['security']) ? sanitize_text_field($_POST['security']) : '';
        if ( ! wp_verify_nonce( $nonce, 'pf_calc_nonce' ) ) {
            $this->log('❌ Nonce fail', ['security' => $nonce]);
            wp_send_json_error('Sessione scaduta, ricarica la pagina.');
        }

        // 3. Decodifica Items e Dati Stampa
        // Supporta sia items[] form data standard che items_json string
        $items = [];
        if ( isset($_POST['items_json']) ) {
            $items = json_decode( stripslashes($_POST['items_json']), true );
        } elseif ( isset($_POST['items']) && is_array($_POST['items']) ) {
            $items = $_POST['items'];
        }

        $print_data = [];
        if ( isset($_POST['print_data']) ) {
            // Se inviato come JSON string
            if ( is_string($_POST['print_data']) ) {
                $print_data = json_decode( stripslashes($_POST['print_data']), true );
            } else {
                $print_data = $_POST['print_data'];
            }
        }

        if ( empty($items) || ! is_array($items) ) {
            wp_send_json_error('Nessun articolo selezionato');
        }

        


        // 5. Preparazione Dati Stampa
        $print_code = isset($print_data['code']) ? sanitize_text_field($print_data['code']) : '';
        
        // ✅ LIMITE FILE (MB)
        $max_mb = 8; // scegli tu: 5 / 8 / 10
        $max_bytes = $max_mb * 1024 * 1024;

        // ✅ SE C'È STAMPA, IL FILE È OBBLIGATORIO
        if ( ! empty($print_code) ) {
            if ( empty($_FILES['pf_logo_file']) || empty($_FILES['pf_logo_file']['name']) ) {
                wp_send_json_error('Aggiungi il file di stampa (logo) prima di procedere.');
            }
        }

        // 4. Gestione Upload File Logo (se presente)
        $file_url = '';
        if ( ! empty($_FILES['pf_logo_file']) && ! empty($_FILES['pf_logo_file']['name']) ) {

            // ✅ CHECK DIMENSIONE
            $size = isset($_FILES['pf_logo_file']['size']) ? (int) $_FILES['pf_logo_file']['size'] : 0;
            if ( $size > $max_bytes ) {
                wp_send_json_error('File troppo grande. Dimensione massima: ' . $max_mb . 'MB.');
            }

            // ✅ CHECK ESTENSIONE (sicurezza server-side)
            $allowed = ['pdf','ai','eps','png','jpg','jpeg'];
            $ext = strtolower( pathinfo($_FILES['pf_logo_file']['name'], PATHINFO_EXTENSION) );
            if ( ! in_array($ext, $allowed, true) ) {
                wp_send_json_error('Formato file non valido. Usa PDF, AI, EPS, PNG, JPG.');
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $upload_overrides = ['test_form' => false];
            $movefile = wp_handle_upload( $_FILES['pf_logo_file'], $upload_overrides );

            if ( $movefile && ! isset( $movefile['error'] ) ) {
                $file_url = $movefile['url'];
                $this->log('File uploaded', ['url' => $file_url]);
            } else {
                $this->log('Upload error', ['error' => $movefile['error']]);
                wp_send_json_error('Errore caricamento file: ' . $movefile['error']);
            }
        }

        // ID univoco per questo "batch" di aggiunta al carrello.
        // Serve per applicare il costo impianto una volta sola per tutto il gruppo.
        $batch_id = uniqid('pf_batch_');

        $added_count = 0;
        $errors = [];

        // 6. Loop Aggiunta Prodotti
        foreach ( $items as $item ) {
            $variation_id = isset($item['variation_id']) ? absint($item['variation_id']) : 0;
            $qty          = isset($item['quantity']) ? absint($item['quantity']) : 0;

            if ( $variation_id <= 0 || $qty <= 0 ) continue;

            // Recupera prodotto
            $product = wc_get_product( $variation_id );
            if ( ! $product ) {
                $this->log('❌ Product not found', ['id' => $variation_id]);
                continue;
            }
            
            $parent_id = $product->get_parent_id();
            
            // Attributi variazione (necessari per add_to_cart su variazioni)
            $variation_attrs = wc_get_product_variation_attributes( $variation_id );

            // Preparazione Meta Data Carrello
            $cart_item_data = [];

            // Se c'è un codice stampa, salviamo tutti i dati necessari per il prezzo
            if ( ! empty( $print_code ) ) {
                $cart_item_data['pf_customization'] = [
                    'batch_id'      => $batch_id,
                    'print_code'    => $print_code,
                    'colors'        => isset($print_data['colors']) ? intval($print_data['colors']) : 1,
                    'config_id'     => isset($print_data['config_id']) ? sanitize_text_field($print_data['config_id']) : '',
                    'file_url'      => $file_url,
                    
                    // PREZZI (Cruciali per il ricalcolo nel carrello)
                    // unit_print_price: Costo stampa unitario (es. 0.354€)
                    'print_unit_cost' => isset($print_data['unit_print_price']) ? floatval($print_data['unit_print_price']) : 0,
                    
                    // setup_total: Costo impianto totale (es. 20.00€)
                    'setup_cost_total'=> isset($print_data['setup_total']) ? floatval($print_data['setup_total']) : 0
                ];

                // Forza chiave unica per separare questi item da altri uguali ma senza stampa
                $cart_item_data['unique_key'] = md5( $batch_id . $variation_id . $print_code );
            }

            // 7. Aggiungi effettivamente al carrello
            try {
                $cart_key = WC()->cart->add_to_cart( 
                    $parent_id, 
                    $qty, 
                    $variation_id, 
                    $variation_attrs, 
                    $cart_item_data 
                );

                if ( $cart_key ) {
                    $added_count++;
                } else {
                    $errors[] = "Errore generico Woo su ID $variation_id";
                    $this->log('❌ add_to_cart fail', ['id' => $variation_id]);
                }
            } catch ( Exception $e ) {
                $errors[] = $e->getMessage();
                $this->log('❌ add_to_cart exception', ['msg' => $e->getMessage()]);
            }
        }

        // 8. Risposta Finale
        if ( $added_count > 0 ) {
            // Forza ricalcolo totali
            WC()->cart->calculate_totals();
            
            $this->log('✅ Bulk add success', ['count' => $added_count]);
            
            wp_send_json_success([ 
                'message' => "$added_count prodotti aggiunti al carrello",
                'cart_count' => WC()->cart->get_cart_contents_count(),
                'redirect' => wc_get_cart_url() // Opzionale: redirect al carrello
            ]);
        } else {
            $msg = !empty($errors) ? implode(', ', $errors) : 'Nessun prodotto aggiunto.';
            wp_send_json_error( $msg );
        }
    }
}