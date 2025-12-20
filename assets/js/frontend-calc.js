jQuery(document).ready(function($) {
    
    // Variabili principali
    var $productForm = $('form.cart');
    var $priceContainer = $('.price'); // Selettore standard WooCommerce (potrebbe variare col tema)
    var $qtyInput = $productForm.find('input[name="quantity"]');
    
    // Se non siamo in una pagina prodotto o non c'è il form, esci
    if (!$productForm.length) return;

    // 1. Ascolta i cambiamenti (Quantità o Opzioni di Stampa)
    // Nota: Adegua i selettori '.pf-print-option' in base a come costruisci l'HTML del preventivatore
    $productForm.on('change', 'input[name="quantity"], .pf-print-option', function() {
        updatePrice();
    });

    // Funzione principale di aggiornamento
    function updatePrice() {
        var qty = parseInt($qtyInput.val());
        var productId = $productForm.find('button[name="add-to-cart"]').val();
        
        // Raccogli i dati di stampa (Esempio basato su ipotetici campi select/radio)
        // Devi creare questi campi nel frontend (es. con Product Add-ons o custom HTML)
        var printCode = $('#pf_print_code').val(); // Es. GPE02
        var printColors = $('#pf_print_colors').val(); // Es. 1
        
        // Se manca la quantità o è zero, non calcolare
        if (!qty || qty < 1) return;

        // Feedback visivo (opacità o spinner)
        $priceContainer.css('opacity', '0.5');

        // 2. Chiamata AJAX a WordPress
        $.ajax({
            type: 'POST',
            url: pf_ajax_obj.ajax_url,
            data: {
                action: 'pf_calculate_price_ajax', // Nome dell'azione PHP
                product_id: productId,
                quantity: qty,
                print_code: printCode,
                print_colors: printColors,
                security: pf_ajax_obj.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Aggiorna il prezzo a video
                    // response.data.html contiene il prezzo formattato (es. "€ 150,00")
                    $priceContainer.html(response.data.html);
                    
                    // Opzionale: Mostra dettagli calcolo (es. "Stampa: +€0,30 cad.")
                    $('#pf-price-breakdown').html(response.data.breakdown_html);
                } else {
                    console.log('Errore calcolo PF: ' + response.data);
                }
            },
            complete: function() {
                $priceContainer.css('opacity', '1');
            }
        });
    }
});