<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PF_Importer {

    public function __construct() {
        add_action( 'wp_ajax_pf_run_import', [ $this, 'run_manual_import' ] );
        add_action( 'pf_daily_import_event', [ $this, 'run_full_import' ] );
    }

    public function run_full_import() {
        // Configurazioni server spinte al massimo per l'importazione
        @ini_set( 'memory_limit', '2048M' );
        @set_time_limit( 0 );

        $log = [];
        $log[] = $this->import_prices();
        $log[] = $this->import_stock();
        $log[] = $this->import_print_prices();
        $log[] = $this->import_print_data(); // ✅ ora join via ref (printfeedrefs -> printFeedRows)
        return $log;
    }

    // --- HELPER NAVIGAZIONE JSON ---
    private function get_node_list( $parent, $key ) {
        if ( ! is_array($parent) || ! isset( $parent[$key] ) ) return [];
        $node = $parent[$key];

        // Gestione array wrapper [0] tipico di PF (alcuni nodi sono [ { count: X, ... } ])
        if ( isset( $node[0] ) && is_array( $node[0] ) && ! isset( $node[0]['priceBar'] ) && ! isset( $node[0]['modelCode'] ) ) {
            return $node[0];
        }
        return $node;
    }

    private function get_items_list( $parent, $wrapper_key, $item_key ) {
        $wrapper = $this->get_node_list( $parent, $wrapper_key );
        if ( empty( $wrapper ) ) return [];

        $list = $wrapper[$item_key] ?? [];
        if ( empty( $list ) ) return [];

        // Normalizza singolo oggetto in array
        if ( is_array($list) && ! isset( $list[0] ) ) {
            return [ $list ];
        }
        return $list;
    }

    // --- 1. PREZZI (Corretto con Markup) ---
    private function import_prices() {
        $unique_code = get_option( 'pf_feed_unique_code' );
        if ( empty( $unique_code ) ) return "Prezzi: Unique Code mancante.";

        $url = "http://www.pfconcept.com/portal/datafeed/pricefeed_{$unique_code}_v3.json";
        $data = $this->fetch_json_stream( $url );
        if ( is_string( $data ) ) return "Prezzi: " . $data;

        $root = $data['PFCPriceFeed'] ?? [];
        $price_info = $this->get_node_list( $root, 'priceInfo' );
        $models = $this->get_items_list( $price_info, 'models', 'model' );

        $global_markup = (float) get_option( 'pf_global_markup', 1.40 );

        $count = 0;
        foreach ( $models as $model ) {
            $items = $this->get_items_list( $model, 'items', 'item' );
            foreach ( $items as $pf_item ) {
                $sku = $pf_item['itemcode'] ?? '';
                if ( ! $sku ) continue;

                $scales_data = $this->get_items_list( $pf_item, 'scales', 'scale' );
                $scales = [];

                foreach ( $scales_data as $scale ) {
                    $qty = (int) ($scale['priceBar'] ?? 0);
                    $net_price = (float) ($scale['nettPrice'] ?? 0);
                    if ( $qty > 0 ) $scales[$qty] = $net_price;
                }

                ksort($scales);

                $product_id = wc_get_product_id_by_sku( $sku );
                if ( $product_id ) {
                    update_post_meta( $product_id, '_pf_price_scales', $scales );

                    if ( ! empty( $scales ) ) {
                        $base_net_price = reset($scales);
                        $sell_price = round( $base_net_price * $global_markup, 2 );

                        update_post_meta( $product_id, '_pf_net_price', $base_net_price );
                        update_post_meta( $product_id, '_price', $sell_price );
                        update_post_meta( $product_id, '_regular_price', $sell_price );

                        $count++;
                    }
                }
            }
        }

        return "Prezzi aggiornati ($count prodotti). Markup usato: $global_markup";
    }

    // --- 2. STOCK ---
private function import_stock() {

    $unique_code = get_option( 'pf_feed_unique_code' );
    if ( empty( $unique_code ) ) return "Stock: Unique Code mancante.";

    $url  = "http://www.pfconcept.com/portal/datafeed/stockfeed_{$unique_code}_v3.json";
    $data = $this->fetch_json_stream( $url );
    if ( is_string( $data ) ) return "Stock: " . $data;

    // STRUTTURA REALE:
    // PFCStockFeed -> stockFeed[0] -> models[0] -> model[] -> (ogni model ha items[0].item[])
    $root = $data['PFCStockFeed'] ?? [];
    if ( empty($root) ) return "Stock: Root PFCStockFeed mancante.";

    $stockFeedArr = $root['stockFeed'] ?? [];
    if ( empty($stockFeedArr) || !is_array($stockFeedArr) ) return "Stock: stockFeed vuoto.";

    $stockFeed0 = $stockFeedArr[0] ?? [];
    if ( empty($stockFeed0) || !is_array($stockFeed0) ) return "Stock: stockFeed[0] mancante.";

    $modelsWrapArr = $stockFeed0['models'] ?? [];
    if ( empty($modelsWrapArr) || !is_array($modelsWrapArr) ) return "Stock: models vuoto.";

    // Questo è il wrapper che contiene "model" (array di modelli)
    $modelsWrap0 = $modelsWrapArr[0] ?? [];
    if ( empty($modelsWrap0) || !is_array($modelsWrap0) ) return "Stock: models[0] mancante.";

    $modelsList = $modelsWrap0['model'] ?? [];
    if ( empty($modelsList) || !is_array($modelsList) ) return "Stock: model[] vuoto.";

    error_log('[PF][STOCK] modelsList count=' . count($modelsList));

    $updated = 0;
    $woo_found = 0;
    $woo_missing = 0;

    $LOG_LIMIT = 50;
    $log_i = 0;

    foreach ( $modelsList as $model ) {
        if ( empty($model) || !is_array($model) ) continue;

        $modelCode = $model['modelCode'] ?? '';
        $itemsWrapArr = $model['items'] ?? [];

        // items è un array con dentro { modelCode, item: [ ... ] }
        if ( empty($itemsWrapArr) || !is_array($itemsWrapArr) ) {
            if ($log_i < 5) error_log("[PF][STOCK] model {$modelCode} has NO items[]");
            continue;
        }

        foreach ( $itemsWrapArr as $itemsWrap ) {
            if ( empty($itemsWrap) || !is_array($itemsWrap) ) continue;

            $items = $itemsWrap['item'] ?? [];
            if ( empty($items) ) continue;

            // normalizza: se item è oggetto singolo, wrap in array
            if ( is_array($items) && !isset($items[0]) && isset($items['itemCode']) ) {
                $items = [ $items ];
            }

            foreach ( $items as $pf_item ) {
                if ( empty($pf_item) || !is_array($pf_item) ) continue;

                $sku = $pf_item['itemCode'] ?? '';
                if ( ! $sku ) continue;

                $stock_qty = (int) ($pf_item['stockDirect'] ?? 0);

                if ( $log_i < $LOG_LIMIT ) {
                    $loc  = $pf_item['stockLocation'] ?? '';
                    $npo  = $pf_item['stockNextPo'] ?? '';
                    $date = $pf_item['stockDateNextPo'] ?? '';
                    error_log("[PF][STOCK] SKU={$sku} stockDirect={$stock_qty} loc={$loc} nextPo={$npo} date={$date}");
                    $log_i++;
                }

                $product_id = wc_get_product_id_by_sku( $sku );
                if ( ! $product_id ) {
                    $woo_missing++;
                    continue;
                }

                $woo_found++;

                $product = wc_get_product( $product_id );
                if ( ! $product ) continue;

                $product->set_manage_stock( true );
                $product->set_stock_quantity( $stock_qty );
                $product->set_stock_status( $stock_qty > 0 ? 'instock' : 'outofstock' );
                $product->save();

                $updated++;
            }
        }
    }

    error_log("[PF][STOCK] DONE updated={$updated} woo_found={$woo_found} woo_missing={$woo_missing}");

    return "Stock aggiornato: {$updated} prodotti. (Woo trovati: {$woo_found}, non trovati: {$woo_missing})";
}



    // --- 3. LISTINI STAMPA ---
    private function import_print_prices() {
        global $wpdb;
        $unique_code = get_option( 'pf_feed_unique_code' );
        if ( empty( $unique_code ) ) return "Print Prices: Unique Code mancante.";

        $url = "http://www.pfconcept.com/portal/datafeed/printpricefeed_{$unique_code}_v3.json";
        $data = $this->fetch_json_stream( $url );
        if ( is_string( $data ) ) return "Print Prices: " . $data;

        $root = $data['PFCPrintpricefeed'] ?? [];
        $deco_root = $this->get_node_list( $root, 'decoCharges' );
        $deco_charges = $this->get_items_list( $deco_root, 'decoCharges', 'decoCharge' );

        if ( empty($deco_charges) && isset($deco_root['decoCharge']) ) {
            $deco_charges = $deco_root['decoCharge'];
            if ( isset($deco_charges['printCode']) ) $deco_charges = [ $deco_charges ];
        }

        if ( empty( $deco_charges ) ) return "Print Prices: Dati vuoti.";

        $table_name = $wpdb->prefix . 'pf_print_prices';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            print_code varchar(50) NOT NULL,
            quantity_start int(11) NOT NULL,
            colors_count int(11) NOT NULL,
            unit_price decimal(10,4) NOT NULL,
            setup_cost decimal(10,4) NOT NULL,
            PRIMARY KEY  (id),
            KEY print_code (print_code),
            KEY qty_idx (quantity_start)
        ) " . $wpdb->get_charset_collate() . ";";
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        $wpdb->query( "TRUNCATE TABLE $table_name" );
        $count = 0;

        foreach ( $deco_charges as $charge ) {
            $print_code = $charge['printCode'] ?? '';
            if ( ! $print_code ) continue;

            $logo_sizes = $this->get_items_list( $charge, 'logoSizes', 'logoSize' );
            foreach ( $logo_sizes as $size ) {
                $colors_list = $this->get_items_list( $size, 'amountColors', 'amountColor' );
                foreach ( $colors_list as $color_node ) {
                    $raw_colors = $color_node['amountColorsId'] ?? '1';
                    $colors_count = ( is_numeric($raw_colors) ) ? (int)$raw_colors : 4;
                    if ( stripos( (string)$raw_colors, 'Full' ) !== false ) $colors_count = 4;

                    $setup_charges = $this->get_items_list( $color_node, 'amountSetupCharges', 'amountSetupCharge' );
                    foreach ( $setup_charges as $setup ) {
                        $setup_cost = (float) ($setup['setupCharge'] ?? 0);
                        $prices = $this->get_items_list( $setup, 'decoPrices', 'decoPrice' );
                        foreach ( $prices as $price_row ) {
                            $wpdb->insert( $table_name, [
                                'print_code' => $print_code,
                                'quantity_start' => (int) ($price_row['decoPriceFromQty'] ?? 0),
                                'colors_count' => $colors_count,
                                'unit_price' => (float) ($price_row['price'] ?? 0),
                                'setup_cost' => $setup_cost
                            ], [ '%s', '%d', '%d', '%f', '%f' ] );
                            $count++;
                        }
                    }
                }
            }
        }

        return "Listini Stampa aggiornati: $count righe.";
    }

    // --- 4. DATI TECNICI STAMPA (JOIN ref) ---
    // --- 4. DATI TECNICI STAMPA (CORRETTO: AGGIORNA VARIANTE E PADRE) ---
    private function import_print_data() {
        // Usa l'URL del feed Label o quello generico a seconda delle esigenze
        $url = "http://www.pfconcept.com/portal/datafeed/printdata_cit1_it_label_v3.json";

        $data = $this->fetch_json_stream( $url );
        if ( is_string( $data ) ) return "Print Data Err: " . $data;

        $root = $data['PFCPrintFeed'] ?? $data;
        $print_feed = $this->get_node_list( $root, 'printfeed' );
        if ( empty($print_feed) && isset($root['printfeed'][0]) ) $print_feed = $root['printfeed'][0];

        // 1. Mappatura Globale delle Righe (Ref -> Dati)
        $all_rows = $this->get_items_list( $print_feed, 'printFeedRows', 'printFeedRow' );
        if ( empty($all_rows) ) $all_rows = $this->get_items_list( $root, 'printFeedRows', 'printFeedRow' );

        $rows_by_ref = [];
        foreach ( $all_rows as $r ) {
            if ( isset($r['ref']) ) $rows_by_ref[(int)$r['ref']] = $r;
        }

        // 2. Iterazione Prodotti
        $models = $this->get_items_list( $print_feed, 'models', 'model' );
        if ( empty($models) && isset($print_feed['models'][0]['model']) ) {
            $models = $print_feed['models'][0]['model'];
            if ( isset($models['modelCode']) ) $models = [ $models ];
        }

        $count = 0;
        
        // Cache per evitare di aggiornare il padre 50 volte per lo stesso modello
        $updated_parents = []; 

        foreach ( $models as $model ) {
            $items = $this->get_items_list( $model, 'items', 'item' );
            foreach ( $items as $pf_item ) {
                $sku = $pf_item['itemCode'] ?? $pf_item['itemcode'] ?? '';
                if ( ! $sku ) continue;

                $refs = $this->get_items_list( $pf_item, 'printfeedrefs', 'printfeedref' );
                if ( empty($refs) ) continue;

                $print_options = [];

                foreach ( $refs as $ref_node ) {
                    $ref = (int)($ref_node['ref'] ?? 0);
                    if ( ! $ref || ! isset($rows_by_ref[$ref]) ) continue;

                    $row = $rows_by_ref[$ref];
                    $print_code = $row['printCode'] ?? '';
                    if ( ! $print_code ) continue;

                    // Creiamo un ID univoco per la configurazione (Metodo + Posizione)
                    $config_id = ($row['impMethodCode'] ?? '') . '-' . sanitize_title($row['impLocationCode'] ?? 'std');

                    if ( ! isset($print_options[$print_code]) ) {
                        $print_options[$print_code] = [
                            'name' => $row['impMethod'] ?? 'Stampa',
                            'locations' => []
                        ];
                    }

                    // Usiamo config_id come chiave per evitare duplicati
                    $print_options[$print_code]['locations'][$config_id] = [
                        'name' => $row['impLocation'] ?? 'Standard',
                        'config_id' => $config_id,
                        'ref' => $ref,
                        'image' => $ref_node['imagePrintLine'] ?? '',
                        'max_colours' => $row['maxColours'] ?? '',
                        'width_mm' => $row['impWidthMm'] ?? '',
                        'height_mm' => $row['impHeightMm'] ?? '',
                        'default' => (isset($row['default']) && $row['default'] == 'true')
                    ];
                }

                if ( empty($print_options) ) continue;

                // Rimuoviamo le chiavi associative temporanee per avere un array pulito per il JSON
                foreach ( $print_options as $code => $opt ) {
                    $print_options[$code]['locations'] = array_values($opt['locations']);
                }

                // --- SALVATAGGIO ---
                $product_id = wc_get_product_id_by_sku( $sku );
                if ( $product_id ) {
                    // 1. Aggiorna la Variante specifica (Database corretto)
                    update_post_meta( $product_id, '_pf_print_options', $print_options );
                    $count++;

                    // 2. *** FIX: Aggiorna il Padre (Per la visualizzazione Frontend) ***
                    $parent_id = wp_get_post_parent_id( $product_id );
                    
                    // Aggiorniamo il padre solo una volta per modello per risparmiare risorse
                    if ( $parent_id && ! isset($updated_parents[$parent_id]) ) {
                        update_post_meta( $parent_id, '_pf_print_options', $print_options );
                        $updated_parents[$parent_id] = true;
                    }
                }
            }
        }

        return "Dati Tecnici Stampa aggiornati: $count prodotti elaborati.";
    }

    // --- HELPER DI DOWNLOAD ROBUSTO ---
    private function fetch_json_stream($url) {
        $tmp_file = download_url( $url );

        if ( is_wp_error( $tmp_file ) ) {
            return "Errore download: " . $tmp_file->get_error_message();
        }

        $json_content = file_get_contents( $tmp_file );
        @unlink( $tmp_file );

        if ( empty( $json_content ) ) return "File scaricato ma vuoto.";

        // Rimuove BOM
        $json_content = trim( $json_content, "\xEF\xBB\xBF" );

        $data = json_decode( $json_content, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $json_content = mb_convert_encoding($json_content, 'UTF-8', 'ISO-8859-1');
            $data = json_decode( $json_content, true );

            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return "Errore JSON Syntax: " . json_last_error_msg();
            }
        }

        return $data;
    }

    public function run_manual_import() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Non autorizzato' );
        $result = $this->run_full_import();
        wp_send_json_success( $result );
    }
}
