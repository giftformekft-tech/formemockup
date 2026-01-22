
(function ($) {
  // AJAX retry configuration
  var MG_AJAX_MAX_RETRIES = 3;
  var MG_AJAX_RETRY_DELAY = 1000; // 1 second base delay
  var MG_AJAX_TIMEOUT = 120000; // 120 seconds
  function basename(name) { return (name || '').replace(/\.[^.]+$/, ''); }
  function normalizeLabel(value) { return (value || '').toString().trim().toLowerCase(); }
  function isJsonFile(file) {
    if (!file) { return false; }
    var name = (file.name || '').toLowerCase();
    return file.type === 'application/json' || /\.json$/i.test(name);
  }
  function isImageFile(file) {
    if (!file) { return false; }
    if (file.type && /^image\/(png|jpe?g|webp|gif|svg\+xml)$/i.test(file.type)) { return true; }
    return /\.(png|jpe?g|jpg|webp|gif|svg)$/i.test(file.name || '');
  }
  function buildAutoName(baseName, mainLabel) {
    var parts = [];
    if (baseName) { parts.push(baseName); }
    if (mainLabel) { parts.push(mainLabel); }
    return parts.join(' ');
  }
  function updateRowAutoName($row) {
    if (!$row || !$row.length) { return; }
    var $name = $row.find('input.mg-name');
    if (!$name.length) { return; }
    var autoEnabled = $name.data('mgAutoName') !== false;
    if (!autoEnabled) { return; }
    var baseName = $row.data('mgBaseName') || '';
    var mainLabel = '';
    var $mainSel = $row.find('select.mg-main');
    if ($mainSel.length) {
      var selectedText = $mainSel.find('option:selected').text() || '';
      var selectedVal = $mainSel.val() || '';
      if (selectedVal && selectedVal !== '0') {
        mainLabel = selectedText;
      }
    }
    $name.val(buildAutoName(baseName, mainLabel));
  }
  function buildMainSelect() {
    var html = '<select class="mg-main">';
    html += '<option value="0">— Nincs —</option>';
    (MG_BULK_ADV.mains || []).forEach(function (m) {
      html += '<option value="' + m.id + '">' + m.name + '</option>';
    });
    html += '</select>';
    return $(html);
  }
  function buildSubMulti(parentId) {
    var list = (MG_BULK_ADV.subs && MG_BULK_ADV.subs[parentId]) ? MG_BULK_ADV.subs[parentId] : [];
    var html = '<select class="mg-subs" multiple size="3">';
    list.forEach(function (s) { html += '<option value="' + s.id + '">' + s.name + '</option>'; });
    html += '</select>';
    return $(html);
  }
  function refreshSubSelect($row, parentId) {
    var $current = $row.find('select.mg-subs');
    var $new = buildSubMulti(parentId);
    if ($current.length) { $current.replaceWith($new); }
    else { $row.find('td').eq(4).append($new); }
    return $new;
  }
  function collectSubValues($sel) { var out = []; $sel.find('option:selected').each(function () { out.push($(this).val()); }); return out; }

  function getAiConfig() {
    return {
      enabled: $('#mg-ai-mode-toggle').is(':checked'),
      fields: {
        main: $('#mg-ai-field-main').is(':checked'),
        sub: $('#mg-ai-field-sub').is(':checked'),
        tags: $('#mg-ai-field-tags').is(':checked')
      }
    };
  }

  function setJsonStatus($row, message, state) {
    if (!$row || !$row.length) { return; }
    var $status = $row.find('.mg-json-status');
    if (!$status.length) { return; }
    $status.removeClass('is-warning is-error is-success');
    if (state) { $status.addClass(state); }
    $status.text(message || '');
  }

  function selectOptionByText($select, label) {
    if (!$select || !$select.length) { return false; }
    var target = normalizeLabel(label);
    if (!target) { return false; }
    var matched = '';
    $select.find('option').each(function () {
      if (normalizeLabel($(this).text()) === target) {
        matched = $(this).val();
        return false;
      }
    });
    if (matched !== '') {
      $select.val(matched);
      return true;
    }
    return false;
  }

  function selectMultiByText($select, labels) {
    if (!$select || !$select.length) { return; }
    var targets = (Array.isArray(labels) ? labels : [labels]).map(normalizeLabel).filter(Boolean);
    if (!targets.length) { return; }
    $select.find('option').each(function () {
      var text = normalizeLabel($(this).text());
      $(this).prop('selected', targets.indexOf(text) !== -1);
    });
  }

  function applyAiDataToRow($row, payload) {
    if (!$row || !$row.length || !payload || typeof payload !== 'object') { return; }
    var config = getAiConfig();
    if (!config.enabled) { return; }
    var categories = payload.categories || {};
    var mainLabel = (categories && typeof categories.main === 'string') ? categories.main : '';
    var subLabel = (categories && typeof categories.sub === 'string') ? categories.sub : '';
    var tags = payload.tags;
    var $mainSel = $row.find('select.mg-main');
    var $subsSel = $row.find('select.mg-subs');
    var mainChanged = false;

    if (config.fields.main && mainLabel) {
      if (selectOptionByText($mainSel, mainLabel)) {
        mainChanged = true;
        var mainId = parseInt($mainSel.val(), 10) || 0;
        $subsSel = refreshSubSelect($row, mainId);
        updateRowAutoName($row);
      }
    }

    if (config.fields.sub && subLabel) {
      var currentMain = parseInt($mainSel.val(), 10) || 0;
      if (!$subsSel.length || mainChanged) {
        $subsSel = refreshSubSelect($row, currentMain);
      }
      selectMultiByText($subsSel, [subLabel]);
    }

    if (config.fields.tags) {
      var tagList = [];
      if (Array.isArray(tags)) {
        tagList = tags.map(function (tag) { return (tag || '').toString().trim(); }).filter(Boolean);
      } else if (typeof tags === 'string') {
        tagList = tags.split(',').map(function (tag) { return tag.trim(); }).filter(Boolean);
      }
      if (tagList.length) {
        $row.find('.mg-tags-input').val(tagList.join(', '));
      }
    }
  }

  function applyAiToExistingRows() {
    var config = getAiConfig();
    if (!config.enabled) { return; }
    $('#mg-bulk-rows .mg-item-row').each(function () {
      var payload = $(this).data('mgAiPayload');
      if (payload) { applyAiDataToRow($(this), payload); }
    });
  }

  function getProductByKey(key) {
    var list = (MG_BULK_ADV.products || []);
    for (var i = 0; i < list.length; i++) {
      if (list[i] && list[i].key === key) { return list[i]; }
    }
    return null;
  }

  function renderDefaultColorOptions($select, typeKey, preferredColor) {
    var product = getProductByKey(typeKey || '');
    var colors = (product && Array.isArray(product.colors)) ? product.colors : [];
    var targetColor = preferredColor;
    if ((!targetColor || typeof targetColor !== 'string' || targetColor === '') && product && product.primary_color) {
      targetColor = product.primary_color;
    }
    var activeColor = '';
    $select.empty();
    if (!colors.length) {
      $select.append($('<option>', { value: '', text: '— Ehhez a típushoz nincs szín —' }));
      return '';
    }
    colors.forEach(function (c, idx) {
      if (!c || !c.slug) { return; }
      var name = c.name || c.slug;
      var selectThis = false;
      if (targetColor && c.slug === targetColor) { selectThis = true; }
      else if (!targetColor && idx === 0) { selectThis = true; }
      var $opt = $('<option>', { value: c.slug, text: name });
      if (selectThis) {
        $opt.prop('selected', true);
        activeColor = c.slug;
      }
      $select.append($opt);
    });
    if (!activeColor && colors.length) {
      activeColor = colors[0].slug;
      $select.val(activeColor);
    }
    if (product && product.color_sizes) {
      var needsSwitch = false;
      if (!activeColor) {
        needsSwitch = true;
      } else if (Object.prototype.hasOwnProperty.call(product.color_sizes, activeColor)) {
        var list = product.color_sizes[activeColor];
        if (Array.isArray(list) && list.length === 0) {
          needsSwitch = true;
        }
      }
      if (needsSwitch) {
        for (var i = 0; i < colors.length; i++) {
          var slugCandidate = colors[i] && colors[i].slug ? colors[i].slug : '';
          if (!slugCandidate) { continue; }
          var candidateList = product.color_sizes[slugCandidate];
          if (Array.isArray(candidateList) && candidateList.length === 0) { continue; }
          activeColor = slugCandidate;
          $select.val(activeColor);
          break;
        }
      }
    }
    return activeColor;
  }

  function renderDefaultSizeOptions($select, typeKey, colorSlug, preferredSize) {
    if (!$select || !$select.length) { return ''; }
    var product = getProductByKey(typeKey || '');
    var sizes = [];
    if (product) {
      var map = (product && product.color_sizes) ? product.color_sizes : null;
      if (colorSlug && map && Object.prototype.hasOwnProperty.call(map, colorSlug)) {
        var fromMap = map[colorSlug];
        if (Array.isArray(fromMap)) {
          sizes = fromMap.slice();
        } else {
          sizes = [];
        }
      } else if (Array.isArray(product.sizes)) {
        sizes = product.sizes.slice();
      }
    }
    if ((!sizes || !sizes.length) && product && Array.isArray(product.sizes)) {
      sizes = product.sizes.slice();
    }
    if (!Array.isArray(sizes)) { sizes = []; }
    sizes = sizes
      .map(function (item) { return (typeof item === 'string') ? item.trim() : ''; })
      .filter(function (item) { return item !== ''; });
    var unique = [];
    sizes.forEach(function (item) { if (unique.indexOf(item) === -1) { unique.push(item); } });
    sizes = unique;
    var targetSize = preferredSize;
    if ((!targetSize || typeof targetSize !== 'string' || targetSize === '') && product && typeof product.primary_size === 'string') {
      targetSize = product.primary_size;
    }
    if (targetSize && sizes.indexOf(targetSize) === -1) {
      targetSize = '';
    }
    $select.empty();
    if (!sizes.length) {
      $select.append($('<option>', { value: '', text: '— Ehhez a színhez nincs méret —' }));
      $select.prop('disabled', true);
      return '';
    }
    $select.prop('disabled', false);
    var activeSize = '';
    sizes.forEach(function (sizeLabel, idx) {
      var selectThis = false;
      if (targetSize && sizeLabel === targetSize) { selectThis = true; }
      else if (!targetSize && idx === 0) { selectThis = true; }
      var $opt = $('<option>', { value: sizeLabel, text: sizeLabel });
      if (selectThis) {
        $opt.prop('selected', true);
        activeSize = sizeLabel;
      }
      $select.append($opt);
    });
    if (!activeSize && sizes.length) {
      activeSize = sizes[0];
      $select.val(activeSize);
    }
    return activeSize;
  }

  function initDefaultSelectors() {
    var $type = $('#mg-default-type');
    var $color = $('#mg-default-color');
    var $size = $('#mg-default-size');
    if (!$type.length) { return; }
    var initialType = (MG_BULK_ADV.default_type && typeof MG_BULK_ADV.default_type === 'string') ? MG_BULK_ADV.default_type : ($type.val() || '');
    if (initialType) { $type.val(initialType); }
    var initialColorPref = (MG_BULK_ADV.default_color && typeof MG_BULK_ADV.default_color === 'string' && MG_BULK_ADV.default_color !== '') ? MG_BULK_ADV.default_color : null;
    var initialSizePref = (MG_BULK_ADV.default_size && typeof MG_BULK_ADV.default_size === 'string' && MG_BULK_ADV.default_size !== '') ? MG_BULK_ADV.default_size : null;
    var resolvedColor = $color.length ? renderDefaultColorOptions($color, $type.val(), initialColorPref) : '';
    var resolvedSize = $size.length ? renderDefaultSizeOptions($size, $type.val(), resolvedColor, initialSizePref) : '';
    MG_BULK_ADV.activeDefaults = {
      type: $type.val() || '',
      color: resolvedColor || '',
      size: resolvedSize || ''
    };
    $type.on('change', function () {
      var selectedType = $(this).val();
      var updatedColor = $color.length ? renderDefaultColorOptions($color, selectedType, null) : '';
      var updatedSize = $size.length ? renderDefaultSizeOptions($size, selectedType, updatedColor, null) : '';
      MG_BULK_ADV.activeDefaults = {
        type: selectedType || '',
        color: updatedColor || '',
        size: updatedSize || ''
      };
    });
    if ($color.length) {
      $color.on('change', function () {
        if (!MG_BULK_ADV.activeDefaults) MG_BULK_ADV.activeDefaults = {};
        MG_BULK_ADV.activeDefaults.type = $type.val() || '';
        var activeColor = $(this).val() || '';
        MG_BULK_ADV.activeDefaults.color = activeColor;
        var retainedSize = MG_BULK_ADV.activeDefaults.size || '';
        var resolved = $size.length ? renderDefaultSizeOptions($size, MG_BULK_ADV.activeDefaults.type, activeColor, retainedSize) : '';
        MG_BULK_ADV.activeDefaults.size = resolved || '';
      });
    }
    if ($size.length) {
      $size.on('change', function () {
        if (!MG_BULK_ADV.activeDefaults) MG_BULK_ADV.activeDefaults = {};
        MG_BULK_ADV.activeDefaults.type = $type.val() || '';
        MG_BULK_ADV.activeDefaults.size = $(this).val() || '';
      });
    }
  }

  function sanitizeWorkerOptions(list) {
    var out = [];
    if (Array.isArray(list)) {
      list.forEach(function (item) {
        var parsed = parseInt(item, 10);
        if (!isNaN(parsed) && out.indexOf(parsed) === -1) {
          out.push(parsed);
        }
      });
    }
    if (!out.length) { out.push(1); }
    out.sort(function (a, b) { return a - b; });
    return out;
  }

  function updateWorkerToggleUI(activeCount) {
    var count = parseInt(activeCount, 10);
    if (isNaN(count)) { count = 1; }
    $('.mg-worker-toggle').each(function () {
      var val = parseInt($(this).attr('data-workers'), 10);
      var isActive = (!isNaN(val) && val === count);
      $(this).toggleClass('is-active', isActive);
      $(this).attr('aria-pressed', isActive ? 'true' : 'false');
    });
    $('.mg-worker-active-count').text(count);
  }

  function showWorkerFeedback(message, state) {
    var $target = $('.mg-worker-feedback');
    if (!$target.length) { return; }
    $target.removeClass('is-error is-success');
    if (state === 'error') { $target.addClass('is-error'); }
    else if (state === 'success') { $target.addClass('is-success'); }
    $target.text(message || '');
  }

  function showQueueFeedback(message, state) {
    var $target = $('.mg-queue-feedback');
    if (!$target.length) { return; }
    $target.removeClass('is-error is-success');
    if (state === 'error') { $target.addClass('is-error'); }
    else if (state === 'success') { $target.addClass('is-success'); }
    $target.text(message || '');
  }

  function initWorkerToggle() {
    if (!window.MG_BULK_ADV) { window.MG_BULK_ADV = {}; }
    var opts = sanitizeWorkerOptions(window.MG_BULK_ADV.worker_options);
    window.MG_BULK_ADV.worker_options = opts;
    var current = parseInt(window.MG_BULK_ADV.worker_count, 10);
    if (isNaN(current) || opts.indexOf(current) === -1) {
      current = opts[0];
    }
    window.MG_BULK_ADV.worker_count = current;
    updateWorkerToggleUI(current);
    showWorkerFeedback('', null);
  }

  function applyBulkMode(mode) {
    var $panel = $('.mg-panel-body--bulk');
    if (!$panel.length) { return; }
    var selected = (mode === 'queue') ? 'queue' : 'direct';
    $panel.toggleClass('is-queue-mode', selected === 'queue');
    $('.mg-queue-control').attr('aria-hidden', selected !== 'queue');
    $('.mg-worker-control').attr('aria-hidden', selected === 'queue');
    try {
      window.localStorage.setItem('mg_bulk_mode', selected);
    } catch (e) { }
    $('input[name="mg-bulk-mode"][value="' + selected + '"]').prop('checked', true);
  }

  function getBulkMode() {
    var checked = $('input[name="mg-bulk-mode"]:checked').val();
    if (checked === 'queue') { return 'queue'; }
    return 'direct';
  }

  function initBulkMode() {
    var stored = '';
    try {
      stored = window.localStorage.getItem('mg_bulk_mode') || '';
    } catch (e) { }
    if (stored !== 'queue' && stored !== 'direct') {
      stored = 'direct';
    }
    applyBulkMode(stored);
  }

  function initQueueSliders() {
    var $batch = $('#mg-bulk-queue-batch');
    var $batchValue = $('#mg-bulk-queue-batch-value');
    var $interval = $('#mg-bulk-queue-interval');
    var $intervalValue = $('#mg-bulk-queue-interval-value');
    if ($batch.length && $batchValue.length) {
      $batchValue.text($batch.val());
      $batch.on('input change', function () { $batchValue.text($(this).val()); });
    }
    if ($interval.length && $intervalValue.length) {
      $intervalValue.text($interval.val());
      $interval.on('input change', function () { $intervalValue.text($(this).val()); });
    }
    showQueueFeedback('', null);
  }

  function mgEnsureHeader() {
    var $thead = $('.mg-bulk-table thead tr');
    if ($thead.length) {
      if ($thead.find('th:contains("Előnézet")').length === 0) { $('<th>Előnézet</th>').insertAfter($thead.find('th').first()); }
    }
    if ($thead.length && $thead.find('th:contains("Egyedi termék")').length === 0) {
      $('<th>Egyedi termék</th>').insertBefore($thead.find('th:contains("Tag-ek")'));
    }
    if ($thead.length && $thead.find('th:contains("Tag-ek")').length === 0) {
      $('<th>Tag-ek</th>').insertBefore($thead.find('th').last());
    }
  }
  function mgDedupeTagInputs() {
    $('#mg-bulk-rows tr.mg-item-row').each(function () {
      var $inputs = $(this).find('.mg-tags-input');
      if ($inputs.length > 1) { $inputs.slice(1).closest('td').remove(); }
    });
  }

  function renderRows(files) {
    var $tbody = $('#mg-bulk-rows').empty();
    var allFiles = Array.from(files || []);
    var imageFiles = allFiles.filter(isImageFile);
    var jsonFiles = allFiles.filter(isJsonFile);
    var jsonByBase = {};
    jsonFiles.forEach(function (file) {
      var base = basename(file.name || '');
      if (!base) { return; }
      var key = base.toLowerCase();
      if (!jsonByBase[key]) {
        jsonByBase[key] = file;
      }
    });
    if (!imageFiles.length) {
      $tbody.append('<tr class="no-items"><td colspan="10">Válassz fájlokat fent.</td></tr>');
      return;
    }
    window.MG_BULK_ADV = window.MG_BULK_ADV || {};
    window.MG_BULK_ADV.imageFiles = imageFiles;
    window.MG_BULK_ADV.jsonFiles = jsonByBase;
    mgEnsureHeader();
    imageFiles.forEach(function (file, idx) {
      var $tr = $('<tr class="mg-item-row">');
      $tr.append('<td>' + (idx + 1) + '</td>');
      /* Preview cell */
      (function () {
        var td = $('<td class="mg-thumb"><div class="mg-thumb-box"></div></td>');
        if (isImageFile(file)) {
          var url = (window.URL || window.webkitURL).createObjectURL(file);
          td.find('.mg-thumb-box').append($('<img>', { src: url, alt: file.name, loading: 'lazy' }));
          window.MG_BULK_ADV = window.MG_BULK_ADV || {}; MG_BULK_ADV._blobUrls = MG_BULK_ADV._blobUrls || []; MG_BULK_ADV._blobUrls.push(url);
        } else { td.find('.mg-thumb-box').text('—'); }
        $tr.append(td);
      })();
      $tr.append('<td class="mg-filename">' + (file.name || '') + '<div class="mg-json-status"></div></td>');
      var $main = $('<td>'); var $mainSel = buildMainSelect();
      var $sub = $('<td>'); var $subsSel = buildSubMulti(0);
      $main.append($mainSel); $sub.append($subsSel);
      $tr.append($main).append($sub);
      /* V8: bind main->subs change */
      $mainSel.on('change', function () {
        var pid = parseInt($(this).val(), 10) || 0;
        $subsSel = refreshSubSelect($tr, pid);
        updateRowAutoName($tr);
      });
      var baseName = basename(file.name || '');
      $tr.data('mgBaseName', baseName);
      $tr.append('<td><input type="text" class="mg-name" value=""></td>');
      var $nameInput = $tr.find('input.mg-name');
      $nameInput.data('mgAutoName', true);
      $nameInput.on('input', function () { $(this).data('mgAutoName', false); });
      updateRowAutoName($tr);
      $tr.append('<td class="mg-parent"><input type="hidden" class="mg-parent-id" value="0"><input type="text" class="mg-parent-search" placeholder="Keresés név alapján..."><div class="mg-parent-results"></div></td>');
      $tr.append('<td class="mg-custom"><label><input type="checkbox" class="mg-custom-flag"> Egyedi</label></td>');
      // NEW: tags cell before state
      $tr.append('<td class="mg-tags"><input type="text" class="mg-tags-input" placeholder="pl. horgaszat, ponty, kapucnis"></td>');
      $tr.append('<td class="mg-state">Várakozik</td>');
      $tbody.append($tr);
      mgDedupeTagInputs();

      var jsonFile = jsonByBase[baseName.toLowerCase()] || null;
      if (!jsonFile) {
        setJsonStatus($tr, 'Nincs páros JSON.', 'is-warning');
        return;
      }

      var reader = new FileReader();
      reader.onload = function () {
        var text = reader.result || '';
        try {
          var payload = JSON.parse(text);
          $tr.data('mgAiPayload', payload);
          setJsonStatus($tr, 'JSON betöltve.', 'is-success');
          applyAiDataToRow($tr, payload);
        } catch (err) {
          setJsonStatus($tr, 'Hibás JSON – kézi kitöltés.', 'is-error');
        }
      };
      reader.onerror = function () {
        setJsonStatus($tr, 'Nem olvasható JSON – kézi kitöltés.', 'is-error');
      };
      reader.readAsText(jsonFile);
    });
    bindParentSearch();
    mgDedupeTagInputs();
  }

  function updateAllAutoNames() {
    $('#mg-bulk-rows .mg-item-row').each(function () {
      updateRowAutoName($(this));
    });
  }

  function bindParentSearch() {
    $(document).off('input.mgps', '.mg-parent-search').on('input.mgps', '.mg-parent-search', function () {
      var $wrap = $(this).closest('.mg-parent');
      var q = $(this).val().trim();
      if (!q) { $wrap.find('.mg-parent-results').empty(); return; }
      $.ajax({
        url: MG_SEARCH.ajax_url, method: 'POST', dataType: 'json',
        data: { action: 'mg_search_products', nonce: MG_SEARCH.nonce, q: q }
      }).done(function (resp) {
        var html = '';
        if (resp && resp.success && Array.isArray(resp.data)) {
          resp.data.forEach(function (r) {
            html += '<div class="mg-option" data-id="' + r.id + '">' + r.text + '</div>';
          });
        }
        $wrap.find('.mg-parent-results').html(html);
      });
    });
    $(document).off('click.mgps', '.mg-parent .mg-option').on('click.mgps', '.mg-parent .mg-option', function () {
      var id = parseInt($(this).data('id'), 10) || 0;
      var $wrap = $(this).closest('.mg-parent');
      $wrap.find('.mg-parent-id').val(String(id));
      $wrap.find('.mg-parent-results').html('<div class="mg-picked">Kiválasztva: ' + $(this).text() + '</div>');
    });
  }

  $('#mg-bulk-files-adv').on('change', function () { renderRows(this.files); });
  $(document).on('change', '#mg-default-type', function () { updateAllAutoNames(); });

  function copyMainFromFirst() {
    var $rows = $('#mg-bulk-rows .mg-item-row');
    if ($rows.length <= 1) { return; }
    var $first = $rows.first();
    var mainVal = $first.find('select.mg-main').val() || '0';
    $rows.slice(1).each(function () {
      var $row = $(this);
      var $mainSel = $row.find('select.mg-main');
      if ($mainSel.val() !== mainVal) {
        $mainSel.val(mainVal);
        var pid = parseInt(mainVal, 10) || 0;
        refreshSubSelect($row, pid);
      }
    });
  }

  function copySubsFromFirst() {
    var $rows = $('#mg-bulk-rows .mg-item-row');
    if ($rows.length <= 1) { return; }
    var $first = $rows.first();
    var subVals = $first.find('select.mg-subs').val() || [];
    if (!Array.isArray(subVals)) { subVals = subVals ? [subVals] : []; }
    $rows.slice(1).each(function () {
      var $row = $(this);
      var $subsSel = $row.find('select.mg-subs');
      if (!$subsSel.length) {
        var pid = parseInt($row.find('select.mg-main').val(), 10) || 0;
        $subsSel = refreshSubSelect($row, pid);
      }
      if (!subVals.length) {
        $subsSel.val([]);
        return;
      }
      $subsSel.find('option').each(function () {
        var $opt = $(this);
        $opt.prop('selected', subVals.indexOf($opt.val()) !== -1);
      });
    });
  }

  function copyParentFromFirst() {
    var $rows = $('#mg-bulk-rows .mg-item-row');
    if ($rows.length <= 1) { return; }
    var $first = $rows.first();
    var parentId = $first.find('.mg-parent-id').val() || '0';
    var parentHtml = $first.find('.mg-parent-results').html();
    $rows.slice(1).each(function () {
      var $row = $(this);
      $row.find('.mg-parent-id').val(parentId);
      $row.find('.mg-parent-results').html(parentHtml);
    });
  }

  function copyTagsFromFirst() {
    var $rows = $('#mg-bulk-rows .mg-item-row');
    if ($rows.length <= 1) { return; }
    var tagsVal = ($rows.first().find('.mg-tags-input').val() || '').trim();
    $rows.slice(1).each(function () {
      $(this).find('.mg-tags-input').val(tagsVal);
    });
  }

  function copyCustomFlagFromFirst() {
    var $rows = $('#mg-bulk-rows .mg-item-row');
    if ($rows.length <= 1) { return; }
    var checked = $rows.first().find('.mg-custom-flag').is(':checked');
    $rows.slice(1).each(function () {
      $(this).find('.mg-custom-flag').prop('checked', checked);
    });
  }

  function appendMainCategoryToAll() {
    var $rows = $('#mg-bulk-rows .mg-item-row');
    if (!$rows.length) { return; }
    $rows.each(function () {
      var $row = $(this);
      var $name = $row.find('input.mg-name');
      if (!$name.length) { return; }
      var $mainSel = $row.find('select.mg-main');
      if (!$mainSel.length) { return; }
      var mainVal = $mainSel.val() || '0';
      if (mainVal === '0') { return; }
      var mainLabel = $mainSel.find('option:selected').text() || '';
      if (!mainLabel) { return; }
      var currentName = $name.val() || '';
      var newName = currentName ? (currentName + ' ' + mainLabel) : mainLabel;
      $name.val(newName);
      $name.data('mgAutoName', false);
    });
  }

  function appendSubCategoryToAll() {
    var $rows = $('#mg-bulk-rows .mg-item-row');
    if (!$rows.length) { return; }
    $rows.each(function () {
      var $row = $(this);
      var $name = $row.find('input.mg-name');
      if (!$name.length) { return; }
      var $subsSel = $row.find('select.mg-subs');
      if (!$subsSel.length) { return; }
      var $firstSelected = $subsSel.find('option:selected').first();
      if (!$firstSelected.length) { return; }
      var subLabel = $firstSelected.text() || '';
      if (!subLabel) { return; }
      var currentName = $name.val() || '';
      var newName = currentName ? (currentName + ' ' + subLabel) : subLabel;
      $name.val(newName);
      $name.data('mgAutoName', false);
    });
  }

  function setupCopyButtons() {
    var $legacy = $('#mg-bulk-apply-first');
    if (!$legacy.length || $legacy.data('mgSplitReady')) { return; }
    $legacy.data('mgSplitReady', true);
    var $wrapper = $legacy.parent();
    if ($wrapper.length) { $wrapper.addClass('mg-copy-actions'); }
    var $mainBtn = $('<button type="button" class="button" id="mg-bulk-copy-main">Főkategória másolása az első sorból</button>');
    var $subsBtn = $('<button type="button" class="button" id="mg-bulk-copy-subs">Alkategóriák másolása az első sorból</button>');
    var $tagsBtn = $('<button type="button" class="button" id="mg-bulk-copy-tags">Tag-ek másolása az első sorból</button>');
    var $customBtn = $('<button type="button" class="button" id="mg-bulk-copy-custom">Egyedi jelölés másolása</button>');
    var $appendMainBtn = $('<button type="button" class="button" id="mg-bulk-append-main">Főkategória hozzáadása a névhez</button>');
    var $appendSubBtn = $('<button type="button" class="button" id="mg-bulk-append-sub">Alkategória hozzáadása a névhez</button>');
    $legacy.after($appendSubBtn).after($appendMainBtn).after($customBtn).after($tagsBtn).after($subsBtn).after($mainBtn);
  }

  $(setupCopyButtons);
  $(initDefaultSelectors);
  $(initWorkerToggle);
  $(initBulkMode);
  $(initQueueSliders);
  $(document).on('change', '#mg-ai-mode-toggle, .mg-ai-field-cb', function () {
    applyAiToExistingRows();
  });

  $(document).on('change', 'input[name="mg-bulk-mode"]', function () {
    applyBulkMode($(this).val());
  });

  $(document).on('click', '#mg-bulk-queue-save', function (e) {
    e.preventDefault();
    if (!window.MG_BULK_ADV || window.MG_BULK_ADV._savingQueueConfig) { return; }
    var batch = parseInt($('#mg-bulk-queue-batch').val(), 10);
    var interval = parseInt($('#mg-bulk-queue-interval').val(), 10);
    if (isNaN(batch)) { batch = 1; }
    if (isNaN(interval)) { interval = 1; }
    var savingMsg = window.MG_BULK_ADV.queue_feedback_saving || 'Mentés…';
    showQueueFeedback(savingMsg, null);
    window.MG_BULK_ADV._savingQueueConfig = true;
    $.post(window.MG_BULK_ADV.ajax_url, {
      action: 'mg_bulk_queue_config',
      nonce: window.MG_BULK_ADV.nonce,
      batch_size: batch,
      interval_minutes: interval
    }, function (resp) {
      if (resp && resp.success && resp.data) {
        if (typeof resp.data.batch_size !== 'undefined') {
          $('#mg-bulk-queue-batch').val(resp.data.batch_size);
          $('#mg-bulk-queue-batch-value').text(resp.data.batch_size);
        }
        if (typeof resp.data.interval_minutes !== 'undefined') {
          $('#mg-bulk-queue-interval').val(resp.data.interval_minutes);
          $('#mg-bulk-queue-interval-value').text(resp.data.interval_minutes);
        }
        var okMsg = window.MG_BULK_ADV.queue_feedback_saved || 'Mentve.';
        showQueueFeedback(okMsg, 'success');
      } else {
        var errMsg = window.MG_BULK_ADV.queue_feedback_error || 'Nem sikerült menteni.';
        showQueueFeedback(errMsg, 'error');
      }
    }, 'json').fail(function () {
      var errMsg = window.MG_BULK_ADV.queue_feedback_error || 'Nem sikerült menteni.';
      showQueueFeedback(errMsg, 'error');
    }).always(function () {
      window.MG_BULK_ADV._savingQueueConfig = false;
    });
  });

  $(document).on('click', '#mg-bulk-copy-main', function (e) {
    e.preventDefault();
    copyMainFromFirst();
  });

  $(document).on('click', '#mg-bulk-copy-subs', function (e) {
    e.preventDefault();
    copySubsFromFirst();
  });

  $(document).on('click', '#mg-bulk-copy-tags', function (e) {
    e.preventDefault();
    copyTagsFromFirst();
  });

  $(document).on('click', '#mg-bulk-copy-custom', function (e) {
    e.preventDefault();
    copyCustomFlagFromFirst();
  });

  $(document).on('click', '#mg-bulk-append-main', function (e) {
    e.preventDefault();
    appendMainCategoryToAll();
  });

  $(document).on('click', '#mg-bulk-append-sub', function (e) {
    e.preventDefault();
    appendSubCategoryToAll();
  });

  $(document).on('click', '.mg-worker-toggle', function (e) {
    e.preventDefault();
    if (!window.MG_BULK_ADV) { window.MG_BULK_ADV = {}; }
    if (window.MG_BULK_ADV._savingWorkerCount) { return; }
    var options = sanitizeWorkerOptions(window.MG_BULK_ADV.worker_options);
    window.MG_BULK_ADV.worker_options = options;
    var requested = parseInt($(this).attr('data-workers'), 10);
    if (isNaN(requested) || options.indexOf(requested) === -1) {
      requested = options[0];
    }
    updateWorkerToggleUI(requested);
    var savingMsg = window.MG_BULK_ADV.worker_feedback_saving || 'Mentés…';
    showWorkerFeedback(savingMsg, null);
    window.MG_BULK_ADV._savingWorkerCount = true;
    $.post(window.MG_BULK_ADV.ajax_url, {
      action: 'mg_bulk_set_worker_count',
      nonce: window.MG_BULK_ADV.nonce,
      count: requested
    }, function (resp) {
      if (resp && resp.success && resp.data && typeof resp.data.count !== 'undefined') {
        var saved = parseInt(resp.data.count, 10);
        if (isNaN(saved)) { saved = requested; }
        window.MG_BULK_ADV.worker_count = saved;
        updateWorkerToggleUI(saved);
        var okMsg = window.MG_BULK_ADV.worker_feedback_saved || 'Beállítva: %d worker.';
        if (typeof okMsg === 'string' && okMsg.indexOf('%d') !== -1) {
          okMsg = okMsg.replace('%d', saved);
        }
        showWorkerFeedback(okMsg, 'success');
      } else {
        updateWorkerToggleUI(window.MG_BULK_ADV.worker_count || options[0]);
        var errMsg = window.MG_BULK_ADV.worker_feedback_error || 'Nem sikerült menteni. Próbáld újra.';
        showWorkerFeedback(errMsg, 'error');
      }
    }, 'json').fail(function () {
      updateWorkerToggleUI(window.MG_BULK_ADV.worker_count || options[0]);
      var errMsg = window.MG_BULK_ADV.worker_feedback_error || 'Nem sikerült menteni. Próbáld újra.';
      showWorkerFeedback(errMsg, 'error');
    }).always(function () {
      window.MG_BULK_ADV._savingWorkerCount = false;
    });
  });

  $(document).on('click', '#mg-bulk-apply-first', function (e) {
    e.preventDefault();
    var $rows = $('#mg-bulk-rows .mg-item-row');
    if ($rows.length <= 1) { return; }
    copyMainFromFirst();
    copySubsFromFirst();
    copyParentFromFirst();
    copyTagsFromFirst();
    copyCustomFlagFromFirst();
  });

  function getSelectedProductKeys() {
    var keys = []; $('.mg-type-cb:checked').each(function () { keys.push($(this).val()); }); return keys;
  }

  function serverErrorToText(xhr) {
    try {
      if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) return xhr.responseJSON.data.message;
      if (xhr && typeof xhr.responseText === 'string') return xhr.responseText.substring(0, 200);
    } catch (e) { }
    return 'Ismeretlen hiba';
  }

  function ajaxWithRetry(options, retryCount, $statusElement) {
    retryCount = retryCount || 0;
    var maxRetries = MG_AJAX_MAX_RETRIES;
    var baseDelay = MG_AJAX_RETRY_DELAY;

    var deferred = $.Deferred();

    $.ajax(options)
      .done(function (data, textStatus, jqXHR) {
        deferred.resolve(data, textStatus, jqXHR);
      })
      .fail(function (xhr, status, error) {
        if (retryCount < maxRetries) {
          var delay = baseDelay * Math.pow(2, retryCount); // Exponential backoff: 1s, 2s, 4s
          var attemptMsg = 'Újrapróbálkozás ' + (retryCount + 1) + '/' + maxRetries + ' (' + (delay / 1000) + 's)...';

          if ($statusElement && $statusElement.length) {
            $statusElement.text(attemptMsg);
          }

          console.log('AJAX failed, retrying in ' + delay + 'ms... (attempt ' + (retryCount + 1) + '/' + maxRetries + ')', {
            status: status,
            error: error,
            url: options.url
          });

          setTimeout(function () {
            ajaxWithRetry(options, retryCount + 1, $statusElement)
              .done(function (data, textStatus, jqXHR) {
                deferred.resolve(data, textStatus, jqXHR);
              })
              .fail(function (xhr, status, error) {
                deferred.reject(xhr, status, error);
              });
          }, delay);
        } else {
          // Max retries exceeded
          console.error('AJAX failed after ' + maxRetries + ' retries', {
            status: status,
            error: error,
            xhr: xhr
          });
          deferred.reject(xhr, status, error);
        }
      });

    return deferred.promise();
  }

  function startQueueProcessing($rowsCollection, files, keys, defaultsSnapshot) {
    var rows = $rowsCollection.toArray();
    var total = rows.length;
    var active = 0;
    var nextIndex = 0;
    var uploadLimit = 2;
    var jobIds = [];
    var jobRows = {};
    var failedLocal = 0;
    var pollingTimer = null;

    window.MG_BULK_ADV._isRunning = true;
    $('#mg-bulk-start').prop('disabled', true);
    $('.mg-worker-toggle').prop('disabled', true);
    $('#mg-bulk-queue-save').prop('disabled', true);
    $('input[name="mg-bulk-mode"]').prop('disabled', true);
    $rowsCollection.each(function () { $(this).find('.mg-state').text('Feltöltésre vár…'); });

    function updateProgressFromStats(stats) {
      var totalCount = (stats && typeof stats.total === 'number') ? stats.total : jobIds.length;
      totalCount += failedLocal;
      var completed = 0;
      if (stats) {
        completed = (stats.completed || 0) + (stats.failed || 0);
      }
      completed += failedLocal;
      var pct = totalCount > 0 ? Math.round((completed / totalCount) * 100) : 100;
      if (pct < 0) { pct = 0; }
      if (pct > 100) { pct = 100; }
      $('#mg-bulk-bar').css('width', pct + '%');
      var statusParts = [pct + '%'];
      if (totalCount > 0) {
        statusParts.push(completed + '/' + totalCount);
      }
      $('#mg-bulk-status').text(statusParts.join(' · '));
    }

    function updateEnqueueProgress(enqueuedCount) {
      var pct = total > 0 ? Math.round((enqueuedCount / total) * 100) : 0;
      if (pct < 0) { pct = 0; }
      if (pct > 100) { pct = 100; }
      $('#mg-bulk-bar').css('width', pct + '%');
      $('#mg-bulk-status').text(pct + '% · feltöltés: ' + enqueuedCount + '/' + total);
    }

    function finalize() {
      window.MG_BULK_ADV._isRunning = false;
      $('#mg-bulk-start').prop('disabled', false);
      $('.mg-worker-toggle').prop('disabled', false);
      $('#mg-bulk-queue-save').prop('disabled', false);
      $('input[name="mg-bulk-mode"]').prop('disabled', false);
      if (pollingTimer) {
        clearInterval(pollingTimer);
        pollingTimer = null;
      }
    }

    function updateRowStatus(job) {
      if (!job || !job.id || !jobRows[job.id]) { return; }
      var $row = jobRows[job.id];
      var status = job.status || '';
      if (status === 'pending') {
        $row.find('.mg-state').text('Sorban áll…');
      } else if (status === 'running') {
        $row.find('.mg-state').text('Feldolgozás…');
      } else if (status === 'completed') {
        $row.find('.mg-state').text('Kész');
      } else if (status === 'failed') {
        var msg = job.message ? ('Hiba: ' + job.message) : 'Hiba';
        $row.find('.mg-state').text(msg);
      } else {
        $row.find('.mg-state').text('Ismeretlen');
      }
    }

    function pollQueue() {
      if (!jobIds.length) {
        updateProgressFromStats({ total: failedLocal, completed: failedLocal, failed: 0 });
        finalize();
        return;
      }
      $.post(MG_BULK_ADV.ajax_url, {
        action: 'mg_bulk_queue_status',
        nonce: MG_BULK_ADV.nonce,
        job_ids: jobIds
      }, function (resp) {
        if (!resp || !resp.success || !resp.data) {
          return;
        }
        var jobs = resp.data.jobs || [];
        jobs.forEach(updateRowStatus);
        updateProgressFromStats(resp.data.stats || {});
        var stats = resp.data.stats || {};
        var totalCount = (typeof stats.total === 'number') ? stats.total : jobIds.length;
        totalCount += failedLocal;
        var completedCount = (stats.completed || 0) + (stats.failed || 0) + failedLocal;
        if (totalCount > 0 && completedCount >= totalCount) {
          finalize();
        }
      }, 'json');
    }

    function enqueueJob(index) {
      var $row = $(rows[index]);
      if (!$row.length) {
        failedLocal++;
        updateEnqueueProgress(nextIndex);
        return;
      }
      var file = files[index];
      if (!file) {
        $row.find('.mg-state').text('Hiba: hiányzó fájl');
        failedLocal++;
        updateEnqueueProgress(nextIndex);
        return;
      }
      active++;
      var $state = $row.find('.mg-state');
      $state.text('Feltöltés…');
      var $mainSel = $row.find('select.mg-main');
      var $subsSel = $row.find('select.mg-subs');
      var $name = $row.find('input.mg-name');
      var parentId = parseInt($row.find('.mg-parent-id').val(), 10) || 0;
      var form = new FormData();
      form.append('action', 'mg_bulk_queue_enqueue');
      form.append('nonce', MG_BULK_ADV.nonce);
      form.append('design_file', file);
      keys.forEach(function (k) { form.append('product_keys[]', k); });
      form.append('product_name', $name.val().trim());
      form.append('main_cat', $mainSel.val() || '0');
      collectSubValues($subsSel).forEach(function (id) { form.append('sub_cats[]', id); });
      form.append('parent_id', String(parentId));
      form.append('tags', ($row.find('.mg-tags-input').val() || '').trim());
      form.append('custom_product', $row.find('.mg-custom-flag').is(':checked') ? '1' : '0');
      form.append('primary_type', defaultsSnapshot.type || '');
      form.append('primary_color', defaultsSnapshot.color || '');
      form.append('primary_size', defaultsSnapshot.size || '');

      $.ajax({
        url: MG_BULK_ADV.ajax_url,
        method: 'POST',
        data: form,
        processData: false,
        contentType: false,
        dataType: 'json'
      }).done(function (resp) {
        if (resp && resp.success && resp.data && resp.data.job_id) {
          var jobId = resp.data.job_id;
          jobIds.push(jobId);
          jobRows[jobId] = $row;
          $state.text('Sorban áll…');
        } else {
          var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Ismeretlen';
          $state.text('Hiba: ' + msg);
          failedLocal++;
        }
      }).fail(function (xhr) {
        $state.text('Hiba: ' + serverErrorToText(xhr));
        failedLocal++;
      }).always(function () {
        active--;
        updateEnqueueProgress(jobIds.length + failedLocal);
        enqueueNext();
      });
    }

    function enqueueNext() {
      if (nextIndex >= total && active === 0) {
        updateEnqueueProgress(jobIds.length + failedLocal);
        pollQueue();
        pollingTimer = setInterval(pollQueue, 4000);
        return;
      }
      while (active < uploadLimit && nextIndex < total) {
        enqueueJob(nextIndex++);
      }
    }

    updateEnqueueProgress(0);
    enqueueNext();
  }

  $('#mg-bulk-start').on('click', function (e) {
    e.preventDefault();
    if (!window.MG_BULK_ADV) { window.MG_BULK_ADV = {}; }
    if (window.MG_BULK_ADV._isRunning) { return; }
    var inputEl = $('#mg-bulk-files-adv')[0];
    var files = (window.MG_BULK_ADV && Array.isArray(window.MG_BULK_ADV.imageFiles)) ? window.MG_BULK_ADV.imageFiles : ((inputEl && inputEl.files) ? Array.from(inputEl.files).filter(isImageFile) : null);
    if (!files || !files.length) { alert('Válassz fájlokat.'); return; }
    var keys = getSelectedProductKeys();
    if (!keys.length) { alert('Válassz legalább egy terméktípust.'); return; }
    var $rowsCollection = $('#mg-bulk-rows .mg-item-row');
    if (!$rowsCollection.length) { alert('Nincs feldolgozható sor.'); return; }

    var defaultsSnapshot = $.extend({}, window.MG_BULK_ADV.activeDefaults || {});

    mgDedupeTagInputs();
    if (getBulkMode() === 'queue') {
      startQueueProcessing($rowsCollection, files, keys, defaultsSnapshot);
      return;
    }

    var rows = $rowsCollection.toArray();
    var total = rows.length;
    var done = 0;
    var active = 0;
    var nextIndex = 0;
    var options = sanitizeWorkerOptions(window.MG_BULK_ADV.worker_options);
    window.MG_BULK_ADV.worker_options = options;
    var limit = parseInt(window.MG_BULK_ADV.worker_count, 10);
    if (isNaN(limit) || limit < 1) { limit = options[0]; }
    var maxOption = options.length ? options[options.length - 1] : limit;
    if (limit > maxOption) { limit = maxOption; }
    limit = Math.max(1, Math.min(limit, total));

    window.MG_BULK_ADV._isRunning = true;
    $('#mg-bulk-start').prop('disabled', true);
    $('.mg-worker-toggle').prop('disabled', true);
    $('#mg-bulk-queue-save').prop('disabled', true);
    $('input[name="mg-bulk-mode"]').prop('disabled', true);
    $rowsCollection.each(function () { $(this).find('.mg-state').text('Sorban áll…'); });

    var jobProgress = {};

    function getActiveProgress() {
      var sum = 0;
      Object.keys(jobProgress).forEach(function (key) {
        var value = jobProgress[key];
        if (typeof value === 'number' && value > 0) { sum += value; }
      });
      return sum;
    }

    function updateProgress() {
      var pct = total > 0 ? Math.round((done / total) * 100) : 0;
      if (pct < 0) { pct = 0; }
      if (pct > 100) { pct = 100; }
      $('#mg-bulk-bar').css('width', pct + '%');
      var statusParts = [pct + '%'];
      if (total > 0) {
        statusParts.push(done + '/' + total);
      }
      var activeCount = Object.keys(jobProgress).length;
      if (activeCount > 0) {
        statusParts.push('folyamatban: ' + activeCount);
      }
      $('#mg-bulk-status').text(statusParts.join(' · '));
    }
    updateProgress();

    function finalize() {
      window.MG_BULK_ADV._isRunning = false;
      $('#mg-bulk-start').prop('disabled', false);
      $('.mg-worker-toggle').prop('disabled', false);
      $('#mg-bulk-queue-save').prop('disabled', false);
      $('input[name="mg-bulk-mode"]').prop('disabled', false);
    }

    function launchNext() {
      if (done >= total && active === 0) {
        finalize();
        updateProgress();
        return;
      }
      while (active < limit && nextIndex < total) {
        startJob(nextIndex++);
      }
    }

    function startJob(index) {
      var $row = $(rows[index]);
      if (!$row.length) {
        done++;
        updateProgress();
        launchNext();
        return;
      }
      var file = files[index];
      if (!file) {
        $row.find('.mg-state').text('Hiba: hiányzó fájl');
        done++;
        updateProgress();
        launchNext();
        return;
      }
      active++;
      var $state = $row.find('.mg-state');
      $state.text('Feldolgozás...');
      var $mainSel = $row.find('select.mg-main');
      var $subsSel = $row.find('select.mg-subs');
      var $name = $row.find('input.mg-name');
      var parentId = parseInt($row.find('.mg-parent-id').val(), 10) || 0;
      var form = new FormData();
      form.append('action', 'mg_bulk_process');
      form.append('nonce', MG_BULK_ADV.nonce);
      form.append('design_file', file);
      keys.forEach(function (k) { form.append('product_keys[]', k); });
      form.append('product_name', $name.val().trim());
      form.append('main_cat', $mainSel.val() || '0');
      collectSubValues($subsSel).forEach(function (id) { form.append('sub_cats[]', id); });
      form.append('parent_id', String(parentId));
      var initialTags = ($row.find('.mg-tags-input').val() || '').trim();
      form.append('tags', initialTags);
      form.append('custom_product', $row.find('.mg-custom-flag').is(':checked') ? '1' : '0');
      form.append('primary_type', defaultsSnapshot.type || '');
      form.append('primary_color', defaultsSnapshot.color || '');
      form.append('primary_size', defaultsSnapshot.size || '');

      ajaxWithRetry({
        url: MG_BULK_ADV.ajax_url,
        method: 'POST',
        data: form,
        processData: false,
        contentType: false,
        dataType: 'json',
        timeout: MG_AJAX_TIMEOUT,
        xhr: function () {
          var xhr = $.ajaxSettings.xhr();
          if (xhr && xhr.upload) {
            xhr.upload.addEventListener('progress', function (evt) {
              if (!evt || !evt.lengthComputable) { return; }
              var ratio = evt.total > 0 ? (evt.loaded / evt.total) : 0;
              if (ratio < 0) { ratio = 0; }
              if (ratio > 1) { ratio = 1; }
              jobProgress[index] = Math.min(ratio, 0.9);
              $state.text('Feltöltés... ' + Math.round(ratio * 100) + '%');
              updateProgress();
            });
            xhr.upload.addEventListener('load', function () {
              if (typeof jobProgress[index] === 'number') {
                jobProgress[index] = Math.max(jobProgress[index], 0.9);
                updateProgress();
              }
              $state.text('Feldolgozás...');
            });
          }
          return xhr;
        }
      }, 0, $state).done(function (resp) {
        if (resp && resp.success) {
          $state.text('OK…');
          var pid = resp.data && resp.data.product_id ? parseInt(resp.data.product_id, 10) : 0;
          var latestTags = ($row.find('.mg-tags-input').val() || '').trim();
          if (pid && latestTags) {
            $.post(MG_BULK_ADV.ajax_url, {
              action: 'mg_set_product_tags',
              nonce: MG_BULK_ADV.nonce,
              product_id: pid,
              tags: latestTags
            }, function (r) {
              if (r && r.success) { $state.text('OK'); }
              else { $state.text('OK – tagek hiba'); }
            }, 'json').fail(function () { $state.text('OK – tagek hiba'); });
          } else {
            $state.text('OK');
          }
        } else {
          var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Ismeretlen';
          $state.text('Hiba: ' + msg);
        }
      }).fail(function (xhr, status, error) {
        var errorMsg = serverErrorToText(xhr);
        if (status === 'timeout') {
          errorMsg = 'Időtúllépés (120s)';
        } else if (xhr.status === 0) {
          errorMsg = 'Hálózati hiba';
        } else if (xhr.status === 404) {
          errorMsg = 'AJAX endpoint nem található (404)';
        } else if (xhr.status === 500) {
          errorMsg = 'Szerver hiba (500)';
        }
        $state.text('Hiba: ' + errorMsg);
        console.error('Bulk upload failed after retries:', {
          status: status,
          error: error,
          xhr: xhr,
          file: file.name
        });
      }).always(function () {
        done++;
        active--;
        delete jobProgress[index];
        updateProgress();
        launchNext();
      });
    }

    launchNext();
  });

})(jQuery);


