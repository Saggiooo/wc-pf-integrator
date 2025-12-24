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
            'PF Concept Config',        
            'PF Integration',           
            'manage_options',           
            'pf_integration_settings',  
            [ $this, 'options_page_html' ], 
            'dashicons-cart',           
            56                          
        );
    }

    /**
     * 2. Registra i campi nel database (wp_options)
     */
    public function register_settings() {
        // --- (Questa parte resta UGUALE al tuo codice originale) ---
        
        // Sezione: Credenziali API Gateway
        register_setting( 'pf_integration_group', 'pf_api_username' );
        register_setting( 'pf_integration_group', 'pf_api_password' );
        register_setting( 'pf_integration_group', 'pf_sender_id' );
        register_setting( 'pf_integration_group', 'pf_environment' );

        // Sezione: Codici Feed
        register_setting( 'pf_integration_group', 'pf_feed_unique_code' );

        // Sezione: Gestione Prezzi
        register_setting( 'pf_integration_group', 'pf_global_markup' );
        register_setting( 'pf_integration_group', 'pf_print_markup' );
        
        // Sezioni
        add_settings_section( 'pf_api_section', 'Configurazione API Gateway', null, 'pf_integration_settings' );
        add_settings_section( 'pf_feed_section', 'Configurazione Data Feeds', null, 'pf_integration_settings' );
        add_settings_section( 'pf_pricing_section', 'Strategia di Prezzo (Markup)', null, 'pf_integration_settings' );

        // Campi
        add_settings_field( 'pf_environment', 'Ambiente', [ $this, 'render_select_env' ], 'pf_integration_settings', 'pf_api_section' );
        add_settings_field( 'pf_api_username', 'API Username', [ $this, 'render_text_field' ], 'pf_integration_settings', 'pf_api_section', ['id' => 'pf_api_username'] );
        add_settings_field( 'pf_api_password', 'API Password', [ $this, 'render_password_field' ], 'pf_integration_settings', 'pf_api_section', ['id' => 'pf_api_password'] );
        add_settings_field( 'pf_sender_id', 'Sender ID', [ $this, 'render_text_field' ], 'pf_integration_settings', 'pf_api_section', ['id' => 'pf_sender_id'] );

        add_settings_field( 'pf_feed_unique_code', 'Unique Code (Feed URL)', [ $this, 'render_text_field' ], 'pf_integration_settings', 'pf_feed_section', ['id' => 'pf_feed_unique_code', 'desc' => 'Il codice presente nell\'URL del feed JSON.'] );

        add_settings_field( 'pf_global_markup', 'Ricarico Prodotti Neutri', [ $this, 'render_markup_field' ], 'pf_integration_settings', 'pf_pricing_section', ['id' => 'pf_global_markup', 'desc' => 'Esempio: 1.40 per un ricarico del 40%.'] );
        add_settings_field( 'pf_print_markup', 'Ricarico Stampa', [ $this, 'render_markup_field' ], 'pf_integration_settings', 'pf_pricing_section', ['id' => 'pf_print_markup', 'desc' => 'Esempio: 1.20 per un ricarico del 20% sui costi di stampa.'] );
    }

    /**
     * 3. Funzioni di Rendering dei Campi HTML (UGUALE al tuo codice)
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
        $option = get_option( $args['id'], '1.00' );
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
     * 4. Rendering della Pagina HTML Completa (MODIFICATA PER LE TAB)
     */
    public function options_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        
        // Recupera la tab corrente dall'URL, default 'settings'
        $active_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'settings';
        ?>
        <div class="wrap">
            <h1>Configurazione Integrazione PF Concept</h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=pf_integration_settings&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Impostazioni Generali</a>
                <a href="?page=pf_integration_settings&tab=importer" class="nav-tab <?php echo $active_tab == 'importer' ? 'nav-tab-active' : ''; ?>">Importatore Prodotti</a>
            </h2>

            <?php if ( $active_tab == 'settings' ): ?>
                
                <form action="options.php" method="post">
                    <?php
                    settings_fields( 'pf_integration_group' );
                    do_settings_sections( 'pf_integration_settings' );
                    submit_button( 'Salva Impostazioni' );
                    ?>
                </form>

                <hr style="margin-top:30px;">
                
                <div style="background:#fff; border:1px solid #ccc; padding:20px; max-width:800px;">
                    <h3>⚡ Aggiornamento Manuale Prezzi e Giacenze</h3>
                    <p>Usa questo pulsante <strong>Sabato dopo le 14:00</strong> (o quando vuoi forzare un aggiornamento).</p>
                    <p>Questo comando scaricherà i feed Prezzi, Stock e Stampa e aggiornerà i prodotti esistenti.</p>
                    
                    <button id="pf-run-manual-import" class="button button-secondary button-large">Esegui Aggiornamento Listini Ora</button>
                    <div id="pf-import-result" style="margin-top:15px;"></div>
                </div>

                <script>
                jQuery(document).ready(function($){
                    $('#pf-run-manual-import').click(function(){
                        var $btn = $(this);
                        var $res = $('#pf-import-result');
                        
                        if(!confirm('Vuoi davvero lanciare l\'aggiornamento massivo dei prezzi? Potrebbe richiedere qualche minuto.')) return;

                        $btn.prop('disabled', true).text('Elaborazione in corso...');
                        $res.html('<span class="spinner is-active" style="float:none; margin:0;"></span> Attendere...');

                        $.post(ajaxurl, { action: 'pf_run_import' }, function(response){
                            $btn.prop('disabled', false).text('Esegui Aggiornamento Listini Ora');
                            if(response.success) {
                                var logHtml = '<ul style="list-style:disc; margin-left:20px;">';
                                $.each(response.data, function(i, msg){ logHtml += '<li>' + msg + '</li>'; });
                                logHtml += '</ul>';
                                $res.html('<div class="notice notice-success inline"><p><strong>Operazione Completata:</strong></p>' + logHtml + '</div>');
                            } else {
                                $res.html('<div class="notice notice-error inline"><p>Errore: ' + response.data + '</p></div>');
                            }
                        }).fail(function(){
                             $btn.prop('disabled', false);
                             $res.html('<div class="notice notice-error inline"><p>Errore di connessione o timeout server.</p></div>');
                        });
                    });
                });
                </script>

            <?php elseif ( $active_tab == 'importer' ): ?>
                
                <?php 
                // Controlla se la classe Creator esiste e mostra la sua interfaccia
                if ( class_exists( 'PF_Product_Creator' ) ) {
                    $creator = new PF_Product_Creator();
                    // Assicurati che nel file class-pf-product-creator.php la funzione render_importer_ui sia pubblica!
                    if ( method_exists( $creator, 'render_importer_ui' ) ) {
                        $creator->render_importer_ui();
                    } else {
                        echo '<div class="notice notice-error"><p>Errore: Metodo render_importer_ui non trovato nella classe PF_Product_Creator.</p></div>';
                    }
                } else {
                    echo '<div class="notice notice-error"><p>Errore: Classe PF_Product_Creator non caricata.</p></div>';
                }
                ?>

            <?php endif; ?>
        </div>
        <?php
    }
}

// Inizializza la classe
if ( is_admin() ) {
    new PF_Settings_Page();
}