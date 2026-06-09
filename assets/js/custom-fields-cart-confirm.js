(function () {
    var confirmedForms = new WeakSet ? new WeakSet() : [];

    // Inject the backdrop dimming once – ::backdrop is a pseudo-element and
    // cannot be set via inline styles, so this guarantees the dark overlay
    // even if the plugin stylesheet is blocked or cached.
    function ensureBackdropStyle() {
        if (document.getElementById('mgcc-backdrop-style')) { return; }
        var style = document.createElement('style');
        style.id = 'mgcc-backdrop-style';
        style.textContent = 'dialog.mgcc-dialog::backdrop{background:rgba(15,23,42,0.55);backdrop-filter:blur(3px);}';
        (document.head || document.documentElement).appendChild(style);
    }

    function isConfirmed(form) {
        if (typeof WeakSet !== 'undefined') {
            return confirmedForms.has(form);
        }
        return confirmedForms.indexOf(form) !== -1;
    }

    function setConfirmed(form) {
        if (typeof WeakSet !== 'undefined') {
            confirmedForms.add(form);
        } else {
            confirmedForms.push(form);
        }
    }

    function removeConfirmed(form) {
        if (typeof WeakSet !== 'undefined') {
            confirmedForms.delete(form);
        } else {
            var idx = confirmedForms.indexOf(form);
            if (idx !== -1) { confirmedForms.splice(idx, 1); }
        }
    }

    function hasCustomFields(form) {
        return form.querySelectorAll('.mg-custom-field').length > 0;
    }

    function collectFieldData(form) {
        var rows = [];
        var fieldBlocks = form.querySelectorAll('.mg-custom-field');
        Array.prototype.forEach.call(fieldBlocks, function (block) {
            var labelEl = block.querySelector('label');
            if (!labelEl) { return; }
            var label = labelEl.textContent.replace(/\s*\*\s*$/, '').trim();

            var input = block.querySelector('input, select, textarea');
            if (!input) { return; }

            var value = '';
            if (input.tagName === 'SELECT') {
                value = input.options[input.selectedIndex] ? input.options[input.selectedIndex].text : '';
            } else if (input.type === 'color') {
                value = input.value;
            } else {
                value = input.value;
            }

            if (value === '' || value === null) { return; }

            rows.push({ label: label, value: value, type: input.type });
        });
        return rows;
    }

    function buildModal(rows) {
        var strings = (window.mgCartConfirm && window.mgCartConfirm.i18n) ? window.mgCartConfirm.i18n : {};
        var title    = strings.title    || 'Egyedi termék megerősítése';
        var intro    = strings.intro    || 'Ez a termék a következő egyedi adatokkal fog elkészülni:';
        var ok       = strings.ok       || 'Rendben';
        var cancel   = strings.cancel   || 'Mégse';

        // Native <dialog> renders in the browser top layer, which escapes any
        // ancestor transform/filter/overflow – so the popup always centers on
        // the viewport regardless of theme CSS.
        var dialog = document.createElement('dialog');
        dialog.className = 'mgcc-dialog';
        dialog.setAttribute('aria-labelledby', 'mgcc-title');
        // Inline styles so the popup looks correct even if the theme/cache
        // prevents the plugin stylesheet from loading.
        dialog.style.cssText = [
            'padding:0',
            'border:none',
            'border-radius:18px',
            'background:transparent',
            'overflow:visible',
            'max-width:480px',
            'width:calc(100% - 32px)',
            'box-shadow:0 32px 64px rgba(15,23,42,0.22)'
        ].join(';');

        var box = document.createElement('div');
        box.className = 'mgcc-box';
        box.style.cssText = [
            'background:#ffffff',
            'border-radius:18px',
            'padding:32px 28px 24px',
            'box-sizing:border-box',
            'width:100%',
            'max-height:calc(100vh - 40px)',
            'overflow-y:auto',
            'text-align:left',
            'color:#0f172a',
            'font-family:inherit'
        ].join(';');

        var h2 = document.createElement('h2');
        h2.id = 'mgcc-title';
        h2.className = 'mgcc-title';
        h2.textContent = title;
        h2.style.cssText = 'margin:0 0 10px;font-size:20px;font-weight:700;color:#0f172a;line-height:1.3';
        box.appendChild(h2);

        var p = document.createElement('p');
        p.className = 'mgcc-intro';
        p.textContent = intro;
        p.style.cssText = 'margin:0 0 18px;font-size:14px;color:#475569;line-height:1.5';
        box.appendChild(p);

        var table = document.createElement('table');
        table.className = 'mgcc-table';
        table.style.cssText = 'width:100%;border-collapse:collapse;margin:0 0 24px';
        rows.forEach(function (row) {
            var tr = document.createElement('tr');
            var th = document.createElement('th');
            th.textContent = row.label;
            th.style.cssText = 'padding:8px 10px;font-size:14px;text-align:left;border-bottom:1px solid #e2e8f0;font-weight:600;color:#334155;width:45%;vertical-align:top';
            var td = document.createElement('td');
            td.style.cssText = 'padding:8px 10px;font-size:14px;text-align:left;border-bottom:1px solid #e2e8f0;color:#0f172a;word-break:break-word;vertical-align:top';
            if (row.type === 'color') {
                var swatch = document.createElement('span');
                swatch.className = 'mgcc-color-swatch';
                swatch.style.cssText = 'display:inline-block;width:16px;height:16px;border-radius:4px;border:1px solid rgba(0,0,0,0.15);vertical-align:middle;margin-right:4px;background:' + row.value;
                td.appendChild(swatch);
                td.appendChild(document.createTextNode(' ' + row.value));
            } else {
                td.textContent = row.value;
            }
            tr.appendChild(th);
            tr.appendChild(td);
            table.appendChild(tr);
        });
        box.appendChild(table);

        var actions = document.createElement('div');
        actions.className = 'mgcc-actions';
        actions.style.cssText = 'display:flex;gap:12px;justify-content:flex-end;flex-wrap:wrap';

        var btnBase = 'display:inline-flex;align-items:center;justify-content:center;padding:10px 24px;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;border:2px solid transparent;line-height:1.2;font-family:inherit;box-shadow:none;text-shadow:none';

        var btnOk = document.createElement('button');
        btnOk.type = 'button';
        btnOk.className = 'mgcc-btn mgcc-btn--ok';
        btnOk.textContent = ok;
        btnOk.style.cssText = btnBase + ';background:#2563eb;color:#ffffff;border-color:#2563eb';

        var btnCancel = document.createElement('button');
        btnCancel.type = 'button';
        btnCancel.className = 'mgcc-btn mgcc-btn--cancel';
        btnCancel.textContent = cancel;
        btnCancel.style.cssText = btnBase + ';background:#ffffff;color:#475569;border-color:#cbd5e1';

        actions.appendChild(btnOk);
        actions.appendChild(btnCancel);
        box.appendChild(actions);
        dialog.appendChild(box);

        return { dialog: dialog, btnOk: btnOk, btnCancel: btnCancel };
    }

    function showConfirm(form, rows, onConfirm) {
        ensureBackdropStyle();
        var modal = buildModal(rows);
        var dialog = modal.dialog;
        var supportsModal = typeof dialog.showModal === 'function';

        document.body.appendChild(dialog);
        document.body.classList.add('mgcc-open');

        function close() {
            if (dialog.open && typeof dialog.close === 'function') {
                try { dialog.close(); } catch (err) {}
            }
            if (dialog.parentNode) {
                dialog.parentNode.removeChild(dialog);
            }
            document.body.classList.remove('mgcc-open');
        }

        modal.btnOk.addEventListener('click', function () {
            close();
            onConfirm();
        });

        modal.btnCancel.addEventListener('click', function () {
            close();
        });

        // Click on the backdrop (outside the box) closes the dialog
        dialog.addEventListener('click', function (e) {
            if (e.target === dialog) {
                close();
            }
        });

        // Native ESC / cancel handling for <dialog>
        dialog.addEventListener('cancel', function (e) {
            e.preventDefault();
            close();
        });

        if (supportsModal) {
            dialog.showModal();
        } else {
            // Fallback for very old browsers without <dialog> support
            dialog.setAttribute('open', '');
            dialog.style.position = 'fixed';
            dialog.style.top = '50%';
            dialog.style.left = '50%';
            dialog.style.transform = 'translate(-50%, -50%)';
            dialog.style.zIndex = '999999';
            document.addEventListener('keydown', function escHandler(e) {
                if (e.key === 'Escape' || e.keyCode === 27) {
                    document.removeEventListener('keydown', escHandler);
                    close();
                }
            });
        }

        modal.btnOk.focus();
    }

    function onFormSubmit(e) {
        var form = e.currentTarget || e.target;

        if (!hasCustomFields(form)) { return; }
        if (isConfirmed(form)) {
            removeConfirmed(form);
            return;
        }

        e.preventDefault();
        e.stopImmediatePropagation();

        var rows = collectFieldData(form);
        if (!rows.length) {
            // No values entered yet – let WooCommerce validation handle it
            setConfirmed(form);
            form.submit();
            return;
        }

        showConfirm(form, rows, function () {
            setConfirmed(form);
            if (form.querySelector('[type="submit"]')) {
                form.querySelector('[type="submit"]').click();
            } else {
                form.submit();
            }
        });
    }

    function attachToForms() {
        var forms = document.querySelectorAll('form.cart');
        Array.prototype.forEach.call(forms, function (form) {
            if (form.dataset.mgccBound === '1') { return; }
            if (!hasCustomFields(form)) { return; }
            form.dataset.mgccBound = '1';
            form.addEventListener('submit', onFormSubmit);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachToForms);
    } else {
        attachToForms();
    }

    // Re-attach after variant/AJAX updates
    document.addEventListener('mgVariantReady', attachToForms);
    if (window.jQuery) {
        window.jQuery(document).on('mgVariantReady updated_checkout wc_fragment_refresh', attachToForms);
    }
})();
