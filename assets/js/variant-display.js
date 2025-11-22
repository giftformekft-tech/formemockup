(function($){
    function VariantDisplay($form, config) {
        this.$form = $form;
        this.config = config;
        this.$typeSelect = $form.find('select[name="attribute_pa_termektipus"]');
        this.$colorSelect = $form.find('select[name="attribute_pa_szin"]');
        this.$sizeSelect = $form.find('select[name="attribute_meret"]');
        this.$variantWrapper = null;
        this.state = {
            type: '',
            color: '',
            size: ''
        };
        this.preview = {
            $button: null,
            $modal: null,
            $backdrop: null,
            $content: null,
            $watermark: null,
            $canvas: null,
            $fallback: null,
            $close: null,
            fallbackImage: '',
            activeVariationId: 0,
            useCanvas: false,
            renderTimer: null,
            renderQueued: false,
            pendingPattern: '',
            pendingColor: '',
            pendingWatermark: '',
            loadFailed: false,
            failedPattern: ''
        };
        this.isReady = false;
        this.syncing = {
            type: false,
            color: false,
            size: false
        };
        this.sizeChart = {
            $link: null,
            $modal: null,
            $title: null,
            $body: null,
            $close: null
        };
        this.descriptionTargets = [];
        this.$colorLabelValue = $();
        this.init();
    }

    VariantDisplay.prototype.getText = function(key, fallback) {
        if (this.config && this.config.text && typeof this.config.text[key] !== 'undefined') {
            return this.config.text[key];
        }
        return fallback;
    };

    VariantDisplay.prototype.supportsCanvas = function() {
        if (typeof document === 'undefined') {
            return false;
        }
        try {
            var canvas = document.createElement('canvas');
            return !!(canvas && canvas.getContext && canvas.getContext('2d'));
        } catch (err) {
            return false;
        }
    };

    VariantDisplay.prototype.init = function() {
        if (!this.$typeSelect.length || !this.$colorSelect.length || !this.$sizeSelect.length) {
            return;
        }

        this.buildLayout();
        this.captureDescriptionTargets();
        this.bindEvents();
        this.createPatternPreview();
        this.initialSync();
    };

    VariantDisplay.prototype.markReady = function() {
        if (this.isReady) {
            return;
        }
        this.isReady = true;
        this.relocateCustomFields();
        this.$form.addClass('mg-variant-form--enhanced');
        if (typeof document !== 'undefined' && document.documentElement) {
            document.documentElement.classList.remove('mg-variant-preparing');
            document.documentElement.classList.remove('mg-variant-preload');
            document.documentElement.classList.remove('mg-variant-fallback');
            document.documentElement.classList.add('mg-variant-ready');
        }
        if (typeof window !== 'undefined' && window.__mgVariantPreloadCleanup) {
            window.clearTimeout(window.__mgVariantPreloadCleanup);
            window.__mgVariantPreloadCleanup = null;
        }
        var detail = {
            form: (this.$form && this.$form.length) ? this.$form[0] : null
        };
        if (typeof document !== 'undefined') {
            var nativeEvent;
            if (typeof window !== 'undefined' && typeof window.CustomEvent === 'function') {
                nativeEvent = new CustomEvent('mgVariantReady', { detail: detail });
            } else if (document.createEvent) {
                nativeEvent = document.createEvent('CustomEvent');
                if (nativeEvent && nativeEvent.initCustomEvent) {
                    nativeEvent.initCustomEvent('mgVariantReady', true, true, detail);
                }
            }
            if (nativeEvent) {
                document.dispatchEvent(nativeEvent);
            }
        }
        if (typeof jQuery !== 'undefined' && jQuery && jQuery(document)) {
            jQuery(document).trigger('mgVariantReady', [this.$form]);
        }
    };

    VariantDisplay.prototype.buildLayout = function() {
        var wrapper = $('<div class="mg-variant-display" />');
        this.$variantWrapper = wrapper;
        var typeSection = $('<div class="mg-variant-section mg-variant-section--type" />');
        typeSection.append($('<div class="mg-variant-section__label" />').text(this.getText('type', 'Terméktípus')));
        this.$typeOptions = $('<div class="mg-variant-options" role="radiogroup" />');
        typeSection.append(this.$typeOptions);
        wrapper.append(typeSection);

        var colorSection = $('<div class="mg-variant-section mg-variant-section--color" />');
        var colorLabelText = this.getText('color', 'Szín');
        var $colorLabel = $('<div class="mg-variant-section__label" />');
        $colorLabel.append($('<span class="mg-variant-section__label-text" />').text(colorLabelText + ': '));
        this.$colorLabelValue = $('<span class="mg-variant-section__label-value" />').text('""');
        $colorLabel.append(this.$colorLabelValue);
        colorSection.append($colorLabel);
        this.$colorOptions = $('<div class="mg-variant-options" role="radiogroup" />');
        colorSection.append(this.$colorOptions);
        wrapper.append(colorSection);

        var sizeSection = $('<div class="mg-variant-section mg-variant-section--size" />');
        sizeSection.append($('<div class="mg-variant-section__label" />').text(this.getText('size', 'Méret')));
        this.$sizeOptions = $('<div class="mg-variant-options" role="radiogroup" />');
        sizeSection.append(this.$sizeOptions);
        this.sizeChart.$link = $('<button type="button" class="mg-size-chart-link" aria-disabled="true" aria-expanded="false" />').text(this.getText('sizeChartLink', '📏 Mérettáblázat megnyitása'));
        sizeSection.append(this.sizeChart.$link);
        wrapper.append(sizeSection);

        this.$form.find('.variations').addClass('mg-variant-hidden').before(wrapper);

        this.createSizeChartModal();
        this.relocateCustomFields();

        var typeOrder = (this.config.order && this.config.order.types && this.config.order.types.length) ? this.config.order.types : Object.keys(this.config.types);
        var self = this;
        $.each(typeOrder, function(_, typeSlug){
            var meta = self.config.types[typeSlug];
            if (!meta) {
                return;
            }
            var $btn = $('<button type="button" class="mg-variant-option mg-variant-option--type" aria-pressed="false" />');
            $btn.attr('data-value', typeSlug);
            $btn.append($('<span class="mg-variant-option__label" />').text(meta.label || typeSlug));
            self.$typeOptions.append($btn);
        });
    };

    VariantDisplay.prototype.getCustomFieldOrder = function($block) {
        if (!$block || !$block.length) {
            return 0;
        }
        var order = parseInt($block.attr('data-mgcf-order'), 10);
        return isNaN(order) ? 0 : order;
    };

    VariantDisplay.prototype.findPlacementBlocks = function(placement) {
        if (!this.$variantWrapper || !this.$variantWrapper.length) {
            return $();
        }
        return this.$variantWrapper.children('.mg-custom-fields[data-mgcf-placement="' + placement + '"]');
    };

    VariantDisplay.prototype.insertBeforeHigherOrder = function($block, $collection, order) {
        var self = this;
        var inserted = false;
        $collection.each(function(){
            var $candidate = $(this);
            var candidateOrder = self.getCustomFieldOrder($candidate);
            if (candidateOrder > order) {
                $block.insertBefore($candidate);
                inserted = true;
                return false;
            }
        });
        return inserted;
    };

    VariantDisplay.prototype.insertAfterSection = function($block, placement, selector, order) {
        if (!this.$variantWrapper || !this.$variantWrapper.length) {
            return false;
        }
        var $existing = this.findPlacementBlocks(placement);
        if ($existing.length && this.insertBeforeHigherOrder($block, $existing, order)) {
            return true;
        }
        if ($existing.length) {
            $block.insertAfter($existing.last());
            return true;
        }
        var $anchor = this.$variantWrapper.children(selector).last();
        if ($anchor.length) {
            $block.insertAfter($anchor);
            return true;
        }
        return false;
    };

    VariantDisplay.prototype.applyCustomFieldSectionClasses = function($block, placement) {
        if (!$block || !$block.length) {
            return;
        }
        $block.addClass('mg-variant-section mg-variant-section--custom');
        var sanitized = (placement || '').toString().toLowerCase().replace(/[^a-z0-9_-]/g, '');
        if (sanitized) {
            $block.addClass('mg-variant-section--custom-' + sanitized);
        }
        if (placement === 'variant_top') {
            $block.addClass('mg-variant-section--custom-top');
        }
        if (placement === 'variant_bottom') {
            $block.addClass('mg-variant-section--custom-bottom');
        }
        var $heading = $block.find('.mg-custom-fields__title').first();
        if ($heading.length) {
            $heading.addClass('mg-variant-section__label');
        }
    };

    VariantDisplay.prototype.insertCustomFieldBlock = function($block, placement) {
        if (!$block || !$block.length || !this.$variantWrapper || !this.$variantWrapper.length) {
            return;
        }
        var order = this.getCustomFieldOrder($block);
        var inserted = false;
        var $existing;

        switch (placement) {
            case 'variant_top':
                $existing = this.findPlacementBlocks('variant_top');
                if ($existing.length) {
                    inserted = this.insertBeforeHigherOrder($block, $existing, order);
                    if (!inserted) {
                        $block.insertAfter($existing.last());
                        inserted = true;
                    }
                }
                if (!inserted) {
                    var $firstSection = this.$variantWrapper.children('.mg-variant-section').first();
                    if ($firstSection.length) {
                        $block.insertBefore($firstSection);
                    } else {
                        this.$variantWrapper.prepend($block);
                    }
                    inserted = true;
                }
                break;
            case 'after_type':
                inserted = this.insertAfterSection($block, 'after_type', '.mg-variant-section--type', order);
                break;
            case 'after_color':
                inserted = this.insertAfterSection($block, 'after_color', '.mg-variant-section--color', order);
                break;
            case 'after_size':
                inserted = this.insertAfterSection($block, 'after_size', '.mg-variant-section--size', order);
                break;
            case 'variant_bottom':
            default:
                $existing = this.findPlacementBlocks('variant_bottom');
                if ($existing.length && this.insertBeforeHigherOrder($block, $existing, order)) {
                    inserted = true;
                    break;
                }
                if ($existing.length) {
                    $block.insertAfter($existing.last());
                    inserted = true;
                    break;
                }
                this.$variantWrapper.append($block);
                inserted = true;
                break;
        }

        if (!inserted) {
            this.$variantWrapper.append($block);
        }
    };

    VariantDisplay.prototype.relocateCustomFields = function() {
        if (!this.$form || !this.$form.length) {
            return;
        }
        if (!this.$variantWrapper || !this.$variantWrapper.length) {
            return;
        }
        var $blocks = this.$form.find('.mg-custom-fields').filter(function(){
            return $(this).attr('data-mgcf-relocated') !== '1';
        });
        if (!$blocks.length) {
            return;
        }
        var blocks = $blocks.toArray();
        var self = this;
        blocks.sort(function(a, b){
            var $a = $(a);
            var $b = $(b);
            var orderA = self.getCustomFieldOrder($a);
            var orderB = self.getCustomFieldOrder($b);
            if (orderA === orderB) {
                var idA = ($a.attr('data-field-id') || '').toString();
                var idB = ($b.attr('data-field-id') || '').toString();
                return idA.localeCompare(idB);
            }
            return orderA - orderB;
        });

        blocks.forEach(function(block){
            var $block = $(block);
            var placement = ($block.attr('data-mgcf-placement') || 'variant_bottom').toString();
            self.applyCustomFieldSectionClasses($block, placement);
            self.insertCustomFieldBlock($block, placement);
            $block.attr('data-mgcf-relocated', '1');
        });
    };

    VariantDisplay.prototype.bindEvents = function() {
        var self = this;

        this.$typeOptions.on('click', '.mg-variant-option', function(e){
            e.preventDefault();
            var value = $(this).attr('data-value') || '';
            if ($(this).hasClass('is-disabled')) {
                return;
            }
            self.setType(value, true);
        });

        this.$colorOptions.on('click', '.mg-variant-option', function(e){
            e.preventDefault();
            if (!self.state.type) {
                return;
            }
            var value = $(this).attr('data-value') || '';
            if ($(this).hasClass('is-disabled')) {
                return;
            }
            self.setColor(value, true);
        });

        this.$colorOptions.on('mouseenter', '.mg-variant-option', function(){
            if (!self.state.type || $(this).hasClass('is-disabled')) {
                return;
            }
            var label = $(this).attr('data-label') || '';
            self.setColorLabelText(label);
        });

        this.$colorOptions.on('mouseleave', '.mg-variant-option', function(){
            self.refreshColorLabel();
        });

        this.$colorOptions.on('focus', '.mg-variant-option', function(){
            if (!self.state.type || $(this).hasClass('is-disabled')) {
                return;
            }
            var label = $(this).attr('data-label') || '';
            self.setColorLabelText(label);
        });

        this.$colorOptions.on('blur', '.mg-variant-option', function(){
            self.refreshColorLabel();
        });

        this.$sizeOptions.on('click', '.mg-variant-option', function(e){
            e.preventDefault();
            if (!self.state.type || !self.state.color) {
                return;
            }
            var value = $(this).attr('data-value') || '';
            if ($(this).hasClass('is-disabled')) {
                return;
            }
            self.setSize(value, true);
        });

        if (this.sizeChart.$link) {
            this.sizeChart.$link.on('click', function(e){
                e.preventDefault();
                if ($(this).hasClass('is-disabled') || $(this).prop('disabled')) {
                    return;
                }
                self.showSizeChart();
            });
        }

        this.$typeSelect.on('change', function(){
            if (self.syncing.type) {
                return;
            }
            self.state.type = $(this).val() || '';
            self.updateTypeUI(true);
        });

        this.$colorSelect.on('change', function(){
            if (self.syncing.color) {
                return;
            }
            self.state.color = $(this).val() || '';
            self.updateColorUI(true);
        });

        this.$sizeSelect.on('change', function(){
            if (self.syncing.size) {
                return;
            }
            self.state.size = $(this).val() || '';
            self.updateSizeUI();
        });

        this.$form.on('click', '.reset_variations', function(){
            setTimeout(function(){
                self.syncFromSelects(false);
            }, 20);
        });

        this.$form.on('reset_data', function(){
            setTimeout(function(){
                self.syncFromSelects(false);
            }, 20);
        });

        this.$form.on('found_variation', function(event, variation){
            self.handleFoundVariation(variation);
        });

        this.$form.on('reset_data hide_variation', function(){
            self.handleVariationReset();
        });
    };

    VariantDisplay.prototype.initialSync = function() {
        this.syncFromSelects(true);
        this.markReady();
    };

    VariantDisplay.prototype.syncFromSelects = function(useDefaults) {
        var typeVal = this.$typeSelect.val() || (useDefaults && this.config.default ? this.config.default.type : '');
        var colorVal = this.$colorSelect.val() || (useDefaults && this.config.default ? this.config.default.color : '');
        var sizeVal = this.$sizeSelect.val() || (useDefaults && this.config.default ? this.config.default.size : '');

        this.setType(typeVal, false);
        this.setColor(colorVal, false);
        this.setSize(sizeVal, false);

        this.updateTypeUI(false);
        this.refreshPreviewState();
    };

    VariantDisplay.prototype.setType = function(value, triggerChange) {
        value = value || '';
        if (this.state.type === value) {
            if (triggerChange) {
                this.syncing.type = true;
                this.$typeSelect.trigger('change');
                this.syncing.type = false;
            }
            this.updateTypeUI(triggerChange !== false);
            return;
        }
        this.state.type = value;
        this.syncing.type = true;
        this.$typeSelect.val(value);
        if (triggerChange !== false) {
            this.$typeSelect.trigger('change');
        }
        this.syncing.type = false;
        if (!value) {
            this.setColor('', triggerChange);
        }
        if (this.sizeChart.$modal && this.sizeChart.$modal.hasClass('is-open')) {
            this.hideSizeChart();
        }
        this.updateTypeUI(triggerChange !== false);
    };

    VariantDisplay.prototype.setColor = function(value, triggerChange) {
        value = value || '';
        if (!this.state.type) {
            value = '';
        }
        if (this.state.color === value) {
            if (triggerChange) {
                this.syncing.color = true;
                this.$colorSelect.trigger('change');
                this.syncing.color = false;
            }
            this.updateColorUI(triggerChange !== false);
            return;
        }
        this.state.color = value;
        this.syncing.color = true;
        this.$colorSelect.val(value);
        if (triggerChange !== false) {
            this.$colorSelect.trigger('change');
        }
        this.syncing.color = false;
        if (!value) {
            this.setSize('', triggerChange);
        }
        this.updateColorUI(triggerChange !== false);
    };

    VariantDisplay.prototype.setSize = function(value, triggerChange) {
        value = value || '';
        if (!this.state.type || !this.state.color) {
            value = '';
        }
        if (this.state.size === value) {
            if (triggerChange) {
                this.syncing.size = true;
                this.$sizeSelect.trigger('change');
                this.syncing.size = false;
            }
            this.updateSizeUI();
            return;
        }
        this.state.size = value;
        this.syncing.size = true;
        this.$sizeSelect.val(value);
        if (triggerChange !== false) {
            this.$sizeSelect.trigger('change');
        }
        this.syncing.size = false;
        this.updateSizeUI();
        this.refreshPreviewState();
    };

    VariantDisplay.prototype.updateTypeUI = function(shouldTriggerChildren) {
        var self = this;
        this.$typeOptions.find('.mg-variant-option').each(function(){
            var $btn = $(this);
            var value = $btn.attr('data-value') || '';
            var isActive = value === self.state.type;
            $btn.toggleClass('is-selected', isActive);
            $btn.attr('aria-pressed', isActive ? 'true' : 'false');
        });
        this.rebuildColorOptions(shouldTriggerChildren !== false);
        this.updateSizeChartLink();
        this.updateDescription();
    };

    VariantDisplay.prototype.updateColorUI = function(shouldTriggerSizes) {
        var self = this;
        this.$colorOptions.find('.mg-variant-option').each(function(){
            var $btn = $(this);
            var value = $btn.attr('data-value') || '';
            var isActive = value === self.state.color;
            $btn.toggleClass('is-selected', isActive);
            $btn.attr('aria-pressed', isActive ? 'true' : 'false');
        });
        this.rebuildSizeOptions(shouldTriggerSizes !== false);
        this.refreshColorLabel();
        this.refreshPreviewState();
    };

    VariantDisplay.prototype.updateSizeUI = function() {
        var self = this;
        this.$sizeOptions.find('.mg-variant-option').each(function(){
            var $btn = $(this);
            var value = $btn.attr('data-value') || '';
            var isActive = value === self.state.size;
            $btn.toggleClass('is-selected', isActive);
            $btn.attr('aria-pressed', isActive ? 'true' : 'false');
        });
    };

    VariantDisplay.prototype.setColorLabelText = function(label) {
        if (!this.$colorLabelValue || !this.$colorLabelValue.length) {
            return;
        }
        var text = label ? '"' + label + '"' : '""';
        this.$colorLabelValue.text(text);
    };

    VariantDisplay.prototype.refreshColorLabel = function() {
        if (!this.$colorLabelValue || !this.$colorLabelValue.length) {
            return;
        }
        var label = '';
        if (this.state.type && this.state.color && this.config.types && this.config.types[this.state.type]) {
            var typeMeta = this.config.types[this.state.type];
            if (typeMeta.colors && typeMeta.colors[this.state.color]) {
                var colorMeta = typeMeta.colors[this.state.color];
                label = colorMeta.label || this.state.color;
            }
        }
        this.setColorLabelText(label);
    };

    VariantDisplay.prototype.rebuildColorOptions = function(shouldTriggerSizeSync) {
        this.$colorOptions.empty();
        if (!this.state.type || !this.config.types[this.state.type]) {
            this.$colorOptions.append($('<div class="mg-variant-placeholder" />').text(this.getText('chooseTypeFirst', 'Először válassz terméktípust.')));
            this.setColor('', shouldTriggerSizeSync);
            this.rebuildSizeOptions(shouldTriggerSizeSync);
            this.refreshPreviewState();
            return;
        }

        var typeMeta = this.config.types[this.state.type];
        var colors = typeMeta.colors || {};
        var order = (typeMeta.color_order && typeMeta.color_order.length) ? typeMeta.color_order : Object.keys(colors);
        var self = this;
        var fallbackColor = '';

        if (!order.length) {
            this.$colorOptions.append($('<div class="mg-variant-placeholder" />').text(this.getText('noColors', 'Ehhez a terméktípushoz nincs elérhető szín.')));
            this.setColor('', shouldTriggerSizeSync);
            this.rebuildSizeOptions(shouldTriggerSizeSync);
            this.refreshPreviewState();
            return;
        }

        $.each(order, function(_, colorSlug){
            var meta = colors[colorSlug];
            if (!meta) {
                return;
            }
            var availableSizes = self.getAvailableSizes(self.state.type, colorSlug);
            var $btn = $('<button type="button" class="mg-variant-option mg-variant-option--color" aria-pressed="false" />');
            var colorLabel = meta.label || colorSlug;
            $btn.attr('data-value', colorSlug);
            $btn.attr('data-label', colorLabel);
            $btn.attr('aria-label', colorLabel);
            $btn.attr('title', colorLabel);
            var $swatch = $('<span class="mg-variant-swatch" />');
            if (meta.swatch) {
                $swatch.css('background-color', meta.swatch);
            }
            $btn.append($swatch);
            $btn.append($('<span class="mg-variant-option__label mg-variant-option__label--sr-only" />').text(colorLabel));
            if (!availableSizes.length) {
                $btn.addClass('is-disabled').attr('aria-disabled', 'true');
            } else if (!fallbackColor) {
                fallbackColor = colorSlug;
            }
            self.$colorOptions.append($btn);
        });

        var currentValid = !!(this.state.color && colors[this.state.color]);
        if (currentValid) {
            currentValid = this.getAvailableSizes(this.state.type, this.state.color).length > 0;
        }

        if (!currentValid) {
            if (fallbackColor) {
                this.setColor(fallbackColor, shouldTriggerSizeSync);
            } else {
                this.setColor('', shouldTriggerSizeSync);
            }
            this.refreshPreviewState();
            return;
        }

        this.updateColorUI(shouldTriggerSizeSync);
    };

    VariantDisplay.prototype.rebuildSizeOptions = function(shouldTriggerSizeChange) {
        this.$sizeOptions.empty();
        if (!this.state.type) {
            this.$sizeOptions.append($('<div class="mg-variant-placeholder" />').text(this.getText('chooseTypeFirst', 'Először válassz terméktípust.')));
            this.setSize('', shouldTriggerSizeChange);
            this.updateSizeChartLink();
            this.refreshPreviewState();
            return;
        }
        if (!this.state.color || !this.config.types[this.state.type] || !this.config.types[this.state.type].colors[this.state.color]) {
            this.$sizeOptions.append($('<div class="mg-variant-placeholder" />').text(this.getText('chooseColorFirst', 'Először válassz színt.')));
            this.setSize('', shouldTriggerSizeChange);
            this.updateSizeChartLink();
            this.refreshPreviewState();
            return;
        }

        var colorMeta = this.config.types[this.state.type].colors[this.state.color];
        var sizes = colorMeta.sizes || [];
        var self = this;
        var fallbackSize = '';

        if (!sizes.length) {
            this.$sizeOptions.append($('<div class="mg-variant-placeholder" />').text(this.getText('noSizes', 'Ehhez a kombinációhoz nincs elérhető méret.')));
            this.setSize('', shouldTriggerSizeChange);
            this.updateSizeChartLink();
            return;
        }

        $.each(sizes, function(_, sizeValue){
            var $btn = $('<button type="button" class="mg-variant-option mg-variant-option--size" aria-pressed="false" />');
            $btn.attr('data-value', sizeValue);
            $btn.append($('<span class="mg-variant-option__label" />').text(sizeValue));
            var availability = self.getAvailability(self.state.type, self.state.color, sizeValue);
            if (!availability.in_stock && !availability.is_purchasable) {
                $btn.addClass('is-disabled').attr('aria-disabled', 'true');
            } else if (!fallbackSize) {
                fallbackSize = sizeValue;
            }
            self.$sizeOptions.append($btn);
        });

        var currentValidSize = this.state.size && sizes.indexOf(this.state.size) !== -1;
        if (currentValidSize) {
            var currentAvailability = this.getAvailability(this.state.type, this.state.color, this.state.size);
            if (!currentAvailability.in_stock && !currentAvailability.is_purchasable) {
                currentValidSize = false;
            }
        }

        if (!currentValidSize) {
            if (fallbackSize) {
                this.setSize(fallbackSize, shouldTriggerSizeChange);
            } else {
                this.setSize('', shouldTriggerSizeChange);
            }
        }

        this.updateSizeUI();
        this.updateSizeChartLink();
    };

    VariantDisplay.prototype.getAvailability = function(type, color, size) {
        var availability = this.config.availability || {};
        if (!availability[type] || !availability[type][color] || !availability[type][color][size]) {
            return { in_stock: true, is_purchasable: true };
        }
        var data = availability[type][color][size];
        return {
            in_stock: !!data.in_stock,
            is_purchasable: typeof data.is_purchasable === 'undefined' ? true : !!data.is_purchasable
        };
    };

    VariantDisplay.prototype.getAvailableSizes = function(type, color) {
        if (!this.config.types[type] || !this.config.types[type].colors[color]) {
            return [];
        }
        var sizes = this.config.types[type].colors[color].sizes || [];
        var availability = this.config.availability || {};
        if (!availability[type] || !availability[type][color]) {
            return sizes.slice();
        }
        var out = [];
        for (var i = 0; i < sizes.length; i++) {
            var size = sizes[i];
            var entry = availability[type][color][size];
            if (!entry || entry.in_stock || entry.is_purchasable) {
                out.push(size);
            }
        }
        return out;
    };

    VariantDisplay.prototype.getVisualDefaults = function() {
        return (this.config && this.config.visuals && this.config.visuals.defaults) ? this.config.visuals.defaults : {};
    };

    VariantDisplay.prototype.getVariationColor = function(variationId, typeSlug, colorSlug) {
        var visuals = this.config && this.config.visuals ? this.config.visuals : {};
        if (variationId && visuals.variationColors && visuals.variationColors[variationId]) {
            return visuals.variationColors[variationId];
        }
        if (typeSlug && colorSlug && this.config && this.config.types && this.config.types[typeSlug] && this.config.types[typeSlug].colors && this.config.types[typeSlug].colors[colorSlug]) {
            return this.config.types[typeSlug].colors[colorSlug].swatch || '';
        }
        var defaults = this.getVisualDefaults();
        if (defaults && defaults.color) {
            return defaults.color;
        }
        return '';
    };

    VariantDisplay.prototype.getVariationPattern = function(variationId) {
        var visuals = this.config && this.config.visuals ? this.config.visuals : {};
        if (variationId && visuals.variationPatterns && visuals.variationPatterns[variationId]) {
            return visuals.variationPatterns[variationId];
        }
        var defaults = this.getVisualDefaults();
        if (defaults && defaults.pattern) {
            return defaults.pattern;
        }
        return '';
    };

    VariantDisplay.prototype.refreshPreviewState = function() {
        if (!this.preview || !this.preview.$modal) {
            return;
        }

        var variationId = this.preview.activeVariationId || 0;
        var colorHex = this.getVariationColor(variationId, this.state.type, this.state.color);
        var pattern = this.getVariationPattern(variationId);
        var hasPattern = !!pattern;
        var hasColor = !!colorHex;
        var watermarkText = this.getText('previewWatermark', 'www.forme.hu');

        var patternFailed = this.preview.loadFailed && this.preview.failedPattern === pattern;
        var patternReady = hasPattern && !patternFailed;

        var usingCanvas = this.preview.useCanvas && patternReady;

        if (this.preview.$content) {
            this.preview.$content.css('background-color', hasColor ? colorHex : '');
        }

        if (this.preview.$watermark) {
            this.applyPreviewWatermark(patternReady, colorHex, watermarkText);
        }

        if (usingCanvas) {
            this.queueCanvasRender(pattern, colorHex, watermarkText);
            if (this.preview.$canvas) {
                this.preview.$canvas.show();
            }
        } else if (this.preview.$canvas) {
            this.preview.$canvas.hide();
        }

        if (this.preview.$fallback) {
            var message = '';
            if (!hasPattern) {
                message = this.getText('previewUnavailable', 'Ehhez a variációhoz nem érhető el minta.');
            } else if (!hasColor) {
                message = this.getText('previewNoColor', 'Ehhez a variációhoz nem található háttérszín.');
            } else if (patternFailed) {
                message = this.getText('previewUnavailable', 'A minta előnézet nem tölthető be.');
            } else if (!this.preview.useCanvas) {
                message = this.getText('previewUnavailable', 'A böngésző nem támogatja a vízjeles előnézetet.');
            }
            this.preview.$fallback.text(message).toggle(message !== '');
        }

        if (this.preview.$button) {
            this.preview.$button.toggleClass('is-disabled', !patternReady);
            this.preview.$button.attr('aria-disabled', patternReady ? 'false' : 'true');
        }
    };

    VariantDisplay.prototype.createPatternPreview = function() {
        if (this.preview.$button) {
            return;
        }

        var $button = $('<button type="button" class="mg-pattern-preview__button" />').text(this.getText('previewButton', 'Minta nagyban'));
        this.preview.$button = $button;

        var $buttonWrap = $('<div class="mg-pattern-preview__button-wrap" />').append($button);

        var $modal = $('<div class="mg-pattern-preview" aria-hidden="true" role="dialog" />');
        var $backdrop = $('<div class="mg-pattern-preview__backdrop" />');
        var $content = $('<div class="mg-pattern-preview__content" />');
        var $watermark = $('<div class="mg-pattern-preview__watermark" aria-hidden="true" />');
        var $close = $('<button type="button" class="mg-pattern-preview__close" aria-label="' + this.getText('previewClose', 'Bezárás') + '">×</button>');
        var $body = $('<div class="mg-pattern-preview__body" />');
        var $canvas = $('<canvas class="mg-pattern-preview__canvas" aria-hidden="true"></canvas>');
        var $fallback = $('<div class="mg-pattern-preview__fallback" />');

        $body.append($canvas).append($fallback).append($watermark);
        $content.append($close).append($body);
        $modal.append($backdrop).append($content);

        $('body').append($modal);

        this.preview.$modal = $modal;
        this.preview.$backdrop = $backdrop;
        this.preview.$content = $content;
        this.preview.$watermark = $watermark;
        this.preview.$canvas = $canvas;
        this.preview.$fallback = $fallback;
        this.preview.$close = $close;
        this.preview.useCanvas = this.supportsCanvas();

        var self = this;
        $button.on('click', function(){
            self.showPatternPreview();
        });

        $close.on('click', function(){
            self.hidePatternPreview();
        });

        $modal.on('click', function(event){
            if ($(event.target).is($modal) || $(event.target).is($backdrop)) {
                self.hidePatternPreview();
            }
        });

        $(document).on('keydown.mgPatternPreview', function(event){
            if (event.key === 'Escape' && self.preview.$modal && self.preview.$modal.hasClass('is-open')) {
                self.hidePatternPreview();
            }
        });

        $content.on('contextmenu', function(event){
            event.preventDefault();
        });

        $content.on('dragstart selectstart', function(event){
            event.preventDefault();
        });

        var $typeSection = this.$variantWrapper ? this.$variantWrapper.find('.mg-variant-section--type').first() : $();
        if ($typeSection && $typeSection.length) {
            $typeSection.before($buttonWrap);
        } else {
            var $galleryAnchor = $('.woocommerce-product-gallery, .woocommerce-product-gallery__wrapper, .product .images').first();
            if (!$galleryAnchor.length) {
                $galleryAnchor = this.$variantWrapper || this.$form;
            }
            if ($galleryAnchor && $galleryAnchor.length) {
                $galleryAnchor.after($buttonWrap);
            }
        }

        this.refreshPreviewState();
    };

    VariantDisplay.prototype.applyPreviewWatermark = function(hasPattern, colorHex, watermarkText) {
        if (!this.preview.$watermark) {
            return;
        }

        var safeText = watermarkText || this.getText('previewWatermark', 'www.forme.hu');
        if (!hasPattern) {
            this.preview.$watermark.removeAttr('style').removeClass('is-visible');
            return;
        }

        var fillColor = '#0f172a';
        if (colorHex && typeof colorHex === 'string') {
            fillColor = colorHex;
        }

        var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="360" height="260" viewBox="0 0 360 260">' +
            '<rect width="360" height="260" fill="' + fillColor + '" fill-opacity="0" />' +
            '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="rgba(255,255,255,0.32)" font-family="sans-serif" font-size="26" font-weight="700" transform="rotate(-24 180 130)">' +
            safeText +
            '</text>' +
            '</svg>';

        var dataUrl = 'url("data:image/svg+xml,' + encodeURIComponent(svg) + '")';

        this.preview.$watermark
            .addClass('is-visible')
            .text(safeText)
            .css({
                'background-image': dataUrl,
                'background-size': '240px 180px'
            });
    };

    VariantDisplay.prototype.queueCanvasRender = function(patternUrl, colorHex, watermarkText) {
        if (!this.preview.useCanvas || !this.preview.$canvas || !this.preview.$canvas.length) {
            return;
        }

        this.preview.pendingPattern = patternUrl || '';
        this.preview.pendingColor = colorHex || '#0f172a';
        this.preview.pendingWatermark = watermarkText || this.getText('previewWatermark', 'www.forme.hu');
        this.preview.loadFailed = false;
        this.preview.failedPattern = '';

        if (this.preview.renderQueued) {
            return;
        }

        this.preview.renderQueued = true;
        var self = this;
        var delay = 120;
        this.preview.renderTimer = window.setTimeout(function(){
            self.preview.renderQueued = false;
            self.renderCanvasPattern();
        }, delay);
    };

    VariantDisplay.prototype.paintCanvasWatermark = function(ctx, width, height, text) {
        if (!ctx || !text) {
            return;
        }
        ctx.save();
        ctx.globalAlpha = 0.24;
        ctx.fillStyle = '#ffffff';
        ctx.strokeStyle = 'rgba(15, 23, 42, 0.18)';
        ctx.lineWidth = 0.75;
        ctx.font = '600 26px sans-serif';
        ctx.translate(width / 2, height / 2);
        ctx.rotate(-Math.PI / 8);
        ctx.translate(-width / 2, -height / 2);
        var stepX = 180;
        var stepY = 140;
        for (var y = -stepY; y < height + stepY; y += stepY) {
            for (var x = -stepX; x < width + stepX; x += stepX) {
                ctx.fillText(text, x, y);
                ctx.strokeText(text, x, y);
            }
        }
        ctx.restore();
    };

    VariantDisplay.prototype.renderCanvasPattern = function() {
        if (!this.preview.useCanvas || !this.preview.$canvas || !this.preview.$canvas.length) {
            return;
        }

        var canvas = this.preview.$canvas[0];
        if (!canvas || typeof canvas.getContext !== 'function') {
            return;
        }

        var ctx = canvas.getContext('2d');
        if (!ctx) {
            return;
        }

        var patternUrl = this.preview.pendingPattern;
        var colorHex = this.preview.pendingColor || '#0f172a';
        var watermarkText = this.preview.pendingWatermark || this.getText('previewWatermark', 'www.forme.hu');

        if (!patternUrl) {
            ctx.clearRect(0, 0, canvas.width || 0, canvas.height || 0);
            return;
        }

        var img = new Image();
        img.crossOrigin = 'anonymous';
        var self = this;

        img.onload = function() {
            var naturalWidth = img.naturalWidth || img.width || 1024;
            var naturalHeight = img.naturalHeight || img.height || 1024;
            var maxWidth = 1400;
            var maxHeight = 1400;
            var scale = Math.min(1, maxWidth / naturalWidth, maxHeight / naturalHeight);
            if (!isFinite(scale) || scale <= 0) {
                scale = 1;
            }

            var drawWidth = Math.max(1, Math.round(naturalWidth * scale));
            var drawHeight = Math.max(1, Math.round(naturalHeight * scale));

            canvas.width = drawWidth;
            canvas.height = drawHeight;

            ctx.clearRect(0, 0, drawWidth, drawHeight);
            ctx.fillStyle = colorHex || '#0f172a';
            ctx.fillRect(0, 0, drawWidth, drawHeight);

            ctx.drawImage(img, 0, 0, drawWidth, drawHeight);

            self.paintCanvasWatermark(ctx, drawWidth, drawHeight, watermarkText);
        };

        img.onerror = function() {
            self.preview.loadFailed = true;
            self.preview.failedPattern = patternUrl;
            self.refreshPreviewState();
        };

        img.src = patternUrl;
    };

    VariantDisplay.prototype.showPatternPreview = function() {
        if (!this.preview.$modal) {
            return;
        }
        this.preview.$modal.addClass('is-open').attr('aria-hidden', 'false');
        if (this.preview.$close) {
            this.preview.$close.trigger('focus');
        }
        this.refreshPreviewState();
    };

    VariantDisplay.prototype.hidePatternPreview = function() {
        if (!this.preview.$modal) {
            return;
        }
        this.preview.$modal.removeClass('is-open').attr('aria-hidden', 'true');
        if (this.preview.$button) {
            this.preview.$button.trigger('focus');
        }
        if (this.preview.renderTimer) {
            window.clearTimeout(this.preview.renderTimer);
            this.preview.renderTimer = null;
            this.preview.renderQueued = false;
        }
    };

    VariantDisplay.prototype.handleFoundVariation = function(variation) {
        if (!variation) {
            return;
        }
        if (variation.variation_id) {
            this.preview.activeVariationId = variation.variation_id;
        }
        this.refreshPreviewState();
    };

    VariantDisplay.prototype.handleVariationReset = function() {
        this.preview.activeVariationId = 0;
        this.refreshPreviewState();
    };

    $(function(){
        if (!window.MG_VARIANT_DISPLAY || !MG_VARIANT_DISPLAY.types) {
            return;
        }
        $('form.variations_form').each(function(){
            new VariantDisplay($(this), MG_VARIANT_DISPLAY);
        });
    });

    VariantDisplay.prototype.createSizeChartModal = function() {
        if (this.sizeChart.$modal) {
            return;
        }
        var $modal = $('<div class="mg-size-chart-modal" role="dialog" aria-modal="true" aria-hidden="true" />');
        var $dialog = $('<div class="mg-size-chart-modal__dialog" role="document" />');
        var $header = $('<div class="mg-size-chart-modal__header" />');
        this.sizeChart.$title = $('<h3 class="mg-size-chart-modal__title" />').text(this.getText('sizeChartTitle', 'Mérettáblázat'));
        this.sizeChart.$close = $('<button type="button" class="mg-size-chart-modal__close" />').text(this.getText('sizeChartClose', 'Bezárás'));
        var $body = $('<div class="mg-size-chart-modal__body" />');

        $header.append(this.sizeChart.$title);
        $header.append(this.sizeChart.$close);
        $dialog.append($header);
        $dialog.append($body);
        $modal.append($dialog);

        $('body').append($modal);

        this.sizeChart.$modal = $modal;
        this.sizeChart.$body = $body;

        var self = this;
        this.sizeChart.$close.on('click', function(){
            self.hideSizeChart();
        });

        $modal.on('click', function(event){
            if ($(event.target).is($modal)) {
                self.hideSizeChart();
            }
        });

        $(document).on('keydown.mgVariantSizeChart', function(event){
            if (event.key === 'Escape' && self.sizeChart.$modal && self.sizeChart.$modal.hasClass('is-open')) {
                self.hideSizeChart();
            }
        });
    };

    VariantDisplay.prototype.updateSizeChartLink = function() {
        if (!this.sizeChart.$link) {
            return;
        }
        var chart = this.getSizeChartContent();
        var hasChart = !!chart;
        this.sizeChart.$link.toggleClass('is-disabled', !hasChart);
        this.sizeChart.$link.attr('aria-disabled', hasChart ? 'false' : 'true');
        this.sizeChart.$link.prop('disabled', !hasChart);
        this.sizeChart.$link.attr('aria-expanded', this.sizeChart.$modal && this.sizeChart.$modal.hasClass('is-open') ? 'true' : 'false');
    };

    VariantDisplay.prototype.getSizeChartContent = function() {
        if (!this.state.type) {
            return '';
        }
        var typeMeta = this.config.types[this.state.type];
        if (!typeMeta || !typeMeta.size_chart) {
            return '';
        }
        return typeMeta.size_chart;
    };

    VariantDisplay.prototype.captureDescriptionTargets = function() {
        this.descriptionTargets = [];
        var selectors = [];
        if (this.config && $.isArray(this.config.descriptionTargets) && this.config.descriptionTargets.length) {
            selectors = this.config.descriptionTargets;
        } else {
            selectors = [
                '.woocommerce-product-details__short-description',
                '#tab-description',
                '.woocommerce-Tabs-panel--description'
            ];
        }

        var self = this;
        $.each(selectors, function(_, selector){
            $(selector).each(function(){
                var $el = $(this);
                if (!$el.length) {
                    return;
                }
                var exists = false;
                for (var i = 0; i < self.descriptionTargets.length; i++) {
                    if (self.descriptionTargets[i].$el && self.descriptionTargets[i].$el[0] === $el[0]) {
                        exists = true;
                        break;
                    }
                }
                if (exists) {
                    return;
                }
                self.descriptionTargets.push({
                    $el: $el,
                    original: $el.html()
                });
            });
        });
    };

    VariantDisplay.prototype.updateDescription = function() {
        if (!this.descriptionTargets.length) {
            return;
        }
        var html = '';
        if (this.state.type && this.config && this.config.types && this.config.types[this.state.type]) {
            html = this.config.types[this.state.type].description || '';
        }

        var hasHtml = typeof html === 'string' && html !== '';
        for (var i = 0; i < this.descriptionTargets.length; i++) {
            var target = this.descriptionTargets[i];
            if (!target.$el || !target.$el.length) {
                continue;
            }
            var newContent = hasHtml ? html : target.original;
            if (typeof newContent === 'undefined') {
                newContent = '';
            }
            target.$el.html(newContent);
            target.$el.toggleClass('mg-variant-description--empty', newContent === '');
        }

        $(document).trigger('mg:variantDescriptionChange', {
            form: this.$form,
            type: this.state.type,
            html: html,
            hasCustomDescription: hasHtml
        });
    };

    VariantDisplay.prototype.showSizeChart = function() {
        if (!this.sizeChart.$modal) {
            return;
        }
        var content = this.getSizeChartContent();
        if (!content) {
            return;
        }
        var typeLabel = this.state.type && this.config.types[this.state.type] ? this.config.types[this.state.type].label : '';
        if (typeLabel) {
            this.sizeChart.$title.text(this.getText('sizeChartTitle', 'Mérettáblázat') + ' – ' + typeLabel);
        } else {
            this.sizeChart.$title.text(this.getText('sizeChartTitle', 'Mérettáblázat'));
        }
        this.sizeChart.$body.html(content);
        this.sizeChart.$modal.addClass('is-open').attr('aria-hidden', 'false');
        this.sizeChart.$link.attr('aria-expanded', 'true');
        this.sizeChart.$close.trigger('focus');
        this.updateSizeChartLink();
    };

    VariantDisplay.prototype.hideSizeChart = function() {
        if (!this.sizeChart.$modal) {
            return;
        }
        this.sizeChart.$modal.removeClass('is-open').attr('aria-hidden', 'true');
        if (this.sizeChart.$body) {
            this.sizeChart.$body.empty();
        }
        if (this.sizeChart.$link) {
            this.sizeChart.$link.attr('aria-expanded', 'false');
            this.sizeChart.$link.trigger('focus');
        }
        this.updateSizeChartLink();
    };
})(jQuery);
