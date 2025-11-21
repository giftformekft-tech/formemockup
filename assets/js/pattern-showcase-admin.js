/**
 * Pattern Showcase Admin JavaScript
 *
 * Handles admin interface interactions for pattern showcases
 *
 * @package MockupGenerator
 * @since 1.3.0
 */

(function($) {
    'use strict';

    var PatternShowcaseAdmin = {
        mediaFrame: null,
        products: {},

        init: function() {
            this.bindEvents();
            this.updateColorStrategyVisibility();
            this.updateLayoutVisibility();
        },

        bindEvents: function() {
            var self = this;

            // Design file selector
            $(document).on('click', '.mg-select-design', function(e) {
                e.preventDefault();
                self.openMediaLibrary();
            });

            // Form submission
            $(document).on('submit', '#mg-showcase-form', function(e) {
                e.preventDefault();
                self.saveShowcase();
            });

            // Delete showcase
            $(document).on('click', '.mg-delete-showcase', function(e) {
                e.preventDefault();
                var id = $(this).data('id');
                self.deleteShowcase(id);
            });

            // Generate mockups
            $(document).on('click', '.mg-generate-mockups, .mg-generate-mockups-single', function(e) {
                e.preventDefault();
                var id = $(this).data('id');
                self.generateMockups(id);
            });

            // Color strategy change
            $(document).on('change', 'input[name="color_strategy"]', function() {
                self.updateColorStrategyVisibility();
            });

            // Layout change
            $(document).on('change', 'input[name="layout"]', function() {
                self.updateLayoutVisibility();
            });

            // Product types change (for custom colors)
            $(document).on('change', 'input[name="product_types[]"]', function() {
                self.updateCustomColorsList();
            });
        },

        openMediaLibrary: function() {
            var self = this;

            if (this.mediaFrame) {
                this.mediaFrame.open();
                return;
            }

            this.mediaFrame = wp.media({
                title: MG_PATTERN_SHOWCASE_ADMIN.strings.select_design,
                button: {
                    text: MG_PATTERN_SHOWCASE_ADMIN.strings.use_this_file
                },
                multiple: false,
                library: {
                    type: 'image'
                }
            });

            this.mediaFrame.on('select', function() {
                var attachment = self.mediaFrame.state().get('selection').first().toJSON();
                $('#showcase_design').val(attachment.id);
                $('.mg-design-preview').html('<img src="' + attachment.url + '" style="max-width: 150px;">');
            });

            this.mediaFrame.open();
        },

        saveShowcase: function() {
            var $form = $('#mg-showcase-form');
            var formData = $form.serializeArray();
            var data = {
                action: 'mg_save_pattern_showcase',
                nonce: MG_PATTERN_SHOWCASE_ADMIN.nonce
            };

            // Process form data
            $.each(formData, function(i, field) {
                if (field.name === 'product_types[]') {
                    if (!data.product_types) {
                        data.product_types = [];
                    }
                    data.product_types.push(field.value);
                } else if (field.name.indexOf('custom_color_') === 0) {
                    if (!data.custom_colors) {
                        data.custom_colors = {};
                    }
                    var productKey = field.name.replace('custom_color_', '');
                    data.custom_colors[productKey] = field.value;
                } else {
                    data[field.name] = field.value;
                }
            });

            // Show loading state
            var $submitBtn = $form.find('button[type="submit"]');
            var originalText = $submitBtn.text();
            $submitBtn.prop('disabled', true).text('Saving...');

            $.ajax({
                url: MG_PATTERN_SHOWCASE_ADMIN.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        // Redirect to list view
                        window.location.href = 'admin.php?page=mg-pattern-showcases';
                    } else {
                        alert(response.data.message || 'Error saving showcase');
                        $submitBtn.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    alert('Error saving showcase');
                    $submitBtn.prop('disabled', false).text(originalText);
                }
            });
        },

        deleteShowcase: function(id) {
            if (!confirm(MG_PATTERN_SHOWCASE_ADMIN.strings.confirm_delete)) {
                return;
            }

            $.ajax({
                url: MG_PATTERN_SHOWCASE_ADMIN.ajax_url,
                type: 'POST',
                data: {
                    action: 'mg_delete_pattern_showcase',
                    nonce: MG_PATTERN_SHOWCASE_ADMIN.nonce,
                    id: id
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message || 'Error deleting showcase');
                    }
                },
                error: function() {
                    alert('Error deleting showcase');
                }
            });
        },

        generateMockups: function(id) {
            var $btn = $('.mg-generate-mockups[data-id="' + id + '"], .mg-generate-mockups-single[data-id="' + id + '"]');
            var originalText = $btn.text();

            $btn.prop('disabled', true).text(MG_PATTERN_SHOWCASE_ADMIN.strings.generating);

            $.ajax({
                url: MG_PATTERN_SHOWCASE_ADMIN.ajax_url,
                type: 'POST',
                data: {
                    action: 'mg_generate_showcase_mockups',
                    nonce: MG_PATTERN_SHOWCASE_ADMIN.nonce,
                    id: id
                },
                success: function(response) {
                    if (response.success) {
                        alert(MG_PATTERN_SHOWCASE_ADMIN.strings.generation_complete + '\n' +
                              'Generated: ' + Object.keys(response.data.mockups).length + ' mockups\n' +
                              (response.data.errors.length > 0 ? 'Errors: ' + response.data.errors.length : ''));
                        location.reload();
                    } else {
                        alert(MG_PATTERN_SHOWCASE_ADMIN.strings.generation_error + '\n' + response.data.message);
                        $btn.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    alert(MG_PATTERN_SHOWCASE_ADMIN.strings.generation_error);
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        },

        updateColorStrategyVisibility: function() {
            var strategy = $('input[name="color_strategy"]:checked').val();

            if (strategy === 'custom') {
                $('#mg-custom-colors-container').show();
                this.updateCustomColorsList();
            } else {
                $('#mg-custom-colors-container').hide();
            }
        },

        updateLayoutVisibility: function() {
            var layout = $('input[name="layout"]:checked').val();

            if (layout === 'grid') {
                $('#mg-columns-row').show();
            } else {
                $('#mg-columns-row').hide();
            }
        },

        updateCustomColorsList: function() {
            var self = this;
            var $container = $('#mg-custom-colors-list');
            var selectedProducts = [];

            $('input[name="product_types[]"]:checked').each(function() {
                selectedProducts.push($(this).val());
            });

            if (selectedProducts.length === 0) {
                $container.html('<p>Please select product types first.</p>');
                return;
            }

            // Get products data from global scope (injected by PHP)
            if (typeof products === 'undefined') {
                $container.html('<p>Product data not available.</p>');
                return;
            }

            $container.empty();

            $.each(selectedProducts, function(i, productKey) {
                if (!products[productKey]) {
                    return;
                }

                var product = products[productKey];
                var colors = product.colors || [];

                if (colors.length === 0) {
                    return;
                }

                var $row = $('<div class="mg-custom-color-row"></div>');
                $row.append('<label>' + product.label + ':</label>');

                var $select = $('<select name="custom_color_' + productKey + '" class="mg-color-select"></select>');

                $.each(colors, function(j, color) {
                    $select.append('<option value="' + color.slug + '">' + color.name + '</option>');
                });

                $row.append($select);
                $container.append($row);
            });
        }
    };

    $(document).ready(function() {
        PatternShowcaseAdmin.init();
    });

})(jQuery);
