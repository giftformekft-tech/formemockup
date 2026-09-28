(function () {
    'use strict';
    const labels = {
        facebook: 'Facebook', instagram: 'Instagram', meta: 'Meta (Facebook / Instagram)',
        messenger: 'Messenger', audience_network: 'Meta Audience Network', google: 'Google',
        tiktok: 'TikTok', pinterest: 'Pinterest', microsoft: 'Microsoft / Bing', youtube: 'YouTube',
        chatgpt: 'ChatGPT / OpenAI', newsletter: 'Hírlevél', direct: 'Közvetlen',
        unknown: 'Ismeretlen forrás', admin: 'Adminban létrehozott', pos: 'Bolti pénztár', mobile_app: 'Mobilalkalmazás'
    };
    const platformName = value => labels[value] || value;
    const date = value => new Date(value + 'T00:00:00Z');
    const iso = value => value.toISOString().slice(0, 10);
    const addDays = (value, count) => { const result = date(value); result.setUTCDate(result.getUTCDate() + count); return iso(result); };

    function preset(today, value) {
        const current = date(today);
        const year = current.getUTCFullYear();
        const month = current.getUTCMonth();
        if (value === 'today') return [today, today];
        if (value === 'yesterday') return [addDays(today, -1), addDays(today, -1)];
        if (value === 'this_month') return [iso(new Date(Date.UTC(year, month, 1))), today];
        if (value === 'last_month') return [iso(new Date(Date.UTC(year, month - 1, 1))), iso(new Date(Date.UTC(year, month, 0)))];
        if (value === 'this_year') return [year + '-01-01', today];
        if (value === 'last_year') return [(year - 1) + '-01-01', (year - 1) + '-12-31'];
        return [addDays(today, -(Number(value) || 30) + 1), today];
    }

    function bucket(day, group) {
        if (group === 'year') return day.slice(0, 4) + '-01-01';
        if (group === 'month') return day.slice(0, 7) + '-01';
        if (group === 'week') return addDays(day, -((date(day).getUTCDay() + 6) % 7));
        return day;
    }

    const empty = () => ({ orders: 0, gross: 0, refunds: 0, tagged: 0 });
    function sum(target, row) {
        target.orders += row.orders;
        target.gross += row.gross;
        target.refunds += row.refunds;
        target.tagged += row.campaign ? row.orders : 0;
    }
    const revenue = row => row.gross - row.refunds;
    const campaignKey = value => JSON.stringify(value); // Empty campaign remains distinct from the all-campaigns option.

    function report(rows, filters, from, to, group) {
        const total = empty(), platforms = new Map(), campaigns = new Map(), periods = new Map();
        for (let day = from; day <= to; day = addDays(day, 1)) {
            const key = bucket(day, group);
            if (!periods.has(key)) periods.set(key, { key, ...empty() });
        }
        rows.forEach(row => {
            if (row.currency !== filters.currency || (filters.platform && row.platform !== filters.platform)
                || (filters.channel && row.channel !== filters.channel)
                || (filters.campaign && campaignKey(row.campaign) !== filters.campaign)) return;
            sum(total, row);
            if (!platforms.has(row.platform)) platforms.set(row.platform, { key: row.platform, ...empty() });
            sum(platforms.get(row.platform), row);
            const key = JSON.stringify([row.platform, row.campaign]);
            if (!campaigns.has(key)) campaigns.set(key, { platform: row.platform, campaign: row.campaign, ...empty() });
            sum(campaigns.get(key), row);
            const period = periods.get(bucket(row.day, group));
            if (period) sum(period, row);
        });
        const ranked = values => Array.from(values).sort((a, b) => b.orders - a.orders || revenue(b) - revenue(a));
        return { total, platforms: ranked(platforms.values()), campaigns: ranked(campaigns.values()), periods: Array.from(periods.values()) };
    }

    // The same calculation code is exercised by the regression tests.
    if (typeof module !== 'undefined' && module.exports) module.exports = { preset, bucket, report, campaignKey, revenue };
    if (typeof document === 'undefined') return;
    const root = document.getElementById('mg-order-attribution');
    if (!root || typeof MG_ORDER_ATTRIBUTION === 'undefined') return;
    const config = MG_ORDER_ATTRIBUTION;
    const get = name => document.getElementById('mg-attribution-' + name);
    const number = value => new Intl.NumberFormat('hu-HU', { maximumFractionDigits: 0 }).format(value);
    const money = value => {
        try { return new Intl.NumberFormat('hu-HU', { style: 'currency', currency: get('currency').value || config.currency }).format(value / 10000); }
        catch (_) { return new Intl.NumberFormat('hu-HU', { maximumFractionDigits: 4 }).format(value / 10000) + ' (ismeretlen pénznem)'; }
    };
    const el = (tag, text, className) => {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        if (className) node.className = className;
        return node;
    };
    const state = { token: 0, controller: null, rows: new Map(), cursor: 0, ceiling: 0, processed: 0, total: 0, from: '', to: '', complete: false };
    const controls = ['platform', 'campaign', 'channel', 'currency', 'group'];
    function status(message, failed) {
        get('status').textContent = message;
        get('status').parentElement.classList.toggle('is-error', !!failed);
    }
    function options(name, items, first, preferred) {
        const select = get(name), previous = preferred === undefined ? select.value : preferred;
        select.replaceChildren();
        if (first) select.add(new Option(first, ''));
        items.forEach(([value, label]) => select.add(new Option(label, value)));
        if (Array.from(select.options).some(option => option.value === previous)) select.value = previous;
    }
    function campaignOptions() {
        const available = new Set();
        state.rows.forEach(row => {
            if ((!get('platform').value || get('platform').value === row.platform)
                && (!get('channel').value || get('channel').value === row.channel)
                && get('currency').value === row.currency) available.add(row.campaign);
        });
        options('campaign', Array.from(available).sort((a, b) => a.localeCompare(b, 'hu')).map(value => [campaignKey(value), value || 'Nincs UTM-kampány']), 'Összes kampány');
    }
    function populate() {
        const platforms = new Set(), currencies = new Set();
        state.rows.forEach(row => { platforms.add(row.platform); currencies.add(row.currency); });
        options('platform', Array.from(platforms).sort((a, b) => platformName(a).localeCompare(platformName(b), 'hu')).map(value => [value, platformName(value)]), 'Összes platform');
        const oldCurrency = get('currency').value || config.currency;
        options('currency', Array.from(currencies.size ? currencies : [config.currency]).sort().map(value => [value, value === 'UNKNOWN' ? 'Ismeretlen pénznem' : value]), null, oldCurrency);
        campaignOptions();
    }

    function table(container, headers, rows) {
        const target = get(container);
        target.replaceChildren();
        if (!rows.length) { target.append(el('p', 'Nincs rendelés a kiválasztott szűrőkkel.', 'mg-attribution-empty')); return; }
        const node = el('table', undefined, 'widefat striped'), head = el('thead'), header = el('tr'), body = el('tbody');
        headers.forEach(title => { const cell = el('th', title); cell.scope = 'col'; header.append(cell); });
        head.append(header);
        rows.forEach(values => {
            const row = el('tr');
            values.forEach(value => { const cell = el('td'); cell.append(value instanceof Node ? value : document.createTextNode(String(value))); row.append(cell); });
            body.append(row);
        });
        node.append(head, body);
        target.append(node);
    }
    function filterLink(text, action) {
        const button = el('button', text, 'button-link');
        button.type = 'button';
        button.addEventListener('click', action);
        return button;
    }
    function periodLabel(key) {
        const group = get('group').value;
        if (group === 'year') return key.slice(0, 4);
        if (group === 'month') return key.slice(0, 7);
        if (group === 'week') return (key < state.from ? state.from : key) + ' – ' + (addDays(key, 6) > state.to ? state.to : addDays(key, 6));
        return key;
    }
    function chart(periods) {
        const target = get('chart');
        target.replaceChildren();
        const max = Math.max(1, ...periods.map(row => row.orders));
        const track = el('div', undefined, 'mg-attribution-chart-track');
        periods.forEach(row => {
            const column = el('div', undefined, 'mg-attribution-chart-column');
            const label = periodLabel(row.key);
            const bar = el('div', undefined, 'mg-attribution-chart-bar');
            bar.style.height = Math.max(2, row.orders / max * 130) + 'px';
            bar.setAttribute('role', 'img');
            bar.setAttribute('aria-label', label + ': ' + number(row.orders) + ' vásárlás, ' + money(revenue(row)));
            bar.title = bar.getAttribute('aria-label');
            column.append(el('span', number(row.orders)), bar, el('small', label));
            track.append(column);
        });
        target.append(track);
    }
    function render() {
        if (!state.complete) return;
        const filters = Object.fromEntries(['platform', 'campaign', 'channel', 'currency'].map(name => [name, get(name).value]));
        const data = report(Array.from(state.rows.values()), filters, state.from, state.to, get('group').value);
        const total = data.total;
        get('orders').textContent = number(total.orders);
        get('revenue').textContent = money(revenue(total));
        get('average').textContent = money(total.orders ? revenue(total) / total.orders : 0);
        get('coverage').textContent = total.orders ? Math.round(total.tagged / total.orders * 100) + '%' : '—';
        get('coverage-detail').textContent = number(total.tagged) + ' / ' + number(total.orders) + ' rendelés';
        get('period').textContent = state.from + ' – ' + state.to + ' · ' + (filters.currency === 'UNKNOWN' ? 'Ismeretlen pénznem' : filters.currency) + ' · ' + number(total.orders) + ' vásárlás';
        const metrics = row => [number(row.orders), money(revenue(row)), money(row.orders ? revenue(row) / row.orders : 0), money(row.refunds)];
        table('platforms', ['Platform', 'Vásárlások', 'Arány', 'Bevétel', 'Átlagos érték', 'Visszatérítés'], data.platforms.map(row => {
            const values = metrics(row);
            return [filterLink(platformName(row.key), () => { get('platform').value = row.key; get('campaign').value = ''; campaignOptions(); render(); }), values[0], (total.orders ? (row.orders / total.orders * 100).toFixed(1) : '0') + '%', ...values.slice(1)];
        }));
        table('campaigns', ['Kampány', 'Platform', 'Vásárlások', 'Bevétel', 'Átlagos érték', 'Visszatérítés'], data.campaigns.map(row => [
            filterLink(row.campaign || 'Nincs UTM-kampány', () => { get('platform').value = row.platform; campaignOptions(); get('campaign').value = campaignKey(row.campaign); render(); }),
            platformName(row.platform), ...metrics(row)
        ]));
        chart(data.periods);
        table('timeline', ['Időszak', 'Vásárlások', 'Bevétel', 'Átlagos érték', 'Visszatérítés'], data.periods.map(row => [periodLabel(row.key), ...metrics(row)]));
        get('results').hidden = false;
    }

    async function load(token) {
        get('retry').hidden = true;
        status('Rendelések betöltése…');
        while (token === state.token) {
            const controller = new AbortController();
            state.controller = controller;
            const timeout = setTimeout(() => controller.abort(), 25000);
            try {
                const response = await fetch(config.ajaxUrl, {
                    method: 'POST', credentials: 'same-origin', signal: controller.signal,
                    body: new URLSearchParams({ action: 'mg_order_attribution_report', nonce: config.nonce, from: state.from, to: state.to, cursor: String(state.cursor), ceiling: String(state.ceiling) })
                });
                const result = await response.json();
                if (token !== state.token) return;
                if (!response.ok || !result.success) throw new Error(result.data && result.data.message || 'Nem sikerült betölteni az adatokat.');
                const data = result.data;
                if (!data || !Array.isArray(data.rows) || typeof data.done !== 'boolean' || (!data.done && data.cursor <= state.cursor)) throw new Error('Hiányos válasz érkezett. Próbáld újra.');
                data.rows.forEach(row => {
                    const key = JSON.stringify([row.day, row.platform, row.channel, row.campaign, row.currency]);
                    if (!state.rows.has(key)) state.rows.set(key, { ...row, orders: 0, gross: 0, refunds: 0 });
                    const aggregate = state.rows.get(key);
                    aggregate.orders += row.orders;
                    aggregate.gross += row.gross;
                    aggregate.refunds += row.refunds;
                });
                state.cursor = data.cursor;
                state.ceiling = data.ceiling;
                state.processed += data.processed;
                if (data.total !== null) state.total = data.total;
                status(number(state.processed) + ' / ' + number(state.total) + ' rendelés feldolgozva…');
                if (data.done) {
                    state.complete = true;
                    controls.forEach(name => { get(name).disabled = false; });
                    populate();
                    render();
                    status(number(state.processed) + ' rendelés feldolgozva. Frissítve: ' + new Date().toLocaleTimeString('hu-HU') + '.');
                    return;
                }
            } catch (error) {
                if (token !== state.token) return;
                const message = error.name === 'AbortError' ? 'A betöltés időtúllépés miatt megállt.' : error.message;
                status(message + ' ' + number(state.processed) + ' rendelés már feldolgozva; az összesítés a teljes betöltés után jelenik meg.', true);
                get('retry').hidden = false;
                return;
            } finally { clearTimeout(timeout); }
        }
    }
    function invalidate() {
        state.token++;
        if (state.controller) state.controller.abort();
        state.complete = false;
        get('results').hidden = true;
        get('retry').hidden = true;
        controls.forEach(name => { get(name).disabled = true; });
    }
    function start(event) {
        if (event) event.preventDefault();
        if (!get('form').reportValidity()) return;
        invalidate();
        state.rows = new Map(); state.cursor = 0; state.ceiling = 0; state.processed = 0; state.total = 0;
        state.from = get('from').value; state.to = get('to').value;
        load(state.token);
    }
    get('form').addEventListener('submit', start);
    get('retry').addEventListener('click', () => load(state.token));
    get('preset').addEventListener('change', () => {
        if (get('preset').value !== 'custom') [get('from').value, get('to').value] = preset(config.today, get('preset').value);
        invalidate(); status('Az időszak módosult. Kattints a Lekérdezés / frissítés gombra.');
    });
    ['from', 'to'].forEach(name => get(name).addEventListener('change', () => {
        get('preset').value = 'custom'; invalidate(); status('Az időszak módosult. Kattints a Lekérdezés / frissítés gombra.');
    }));
    controls.forEach(name => get(name).addEventListener('change', () => {
        if (['platform', 'channel', 'currency'].includes(name)) campaignOptions();
        render();
    }));
    [get('from').value, get('to').value] = preset(config.today, '30');
    start();
}());
