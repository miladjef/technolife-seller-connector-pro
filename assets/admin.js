(function($){
  function showResult(data, ok){
    var $box = $('#tlscp-ajax-result');
    if (!$box.length) { $box = $('<div id="tlscp-ajax-result"></div>').appendTo('.tlscp-wrap'); }
    var txt = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
    $box.removeClass().addClass('tlscp-result ' + (ok ? 'ok':'err')).text(txt);
  }
  function post(action, data){
    data = data || {}; data.action = action; data.nonce = TLSCP.nonce;
    showResult('در حال اجرا...', true);
    return $.post(TLSCP.ajaxurl, data).done(function(resp){
      var ok = !!(resp && (resp.success === true || resp.success === undefined || (resp.data && resp.data.success)));
      showResult(resp, ok);
    }).fail(function(xhr){ showResult(xhr.responseText || 'خطای ارتباط با وردپرس', false); });
  }
  $(document).on('click','#tlscp-test-connection',function(e){e.preventDefault();post('tlscp_test_connection');});
  $(document).on('click','.tlscp-sync-product',function(e){e.preventDefault();post('tlscp_sync_product',{product_id:$(this).data('product-id')});});
  $(document).on('click','#tlscp-sync-all',function(e){e.preventDefault();post('tlscp_sync_all',{price:$(this).data('price'),inventory:$(this).data('inventory')});});
  $(document).on('click','#tlscp-import-orders',function(e){e.preventDefault();post('tlscp_import_orders');});
  $(document).on('click','.tlscp-retry-log',function(e){e.preventDefault();post('tlscp_retry_log',{log_id:$(this).data('log-id')});});
})(jQuery);
