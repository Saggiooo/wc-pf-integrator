<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PF_Importer {

    public function __construct() {
        // Hook per lanciare l'importazione manualmente (es. via AJAX)
        add_action( 'wp_ajax_pf_run_import', [ $this, 'run_manual_import' ] );
        
        // Hook per CRON job (importazione automatica giornaliera)
        add_action( 'pf_daily_import_event', [ $this, 'run_full_import' ] );
    }

    /**
     * Esegue l'importazione completa (4 step)
     */
    public function run_full_import() {
        $log = [];
        
        // 1. Aggiorna Prezzi Base e Scaglioni Quantità
        $log[] = $this->import_prices();
        
        // 2. Aggiorna Giacenze (Stock)
        $log[] = $this->import_stock();
        
        // 3. Aggiorna Listini Costi Stampa (Tabella DB)
        $log[] = $this->import_print_prices();
        
        // 4. Aggiorna Regole e Posizioni Stampa (Meta Dati Prodotto)
        $log[] = $this->import_print_data();
        
        return $log;
    }

    /**
     * IMPORT 1: Prezzi Acquisto (Price Feed)
     * Gestisce prezzo base e scaglioni di quantità (Tiered Pricing)
     */
    private function import_prices() {
        $unique_code = get_option( 'pf_feed_unique_code' );
        if ( empty( $unique_code ) ) return "Errore: Unique Code mancante.";

        $url = "http://www.pfconcept.com/portal/datafeed/pricefeed_{$unique_code}_v3.json";
        $response = wp_remote_get( $url, ['timeout' => 90] );
        
        if ( is_wp_error( $response ) ) return "Errore download Price Feed.";

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $count = 0;

        $models = $data['priceInfo']['models']['model'] ?? [];
        if ( isset( $models['modelcode'] ) ) $models = [ $models ];

        foreach ( $models as $model ) {
            $items = $model['items']['item'] ?? [];
            if ( isset( $items['itemcode'] ) ) $items = [ $items ];

            foreach ( $items as $pf_item ) {
                $sku = $pf_item['itemcode'];
                
                // Logica per gli scaglioni (Scales)
                $scales = [];
                $pf_scales_data = $pf_item['scales']['scale'] ?? [];
                if ( isset( $pf_scales_data['priceBar'] ) ) $pf_scales_data = [ $pf_scales_data ];

                foreach ( $pf_scales_data as $scale ) {
                    $scales[ (int)$scale['priceBar'] ] = (float)$scale['nettPrice'];
                }
                // Ordina per quantità crescente
                ksort($scales);

                $product_id = wc_get_product_id_by_sku( $sku );
                if ( $product_id ) {
                    // 1. Salva gli scaglioni
                    update_post_meta( $product_id, '_pf_price_scales', $scales );

                    // 2. Imposta prezzo base (il primo scaglione) + Markup
                    $base_price = reset($scales); 
                    $markup = (float) get_option( 'pf_global_markup', 1.00 );
                    $sell_price = round( $base_price * $markup, 2 );
                    
                    update_post_meta( $product_id, '_pf_net_price', $base_price );
                    update_post_meta( $product_id, '_price', $sell_price );
                    update_post_meta( $product_id, '_regular_price', $sell_price );
                    
                    $count++;
                }
            }
        }
        return "Prezzi e Scaglioni aggiornati: $count prodotti.";
    }

    /**
     * IMPORT 2: Giacenze (Stock Feed)
     */
    private function import_stock() {
        $unique_code = get_option( 'pf_feed_unique_code' );
        if ( empty( $unique_code ) ) return "Errore: Unique Code mancante.";

        $url = "http://www.pfconcept.com/portal/datafeed/stockfeed_{$unique_code}_v3.json";
        $response = wp_remote_get( $url, ['timeout' => 60] );
        
        if ( is_wp_error( $response ) ) return "Errore download Stock Feed.";

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $models = $data['stockFeed']['models']['model'] ?? [];
        $count = 0;

        if ( isset( $models['modelCode'] ) ) $models = [ $models ];

        foreach ( $models as $model ) {
            $items = $model['items']['item'] ?? [];
            if ( isset( $items['itemCode'] ) ) $items = [ $items ];

            foreach ( $items as $pf_item ) {
                $sku = $pf_item['itemCode'];
                $stock_qty = (int) $pf_item['stockDirect'];

                $product_id = wc_get_product_id_by_sku( $sku );
                if ( $product_id ) {
                    $product = wc_get_product( $product_id );
                    if ( $product ) {
                        $product->set_manage_stock( true );
                        $product->set_stock_quantity( $stock_qty );
                        $product->save();
                        $count++;
                    }
                }
            }
        }
        return "Stock aggiornato: $count prodotti.";
    }

    /**
     * IMPORT 3: Listini Stampa (Print Price Feed)
     * Popola la tabella custom wp_pf_print_prices
     */
    private function import_print_prices() {
        global $wpdb;
        $unique_code = get_option( 'pf_feed_unique_code' );
        if ( empty( $unique_code ) ) return "Errore: Unique Code mancante.";

        // Controlla sempre l'URL esatto fornito da PF
        $url = "http://www.pfconcept.com/portal/datafeed/printpricefeed_{$unique_code}_v3.json";
        
        $response = wp_remote_get( $url, ['timeout' => 90] );
        if ( is_wp_error( $response ) ) return "Errore download Print Price Feed.";

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['decoCharges'] ) ) return "Dati stampa vuoti.";

        // Svuota la tabella prima di riempirla
        $table_name = $wpdb->prefix . 'pf_print_prices';
        $wpdb->query( "TRUNCATE TABLE $table_name" );

        $count = 0;
        $deco_charges = $data['decoCharges']['decoCharge'] ?? [];
        if ( isset( $deco_charges['printCode'] ) ) $deco_charges = [ $deco_charges ];

        foreach ( $deco_charges as $charge ) {
            $print_code = $charge['printCode']; 
            
            $logo_sizes = $charge['logoSizes']['logoSize'] ?? [];
            if ( isset( $logo_sizes['itemCodeId'] ) ) $logo_sizes = [ $logo_sizes ];

            foreach ( $logo_sizes as $size ) {
                $colors_list = $size['amountColors']['amountColor'] ?? [];
                if ( isset( $colors_list['amountColorsId'] ) ) $colors_list = [ $colors_list ];

                foreach ( $colors_list as $color_node ) {
                    $colors_count = (int) $color_node['amountColorsId']; 
                    
                    $setup_charges = $color_node['amountSetupCharges']['amountSetupCharge'] ?? [];
                    if ( isset( $setup_charges['AmountSetupChargeId'] ) ) $setup_charges = [ $setup_charges ];
                    
                    foreach ( $setup_charges as $setup ) {
                        $setup_cost = (float) ($setup['SetupCharge'] ?? 0);
                        
                        $prices = $setup['decoPrices']['decoPrice'] ?? [];
                        if ( isset( $prices['decoPriceFromQty'] ) ) $prices = [ $prices ];

                        foreach ( $prices as $price_row ) {
                            $qty_start = (int) $price_row['decoPriceFromQty'];
                            $unit_price = is_array($price_row) && isset($price_row['price']) 
                                ? (float)$price_row['price'] 
                                : (float)$price_row; 

                            $wpdb->insert(
                                $table_name,
                                [
                                    'print_code' => $print_code,
                                    'quantity_start' => $qty_start,
                                    'colors_count' => $colors_count,
                                    'unit_price' => $unit_price,
                                    'setup_cost' => $setup_cost
                                ],
                                [ '%s', '%d', '%d', '%f', '%f' ]
                            );
                            $count++;
                        }
                    }
                }
            }
        }
        return "Listini Stampa aggiornati: $count righe.";
    }

    /**
     * IMPORT 4: Dati Tecnici Stampa (Print Data Feed)
     * Recupera le configurazioni (es. 1-front) e le salva nel prodotto
     */
    private function import_print_data() {
        // URL Standard per l'Italia. Se PF ti dà un codice diverso, aggiornalo qui.
        $url = "http://www.pfconcept.com/portal/datafeed/printdata_cnl1_it_v3.json"; 
        
        $response = wp_remote_get( $url, ['timeout' => 120] ); // File molto grande
        if ( is_wp_error( $response ) ) return "Errore download Print Data Feed.";

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data ) ) return "Errore JSON Print Data.";

        $count = 0;
        
        $models = $data['printfeed']['models']['model'] ?? [];
        if ( isset( $models['modelCode'] ) ) $models = [ $models ];

        foreach ( $models as $model ) {
            $items = $model['items']['item'] ?? [];
            if ( isset( $items['itemCode'] ) ) $items = [ $items ];

            foreach ( $items as $pf_item ) {
                $sku = $pf_item['itemCode'];
                $rows = $pf_item['printFeedRows']['printFeedRow'] ?? [];
                if ( isset( $rows['printCode'] ) ) $rows = [ $rows ];

                $print_options = [];

                foreach ( $rows as $row ) {
                    // Dati essenziali per il Configuration ID
                    $method_code = $row['impMethodCode']; 
                    $loc_code    = $row['impLocationCode']; 
                    $print_code  = $row['printCode']; 
                    $location_name = $row['impLocation']; 
                    $technique_name = $row['impMethod']; 
                    
                    // COSTRUZIONE ID (es. "6-front")
                    $config_id = $method_code . '-' . $loc_code; 

                    if (!isset($print_options[$print_code])) {
                        $print_options[$print_code] = [
                            'name' => $technique_name,
                            'locations' => []
                        ];
                    }
                    
                    $print_options[$print_code]['locations'][] = [
                        'name' => $location_name,
                        'config_id' => $config_id 
                    ];
                }

                $product_id = wc_get_product_id_by_sku( $sku );
                if ( $product_id ) {
                    // Salva le regole tecniche nel prodotto
                    update_post_meta( $product_id, '_pf_print_options', $print_options );
                    $count++;
                }
            }
        }
        return "Dati Tecnici Stampa (Regole) aggiornati per $count prodotti.";
    }

    public function run_manual_import() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Non autorizzato' );
        
        // Esegue l'import completo e restituisce il log in JSON
        $result = $this->run_full_import();
        wp_send_json_success( $result );
    }
}