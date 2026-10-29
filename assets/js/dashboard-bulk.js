/**
 * Dashboard: category filter, row selection and the bulk "AI újratervezés".
 *
 * The bulk run reuses the single redesign endpoints per product: generation
 * runs two at a time from this tab (with status polling if a proxy cuts the
 * request), every card shows the original and the new design side by side,
 * and only the approved cards are upscaled in the browser (3× with
 * Real-CUGAN or 4× with Real-ESRGAN, 2× or, when large enough, not at all),
 * finished in the browser (the Design-flow palette PNG when that is on) and
 * queued for mockup regeneration at the end.
 */
(function ($) {
    'use strict';

    const MAX_PARALLEL = 2;
    const POLL_MS = 5000;
    const POLL_LIMIT_MS = 15 * 60 * 1000;

    function cfg() {
        return window.MG_AJAX || {};
    }

    function post(action, data) {
        return $.ajax({
            url: cfg().ajax_url,
            method: 'POST',
            dataType: 'json',
            data: $.extend({ action: action, nonce: cfg().nonce }, data || {}),
        });
    }

    function message(resp) {
        return (resp && resp.data && resp.data.message) || '';
    }

    // ---- Filters: the subcategory list follows the main category. ----
    function syncSubcategories() {
        const main = String($('#mg-dash-cat-main').val() || '0');
        const $sub = $('#mg-dash-cat-sub');
        if (!$sub.length) {
            return;
        }
        let keep = false;
        $sub.find('option').each(function () {
            const owner = String($(this).data('main'));
            const show = owner === '0' || main === '0' || owner === main;
            $(this).prop('hidden', !show).prop('disabled', !show);
            if (show && this.selected) {
                keep = true;
            }
        });
        if (!keep) {
            $sub.val('0');
        }
    }

    // ---- Row selection. ----
    function selectedRows() {
        return $('.mg-dash-check:checked').map(function () {
            return { id: String(this.value), title: String($(this).data('title') || '') };
        }).get();
    }

    function refreshSelection() {
        const total = $('.mg-dash-check').length;
        const count = $('.mg-dash-check:checked').length;
        $('#mg-dash-selected-count').text(count + ' kijelölve');
        $('#mg-dash-bulk-redesign')
            .prop('disabled', count === 0)
            .text('AI újratervezés a kijelöltekre' + (count ? ' (' + count + ')' : ''));
        $('#mg-dash-check-page').prop('checked', total > 0 && count === total).prop('indeterminate', count > 0 && count < total);
    }

    // ---- Bulk redesign. ----
    const bulk = { items: [], opts: null, finalizing: false, finTotal: 0, finDone: 0, finItem: null, dismissed: false };

    const STATUS = {
        queued: 'Sorban áll',
        running: 'Generálás folyamatban… (1–3 perc)',
        done: 'Elkészült – hagyd jóvá, generáld újra vagy töröld',
        approved: 'Jóváhagyva',
        error: 'Hiba',
        skipped: 'Kihagyva',
        finalizing: 'Véglegesítés…',
        finished: 'Kész – a mockupok újragenerálása sorba állítva',
    };
    const STAGES = {
        prepare: 'Előkészítés…',
        analyze: 'Az elemző modell írja az új promptot…',
        image: 'A képgeneráló rajzolja az új mintát (1–3 perc)…',
        save: 'Mentés a médiatárba…',
    };

    function active() {
        return bulk.items.filter(function (item) { return item.status !== 'removed'; });
    }

    function busy() {
        return bulk.finalizing || active().some(function (item) { return item.status === 'running' || item.status === 'queued'; });
    }

    function summary() {
        const items = active();
        const count = function (fn) { return items.filter(fn).length; };
        const done = count(function (i) { return i.candidate && ['done', 'error', 'finished', 'finalizing'].indexOf(i.status) !== -1; });
        const approved = count(function (i) { return i.approved && i.status === 'done'; });
        const parts = [
            items.length + ' minta',
            done + ' elkészült',
            count(function (i) { return i.status === 'running'; }) + ' fut',
            count(function (i) { return i.status === 'queued'; }) + ' vár',
            approved + ' jóváhagyva',
        ];
        const errors = count(function (i) { return i.status === 'error'; });
        const finished = count(function (i) { return i.status === 'finished'; });
        if (errors) {
            parts.push(errors + ' hiba');
        }
        if (finished) {
            parts.push(finished + ' véglegesítve');
        }
        $('#mg-ai-bulk-summary').text(parts.join(' · '));
        $('#mg-ai-bulk-finalize')
            .prop('disabled', bulk.finalizing || approved === 0)
            .text('Jóváhagyottak véglegesítése' + (approved ? ' (' + approved + ')' : ''));
        $('#mg-ai-bulk-approve-all').prop('disabled', bulk.finalizing || !items.some(function (i) { return i.status === 'done' && !i.approved; }));
        $('#mg-ai-bulk-stop').prop('disabled', !items.some(function (i) { return i.status === 'queued'; }));
        $('#mg-ai-bulk-new').prop('disabled', busy());
        progress();
    }

    // ---- Progress bar: where the whole run is, from generation to the end of finalizing. ----
    const STEPS = [['generate', 'Generálás'], ['decide', 'Jóváhagyás'], ['finalize', 'Véglegesítés'], ['complete', 'Kész']];
    const baseTitle = document.title;

    // Generation is the first half of a design's way, finalizing the second.
    function itemProgress(item) {
        switch (item.status) {
            case 'running': return 0.2;
            case 'done': return 0.5;
            case 'finalizing': return 0.5 + 0.5 * (item.fin || 0);
            case 'finished':
            case 'error': return 1;
            default: return 0;
        }
    }

    function progressModel() {
        const items = active().filter(function (i) { return i.status !== 'new' && i.status !== 'skipped'; });
        if (!items.length) {
            return null;
        }
        const count = function (fn) { return items.filter(fn).length; };
        const generating = count(function (i) { return i.status === 'running' || i.status === 'queued'; });
        const waiting = count(function (i) { return i.status === 'done'; });
        const approved = count(function (i) { return i.status === 'done' && i.approved; });
        const finished = count(function (i) { return i.status === 'finished'; });
        const errors = count(function (i) { return i.status === 'error'; });
        const complete = !generating && !waiting && !bulk.finalizing;
        const lines = [];
        if (generating) {
            lines.push('Generálás: ' + (items.length - generating) + '/' + items.length + ' kész');
        }
        if (bulk.finalizing && bulk.finItem) {
            lines.push('Véglegesítés ' + Math.min(bulk.finDone + 1, bulk.finTotal) + '/' + bulk.finTotal + ': '
                + (bulk.finItem.title || '#' + bulk.finItem.id) + (bulk.finItem.note ? ' – ' + bulk.finItem.note : ''));
        } else if (waiting) {
            lines.push(approved
                ? approved + '/' + waiting + ' jóváhagyva – a „Jóváhagyottak véglegesítése” gombbal indul a felskálázás'
                : 'Jóváhagyásra vár: ' + waiting + ' minta');
        }
        if (finished) {
            lines.push(finished + ' véglegesítve' + (complete ? ' – a mockupok a háttérben készülnek, a lap bezárható' : ''));
        }
        if (errors) {
            lines.push(errors + ' hiba');
        }
        return {
            percent: Math.round(items.reduce(function (sum, i) { return sum + itemProgress(i); }, 0) / items.length * 100),
            title: complete ? (finished ? 'Kész' : 'Nincs több teendő') : (bulk.finalizing ? 'Véglegesítés folyamatban' : (generating ? 'Generálás folyamatban' : 'Döntésre vár')),
            text: lines.join(' · '),
            complete: complete,
            steps: {
                generate: generating ? 'active' : 'done',
                decide: waiting && !bulk.finalizing ? 'active' : (generating ? '' : 'done'),
                finalize: bulk.finalizing ? 'active' : (finished ? 'done' : ''),
                complete: complete && finished ? 'done' : '',
            },
        };
    }

    function strip($el) {
        if (!$el.children().length) {
            const $steps = $('<ol class="mg-ai-bulk-progress__steps"></ol>');
            STEPS.forEach(function (step) {
                $steps.append($('<li></li>').attr('data-step', step[0]).text(step[1]));
            });
            $el.append($('<div class="mg-ai-bulk-progress__head"></div>')
                .append($('<strong class="mg-ai-bulk-progress__title"></strong>'))
                .append($steps)
                .append($('<span class="mg-ai-bulk-progress__pct"></span>')))
                .append($('<div class="mg-ai-bulk-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"><span></span></div>'))
                .append($('<p class="mg-ai-bulk-progress__text" aria-live="polite"></p>'));
        }
        return $el;
    }

    function fillStrip($el, m) {
        strip($el).toggleClass('is-complete', m.complete);
        $el.find('.mg-ai-bulk-progress__title').text(m.title);
        $el.find('.mg-ai-bulk-progress__pct').text(m.percent + '%');
        $el.find('.mg-ai-bulk-progress__bar').attr('aria-valuenow', m.percent).find('span').css('width', m.percent + '%');
        $el.find('.mg-ai-bulk-progress__text').text(m.text);
        $el.find('.mg-ai-bulk-progress__steps li').each(function () {
            const state = m.steps[$(this).attr('data-step')];
            $(this).toggleClass('is-active', state === 'active').toggleClass('is-done', state === 'done');
        });
    }

    // In the window, and floating on the page while the window is closed;
    // the tab title shows it too, for when another tab is in front.
    function progress() {
        const m = progressModel();
        const $inner = $('#mg-ai-bulk-progress');
        const $float = $('#mg-ai-bulk-progress-float');
        if (!m) {
            $inner.prop('hidden', true);
            $float.prop('hidden', true);
            document.title = baseTitle;
            return;
        }
        fillStrip($inner.prop('hidden', false), m);
        fillStrip($float, m);
        $float.prop('hidden', bulk.dismissed);
        $float.find('.mg-ai-bulk-progress__dismiss').prop('hidden', !m.complete);
        document.title = bulk.dismissed ? baseTitle : (m.complete ? '✓ Kész – ' : '[' + m.percent + '%] ') + baseTitle;
    }

    // "Végleges: 3× AI felskálázás · 255 színes paletta · 2352×2544 px – " once the file is finished.
    function finishLabel(c) {
        const how = { ai2x: '2× AI felskálázás', ai3x: '3× AI felskálázás', ai4x: '4× AI felskálázás', lanczos2x: '2× sima nagyítás', lanczos3x: '3× sima nagyítás', lanczos4x: '4× sima nagyítás', native: 'eredeti felbontás' }[c.upscale] || '';
        if (!how) {
            return '';
        }
        const size = c.width && c.height ? c.width + '×' + c.height + ' px' : '';
        return 'Végleges: ' + [how, c.palette ? c.palette + ' színes paletta' : '', size].filter(Boolean).join(' · ') + ' – ';
    }

    // item.note overrides the status text until the next status change clears it.
    function render(item) {
        const $card = item.$card;
        const note = item.note;
        const label = item.status === 'done' && item.approved ? STATUS.approved : STATUS[item.status] || item.status;
        $card.toggleClass('is-approved', item.approved && item.status === 'done')
            .toggleClass('is-error', item.status === 'error')
            .toggleClass('is-finished', item.status === 'finished');
        $card.find('.mg-ai-bulk__status').text(note || (item.status === 'error' && item.error ? label + ': ' + item.error : label));
        const $after = $card.find('.mg-ai-bulk__after').empty();
        if (item.candidate && item.candidate.full) {
            $after.append($('<a target="_blank" rel="noopener"></a>').attr('href', item.candidate.full)
                .append($('<img alt="Új minta" />').attr('src', item.candidate.full)));
        } else {
            $after.append($('<span class="mg-ai-bulk__placeholder"></span>').text(item.status === 'running' ? 'Készül…' : '—'));
        }
        $card.find('.mg-ai-bulk__meta').text(item.candidate ? finishLabel(item.candidate) + (item.candidate.new_style || '') : '');
        const locked = bulk.finalizing || item.status === 'finished' || item.status === 'finalizing';
        $card.find('.mg-ai-bulk-approve')
            .prop('disabled', locked || item.status !== 'done')
            .text(item.approved && item.status === 'done' ? 'Jóváhagyás visszavonása' : 'Jóváhagyom');
        $card.find('.mg-ai-bulk-regen')
            .prop('disabled', locked || ['done', 'error', 'skipped'].indexOf(item.status) === -1)
            .text(item.status === 'skipped' ? 'Generálás' : 'Újragenerálás');
        $card.find('.mg-ai-bulk-remove').prop('disabled', locked || item.status === 'running');
        const canEdit = !locked && item.candidate && item.candidate.prompt && ['done', 'error'].indexOf(item.status) !== -1;
        $card.find('.mg-ai-bulk-edit').prop('disabled', !canEdit);
        if (!canEdit) {
            $card.find('.mg-ai-bulk__editor').prop('hidden', true);
        }
        summary();
    }

    function card(item) {
        const $card = $('<div class="mg-ai-bulk__item"></div>').attr('data-product-id', item.id);
        $card.append($('<div class="mg-ai-bulk__head"></div>')
            .append($('<strong></strong>').text(item.title || ('#' + item.id)))
            .append(' ')
            .append($('<span class="mg-ai-bulk__status"></span>')));
        const $pair = $('<div class="mg-ai-bulk__pair"></div>');
        $pair.append($('<figure></figure>').append($('<figcaption>Eredeti</figcaption>'))
            .append($('<div class="mg-ai-bulk__before"><span class="mg-ai-bulk__placeholder">Betöltés…</span></div>')));
        $pair.append($('<figure></figure>').append($('<figcaption>Új</figcaption>'))
            .append($('<div class="mg-ai-bulk__after"></div>')));
        $card.append($pair);
        $card.append($('<p class="description mg-ai-bulk__meta"></p>'));
        $card.append($('<p class="mg-ai-bulk__actions"></p>')
            .append($('<button type="button" class="button button-primary mg-ai-bulk-approve">Jóváhagyom</button>'))
            .append(' ')
            .append($('<button type="button" class="button mg-ai-bulk-regen">Újragenerálás</button>'))
            .append(' ')
            .append($('<button type="button" class="button mg-ai-bulk-edit">Prompt javítása</button>'))
            .append(' ')
            .append($('<button type="button" class="button button-link-delete mg-ai-bulk-remove">Törlés</button>')));
        // Fix a typo (e.g. in the exact text line) and regenerate with this prompt only.
        $card.append($('<div class="mg-ai-bulk__editor" hidden></div>')
            .append($('<p class="description"></p>').text('Javítsd ki a hibát a promptban (az elírt szöveg a „The design must contain exactly this text” sorban van), majd generáld újra. Az elemzés ilyenkor kimarad.'))
            .append($('<textarea class="large-text code mg-ai-bulk__prompt" rows="8"></textarea>'))
            .append($('<p></p>')
                .append($('<button type="button" class="button button-primary mg-ai-bulk-edit-run">Újragenerálás ezzel a prompttal</button>'))
                .append(' ')
                .append($('<button type="button" class="button mg-ai-bulk-edit-cancel">Mégse</button>'))));
        item.$card = $card;
        return $card;
    }

    function find(id) {
        return bulk.items.find(function (item) { return item.id === String(id) && item.status !== 'removed'; });
    }

    // Original design previews, three requests at a time.
    function loadBefore(queue) {
        const next = queue.shift();
        if (!next) {
            return;
        }
        post('mg_ai_redesign_info', { product_id: next.id }).done(function (resp) {
            const $before = next.$card.find('.mg-ai-bulk__before').empty();
            const d = resp && resp.success ? resp.data : null;
            if (d && d.design_preview) {
                $before.append($('<img alt="Eredeti minta" />').attr('src', d.design_preview));
            } else {
                $before.append($('<span class="mg-ai-bulk__placeholder"></span>').text((d && d.design_error) || message(resp) || 'Nincs előnézet'));
            }
        }).fail(function () {
            next.$card.find('.mg-ai-bulk__before').empty().append($('<span class="mg-ai-bulk__placeholder">Nincs előnézet</span>'));
        }).always(function () {
            loadBefore(queue);
        });
    }

    function pump() {
        let running = active().filter(function (item) { return item.status === 'running'; }).length;
        active().forEach(function (item) {
            if (running < MAX_PARALLEL && item.status === 'queued') {
                running++;
                generate(item);
            }
        });
        summary();
    }

    function generate(item) {
        const previous = item.candidate;
        // An edited prompt is used for this one run, without the analysis.
        const customPrompt = item.customPrompt || '';
        item.customPrompt = '';
        item.status = 'running';
        item.approved = false;
        item.error = '';
        item.note = '';
        render(item);
        post('mg_ai_redesign_generate', $.extend({}, bulk.opts, { product_id: item.id, design_text: '', custom_prompt: customPrompt })).done(function (resp) {
            const d = resp && resp.data;
            if (d && d.status === 'running') {
                poll(item, previous, Date.now());
                return;
            }
            finish(item, resp && resp.success ? d : { status: 'error', message: message(resp) }, previous);
        }).fail(function (xhr) {
            if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                finish(item, { status: 'error', message: xhr.responseJSON.data.message || ('HTTP ' + xhr.status) }, previous);
            } else {
                // Proxy or timeout: the server keeps working, read its state.
                poll(item, previous, Date.now());
            }
        });
    }

    function poll(item, previous, started) {
        setTimeout(function () {
            if (item.status !== 'running') {
                return;
            }
            post('mg_ai_redesign_status', { product_id: item.id }).done(function (resp) {
                const d = resp && resp.data;
                if (d && d.status === 'running') {
                    const elapsed = d.started ? Math.max(0, Math.round(Date.now() / 1000 - d.started)) : 0;
                    item.note = (STAGES[d.stage] || STATUS.running) + (elapsed ? ' (' + elapsed + ' mp)' : '');
                    render(item);
                    if (Date.now() - started > POLL_LIMIT_MS) {
                        finish(item, { status: 'error', message: 'A generálás nem készült el időben.' }, previous);
                    } else {
                        poll(item, previous, started);
                    }
                    return;
                }
                finish(item, d && d.status === 'done' ? d : { status: 'error', message: (d && d.message) || 'a szerver nem talál futó generálást ehhez a mintához. Generáld újra.' }, previous);
            }).fail(function () {
                if (Date.now() - started > POLL_LIMIT_MS) {
                    finish(item, { status: 'error', message: 'Az állapot nem kérdezhető le.' }, previous);
                } else {
                    poll(item, previous, started);
                }
            });
        }, POLL_MS);
    }

    function finish(item, state, previous) {
        if (item.status !== 'running') {
            return;
        }
        item.note = '';
        if (state && state.status === 'done' && state.candidate) {
            item.candidate = state.candidate;
            item.status = 'done';
            // A regeneration replaces the earlier candidate of this run.
            if (previous && String(previous.attachment_id) !== String(state.candidate.attachment_id)) {
                post('mg_ai_redesign_discard', { product_id: item.id, attachment_id: previous.attachment_id });
            }
        } else {
            item.candidate = previous || null;
            item.status = previous ? 'done' : 'error';
            item.error = (state && state.message) || 'ismeretlen hiba';
            if (previous) {
                item.note = 'Az újragenerálás nem sikerült (' + item.error + '), az előző változat maradt.';
            }
        }
        render(item);
        pump();
    }

    function upload(item, blob) {
        const form = new FormData();
        form.append('action', 'mg_ai_redesign_upscaled');
        form.append('nonce', cfg().nonce);
        form.append('product_id', item.id);
        form.append('attachment_id', item.candidate.attachment_id);
        form.append('upscaled', blob, 'upscaled.png');
        return $.ajax({ url: cfg().ajax_url, method: 'POST', data: form, processData: false, contentType: false, dataType: 'json' });
    }

    async function finalize() {
        const queue = active().filter(function (item) { return item.approved && item.status === 'done' && item.candidate; });
        if (!queue.length || !window.confirm(queue.length + ' jóváhagyott minta kerül a termékére: felskálázás' + ((window.MG_AI_UPSCALE || {}).palette ? ', palettás PNG' : '') + ', majd az összes mockupjuk újragenerálása. A termékek többi AI jelöltje törlődik. Folytatod?')) {
            return;
        }
        bulk.finalizing = true;
        bulk.dismissed = false;
        bulk.finTotal = queue.length;
        bulk.finDone = 0;
        queue.forEach(function (item) { item.status = 'finalizing'; item.fin = 0; item.note = 'Véglegesítésre vár'; render(item); });
        for (const item of queue) {
            let note = '';
            bulk.finItem = item;
            try {
                if (item.candidate.upscale === 'none' && window.MGAIUpscale) {
                    // Edge cleanup, binary alpha and crop on the server first (Design-flow input).
                    item.note = 'A szélek előkészítése a felskálázáshoz…';
                    item.fin = 0.05;
                    render(item);
                    const prepared = await post('mg_ai_redesign_prepare', { product_id: item.id, attachment_id: item.candidate.attachment_id });
                    if (!prepared || !prepared.success || !prepared.data) {
                        throw new Error(message(prepared) || 'az előkészítés nem sikerült');
                    }
                    item.candidate = prepared.data;
                    // 3× (Real-ESRGAN: 4×) or 2× by its size; a large one (above 2560 px) needs none.
                    const factor = item.candidate.upscale_factor || 0;
                    if (factor >= 2) {
                        item.note = 'AI felskálázás indul…';
                        item.fin = 0.1;
                        render(item);
                        const blob = await window.MGAIUpscale.upscale(item.candidate.full, function (p) {
                            item.note = 'AI felskálázás ' + factor + '×: ' + Math.round(p * 100) + '% – ne zárd be és ne frissítsd a lapot.';
                            item.fin = 0.1 + 0.7 * p;
                            render(item);
                        }, factor);
                        item.note = 'A felskálázott PNG feltöltése…';
                        item.fin = 0.85;
                        render(item);
                        const resp = await upload(item, blob);
                        if (resp && resp.success && resp.data) {
                            item.candidate = resp.data; // the upscaled palette PNG, with a new version in its URL
                            render(item);
                        } else {
                            note = ' (AI felskálázás: ' + (message(resp) || 'hiba') + ' – sima nagyítás lett)';
                        }
                    }
                }
            } catch (err) {
                note = ' (AI felskálázás: ' + ((err && err.message) || (err && err.status === 413 ? 'a fájl túl nagy (HTTP 413)' : 'hiba')) + ' – sima nagyítás lett)';
            }
            try {
                item.note = 'Palettázás, jóváhagyás, mockupok sorba állítása…';
                item.fin = 0.95;
                render(item);
                const resp = await post('mg_ai_redesign_apply', { product_id: item.id, attachment_id: item.candidate.attachment_id });
                if (resp && resp.success) {
                    if (resp.data && resp.data.candidate) {
                        item.candidate = resp.data.candidate; // the finished file on the product
                    }
                    item.status = 'finished';
                    item.note = STATUS.finished + note;
                } else {
                    item.status = 'error';
                    item.note = '';
                    item.error = message(resp) || 'a jóváhagyás nem sikerült';
                }
            } catch (err) {
                item.status = 'error';
                item.note = '';
                item.error = 'a jóváhagyás nem sikerült';
            }
            bulk.finDone++;
            render(item);
        }
        bulk.finalizing = false;
        bulk.finItem = null;
        active().forEach(render);
    }

    function startRun() {
        bulk.opts = {
            prompt_mode: $('#mg-ai-bulk-mode').val() || 'brief',
            intensity: $('#mg-ai-bulk-intensity').val() || 'strong',
            analyzer: $('#mg-ai-bulk-analyzer').val() || '',
            quality: $('#mg-ai-bulk-quality').val() || 'medium',
            resolution: $('#mg-ai-bulk-resolution').val() || 'economy',
            style_hint: $('#mg-ai-bulk-hint').val() || '',
        };
        const $list = $('#mg-ai-bulk-list').empty();
        bulk.items.forEach(function (item) {
            item.status = 'queued';
            $list.append(card(item));
            render(item);
        });
        $('#mg-ai-bulk-setup').prop('hidden', true);
        $('#mg-ai-bulk-run').prop('hidden', false);
        bulk.dismissed = false;
        const queue = bulk.items.slice();
        for (let i = 0; i < 3; i++) {
            loadBefore(queue);
        }
        pump();
    }

    $(function () {
        syncSubcategories();
        refreshSelection();
        if ($('#mg-ai-redesign-bulk-modal').length) {
            const $float = strip($('<div class="mg-ai-bulk-progress is-float" id="mg-ai-bulk-progress-float" hidden></div>'));
            $float.find('.mg-ai-bulk-progress__head')
                .append($('<button type="button" class="button button-small" data-mg-open-modal="ai-redesign-bulk">Megnyitás</button>'))
                .append($('<button type="button" class="mg-ai-bulk-progress__dismiss" aria-label="Elrejtés" hidden>&times;</button>'));
            $('body').append($float);
        }
        $(document).on('click', '.mg-ai-bulk-progress__dismiss', function () {
            bulk.dismissed = true;
            progress();
        });

        $(document).on('change', '#mg-dash-cat-main', syncSubcategories);
        $(document).on('change', '.mg-dash-check', refreshSelection);
        $(document).on('change', '#mg-dash-check-page', function () {
            $('.mg-dash-check').prop('checked', this.checked);
            refreshSelection();
        });
        $(document).on('click', '#mg-dash-select-all', function () {
            $('.mg-dash-check').prop('checked', true);
            refreshSelection();
        });
        $(document).on('click', '#mg-dash-select-none', function () {
            $('.mg-dash-check').prop('checked', false);
            refreshSelection();
        });

        function setup() {
            bulk.items = selectedRows().map(function (row) {
                return { id: row.id, title: row.title, status: 'new', candidate: null, approved: false, error: '' };
            });
            $('#mg-ai-bulk-count').text(bulk.items.length);
            $('#mg-ai-bulk-setup').prop('hidden', false);
            $('#mg-ai-bulk-run').prop('hidden', true);
            $('#mg-ai-bulk-list').empty();
        }

        // Opening keeps an unfinished run (cards waiting for a decision);
        // otherwise it starts over with the current selection.
        $(document).on('click', '#mg-dash-bulk-redesign', function () {
            if (bulk.items.length && (busy() || active().some(function (i) { return i.status === 'done'; }))) {
                return;
            }
            setup();
        });
        $(document).on('click', '#mg-ai-bulk-new', function () {
            if (busy()) {
                return;
            }
            if (active().some(function (i) { return i.status === 'done'; })
                && !window.confirm('Új futást indítasz a mostani kijelöléssel. A még el nem döntött új minták a termékek AI újratervezés ablakában megmaradnak. Folytatod?')) {
                return;
            }
            setup();
        });
        $(document).on('change', '#mg-ai-bulk-mode', function () {
            $('#mg-ai-bulk-intensity').prop('disabled', $(this).val() === 'describe');
        });
        $(document).on('click', '#mg-ai-bulk-start', function () {
            if (bulk.items.length) {
                startRun();
            }
        });
        $(document).on('click', '.mg-ai-bulk-approve', function () {
            const item = find($(this).closest('.mg-ai-bulk__item').attr('data-product-id'));
            if (item && item.status === 'done') {
                item.approved = !item.approved;
                render(item);
            }
        });
        $(document).on('click', '.mg-ai-bulk-regen', function () {
            const item = find($(this).closest('.mg-ai-bulk__item').attr('data-product-id'));
            if (item && !bulk.finalizing && ['done', 'error', 'skipped'].indexOf(item.status) !== -1) {
                item.status = 'queued';
                item.approved = false;
                render(item);
                pump();
            }
        });
        $(document).on('click', '.mg-ai-bulk-edit', function () {
            const item = find($(this).closest('.mg-ai-bulk__item').attr('data-product-id'));
            if (!item || !item.candidate || !item.candidate.prompt) {
                return;
            }
            const $editor = item.$card.find('.mg-ai-bulk__editor');
            if ($editor.prop('hidden')) {
                $editor.find('.mg-ai-bulk__prompt').val(item.candidate.prompt);
            }
            $editor.prop('hidden', !$editor.prop('hidden'));
        });
        $(document).on('click', '.mg-ai-bulk-edit-cancel', function () {
            $(this).closest('.mg-ai-bulk__editor').prop('hidden', true);
        });
        $(document).on('click', '.mg-ai-bulk-edit-run', function () {
            const item = find($(this).closest('.mg-ai-bulk__item').attr('data-product-id'));
            const prompt = String(item ? item.$card.find('.mg-ai-bulk__prompt').val() || '' : '').trim();
            if (!item || bulk.finalizing || !prompt || ['done', 'error'].indexOf(item.status) === -1) {
                return;
            }
            item.customPrompt = prompt;
            item.status = 'queued';
            item.approved = false;
            item.$card.find('.mg-ai-bulk__editor').prop('hidden', true);
            render(item);
            pump();
        });
        $(document).on('click', '.mg-ai-bulk-remove', function () {
            const item = find($(this).closest('.mg-ai-bulk__item').attr('data-product-id'));
            if (!item || item.status === 'running' || bulk.finalizing) {
                return;
            }
            if (!window.confirm('Kiveszed ezt a mintát a tömeges folyamatból? Az új változata a médiatárból is törlődik, az eredeti minta marad.')) {
                return;
            }
            if (item.candidate) {
                post('mg_ai_redesign_discard', { product_id: item.id, attachment_id: item.candidate.attachment_id });
            }
            item.status = 'removed';
            // After this click: a target detached mid-click would read as a
            // click outside the dialog and close the modal.
            setTimeout(function () { item.$card.remove(); }, 0);
            summary();
        });
        $(document).on('click', '#mg-ai-bulk-approve-all', function () {
            active().forEach(function (item) {
                if (item.status === 'done') {
                    item.approved = true;
                    render(item);
                }
            });
        });
        $(document).on('click', '#mg-ai-bulk-stop', function () {
            active().forEach(function (item) {
                if (item.status === 'queued') {
                    item.status = 'skipped';
                    render(item);
                }
            });
        });
        $(document).on('click', '#mg-ai-bulk-finalize', function () {
            finalize();
        });

        window.addEventListener('beforeunload', function (e) {
            if (busy()) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    });
})(jQuery);
