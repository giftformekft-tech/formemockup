/* global MgOrderBadges */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // Collect all visible order row IDs from the DOM
        var rows   = document.querySelectorAll('tr[id^="order-"]');
        var ids    = [];

        rows.forEach(function (row) {
            var match = row.id.match(/^order-(\d+)$/);
            if (match) {
                ids.push(parseInt(match[1], 10));
            }
        });

        if (ids.length === 0) {
            return;
        }

        // Send order IDs to server for badge detection
        var body = new URLSearchParams();
        body.append('action',    MgOrderBadges.action);
        body.append('nonce',     MgOrderBadges.nonce);
        body.append('order_ids', JSON.stringify(ids));

        fetch(MgOrderBadges.ajax_url, {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:        body.toString(),
        })
        .then(function (r) { return r.json(); })
        .then(function (response) {
            if (!response || !response.success || !response.data) {
                return;
            }

            var data = response.data; // { is_express: [id, ...], is_premium_material: [...], ... }

            Object.keys(data).forEach(function (typeKey) {
                var badgeDef = MgOrderBadges.badges[typeKey];
                if (!badgeDef) return;

                data[typeKey].forEach(function (orderId) {
                    var row = document.getElementById('order-' + orderId);
                    if (!row) return;

                    // Find the total/amount cell
                    var cell = row.querySelector('.column-order_total');
                    if (!cell) cell = row.querySelector('td.order_total');
                    if (!cell) return;

                    // Avoid duplicate badges
                    if (cell.querySelector('.mg-order-badge--' + typeKey)) return;

                    cell.insertAdjacentHTML('beforeend', '<br>' + badgeDef.html);
                });
            });
        })
        .catch(function () {
            // Silent fail – badges are non-critical
        });
    });
}());
