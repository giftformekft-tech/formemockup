(function($){
  var box = $('#mg-parent-search');
  var results = $('#mg-parent-results');
  var hidden = $('#mg-parent-id');
  var timer = null;

  function renderError(text){
    results.removeClass('loading').addClass('error').html('<div class="mg-error">'+$('<div>').text(text).html()+'</div>');
  }
  function search(q){
    results.removeClass('error').empty().addClass('loading').text('Keresés...');
    $.ajax({
      url: MG_SEARCH.ajax_url,
      method: 'GET',
      dataType: 'json',
      data: { action:'mg_search_products', nonce:MG_SEARCH.nonce, q:q }
    }).done(function(resp, status, xhr){
      results.removeClass('loading').empty();
      if (!resp || resp.success !== true){
        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Ismeretlen hiba a válaszban.';
        renderError(msg);
        return;
      }
      var list = resp.data || [];
      if (!list.length){ results.append('<div class="mg-item none">Nincs találat</div>'); return; }
      list.forEach(function(it){
        var el = $('<div class="mg-item">').text('#'+it.id+' — '+it.title).data('id', it.id);
        results.append(el);
      });
    }).fail(function(xhr){
      var msg = 'Hiba történt a keresés közben.';
      if (xhr && xhr.status){ msg += ' (HTTP '+xhr.status+')'; }
      if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){
        msg += ' — ' + xhr.responseJSON.data.message;
      } else if (xhr && xhr.responseText){
        msg += ' — ' + xhr.responseText.substring(0,180);
      }
      renderError(msg);
    });
  }

  box.on('input', function(){
    var q = $(this).val().trim();
    clearTimeout(timer);
    if (!q){ results.empty().removeClass('error loading'); hidden.val('0'); return; }
    timer = setTimeout(function(){ search(q); }, 250);
  });
  results.on('click', '.mg-item', function(){
    var id = $(this).data('id');
    if (!id){ return; }
    hidden.val(id);
    results.removeClass('error loading').html('<div class="mg-picked">Kiválasztva: '+$(this).text()+'</div>');
  });
})(jQuery);
