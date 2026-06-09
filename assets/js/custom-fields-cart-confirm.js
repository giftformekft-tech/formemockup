(function () {
    var confirmedForms = new WeakSet ? new WeakSet() : [];

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

        var overlay = document.createElement('div');
        overlay.className = 'mgcc-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'mgcc-title');
        // Force fixed positioning inline so theme transforms cannot break it
        var ov = overlay.style;
        ov.position = 'fixed';
        ov.top = '0';
        ov.left = '0';
        ov.right = '0';
        ov.bottom = '0';
        ov.width = '100%';
        ov.height = '100%';
        ov.zIndex = '999999';
        ov.display = 'flex';
        ov.alignItems = 'center';
        ov.justifyContent = 'center';
        ov.background = 'rgba(15,23,42,0.55)';
        ov.boxSizing = 'border-box';
        ov.padding = '16px';

        var box = document.createElement('div');
        box.className = 'mgcc-box';

        var h2 = document.createElement('h2');
        h2.id = 'mgcc-title';
        h2.className = 'mgcc-title';
        h2.textContent = title;
        box.appendChild(h2);

        var p = document.createElement('p');
        p.className = 'mgcc-intro';
        p.textContent = intro;
        box.appendChild(p);

        var table = document.createElement('table');
        table.className = 'mgcc-table';
        rows.forEach(function (row) {
            var tr = document.createElement('tr');
            var th = document.createElement('th');
            th.textContent = row.label;
            var td = document.createElement('td');
            if (row.type === 'color') {
                var swatch = document.createElement('span');
                swatch.className = 'mgcc-color-swatch';
                swatch.style.background = row.value;
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

        var btnOk = document.createElement('button');
        btnOk.type = 'button';
        btnOk.className = 'mgcc-btn mgcc-btn--ok';
        btnOk.textContent = ok;

        var btnCancel = document.createElement('button');
        btnCancel.type = 'button';
        btnCancel.className = 'mgcc-btn mgcc-btn--cancel';
        btnCancel.textContent = cancel;

        actions.appendChild(btnOk);
        actions.appendChild(btnCancel);
        box.appendChild(actions);
        overlay.appendChild(box);

        return { overlay: overlay, btnOk: btnOk, btnCancel: btnCancel };
    }

    function showConfirm(form, rows, onConfirm) {
        var modal = buildModal(rows);
        var overlay = modal.overlay;

        document.body.appendChild(overlay);
        document.body.classList.add('mgcc-open');

        // Trap focus on OK by default
        modal.btnOk.focus();

        function close() {
            document.body.removeChild(overlay);
            document.body.classList.remove('mgcc-open');
        }

        modal.btnOk.addEventListener('click', function () {
            close();
            onConfirm();
        });

        modal.btnCancel.addEventListener('click', function () {
            close();
        });

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) {
                close();
            }
        });

        document.addEventListener('keydown', function escHandler(e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                document.removeEventListener('keydown', escHandler);
                close();
            }
        });
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
