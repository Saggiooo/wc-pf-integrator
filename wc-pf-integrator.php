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

// 3. Inizializzazione Plugin
function pf_init_plugin() {
    new PF_Importer();
    new PF_Pricing();
    new PF_Gateway();
    new PF_Frontend();
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