// ---- Drag & drop zone ----
(function ($) {
  var $zone = $('#mg-drop-zone');
  var $input = $('#mg-bulk-files-adv');
  if ($zone.length && $input.length) {
    function isJsonCandidate(file) {
      if (!file) { return false; }
      return file.type === 'application/json' || /\.json$/i.test(file.name || '');
    }
    function isImageCandidate(file) {
      if (!file) { return false; }
      if (file.type && /^image\/(png|jpe?g|webp|gif|svg\+xml)$/i.test(file.type)) { return true; }
      return /\.(png|jpe?g|jpg|webp|gif|svg)$/i.test(file.name || '');
    }
    function accept(f) {
      return isImageCandidate(f) || isJsonCandidate(f);
    }
    function setFiles(list) {
      try {
        var dt = new DataTransfer();
        Array.from(list || []).forEach(function (f) { if (accept(f)) dt.items.add(f); });
        if (dt.files && dt.files.length) { $input[0].files = dt.files; renderRows($input[0].files); return; }
      } catch (e) { }
      renderRows(Array.from(list || []).filter(accept));
    }
    $zone.on('click', '.button-link', function (e) { e.preventDefault(); $input.trigger('click'); });
    $zone.on('drag dragstart dragend dragover dragenter dragleave drop', function (e) { e.preventDefault(); e.stopPropagation(); });
    $zone.on('dragover dragenter', function () { $zone.addClass('is-dragover'); });
    $zone.on('dragleave dragend drop', function () { $zone.removeClass('is-dragover'); });
    $zone.on('drop', function (e) { var dt = e.originalEvent && e.originalEvent.dataTransfer; setFiles((dt && dt.files) || []); });
  }
  // Revoke blobs on start
  $(document).on('click', '#mg-bulk-start', function () {
    try { (MG_BULK_ADV._blobUrls || []).forEach(function (u) { (window.URL || window.webkitURL).revokeObjectURL(u); }); MG_BULK_ADV._blobUrls = []; } catch (e) { }
  });
})(jQuery);
