<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PF_Frontend {

    public function __construct() {

        // NON rimuovere il template standard: serve per variations_form + JS + plugin galleria variazioni
        // remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );

        // Mettiamo la nostra UI DOPO quella standard (che lasciamo nel DOM)
        add_action( 'woocommerce_single_product_summary', [ $this, 'render_pf_interface' ], 31 );

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_filter( 'woocommerce_add_cart_item_data', [ $this, 'add_custom_data_to_cart' ], 10, 3 );
        add_filter( 'woocommerce_get_item_data', [ $this, 'display_cart_item_data' ], 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'save_order_item_data' ], 10, 4 );
    }

    public function enqueue_assets() {
        if ( ! is_product() ) return;

        // CSS
        $css = "
            /* -------------------------------------------------
               NASCONDERE SOLO LA PARTE 'ADD TO CART' STANDARD
               MA LASCIARE IN VITA variations_form (non display:none)
               ------------------------------------------------- */

    
            form.variations_form.cart {

                height: 0 !important;

                overflow: hidden !important;

                opacity: 0 !important;

                margin: 0 !important;

                padding: 0 !important;

                position: absolute !important;

                z-index: -100;

            })

            /* Nascondi la parte add-to-cart (qty + bottone) */
            .woocommerce-variation-add-to-cart {
                position: absolute !important;
                left: -9999px !important;
                top: -9999px !important;
                opacity: 0 !important;
                height: 0 !important;
                overflow: hidden !important;
                z-index: -1 !important;
                pointer-events: none !important;
            }

            /* Nascondi eventuale prezzo/stock duplicato dentro single_variation */
            form.variations_form .single_variation_wrap .single_variation {
                position: absolute !important;
                left: -9999px !important;
                top: -9999px !important;
                opacity: 0 !important;
                height: 0 !important;
                overflow: hidden !important;
                z-index: -1 !important;
                pointer-events: none !important;
            }

            /* -------------------------------------------------
               STILI UI PF
               ------------------------------------------------- */
            .pf-container { display: flex; flex-wrap: wrap; gap: 30px; margin-top: 20px; }
            .pf-main-col { flex: 1; min-width: 60%; }
            .pf-sidebar-col { flex: 0 0 350px; }

            .pf-section-title { font-size: 15px; font-weight: 700; margin-bottom: 10px; display: block; color: #333; }

            .pf-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; margin-bottom: 25px; box-shadow: 0 4px 10px rgba(0,0,0,0.03); }

            /* SWATCHES */
            .pf-color-swatches { display: flex; gap: 10px; flex-wrap: wrap; }
            .pf-swatch { width: 40px; height: 40px; border-radius: 50%; border: 2px solid #ddd; cursor: pointer; transition: all 0.2s; position: relative; }
            .pf-swatch.selected { border-color: #222; transform: scale(1.15); box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
            .pf-swatch:hover::after { content: attr(title); position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); background: #333; color: #fff; padding: 4px 8px; font-size: 11px; border-radius: 4px; white-space: nowrap; pointer-events: none; z-index: 10; margin-bottom: 6px; }

            /* QUANTITY TABLE */
            .pf-size-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #f0f0f0; }
            .pf-size-label { font-weight: 700; width: 80px; }
            .pf-qty-ctrl { display: flex; align-items: center; gap: 0; border: 1px solid #ddd; border-radius: 4px; overflow: hidden; }
            .pf-qty-btn { width: 32px; height: 32px; border: none; background: #f9f9f9; cursor: pointer; font-weight: bold; }
            .pf-qty-btn:hover { background: #eee; }
            .pf-qty-input { width: 50px; text-align: center; border: none; height: 32px; -moz-appearance: textfield; }

            /* PRINT CARDS */
            .pf-print-options-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 15px; margin-bottom: 20px; }
            .pf-option-card { border: 2px solid #e0e0e0; border-radius: 8px; padding: 15px; text-align: center; cursor: pointer; transition: all 0.2s; background: #fff; position: relative; }
            .pf-option-card:hover { border-color: #bbb; }
            .pf-option-card.selected { border-color: #007cba; background: #f0f7fc; box-shadow: 0 0 0 1px #007cba; }
            .pf-check-icon { position: absolute; top: -8px; right: -8px; background: #007cba; color: #fff; border-radius: 50%; width: 20px; height: 20px; font-size: 12px; line-height: 20px; display: none; }
            .pf-option-card.selected .pf-check-icon { display: block; }
            .pf-opt-icon { font-size: 24px; display: block; margin-bottom: 8px; }
            .pf-opt-label { font-size: 13px; font-weight: 600; line-height: 1.3; }
            .pf-opt-desc { font-size: 11px; color: #777; margin-top: 4px; display: block; }

            /* FILE UPLOAD */
            .pf-upload-zone { border: 2px dashed #ccc; border-radius: 8px; padding: 30px 20px; text-align: center; background: #fafafa; cursor: pointer; transition: 0.2s; margin-top: 15px; }
            .pf-upload-zone:hover { border-color: #999; background: #f0f0f0; }
            .pf-file-label { font-weight: 600; color: #555; display: block; margin-bottom: 5px; }
            .pf-file-input { display: none; }

            /* SUMMARY */
            .pf-sticky-summary { position: sticky; top: 30px; background: #fbfbfb; border: 1px solid #e0e0e0; border-radius: 8px; padding: 25px; }
            .pf-summary-row { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 14px; color: #555; }
            .pf-total-row { font-weight: 800; font-size: 20px; border-top: 2px solid #ddd; padding-top: 15px; margin-top: 15px; color: #222; }

            @media (max-width: 768px) { .pf-sidebar-col { display: none; } }
        ";

        wp_add_inline_style( 'woocommerce-general', $css );

        // JS
        wp_enqueue_script(
            'pf-frontend-js',
            plugins_url( '../assets/js/frontend-calc.js', __FILE__ ),
            [ 'jquery' ],
            '1.5',
            true
        );

        wp_localize_script( 'pf-frontend-js', 'pf_params', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'pf_calc_nonce' ),
        ] );
    }

    // HEX per slug
    private function get_term_hex_color_by_slug( $slug ) {
        $term = get_term_by( 'slug', $slug, 'pa_colore' );
        if ( ! $term ) return '';
        $hex = get_term_meta( $term->term_id, 'product_attribute_color', true );
        if ( ! $hex ) $hex = get_term_meta( $term->term_id, 'color', true );
        return $hex;
    }

    public function render_pf_interface() {
        global $product;
        if ( ! $product ) return;

        $print_options = get_post_meta( $product->get_id(), '_pf_print_options', true );

        // Raggruppo per SLUG colore (fondamentale per parlare con Woo)
        $grouped_by_color = [];

        if ( $product->is_type( 'variable' ) ) {
            $available_variations = $product->get_available_variations();

            foreach ( $available_variations as $var ) {
                $attrs = $var['attributes'];

                // standard Woo
                $color_slug = $attrs['attribute_pa_colore'] ?? '';
                if ( ! $color_slug ) continue;

                $term = get_term_by( 'slug', $color_slug, 'pa_colore' );
                $color_label = $term ? $term->name : $color_slug;

                // opzionale size
                $size_key = '';
                foreach ( $attrs as $k => $v ) {
                    if ( strpos($k, 'size') !== false || strpos($k, 'taglia') !== false ) {
                        $size_key = $k;
                        break;
                    }
                }

                if ( ! isset( $grouped_by_color[ $color_slug ] ) ) {
                    $grouped_by_color[ $color_slug ] = [
                        'label' => $color_label,
                        'vars'  => []
                    ];
                }

                $grouped_by_color[ $color_slug ]['vars'][] = [
                    'variation_id' => $var['variation_id'],
                    'size'         => $size_key ? ($attrs[$size_key] ?? 'Unica') : 'Unica',
                    'stock'        => $var['max_qty'] ?: 0,
                    'price'        => $var['display_price'],
                    'image'        => $var['image']['src'] ?? ''
                ];
            }
        } else {
            // Non-variable: opzionale (qui potresti gestirlo, per ora lascio comunque UI minima)
            return;
        }

        if ( empty( $grouped_by_color ) ) return;
        ?>

        <div class="pf-container">
            <div class="pf-main-col">
                <form id="pf-add-to-cart-form" enctype="multipart/form-data">

                    <div class="pf-card">
                        <label class="pf-section-title">1. Scegli il Colore</label>
                        <div class="pf-color-swatches">
                            <?php
                            $first = true;
                            foreach ( $grouped_by_color as $color_slug => $data ):
                                $vars  = $data['vars'];
                                if ( empty($vars) ) continue;

                                $label = $data['label'];
                                $hex   = $this->get_term_hex_color_by_slug( $color_slug );
                                $style = $hex ? "background-color: {$hex};" : "background-color:#eee;";
                                if ( strtolower($hex) === '#ffffff' || strtolower($hex) === '#fff' ) $style .= " border:1px solid #ccc;";
                                ?>
                                <div class="pf-swatch <?php echo $first ? 'selected' : ''; ?>"
                                     title="<?php echo esc_attr($label); ?>"
                                     style="<?php echo esc_attr($style); ?>"
                                     data-color-slug="<?php echo esc_attr($color_slug); ?>"
                                     data-image="<?php echo esc_url($vars[0]['image']); ?>">
                                </div>
                                <?php
                                $first = false;
                            endforeach;
                            ?>
                        </div>
                    </div>

                    <div class="pf-card">
                        <label class="pf-section-title">2. Inserisci Quantità</label>

                        <?php
                        $first = true;
                        foreach ( $grouped_by_color as $color_slug => $data ):
                            $vars = $data['vars'];
                            ?>
                            <div class="pf-size-table" style="<?php echo $first ? '' : 'display:none;'; ?>">
                                <?php foreach ( $vars as $v ): ?>
                                    <div class="pf-size-row">
                                        <div class="pf-size-label"><?php echo esc_html( strtoupper($v['size']) ); ?></div>

                                        <div class="pf-qty-ctrl">
                                            <button type="button" class="pf-qty-btn minus">-</button>
                                            <input type="number"
                                                   class="pf-qty-input"
                                                   name="pf_qty[<?php echo esc_attr($v['variation_id']); ?>]"
                                                   value="0"
                                                   min="0"
                                                   data-price="<?php echo esc_attr($v['price']); ?>">
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
                            <?php
                            $first = false;
                        endforeach;
                        ?>
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

                                <?php foreach ( $print_options as $code => $data ): ?>
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
                                <?php foreach ( $print_options as $code => $data ): ?>
                                    <div class="pf-tech-group" id="pf-tech-group-<?php echo esc_attr($code); ?>" style="display:none;">
                                        <p style="font-size:13px; margin-bottom:10px;">Seleziona posizione e dimensione:</p>
                                        <div class="pf-print-options-grid">
                                            <?php foreach ( $data['locations'] as $loc ): ?>
                                                <div class="pf-option-card pf-loc-card" data-config="<?php echo esc_attr($loc['config_id']); ?>">
                                                    <div class="pf-check-icon">✓</div>
                                                    <span class="pf-opt-icon">📍</span>
                                                    <span class="pf-opt-label"><?php echo esc_html($loc['name']); ?></span>
                                                    <span class="pf-opt-desc">Area Max: Standard</span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <input type="hidden" name="pf_selected_config_id" id="pf_selected_config_id">

                                <div style="margin-top:15px; margin-bottom:20px;">
                                    <label style="font-weight:600;">Numero Colori di Stampa:</label>
                                    <select id="pf_print_colors" name="pf_print_colors" style="margin-left:10px; padding:5px;">
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
                    <h4>Riepilogo Ordine</h4>
                    <div class="pf-summary-row"><span>Quantità:</span><span id="pf-summ-qty">0 pz</span></div>
                    <div class="pf-summary-row"><span>Merce:</span><span id="pf-summ-net">€ 0,00</span></div>
                    <div class="pf-summary-row"><span>Stampa:</span><span id="pf-summ-print">€ 0,00</span></div>
                    <div class="pf-summary-row"><span>Impianto:</span><span id="pf-summ-setup">€ 0,00</span></div>
                    <div class="pf-summary-row pf-total-row"><span>Totale (IVA incl.):</span><span id="pf-summ-total">€ 0,00</span></div>

                    <button type="button" id="pf-add-to-cart-btn" class="button alt" style="width:100%; margin-top:20px; font-size:16px; padding:15px;">
                        Aggiungi al carrello 🛒
                    </button>
                    <p style="font-size:12px; text-align:center; margin-top:15px; color:#888;">🔒 Pagamenti sicuri e spedizione tracciata.</p>
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
