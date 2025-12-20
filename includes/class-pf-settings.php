<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Sicurezza

class PF_Settings_Page {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    /**
     * 1. Aggiunge la voce di menu "PF Integration"
     */
    public function add_admin_menu() {
        add_menu_page(
            'PF Concept Config',        // Titolo Pagina
            'PF Integration',           // Titolo Menu
            'manage_options',           // Capacità richiesta
            'pf_integration_settings',  // Slug Menu
            [ $this, 'options_page_html' ], // Funzione di rendering
            'dashicons-cart',           // Icona
            56                          // Posizione
        );
    }

    /**
     * 2. Registra i campi nel database (wp_options)
     */
    public function register_settings() {
        // Sezione: Credenziali API Gateway
        register_setting( 'pf_integration_group', 'pf_api_username' );
        register_setting( 'pf_integration_group', 'pf_api_password' );
        register_setting( 'pf_integration_group', 'pf_sender_id' );
        register_setting( 'pf_integration_group', 'pf_environment' ); // Test o Live

        // Sezione: Codici Feed
        register_setting( 'pf_integration_group', 'pf_feed_unique_code' );

        // Sezione: Gestione Prezzi (Ricarichi)
        register_setting( 'pf_integration_group', 'pf_global_markup' );
        register_setting( 'pf_integration_group', 'pf_print_markup' );
        
        // Crea le sezioni visive
        add_settings_section(
            'pf_api_section',
            'Configurazione API Gateway',
            null,
            'pf_integration_settings'
        );

        add_settings_section(
            'pf_feed_section',
            'Configurazione Data Feeds',
            null,
            'pf_integration_settings'
        );

        add_settings_section(
            'pf_pricing_section',
            'Strategia di Prezzo (Markup)',
            null,
            'pf_integration_settings'
        );

        // --- CAMPI API ---
        add_settings_field(
            'pf_environment',
            'Ambiente',
            [ $this, 'render_select_env' ],
            'pf_integration_settings',
            'pf_api_section'
        );
        add_settings_field( 'pf_api_username', 'API Username', [ $this, 'render_text_field' ], 'pf_integration_settings', 'pf_api_section', ['id' => 'pf_api_username'] );
        add_settings_field( 'pf_api_password', 'API Password', [ $this, 'render_password_field' ], 'pf_integration_settings', 'pf_api_section', ['id' => 'pf_api_password'] );
        add_settings_field( 'pf_sender_id', 'Sender ID', [ $this, 'render_text_field' ], 'pf_integration_settings', 'pf_api_section', ['id' => 'pf_sender_id'] );

        // --- CAMPI FEED ---
        add_settings_field( 'pf_feed_unique_code', 'Unique Code (Feed URL)', [ $this, 'render_text_field' ], 'pf_integration_settings', 'pf_feed_section', ['id' => 'pf_feed_unique_code', 'desc' => 'Il codice presente nell\'URL del feed JSON.'] );

        // --- CAMPI PREZZI ---
        add_settings_field( 'pf_global_markup', 'Ricarico Prodotti Neutri', [ $this, 'render_markup_field' ], 'pf_integration_settings', 'pf_pricing_section', ['id' => 'pf_global_markup', 'desc' => 'Esempio: 1.40 per un ricarico del 40%.'] );
        add_settings_field( 'pf_print_markup', 'Ricarico Stampa', [ $this, 'render_markup_field' ], 'pf_integration_settings', 'pf_pricing_section', ['id' => 'pf_print_markup', 'desc' => 'Esempio: 1.20 per un ricarico del 20% sui costi di stampa.'] );
    }

    /**
     * 3. Funzioni di Rendering dei Campi HTML
     */
    public function render_text_field( $args ) {
        $option = get_option( $args['id'] );
        echo '<input type="text" name="' . $args['id'] . '" value="' . esc_attr( $option ) . '" class="regular-text" />';
        if( !empty($args['desc']) ) echo '<p class="description">' . $args['desc'] . '</p>';
    }

    public function render_password_field( $args ) {
        $option = get_option( $args['id'] );
        echo '<input type="password" name="' . $args['id'] . '" value="' . esc_attr( $option ) . '" class="regular-text" />';
    }

    public function render_markup_field( $args ) {
        $option = get_option( $args['id'], '1.00' ); // Default 1.00
        echo '<input type="number" step="0.01" min="1.00" name="' . $args['id'] . '" value="' . esc_attr( $option ) . '" class="small-text" />';
        if( !empty($args['desc']) ) echo '<p class="description">' . $args['desc'] . '</p>';
    }

    public function render_select_env() {
        $option = get_option( 'pf_environment', 'test' );
        ?>
        <select name="pf_environment">
            <option value="test" <?php selected( $option, 'test' ); ?>>Test (Sandbox)</option>
            <option value="live" <?php selected( $option, 'live' ); ?>>Produzione (Live)</option>
        </select>
        <?php
    }

    /**
     * 4. Rendering della Pagina HTML Completa
     */
    public function options_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        ?>
        <div class="wrap">
            <h1>Configurazione Integrazione PF Concept</h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'pf_integration_group' );
                do_settings_sections( 'pf_integration_settings' );
                submit_button( 'Salva Impostazioni' );
                ?>
            </form>
        </div>
        <?php
    }
}

// Inizializza la classe
if ( is_admin() ) {
    new PF_Settings_Page();
}