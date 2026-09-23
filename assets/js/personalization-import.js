(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.MGPersonalization = api;
})(typeof window === 'undefined' ? globalThis : window, function () {
  'use strict';
  var states = new WeakMap(), pending = [], active = 0, scheduled = false;
  function state(row) {
    if (!states.has(row)) states.set(row, {mode: 'preserve', revision: 0, payload: null});
    return states.get(row);
  }
  function manual(row) {
    var s = state(row); s.revision++; s.mode = 'manual'; s.payload = null;
  }
  function apply(row, payload, enabled, resolve, display) {
    var s = state(row), revision = ++s.revision;
    if (s.mode === 'manual') return;
    s.mode = 'preserve'; s.payload = null;
    display({state: 'reset'});
    if (!enabled) return;
    if (!payload) { display({state: 'missing', message: 'Nincs egyedi mező elemzés.'}); return; }
    s.mode = 'pending';
    display({state: 'pending', message: 'Preset ellenőrzése…'});
    resolve(payload, function (result) {
      if (s.revision !== revision || s.mode === 'manual') return;
      s.mode = result.state === 'matched' ? 'auto' : 'preserve';
      s.payload = s.mode === 'auto' ? payload : null;
      s.preset = result.preset_id || '';
      display(result);
    });
  }
  function selection(row) {
    var s = state(row);
    if (s.mode === 'pending') throw new Error('Várd meg az egyedi mezők ellenőrzését.');
    return {action: s.mode, payload: s.payload, preset: s.preset || ''};
  }
  function request(payload, done) {
    pending.push({payload: payload, done: done});
    if (!scheduled) { scheduled = true; setTimeout(pump, 20); }
  }
  function pump() {
    scheduled = false;
    while (active < 2 && pending.length) {
      var batch = pending.splice(0, 50); active++;
      (function (items) {
        jQuery.ajax({url: MG_BULK_ADV.ajax_url, method: 'POST', dataType: 'json', timeout: 30000,
          data: {action: 'mg_personalization_resolve', nonce: MG_BULK_ADV.nonce,
            items: JSON.stringify(items.map(function (item) { return item.payload; }))}
        }).done(function (response) {
          items.forEach(function (item, index) {
            item.done(response && response.success && Array.isArray(response.data) && response.data[index] || {state: 'review', message: 'A preset ellenőrzése sikertelen.'});
          });
        }).fail(function () {
          items.forEach(function (item) { item.done({state: 'review', message: 'Kapcsolati hiba – ellenőrizd az egyedi mezőket.'}); });
        }).always(function () { active--; pump(); });
      })(batch);
    }
  }
  function updateRow($row, payload, enabled) {
    var row = $row[0];
    var $status = $row.find('.mg-personalization-status');
    if (!$status.length) $status = jQuery('<div class="mg-personalization-status" style="margin-top:6px"></div>').appendTo($row.find('.mg-custom'));
    apply(row, payload && payload.personalization, enabled, request, function (result) {
      if (result.state === 'reset') {
        if ($row.data('mgPersonalizationApplied')) {
          $row.find('.mg-custom-flag').prop('checked', false);
          $row.find('.mg-preset-select').val('').hide();
          $row.data('mgPersonalizationApplied', false);
        }
        $status.text(''); return;
      }
      $status.text((result.state === 'review' ? 'Ellenőrizendő: ' : '') + (result.message || ''));
      if (result.state === 'matched') {
        $row.find('.mg-custom-flag').prop('checked', true);
        $row.find('.mg-preset-select').val(result.preset_id).show();
        $row.data('mgPersonalizationApplied', true);
      }
    });
  }
  function append($row, form) {
    var selected = selection($row[0]);
    form.append('personalization_action', selected.action);
    if (selected.action === 'auto') form.append('personalization', JSON.stringify(selected.payload));
  }
  return {apply: apply, manual: manual, selection: selection, updateRow: updateRow, append: append};
});
