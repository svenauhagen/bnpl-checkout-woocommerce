<script>
  var shouldShowMondu = true;
  var order_id = 0;
  var orderpay = false;
  var result = '';
  var url = '<?php echo get_site_url(null, '/index.php'); ?>';

  function monduBlock() {
    shouldShowMondu = false;
    jQuery('.woocommerce-checkout-payment, .woocommerce-checkout-review-order-table').block({
      message: null,
      overlayCSS: {
        background: '#fff',
        opacity: 0.6
      }
    });
  }

  function monduUnblock() {
    jQuery('.woocommerce-checkout-payment, .woocommerce-checkout-review-order-table').unblock();
    shouldShowMondu = true;
  }

  function isGatewayMondu(currentGateway) {
    return ['mondu_invoice', 'mondu_direct_debit', 'mondu_installment'].includes(currentGateway);
  }

  function isMondu() {
    return isGatewayMondu(jQuery('input[name=payment_method]:checked').val());
  }

  function payWithMondu() {
    if (shouldShowMondu === false) {
      return;
    }
    monduBlock();

    if (orderpay === false) {
      $data = jQuery('form.woocommerce-checkout').serialize();
    } else {
      $data = jQuery('form#order_review').serialize() + "&orderpay=yes" + "&order_id=" + order_id;
    }

    jQuery.ajax({
      type: 'POST',
      url: `${url}?rest_route=/mondu/v1/orders/create`,
      data: $data,
      dataType: 'json',
      success: function(res) {
        // TODO: check refresh and reload
        if (res['token']) {
          let token = res['token'];
          renderWidget(token);
        } else {
          monduUnblock();
          handleErrors(res.errors);
        }
      },
      fail: function(err) {
        handleErrors();
      }
    });
  }

  function renderWidget(token) {
    window.monduCheckout.render({
      token,
      onClose: () => {
        monduUnblock();
        checkoutCallback();
        result = '';
        return new Promise((resolve) => resolve())
      },
      onSuccess: () => {
        console.log('Success');
        result = 'success';
        return new Promise((resolve) => resolve())
      },
      onError: (err) => {
        console.log('Error occurred', err);
        result = 'error';
        return new Promise((resolve) => resolve())
      },
    });
  }

  function checkoutCallback() {
    if (result === 'success') {
      jQuery('#place_order').parents('form').submit();
    } else {
      jQuery(document.body).trigger('wc_update_cart');
      jQuery(document.body).trigger('update_checkout');
      window.monduCheckout.destroy();
    }
  }

  function handleErrors(error_message = null) {
    var scrollElement = jQuery('.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout');

    if (orderpay)
      var $checkout_form = jQuery('form#order_review');
    else
      var $checkout_form = jQuery('form.checkout');

    if (!scrollElement.length ) {
      scrollElement = $checkout_form;
    }

    if (!error_message) {
      if (orderpay)
        var $checkout_form = jQuery('form#order_review');
      else
        var $checkout_form = jQuery('form.woocommerce-checkout');

      $checkout_form.prepend(
        '<div class="woocommerce-error">' +
        '<?php echo __('Error processing checkout. Please try again.', 'mondu'); ?>' +
        '</div>'
      );
      jQuery.scroll_to_notices( scrollElement );
      return;
    }
    // from woocommerce checkout.js submit_error function line 570
    jQuery('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
    $checkout_form.prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' + error_message + '</div>');
    $checkout_form.removeClass('processing').unblock();
    $checkout_form.find('.input-text, select, input:checkbox').trigger('validate').trigger('blur');
    var scrollElement = jQuery('.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout');

    jQuery.scroll_to_notices( scrollElement );
    jQuery( document.body ).trigger( 'checkout_error' , [ error_message ] );
  }

  jQuery(document).ready(function () {
    if ( jQuery( document.body ).hasClass( 'woocommerce-order-pay' ) ) {
      jQuery( '#order_review' ).on( 'submit', function (e) {
        if (!isMondu()) return true;
        if (result ==='success') return true;

        e.preventDefault();
        orderpay = true;
        order_id = <?php echo $order_id; ?>;

        if (shouldShowMondu === true) {
          payWithMondu();
          return false;
        }
        return false;
      });
    } else {
      jQuery('form.woocommerce-checkout').on('checkout_place_order', function (e) {
        if (!isMondu()) return true;
        if (result ==='success') return true;

        if (shouldShowMondu === true) {
          payWithMondu();
          return false;
        }
        return false;
      });
    }
  });
</script>

<p>
  <?php
    printf(wp_kses(__('Hinweise zur Verarbeitung Ihrer personenbezogenen Daten durch die Mondu GmbH finden Sie <a href="https://mondu.ai/de/datenschutzgrundverordnung-kaeufer" target="_blank">hier</a>.', 'mondu'), array('a' => array('href' => array(), 'target' => array()))));
  ?>
</p>
