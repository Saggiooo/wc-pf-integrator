<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PF_Frontend {

    public function __construct() {

        // Lasciamo Woo variations_form nel DOM: serve per galleria + eventi JS
        // NON rimuovere woocommerce_template_single_add_to_cart

        // UI PF sotto titolo/descrizione Woo (così non schiaccia la summary)
        add_action( 'woocommerce_after_single_product_summary', [ $this, 'render_pf_interface' ], 5 );

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_filter( 'woocommerce_add_cart_item_data', [ $this, 'add_custom_data_to_cart' ], 10, 3 );
        add_filter( 'woocommerce_get_item_data', [ $this, 'display_cart_item_data' ], 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'save_order_item_data' ], 10, 4 );

        // Nuovo AJAX calcolo prezzi
        add_action( 'wp_ajax_pf_calculate_price_ajax', [ $this, 'calculate_price_ajax' ] );
        add_action( 'wp_ajax_nopriv_pf_calculate_price_ajax', [ $this, 'calculate_price_ajax' ] );
    
    }

    private function pf_get_current_product() {
        $qid = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
        if ( $qid > 0 ) {
            $p = wc_get_product( $qid );
            if ( $p && is_a($p, 'WC_Product') ) return $p;
        }
        global $product;
        if ( $product && is_a($product, 'WC_Product') ) return $product;
        return null;
    }

    public function enqueue_assets() {
        if ( ! is_product() ) return;

        $product = $this->pf_get_current_product();
        $pid = ( $product && is_a($product, 'WC_Product') ) ? (int) $product->get_id() : 0;

        $css = "
            /* -------------------------------------------------
               1) NON nascondiamo la variations_form, altrimenti galleria muore.
               Nascondiamo solo la parte add-to-cart di Woo (qty + bottone)
               ------------------------------------------------- */
            .woocommerce div.product form.cart .woocommerce-variation-add-to-cart,
            .woocommerce div.product form.cart .single_variation_wrap .single_variation{
                position:absolute !important;
                left:-9999px !important;
                top:-9999px !important;
                opacity:0 !important;
                height:0 !important;
                overflow:hidden !important;
                pointer-events:none !important;
            }

            /* -------------------------------------------------
               2) Layout PF: due colonne sotto (sinistra configuratore, destra costi)
               ------------------------------------------------- */
            .pf-wrap{
                margin-top: 25px;
                padding-top: 25px;
                border-top: 1px solid #eee;
            }
            .pf-container{
                display:flex;
                gap:24px;
                align-items:flex-start;
            }
            .pf-main-col{ flex: 1 1 auto; min-width: 0; }
            .pf-sidebar-col{ flex: 0 0 360px; }

            @media (max-width: 980px){
                .pf-container{ flex-direction: column; }
                .pf-sidebar-col{ flex: 1 1 auto; width: 100%; }
            }

            /* TABELLA PREZZI SCAGLIONI (NUOVA) */
            .pf-price-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 14px; }
            .pf-price-table th { text-align: left; background: #f9f9f9; padding: 8px; border-bottom: 2px solid #eee; color: #555; }
            .pf-price-table td { padding: 8px; border-bottom: 1px solid #eee; color: #333; }
            .pf-price-table tr:last-child td { border-bottom: none; }
            .pf-price-highlight { color: #007cba; font-weight: bold; }

            .pf-section-title{ font-size:15px; font-weight:700; margin:0 0 10px 0; display:block; color:#333; }
            .pf-card{ background:#fff; border:1px solid #e0e0e0; border-radius:10px; padding:18px; margin-bottom:18px; box-shadow:0 4px 10px rgba(0,0,0,0.03); }

            /* Swatches */
            .pf-color-swatches{ display:flex; gap:10px; flex-wrap:wrap; }
            .pf-swatch{ width:40px; height:40px; border-radius:999px; border:2px solid #ddd; cursor:pointer; transition:all .15s; position:relative; }
            .pf-swatch.selected{ border-color:#222; transform:scale(1.1); box-shadow:0 2px 8px rgba(0,0,0,0.15); }
            .pf-swatch:hover::after{ content:attr(title); position:absolute; bottom:100%; left:50%; transform:translateX(-50%); background:#333; color:#fff; padding:4px 8px; font-size:11px; border-radius:6px; white-space:nowrap; pointer-events:none; z-index:10; margin-bottom:6px; }

            /* Quantity rows */
            .pf-size-row{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 0; border-bottom:1px solid #f0f0f0; }
            .pf-size-label{ font-weight:700; width:80px; }
            .pf-qty-ctrl{ display:flex; align-items:center; border:1px solid #ddd; border-radius:6px; overflow:hidden; }
            .pf-qty-btn{ width:34px; height:34px; border:none; background:#f9f9f9; cursor:pointer; font-weight:700; }
            .pf-qty-btn:hover{ background:#eee; }
            .pf-qty-input{ width:56px; text-align:center; border:none; height:34px; -moz-appearance:textfield; }

            /* Print cards */
            .pf-print-options-grid{ display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:12px; margin-bottom:14px; }
            .pf-option-card{ border:2px solid #e0e0e0; border-radius:10px; padding:12px; text-align:center; cursor:pointer; transition:all .15s; background:#fff; position:relative; }
            .pf-option-card:hover{ border-color:#bbb; }
            .pf-option-card.selected{ border-color:#007cba; background:#f0f7fc; box-shadow:0 0 0 1px #007cba; }
            .pf-check-icon{ position:absolute; top:-8px; right:-8px; background:#007cba; color:#fff; border-radius:999px; width:20px; height:20px; font-size:12px; line-height:20px; display:none; }
            .pf-option-card.selected .pf-check-icon{ display:block; }
            .pf-opt-icon{ font-size:22px; display:block; margin-bottom:6px; }
            .pf-opt-label{ font-size:13px; font-weight:700; line-height:1.2; }
            .pf-opt-desc{ font-size:11px; color:#777; margin-top:4px; display:block; }

            /* Upload */
            .pf-upload-zone{ border:2px dashed #ccc; border-radius:10px; padding:22px 16px; text-align:center; background:#fafafa; cursor:pointer; transition:.15s; }
            .pf-upload-zone:hover{ border-color:#999; background:#f0f0f0; }
            .pf-file-label{ font-weight:700; color:#555; display:block; margin-bottom:6px; }
            .pf-file-input{ display:none; }

            /* Page */
            .woo-variation-swatches.wvs-show-label .variations th, .woo-variation-swatches.wvs-show-label .variations td {display: block; width: auto !important; text-align: start; background: white !important; }

            /* Summary */
            .pf-sticky-summary{ position: sticky; top: 20px; background:#fbfbfb; border:1px solid #e0e0e0; border-radius:10px; padding:18px; }
            .pf-summary-row{ display:flex; justify-content:space-between; margin-bottom:10px; font-size:14px; color:#555; }
            .pf-total-row{ font-weight:900; font-size:20px; border-top:2px solid #ddd; padding-top:12px; margin-top:12px; color:#222; }
            #pf-summ-lines{ margin:10px 0 14px 0; padding:10px; background:#fff; border:1px solid #eee; border-radius:10px; }
            .pf-summary-line{ display:flex; justify-content:space-between; font-size:13px; color:#444; margin:6px 0; }
        ";

        wp_add_inline_style( 'woocommerce-general', $css );

        wp_enqueue_script(
            'pf-frontend-js',
            plugins_url( '../assets/js/frontend-calc.js', __FILE__ ),
            [ 'jquery' ],
            '2.0',
            true
        );

        global $product;
        $product_id = 0;
        if ( is_a( $product, 'WC_Product' ) ) {
        $product_id = $product->get_id();
        } else {
        $product_id = get_queried_object_id();
        }

        wp_localize_script( 'pf-frontend-js', 'pf_params', [
        'ajax_url'    => admin_url( 'admin-ajax.php' ),
        'nonce'       => wp_create_nonce( 'pf_calc_nonce' ),
        'product_id'  => $product_id,
        ]);

    }

    private function get_term_hex_color_by_slug( $slug ) {
        $term = get_term_by( 'slug', $slug, 'pa_colore' );
        if ( ! $term ) return '';
        $hex = get_term_meta( $term->term_id, 'product_attribute_color', true );
        if ( ! $hex ) $hex = get_term_meta( $term->term_id, 'color', true );
        return $hex;
    }

    public function render_pf_interface() {
        $product = $this->pf_get_current_product();
        if ( ! $product || ! $product->is_type('variable') ) return;

        $print_options = get_post_meta( $product->get_id(), '_pf_print_options', true );

        $grouped_by_color = [];
        $available_variations = $product->get_available_variations();

        foreach ( $available_variations as $var ) {
            $attrs = $var['attributes'];
            $color_slug = $attrs['attribute_pa_colore'] ?? '';
            if ( ! $color_slug ) continue;

            $term = get_term_by( 'slug', $color_slug, 'pa_colore' );
            $color_label = $term ? $term->name : $color_slug;

            $size_key = '';
            foreach ( $attrs as $k => $v ) {
                if ( strpos($k, 'size') !== false || strpos($k, 'taglia') !== false ) {
                    $size_key = $k;
                    break;
                }
            }

            if ( ! isset($grouped_by_color[$color_slug]) ) {
                $grouped_by_color[$color_slug] = [
                    'label' => $color_label,
                    'vars'  => []
                ];
            }

            $grouped_by_color[$color_slug]['vars'][] = [
                'variation_id' => (int) $var['variation_id'],
                'size'         => $size_key ? ( $attrs[$size_key] ?? 'Unica' ) : 'Unica',
                'stock'        => $var['max_qty'] ?: 0,
                'price'        => $var['display_price'],
                'image'        => $var['image']['src'] ?? ''
            ];
        }

        if ( empty($grouped_by_color) ) return;
        ?>

        <div class="pf-wrap">
            <div class="pf-container">
                <div class="pf-main-col">
                    <form id="pf-add-to-cart-form" enctype="multipart/form-data">

                        <div class="pf-card">
                            <label class="pf-section-title">1. Scegli il Colore</label>
                            <div class="pf-color-swatches">
                                <?php $first = true; ?>
                                <?php foreach ( $grouped_by_color as $color_slug => $data ): ?>
                                    <?php $vars = $data['vars']; if (empty($vars)) continue; ?>
                                    <?php
                                        $label = $data['label'];
                                        $hex   = $this->get_term_hex_color_by_slug( $color_slug );
                                        $style = $hex ? "background-color:{$hex};" : "background-color:#eee;";
                                        if ( strtolower($hex) === '#ffffff' || strtolower($hex) === '#fff' ) $style .= " border:1px solid #ccc;";
                                    ?>
                                    <div class="pf-swatch <?php echo $first ? 'selected' : ''; ?>"
                                        title="<?php echo esc_attr($label); ?>"
                                        style="<?php echo esc_attr($style); ?>"
                                        data-color-slug="<?php echo esc_attr($color_slug); ?>"
                                        data-color-label="<?php echo esc_attr($label); ?>"
                                        data-image="<?php echo esc_url($vars[0]['image']); ?>">
                                    </div>
                                    <?php $first = false; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="pf-card">
                            <label class="pf-section-title">2. Inserisci Quantità</label>

                            <?php $first = true; ?>
                            <?php foreach ( $grouped_by_color as $color_slug => $data ): ?>
                                <div class="pf-size-table" style="<?php echo $first ? '' : 'display:none;'; ?>">
                                    <?php foreach ( $data['vars'] as $v ): ?>
                                        <div class="pf-size-row">
                                            <div class="pf-size-label"><?php echo esc_html( strtoupper($v['size']) ); ?></div>

                                            <div class="pf-qty-ctrl">
                                                <button type="button" class="pf-qty-btn minus">-</button>

                                                <input type="number"
                                                    class="pf-qty-input"
                                                    name="pf_qty[<?php echo esc_attr($v['variation_id']); ?>]"
                                                    value="0"
                                                    min="0"
                                                    data-price="<?php echo esc_attr($v['price']); ?>"
                                                    data-color-slug="<?php echo esc_attr($color_slug); ?>"
                                                    data-color-label="<?php echo esc_attr($data['label']); ?>"
                                                >

                                                <button type="button" class="pf-qty-btn plus">+</button>
                                            </div>

                                            <div class="pf-stock-info">
                                                <?php echo ( (int)$v['stock'] > 0 )
                                                    ? '<span style="color:green">● Disponibile</span>'
                                                    : '<span style="color:red">Esaurito</span>'; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php $first = false; ?>
                            <?php endforeach; ?>
                        </div>

                        <?php if ( ! empty( $print_options ) ): ?>
                    <div class="pf-card">
                        <label class="pf-section-title">3. Personalizzazione Stampa</label>
                        
                        <p style="font-size:13px; margin-bottom:10px;">Seleziona il tipo di lavorazione:</p>
                        <div class="pf-print-options-grid">
                            <div class="pf-option-card pf-tech-card selected" data-code="">
                                <div class="pf-check-icon">✓</div>
                                <span class="pf-opt-icon">🚫</span>
                                <span class="pf-opt-label">Nessuna Stampa</span>
                                <span class="pf-opt-desc">Prodotto neutro</span>
                            </div>

                            <?php foreach($print_options as $code => $data): ?>
                                <div class="pf-option-card pf-tech-card" data-code="<?php echo esc_attr($code); ?>">
                                    <div class="pf-check-icon">✓</div>
                                    <span class="pf-opt-icon">🖨️</span> 
                                    <span class="pf-opt-label"><?php echo esc_html($data['name']); ?></span>
                                    <span class="pf-opt-desc">Include Setup</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <input type="hidden" id="pf_print_code" name="pf_print_code" value="">

                        <div id="pf-positions-wrapper" style="display:none; border-top:1px solid #eee; padding-top:20px; margin-top:20px;">
                            
                            <?php foreach($print_options as $code => $data): ?>
                                <div class="pf-tech-group" id="pf-tech-group-<?php echo esc_attr($code); ?>" style="display:none;">
                                    <p style="font-size:13px; margin-bottom:10px;">Seleziona posizione:</p>
                                    
                                    <div class="pf-print-options-grid">
                                        <?php 
                                        // URL Base per le immagini tecniche (Line Drawings)
                                        $base_imprint_url = 'https://images.pfconcept.com/ImprintImages_All/JPG/500x500/';
                                        
                                        foreach($data['locations'] as $loc): 
                                            // Recuperiamo il nome file salvato dall'importer
                                            $img_file = $loc['image'] ?? '';
                                            $has_image = !empty($img_file);
                                            $image_url = $has_image ? $base_imprint_url . $img_file : '';
                                        ?>
                                            <div class="pf-option-card pf-loc-card" data-config="<?php echo esc_attr($loc['config_id']); ?>">
                                                <div class="pf-check-icon">✓</div>
                                                
                                                <div class="pf-loc-visual" style="height: 80px; display: flex; align-items: center; justify-content: center; margin-bottom: 8px;">
                                                    <?php if ( $has_image ): ?>
                                                        <img src="<?php echo esc_url($image_url); ?>" 
                                                             alt="<?php echo esc_attr($loc['name']); ?>" 
                                                             style="max-height: 100%; max-width: 100%; object-fit: contain;">
                                                    <?php else: ?>
                                                        <span class="pf-opt-icon" style="font-size: 30px;">📍</span>
                                                    <?php endif; ?>
                                                </div>

                                                <span class="pf-opt-label"><?php echo esc_html($loc['name']); ?></span>
                                                
                                                <?php if(!empty($loc['max_colours'])): ?>
                                                    <span class="pf-opt-desc" style="font-size:10px; color:#999; display:block; margin-top:2px;">
                                                        Max Col: <?php echo $loc['max_colours']; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <input type="hidden" name="pf_selected_config_id" id="pf_selected_config_id">
                            
                            <div style="margin-top:15px; margin-bottom:20px;">
                                <label style="font-weight:600;">Numero Colori di Stampa:</label>
                                <select id="pf_print_colors" name="pf_print_colors" style="margin-left:10px; padding:5px; border-radius:4px; border:1px solid #ccc;">
                                    <option value="1">1 Colore</option>
                                    <option value="2">2 Colori</option>
                                    <option value="3">3 Colori</option>
                                    <option value="4">4 Colori (Quadricromia)</option>
                                </select>
                            </div>

                            <div class="pf-upload-zone" onclick="document.getElementById('pf_real_file').click();">
                                <span class="pf-file-label">📂 Clicca per caricare il tuo logo</span>
                                <span style="font-size:12px; color:#888;">Formati: PDF, AI, EPS, PNG, JPG (Max 10MB)</span>
                                <input type="file" id="pf_real_file" name="pf_logo_file" class="pf-file-input" accept=".pdf,.ai,.eps,.png,.jpg,.jpeg">
                                <div id="pf-file-name" style="margin-top:10px; font-weight:bold; color:#007cba;"></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    </form>
                </div>

                <div class="pf-sidebar-col">
                    <div class="pf-sticky-summary">
                        <h4 style="margin:0 0 10px 0;">Riepilogo Ordine</h4>

                        <div id="pf-summ-lines">
                            <div style="font-size:12px; color:#888;">Nessun colore selezionato</div>
                        </div>

                        <div class="pf-summary-row"><span>Quantità:</span><span id="pf-summ-qty">0 pz</span></div>
                        <div class="pf-summary-row"><span>Merce:</span><span id="pf-summ-net">€ 0,00</span></div>
                        <div class="pf-summary-row"><span>Stampa:</span><span id="pf-summ-print">€ 0,00</span></div>
                        <div class="pf-summary-row"><span>Impianto:</span><span id="pf-summ-setup">€ 0,00</span></div>
                        <div class="pf-summary-row pf-total-row"><span>Totale (IVA escl.):</span><span id="pf-summ-total">€ 0,00</span></div>

                        <button type="button" id="pf-add-to-cart-btn" class="button alt" style="width:100%; margin-top:16px; font-size:16px; padding:14px;">
                            Aggiungi al carrello 🛒
                        </button>
                        <p style="font-size:12px; text-align:center; margin-top:12px; color:#888;">🔒 Pagamenti sicuri e spedizione tracciata.</p>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function(){
            var f = document.getElementById('pf_real_file');
            if(!f) return;
            f.addEventListener('change', function(){
                var fileName = this.files[0] ? this.files[0].name : '';
                var out = document.getElementById('pf-file-name');
                if(out) out.textContent = fileName ? 'File selezionato: ' + fileName : '';
            });
        })();
        </script>

        <?php
    }

    // --- FUNZIONE AGGIUNTA: Calcolo AJAX dei prezzi (VERSIONE DEBUG & FALLBACK) ---
    // --- FUNZIONE CORRETTA: Restituisce NUMERI per il calcolo JS ---
    public function calculate_price_ajax() {
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

        // 2. FALLBACK A 4 COLORI (Es. Stampa Digitale è sempre 'Full Color' nel DB)
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

        // 3. FALLBACK A 0 COLORI (Es. Incisione/Laser senza colori)
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
        $markup = (float) get_option( 'pf_global_markup', 1.40 );
        
        $net_print_unit = (float) $row->unit_price;
        $net_setup_cost = (float) $row->setup_cost;

        $gross_print_unit = $net_print_unit * $markup;
        $gross_setup_cost = $net_setup_cost * $markup;

        $print_total = $gross_print_unit * $qty;
        $setup_total = $gross_setup_cost;

        // *** PUNTO CRUCIALE: Restituisco JSON pulito con i numeri ***
        wp_send_json_success([
            'print_total' => number_format($print_total, 2, '.', ''), // Esempio: "157.00"
            'setup_total' => number_format($setup_total, 2, '.', '')  // Esempio: "45.00"
        ]);
    }

    public function add_custom_data_to_cart( $cart_item_data, $product_id, $variation_id ) {
        return $cart_item_data;
    }

    public function display_cart_item_data( $item_data, $cart_item ) {
        if ( isset( $cart_item['pf_customization'] ) ) {
            $cust = $cart_item['pf_customization'];

            $item_data[] = [
                'key'   => 'Stampa',
                'value' => esc_html( $cust['print_code'] . ' (' . $cust['colors'] . ' colori)' ),
            ];

            if ( ! empty( $cust['file_url'] ) ) {
                $item_data[] = [
                    'key'   => 'Logo',
                    'value' => '<a href="' . esc_url( $cust['file_url'] ) . '" target="_blank">Scarica File</a>',
                ];
            }
        }
        return $item_data;
    }

    public function save_order_item_data( $item, $cart_item_key, $values, $order ) {
        if ( isset( $values['pf_customization'] ) ) {
            $cust = $values['pf_customization'];
            $item->add_meta_data( 'PF Stampa', $cust['print_code'] );
            $item->add_meta_data( 'PF Colori', $cust['colors'] );
            if ( ! empty( $cust['file_url'] ) ) {
                $item->add_meta_data( 'PF Logo', $cust['file_url'] );
            }
        }
    }
}