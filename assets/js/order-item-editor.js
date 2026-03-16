(function ($) {
    'use strict';

    var cfg = window.mgOrderItemEditor || {};
    var currentItemId = 0;
    var currentOrderId = 0;

    // Cache modal elements
    var $overlay, $loading, $content, $saveBtn, $colorSelect, $sizeSelect, $message;

    function init() {
        $overlay = $('#mg-item-editor-overlay');
        $loading = $('#mg-item-editor-loading');
        $content = $('#mg-item-editor-content');
        $saveBtn = $('#mg-item-editor-save');
        $colorSelect = $('#mg-new-color');
        $sizeSelect = $('#mg-new-size');
        $message = $('#mg-item-editor-message');

        // Open modal when "Módosítás" button clicked
        $(document).on('click', '.mg-edit-item-btn', function () {
            var $btn = $(this);
            currentItemId = $btn.data('item-id');
            currentOrderId = $btn.data('order-id');
            var typeSlug = $btn.data('type') || '';
            var curColor = $btn.data('color') || '—';
            var curSize = $btn.data('size') || '—';

            // Reset modal state
            resetModal();
            $('#mg-current-color').text(curColor);
            $('#mg-current-size').text(curSize);
            $overlay.fadeIn(150);

            // Fetch options
            $.post(cfg.ajax_url, {
                action: 'mg_get_item_options',
                nonce: cfg.nonce,
                item_id: currentItemId,
                type_slug: typeSlug
            }, function (response) {
                $loading.hide();

                if (!response.success) {
                    showMessage(response.data.message || cfg.i18n.error, 'error');
                    return;
                }

                var data = response.data;
                populateColors(data.colors || [], curColor);
                populateSizes(data.sizes || [], curSize);

                $content.show();
                $saveBtn.show();
            }).fail(function () {
                $loading.hide();
                showMessage(cfg.i18n.error, 'error');
            });
        });

        // Close modal
        $(document).on('click', '.mg-item-editor-close', closeModal);
        $(document).on('click', '#mg-item-editor-overlay', function (e) {
            if ($(e.target).is('#mg-item-editor-overlay')) {
                closeModal();
            }
        });
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') { closeModal(); }
        });

        // Save
        $(document).on('click', '#mg-item-editor-save', saveChanges);
    }

    function resetModal() {
        $loading.show();
        $content.hide();
        $saveBtn.hide();
        $message.hide().removeClass('notice-success notice-error');
        $colorSelect.html('<option value="">' + cfg.i18n.select_color + '</option>');
        $sizeSelect.html('<option value="">' + cfg.i18n.select_size + '</option>');
        $('#mg-current-color').text('—');
        $('#mg-current-size').text('—');
    }

    function populateColors(colors, currentSlug) {
        $colorSelect.html('<option value="">' + cfg.i18n.select_color + '</option>');
        if (!colors.length) {
            $('#mg-color-row').hide();
            return;
        }
        $('#mg-color-row').show();
        $.each(colors, function (i, c) {
            var label = c.label || c.slug;
            if (c.hex) {
                label = '● ' + label;
            }
            var $opt = $('<option>').val(c.slug).text(label);
            if (c.slug === currentSlug) {
                $opt.prop('selected', true);
            }
            if (c.hex) {
                $opt.attr('data-hex', c.hex);
            }
            $colorSelect.append($opt);
        });
    }

    function populateSizes(sizes, currentSize) {
        $sizeSelect.html('<option value="">' + cfg.i18n.select_size + '</option>');
        if (!sizes.length) {
            $('#mg-size-row').hide();
            return;
        }
        $('#mg-size-row').show();
        $.each(sizes, function (i, s) {
            var $opt = $('<option>').val(s).text(s);
            if (s === currentSize) {
                $opt.prop('selected', true);
            }
            $sizeSelect.append($opt);
        });
    }

    function saveChanges() {
        var newColor = $colorSelect.val();
        var newSize = $sizeSelect.val();

        if (!newColor && !newSize) {
            showMessage(cfg.i18n.error, 'error');
            return;
        }

        $saveBtn.prop('disabled', true).text(cfg.i18n.loading);
        $message.hide();

        $.post(cfg.ajax_url, {
            action: 'mg_update_item_color_size',
            nonce: cfg.nonce,
            item_id: currentItemId,
            new_color: newColor,
            new_size: newSize
        }, function (response) {
            $saveBtn.prop('disabled', false).text(cfg.i18n.save);

            if (!response.success) {
                showMessage(response.data.message || cfg.i18n.error, 'error');
                return;
            }

            showMessage(cfg.i18n.saved, 'success');

            // Update the button's data attributes for next open
            var $btn = $('.mg-edit-item-btn[data-item-id="' + currentItemId + '"]');
            if (newColor) { $btn.data('color', newColor); }
            if (newSize) { $btn.data('size', newSize); }

            // Close after short delay
            setTimeout(closeModal, 1200);
        }).fail(function () {
            $saveBtn.prop('disabled', false).text(cfg.i18n.save);
            showMessage(cfg.i18n.error, 'error');
        });
    }

    function showMessage(msg, type) {
        $message
            .removeClass('notice-success notice-error')
            .addClass(type === 'success' ? 'notice-success' : 'notice-error')
            .text(msg)
            .show();
    }

    function closeModal() {
        $overlay.fadeOut(150);
        currentItemId = 0;
        currentOrderId = 0;
    }

    $(document).ready(init);

}(jQuery));
