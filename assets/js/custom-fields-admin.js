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

            // PNG-only media picker for linked custom-field products.
            $(document).on('click', '.mgcf-png-select', function(e) {
                e.preventDefault();
                if (typeof wp === 'undefined' || !wp.media) {
                    alert('A WordPress média könyvtár nem érhető el.');
                    return;
                }

                var button = $(this);
                var input = $(button.data('input'));
                var preview = $(button.data('preview'));
                var remove = button.siblings('.mgcf-png-remove');
                var frame = wp.media({
                    title: 'PNG minta kiválasztása',
                    button: { text: 'PNG használata' },
                    multiple: false,
                    // The library query is PNG-only; the select callback also
                    // validates the MIME/filename for defense in depth.
                    library: { type: 'image', mime: 'image/png' }
                });

                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    var mime = (attachment.mime || '').toLowerCase();
                    var filename = (attachment.filename || attachment.url || '').toLowerCase();
                    if (mime !== 'image/png' && !/\.png(?:$|\?)/i.test(filename)) {
                        alert('Csak PNG kép választható.');
                        return;
                    }
                    var imageUrl = attachment.url || '';
                    if (attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) {
                        imageUrl = attachment.sizes.thumbnail.url;
                    }
                    input.val(parseInt(attachment.id, 10) || 0);
                    preview.replaceWith('<img id="' + preview.attr('id') + '" class="mgcf-variant-png-preview" alt="" />');
                    preview = $(button.data('preview'));
                    preview.attr('src', imageUrl);
                    remove.show();
                });
                frame.open();
            });

            $(document).on('click', '.mgcf-png-remove', function(e) {
                e.preventDefault();
                var button = $(this);
                var input = $(button.data('input'));
                var preview = $(button.data('preview'));
                input.val('0');
                preview.replaceWith('<span id="' + preview.attr('id') + '" class="mgcf-no-image">—</span>');
                button.hide();
            });

            this.bindVariantAiImport();

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

        bindVariantAiImport: function() {
            var $form = $('#mgcf-variant-ai-import-form');
            if (!$form.length) {
                return;
            }
            var $toggle = $('#mgcf-variant-ai-toggle');
            var $controls = $('#mgcf-variant-ai-controls');
            var $fileInput = $('#mgcf-variant-ai-files');
            var $preview = $('#mgcf-variant-ai-preview');
            var $status = $('#mgcf-variant-ai-status');
            var $submit = $('#mgcf-variant-ai-submit');
            var options = [];
            var renderToken = 0;
            var previewUrls = [];

            try {
                options = JSON.parse($form.attr('data-options') || '[]');
            } catch (e) {
                options = [];
            }
            options = Array.isArray(options) ? options : [];

            function normalizeVariantSlug(value) {
                var text = (value === null || typeof value === 'undefined') ? '' : String(value);
                text = text.trim().toLowerCase();
                try {
                    text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
                } catch (e) {}
                return text.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
            }

            function basename(fileName) {
                return String(fileName || '').replace(/\\/g, '/').split('/').pop().replace(/\.[^.]+$/, '');
            }

            function isExtension(file, extension) {
                return new RegExp('\\.' + extension + '$', 'i').test(String(file && file.name || ''));
            }

            function makeFileMap(files) {
                var result = {};
                files.forEach(function(file) {
                    var key = basename(file.name).toLowerCase();
                    if (!key) {
                        key = '__empty__' + Object.keys(result).length;
                    }
                    if (!result[key]) {
                        result[key] = [];
                    }
                    result[key].push(file);
                });
                return result;
            }

            function matchTarget(payload) {
                if (!payload || typeof payload !== 'object' || !payload.categories || typeof payload.categories !== 'object') {
                    return { error: 'Hiányzik vagy hibás a categories objektum.' };
                }
                var categories = payload.categories;
                var matches = {};
                var fields = ['main', 'sub'];
                for (var i = 0; i < fields.length; i++) {
                    var key = fields[i];
                    if (Object.prototype.hasOwnProperty.call(categories, key) && categories[key] !== '' && typeof categories[key] !== 'string') {
                        return { error: 'A categories.' + key + ' mezőnek szövegnek kell lennie.' };
                    }
                    var value = typeof categories[key] === 'string' ? categories[key].trim() : '';
                    if (!value) {
                        continue;
                    }
                    var needle = normalizeVariantSlug(value);
                    var fieldMatches = {};
                    options.forEach(function(option) {
                        var labelSlug = normalizeVariantSlug(option.label);
                        var optionSlug = normalizeVariantSlug(option.slug);
                        if (needle && (needle === labelSlug || needle === optionSlug)) {
                            fieldMatches[option.slug] = option;
                        }
                    });
                    if (Object.keys(fieldMatches).length > 1) {
                        return { error: 'A categories.' + key + ' érték több választási értékre illeszkedik.' };
                    }
                    Object.keys(fieldMatches).forEach(function(slug) {
                        matches[slug] = fieldMatches[slug];
                    });
                }
                var slugs = Object.keys(matches);
                if (slugs.length > 1) {
                    return { error: 'A categories.main és categories.sub eltérő választási értéket azonosít.' };
                }
                if (!slugs.length) {
                    return { error: 'A categories.main/sub egyik értéke sem egyezik pontosan egy választási értékkel.' };
                }
                return { option: matches[slugs[0]] };
            }

            function setStatus(message, state) {
                $status.removeClass('is-warning is-error is-success');
                if (state) {
                    $status.addClass(state);
                }
                $status.text(message || '');
            }

            function clearPreviewUrls() {
                if (!window.URL || !window.URL.revokeObjectURL) {
                    previewUrls = [];
                    return;
                }
                previewUrls.forEach(function(url) {
                    window.URL.revokeObjectURL(url);
                });
                previewUrls = [];
            }

            function updateUi() {
                var $rows = $preview.find('tr.mgcf-variant-ai-row');
                var allValid = $rows.length > 0;
                var invalid = 0;
                var targetRows = {};
                $rows.each(function() {
                    var $row = $(this);
                    var parsedValid = $row.data('parsedValid') === true;
                    if (parsedValid) {
                        $row.data('valid', true).removeClass('is-error').addClass('is-success');
                        var targetSlug = String($row.data('targetSlug') || '');
                        if (targetSlug) {
                            if (!targetRows[targetSlug]) {
                                targetRows[targetSlug] = [];
                            }
                            targetRows[targetSlug].push($row);
                        }
                    }
                });
                Object.keys(targetRows).forEach(function(targetSlug) {
                    if (targetRows[targetSlug].length < 2) {
                        return;
                    }
                    targetRows[targetSlug].forEach(function($row) {
                        $row.data('valid', false).removeClass('is-success').addClass('is-error');
                        $row.find('.mgcf-variant-ai__row-state').text('Duplikált célérték – importálás blokkolva.');
                    });
                });
                $rows.each(function() {
                    if ($(this).data('valid') !== true) {
                        allValid = false;
                        invalid++;
                    }
                });
                var enabled = $toggle.is(':checked');
                $submit.prop('disabled', !(enabled && allValid));
                if (!$rows.length) {
                    setStatus('Válassz fájlokat az előnézethez.', null);
                } else if (invalid > 0) {
                    setStatus(invalid + ' sor hibás; az importálás blokkolva van.', 'is-error');
                } else if (!enabled) {
                    setStatus($rows.length + ' érvényes pár előnézete elkészült. Kapcsold be az AI/JSON módot az importáláshoz.', 'is-warning');
                } else {
                    setStatus($rows.length + ' érvényes PNG/JSON pár importálható.', 'is-success');
                }
            }

            function addRow(key, pngFiles, jsonFiles, token) {
                var $row = $('<tr class="mgcf-variant-ai-row"></tr>').data('valid', false);
                var pngName = pngFiles.length ? pngFiles.map(function(file) { return file.name; }).join(', ') : '—';
                var jsonName = jsonFiles.length ? jsonFiles.map(function(file) { return file.name; }).join(', ') : '—';
                var $pngCell = $('<td class="mgcf-variant-ai__file"></td>').text(pngName);
                var $jsonCell = $('<td class="mgcf-variant-ai__file"></td>').text(jsonName);
                var $targetCell = $('<td class="mgcf-variant-ai__target"></td>').text('—');
                var $stateCell = $('<td class="mgcf-variant-ai__row-state"></td>').text('Feldolgozás…');
                $row.append($pngCell).append($jsonCell).append($targetCell).append($stateCell);
                $preview.append($row);

                if (pngFiles.length !== 1 || jsonFiles.length !== 1) {
                    $stateCell.text(pngFiles.length !== 1 && jsonFiles.length !== 1 ? 'Hiányzó/duplikált PNG és JSON.' : (pngFiles.length !== 1 ? 'Hiányzó vagy duplikált PNG.' : 'Hiányzó vagy duplikált JSON.'));
                    $row.addClass('is-error');
                    return;
                }
                var png = pngFiles[0];
                var json = jsonFiles[0];
                if (!isExtension(png, 'png')) {
                    $stateCell.text('A fájl nem PNG.');
                    $row.addClass('is-error');
                    return;
                }
                if (!isExtension(json, 'json')) {
                    $stateCell.text('A fájl nem JSON.');
                    $row.addClass('is-error');
                    return;
                }
                if (window.URL && window.URL.createObjectURL) {
                    var previewUrl = window.URL.createObjectURL(png);
                    previewUrls.push(previewUrl);
                    $pngCell.prepend($('<img class="mgcf-variant-ai__thumb" alt="">').attr('src', previewUrl));
                }
                var reader = new FileReader();
                reader.onload = function() {
                    if (token !== renderToken) {
                        return;
                    }
                    var payload;
                    try {
                        payload = JSON.parse(String(reader.result || '').replace(/^\uFEFF/, ''));
                    } catch (e) {
                        $stateCell.text('Hibás JSON.');
                        $row.addClass('is-error');
                        updateUi();
                        return;
                    }
                    var result = matchTarget(payload);
                    if (result.error) {
                        $stateCell.text(result.error);
                        $row.addClass('is-error');
                        updateUi();
                        return;
                    }
                    $targetCell.text(result.option.label + ' (' + result.option.slug + ')');
                    $stateCell.text('Érvényes – importálható.');
                    $row.data('valid', true).data('parsedValid', true).data('targetSlug', result.option.slug).data('aiPayload', payload).addClass('is-success');
                    updateUi();
                };
                reader.onerror = function() {
                    $stateCell.text('A JSON nem olvasható.');
                    $row.addClass('is-error');
                    updateUi();
                };
                reader.readAsText(json);
            }

            function renderPreview() {
                renderToken++;
                var token = renderToken;
                clearPreviewUrls();
                var allFiles = Array.prototype.slice.call($fileInput[0].files || []);
                var jsonFiles = allFiles.filter(function(file) { return isExtension(file, 'json'); });
                // Unknown extensions remain in the PNG side so the preview
                // visibly reports the invalid file instead of silently hiding it.
                var pngFiles = allFiles.filter(function(file) { return !isExtension(file, 'json'); });
                $preview.empty();
                if (!pngFiles.length && !jsonFiles.length) {
                    $preview.append('<tr class="no-items"><td colspan="4">Még nincs kiválasztott fájl.</td></tr>');
                    updateUi();
                    return;
                }
                var pngMap = makeFileMap(pngFiles);
                var jsonMap = makeFileMap(jsonFiles);
                var keys = {};
                Object.keys(pngMap).forEach(function(key) { keys[key] = true; });
                Object.keys(jsonMap).forEach(function(key) { keys[key] = true; });
                Object.keys(keys).sort().forEach(function(key) {
                    addRow(key, pngMap[key] || [], jsonMap[key] || [], token);
                });
                updateUi();
            }

            $toggle.on('change', function() {
                $controls.toggle($(this).is(':checked'));
                updateUi();
            });
            $fileInput.on('change', renderPreview);
            $(window).on('beforeunload', clearPreviewUrls);
            $form.on('submit', function(e) {
                var $rows = $preview.find('tr.mgcf-variant-ai-row');
                var valid = $toggle.is(':checked') && $rows.length > 0;
                $rows.each(function() {
                    if ($(this).data('valid') !== true) {
                        valid = false;
                    }
                });
                if (!valid) {
                    e.preventDefault();
                    setStatus('Az importálás csak bekapcsolt AI módban és hibátlan előnézettel indítható.', 'is-error');
                }
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
