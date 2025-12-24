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

// 3. Inizializzazione Plugin
function pf_init_plugin() {
    new PF_Importer();
    new PF_Pricing();
    new PF_Gateway();
    new PF_Frontend();
    new PF_Webhook();
    new PF_Product_Creator();
    // Settings si auto-inizializza se is_admin()
}
add_action( 'plugins_loaded', 'pf_init_plugin' );

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
}

// 2. Gestore Calcolo AJAX
add_action( 'wp_ajax_pf_calculate_price_ajax', 'pf_handle_ajax_calculation' );
add_action( 'wp_ajax_nopriv_pf_calculate_price_ajax', 'pf_handle_ajax_calculation' );

function pf_handle_ajax_calculation() {
    check_ajax_referer( 'pf_calc_nonce', 'security' );

    $product_id = intval( $_POST['product_id'] );
    $qty = intval( $_POST['quantity'] );
    $print_code = sanitize_text_field( $_POST['print_code'] );
    $colors = intval( $_POST['print_colors'] );

    $product = wc_get_product( $product_id );
    if ( ! $product ) wp_send_json_error( 'Prodotto non trovato' );

    // A. Prezzo Base (con ricarico già applicato nell'import)
    $base_price = (float) $product->get_price();
    $total_unit_price = $base_price;
    $breakdown = [];

    // B. Calcolo Stampa (se selezionata)
    if ( ! empty( $print_code ) && $colors > 0 ) {
        // Usiamo la logica della classe Pricing (istanziamola al volo o rendiamo il metodo statico)
        // Per semplicità, replico la query qui o chiamo un metodo helper
        global $wpdb;
        $table = $wpdb->prefix . 'pf_print_prices';
        
        $row = $wpdb->get_row( $wpdb->prepare( "
            SELECT unit_price, setup_cost 
            FROM $table 
            WHERE print_code = %s 
            AND colors_count = %d 
            AND quantity_start <= %d 
            ORDER BY quantity_start DESC 
            LIMIT 1
        ", $print_code, $colors, $qty ) );

        if ( $row ) {
            $markup_print = (float) get_option( 'pf_print_markup', 1.00 );
            
            $print_unit_cost = (float) $row->unit_price;
            $setup_cost_total = (float) $row->setup_cost;
            
            // Spalma il costo impianto sulla quantità
            $setup_per_unit = $setup_cost_total / $qty;
            
            // Totale stampa per unità (con ricarico)
            $print_total_unit = ($print_unit_cost + $setup_per_unit) * $markup_print;
            
            $total_unit_price += $print_total_unit;
            
            $breakdown[] = "Stampa: " . wc_price($print_total_unit * $qty);
        }
    }

    // C. Calcolo Totale
    $total_price = $total_unit_price * $qty;
    
    // Risposta JSON
    wp_send_json_success([
        'html' => wc_price( $total_price ), // Prezzo formattato WooCommerce
        'unit_html' => wc_price( $total_unit_price ),
        'breakdown_html' => implode('<br>', $breakdown)
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