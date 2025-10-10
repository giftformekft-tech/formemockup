
(function($){
  function basename(name){ return (name||'').replace(/\.[^.]+$/, ''); }
  function buildMainSelect(){
    var html = '<select class="mg-main">';
    html += '<option value="0">— Nincs —</option>';
    (MG_BULK_ADV.mains||[]).forEach(function(m){
      html += '<option value="'+m.id+'">'+m.name+'</option>';
    });
    html += '</select>';
    return $(html);
  }
  function buildSubMulti(parentId){
    var list = (MG_BULK_ADV.subs && MG_BULK_ADV.subs[parentId]) ? MG_BULK_ADV.subs[parentId] : [];
    var html = '<select class="mg-subs" multiple size="3">';
    list.forEach(function(s){ html += '<option value="'+s.id+'">'+s.name+'</option>'; });
    html += '</select>';
    return $(html);
  }
  function refreshSubSelect($row, parentId){
    var $current = $row.find('select.mg-subs');
    var $new = buildSubMulti(parentId);
    if ($current.length){ $current.replaceWith($new); }
    else { $row.find('td').eq(4).append($new); }
    return $new;
  }
  function collectSubValues($sel){ var out=[]; $sel.find('option:selected').each(function(){ out.push($(this).val()); }); return out; }

  function mgEnsureHeader(){
    var $thead = $('.mg-bulk-table thead tr');
    if ($thead.length) {
      if ($thead.find('th:contains("Előnézet")').length === 0) { $('<th>Előnézet</th>').insertAfter($thead.find('th').first()); }
    }
    if ($thead.length && $thead.find('th:contains("Tag-ek")').length === 0) {
      $('<th>Tag-ek</th>').insertBefore($thead.find('th').last());
    }
  }
  function mgDedupeTagInputs(){
    $('#mg-bulk-rows tr.mg-item-row').each(function(){
      var $inputs = $(this).find('.mg-tags-input');
      if ($inputs.length > 1){ $inputs.slice(1).closest('td').remove(); }
    });
  }

  function renderRows(files){
    var $tbody = $('#mg-bulk-rows').empty();
    if (!files || !files.length){
      $tbody.append('<tr class="no-items"><td colspan="8">Válassz fájlokat fent.</td></tr>');
      return;
    }
    mgEnsureHeader();
    Array.from(files).forEach(function(file, idx){
      var $tr = $('<tr class="mg-item-row">');
      $tr.append('<td>'+(idx+1)+'</td>');
      /* Preview cell */
      (function(){
        var td = $('<td class="mg-thumb"><div class="mg-thumb-box"></div></td>');
        if (file && file.type && /^image\/(png|jpe?g|webp|gif|svg\+xml)$/i.test(file.type)){
          var url = (window.URL||window.webkitURL).createObjectURL(file);
          td.find('.mg-thumb-box').append($('<img>',{src:url, alt:file.name, loading:'lazy'}));
          window.MG_BULK_ADV = window.MG_BULK_ADV || {}; MG_BULK_ADV._blobUrls = MG_BULK_ADV._blobUrls || []; MG_BULK_ADV._blobUrls.push(url);
        } else { td.find('.mg-thumb-box').text('—'); }
        $tr.append(td);
      })();
      $tr.append('<td class="mg-filename">'+(file.name||'')+'</td>');
      var $main = $('<td>'); var $mainSel = buildMainSelect();
      var $sub = $('<td>');  var $subsSel = buildSubMulti(0);
      $main.append($mainSel); $sub.append($subsSel);
      $tr.append($main).append($sub);
      /* V8: bind main->subs change */
      $mainSel.on('change', function(){
        var pid = parseInt($(this).val(),10) || 0;
        $subsSel = refreshSubSelect($tr, pid);
      });
      $tr.append('<td><input type="text" class="mg-name" value="'+basename(file.name||'')+'"></td>');
      $tr.append('<td class="mg-parent"><input type="hidden" class="mg-parent-id" value="0"><input type="text" class="mg-parent-search" placeholder="Keresés név alapján..."><div class="mg-parent-results"></div></td>');
      // NEW: tags cell before state
      $tr.append('<td class="mg-tags"><input type="text" class="mg-tags-input" placeholder="pl. horgaszat, ponty, kapucnis"></td>');
      $tr.append('<td class="mg-state">Várakozik</td>');
      $tbody.append($tr);
    mgDedupeTagInputs();
    });
    bindParentSearch();
    mgDedupeTagInputs();
  }

  function bindParentSearch(){
    $(document).off('input.mgps','.mg-parent-search').on('input.mgps', '.mg-parent-search', function(){
      var $wrap = $(this).closest('.mg-parent');
      var q = $(this).val().trim();
      if (!q){ $wrap.find('.mg-parent-results').empty(); return; }
      $.ajax({
        url: MG_SEARCH.ajax_url, method:'POST', dataType:'json',
        data: { action:'mg_search_products', nonce:MG_SEARCH.nonce, q:q }
      }).done(function(resp){
        var html = '';
        if (resp && resp.success && Array.isArray(resp.data)){
          resp.data.forEach(function(r){
            html += '<div class="mg-option" data-id="'+r.id+'">'+r.text+'</div>';
          });
        }
        $wrap.find('.mg-parent-results').html(html);
      });
    });
    $(document).off('click.mgps','.mg-parent .mg-option').on('click.mgps', '.mg-parent .mg-option', function(){
      var id = parseInt($(this).data('id'),10)||0;
      var $wrap = $(this).closest('.mg-parent');
      $wrap.find('.mg-parent-id').val(String(id));
      $wrap.find('.mg-parent-results').html('<div class="mg-picked">Kiválasztva: '+$(this).text()+'</div>');
    });
  }

  $('#mg-bulk-files-adv').on('change', function(){ renderRows(this.files); });

  $(document).on('click', '#mg-bulk-apply-first', function(e){
    e.preventDefault();
    var $rows = $('#mg-bulk-rows .mg-item-row');
    if ($rows.length <= 1){ return; }
    var $first = $rows.first();
    var mainVal = $first.find('select.mg-main').val() || '0';
    var subVals = $first.find('select.mg-subs').val() || [];
    if (!Array.isArray(subVals)) { subVals = subVals ? [subVals] : []; }
    var parentId = $first.find('.mg-parent-id').val() || '0';
    var parentHtml = $first.find('.mg-parent-results').html();
    var tagsVal = ($first.find('.mg-tags-input').val()||'').trim();

    $rows.slice(1).each(function(){
      var $row = $(this);
      var $mainSel = $row.find('select.mg-main');
      $mainSel.val(mainVal);
      var pid = parseInt(mainVal,10) || 0;
      var $subsSel = refreshSubSelect($row, pid);
      if (subVals.length){
        $subsSel.find('option').each(function(){
          if (subVals.indexOf($(this).val()) !== -1){ $(this).prop('selected', true); }
        });
      }
      $row.find('.mg-parent-id').val(parentId);
      $row.find('.mg-parent-results').html(parentHtml);
      $row.find('.mg-tags-input').val(tagsVal);
    });
  });

  function getSelectedProductKeys(){
    var keys=[]; $('.mg-type-cb:checked').each(function(){ keys.push($(this).val()); }); return keys;
  }

  function serverErrorToText(xhr){
    try {
      if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) return xhr.responseJSON.data.message;
      if (xhr && typeof xhr.responseText === 'string') return xhr.responseText.substring(0,200);
    } catch(e){}
    return 'Ismeretlen hiba';
  }

  $('#mg-bulk-start').on('click', function(e){
    e.preventDefault();
    var files = ($('#mg-bulk-files-adv')[0] && $('#mg-bulk-files-adv')[0].files) ? $('#mg-bulk-files-adv')[0].files : null;
    if (!files || !files.length){ alert('Válassz fájlokat.'); return; }
    var keys = getSelectedProductKeys();
    if (!keys.length){ alert('Válassz legalább egy terméktípust.'); return; }
    var $rows = $('#mg-bulk-rows .mg-item-row').toArray();
    var total = $rows.length, done = 0;
    function updateProgress(){
      var pct = Math.round((done/total)*100);
      $('#mg-bulk-bar').css('width', pct+'%'); $('#mg-bulk-status').text(pct+'%');
    }
    updateProgress();

    function processRow(i){
      if (i>=total) return;
      var $row = $($rows[i]);
      var file = files[i];
      var $mainSel = $row.find('select.mg-main');
      var $subsSel = $row.find('select.mg-subs');
      var $name = $row.find('input.mg-name');
      var parentId = parseInt($row.find('.mg-parent-id').val(),10)||0;
      var tags = ($row.find('.mg-tags-input').val()||'').trim();

      mgDedupeTagInputs();
      var form = new FormData();
      form.append('action', 'mg_bulk_process');
      form.append('nonce', MG_BULK_ADV.nonce);
      form.append('design_file', file);
      keys.forEach(function(k){ form.append('product_keys[]', k); });
      form.append('product_name', $name.val().trim());
      form.append('main_cat', $mainSel.val()||'0');
      collectSubValues($subsSel).forEach(function(id){ form.append('sub_cats[]', id); });
      form.append('parent_id', String(parentId));
      form.append('tags', tags);

      $row.find('.mg-state').text('Feldolgozás...');
      $.ajax({
        url: MG_BULK_ADV.ajax_url, method:'POST', data: form, processData:false, contentType:false, dataType:'json'
      }).done(function(resp){
        if (resp && resp.success){
          $row.find('.mg-state').text('OK…');
          var pid = resp.data && resp.data.product_id ? parseInt(resp.data.product_id,10) : 0;
          var tags = ($row.find('.mg-tags-input').val()||'').trim();
          if (pid && tags){
            $.post(MG_BULK_ADV.ajax_url, {
              action: 'mg_set_product_tags',
              nonce: MG_BULK_ADV.nonce,
              product_id: pid,
              tags: tags
            }, function(r){
              if (r && r.success){ $row.find('.mg-state').text('OK'); }
              else { $row.find('.mg-state').text('OK – tagek hiba'); }
            }, 'json').fail(function(){ $row.find('.mg-state').text('OK – tagek hiba'); });
          } else {
            $row.find('.mg-state').text('OK');
          }
        } else {
          $row.find('.mg-state').text('Hiba: '+(resp && resp.data && resp.data.message ? resp.data.message : 'Ismeretlen'));
        }
      }).fail(function(xhr){
        $row.find('.mg-state').text('Hiba: '+serverErrorToText(xhr));
      }).always(function(){
        done++; updateProgress(); processRow(i+1);
      });
    }
    processRow(0);
  });

})(jQuery);


