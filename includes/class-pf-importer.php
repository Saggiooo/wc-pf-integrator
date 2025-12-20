public function import_products() {
    // 1. Scarica il JSON usando il codice univoco
    $json_url = 'http://www.pfconcept.com/portal/datafeed/pricefeed_TUOCODICE_v3.json';
    // ... logica download ...

    // 2. Cicla i prodotti
    foreach ($products as $pf_item) {
        // 3. Applica il ricarico salvato nelle impostazioni
        $markup = get_option('pf_global_markup'); 
        $net_price = $pf_item['nettPrice']; [cite_start]// Dal feed [cite: 773]
        $sell_price = $net_price * $markup;

        // 4. Salva o Aggiorna in WooCommerce
        update_post_meta( $wc_product_id, '_regular_price', $sell_price );
        update_post_meta( $wc_product_id, '_price', $sell_price );
    }
}