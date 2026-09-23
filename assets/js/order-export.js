(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var cfg = window.MG_ORDER_EXPORT;
        if (!cfg || !cfg.ajax_url || !cfg.nonce || !cfg.order_ids || !cfg.order_ids.length) {
            return;
        }

        var i18n = cfg.i18n || {};

        var overlay = document.createElement('div');
        overlay.className = 'mg-order-export-overlay';
        overlay.innerHTML =
            '<div class="mg-order-export-modal" role="dialog" aria-modal="true" aria-labelledby="mg-export-title">' +
                '<h2 class="mg-order-export-title" id="mg-export-title" tabindex="-1"></h2>' +
                '<div class="mg-order-export-choice">' +
                    '<p class="mg-order-export-choice-text"></p>' +
                    '<div class="mg-order-export-choice-actions">' +
                        '<button type="button" class="button button-primary mg-order-export-choice-strip"></button>' +
                        '<button type="button" class="button mg-order-export-choice-normal"></button>' +
                    '</div>' +
                '</div>' +
                '<section class="mg-order-export-review" hidden>' +
                    '<p class="mg-order-export-review-counter" aria-live="polite"></p>' +
                    '<h3 class="mg-order-export-item-title"></h3>' +
                    '<div class="mg-order-export-review-grid">' +
                        '<div><div class="mg-order-export-image-wrap"><img class="mg-order-export-image" alt="Ellenőrizendő alapminta" /></div>' +
                            '<p class="mg-order-export-image-status" role="status"></p>' +
                            '<a class="mg-order-export-image-link" target="_blank" rel="noopener">Teljes méret megnyitása</a> · ' +
                            '<button type="button" class="button-link mg-order-export-image-bg">Sötét háttér</button> · ' +
                            '<button type="button" class="button-link mg-order-export-image-retry">Kép újratöltése</button></div>' +
                        '<div><h4>A vásárló által megadott értékek</h4><dl class="mg-order-export-fields"></dl>' +
                            '<p>Ha minden érték egyezik a mintával, használd az alapmintát. Ha bármelyik eltér, kérj AI-módosítást.</p>' +
                            '<div class="mg-order-export-decisions">' +
                                '<button type="button" class="button mg-order-export-use-original">Alapminta jó – nem kell AI</button>' +
                                '<button type="button" class="button mg-order-export-use-ai">AI-módosítás kell</button>' +
                            '</div><p class="mg-order-export-decision-status" aria-live="polite"></p></div>' +
                    '</div><div class="mg-order-export-review-nav">' +
                        '<button type="button" class="button mg-order-export-prev">Előző tétel</button>' +
                        '<button type="button" class="button button-primary mg-order-export-next">Következő tétel</button>' +
                    '</div>' +
                '</section>' +
                '<section class="mg-order-export-summary" hidden><h3>Ellenőrzés kész</h3>' +
                    '<p class="mg-order-export-summary-counts"></p><ul class="mg-order-export-summary-list"></ul>' +
                    '<button type="button" class="button button-primary mg-order-export-confirm">Export indítása</button>' +
                '</section>' +
                '<div class="mg-order-export-progress-bar" hidden><span></span></div>' +
                '<p class="mg-order-export-status" hidden></p>' +
                '<p class="mg-order-export-detail" hidden></p>' +
                '<p class="mg-order-export-notice" role="status" hidden></p>' +
                '<p class="mg-order-export-error" role="alert" hidden></p>' +
                '<div class="mg-order-export-actions">' +
                    '<a class="button button-primary mg-order-export-download" hidden></a>' +
                    '<button type="button" class="button button-primary mg-order-export-retry" hidden>Export folytatása</button>' +
                    '<button type="button" class="button mg-order-export-close"></button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);

        var titleEl       = overlay.querySelector('.mg-order-export-title');
        var choiceEl       = overlay.querySelector('.mg-order-export-choice');
        var choiceTextEl   = overlay.querySelector('.mg-order-export-choice-text');
        var choiceStripEl  = overlay.querySelector('.mg-order-export-choice-strip');
        var choiceNormalEl = overlay.querySelector('.mg-order-export-choice-normal');
        var barWrapEl     = overlay.querySelector('.mg-order-export-progress-bar');
        var barEl         = overlay.querySelector('.mg-order-export-progress-bar span');
        var statusEl      = overlay.querySelector('.mg-order-export-status');
        var detailEl      = overlay.querySelector('.mg-order-export-detail');
        var noticeEl      = overlay.querySelector('.mg-order-export-notice');
        var errorEl       = overlay.querySelector('.mg-order-export-error');
        var downloadEl    = overlay.querySelector('.mg-order-export-download');
        var retryEl       = overlay.querySelector('.mg-order-export-retry');
        var closeEl       = overlay.querySelector('.mg-order-export-close');
        var modalEl       = overlay.querySelector('.mg-order-export-modal');
        var reviewEl      = overlay.querySelector('.mg-order-export-review');
        var summaryEl     = overlay.querySelector('.mg-order-export-summary');
        var imageEl       = overlay.querySelector('.mg-order-export-image');
        var imageWrapEl   = overlay.querySelector('.mg-order-export-image-wrap');
        var imageStatusEl = overlay.querySelector('.mg-order-export-image-status');
        var imageLinkEl   = overlay.querySelector('.mg-order-export-image-link');
        var fieldsEl      = overlay.querySelector('.mg-order-export-fields');
        var originalEl    = overlay.querySelector('.mg-order-export-use-original');
        var aiEl          = overlay.querySelector('.mg-order-export-use-ai');
        var prevEl        = overlay.querySelector('.mg-order-export-prev');
        var nextEl        = overlay.querySelector('.mg-order-export-next');
        var confirmEl     = overlay.querySelector('.mg-order-export-confirm');
        var previousFocus = document.activeElement;

        titleEl.textContent       = i18n.title || 'Export';
        choiceTextEl.textContent  = i18n.choice_question || '';
        choiceStripEl.textContent = i18n.choice_strip || '';
        choiceNormalEl.textContent = i18n.choice_normal || '';
        statusEl.textContent      = i18n.processing || '...';
        closeEl.textContent       = i18n.close || 'Close';

        closeEl.addEventListener('click', function () {
            stopped = true;
            stopPolling();
            overlay.remove();
            if (previousFocus && previousFocus.focus) previousFocus.focus();
        });
        var stopped = false;
        var started = false;
        var review = null;
        var decisions = {};
        var index = 0;
        var imageReady = false;
        var submitting = false;
        var darkBackground = false;
        var activeJobId = '';
        var retrying = false;
        var workers = {};
        var pollVersion = 0;
        var pollTimer = null;
        var clockTimer = null;
        var lastReplyAt = 0;
        var lastProgressAt = 0;
        var progressSignature = '';
        var lastProgress = null;
        var activeWorkerKey = '';
        titleEl.focus();
        overlay.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeEl.click();
            if (event.key !== 'Tab') return;
            var focusable = Array.prototype.filter.call(overlay.querySelectorAll('button, a[href], [tabindex="0"]'), function (el) { return !el.disabled && el.offsetParent !== null; });
            var first = focusable[0], last = focusable[focusable.length - 1];
            if (event.shiftKey && (document.activeElement === first || document.activeElement === titleEl)) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        });

        var showError = function (message) {
            if (stopped) return;
            errorEl.textContent = message || i18n.error || 'Error';
            errorEl.hidden = false;
        };

        // A step converts up to a few large print PNGs; slow hosts need more than
        // the 30 s allowed for short requests (PHP limit for a step is 120 s).
        var STEP_TIMEOUT = 110000;
        var postJson = function (body, timeoutMs) {
            var controller = typeof AbortController === 'function' ? new AbortController() : null;
            var limit = timeoutMs || 30000;
            var timeout;
            // Reject independently of abort(): a stuck connection must not keep
            // this promise (and the export controls) pending indefinitely.
            var deadline = new Promise(function (resolve, reject) {
                timeout = window.setTimeout(function () {
                    reject(new Error('A szerver ' + Math.round(limit / 1000) + ' másodpercen belül nem válaszolt.'));
                    if (controller) controller.abort();
                }, limit);
            });
            var request = Promise.resolve().then(function () {
                return fetch(cfg.ajax_url, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body, signal: controller ? controller.signal : undefined,
                });
            }).then(function (response) {
                var status = response.status || 200;
                return response.json().catch(function () {
                    throw new Error('A szerver nem olvasható exportválaszt adott (HTTP ' + status + '). Szerverhiba vagy lejárt bejelentkezés is okozhatja.');
                }).then(function (payload) {
                    if (payload === -1) throw new Error('Lejárt a biztonsági token. Frissítsd az adminoldalt.');
                    if (payload === 0) throw new Error('A szerver nem ismeri ezt az exportműveletet. Ellenőrizd a bővítmény frissítését, majd töltsd újra az adminoldalt.');
                    if (!payload || typeof payload.success !== 'boolean') throw new Error('Hiányos exportválasz érkezett a szervertől (HTTP ' + status + ').');
                    if (!payload.success && (!payload.data || typeof payload.data.message !== 'string' || !payload.data.message.trim())) {
                        payload.data = { message: 'A szerver elutasította az exportkérést (HTTP ' + status + ').' };
                    }
                    return payload;
                });
            }, function () {
                throw new Error('Megszakadt a kapcsolat a webshoppal. Ellenőrizd az internetkapcsolatot, majd folytasd az exportot.');
            });
            return Promise.race([request, deadline]).finally(function () { window.clearTimeout(timeout); });
        };

        var stopPolling = function () {
            pollVersion++;
            if (pollTimer !== null) window.clearTimeout(pollTimer);
            if (clockTimer !== null) window.clearInterval(clockTimer);
            pollTimer = clockTimer = null;
        };

        var formatTime = function (seconds) {
            seconds = Math.max(0, Math.floor(seconds));
            return Math.floor(seconds / 60) + ':' + ('0' + (seconds % 60)).slice(-2);
        };

        var renderTiming = function () {
            var sinceReply = Math.max(0, Math.floor((Date.now() - lastReplyAt) / 1000));
            var text = 'Utolsó állapotválasz: ' + sinceReply + ' mp.';
            if (lastProgress && typeof lastProgress.ai_elapsed === 'number') {
                text = 'Ennél a képnél eltelt idő: ' + formatTime(lastProgress.ai_elapsed + sinceReply) + ' · ' + text;
                if (lastProgress.ai_stage === 'api') text += ' OpenAI-kérés időkorlátja: ' + formatTime(lastProgress.ai_api_timeout || 180) + '.';
            }
            detailEl.textContent = text;
            detailEl.hidden = false;
        };

        var updateProgress = function (data) {
            if (!data || typeof data.completed !== 'number' || typeof data.total !== 'number') throw new Error('Hiányzik az export állapota a szerver válaszából.');
            lastReplyAt = Date.now();
            lastProgress = data;
            var nextWorkerKey = data.ai_key || data.ai_worker_key || '';
            if (activeWorkerKey && nextWorkerKey && activeWorkerKey !== nextWorkerKey) noticeEl.hidden = true;
            activeWorkerKey = nextWorkerKey;
            var signature = [data.completed, activeWorkerKey, data.ai_stage, data.message].join('|');
            if (signature !== progressSignature) { progressSignature = signature; lastProgressAt = lastReplyAt; }
            var percent = parseInt(data.percent || 0, 10);
            barEl.style.width = percent + '%';
            statusEl.textContent = (data.message || i18n.processing || '') + ' ' + data.completed + ' / ' + data.total + ' (' + percent + '%)';
            renderTiming();
        };

        var pauseExport = function (message) {
            if (stopped) return;
            stopPolling();
            if (lastReplyAt) {
                renderTiming();
                if (lastProgress && lastProgress.message) detailEl.textContent = 'Utolsó ismert lépés: ' + lastProgress.message + ' ' + detailEl.textContent;
            }
            statusEl.textContent = 'Az export megállt. A hiba javítása után folytathatod; a még elérhető kész AI-képeket újra felhasználjuk. A hiányzó képek újrapróbálása új API-hívást indíthat.';
            retryEl.hidden = !activeJobId;
            retryEl.disabled = false;
            showError(message);
        };

        var dispatchWorker = function (jobId, key) {
            if (!key || workers[key] || stopped) return;
            var worker = workers[key] = { error: '' };
            var version = pollVersion;
            // Polling stays independent of this long request. A proxy timeout does
            // not trigger another paid call; the shared server lock owns execution.
            postJson('action=mg_design_export_run_ai&nonce=' + encodeURIComponent(cfg.nonce) + '&job_id=' + encodeURIComponent(jobId) + '&worker_key=' + encodeURIComponent(key), 260000).then(function (payload) {
                if (!payload.success) throw new Error(payload.data && payload.data.message || 'A háttérfeladat indítását a szerver elutasította.');
            }).catch(function (error) {
                if (stopped || version !== pollVersion) return;
                worker.error = error.message;
                if (activeWorkerKey !== key) return;
                noticeEl.textContent = 'Háttérkérés: ' + error.message + ' Az állapotellenőrzés folytatódik.';
                noticeEl.hidden = false;
            });
        };

        var step = function (jobId) {
            if (stopped) { return; }
            var version = pollVersion;
            var body = 'action=mg_design_export_step&nonce=' + encodeURIComponent(cfg.nonce) + '&job_id=' + encodeURIComponent(jobId);
            postJson(body, STEP_TIMEOUT).then(function (payload) {
                if (stopped || version !== pollVersion) return;
                if (!payload || !payload.success) {
                    pauseExport(payload && payload.data && payload.data.message);
                    return;
                }
                updateProgress(payload.data);
                if (payload.data.done) {
                    stopPolling();
                    detailEl.hidden = noticeEl.hidden = true;
                    statusEl.textContent = i18n.done || 'Done';
                    downloadEl.textContent = i18n.download || 'Download';
                    downloadEl.href = cfg.ajax_url + '?action=mg_design_export_download&job_id=' + encodeURIComponent(jobId) + '&nonce=' + encodeURIComponent(cfg.nonce);
                    downloadEl.hidden = false;
                    return;
                }
                var worker = workers[activeWorkerKey];
                if (worker && worker.error && payload.data.ai_status === 'queued') {
                    pauseExport('A generálás nem indult el. ' + worker.error);
                    return;
                }
                dispatchWorker(jobId, payload.data.ai_worker_key);
                pollTimer = window.setTimeout(function () { if (version === pollVersion) step(jobId); }, payload.data.waiting ? 2000 : 100);
            }).catch(function (error) {
                if (!stopped && version === pollVersion) pauseExport(error.message);
            });
        };

        var beginPolling = function (jobId) {
            stopPolling();
            lastReplyAt = lastProgressAt = Date.now();
            progressSignature = '';
            lastProgress = null;
            activeWorkerKey = '';
            workers = {};
            noticeEl.hidden = true;
            renderTiming();
            clockTimer = window.setInterval(function () {
                renderTiming();
                if (Date.now() - lastReplyAt > STEP_TIMEOUT + 10000) pauseExport('2 perce nem érkezett állapotfrissítés. A folyamat állapota bizonytalan; az Export folytatása gombbal ellenőrizheted.');
                else if (Date.now() - lastProgressAt > 600000) pauseExport('10 perce nem változott a feldolgozás lépése. A generálás elakadhatott; az Export folytatása gombbal ellenőrizheted.');
            }, 1000);
            step(jobId);
        };

        retryEl.addEventListener('click', function () {
            if (stopped || retrying || !activeJobId) return;
            retrying = true;
            retryEl.disabled = true;
            errorEl.hidden = true;
            postJson('action=mg_design_export_retry&nonce=' + encodeURIComponent(cfg.nonce) + '&job_id=' + encodeURIComponent(activeJobId)).then(function (payload) {
                if (stopped) return;
                if (!payload || !payload.success) {
                    pauseExport(payload && payload.data && payload.data.message);
                    return;
                }
                retryEl.hidden = true;
                statusEl.textContent = 'Export folytatása…';
                beginPolling(activeJobId);
            }).catch(function (error) { pauseExport(error.message); }).finally(function () { retrying = false; retryEl.disabled = false; });
        });

        var start = function () {
            if (stopped || submitting || !review || review.items.some(function (item) { return !decisions[item.key]; })) return;
            submitting = true;
            confirmEl.disabled = true;
            errorEl.hidden = true;
            var body = 'action=mg_design_export_start&nonce=' + encodeURIComponent(cfg.nonce) + '&review_id=' + encodeURIComponent(review.review_id) + '&decisions=' + encodeURIComponent(JSON.stringify(decisions));
            postJson(body).then(function (payload) {
                if (stopped) return;
                if (!payload || !payload.success) {
                    submitting = false;
                    confirmEl.disabled = false;
                    showError(payload && payload.data && payload.data.message);
                    return;
                }
                if (!payload.data || !payload.data.job_id) throw new Error('A szerver nem adott exportazonosítót.');
                summaryEl.hidden = true;
                barWrapEl.hidden = false;
                statusEl.hidden = false;
                activeJobId = payload.data.job_id;
                beginPolling(activeJobId);
            }).catch(function (error) {
                submitting = false;
                confirmEl.disabled = false;
                showError(error.message);
            });
        };

        var decisionLabel = function (decision) { return decision === 'original' ? 'Alapminta – AI nélkül' : 'AI-módosítás'; };
        var updateDecision = function () {
            var decision = decisions[review.items[index].key];
            originalEl.disabled = !imageReady;
            aiEl.disabled = !imageReady;
            originalEl.setAttribute('aria-pressed', String(decision === 'original'));
            aiEl.setAttribute('aria-pressed', String(decision === 'generate'));
            nextEl.disabled = !decision || !imageReady;
            overlay.querySelector('.mg-order-export-decision-status').textContent = decision ? 'Választás: ' + decisionLabel(decision) : 'Válassz a két lehetőség közül a továbblépéshez.';
        };
        var previewUrl = function () {
            return cfg.ajax_url + '?action=mg_design_export_preview&nonce=' + encodeURIComponent(cfg.nonce) + '&review_id=' + encodeURIComponent(review.review_id) + '&item_key=' + encodeURIComponent(review.items[index].key);
        };
        var loadImage = function () {
            imageReady = false;
            imageEl.hidden = true;
            imageStatusEl.textContent = 'Alapminta betöltése…';
            updateDecision();
            imageLinkEl.href = previewUrl();
            imageEl.src = previewUrl() + '&view=' + Date.now();
        };
        imageEl.addEventListener('load', function () {
            if (!review || stopped) return;
            imageReady = true;
            imageEl.hidden = false;
            imageStatusEl.textContent = 'Az exporthoz használt alapminta.';
            updateDecision();
        });
        imageEl.addEventListener('error', function () {
            if (!review || stopped) return;
            imageReady = false;
            imageStatusEl.textContent = 'A kép nem tölthető be. Próbáld újratölteni; lejárt vagy megváltozott minta esetén indíts új exportot.';
            updateDecision();
        });
        var showItem = function () {
            summaryEl.hidden = true;
            reviewEl.hidden = false;
            errorEl.hidden = true;
            var item = review.items[index];
            overlay.querySelector('.mg-order-export-review-counter').textContent = (index + 1) + ' / ' + review.items.length + ' egyedi tétel · Rendelés #' + item.order_id + ' · Tétel #' + item.item_id + ' · ' + item.quantity + ' db';
            overlay.querySelector('.mg-order-export-item-title').textContent = item.product_name;
            fieldsEl.textContent = '';
            item.fields.forEach(function (field) {
                var label = document.createElement('dt');
                var value = document.createElement('dd');
                label.textContent = field.label;
                value.textContent = field.value || 'Nincs megadva';
                fieldsEl.appendChild(label);
                fieldsEl.appendChild(value);
            });
            prevEl.disabled = index === 0;
            nextEl.textContent = index === review.items.length - 1 ? 'Összesítés' : 'Következő tétel';
            loadImage();
            titleEl.focus();
        };
        var showSummary = function () {
            reviewEl.hidden = true;
            summaryEl.hidden = false;
            var aiCount = review.items.filter(function (item) { return decisions[item.key] === 'generate'; }).length;
            overlay.querySelector('.mg-order-export-summary-counts').textContent = aiCount + ' tétel AI-módosítással · ' + (review.items.length - aiCount) + ' egyedi tétel alapmintával · Összesen ' + review.total + ' nyomat az exportban.';
            var list = overlay.querySelector('.mg-order-export-summary-list');
            list.textContent = '';
            review.items.forEach(function (item, position) {
                var row = document.createElement('li');
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'button-link';
                button.textContent = '#' + item.order_id + ' / #' + item.item_id + ' – ' + item.product_name + ': ' + decisionLabel(decisions[item.key]) + ' · Módosítás';
                button.addEventListener('click', function () { if (!submitting) { index = position; showItem(); } });
                row.appendChild(button);
                list.appendChild(row);
            });
            confirmEl.disabled = false;
            confirmEl.focus();
        };
        originalEl.addEventListener('click', function () { if (!imageReady) return; decisions[review.items[index].key] = 'original'; updateDecision(); });
        aiEl.addEventListener('click', function () { if (!imageReady) return; decisions[review.items[index].key] = 'generate'; updateDecision(); });
        prevEl.addEventListener('click', function () { if (index > 0) { index--; showItem(); } });
        nextEl.addEventListener('click', function () {
            if (!imageReady || !decisions[review.items[index].key]) return;
            if (index < review.items.length - 1) { index++; showItem(); } else { showSummary(); }
        });
        confirmEl.addEventListener('click', start);
        overlay.querySelector('.mg-order-export-image-retry').addEventListener('click', loadImage);
        overlay.querySelector('.mg-order-export-image-bg').addEventListener('click', function () {
            darkBackground = !darkBackground;
            imageWrapEl.classList.toggle('is-dark', darkBackground);
            this.textContent = darkBackground ? 'Világos háttér' : 'Sötét háttér';
        });

        var beginExport = function (stripBlack) {
            if (started || stopped) { return; }
            started = true;
            choiceEl.hidden = true;
            statusEl.hidden = false;
            statusEl.textContent = 'Ellenőrizendő minták betöltése…';
            errorEl.hidden = true;
            var body = 'action=mg_design_export_review&nonce=' + encodeURIComponent(cfg.nonce) + '&strip_black=' + (stripBlack ? '1' : '0');
            cfg.order_ids.forEach(function (id) { body += '&order_ids[]=' + encodeURIComponent(id); });
            postJson(body).then(function (payload) {
                if (stopped) return;
                if (!payload || !payload.success) throw new Error(payload && payload.data && payload.data.message || i18n.error);
                review = payload.data;
                statusEl.hidden = true;
                if (!review.items.length) { showSummary(); start(); return; }
                modalEl.classList.add('has-review');
                titleEl.textContent = 'Egyedi nyomatok ellenőrzése';
                showItem();
            }).catch(function (error) {
                if (stopped) return;
                started = false;
                choiceEl.hidden = false;
                statusEl.hidden = true;
                showError(error.message);
            });
        };

        choiceStripEl.addEventListener('click', function () { beginExport(true); });
        choiceNormalEl.addEventListener('click', function () { beginExport(false); });
    });
})();
