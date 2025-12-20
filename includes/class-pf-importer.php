<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PF_Importer {

    public function __construct() {
        add_action( 'wp_ajax_pf_run_import', [ $this, 'run_manual_import' ] );
        add_action( 'pf_daily_import_event', [ $this, 'run_full_import' ] );
    }

    public function run_full_import() {
        $log = [];
        // 1. Aggiorna Prezzi Base
        $log[] = $this->import_prices();
        
        // 2. Aggiorna Stock
        $log[] = $this->import_stock();
        
        // 3. (NUOVO) Aggiorna Listini Stampa per il preventivatore
        $log[] = $this->import_print_prices();
        
        return $log;
    }

    /**
     * IMPORT 1: Prezzi Acquisto Prodotti (Price Feed)
     */
    private function import_prices() {
        $unique_code = get_option( 'pf_feed_unique_code' );
        if ( empty( $unique_code ) ) return "Errore: Unique Code mancante.";

        $url = "http://www.pfconcept.com/portal/datafeed/pricefeed_{$unique_code}_v3.json";
        $response = wp_remote_get( $url, ['timeout' => 60] );
        
        if ( is_wp_error( $response ) ) return "Errore download Price Feed.";

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $markup = (float) get_option( 'pf_global_markup', 1.00 );
        $count = 0;

        // Gestione struttura array/oggetto del JSON PF
        $models = $data['priceInfo']['models']['model'] ?? [];
        if ( isset( $models['modelcode'] ) ) $models = [ $models ]; // Normalizza singolo

        foreach ( $models as $model ) {
            $items = $model['items']['item'] ?? [];
            if ( isset( $items['itemcode'] ) ) $items = [ $items ];

            foreach ( $items as $pf_item ) {
                $sku = $pf_item['itemcode'];
                $net_price = (float) $pf_item['nettPrice'];
                $sell_price = round( $net_price * $markup, 2 );

                $product_id = wc_get_product_id_by_sku( $sku );
                if ( $product_id ) {
                    update_post_meta( $product_id, '_regular_price', $sell_price );
                    update_post_meta( $product_id, '_price', $sell_price );
                    update_post_meta( $product_id, '_pf_net_price', $net_price );
                    $count++;
                }
            }
        }
        return "Prezzi Base aggiornati: $count prodotti.";
    }

    /**
     * IMPORT 2: Giacenze Magazzino (Stock Feed)
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
     * IMPORT 3: Listini Stampa (Print Price Feed) - NUOVO!
     * Popola la tabella custom wp_pf_print_prices
     */
    private function import_print_prices() {
        global $wpdb;
        $unique_code = get_option( 'pf_feed_unique_code' );
        if ( empty( $unique_code ) ) return "Errore: Unique Code mancante.";

        // Nota: Spesso il feed stampa ha un prefisso diverso o usa lo stesso codice.
        // Controlla l'URL esatto fornito da PF, a volte è 'printpricefeed_{CODE}_v3.json'
        $url = "http://www.pfconcept.com/portal/datafeed/printpricefeed_{$unique_code}_v3.json";
        
        $response = wp_remote_get( $url, ['timeout' => 60] );
        if ( is_wp_error( $response ) ) return "Errore download Print Price Feed.";

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['decoCharges'] ) ) return "Dati stampa vuoti.";

        // Svuota la tabella prima di riempirla (per evitare duplicati e pulire vecchi prezzi)
        $table_name = $wpdb->prefix . 'pf_print_prices';
        $wpdb->query( "TRUNCATE TABLE $table_name" );

        $count = 0;
        $deco_charges = $data['decoCharges']['decoCharge'] ?? [];
        if ( isset( $deco_charges['printCode'] ) ) $deco_charges = [ $deco_charges ];

        foreach ( $deco_charges as $charge ) {
            $print_code = $charge['printCode']; // Es. GPE02
            
            // PF struttura i prezzi per "LogoSize" -> "AmountColors"
            $logo_sizes = $charge['logoSizes']['logoSize'] ?? [];
            if ( isset( $logo_sizes['itemCodeId'] ) ) $logo_sizes = [ $logo_sizes ];

            foreach ( $logo_sizes as $size ) {
                $colors_list = $size['amountColors']['amountColor'] ?? [];
                if ( isset( $colors_list['amountColorsId'] ) ) $colors_list = [ $colors_list ];

                foreach ( $colors_list as $color_node ) {
                    $colors_count = (int) $color_node['amountColorsId']; // Numero colori (es. 1)
                    
                    // Costi impianto (Setup)
                    $setup_charges = $color_node['amountSetupCharges']['amountSetupCharge'] ?? [];
                    if ( isset( $setup_charges['AmountSetupChargeId'] ) ) $setup_charges = [ $setup_charges ];
                    
                    foreach ( $setup_charges as $setup ) {
                        $setup_cost = (float) ($setup['SetupCharge'] ?? 0);
                        
                        // Fasce di prezzo per quantità
                        $prices = $setup['decoPrices']['decoPrice'] ?? [];
                        if ( isset( $prices['decoPriceFromQty'] ) ) $prices = [ $prices ];

                        foreach ( $prices as $price_row ) {
                            $qty_start = (int) $price_row['decoPriceFromQty'];
                            // A volte il valore è direttamente nel nodo, a volte in un campo 'price'
                            $unit_price = is_array($price_row) && isset($price_row['price']) 
                                ? (float)$price_row['price'] 
                                : (float)$price_row; 

                            // Inserimento nel DB Custom
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
        
        return "Listini Stampa aggiornati: $count righe inserite.";
    }

    public function run_manual_import() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Non autorizzato' );
        $result = $this->run_full_import();
        wp_send_json_success( $result );
    }
}