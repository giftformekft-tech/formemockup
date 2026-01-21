(function(){
    document.addEventListener('DOMContentLoaded', function(){
        var scope = document.querySelector('.mg-maintenance-table');
        if (!scope) {
            return;
        }
        var selectAll = scope.querySelector('.mg-select-all');
        if (selectAll) {
            selectAll.addEventListener('change', function(){
                var checkboxes = scope.querySelectorAll('tbody input[type="checkbox"][name="mockup_keys[]"]');
                checkboxes.forEach(function(cb){ cb.checked = selectAll.checked; });
            });
        }
        scope.addEventListener('click', function(evt){
            var target = evt.target;
            if (target.classList.contains('mg-row-action')) {
                var form = target.closest('form');
                if (form) {
                    var hiddenAction = form.querySelector('input[name="mg_mockup_action"]');
                    if (hiddenAction) {
                        hiddenAction.value = 'bulk_regenerate';
                    }
                }
            }
        });

        var toggles = scope.querySelectorAll('.mg-group-toggle');
        toggles.forEach(function(toggle){
            toggle.addEventListener('click', function(){
                var groupId = toggle.getAttribute('data-group');
                if (!groupId) {
                    return;
                }
                var isExpanded = toggle.getAttribute('aria-expanded') === 'true';
                toggle.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
                var rows = scope.querySelectorAll('.mg-group-entry[data-group="' + groupId + '"]');
                rows.forEach(function(row){
                    row.classList.toggle('is-collapsed', isExpanded);
                });
            });
        });
    });

    document.addEventListener('DOMContentLoaded', function(){
        var forms = document.querySelectorAll('.mg-batch-size-form');
        if (!forms.length) {
            return;
        }
        forms.forEach(function(form){
            var slider = form.querySelector('.mg-batch-size-slider');
            var output = form.querySelector('.mg-batch-size-value');
            if (!slider || !output) {
                return;
            }
            var update = function(){
                output.textContent = slider.value;
            };
            slider.addEventListener('input', update);
            slider.addEventListener('change', update);
            update();
        });
    });

    document.addEventListener('DOMContentLoaded', function(){
        var progressWrap = document.querySelector('[data-variant-progress]');
        if (!progressWrap || typeof window.MG_MOCKUP_MAINTENANCE === 'undefined') {
            return;
        }
        var bar = progressWrap.querySelector('.progress-bar span');
        var barWrap = progressWrap.querySelector('.progress-bar');
        var statusEl = progressWrap.querySelector('[data-variant-progress-status]');
        var valueEl = progressWrap.querySelector('[data-variant-progress-value]');
        var runningLabel = progressWrap.getAttribute('data-label-running') || '';
        var idleLabel = progressWrap.getAttribute('data-label-idle') || '';
        var endpoint = window.MG_MOCKUP_MAINTENANCE.ajax_url || '';
        var nonce = window.MG_MOCKUP_MAINTENANCE.nonce || '';

        if (!endpoint || !nonce) {
            return;
        }

        var applyData = function(data){
            if (!data) {
                return;
            }
            var percent = parseInt(data.percent || 0, 10);
            var current = parseInt(data.current || 0, 10);
            var total = parseInt(data.total || 0, 10);
            var status = data.status || 'idle';
            if (bar) {
                bar.style.width = percent + '%';
            }
            if (barWrap) {
                barWrap.setAttribute('aria-valuenow', percent);
            }
            if (statusEl) {
                statusEl.textContent = status === 'running' ? runningLabel : idleLabel;
            }
            if (valueEl) {
                valueEl.textContent = total > 0 ? (current + ' / ' + total) : valueEl.textContent;
            }
        };

        var refresh = function(){
            fetch(endpoint + '?action=mg_variant_progress&nonce=' + encodeURIComponent(nonce), { credentials: 'same-origin' })
                .then(function(response){ return response.json(); })
                .then(function(payload){
                    if (payload && payload.success) {
                        applyData(payload.data);
                    }
                })
                .catch(function(){});
        };

        refresh();
        setInterval(refresh, 4000);
    });
})();
