(function (w, d) {
    'use strict';
    if (w.mgOpenAIPixelLoaded || !w.mgOpenAIConfig) return;
    w.mgOpenAIPixelLoaded = true;
    var config = w.mgOpenAIConfig;
    var granted = false, initialized = false, sdkReady = false, ready = false, viewed = false, checkoutSent = false;
    var busy = false, again = false, retries = 0, timer = null, seen = {};

    function init() {
        if (initialized) return;
        initialized = true;
        if (!w.oaiq) {
            var q = function () { q.q.push(arguments); };
            q.q = [];
            w.oaiq = q;
            var script = d.createElement('script');
            script.async = true;
            script.src = 'https://bzrcdn.openai.com/sdk/oaiq.min.js';
            script.onload = function () { sdkReady = true; start(); };
            d.head.appendChild(script);
        } else sdkReady = true;
        w.oaiq('consent', false);
        w.oaiq('init', {pixelId: config.pixelId, debug: config.debug});
    }

    function measure(name, data, id) {
        if (!granted) return;
        var storageKey = 'mg_oai_' + config.pixelId + '_' + id;
        if (id) {
            if (seen[id]) return;
            try { if (w.localStorage.getItem(storageKey)) return; } catch (e) {}
        }
        w.oaiq('measure', name, data, id ? {event_id: id} : {});
        if (id) {
            seen[id] = true;
            // Persist purchases only. SDK event_id also covers repeated browser/server delivery.
            if (name === 'order_created') {
                try { w.localStorage.setItem(storageKey, '1'); } catch (e) {}
            }
        }
    }

    function refresh() {
        if (!granted || !ready || !sdkReady) return;
        if (busy) { again = true; return; }
        busy = true;
        var body = new URLSearchParams();
        if (config.checkout && !checkoutSent) body.set('checkout', '1');
        if (config.orderId) {
            body.set('order_id', config.orderId);
            body.set('order_key', new URLSearchParams(w.location.search).get('key') || '');
        }
        w.fetch(config.endpoint, {method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body.toString()})
            .then(function (response) { if (!response.ok) throw new Error('Measurement lookup failed'); return response.json(); })
            .then(function (result) {
                if (!granted || !result.success) return;
                (result.data.events || []).forEach(function (event) {
                    if (event.name === 'checkout_started') {
                        if (checkoutSent) return;
                        checkoutSent = true;
                    }
                    measure(event.name, event.data, event.id);
                });
                if (result.data.pending && retries++ < 12) timer = w.setTimeout(refresh, 5000);
            }).catch(function () {
                if (granted && retries++ < 3) timer = w.setTimeout(refresh, 5000);
            }).finally(function () {
                busy = false;
                if (again) { again = false; refresh(); }
            });
    }

    function start() {
        if (!granted || !ready || !sdkReady) return;
        if (!viewed) {
            viewed = true;
            measure('page_viewed', {type: 'contents'});
            if (config.product) measure('contents_viewed', {type: 'contents', contents: [config.product]});
        }
        refresh();
    }

    function consent(value) {
        var next = value === true;
        if (next === granted) return;
        granted = next;
        if (granted) init();
        if (initialized) w.oaiq('consent', granted);
        if (!granted && timer) { w.clearTimeout(timer); timer = null; }
        start();
    }
    d.addEventListener('mg_gads_consent', function (event) {
        var detail = event.detail || {};
        consent(typeof detail.granted === 'boolean' ? detail.granted : detail.marketing === true);
    });
    function loaded() {
        ready = true;
        if (w.jQuery) w.jQuery(d.body).on('added_to_cart.mgOpenAI', refresh);
        d.addEventListener('wc-blocks_added_to_cart', refresh);
        var match = d.cookie.match(/(?:^|;\s*)mg_gads_consent=(granted|denied)(?:;|$)/);
        if (granted) start();
        else consent(!!match && match[1] === 'granted');
    }
    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', loaded);
    else loaded();
})(window, document);
