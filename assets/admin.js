(function($){
  function nf(n){ if(n===null||n===undefined||n==='') return '-'; var x=Number(n); if(isNaN(x)) return String(n); return x.toLocaleString('en-US'); }
  function esc(s){ return $('<div>').text(s===undefined||s===null?'':String(s)).html(); }

  function showResult(data, ok, sel){
    var $box = $(sel || '#tlscp-ajax-result');
    if (!$box.length) { $box = $('<div id="tlscp-ajax-result"></div>').appendTo('.tlscp-wrap'); }
    var txt = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
    $box.removeClass().addClass('tlscp-result ' + (ok ? 'ok':'err')).text(txt);
  }
  function post(action, data){
    data = data || {}; data.action = action; data.nonce = TLSCP.nonce;
    return $.post(TLSCP.ajaxurl, data);
  }

  // --- عملیات پایه ---
  $(document).on('click','#tlscp-test-connection',function(e){e.preventDefault();showResult('در حال اجرا...',true);post('tlscp_test_connection').done(function(r){showResult(r,!!(r&&r.success));}).fail(function(x){showResult(x.responseText||'خطا',false);});});
  $(document).on('click','.tlscp-sync-product',function(e){e.preventDefault();var id=$(this).data('product-id');showResult('در حال ارسال...',true);post('tlscp_sync_product',{product_id:id}).done(function(r){showResult(r,!!(r&&r.success));}).fail(function(x){showResult(x.responseText||'خطا',false);});});
  $(document).on('click','#tlscp-sync-all',function(e){e.preventDefault();showResult('در حال همگام‌سازی...',true);post('tlscp_sync_all',{price:$(this).data('price'),inventory:$(this).data('inventory')}).done(function(r){showResult(r,!!(r&&(r.failed===0)));}).fail(function(x){showResult(x.responseText||'خطا',false);});});
  $(document).on('click','#tlscp-import-orders',function(e){e.preventDefault();showResult('در حال دریافت سفارش‌ها...',true);post('tlscp_import_orders').done(function(r){showResult(r,!!(r&&r.success));}).fail(function(x){showResult(x.responseText||'خطا',false);});});
  $(document).on('click','.tlscp-retry-log',function(e){e.preventDefault();showResult('در حال اجرای مجدد...',true);post('tlscp_retry_log',{log_id:$(this).data('log-id')}).done(function(r){showResult(r,!!(r&&r.success));}).fail(function(x){showResult(x.responseText||'خطا',false);});});

  // --- اطلاعات زنده تنوع (متاباکس) ---
  $(document).on('click','.tlscp-load-item-info',function(e){
    e.preventDefault();
    var id=$(this).data('product-id');
    var $box=$('.tlscp-item-info[data-for="'+id+'"]');
    $box.html('<p>در حال دریافت...</p>');
    post('tlscp_item_info',{product_id:id}).done(function(r){
      if(!r||!r.success){ $box.html('<p class="tlscp-danger">'+esc(r&&r.message?r.message:'خطا')+'</p>'); return; }
      var it=r.item||{};
      var cash=(it.cash&&it.cash.price!==undefined)?it.cash.price:null;
      var bb=it.isWinnerOfBuyBox?'<span class="tlscp-badge tlscp-ok">برنده</span>':'<span class="tlscp-badge tlscp-error">بازنده</span>';
      var rows=''
        +'<tr><td>وضعیت بای‌باکس</td><td>'+bb+'</td></tr>'
        +'<tr><td>قیمت برنده بای‌باکس</td><td class="ltr">'+nf(it.buyBoxWinnerPrice)+'</td></tr>'
        +'<tr><td>قیمت نقدی فعلی من</td><td class="ltr">'+nf(cash)+'</td></tr>'
        +'<tr><td>موجودی (available)</td><td class="ltr">'+nf(it.available)+'</td></tr>'
        +'<tr><td>انبارش (stock)</td><td class="ltr">'+nf(it.stock)+'</td></tr>'
        +'<tr><td>در فرایند فروش</td><td class="ltr">'+nf(it.processingCount)+'</td></tr>'
        +'<tr><td>در انتظار ارسال</td><td class="ltr">'+nf(it.waitingCount)+'</td></tr>'
        +'<tr><td>در انتظار پرداخت</td><td class="ltr">'+nf(it.waitingForPaymentCount)+'</td></tr>'
        +'<tr><td>نمایش</td><td>'+(it.hide?'مخفی':'نمایش')+'</td></tr>';
      $box.html('<table class="widefat striped tlscp-info-table" style="margin-top:8px"><tbody>'+rows+'</tbody></table>');
    }).fail(function(){ $box.html('<p class="tlscp-danger">خطای ارتباط</p>'); });
  });

  // --- اسکن بای‌باکس ---
  $(document).on('click','#tlscp-scan-buybox',function(e){
    e.preventDefault();
    var $r=$('#tlscp-buybox-result'); $r.html('<p>در حال اسکن... (ممکن است چند ثانیه طول بکشد)</p>');
    post('tlscp_scan_buybox').done(function(r){
      if(!r){ $r.html('<p class="tlscp-danger">خطا</p>'); return; }
      var html='<p>اسکن‌شده: '+nf(r.scanned)+' | برنده: '+nf(r.winners)+' | بازنده: <strong>'+nf(r.losers)+'</strong> | ناموفق: '+nf(r.failed)+'</p>';
      if(r.losers_list&&r.losers_list.length){
        html+='<table class="widefat striped"><thead><tr><th>محصول</th><th>SellerItemCode</th><th>قیمت من</th><th>قیمت برنده</th><th>اختلاف</th></tr></thead><tbody>';
        r.losers_list.forEach(function(l){
          html+='<tr><td>'+esc(l.title)+'</td><td class="ltr">'+esc(l.seller_item_code)+'</td><td class="ltr">'+nf(l.my_price)+'</td><td class="ltr">'+nf(l.winner_price)+'</td><td class="ltr">'+nf(l.gap)+'</td></tr>';
        });
        html+='</tbody></table>';
      }
      $r.html(html);
    }).fail(function(){ $r.html('<p class="tlscp-danger">خطای ارتباط</p>'); });
  });

  // --- پروموشن ---
  $(document).on('click','#tlscp-promo-load',function(e){
    e.preventDefault();
    var code=$.trim($('#tlscp-promo-product-code').val());
    var $r=$('#tlscp-promo-load-result');
    if(!code){ $r.html('<p class="tlscp-danger">کد محصول را وارد کنید.</p>'); return; }
    $r.html('<p>در حال بارگذاری...</p>');
    post('tlscp_promo_list',{product_code:code}).done(function(pl){
      if(!pl||!pl.success){ $r.html('<p class="tlscp-danger">'+esc(pl&&pl.message?pl.message:'خطا در دریافت پروموشن')+'</p>'); return; }
      var $g=$('#tlscp-promo-group').empty();
      (pl.items||[]).forEach(function(p){ $g.append($('<option>').val(p.id).text((p.name||'')+' ('+p.id+')')); });
      if(!(pl.items||[]).length){ $g.append($('<option>').val('').text('— پروموشنی یافت نشد —')); }
      post('tlscp_promo_items',{product_code:code}).done(function(pi){
        var $tb=$('#tlscp-promo-items tbody').empty();
        if(pi&&pi.success&&(pi.items||[]).length){
          pi.items.forEach(function(it){
            var cash=(it.cash&&it.cash.price!==undefined)?it.cash.price:null;
            var bb=it.isWinnerOfBuyBox?'برنده':'بازنده';
            $tb.append('<tr><td><input type="checkbox" class="tlscp-promo-item" value="'+esc(it.code)+'"></td><td class="ltr">'+esc(it.code)+'</td><td>'+esc(it.variation||'')+'</td><td class="ltr">'+nf(cash)+'</td><td>'+bb+'</td></tr>');
          });
        } else {
          $tb.append('<tr><td colspan="5">تنوعی یافت نشد.</td></tr>');
        }
        $('#tlscp-promo-builder').show();
        $r.html('<p class="tlscp-result ok">بارگذاری شد. '+(pl.items||[]).length+' پروموشن، '+((pi&&pi.items)?pi.items.length:0)+' تنوع.</p>');
      });
    }).fail(function(){ $r.html('<p class="tlscp-danger">خطای ارتباط</p>'); });
  });
  $(document).on('change','#tlscp-promo-all',function(){ $('.tlscp-promo-item').prop('checked',$(this).prop('checked')); });
  $(document).on('click','#tlscp-promo-apply',function(e){
    e.preventDefault();
    var codes=$('.tlscp-promo-item:checked').map(function(){return $(this).val();}).get();
    var $r=$('#tlscp-promo-apply-result');
    if(!codes.length){ $r.html('<p class="tlscp-danger">حداقل یک تنوع را انتخاب کنید.</p>'); return; }
    $r.html('<p>در حال اعمال تخفیف...</p>');
    post('tlscp_promo_apply',{
      seller_item_codes:codes,
      count:$('#tlscp-promo-count').val(),
      discountedPercent:$('#tlscp-promo-percent').val(),
      discountedPrice:$('#tlscp-promo-price').val(),
      marketingGroup:$('#tlscp-promo-group').val(),
      startDate:$('#tlscp-promo-start').val(),
      endDate:$('#tlscp-promo-end').val()
    }).done(function(r){
      if(!r){ $r.html('<p class="tlscp-danger">خطا</p>'); return; }
      if(r.message&&!r.items){ $r.html('<p class="tlscp-danger">'+esc(r.message)+'</p>'); return; }
      var html='<p>موفق: '+nf(r.ok)+' | ناموفق: '+nf(r.failed)+'</p>';
      (r.items||[]).forEach(function(i){ html+='<div class="tlscp-result '+(i.success?'ok':'err')+'">'+esc(i.seller_item_code)+': '+esc(i.message)+'</div>'; });
      $r.html(html);
    }).fail(function(){ $r.html('<p class="tlscp-danger">خطای ارتباط</p>'); });
  });

  // --- ساخت تنوع ---
  $(document).on('click','#tlscp-var-load',function(e){
    e.preventDefault();
    var code=$.trim($('#tlscp-var-product-code').val());
    var $r=$('#tlscp-var-load-result');
    if(!code){ $r.html('<p class="tlscp-danger">کد محصول را وارد کنید.</p>'); return; }
    $r.html('<p>در حال بارگذاری...</p>');
    post('tlscp_var_options',{product_code:code}).done(function(r){
      if(!r||!r.success){ $r.html('<p class="tlscp-danger">'+esc(r&&r.message?r.message:'خطا')+'</p>'); return; }
      var $v=$('#tlscp-var-variation').empty(), $g=$('#tlscp-var-guarantee').empty();
      (r.variations||[]).forEach(function(v){ $v.append($('<option>').val(v.id).text((v.displayTitle||v.name||'')+' ('+v.id+')')); });
      (r.guarantees||[]).forEach(function(g){ $g.append($('<option>').val(g.id).text((g.name||'')+' ('+g.id+')')); });
      if(!(r.variations||[]).length) $v.append($('<option>').val('').text('— تنوعی یافت نشد —'));
      if(!(r.guarantees||[]).length) $g.append($('<option>').val('').text('— گارانتی یافت نشد —'));
      $('#tlscp-var-form,#tlscp-var-actions').show();
      $r.html('<p class="tlscp-result ok">'+(r.variations||[]).length+' تنوع و '+(r.guarantees||[]).length+' گارانتی بارگذاری شد.</p>');
    }).fail(function(){ $r.html('<p class="tlscp-danger">خطای ارتباط</p>'); });
  });
  $(document).on('click','#tlscp-var-create',function(e){
    e.preventDefault();
    var $r=$('#tlscp-var-create-result'); $r.html('<p>در حال ساخت تنوع...</p>');
    post('tlscp_create_variation',{
      product_code:$.trim($('#tlscp-var-product-code').val()),
      variation_id:$('#tlscp-var-variation').val(),
      guarantee_id:$('#tlscp-var-guarantee').val()
    }).done(function(r){
      $r.html('<div class="tlscp-result '+(r&&r.success?'ok':'err')+'">'+esc(r&&r.message?r.message:'خطا')+'</div>');
    }).fail(function(){ $r.html('<p class="tlscp-danger">خطای ارتباط</p>'); });
  });
})(jQuery);
