<?php
/**
 * Plugin Name: WC PF Concept Integrator
 * Description: Soluzione completa per sincronizzare Feed e Ordini con PF Concept.
 * Version: 1.0.0
 * Author: Tuo Nome
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Definizioni Costanti
define( 'PF_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'PF_TABLE_PRINT_PRICES', $wpdb->prefix . 'pf_print_prices' );

// 1. Installazione DB all'attivazione
register_activation_hook( __FILE__, 'pf_install_database' );

function pf_install_database() {
    global $wpdb;
    $table_name = PF_TABLE_PRINT_PRICES;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        print_code varchar(20) NOT NULL,
        quantity_start int(11) NOT NULL,
        colors_count int(2) NOT NULL,
        unit_price decimal(10,4) NOT NULL,
        setup_cost decimal(10,2) NOT NULL,
        PRIMARY KEY  (id),
        KEY print_code (print_code)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

// 2. Caricamento Classi
require_once PF_PLUGIN_PATH . 'includes/class-pf-settings.php';
require_once PF_PLUGIN_PATH . 'includes/class-pf-importer.php';
require_once PF_PLUGIN_PATH . 'includes/class-pf-pricing.php';
require_once PF_PLUGIN_PATH . 'includes/class-pf-gateway.php';
require_once PF_PLUGIN_PATH . 'includes/class-pf-frontend.php';
require_once PF_PLUGIN_PATH . 'includes/class-pf-webhook.php';
require_once PF_PLUGIN_PATH . 'includes/class-pf-product-creator.php';
require_once PF_PLUGIN_PATH . 'includes/class-pf-ajax.php';

// 3. Inizializzazione Plugin
function pf_init_plugin() {
    new PF_Importer();
    new PF_Pricing();
    new PF_Gateway();
    new PF_Frontend();
    new PF_Webhook();
    new PF_Product_Creator();
    new PF_Ajax();

    // Settings si auto-inizializza se is_admin()
}
add_action( 'plugins_loaded', 'pf_init_plugin' );

/*
// 1. Caricamento Script Frontend
add_action( 'wp_enqueue_scripts', 'pf_enqueue_frontend_scripts' );

function pf_enqueue_frontend_scripts() {
    if ( ! is_product() ) return;

    wp_enqueue_script( 'pf-frontend-calc', plugins_url( 'assets/js/frontend-calc.js', __FILE__ ), ['jquery'], '1.0', true );

    // Passiamo variabili PHP a JS (URL Ajax e Nonce di sicurezza)
    wp_localize_script( 'pf-frontend-calc', 'pf_ajax_obj', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'pf_calc_nonce' )
    ]);
} */

// 2. Gestore Calcolo AJAX
// NOTA: Mantieni 'pf_calculate_price_ajax' come nome dell'azione se il tuo JS chiama quella action.
add_action( 'wp_ajax_pf_calculate_price_ajax', 'pf_calculate_price_ajax' ); 
add_action( 'wp_ajax_nopriv_pf_calculate_price_ajax', 'pf_calculate_price_ajax' );

