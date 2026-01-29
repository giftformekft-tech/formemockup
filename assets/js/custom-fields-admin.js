/**
 * Custom Fields Admin JavaScript
 * Handles modal popups and interactions for preset management
 */
(function($) {
    'use strict';

    var MGCF = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // New preset modal
            $('#mgcf-new-preset-btn').on('click', function(e) {
                e.preventDefault();
                MGCF.openModal('#mgcf-new-preset-modal');
            });

            // Edit preset modal
            $('#mgcf-edit-preset-btn').on('click', function(e) {
                e.preventDefault();
                MGCF.openModal('#mgcf-edit-preset-modal');
            });

            // Close modal buttons
            $(document).on('click', '.mgcf-modal-close, .mgcf-modal-overlay', function(e) {
                e.preventDefault();
                MGCF.closeAllModals();
            });

            // Escape key to close modals
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    MGCF.closeAllModals();
                }
            });

            // Prevent modal content clicks from closing
            $(document).on('click', '.mgcf-modal-content', function(e) {
                e.stopPropagation();
            });

            // Select all checkbox
            $('#mgcf-select-all').on('change', function() {
                var isChecked = $(this).is(':checked');
                $('#mgcf-product-assignment-form input[name="product_ids[]"]').prop('checked', isChecked);
            });

            // Update select all state on individual change
            $(document).on('change', '#mgcf-product-assignment-form input[name="product_ids[]"]', function() {
                var total = $('#mgcf-product-assignment-form input[name="product_ids[]"]').length;
                var checked = $('#mgcf-product-assignment-form input[name="product_ids[]"]:checked').length;
                $('#mgcf-select-all').prop('checked', total === checked);
            });
        },

        openModal: function(selector) {
            $(selector).fadeIn(200);
            $('body').addClass('mgcf-modal-open');
        },

        closeAllModals: function() {
            $('.mgcf-modal').fadeOut(200);
            $('body').removeClass('mgcf-modal-open');
        }
    };

    $(document).ready(function() {
        MGCF.init();
    });

})(jQuery);
