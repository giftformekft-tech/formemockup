(function () {
    'use strict';
    const copy = document.getElementById('mg-outlet-copy');
    if (copy) copy.addEventListener('click', async function () {
        const input = document.getElementById('mg-outlet-link');
        input.select();
        try {
            await navigator.clipboard.writeText(input.value);
            document.getElementById('mg-outlet-copy-status').textContent = 'Link másolva.';
        } catch (_) {
            document.getElementById('mg-outlet-copy-status').textContent = 'A link kijelölve; másoláshoz nyomj Ctrl+C-t.';
        }
    });
    const form = document.getElementById('mg-outlet-form');
    if (!form) return;
    const types = JSON.parse(document.getElementById('mg-outlet-types').textContent);
    const field = key => document.getElementById('mg-outlet-' + key);
    function options(select, entries) {
        select.replaceChildren(new Option('Válassz…', ''));
        entries.forEach(([value, label]) => select.add(new Option(label, value)));
    }
    options(field('type'), Object.entries(types).map(([key, data]) => [key, data.label]));
    options(field('color'), []);
    options(field('size'), []);
    field('type').addEventListener('change', () => {
        options(field('color'), Object.entries(types[field('type').value]?.colors || {}).map(([key, data]) => [key, data.label]));
        options(field('size'), []);
    });
    field('color').addEventListener('change', () => {
        const sizes = types[field('type').value]?.colors[field('color').value]?.sizes || [];
        options(field('size'), sizes.map(size => [String(size), String(size)]));
        if (sizes.length === 1) field('size').value = String(sizes[0]);
    });
    field('photo').addEventListener('click', () => {
        const picker = wp.media({ title: 'Outlet darab fotója', library: { type: 'image' }, multiple: false });
        picker.on('select', () => {
            const image = picker.state().get('selection').first().toJSON();
            field('image').value = image.id;
            field('photo-label').textContent = ' ' + (image.filename || image.title);
        });
        picker.open();
    });
    field('photo-clear').addEventListener('click', () => {
        field('image').value = '0';
        field('photo-label').textContent = ' A kombináció mockupját használjuk.';
    });
    field('submit').addEventListener('click', async () => {
        const result = field('result');
        if (!['type', 'color', 'size', 'price', 'qty'].every(key => field(key).value.trim())) {
            result.textContent = 'Válaszd ki a típust, színt és méretet, és add meg az árat és darabszámot.';
            return;
        }
        field('submit').disabled = true;
        result.textContent = 'Outlet darab létrehozása…';
        const body = new URLSearchParams({ action: 'mg_create_outlet', product_id: form.dataset.product,
            nonce: form.dataset.nonce, request: form.dataset.request, image_id: field('image').value });
        ['type', 'color', 'size', 'price', 'qty', 'note'].forEach(key => body.set(key, field(key).value));
        try {
            const response = await fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body });
            const payload = await response.json();
            if (!payload.success) throw new Error(payload.data?.message || 'Nem sikerült létrehozni a darabot.');
            result.textContent = payload.data.message + ' ';
            const link = document.createElement('a');
            link.href = payload.data.edit_url;
            link.textContent = 'Outlet darab szerkesztése';
            result.append(link);
            field('submit').textContent = 'Elkészült – új darabhoz frissítsd az oldalt';
        } catch (error) {
            result.textContent = error.message || 'Kapcsolati hiba. Próbáld újra ugyanazzal a gombbal.';
            field('submit').disabled = false;
        }
    });
}());
