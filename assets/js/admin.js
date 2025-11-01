(function($){
  function log(msg){ $('#mg-bulk-log').append($('<div/>').text(msg)); var el = $('#mg-bulk-log')[0]; el.scrollTop = el.scrollHeight; }

  var CONCURRENCY = 3;
  var queue = [], active = 0, done = 0, total = 0;

  function startBulk(files, keys){
    $('#mg-bulk-status').addClass('is-visible').show();
    total = files.length;
    $('#mg-bulk-total').text(total);
    queue = Array.from(files);
    pump(keys);
  }
  function pump(keys){
    while (active < CONCURRENCY && queue.length){
      var file = queue.shift();
      active++; processOne(file, keys).always(function(){
        active--; done++;
        var percent = Math.round(done/total*100);
        $('#mg-bulk-count').text(done);
        $('#mg-bulk-percent').text(percent);
        $('#mg-bulk-bar').css('width', percent+'%');
        if (done >= total){ log('Kész.'); }
        else { pump(keys); }
      });
    }
  }
  function processOne(file, keys){
    var fd = new FormData();
    fd.append('action','mg_bulk_process_one');
    fd.append('nonce', MG_AJAX.nonce);
    fd.append('parent_name', file.name.replace(/\.[^.]+$/, ''));
    keys.forEach(k => fd.append('product_keys[]', k));
    fd.append('design', file, file.name);
    return $.ajax({ url: MG_AJAX.ajax_url, method: 'POST', data: fd, processData: false, contentType: false })
      .done(function(resp){
        if(resp && resp.success){ log('OK: '+file.name+' → ID '+resp.data.product_id); }
        else { log('Hiba: '+file.name+' → '+ (resp && resp.data && resp.data.message ? resp.data.message : 'ismeretlen hiba')); }
      }).fail(function(xhr){
        var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'HTTP hiba';
        log('Hiba: '+file.name+' → '+msg);
      });
  }

  $(document).on('click','#mg-bulk-start', function(e){
    e.preventDefault();
    var files = $('#mg-bulk-files')[0].files;
    if(!files || !files.length){ alert('Válassz ki legalább egy képfájlt!'); return; }
    var keys = $('.mg-bulk-type:checked').map(function(){return this.value;}).get();
    if(!keys.length){ alert('Válassz ki legalább egy terméktípust!'); return; }
    $('#mg-bulk-log').empty(); $('#mg-bulk-count').text(0); $('#mg-bulk-percent').text(0); $('#mg-bulk-bar').css('width','0%');
    startBulk(files, keys);
  });
})(jQuery);