// ---- Drag & drop zone ----
(function($){
  var $zone = $('#mg-drop-zone');
  var $input = $('#mg-bulk-files-adv');
  if ($zone.length && $input.length){
    function accept(f){ return /^image\/(png|jpe?g|webp)$/i.test(f.type) || /\.(png|jpe?g|jpg|webp)$/i.test(f.name||''); }
    function setFiles(list){
      try{
        var dt = new DataTransfer();
        Array.from(list||[]).forEach(function(f){ if (accept(f)) dt.items.add(f); });
        if (dt.files && dt.files.length){ $input[0].files = dt.files; renderRows($input[0].files); return; }
      }catch(e){}
      renderRows(Array.from(list||[]).filter(accept));
    }
    $zone.on('click', '.button-link', function(e){ e.preventDefault(); $input.trigger('click'); });
    $zone.on('drag dragstart dragend dragover dragenter dragleave drop', function(e){ e.preventDefault(); e.stopPropagation(); });
    $zone.on('dragover dragenter', function(){ $zone.addClass('is-dragover'); });
    $zone.on('dragleave dragend drop', function(){ $zone.removeClass('is-dragover'); });
    $zone.on('drop', function(e){ var dt = e.originalEvent && e.originalEvent.dataTransfer; setFiles((dt&&dt.files)||[]); });
  }
  // Revoke blobs on start
  $(document).on('click', '#mg-bulk-start', function(){
    try{ (MG_BULK_ADV._blobUrls||[]).forEach(function(u){ (window.URL||window.webkitURL).revokeObjectURL(u); }); MG_BULK_ADV._blobUrls=[]; }catch(e){}
  });
})(jQuery);
