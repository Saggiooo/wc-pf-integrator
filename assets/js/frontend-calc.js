jQuery(function ($) {

  // --- 1. AGGIUNGI QUESTE VARIABILI GLOBALI ALL'INIZIO ---
  var currentPrintTotal = 0.0;
  var currentSetupTotal = 0.0;

  // ---------------------------
  // Helper: mostra tabella quantità per index swatch
  // ---------------------------
  function showSizeTableByIndex(index) {
    $('.pf-size-table').hide();
    $('.pf-size-table').eq(index).show();
  }

  // ---------------------------
  // Helper: escape HTML
  // ---------------------------
  function escapeHtml(str) {
    return String(str)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  // ---------------------------
  // Helper: formatta valuta
  // ---------------------------
  function formatMoney(amount) {
    return amount.toFixed(2).replace('.', ',');
  }

  // ---------------------------
  // Riepilogo per colore
  // ---------------------------
  function rebuildColorSummary() {
    var map = {}; // { slug: {label, qty} }

    $('.pf-qty-input').each(function () {
      var q = parseInt($(this).val(), 10) || 0;
      if (q <= 0) return;

      var slug = String($(this).data('color-slug') || '');
      var label = String($(this).data('color-label') || slug || 'Colore');

      if (!slug) slug = label;
      if (!map[slug]) map[slug] = { label: label, qty: 0 };
      map[slug].qty += q;
    });

    var $box = $('#pf-summ-lines');
    if (!$box.length) return;

    var slugs = Object.keys(map);
    if (!slugs.length) {
      $box.html('<div style="font-size:12px; color:#888;">Nessun colore selezionato</div>');
      return;
    }

    var html = '';
    slugs.forEach(function (slug) {
      html += '<div class="pf-summary-line">' +
                '<span>' + escapeHtml(map[slug].label) + '</span>' +
                '<strong>' + map[slug].qty + ' pz</strong>' +
              '</div>';
    });

    $box.html(html);
  }

  // ---------------------------
  // Woo -> PF UI (found_variation)
  // ---------------------------
  $(document).on('found_variation', 'form.variations_form', function (event, variation) {
    if (!variation || !variation.attributes) return;

    var attrs = variation.attributes;
    var colorSlug = attrs['attribute_pa_colore'] || attrs.attribute_pa_colore || '';
    if (!colorSlug) return;

    var $targetSwatch = $('.pf-swatch').filter(function () {
      return String($(this).data('color-slug')) === String(colorSlug);
    }).first();

    if ($targetSwatch.length) {
      $('.pf-swatch').removeClass('selected');
      $targetSwatch.addClass('selected');
      showSizeTableByIndex($targetSwatch.index());
    }
  });

  $(document).on('reset_data', 'form.variations_form', function () {
    $('.pf-size-table').hide();
    $('.pf-swatch').removeClass('selected');
  });

  // ---------------------------
  // PF swatch -> Woo (cambia galleria)
  // ---------------------------
  $(document).on('click', '.pf-swatch', function (e) {
    e.preventDefault();

    var $this = $(this);
    var slug = ($this.data('color-slug') || '').toString().trim();
    if (!slug) return;

    // UI PF
    $('.pf-swatch').removeClass('selected');
    $this.addClass('selected');
    showSizeTableByIndex($this.index());

    // Aggancio Woo select
    var $form = $('form.variations_form').first();
    var $wooSelect = $form.find('select[name="attribute_pa_colore"]');
    if (!$form.length || !$wooSelect.length) return;

    if (String($wooSelect.val()) !== String(slug)) {
      $wooSelect.val(slug).trigger('change');
      // Forziamo l'evento change per dire a Woo di aggiornare la foto
      $form.trigger('check_variations');
    }
  });

  // ---------------------------
  // Quantità
  // ---------------------------
  $(document).on('click', '.pf-qty-btn', function () {
    var $btn = $(this);
    var $input = $btn.siblings('.pf-qty-input');
    var val = parseInt($input.val(), 10) || 0;

    if ($btn.hasClass('plus')) val++;
    else if (val > 0) val--;

    $input.val(val).trigger('change');
  });

  // ---------------------------
  // Stampa (cards)
  // ---------------------------
  $(document).on('click', '.pf-tech-card', function () {
    $('.pf-tech-card').removeClass('selected');
    $(this).addClass('selected');
    
    var code = $(this).data('code');
    $('#pf_print_code').val(code).trigger('change');
  });

  $('#pf_print_code').on('change', function () {
    var code = $(this).val();
    
    // Reset UI Posizioni
    $('.pf-tech-group').hide();
    $('#pf-positions-wrapper').hide();

    if (code) {
        $('#pf-positions-wrapper').slideDown();
        var $group = $('#pf-tech-group-' + code);
        $group.show();
        // Auto-select prima posizione
        $group.find('.pf-loc-card').first().trigger('click');
    } else {
        // Reset se "Nessuna Stampa"
        $('.pf-loc-card').removeClass('selected');
        $('#pf_selected_config_id').val('');
    }

    calculateTotal();
  });

  $(document).on('click', '.pf-loc-card', function () {
    $(this).siblings().removeClass('selected');
    $(this).addClass('selected');
    $('#pf_selected_config_id').val($(this).data('config'));
    // Non ricalcoliamo qui per evitare chiamate doppie, il prezzo dipende solo dal codice stampa
  });

  // ---------------------------
  // Totali live
  // ---------------------------
  $(document).on('change keyup', '.pf-qty-input, #pf_print_code, #pf_print_colors', function () {
    rebuildColorSummary();
    calculateTotal();
  });

  function resetSumm() {
    $('#pf-summ-qty').text('0 pz');
    $('#pf-summ-net').text('€ 0,00');
    $('#pf-summ-print').text('€ 0,00');
    $('#pf-summ-setup').text('€ 0,00');
    $('#pf-summ-total').text('€ 0,00');
  }

  function calculateTotal() {
    var totalQty = 0;
    var merchTotal = 0.0;

    $('.pf-qty-input').each(function () {
      var q = parseInt($(this).val(), 10) || 0;
      var priceStr = String($(this).data('price')).replace(',', '.');
      var p = parseFloat(priceStr) || 0;
      if (q > 0) {
        totalQty += q;
        merchTotal += (q * p);
      }
    });

    $('#pf-summ-qty').text(totalQty + ' pz');
    $('#pf-summ-net').text('€ ' + formatMoney(merchTotal));

    // Reset variabili globali se non c'è stampa
    currentPrintTotal = 0.0;
    currentSetupTotal = 0.0;

    if (totalQty === 0) { resetSumm(); return; }

    var printCode = $('#pf_print_code').val();
    var printColors = parseInt($('#pf_print_colors').val(), 10) || 1;

    if (!printCode) {
        var totalFallback = merchTotal * 1; //metto * 1 per togliere il calcolo dell'iva
        $('#pf-summ-print').text('€ 0,00');
        $('#pf-summ-setup').text('€ 0,00');
        $('#pf-summ-total').text('€ ' + formatMoney(totalFallback));
        return;
    }

    $('#pf-summ-total').css('opacity', '0.5');

    $.ajax({
      type: 'POST',
      url: pf_params.ajax_url,
      data: {
        action: 'pf_calculate_price_ajax',
        security: pf_params.nonce,
        quantity: totalQty,
        print_code: printCode,
        print_colors: printColors
      },
      success: function (response) {
        $('#pf-summ-total').css('opacity', '1');

        if (!response || !response.success) {
          return;
        }

        var d = response.data || {};
        
        // --- 2. SALVIAMO I DATI NELLE VARIABILI GLOBALI ---
        // Nota: rimuovi eventuali virgole se arrivano come stringa formattata, ma dal PHP dovrebbero arrivare numeri o stringhe con punto
        currentPrintTotal = parseFloat(d.print_total) || 0; 
        currentSetupTotal = parseFloat(d.setup_total) || 0;

        var taxable = merchTotal + currentPrintTotal + currentSetupTotal;
        var total = taxable; // Aggiungi IVA se necessario

        $('#pf-summ-print').text('€ ' + formatMoney(currentPrintTotal));
        $('#pf-summ-setup').text('€ ' + formatMoney(currentSetupTotal));
        $('#pf-summ-total').text('€ ' + formatMoney(total));
      },
      error: function (err) {
        $('#pf-summ-total').css('opacity', '1');
      }
    });
  }

  // ---------------------------
  // ADD TO CART (AJAX)
  // ---------------------------
  $(document).off('click.pf', '#pf-add-to-cart-btn').on('click.pf', '#pf-add-to-cart-btn', function (e) {
    e.preventDefault();

    var items = [];
    var totalQty = 0; // Serve per calcolare il costo unitario

    $('.pf-qty-input').each(function () {
      var qty = parseInt($(this).val(), 10) || 0;
      if (qty <= 0) return;
      var name = $(this).attr('name') || '';
      var m = name.match(/\[(\d+)\]/);
      if (!m || !m[1]) return;

      items.push({ variation_id: m[1], quantity: qty });
      totalQty += qty;
    });

    if (!items.length) { alert('Nessun articolo selezionato'); return; }

    var printCode = ($('#pf_print_code').val() || '').trim();
    var configId  = ($('#pf_selected_config_id').val() || '').trim();
    var colors    = parseInt($('#pf_print_colors').val(), 10) || 1;

    if (printCode && !configId) { alert('Seleziona una posizione di stampa.'); return; }

    var formData = new FormData();
    formData.append('action', 'pf_bulk_add_to_cart');
    formData.append('security', pf_params.nonce);

    items.forEach(function (item, i) {
      formData.append('items[' + i + '][variation_id]', item.variation_id);
      formData.append('items[' + i + '][quantity]', item.quantity);
    });

    formData.append('print_data[code]', printCode);
    formData.append('print_data[config_id]', configId);
    formData.append('print_data[colors]', String(colors));

    // --- 3. INVIAMO I PREZZI CALCOLATI ---
    if (printCode && totalQty > 0) {
        // Calcoliamo il costo unitario della stampa: (Totale Stampa / Quantità Totale)
        var unitPrintPrice = currentPrintTotal / totalQty;
        
        formData.append('print_data[unit_print_price]', unitPrintPrice.toFixed(4)); // Es: 0.2350
        formData.append('print_data[setup_total]', currentSetupTotal.toFixed(2));     // Es: 20.00
    }

    var f = document.getElementById('pf_real_file');
    if (f && f.files && f.files.length) {
      formData.append('pf_logo_file', f.files[0]);
    }

    var $btn = $(this);
    $btn.text('Attendere...').prop('disabled', true);

    $.ajax({
      type: 'POST',
      url: pf_params.ajax_url,
      data: formData,
      processData: false,
      contentType: false,
      success: function (res) {
        if (res && res.success) {
          window.location.href = '/cart/';
          return;
        }
        alert((res && res.data) ? res.data : 'Errore durante l\'aggiunta al carrello');
        $btn.text('Aggiungi al carrello 🛒').prop('disabled', false);
      },
      error: function () {
        alert('Errore di connessione. Riprova.');
        $btn.text('Aggiungi al carrello 🛒').prop('disabled', false);
      }
    });
  });

  // ... (INIT resta uguale) ...
  setTimeout(function () {
    var $first = $('.pf-swatch').first();
    if ($first.length) $first.trigger('click');
    rebuildColorSummary();
    calculateTotal();
  }, 500);

});