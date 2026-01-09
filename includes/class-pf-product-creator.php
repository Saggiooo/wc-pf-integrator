<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PF_Product_Creator {

    const PF_IMAGE_BASE_URL = 'https://images.pfconcept.com/ProductImages_All/JPG/1600x1600/';

    public function __construct() {
        add_action( 'wp_ajax_pf_create_product_by_sku', [ $this, 'ajax_create_product' ] );
    }

    public function render_importer_ui() {
        ?>
        <div class="pf-importer-box" style="margin-top:20px;">
            <h3>Importatore v12 (Stampa Attiva)</h3>
            <p>Inserisci il <strong>Codice Modello</strong> (es. <code>210263</code>).<br>
            Ora estrae anche le regole di stampa dal JSON per attivare il box preventivi.</p>
            
            <div style="background:#fff; padding:20px; border:1px solid #ccc; max-width:600px;">
                <label for="pf_model_sku"><strong>Codice Modello (Padre):</strong></label><br>
                <input type="text" id="pf_model_sku" style="width:100%; margin-top:5px; font-size:18px; padding:10px;" placeholder="Es. 210263">
                <br><br>
                <button id="pf-run-creator" class="button button-primary button-hero">Crea Prodotto</button>
                <div id="pf-creator-log" style="margin-top:20px; background:#f9f9f9; padding:10px; border:1px solid #ddd; display:none; overflow:auto; max-height:400px; font-family:monospace;"></div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($){
            $('#pf-run-creator').click(function(e){
                e.preventDefault();
                var sku = $('#pf_model_sku').val();
                if(!sku) { alert('Inserisci un codice!'); return; }
                
                var $log = $('#pf-creator-log');
                $log.show().html('⏳ <b>Elaborazione v12...</b><br>Sto mappando le opzioni di stampa...');
                $(this).prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'pf_create_product_by_sku',
                    sku: sku
                }, function(response){
                    if(response.success) {
                        $log.html('<div style="color:green; border-left:4px solid green; padding-left:10px;">✅ ' + response.data + '</div>');
                    } else {
                        $log.html('<div style="color:red; border-left:4px solid red; padding-left:10px;">❌ ' + response.data + '</div>');
                    }
                    $('#pf-run-creator').prop('disabled', false);
                }).fail(function() {
                    $log.html('<span style="color:red;">Errore Server (Timeout). Riprova.</span>');
                    $('#pf-run-creator').prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }

    /**
 * Scarica un JSON grande in un file temporaneo e lo decodifica.
 * Ritorna array se ok, stringa errore se fail.
 */
private function fetch_json_stream_temp( $url ) {

    // download_url usa WP HTTP API e scrive un tmp file
    $tmp_file = download_url( $url, 120 ); // 120s timeout

    if ( is_wp_error( $tmp_file ) ) {
        return "Errore download: " . $tmp_file->get_error_message();
    }

    $json_content = file_get_contents( $tmp_file );
    @unlink( $tmp_file );

    if ( empty( $json_content ) ) {
        return "File scaricato ma vuoto.";
    }

    // Rimuove BOM
    $json_content = trim( $json_content, "\xEF\xBB\xBF" );

    $data = json_decode( $json_content, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        // fallback encoding (PF a volte fa scherzi)
        if ( function_exists('mb_convert_encoding') ) {
            $json_content = mb_convert_encoding($json_content, 'UTF-8', 'ISO-8859-1');
            $data = json_decode( $json_content, true );
        }

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return "Errore JSON: " . json_last_error_msg();
        }
    }

    return $data;
}

private function fetch_json_stream($url) {
    $tmp_file = download_url( $url );

    if ( is_wp_error( $tmp_file ) ) {
        return "Errore download: " . $tmp_file->get_error_message();
    }

    $json_content = file_get_contents( $tmp_file );
    @unlink( $tmp_file );

    if ( empty( $json_content ) ) return "File scaricato ma vuoto.";

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


/**
 * Estrae la lista modelli dal productfeed PF in modo ultra-robusto.
 * Ritorna array di modelli (ognuno con modelCode), oppure [] se non trova nulla.
 */
private function extract_models_list_from_productfeed( $data ) {

    // 1) PATH NOTI (casing/nomi che PF usa spesso)
    $paths = [
        ['PFCProductfeed','productfeed','models','model'],
        ['PFCProductFeed','productfeed','models','model'],
        ['pfcProductfeed','productfeed','models','model'],
        ['pfcProductFeed','productfeed','models','model'],

        // a volte cambia productfeed/ProductFeed
        ['PFCProductfeed','Productfeed','models','model'],
        ['PFCProductFeed','Productfeed','models','model'],
        ['pfcProductfeed','Productfeed','models','model'],
        ['pfcProductFeed','Productfeed','models','model'],

        // alcuni feed hanno products->models->model
        ['products','models','model'],
        ['models','model'],
        ['model'],
    ];

    foreach ( $paths as $path ) {
        $node = $data;
        $ok = true;
        foreach ( $path as $k ) {
            if ( is_array($node) && array_key_exists($k, $node) ) {
                $node = $node[$k];
            } else {
                $ok = false;
                break;
            }
        }
        if ( ! $ok || empty($node) ) continue;

        // Normalizza wrapper PF: models può essere [ {count, model:[...]} ]
        $list = $this->normalize_model_list_node( $node );
        if ( ! empty($list) ) return $list;
    }

    // 2) FALLBACK: scan iterativo (no ricorsione profonda che ti spacca lo stack)
    // Cerchiamo un nodo chiave "model" che sia lista e contenga modelCode.
    $stack = [ $data ];
    $max_visits = 200000; // safety
    $visits = 0;

    while ( ! empty($stack) ) {
        $visits++;
        if ( $visits > $max_visits ) break;

        $node = array_pop($stack);
        if ( ! is_array($node) ) continue;

        foreach ( $node as $key => $value ) {
            if ( $key === 'model' && is_array($value) ) {
                $list = $this->normalize_model_list_node( $value );
                if ( ! empty($list) ) return $list;
            }

            if ( is_array($value) ) {
                $stack[] = $value;
            }
        }
    }

    return [];
}

/**
 * Normalizza vari casi:
 * - value già = [ {modelCode:...}, ... ]
 * - value = {modelCode:...} singolo
 * - value = [ {count:..., model:[...] } ] wrapper
 * - value = {count:..., model:[...] } wrapper
 */
private function normalize_model_list_node( $node ) {

    if ( empty($node) || !is_array($node) ) return [];

    // Caso: wrapper singolo {count, model:[...]}
    if ( isset($node['model']) && is_array($node['model']) ) {
        $node = $node['model'];
    }

    // Caso: wrapper array [ {count, model:[...]} ]
    if ( isset($node[0]) && is_array($node[0]) && isset($node[0]['model']) && is_array($node[0]['model']) ) {
        $node = $node[0]['model'];
    }

    // Normalizza singolo modello in array
    if ( is_array($node) && !isset($node[0]) ) {
        // se è proprio un modello con modelCode
        if ( isset($node['modelCode']) ) {
            $node = [ $node ];
        }
    }

    if ( empty($node) || !is_array($node) ) return [];

    // Valida: deve contenere modelCode (diretto o sotto ['model'])
    $first = $node[0] ?? null;
    if ( !is_array($first) ) return [];

    if ( isset($first['modelCode']) ) return $node;

    // a volte ogni elemento è { model: {...} }
    if ( isset($first['model']) && is_array($first['model']) && isset($first['model']['modelCode']) ) {
        $out = [];
        foreach ( $node as $wrap ) {
            if ( isset($wrap['model']) && is_array($wrap['model']) ) {
                $out[] = $wrap['model'];
            }
        }
        return $out;
    }

    return [];
}



    public function ajax_create_product() {
    set_time_limit(900);
    ini_set('memory_limit', '4096M');

    $target = trim( sanitize_text_field( $_POST['sku'] ?? '' ) );
    if ( empty($target) ) wp_send_json_error("SKU mancante.");

    // 1) Scarica feed remoto in file temporaneo (zero JSON locale)
    $url = 'https://www.pfconcept.com/portal/datafeed/productfeed_it_v3.json';
    $data = $this->fetch_json_stream( $url );
    if ( is_string($data) ) {
        wp_send_json_error("Errore download/JSON: " . $data);
    }

    // 2) Trova lista modelli: pfcProductfeed -> productfeed -> models
    $models_list = [];
    if ( isset($data['pfcProductfeed']['productfeed']['models']) && is_array($data['pfcProductfeed']['productfeed']['models']) ) {
        $models_list = $data['pfcProductfeed']['productfeed']['models'];
    }

    if ( empty($models_list) ) {
        // debug utile: quali chiavi ci sono a livello root
        $root_keys = is_array($data) ? implode(', ', array_keys($data)) : 'N/A';
        wp_send_json_error("Struttura JSON non riconosciuta o models vuoto. Root keys: {$root_keys}");
    }

    // 3) Normalizza: ogni entry è { model: {...} } (come nel tuo esempio)
    $models_clean = [];
    foreach ( $models_list as $wrap ) {
        if ( isset($wrap['model']) && is_array($wrap['model']) ) {
            $models_clean[] = $wrap['model'];
        } elseif ( is_array($wrap) ) {
            // fallback (nel caso arrivi già il model “nudo”)
            $models_clean[] = $wrap;
        }
    }

    if ( empty($models_clean) ) {
        wp_send_json_error("models presenti ma non convertibili in array di model.");
    }

    // 4) Cerca per modelCode
    $found_model = null;
    foreach ( $models_clean as $model ) {
        $modelCode = $model['modelCode'] ?? '';
        if ( $modelCode && strcasecmp(trim((string)$modelCode), $target) === 0 ) {
            $found_model = $model;
            break;
        }
    }

    // 5) Se non trovato: cerca per itemCode dentro items
    if ( ! $found_model ) {
        foreach ( $models_clean as $model ) {
            $items = $this->normalize_items( $model ); // la tua funzione wrapper-aware
            if ( empty($items) ) continue;

            foreach ( $items as $it ) {
                $itemCode = $it['itemCode'] ?? $it['itemcode'] ?? '';
                if ( $itemCode && strcasecmp(trim((string)$itemCode), $target) === 0 ) {
                    $found_model = $model;
                    break 2;
                }
            }
        }
    }

    if ( ! $found_model ) {
        wp_send_json_error("Modello/SKU <b>{$target}</b> non trovato nel productfeed (né modelCode né itemCode).");
    }

    // 6) Creazione prodotto (tuo metodo)
    $product_id = $this->create_variable_product( $found_model );
    if ( is_wp_error( $product_id ) ) wp_send_json_error( $product_id->get_error_message() );

    // libera memoria
    unset($data);

    wp_send_json_success(
        "Prodotto Creato! <a href='".get_edit_post_link($product_id)."' target='_blank'>Modifica</a> | <a href='".get_permalink($product_id)."' target='_blank'>Vedi</a>"
    );
}



    private function normalize_items( $model_data ) {
    $raw = $model_data['items'] ?? [];
    if ( empty($raw) ) return [];

    $out = [];

    // Caso A: items è array di wrapper: [ {item:{...}}, {item:{...}} ]
    if ( is_array($raw) ) {
        foreach ( $raw as $w ) {
            if ( isset($w['item']) && is_array($w['item']) ) {
                $it = $w['item'];
            } else {
                $it = $w;
            }

            $code = $it['itemCode'] ?? $it['itemcode'] ?? '';
            if ( ! empty($code) ) $out[] = $it;
        }
    }

    return $out;
}


    // --- HELPER DI ESTRAZIONE ---
    private function get_color_data( $item ) {
        $c_container = $item['colors']['color'] ?? [];
        $c_obj = isset($c_container[0]) ? $c_container[0] : $c_container;

        return [
            'name' => $c_obj['colorDesc'] ?? '',
            'hex'  => $c_obj['hexColor'] ?? ''
        ];
    }

    private function get_size_from_item( $item ) {
        return $item['size'] ?? '';
    }

    private function parse_float( $str ) {
        return floatval( str_replace( ',', '.', $str ) );
    }

    /**
     * NUOVA FUNZIONE: Estrae le info di stampa e le formatta per il Frontend
     */
    private function parse_print_options( $item_data ) {
        // Struttura JSON PF: decorationSettings -> decoDefault
        $deco = $item_data['decorationSettings']['decoDefault'] ?? null;
        
        if ( ! $deco ) return [];

        // Esempio dato: method="Tampografia", impLocationDefault="Fronte"
        $method_name = $deco['method'] ?? 'Stampa Standard';
        $location_name = $deco['impLocationDefault'] ?? 'Posizione Standard';
        
        // Generiamo un codice univoco fittizio basato sul nome (es. TAMP01)
        $method_code = strtoupper(substr($method_name, 0, 4)) . '01';

        // Costruiamo l'array che il frontend si aspetta
        return [
            $method_code => [
                'name' => $method_name,
                'locations' => [
                    [
                        'name' => $location_name,
                        'config_id' => 'def-loc-1' // ID fittizio per ora
                    ]
                ]
            ]
        ];
    }

    // --- CORE ---

    private function create_variable_product( $data ) {
        $items = $this->normalize_items( $data );
        if ( empty( $items ) ) return new WP_Error( 'no_items', 'Nessuna variante trovata.' );
        $ref_item = $items[0];

        // 1. Crea Padre
        $existing_id = wc_get_product_id_by_sku( $data['modelCode'] );
        $product = $existing_id ? wc_get_product( $existing_id ) : new WC_Product_Variable();

        $product->set_name( $data['description'] ?? 'Prodotto ' . $data['modelCode'] );
        $product->set_sku( $data['modelCode'] );
        $product->set_status( 'publish' );
        
        // Descrizione
        $desc = $data['extDesc'] ?? ($data['description'] ?? '');
        $tech_table = '<h4>Dettagli Tecnici</h4><table class="woocommerce-product-attributes shop_attributes">';
        if(!empty($ref_item['material'])) $tech_table .= '<tr><th>Materiale</th><td>' . $ref_item['material'] . '</td></tr>';
        if(!empty($ref_item['countryOfOrigin'])) $tech_table .= '<tr><th>Origine</th><td>' . $ref_item['countryOfOrigin'] . '</td></tr>';
        if(!empty($ref_item['brand'])) $tech_table .= '<tr><th>Brand</th><td>' . $ref_item['brand'] . '</td></tr>';
        if(!empty($ref_item['measurements']['SizeCombined'])) $tech_table .= '<tr><th>Dimensioni</th><td>' . $ref_item['measurements']['SizeCombined'] . '</td></tr>';
        $tech_table .= '</table>';
        $product->set_description( $desc . '<br><br>' . $tech_table );

        // Dati fisici
        $weight_gr = $this->parse_float($ref_item['measurements']['weightGr'] ?? '0');
        if($weight_gr > 0) $product->set_weight( $weight_gr / 1000 );
        $product->set_length($this->parse_float($ref_item['measurements']['lengthCm'] ?? '0'));
        $product->set_width($this->parse_float($ref_item['measurements']['widthCm'] ?? '0'));
        $product->set_height($this->parse_float($ref_item['measurements']['heightCm'] ?? '0'));

        // Categorie
        if( isset($data['categoryData']['catDesc']) ) {
            $cat_name = $data['categoryData']['catDesc'];
            $term = term_exists( $cat_name, 'product_cat' );
            if ( ! $term ) $term = wp_insert_term( $cat_name, 'product_cat' );
            if ( ! is_wp_error( $term ) ) $product->set_category_ids( [ $term['term_id'] ] );
        }

        // *** SALVATAGGIO OPZIONI STAMPA (FIX PER IL FRONTEND) ***
        $print_opts = $this->parse_print_options( $ref_item );
        // Salva in '_pf_print_options' che è quello che class-pf-frontend.php cerca!
        $product->update_meta_data( '_pf_print_options', $print_opts );
        
        // Salva anche i dati grezzi per backup
        $product->update_meta_data( '_pf_decoration_settings', $ref_item['decorationSettings'] ?? [] );


        // 3. PREPARAZIONE ATTRIBUTI
        $colors_map = []; 
        $sizes = [];

        foreach ( $items as $item ) {
            $c_data = $this->get_color_data($item);
            if( $c_data['name'] ) {
                $colors_map[ $c_data['name'] ] = $c_data['hex'];
            }
            $s = $this->get_size_from_item($item);
            if($s) $sizes[] = $s;
        }

        $sizes = array_unique($sizes);
        $attr_color_name = 'Colore';
        $attr_size_name = 'Taglia';

        // Forza attributo globale Colore (Tipo Color)
        $this->force_attribute_type_color( $attr_color_name );
        // Inserisce i termini
        $this->insert_color_terms( $attr_color_name, $colors_map );

        $product_attributes = [];

        // Attributo Colore
        if ( ! empty( $colors_map ) ) {
            $attr = new WC_Product_Attribute();
            $attr->set_id( wc_attribute_taxonomy_id_by_name( $attr_color_name ) ); 
            $attr->set_name( 'pa_' . sanitize_title($attr_color_name) ); 
            $attr->set_options( array_keys($colors_map) );
            $attr->set_position( 0 );
            $attr->set_visible( true );
            $attr->set_variation( true );
            $product_attributes[] = $attr;
        }

        // Attributo Taglia
        if ( ! empty( $sizes ) ) {
            $attr = new WC_Product_Attribute();
            $attr->set_name( $attr_size_name );
            $attr->set_options( $sizes );
            $attr->set_position( 1 );
            $attr->set_visible( true );
            $attr->set_variation( true );
            $product_attributes[] = $attr;
        }

        $product->set_attributes( $product_attributes );
        $product_id = $product->save();

        // 4. Varianti
        foreach ( $items as $item ) {
            $this->create_variation( $product_id, $item, $attr_color_name, $attr_size_name );
        }
        
        // 5. Immagini
        $this->handle_product_images( $product, $ref_item );

        return $product_id;
    }

    private function force_attribute_type_color( $name ) {
        global $wpdb;
        $slug = sanitize_title( $name ); 

        $exists = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s", $slug) );

        if ( $exists ) {
            if ( $exists->attribute_type !== 'color' ) {
                $wpdb->update(
                    "{$wpdb->prefix}woocommerce_attribute_taxonomies",
                    [ 'attribute_type' => 'color' ],
                    [ 'attribute_id'   => $exists->attribute_id ]
                );
                delete_transient( 'wc_attribute_taxonomies' );
            }
        } else {
            wc_create_attribute([
                'name' => $name,
                'slug' => $slug,
                'type' => 'color',
                'order_by' => 'menu_order',
                'has_archives' => false,
            ]);
        }

        $taxonomy_name = wc_attribute_taxonomy_name( $name );
        if( ! taxonomy_exists($taxonomy_name) ) {
            register_taxonomy( $taxonomy_name, ['product'], [] );
        }
    }

    private function insert_color_terms( $attr_name, $colors_map ) {
        $taxonomy = wc_attribute_taxonomy_name( $attr_name ); 

        foreach ( $colors_map as $name => $hex ) {
            if ( ! term_exists( $name, $taxonomy ) ) {
                wp_insert_term( $name, $taxonomy );
            }
            
            $term = get_term_by( 'name', $name, $taxonomy );
            if ( $term && ! is_wp_error( $term ) && $hex ) {
                $hex = '#' . ltrim($hex, '#');
                update_term_meta( $term->term_id, 'product_attribute_color', $hex ); 
                update_term_meta( $term->term_id, 'color', $hex );
            }
        }
    }

    private function handle_product_images( $product, $ref_item ) {
        $img_data = $ref_item['imageData'] ?? [];
        $gallery_ids = [];
        $main_image_id = 0;

        if ( ! empty( $img_data['imageMain'] ) ) {
            $full_url = self::PF_IMAGE_BASE_URL . $img_data['imageMain'];
            $main_image_id = $this->upload_image_from_url( $full_url, $product->get_id() );
            if ( $main_image_id ) $product->set_image_id( $main_image_id );
        }

        $gallery_keys = ['imageBack', 'imageExtra1', 'imageExtra2', 'imageDetail1', 'imageMood1'];
        foreach($gallery_keys as $key) {
            if ( ! empty( $img_data[$key] ) ) {
                $full_url = self::PF_IMAGE_BASE_URL . $img_data[$key];
                $attach_id = $this->upload_image_from_url( $full_url, $product->get_id() );
                if ( $attach_id && $attach_id !== $main_image_id ) $gallery_ids[] = (int) $attach_id;
            }
        }
        if ( ! empty( $gallery_ids ) ) $product->set_gallery_image_ids( $gallery_ids );
        $product->save();
    }

    private function create_variation( $parent_id, $item_data, $attr_color_name, $attr_size_name ) {
        $sku = $item_data['itemCode'];
        
        // Cerca se esiste già o ne crea una nuova
        $variation_id_check = wc_get_product_id_by_sku( $sku );
        $variation = $variation_id_check ? wc_get_product( $variation_id_check ) : new WC_Product_Variation();

        if( ! $variation_id_check ) $variation->set_parent_id( $parent_id );

        $variation->set_sku( $sku );
        $variation->set_manage_stock( true );
        $variation->set_stock_quantity( 0 ); 
        $variation->set_regular_price( 0 ); 
        
        // Pesi e Misure (Gestione decimali e conversione grammi in kg)
        $weight_gr = $this->parse_float($item_data['measurements']['weightGr'] ?? '0');
        if($weight_gr > 0) $variation->set_weight( $weight_gr / 1000 );
        
        $variation->set_length( $this->parse_float($item_data['measurements']['lengthCm'] ?? '0') );
        $variation->set_width( $this->parse_float($item_data['measurements']['widthCm'] ?? '0') );
        $variation->set_height( $this->parse_float($item_data['measurements']['heightCm'] ?? '0') );

        // Attributi (Colore / Taglia)
        $attributes = [];
        
        // Gestione Colore
        $c_data = $this->get_color_data($item_data);
        if ( $c_data['name'] ) {
            $term = get_term_by( 'name', $c_data['name'], 'pa_' . sanitize_title($attr_color_name) );
            if ( $term && ! is_wp_error( $term ) ) {
                $attributes[ 'pa_' . sanitize_title( $attr_color_name ) ] = $term->slug; 
            }
        }
        
        // Gestione Taglia
        $s_val = $this->get_size_from_item($item_data);
        if ( $s_val ) {
            $attributes[ sanitize_title( $attr_size_name ) ] = $s_val;
        }
        
        $variation->set_attributes( $attributes );

        // --- GESTIONE IMMAGINI SPECIFICHE PER VARIANTE ---
        $img_data = $item_data['imageData'] ?? [];
        $variation_gallery_ids = []; // <--- ASSICURATI CHE QUI CI SIA IL PUNTO E VIRGOLA

        // 1. Immagine Principale della variante (imageMain)
        if ( ! empty( $img_data['imageMain'] ) ) {
            $full_url = self::PF_IMAGE_BASE_URL . $img_data['imageMain'];
            $attach_id = $this->upload_image_from_url( $full_url, $parent_id ); // Usa parent_id per allegare al media library
            if ( $attach_id ) {
                $variation->set_image_id( $attach_id );
            }
        }
        
        // 2. Immagini Galleria della variante
        $gallery_keys = ['imageFront', 'imageBack', 'imageDetail1', 'imageDetail2', 'imageDetail3', 'imageMood1', 'imageExtra1'];
        
        foreach( $gallery_keys as $key ) {
            if ( ! empty( $img_data[$key] ) ) {
                $full_url = self::PF_IMAGE_BASE_URL . $img_data[$key];
                
                // Scarichiamo l'immagine
                $gallery_attach_id = $this->upload_image_from_url( $full_url, $parent_id );
                
                // Se caricata con successo E diversa dall'immagine principale (per evitare duplicati visivi)
                if ( $gallery_attach_id && $gallery_attach_id !== $variation->get_image_id() ) {
                    $variation_gallery_ids[] = $gallery_attach_id;
                }
            }
        }

        // 3. Salvataggio ID Galleria nel Meta Field del Plugin
        // 'rtwp_vg_images' è la chiave usata da "Variation Images Gallery for WooCommerce"
        if ( ! empty( $variation_gallery_ids ) ) {
            $variation->update_meta_data( 'rtwp_vg_images', implode(',', $variation_gallery_ids) );
        }

        $variation->save();
    }

    private function upload_image_from_url( $url, $post_id ) {
        $filename = basename($url);
        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_title = %s AND post_type = 'attachment'", pathinfo($filename, PATHINFO_FILENAME) ) );
        if ( $existing ) return $existing;

        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $desc = "PF Concept Product Image";
        $id = media_sideload_image( $url, $post_id, $desc, 'id' );
        if ( is_wp_error( $id ) ) return false;
        return $id;
    }
}