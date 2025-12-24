jQuery(function ($) {

  // ---------------------------
  // Helper: mostra tabella quantità per indice swatch
  // ---------------------------
  function showSizeTableByIndex(index) {
    $('.pf-size-table').hide();
    $('.pf-size-table').eq(index).show();
  }

  // ---------------------------
  // 1) Woo -> PF UI (quando Woo trova una variazione)
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

      var idx = $targetSwatch.index();
      showSizeTableByIndex(idx);
      // console.log('[PF] Sync from Woo color=', colorSlug);
    }
  });

  $(document).on('reset_data', 'form.variations_form', function () {
    $('.pf-size-table').hide();
    $('.pf-swatch').removeClass('selected');
  });

  // ---------------------------
  // 2) PF swatch -> Woo (QUESTO È IL PEZZO CHE FA CAMBIARE LA GALLERIA)
  // ---------------------------
  $(document).on('click', '.pf-swatch', function (e) {
    e.preventDefault();

    var $this = $(this);
    var slug = ($this.data('color-slug') || '').toString().trim();

    if (!slug) {
      console.error('[PF] Manca data-color-slug nello swatch');
      return;
    }

    var $form = $('form.variations_form').first();
    var $wooSelect = $form.find('select[name="attribute_pa_colore"]');

    if (!$form.length || !$wooSelect.length) {
      console.error('[PF] variations_form o select attribute_pa_colore non trovata');
      return;
    }

    // set valore e trigger change -> Woo aggiorna variazione -> plugin galleria aggiorna immagini
    $wooSelect.val(slug).trigger('change');

    // Forza check variazioni (alcuni temi/plugin lo richiedono)
    $form.trigger('check_variations');
  });

  // ---------------------------
  // 3) Quantità
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
  // 4) Stampa (cards)
  // ---------------------------
  $(document).on('click', '.pf-tech-card', function () {
    $('.pf-tech-card').removeClass('selected');
    $(this).addClass('selected');
    $('#pf_print_code').val($(this).data('code')).trigger('change');
  });

  $('#pf_print_code').on('change', function () {
    var code = $(this).val();
    $('.pf-tech-group').hide();

    if (code) {
      $('#pf-positions-wrapper').slideDown();
      $('#pf-tech-group-' + code).show();
      $('#pf-tech-group-' + code + ' .pf-loc-card').first().trigger('click');
    } else {
      $('#pf-positions-wrapper').slideUp();
      $('.pf-loc-card').removeClass('selected');
      $('#pf_selected_config_id').val('');
    }
    calculateTotal();
  });

  $(document).on('click', '.pf-loc-card', function () {
    $(this).siblings().removeClass('selected');
    $(this).addClass('selected');
    $('#pf_selected_config_id').val($(this).data('config'));
    calculateTotal();
  });

  // ---------------------------
  // 5) Calcolo live
  // ---------------------------
  $(document).on('change keyup', '.pf-qty-input, #pf_print_code, #pf_print_colors', function () {
    calculateTotal();
  });

  function resetSumm() {
    $('#pf-summ-print').text('€ 0,00');
    $('#pf-summ-setup').text('€ 0,00');
    $('#pf-summ-total').text('€ 0,00');
  }

  function calculateTotal() {
    var totalQty = 0;
    var merchTotal = 0.0;

    $('.pf-qty-input').each(function () {
      var q = parseInt($(this).val(), 10) || 0;
      var p = parseFloat($(this).data('price')) || 0;
      if (q > 0) {
        totalQty += q;
        merchTotal += (q * p);
      }
    });

    $('#pf-summ-qty').text(totalQty + ' pz');
    $('#pf-summ-net').text('€ ' + merchTotal.toFixed(2).replace('.', ','));

    if (totalQty === 0) { resetSumm(); return; }

    var printCode = $('#pf_print_code').val();
    var printColors = parseInt($('#pf_print_colors').val(), 10) || 1;
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

        if (response && response.success) {
          var d = response.data || {};
          var printCost = parseFloat(d.print_total) || 0;
          var setupCost = parseFloat(d.setup_total) || 0;

          var taxable = merchTotal + printCost + setupCost;
          var vat = taxable * 0.22;
          var total = taxable + vat;

          $('#pf-summ-print').text('€ ' + printCost.toFixed(2).replace('.', ','));
          $('#pf-summ-setup').text('€ ' + setupCost.toFixed(2).replace('.', ','));
          $('#pf-summ-total').text('€ ' + total.toFixed(2).replace('.', ','));
        }
      },
      error: function () {
        $('#pf-summ-total').css('opacity', '1');
      }
    });
  }

  // ---------------------------
  // 6) Add to cart (AJAX + FILE) - invariato
  // ---------------------------
  $(document).on('click', '#pf-add-to-cart-btn', function (e) {
    e.preventDefault();

    var items = [];
    $('.pf-qty-input').each(function () {
      var qty = parseInt($(this).val(), 10) || 0;
      if (qty > 0) {
        var inputName = $(this).attr('name');
        var match = inputName && inputName.match(/\[(\d+)\]/);
        if (match && match[1]) items.push({ variation_id: match[1], quantity: qty });
      }
    });

    if (!items.length) { alert('Seleziona quantità'); return; }

    var printCode = $('#pf_print_code').val();
    var configId = $('#pf_selected_config_id').val();
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
    formData.append('print_data[colors]', $('#pf_print_colors').val());

    var f = document.getElementById('pf_real_file');
    if (f && f.files && f.files.length) formData.append('pf_logo_file', f.files[0]);

    var $btn = $(this);
    $btn.text('Attendere...').prop('disabled', true);

    $.ajax({
      type: 'POST',
      url: pf_params.ajax_url,
      data: formData,
      processData: false,
      contentType: false,
      success: function (res) {
        if (res && res.success) window.location.href = '/cart/';
        else {
          alert(res && res.data ? res.data : 'Errore');
          $btn.text('Aggiungi al carrello').prop('disabled', false);
        }
      },
      error: function () {
        alert('Errore');
        $btn.text('Aggiungi al carrello').prop('disabled', false);
      }
    });
  });

  // ---------------------------
  // INIT
  // ---------------------------
  setTimeout(function () {
    var $first = $('.pf-swatch').first();
    if ($first.length) $first.trigger('click');
  }, 300);

});
