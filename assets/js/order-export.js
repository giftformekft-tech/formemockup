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
            '<div class="mg-order-export-modal" role="dialog" aria-modal="true">' +
                '<h2 class="mg-order-export-title"></h2>' +
                '<div class="mg-order-export-choice">' +
                    '<p class="mg-order-export-choice-text"></p>' +
                    '<div class="mg-order-export-choice-actions">' +
                        '<button type="button" class="button button-primary mg-order-export-choice-strip"></button>' +
                        '<button type="button" class="button mg-order-export-choice-normal"></button>' +
                    '</div>' +
                '</div>' +
                '<div class="mg-order-export-progress-bar" hidden><span></span></div>' +
                '<p class="mg-order-export-status" hidden></p>' +
                '<p class="mg-order-export-error" hidden></p>' +
                '<div class="mg-order-export-actions">' +
                    '<a class="button button-primary mg-order-export-download" hidden></a>' +
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
        var errorEl       = overlay.querySelector('.mg-order-export-error');
        var downloadEl    = overlay.querySelector('.mg-order-export-download');
        var closeEl       = overlay.querySelector('.mg-order-export-close');

        titleEl.textContent       = i18n.title || 'Export';
        choiceTextEl.textContent  = i18n.choice_question || '';
        choiceStripEl.textContent = i18n.choice_strip || '';
        choiceNormalEl.textContent = i18n.choice_normal || '';
        statusEl.textContent      = i18n.processing || '...';
        closeEl.textContent       = i18n.close || 'Close';

        closeEl.addEventListener('click', function () {
            overlay.remove();
        });

        var showError = function (message) {
            errorEl.textContent = message || i18n.error || 'Error';
            errorEl.hidden = false;
        };

        var postJson = function (body) {
            return fetch(cfg.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body,
            }).then(function (response) { return response.json(); });
        };

        var updateProgress = function (data) {
            var percent = parseInt(data.percent || 0, 10);
            barEl.style.width = percent + '%';
            statusEl.textContent = (i18n.processing || '') + ' ' + data.completed + ' / ' + data.total + ' (' + percent + '%)';
        };

        var step = function (jobId) {
            var body = 'action=mg_design_export_step&nonce=' + encodeURIComponent(cfg.nonce) + '&job_id=' + encodeURIComponent(jobId);
            postJson(body).then(function (payload) {
                if (!payload || !payload.success) {
                    showError(payload && payload.data && payload.data.message);
                    return;
                }
                updateProgress(payload.data);
                if (payload.data.done) {
                    statusEl.textContent = i18n.done || 'Done';
                    downloadEl.textContent = i18n.download || 'Download';
                    downloadEl.href = cfg.ajax_url + '?action=mg_design_export_download&job_id=' + encodeURIComponent(jobId) + '&nonce=' + encodeURIComponent(cfg.nonce);
                    downloadEl.hidden = false;
                    return;
                }
                step(jobId);
            }).catch(function () {
                showError();
            });
        };

        var start = function (stripBlack) {
            var body = 'action=mg_design_export_start&nonce=' + encodeURIComponent(cfg.nonce) + '&strip_black=' + (stripBlack ? '1' : '0');
            cfg.order_ids.forEach(function (id) {
                body += '&order_ids[]=' + encodeURIComponent(id);
            });
            postJson(body).then(function (payload) {
                if (!payload || !payload.success) {
                    showError(payload && payload.data && payload.data.message);
                    return;
                }
                step(payload.data.job_id);
            }).catch(function () {
                showError();
            });
        };

        var beginExport = function (stripBlack) {
            choiceEl.hidden = true;
            barWrapEl.hidden = false;
            statusEl.hidden = false;
            start(stripBlack);
        };

        choiceStripEl.addEventListener('click', function () { beginExport(true); });
        choiceNormalEl.addEventListener('click', function () { beginExport(false); });
    });
})();