function pf_calculate_price_ajax() { // Ho rinominato la funzione per coerenza con il tuo codice desiderato
    check_ajax_referer( 'pf_calc_nonce', 'security' );

    $qty = isset($_POST['quantity']) ? absint($_POST['quantity']) : 0;
    $code = isset($_POST['print_code']) ? sanitize_text_field($_POST['print_code']) : '';
    $colors = isset($_POST['print_colors']) ? absint($_POST['print_colors']) : 1;

    // Se i dati sono invalidi, restituisci 0
    if ( $qty <= 0 || empty($code) ) {
        wp_send_json_success([ 'print_total' => 0, 'setup_total' => 0 ]);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'pf_print_prices';

    // 1. TENTATIVO ESATTO (Es. cerco 1 colore)
    // Questa logica segue il manuale Print Price Feed che definisce setup e costi unitari [cite: 782, 803]
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

    // 2. FALLBACK A 4 COLORI (Es. Stampa Digitale spesso è Full Color)
    if ( ! $row ) {
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
    }

    // 3. FALLBACK A 0 COLORI (Es. Incisione/Laser che non dipendono dai colori [cite: 791])
    if ( ! $row ) {
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
        $row = $wpdb->get_row( $sql );
    }

    // Se ancora non trovo nulla, restituisco 0
    if ( ! $row ) {
        wp_send_json_success([ 'print_total' => 0, 'setup_total' => 0 ]);
    }

    // Calcoli con Markup
    // Il manuale Gateway specifica che i prezzi nel feed sono netti[cite: 752], quindi il markup è necessario lato WP.
    $markup = (float) get_option( 'pf_print_markup', 1.00 ); // Usa l'opzione che avevi nel codice originale o 'pf_global_markup'
    
    $net_print_unit = (float) $row->unit_price;
    $net_setup_cost = (float) $row->setup_cost;

    // Applica markup
    $gross_print_unit = $net_print_unit * $markup;
    $gross_setup_cost = $net_setup_cost * $markup;

    // Totale stampa = (Costo unitario lordo * quantità)
    $print_total = $gross_print_unit * $qty;
    
    // Totale setup = Costo impianto lordo (una tantum per ordine/grafica)
    $setup_total = $gross_setup_cost;

    // Restituisco JSON con numeri puri per il JS
    wp_send_json_success([
        'print_total' => number_format($print_total, 2, '.', ''), 
        'setup_total' => number_format($setup_total, 2, '.', '')
    ]);
}



// --- AREA TEST: DA RIMUOVERE IN PRODUZIONE ---

add_action( 'init', 'pf_inject_test_data' );

function pf_inject_test_data() {
    // Esegui solo se c'è il parametro nell'URL
    if ( ! isset( $_GET['setup_test_data'] ) ) return;

    // 1. Trova il prodotto Agenda tramite SKU
    $sku_da_cercare = '10624601'; 
    $product_id = wc_get_product_id_by_sku( $sku_da_cercare );

    if ( ! $product_id ) {
        wp_die( "Errore: Nessun prodotto trovato con SKU $sku_da_cercare. Controlla di averlo inserito correttamente in WooCommerce." );
    }

    // A. LISTINO PREZZI ARTICOLO (Tiered Pricing)
    $fake_scales = [
        1    => 5.50,  // Base
        30   => 5.00,  // Sconto 1
        100  => 4.50,  // Sconto 2
        500  => 4.00,  // Sconto 3
    ];
    update_post_meta( $product_id, '_pf_price_scales', $fake_scales );
    update_post_meta( $product_id, '_pf_net_price', 5.50 );

    // B. REGOLE DI STAMPA (Fondamentale per il nuovo Frontend!)
    // Questo dice al sito: "Su questo prodotto puoi fare Serigrafia sul Fronte o sul Retro"
    $fake_print_options = [
        'GPE02' => [
            'name' => 'Serigrafia (Test)',
            'locations' => [
                [ 'name' => 'Fronte Copertina', 'config_id' => '6-front' ],
                [ 'name' => 'Retro Copertina', 'config_id' => '6-back' ]
            ]
        ],
        'LAS02' => [
            'name' => 'Incisione Laser',
            'locations' => [
                [ 'name' => 'Su targhetta metallica', 'config_id' => '7-plate' ]
            ]
        ]
    ];
    update_post_meta( $product_id, '_pf_print_options', $fake_print_options );

    // C. PREZZI DI STAMPA (Nel Database Custom)
    global $wpdb;
    $table_print = $wpdb->prefix . 'pf_print_prices';
    
    // Svuota tabella per pulizia
    $wpdb->query("TRUNCATE TABLE $table_print");

    // Inseriamo prezzi per SERIGRAFIA (GPE02)
    // 1 Colore
    $wpdb->insert($table_print, ['print_code' => 'GPE02', 'quantity_start' => 1,   'colors_count' => 1, 'unit_price' => 0.60, 'setup_cost' => 45.00]);
    $wpdb->insert($table_print, ['print_code' => 'GPE02', 'quantity_start' => 50,  'colors_count' => 1, 'unit_price' => 0.50, 'setup_cost' => 45.00]);
    $wpdb->insert($table_print, ['print_code' => 'GPE02', 'quantity_start' => 100, 'colors_count' => 1, 'unit_price' => 0.40, 'setup_cost' => 45.00]);
    
    // 2 Colori (più costoso)
    $wpdb->insert($table_print, ['print_code' => 'GPE02', 'quantity_start' => 1,   'colors_count' => 2, 'unit_price' => 0.90, 'setup_cost' => 90.00]);
    
    // Inseriamo prezzi per LASER (LAS02)
    $wpdb->insert($table_print, ['print_code' => 'LAS02', 'quantity_start' => 1,   'colors_count' => 1, 'unit_price' => 0.80, 'setup_cost' => 50.00]);

    // Conferma a video
    wp_die( "✅ DATI TEST INSERITI!<br>Prodotto ID: $product_id ($sku_da_cercare)<br>- Prezzi a scalare inseriti<br>- Opzioni Stampa (Serigrafia/Laser) inserite<br>- Costi stampa inseriti nel DB.<br><br><b>Ora vai sulla pagina prodotto e ricarica (F5).</b>" );
}

/*
// Gestore AJAX Bulk Add to Cart
add_action( 'wp_ajax_pf_bulk_add_to_cart', 'pf_handle_bulk_add_cart' );
add_action( 'wp_ajax_nopriv_pf_bulk_add_to_cart', 'pf_handle_bulk_add_cart' );

function pf_handle_bulk_add_cart() {
    check_ajax_referer( 'pf_calc_nonce', 'security' );
    
    // Decodifica items dal JSON inviato via FormData
    $items = isset($_POST['items_json']) ? json_decode(stripslashes($_POST['items_json']), true) : [];
    $print_data = $_POST['print_data'] ?? [];
    
    if ( empty( $items ) || ! is_array( $items ) ) {
        wp_send_json_error( 'Nessun articolo selezionato' );
    }

    // GESTIONE UPLOAD FILE
    $file_url = '';
    if ( ! empty( $_FILES['pf_logo_file'] ) ) {
        $uploaded = $_FILES['pf_logo_file'];
        
        // Controlli sicurezza base
        $allowed = ['pdf','ai','eps','png','jpg','jpeg'];
        $ext = pathinfo($uploaded['name'], PATHINFO_EXTENSION);
        if(!in_array(strtolower($ext), $allowed)) {
            wp_send_json_error('Formato file non valido. Usa PDF, AI, o PNG.');
        }

        // Usa le funzioni WP per l'upload sicuro
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        $upload_overrides = [ 'test_form' => false ];
        $movefile = wp_handle_upload( $uploaded, $upload_overrides );

        if ( $movefile && ! isset( $movefile['error'] ) ) {
            $file_url = $movefile['url']; // URL pubblico del file
        } else {
            wp_send_json_error( 'Errore caricamento file: ' . $movefile['error'] );
        }
    } 

    // AGGIUNTA AL CARRELLO
    foreach ( $items as $item ) {
        $var_id = intval( $item['variation_id'] );
        $qty = intval( $item['quantity'] );
        $product = wc_get_product( $var_id );
        
        if ( ! $product ) continue;
        
        // Prepara i meta custom
        $cart_item_data = [];
        if ( ! empty( $print_data['code'] ) ) {
            $cart_item_data['pf_customization'] = [
                'print_code' => sanitize_text_field( $print_data['code'] ),
                'config_id'  => sanitize_text_field( $print_data['config_id'] ),
                'colors'     => intval( $print_data['colors'] ),
                'file_url'   => $file_url // Salviamo l'URL del file caricato
            ];
            // Unique key per evitare raggruppamenti indesiderati
            $cart_item_data['unique_key'] = md5( microtime() . rand() );
        }

        WC()->cart->add_to_cart( $product->get_parent_id(), $qty, $var_id, [], $cart_item_data );
    }

    wp_send_json_success();
}
*/

/**
 * 1. MODIFICA PREZZO PRODOTTO (Merce + Costo Stampa Unitario)
 * Questo hook intercetta il carrello prima del calcolo dei totali
 */
add_action( 'woocommerce_before_calculate_totals', 'pf_apply_custom_price', 10, 1 );

function pf_apply_custom_price( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

    foreach ( $cart->get_cart() as $cart_item ) {
        // Verifichiamo se l'articolo ha i dati di personalizzazione PF
        if ( isset( $cart_item['pf_customization'] ) ) {
            
            // Recuperiamo il costo stampa UNITARIO salvato da PF_Ajax
            // Nota: in PF_Ajax l'abbiamo salvato come 'print_unit_cost'
            $print_unit_cost = isset($cart_item['pf_customization']['print_unit_cost']) 
                ? (float) $cart_item['pf_customization']['print_unit_cost'] 
                : 0;

            if ( $print_unit_cost > 0 ) {
                $product = $cart_item['data'];
                
                // Prezzo Base originale del prodotto
                $base_price = (float) $product->get_price();
                
                // Nuovo Prezzo = Base + Stampa
                $new_price = $base_price + $print_unit_cost;
                
                // Impostiamo il nuovo prezzo per questo calcolo
                $product->set_price( $new_price );
            }
        }
    }
}

/**
 * 2. AGGIUNGI COSTO IMPIANTO (Fee Globale)
 * Questo hook aggiunge una riga extra nel totale carrello per l'impianto
 */
add_action( 'woocommerce_cart_calculate_fees', 'pf_add_setup_fees', 20, 1 );

function pf_add_setup_fees( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    if ( ! $cart || $cart->is_empty() ) return;

    $batches_processed = [];

    foreach ( $cart->get_cart() as $cart_item ) {
        if ( empty($cart_item['pf_customization']) ) continue;

        $data = $cart_item['pf_customization'];
        $batch_id   = isset($data['batch_id']) ? (string) $data['batch_id'] : '';
        $setup_cost = isset($data['setup_cost_total']) ? (float) $data['setup_cost_total'] : 0;

        if ( $setup_cost <= 0 || $batch_id === '' ) continue;
        if ( isset($batches_processed[$batch_id]) ) continue;

        $cart->add_fee( 'Costo Impianto Stampa', $setup_cost, true );
        $batches_processed[$batch_id] = true;
    }
}


/**
 * 3. MOSTRA DETTAGLI NEL CARRELLO (Opzionale ma utile per debug)
 * Mostra "Codice Stampa: GPE02" sotto il nome del prodotto
 **/
/*
add_filter( 'woocommerce_get_item_data', 'pf_display_cart_meta', 10, 2 );

function pf_display_cart_meta( $item_data, $cart_item ) {
    if ( isset( $cart_item['pf_customization'] ) ) {
        $data = $cart_item['pf_customization'];
        
        if ( ! empty($data['print_code']) ) {
            $item_data[] = [
                'key'     => 'Stampa',
                'value'   => $data['print_code'] . ' (' . $data['colors'] . ' colori)',
            ];
        }
    }
    return $item_data;
}
    */
    