(function($){
  'use strict';

  var cfg = window.RIPEX_CHECKOUT_CREDIT || {};
  if (!cfg.enabled) return;

  function text(value, fallback) {
    value = (value || '').toString().trim();
    return value || fallback || '';
  }

  function showExistingCheque() {
    var $existing = $('.payment_method_cheque, li.wc_payment_method.payment_method_cheque');
    if (!$existing.length) return false;

    $existing
      .addClass('ripex-credit-fallback-added')
      .attr('aria-hidden', 'false')
      .show()
      .css({ display: 'block', visibility: 'visible', opacity: 1 });

    $existing.find('input[name="payment_method"][value="cheque"]').prop('disabled', false);

    var $label = $existing.find('label[for="payment_method_cheque"]').first();
    if ($label.length && text(cfg.title)) {
      var $icon = $label.find('img, svg').detach();
      $label.text(text(cfg.title, 'Pago a crédito'));
      if ($icon.length) $label.append($icon);
    }

    var $box = $existing.find('.payment_box.payment_method_cheque, .payment_box').first();
    if ($box.length && text(cfg.description)) {
      $box.html('<p>' + text(cfg.description, 'Pago a crédito habilitado para tu cuenta.') + '</p>');
    }

    return true;
  }

  function injectChequeFallback() {
    var $methods = $('ul.wc_payment_methods, .wc_payment_methods').first();
    if (!$methods.length) return;
    if ($('#payment_method_cheque').length || $('.payment_method_cheque').length) return;

    var title = text(cfg.title, 'Pago a crédito');
    var description = text(cfg.description, 'Pago a crédito habilitado para tu cuenta.');

    var html = '' +
      '<li class="wc_payment_method payment_method_cheque ripex-credit-fallback-added">' +
        '<input id="payment_method_cheque" type="radio" class="input-radio" name="payment_method" value="cheque" data-order_button_text="" />' +
        '<label for="payment_method_cheque">' + title + '</label>' +
        '<div class="payment_box payment_method_cheque" style="display:none;"><p>' + description + '</p></div>' +
      '</li>';

    $methods.append(html);
  }

  function syncPaymentBoxes() {
    var checked = $('input[name="payment_method"]:checked').val();
    $('.payment_box').hide();
    if (checked) $('.payment_box.payment_method_' + checked).show();
  }

  function ensureCreditPaymentVisible() {
    if (!showExistingCheque()) {
      injectChequeFallback();
      showExistingCheque();
    }
    syncPaymentBoxes();
  }

  $(document.body).on('updated_checkout payment_method_selected', function(){
    window.setTimeout(ensureCreditPaymentVisible, 60);
  });

  $(document).on('change', 'input[name="payment_method"]', function(){
    syncPaymentBoxes();
    $(document.body).trigger('payment_method_selected');
  });

  $(function(){
    ensureCreditPaymentVisible();
    window.setTimeout(ensureCreditPaymentVisible, 300);
    window.setTimeout(ensureCreditPaymentVisible, 1000);
  });
})(jQuery);
