(function ($) {
    'use strict';

    var cfg = window.mgOrderAddItem || {};
    var i18n = cfg.i18n || {};

    var $overlay, $results, $search, $selected, $variant, $type, $color, $size,
        $qty, $price, $notify, $save, $message, $warning;

    var state = {
        orderId: 0,
        productId: 0,
        types: [],
        searchTimer: null,
        priceTouched: false
    };

    function init() {
        $overlay  = $('#mg-add-item-overlay');
        $results  = $('#mg-add-item-results');
        $search   = $('#mg-add-item-search');
        $selected = $('#mg-add-item-selected');
        $variant  = $('#mg-add-item-variant');
        $type     = $('#mg-add-item-type');
        $color    = $('#mg-add-item-color');
        $size     = $('#mg-add-item-size');
        $qty      = $('#mg-add-item-qty');
        $price    = $('#mg-add-item-price');
        $notify   = $('#mg-add-item-notify');
        $save     = $('#mg-add-item-save');
        $message  = $('#mg-add-item-message');
        $warning  = $('#mg-add-item-production-warning');

        $(document).on('click', '.mg-add-item-btn', openModal);
        $(document).on('click', '.mg-add-item-close', closeModal);
        $(document).on('click', '#mg-add-item-overlay', function (e) {
            if ($(e.target).is('#mg-add-item-overlay')) {
                closeModal();
            }
        });
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $overlay.is(':visible')) {
                closeModal();
            }
        });

        $(document).on('input', '#mg-add-item-search', onSearchInput);
        $(document).on('click', '.mg-add-item-result', onResultClick);
        $(document).on('click', '#mg-add-item-change', backToSearch);
        $(document).on('change', '#mg-add-item-type', function () {
            renderColors();
            renderSizes();
            refreshPrice();
            refreshSaveState();
        });
        $(document).on('change', '#mg-add-item-color', refreshSaveState);
        $(document).on('change', '#mg-add-item-size', function () {
            refreshPrice();
            refreshSaveState();
        });
        $(document).on('input', '#mg-add-item-price', function () {
            state.priceTouched = true;
        });
        $(document).on('click', '#mg-add-item-save', saveItem);
    }

    function openModal() {
        var $btn = $(this);
        state.orderId = parseInt($btn.data('order-id'), 10) || 0;
        state.productId = 0;
        state.types = [];
        state.priceTouched = false;

        $warning.toggle(parseInt($btn.data('in-production'), 10) === 1);
        $search.val('');
        $results.empty();
        $selected.hide();
        $variant.hide();
        $qty.val(1);
        $price.val(0);
        $message.hide();
        $save.prop('disabled', true).text(i18n.add || 'Hozzáadás');
        $overlay.fadeIn(150);
        $search.trigger('focus');
    }

    function closeModal() {
        $overlay.fadeOut(120);
    }

    function showMessage(text, type) {
        $message
            .removeClass('notice-success notice-error')
            .addClass(type === 'success' ? 'notice-success' : 'notice-error')
            .show()
            .find('p').text(text);
    }

    function onSearchInput() {
        var term = $.trim($search.val());
        window.clearTimeout(state.searchTimer);

        if (term.length < 3) {
            $results.empty();
            return;
        }

        state.searchTimer = window.setTimeout(function () {
            $results.html('<li class="mg-add-item-note">' + (i18n.loading || '') + '</li>');
            $.post(cfg.ajax_url, {
                action: 'mg_order_add_item_search',
                nonce: cfg.nonce,
                q: term
            }, function (response) {
                if (!response.success) {
                    $results.empty();
                    showMessage((response.data && response.data.message) || i18n.error, 'error');
                    return;
                }
                renderResults(response.data.products || []);
            }).fail(function () {
                $results.empty();
                showMessage(i18n.error, 'error');
            });
        }, 300);
    }

    function renderResults(products) {
        $results.empty();

        if (!products.length) {
            $results.html('<li class="mg-add-item-note">' + (i18n.no_results || '') + '</li>');
            return;
        }

        products.forEach(function (product) {
            var $li = $('<li/>', { 'class': 'mg-add-item-result' })
                .attr('data-id', product.id)
                .attr('data-name', product.name)
                .attr('data-sku', product.sku || '')
                .attr('data-thumb', product.thumb || '');

            $li.append($('<img/>', { src: product.thumb || '', alt: '' }));
            $li.append($('<span/>', { 'class': 'mg-add-item-result-name', text: product.name }));
            if (product.sku) {
                $li.append($('<span/>', { 'class': 'mg-add-item-result-sku', text: product.sku }));
            }
            $results.append($li);
        });
    }

    function onResultClick() {
        var $li = $(this);
        state.productId = parseInt($li.data('id'), 10) || 0;
        if (!state.productId) {
            return;
        }

        $('#mg-add-item-thumb').attr('src', $li.data('thumb') || '');
        $('#mg-add-item-product-name').text($li.data('name') || '');
        $('#mg-add-item-product-sku').text($li.data('sku') || '');

        $results.empty();
        $search.val('');
        $search.closest('.mg-add-item-field').hide();
        $selected.show();

        loadOptions();
    }

    function backToSearch() {
        state.productId = 0;
        state.types = [];
        $selected.hide();
        $variant.hide();
        $save.prop('disabled', true);
        $search.closest('.mg-add-item-field').show();
        $search.val('').trigger('focus');
    }

    function loadOptions() {
        $variant.hide();
        $message.hide();

        $.post(cfg.ajax_url, {
            action: 'mg_order_add_item_options',
            nonce: cfg.nonce,
            product_id: state.productId
        }, function (response) {
            if (!response.success) {
                showMessage((response.data && response.data.message) || i18n.error, 'error');
                return;
            }

            state.types = response.data.types || [];
            renderTypes(response.data.default || {});
            renderColors((response.data.default || {}).color);
            renderSizes((response.data.default || {}).size);
            refreshPrice();
            refreshSaveState();
            $variant.show();
        }).fail(function () {
            showMessage(i18n.error, 'error');
        });
    }

    function renderTypes(defaults) {
        $type.empty().append($('<option/>', { value: '', text: i18n.select_type || '' }));
        state.types.forEach(function (type) {
            $type.append($('<option/>', { value: type.slug, text: type.label }));
        });
        if (defaults && defaults.type) {
            $type.val(defaults.type);
        }
    }

    function currentType() {
        var slug = $type.val();
        var found = null;
        state.types.forEach(function (type) {
            if (type.slug === slug) {
                found = type;
            }
        });
        return found;
    }

    function renderColors(preselect) {
        var type = currentType();
        $color.empty().append($('<option/>', { value: '', text: i18n.select_color || '' }));
        if (!type) {
            return;
        }
        (type.colors || []).forEach(function (color) {
            $color.append($('<option/>', { value: color.slug, text: color.label }));
        });
        if (preselect) {
            $color.val(preselect);
        }
    }

    function renderSizes(preselect) {
        var type = currentType();
        $size.empty().append($('<option/>', { value: '', text: i18n.select_size || '' }));
        if (!type) {
            return;
        }
        (type.sizes || []).forEach(function (size) {
            $size.append($('<option/>', { value: size.value, text: size.value }).attr('data-price', size.price));
        });
        if (preselect) {
            $size.val(preselect);
        }
    }

    /**
     * Keep the price field in sync with the catalog price until the admin
     * types their own value.
     */
    function refreshPrice() {
        if (state.priceTouched) {
            return;
        }
        var price = $size.find('option:selected').attr('data-price');
        $price.val(price ? Math.round(parseFloat(price)) : 0);
    }

    function refreshSaveState() {
        var ready = state.productId > 0 && $type.val() && $color.val() && $size.val();
        $save.prop('disabled', !ready);
    }

    function saveItem() {
        if ($save.prop('disabled')) {
            return;
        }

        $save.prop('disabled', true).text(i18n.adding || '');
        $message.hide();

        $.post(cfg.ajax_url, {
            action: 'mg_order_add_item_save',
            nonce: cfg.nonce,
            order_id: state.orderId,
            product_id: state.productId,
            type: $type.val(),
            color: $color.val(),
            size: $size.val(),
            qty: $qty.val(),
            price: $price.val(),
            notify: $notify.is(':checked') ? 1 : 0
        }, function (response) {
            if (!response.success) {
                showMessage((response.data && response.data.message) || i18n.error, 'error');
                $save.prop('disabled', false).text(i18n.add || '');
                return;
            }

            showMessage(response.data.message, 'success');
            // Reload so WooCommerce re-renders the items table and the totals.
            window.location.reload();
        }).fail(function () {
            showMessage(i18n.error, 'error');
            $save.prop('disabled', false).text(i18n.add || '');
        });
    }

    $(init);
})(jQuery);
