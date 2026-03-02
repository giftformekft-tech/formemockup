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

            // Product search logic
            $('#mgcf-search-btn').on('click', function(e) {
                e.preventDefault();
                var query = $('#mgcf-search-input').val().trim();
                var presetId = $(this).data('preset');
                
                if (query.length < 2) {
                    alert('Kérjük, írjon be legalább 2 karaktert a kereséshez.');
                    return;
                }

                $('#mgcf-search-spinner').addClass('is-active');
                $('#mgcf-search-btn').prop('disabled', true);

                $.ajax({
                    url: mgcfAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'mgcf_search_products_by_minta',
                        nonce: mgcfAdmin.nonce,
                        query: query
                    },
                    success: function(response) {
                        $('#mgcf-search-spinner').removeClass('is-active');
                        $('#mgcf-search-btn').prop('disabled', false);

                        if (response.success) {
                            var products = response.data.products;
                            var tbody = $('#mgcf-search-results-table tbody');
                            tbody.empty();

                            if (products.length === 0) {
                                tbody.append('<tr><td colspan="4">Nem található megfelelő termék.</td></tr>');
                            } else {
                                $.each(products, function(i, product) {
                                    var row = '<tr>' +
                                        '<td class="mgcf-table__check"><input type="checkbox" name="mgcf_search_product_ids[]" value="' + product.id + '" /></td>' +
                                        '<td class="mgcf-table__image">' + product.thumbnail + '</td>' +
                                        '<td>' + product.title + '</td>' +
                                        '<td>' + product.status + '</td>' +
                                        '</tr>';
                                    tbody.append(row);
                                });
                            }
                            $('#mgcf-search-results-container').show();
                            $('#mgcf-search-select-all').prop('checked', false);
                        } else {
                            alert(response.data.message || 'Hiba történt a keresés során.');
                        }
                    },
                    error: function() {
                        $('#mgcf-search-spinner').removeClass('is-active');
                        $('#mgcf-search-btn').prop('disabled', false);
                        alert('Hálózati hiba történt.');
                    }
                });
            });

            // Search results Select All checkbox
            $('#mgcf-search-select-all').on('change', function() {
                var isChecked = $(this).is(':checked');
                $('#mgcf-search-results-table input[name="mgcf_search_product_ids[]"]').prop('checked', isChecked);
            });

            $(document).on('change', '#mgcf-search-results-table input[name="mgcf_search_product_ids[]"]', function() {
                var total = $('#mgcf-search-results-table input[name="mgcf_search_product_ids[]"]').length;
                var checked = $('#mgcf-search-results-table input[name="mgcf_search_product_ids[]"]:checked').length;
                $('#mgcf-search-select-all').prop('checked', total === checked);
            });

            // Assign searched products
            $('#mgcf-search-assign-btn').on('click', function(e) {
                e.preventDefault();
                var presetId = $(this).data('preset');
                var selectedIds = [];
                
                $('#mgcf-search-results-table input[name="mgcf_search_product_ids[]"]:checked').each(function() {
                    selectedIds.push($(this).val());
                });

                if (selectedIds.length === 0) {
                    alert('Kérjük, válasszon ki legalább egy terméket a hozzárendeléshez.');
                    return;
                }

                var btn = $(this);
                var originalText = btn.text();
                btn.prop('disabled', true).text('A hozzárendelés folyamatban...');

                $.ajax({
                    url: mgcfAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'mgcf_assign_searched_products',
                        nonce: mgcfAdmin.nonce,
                        preset_id: presetId,
                        product_ids: selectedIds
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            // Reload page to show updated standard assignment list
                            window.location.reload();
                        } else {
                            alert(response.data.message || 'Hiba történt.');
                            btn.prop('disabled', false).text(originalText);
                        }
                    },
                    error: function() {
                        alert('Hálózati hiba történt.');
                        btn.prop('disabled', false).text(originalText);
                    }
                });
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
