/**
 * "AI duplikátum" modal (admin/class-dashboard-page.php, MG_AI_Duplicate AJAX).
 *
 * One analysis plans 1–4 variants (each with its own direction and suggested
 * product name); the page draws them two at a time, one request per variant,
 * and polls the server if a proxy cuts a long request. A variant can be
 * kept, redrawn (also with an edited prompt) or dropped. "Létrehozás" takes
 * the kept ones in turn: the upscale input, the browser upscale (the set
 * model and palette, assets/js/ai-upscale.js), the upload, then a new
 * product in the product queue.
 */
(function ($) {
    'use strict';

    const PARALLEL = 2;
    const POLL_MS = 5000;
    const intensityLabels = { strong: 'új stílus', medium: 'finom átdolgozás', subtle: 'extra finom', full: 'teljesen új' };
    const upscaleLabels = {
        ai2x: 'AI felskálázva 2×', ai3x: 'AI felskálázva 3×', ai4x: 'AI felskálázva 4×',
        lanczos2x: '2× sima nagyítás', lanczos3x: '3× sima nagyítás', lanczos4x: '4× sima nagyítás',
        native: 'Eredeti felbontás',
    };
    const modelLabels = { cugan: 'Real-CUGAN', waifu2x: 'waifu2x', esrgan: 'Real-ESRGAN' };

    let productId = '';
    let slots = {};        // id -> slot from the server
    let order = [];        // slot ids, as listed
    let approved = {};     // id -> true
    let names = {};        // id -> the name typed on the card
    let queue = [];        // [id, customPrompt] waiting to be drawn
    let drawing = {};      // id -> true while this page's request runs
    let planning = false;
    let creating = false;
    let pollTimer = null;

    const $status = function () { return $('#mg-ai-duplicate-status'); };

    function post(action, data) {
        const cfg = window.MG_AJAX || {};
        return $.ajax({
            url: cfg.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: $.extend({ action: action, nonce: cfg.nonce, product_id: productId }, data || {}),
        });
    }

    function message(resp, xhr) {
        const data = (resp && resp.data) || (xhr && xhr.responseJSON && xhr.responseJSON.data);
        return (data && data.message) || (xhr && xhr.status ? 'a kérés nem sikerült (HTTP ' + xhr.status + ').' : 'a kérés nem sikerült.');
    }

    function megapixels(w, h) {
        return (w * h / 1e6).toFixed(2).replace('.', ',');
    }

    function busy() {
        return planning || creating || queue.length > 0 || Object.keys(drawing).length > 0
            || order.some(function (id) { return slots[id] && slots[id].status === 'running'; });
    }

    function refreshControls() {
        const kept = order.filter(function (id) { return approved[id] && slots[id] && slots[id].status === 'done'; });
        $('.mg-ai-duplicate__footer').prop('hidden', order.length === 0);
        $('#mg-ai-duplicate-create').prop('disabled', creating || kept.length === 0)
            .text(kept.length ? 'Jóváhagyottak létrehozása új termékként (' + kept.length + ')' : 'Jóváhagyottak létrehozása új termékként');
        $('#mg-ai-duplicate-submit').prop('disabled', planning || creating);
        $('#mg-ai-duplicate-discard-all').prop('disabled', creating);
    }

    /** A card is rebuilt only when what it shows changed, so a name being typed stays. */
    function signature(slot) {
        return [slot.status, slot.attachment_id, slot.upscale, slot.message, slot.full, drawing[slot.id] ? 1 : 0].join('|');
    }

    function renderSlot(id, note) {
        const slot = slots[id];
        const $wrap = $('#mg-ai-duplicate-slots');
        let $card = $wrap.children('[data-slot="' + id + '"]');
        if (!slot) {
            $card.remove();
            return;
        }
        if ($card.length && $card.data('sig') === signature(slot) && note === undefined) {
            return;
        }
        const $new = $('<div class="mg-ai-redesign__candidate mg-ai-duplicate__slot"></div>').attr('data-slot', id).data('sig', signature(slot));
        $new.toggleClass('is-approved', !!approved[id]);
        const index = order.indexOf(id) + 1;
        $new.append($('<p></p>').append($('<strong></strong>').text(index + '. változat')));
        if (slot.status === 'done' && slot.full) {
            $new.append($('<a target="_blank" rel="noopener"></a>').attr('href', slot.full).append($('<img alt="" />').attr('src', slot.preview || slot.full)));
        } else if (slot.status === 'error') {
            $new.append($('<p class="mg-ai-duplicate__error"></p>').text('Hiba: ' + (slot.message || 'ismeretlen')));
        } else {
            $new.append($('<p class="description"></p>').text(slot.status === 'running' || drawing[id] ? 'A képgeneráló rajzolja (1–3 perc)…' : 'Sorban áll…'));
        }
        if (slot.status === 'done' && slot.message) {
            $new.append($('<p class="mg-ai-duplicate__error"></p>').text('Az újrarajzolás nem sikerült: ' + slot.message));
        }
        const $name = $('<input type="text" class="large-text mg-ai-duplicate-name" />').val(names[id] !== undefined ? names[id] : (slot.name || ''));
        $new.append($('<label class="mg-ai-duplicate__name"></label>').text('Terméknév').append($name));
        if (slot.new_style) {
            $new.append($('<p></p>').append($('<strong>Új stílus: </strong>')).append(document.createTextNode(slot.new_style)));
        }
        const meta = [intensityLabels[slot.intensity] || '', slot.generated_size ? 'generálva ' + slot.generated_size + ' px' : ''];
        if (upscaleLabels[slot.upscale]) {
            meta.push(upscaleLabels[slot.upscale] + (slot.upscale.indexOf('ai') === 0 && modelLabels[slot.upscale_model] ? ' (' + modelLabels[slot.upscale_model] + ')' : '')
                + (slot.palette ? ' · palettás PNG, ' + slot.palette + ' szín' : '') + (slot.width ? ' (' + slot.width + '×' + slot.height + ' px)' : ''));
        }
        $new.append($('<p class="description"></p>').text(meta.filter(Boolean).join(' · ')));
        const $actions = $('<p class="mg-ai-duplicate__actions"></p>');
        const locked = creating || slot.status === 'running' || !!drawing[id];
        if (slot.status === 'done') {
            $actions.append($('<button type="button" class="button mg-ai-duplicate-approve"></button>')
                .text(approved[id] ? '✓ Jóváhagyva' : 'Jóváhagyom').toggleClass('button-primary', !!approved[id]).prop('disabled', creating));
            $actions.append(' ');
        }
        $actions.append($('<button type="button" class="button mg-ai-duplicate-redraw"></button>').text(slot.status === 'done' ? 'Újragenerálás' : 'Generálás').prop('disabled', locked));
        $actions.append(' ');
        $actions.append($('<button type="button" class="button mg-ai-duplicate-edit">Prompt javítása</button>').prop('disabled', locked));
        $actions.append(' ');
        $actions.append($('<button type="button" class="button button-link-delete mg-ai-duplicate-discard">Törlés</button>').prop('disabled', locked));
        $new.append($actions);
        $new.append($('<div class="mg-ai-duplicate__prompt" hidden></div>')
            .append($('<textarea rows="7" class="large-text code"></textarea>').val(slot.prompt || ''))
            .append($('<p></p>').append($('<button type="button" class="button mg-ai-duplicate-redraw-prompt">Újragenerálás ezzel a prompttal</button>'))));
        if (note) {
            $new.append($('<p class="description mg-ai-duplicate__note"></p>').text(note));
        }
        if ($card.length) {
            $card.replaceWith($new);
        } else {
            $wrap.append($new);
        }
    }

    function setSlots(list) {
        (list || []).forEach(function (slot) {
            slots[slot.id] = slot;
            if (order.indexOf(slot.id) === -1) {
                order.push(slot.id);
            }
        });
        const ids = (list || []).map(function (s) { return s.id; });
        order = order.filter(function (id) {
            if (ids.indexOf(id) !== -1) {
                return true;
            }
            delete slots[id];
            delete approved[id];
            $('#mg-ai-duplicate-slots').children('[data-slot="' + id + '"]').remove();
            return false;
        });
        order.forEach(function (id) { renderSlot(id); });
        refreshControls();
    }

    function startPlanned() {
        order.forEach(function (id) {
            if (slots[id].status === 'planned' && !drawing[id] && !queue.some(function (q) { return q[0] === id; })) {
                queue.push([id, '']);
            }
        });
        pump();
    }

    function pump() {
        while (Object.keys(drawing).length < PARALLEL && queue.length) {
            const job = queue.shift();
            if (slots[job[0]]) {
                draw(job[0], job[1]);
            }
        }
        refreshControls();
    }

    function draw(id, customPrompt) {
        const pid = productId;
        drawing[id] = true;
        renderSlot(id);
        post('mg_ai_duplicate_generate', { slot: id, custom_prompt: customPrompt || '' }).then(function (resp) {
            if (pid !== productId) {
                return;
            }
            if (resp && resp.data && resp.data.slot) {
                slots[id] = resp.data.slot;
            }
        }, function (xhr) {
            if (pid !== productId) {
                return;
            }
            const data = xhr && xhr.responseJSON && xhr.responseJSON.data;
            if (data && data.slot) {
                slots[id] = data.slot;
            } else {
                // A proxy cut the request: the server goes on, the poll reads its state.
                schedulePoll();
            }
        }).always(function () {
            delete drawing[id];
            if (pid === productId) {
                renderSlot(id);
                pump();
                updateStatus();
            }
        });
    }

    function updateStatus() {
        if (creating || planning) {
            return;
        }
        const done = order.filter(function (id) { return slots[id].status === 'done'; }).length;
        const waiting = order.filter(function (id) { return slots[id].status === 'planned' || slots[id].status === 'running' || drawing[id]; }).length;
        if (waiting) {
            $status().text('Változatok rajzolása: ' + done + ' kész, ' + waiting + ' folyamatban. Az ablak bezárható, a lapot hagyd nyitva.');
        } else if (order.length) {
            $status().text('Jelöld meg a jó változatokat, írd át a nevüket, ha kell, és hozd létre őket új termékként.');
        }
    }

    function schedulePoll() {
        if (pollTimer) {
            return;
        }
        pollTimer = setTimeout(function () {
            pollTimer = null;
            const pid = productId;
            post('mg_ai_duplicate_status').done(function (resp) {
                if (pid !== productId || !resp || !resp.success) {
                    return;
                }
                handlePlan(resp.data.plan);
                setSlots(resp.data.slots);
                startPlanned();
                updateStatus();
                if (planning || order.some(function (id) { return slots[id].status === 'running' && !drawing[id]; })) {
                    schedulePoll();
                }
            }).fail(function () {
                if (pid === productId) {
                    schedulePoll();
                }
            });
        }, POLL_MS);
    }

    function handlePlan(plan) {
        if (!plan) {
            return;
        }
        if (plan.status === 'running') {
            planning = true;
            $status().text('Az elemző modell tervezi a változatokat és a neveket…');
        } else if (planning) {
            planning = false;
            if (plan.status === 'error') {
                $status().text('Hiba: ' + (plan.message || 'ismeretlen'));
            }
        }
        refreshControls();
    }

    function loadInfo() {
        const pid = productId;
        return post('mg_ai_duplicate_info').done(function (resp) {
            if (pid !== productId) {
                return;
            }
            if (!resp || !resp.success) {
                $status().text('Hiba: ' + message(resp));
                return;
            }
            const d = resp.data;
            if (d.design_url) {
                $('#mg-ai-duplicate-original').attr('src', d.design_url).show();
            }
            $('#mg-ai-duplicate-original-error').text(d.design_error || '');
            const $analyzer = $('#mg-ai-duplicate-analyzer').empty();
            $.each(d.analyzers || {}, function (value, label) {
                $analyzer.append($('<option></option>').val(value).text(label));
            });
            $analyzer.val(d.analyzer);
            $('#mg-ai-duplicate-quality').val(d.quality || 'medium');
            const $res = $('#mg-ai-duplicate-resolution').empty();
            const sizes = d.sizes || [];
            $res.append($('<option value="economy"></option>').text('Gazdaságos – a legkisebb' + (sizes[0] ? ' (' + sizes[0].width + '×' + sizes[0].height + ', ' + megapixels(sizes[0].width, sizes[0].height) + ' MP)' : '')));
            let pick = 'economy';
            sizes.forEach(function (z) {
                $res.append($('<option></option>').val(String(z.edge))
                    .text(z.width + '×' + z.height + ' px · ' + megapixels(z.width, z.height) + ' MP' + (z.experimental ? ' · kísérleti' : '')));
                if (d.resolution !== 'economy' && z.edge <= Number(d.resolution)) {
                    pick = String(z.edge);
                }
            });
            $res.val(pick);
            handlePlan(d.plan);
            setSlots(d.slots);
            $('#mg-ai-duplicate-submit').prop('disabled', !!d.design_error || planning);
            startPlanned();
            updateStatus();
            if (planning || order.some(function (id) { return slots[id].status === 'running'; })) {
                schedulePoll();
            }
        }).fail(function (xhr) {
            $status().text('Hiba: ' + message(null, xhr));
        });
    }

    function plan() {
        const pid = productId;
        planning = true;
        refreshControls();
        $status().text('Az elemző modell tervezi a változatokat és a neveket…');
        post('mg_ai_duplicate_plan', {
            count: $('#mg-ai-duplicate-count').val(),
            intensity: $('#mg-ai-duplicate-intensity').val(),
            analyzer: $('#mg-ai-duplicate-analyzer').val(),
            quality: $('#mg-ai-duplicate-quality').val(),
            resolution: $('#mg-ai-duplicate-resolution').val(),
            design_text: $('#mg-ai-duplicate-text').val(),
            style_hint: $('#mg-ai-duplicate-hint').val(),
        }).then(function (resp) {
            if (pid !== productId) {
                return;
            }
            planning = false;
            if (resp && resp.data && resp.data.plan && resp.data.plan.status === 'running') {
                planning = true;
                schedulePoll();
                return;
            }
            setSlots(resp.data.slots);
            startPlanned();
            updateStatus();
        }, function (xhr) {
            if (pid !== productId) {
                return;
            }
            const data = xhr && xhr.responseJSON && xhr.responseJSON.data;
            if (data && data.plan) {
                planning = false;
                $status().text('Hiba: ' + message(null, xhr));
                setSlots(data.slots);
            } else {
                // A proxy cut the analysis: the server goes on, the poll reads its state.
                schedulePoll();
            }
        }).always(refreshControls);
    }

    function upload(id, blob) {
        const cfg = window.MG_AJAX || {};
        const form = new FormData();
        form.append('action', 'mg_ai_duplicate_upscaled');
        form.append('nonce', cfg.nonce);
        form.append('product_id', productId);
        form.append('slot', id);
        form.append('upscaled', blob, 'upscaled.png');
        return $.ajax({ url: cfg.ajax_url, method: 'POST', data: form, processData: false, contentType: false, dataType: 'json' });
    }

    /** The kept variants in turn: upscale (a failed one gets the sharp enlargement), then a new product. */
    async function createKept() {
        const ids = order.filter(function (id) { return approved[id] && slots[id] && slots[id].status === 'done'; });
        if (!ids.length || !window.confirm(ids.length + ' változatból új termék lesz (felskálázás, majd a mockupok elkészítése a háttérben). Folytatod?')) {
            return;
        }
        creating = true;
        refreshControls();
        order.forEach(function (id) { renderSlot(id, ''); });
        let made = 0;
        for (let i = 0; i < ids.length; i++) {
            const id = ids[i];
            const name = names[id] !== undefined ? names[id] : (slots[id].name || '');
            let note = '';
            $status().text((i + 1) + '/' + ids.length + ': ' + name + ' – felskálázás előkészítése…');
            try {
                const prepared = await post('mg_ai_duplicate_prepare', { slot: id });
                if (!prepared || !prepared.success) {
                    throw new Error(message(prepared));
                }
                const d = prepared.data;
                if (d.upscale === 'none' && d.upscale_factor >= 2 && window.MGAIUpscale) {
                    const blob = await window.MGAIUpscale.upscale(d.full, function (p) {
                        $status().text((i + 1) + '/' + ids.length + ': ' + name + ' – AI felskálázás ' + d.upscale_factor + '×: ' + Math.round(p * 100) + '% (másik fülre átválthatsz).');
                    }, d.upscale_factor, d.upscale_engine, d.palette_finish);
                    $status().text((i + 1) + '/' + ids.length + ': ' + name + ' – feltöltés…');
                    const stored = await upload(id, blob);
                    if (!stored || !stored.success) {
                        note = ' (AI felskálázás: ' + message(stored) + ' – sima nagyítás lett)';
                    }
                }
            } catch (err) {
                note = ' (AI felskálázás: ' + ((err && err.message) || (err && err.status === 413 ? 'a fájl túl nagy (HTTP 413)' : message(null, err))) + ' – sima nagyítás lett)';
            }
            try {
                $status().text((i + 1) + '/' + ids.length + ': ' + name + ' – új termék sorba állítása…');
                const resp = await post('mg_ai_duplicate_create', { slot: id, name: name });
                if (!resp || !resp.success) {
                    throw new Error(message(resp));
                }
                made++;
                delete approved[id];
                delete slots[id];
                order = order.filter(function (x) { return x !== id; });
                $('#mg-ai-duplicate-slots').children('[data-slot="' + id + '"]')
                    .replaceWith($('<div class="mg-ai-redesign__candidate mg-ai-duplicate__made"></div>').append($('<p></p>').text('✓ ' + resp.data.message + note)));
            } catch (err) {
                renderSlot(id, 'Nem sikerült: ' + ((err && err.message) || message(null, err)) + note);
            }
        }
        creating = false;
        order.forEach(function (id) { renderSlot(id, ''); });
        refreshControls();
        $status().text(made + ' új termék sorba állítva. A mockupok a szerveren, a háttérben készülnek, azt már nem kell megvárni.');
    }

    function reset() {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
        slots = {};
        order = [];
        approved = {};
        names = {};
        queue = [];
        drawing = {};
        planning = false;
        $('#mg-ai-duplicate-slots').empty();
        $('#mg-ai-duplicate-original').attr('src', '').hide();
        $('#mg-ai-duplicate-original-error').empty();
        $('#mg-ai-duplicate-text, #mg-ai-duplicate-hint').val('');
        $status().text('');
        refreshControls();
    }

    $(document).on('click', '.mg-ai-duplicate-trigger', function () {
        if (busy() && String($(this).data('productId')) !== productId
            && !window.confirm('Egy másik minta változatai még készülnek ebben az ablakban. Ha most váltasz, azok a szerveren befejeződnek, és a minta újbóli megnyitásakor itt lesznek. Váltasz?')) {
            return;
        }
        reset();
        productId = String($(this).data('productId') || '');
        $('#mg-ai-duplicate-product-name').text($(this).data('productTitle') || '');
        if (productId) {
            loadInfo();
        }
    });

    $(document).on('click', '#mg-ai-duplicate-submit', plan);

    $(document).on('click', '#mg-ai-duplicate-create', function () {
        createKept();
    });

    $(document).on('input', '.mg-ai-duplicate-name', function () {
        names[$(this).closest('[data-slot]').attr('data-slot')] = $(this).val();
    });

    $(document).on('click', '.mg-ai-duplicate-approve', function () {
        const id = $(this).closest('[data-slot]').attr('data-slot');
        approved[id] = !approved[id];
        renderSlot(id, '');
        refreshControls();
    });

    $(document).on('click', '.mg-ai-duplicate-redraw', function () {
        const id = $(this).closest('[data-slot]').attr('data-slot');
        delete approved[id];
        queue.push([id, '']);
        pump();
        renderSlot(id, '');
        updateStatus();
    });

    $(document).on('click', '.mg-ai-duplicate-edit', function () {
        const $box = $(this).closest('[data-slot]').find('.mg-ai-duplicate__prompt');
        $box.prop('hidden', !$box.prop('hidden'));
    });

    $(document).on('click', '.mg-ai-duplicate-redraw-prompt', function () {
        const $card = $(this).closest('[data-slot]');
        const id = $card.attr('data-slot');
        const prompt = $.trim($card.find('.mg-ai-duplicate__prompt textarea').val());
        if (!prompt) {
            return;
        }
        delete approved[id];
        queue.push([id, prompt]);
        pump();
        renderSlot(id, '');
        updateStatus();
    });

    $(document).on('click', '.mg-ai-duplicate-discard', function () {
        const id = $(this).closest('[data-slot]').attr('data-slot');
        if (!window.confirm('Törlöd ezt a változatot? A képe is törlődik.')) {
            return;
        }
        queue = queue.filter(function (q) { return q[0] !== id; });
        post('mg_ai_duplicate_discard', { slot: id }).done(function (resp) {
            if (resp && resp.success) {
                setSlots(resp.data.slots);
                updateStatus();
            } else {
                $status().text('Hiba: ' + message(resp));
            }
        });
    });

    $(document).on('click', '#mg-ai-duplicate-discard-all', function () {
        if (!order.length || !window.confirm('Törlöd az összes változatot (a képeikkel együtt)? Az eredeti minta nem változik.')) {
            return;
        }
        queue = [];
        post('mg_ai_duplicate_discard', { all: 1 }).done(function (resp) {
            if (resp && resp.success) {
                setSlots(resp.data.slots);
                $status().text(resp.data.deleted + ' változat törölve.');
            } else {
                $status().text('Hiba: ' + message(resp));
            }
        });
    });

    window.addEventListener('beforeunload', function (e) {
        if (busy()) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
})(jQuery);
