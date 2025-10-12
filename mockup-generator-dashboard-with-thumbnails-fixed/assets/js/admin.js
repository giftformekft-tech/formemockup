(function($){
  function log(msg){ $('#mg-bulk-log').append($('<div/>').text(msg)); var el = $('#mg-bulk-log')[0]; el.scrollTop = el.scrollHeight; }

  var queue = [], done = 0, total = 0;

  function startBulk(files, keys){
    resetState();
    running = true;
    $('#mg-bulk-status').show();
    total = files.length;
    done = 0;
    $('#mg-bulk-total').text(total);
    queue = Array.from(files);
    updateQueueProgress();
    pump(keys);
  }
  function pump(keys){
    if (!queue.length){ return; }
    var file = queue.shift();
    processOne(file, keys).always(function(){
      done++;
      var percent = Math.round(done/total*100);
      $('#mg-bulk-count').text(done);
      $('#mg-bulk-percent').text(percent);
      $('#mg-bulk-bar').css('width', percent+'%');
      if (done >= total){ log('Kész.'); }
      else { pump(keys); }
    });
  }
  function processOne(file, keys){
    var fd = new FormData();
    fd.append('action','mg_bulk_process_one');
    fd.append('nonce', MG_AJAX.nonce);
    fd.append('parent_name', file.name.replace(/\.[^.]+$/, ''));
    keys.forEach(function(k){ fd.append('product_keys[]', k); });
    fd.append('design', file, file.name);
    return $.ajax({ url: MG_AJAX.ajax_url, method: 'POST', data: fd, processData: false, contentType: false, dataType:'json' })
      .done(function(resp){
        if(resp && resp.success && resp.data && resp.data.job_id){
          var jobId = resp.data.job_id;
          jobIds.push(jobId);
          jobMeta[jobId] = { name: file.name };
          log('Sorba állítva: '+file.name);
        } else {
          var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Ismeretlen hiba';
          log('Hiba: '+file.name+' → '+msg);
        }
      }).fail(function(xhr){
        var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'HTTP hiba';
        log('Hiba: '+file.name+' → '+msg);
      });
  }

  function startPolling(){
    if (!jobIds.length){ running = false; return; }
    var interval = parseInt(MG_AJAX.poll_interval, 10);
    if (!interval || interval < 1000){ interval = 4000; }
    function poll(){
      $.ajax({
        url: MG_AJAX.ajax_url,
        method: 'POST',
        dataType: 'json',
        data: {
          action: 'mg_bulk_queue_status',
          nonce: MG_AJAX.nonce,
          job_ids: jobIds
        }
      }).done(function(resp){
        if (resp && resp.success && resp.data){
          var stats = resp.data.stats || {};
          var jobs = resp.data.jobs || [];
          var finished = (stats.completed || 0) + (stats.failed || 0);
          var percent = stats.percent || 0;
          $('#mg-bulk-count').text(finished);
          $('#mg-bulk-percent').text(percent);
          $('#mg-bulk-bar').css('width', percent+'%');
          jobs.forEach(function(job){
            var meta = jobMeta[job.id] || {};
            var label = meta.name ? meta.name : job.id;
            if (lastStates[job.id] === job.status){ return; }
            lastStates[job.id] = job.status;
            if (job.status === 'running'){
              log('Fut: '+label);
            } else if (job.status === 'completed'){
              var pid = job.product_id ? ' → ID '+job.product_id : '';
              log('Kész: '+label+pid);
            } else if (job.status === 'failed'){
              var message = job.message || 'Ismeretlen hiba';
              log('Hiba: '+label+' → '+message);
            }
          });
          if (stats.total && finished >= stats.total){
            clearInterval(pollTimer);
            pollTimer = null;
            running = false;
            log('Háttérfeldolgozás befejeződött.');
          }
        }
      });
    }
    poll();
    if (pollTimer){ clearInterval(pollTimer); }
    pollTimer = setInterval(poll, interval);
  }

  $(document).on('click','#mg-bulk-start', function(e){
    e.preventDefault();
    if (running){ return; }
    var files = $('#mg-bulk-files')[0].files;
    if(!files || !files.length){ alert('Válassz ki legalább egy képfájlt!'); return; }
    var keys = $('.mg-bulk-type:checked').map(function(){return this.value;}).get();
    if(!keys.length){ alert('Válassz ki legalább egy terméktípust!'); return; }
    $('#mg-bulk-log').empty(); $('#mg-bulk-count').text(0); $('#mg-bulk-percent').text(0); $('#mg-bulk-bar').css('width','0%');
    startBulk(files, keys);
  });
})(jQuery);
