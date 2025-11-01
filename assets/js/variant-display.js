(function($){
    function VariantDisplay($form, config) {
        this.$form = $form;
        this.config = config;
        this.$typeSelect = $form.find('select[name="attribute_pa_termektipus"]');
        this.$colorSelect = $form.find('select[name="attribute_pa_szin"]');
        this.$sizeSelect = $form.find('select[name="attribute_meret"]');
        this.state = {
            type: '',
            color: '',
            size: ''
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
        this.init();
    }

    VariantDisplay.prototype.getText = function(key, fallback) {
        if (this.config && this.config.text && typeof this.config.text[key] !== 'undefined') {
            return this.config.text[key];
        }
        return fallback;
    };

    VariantDisplay.prototype.init = function() {
        if (!this.$typeSelect.length || !this.$colorSelect.length || !this.$sizeSelect.length) {
            return;
        }

        this.buildLayout();
        this.captureDescriptionTargets();
        this.bindEvents();
        this.initialSync();
    };

    VariantDisplay.prototype.markReady = function() {
        if (this.isReady) {
            return;
        }
        this.isReady = true;
        this.$form.addClass('mg-variant-form--enhanced');
        if (typeof document !== 'undefined' && document.documentElement) {
            document.documentElement.classList.remove('mg-variant-preparing');
            document.documentElement.classList.remove('mg-variant-preload');
            document.documentElement.classList.add('mg-variant-ready');
        }
        if (typeof window !== 'undefined' && window.__mgVariantPreloadCleanup) {
            window.clearTimeout(window.__mgVariantPreloadCleanup);
            window.__mgVariantPreloadCleanup = null;
        }
    };

    VariantDisplay.prototype.buildLayout = function() {
        var wrapper = $('<div class="mg-variant-display" />');
        var typeSection = $('<div class="mg-variant-section mg-variant-section--type" />');
        typeSection.append($('<div class="mg-variant-section__label" />').text(this.getText('type', 'Terméktípus')));
        this.$typeOptions = $('<div class="mg-variant-options" role="radiogroup" />');
        typeSection.append(this.$typeOptions);
        wrapper.append(typeSection);

        var colorSection = $('<div class="mg-variant-section mg-variant-section--color" />');
        colorSection.append($('<div class="mg-variant-section__label" />').text(this.getText('color', 'Szín')));
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

    VariantDisplay.prototype.rebuildColorOptions = function(shouldTriggerSizeSync) {
        this.$colorOptions.empty();
        if (!this.state.type || !this.config.types[this.state.type]) {
            this.$colorOptions.append($('<div class="mg-variant-placeholder" />').text(this.getText('chooseTypeFirst', 'Először válassz terméktípust.')));
            this.setColor('', shouldTriggerSizeSync);
            this.rebuildSizeOptions(shouldTriggerSizeSync);
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
            return;
        }

        $.each(order, function(_, colorSlug){
            var meta = colors[colorSlug];
            if (!meta) {
                return;
            }
            var availableSizes = self.getAvailableSizes(self.state.type, colorSlug);
            var $btn = $('<button type="button" class="mg-variant-option mg-variant-option--color" aria-pressed="false" />');
            $btn.attr('data-value', colorSlug);
            var $swatch = $('<span class="mg-variant-swatch" />');
            if (meta.swatch) {
                $swatch.css('background-color', meta.swatch);
            }
            $btn.append($swatch);
            $btn.append($('<span class="mg-variant-option__label" />').text(meta.label || colorSlug));
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
            return;
        }
        if (!this.state.color || !this.config.types[this.state.type] || !this.config.types[this.state.type].colors[this.state.color]) {
            this.$sizeOptions.append($('<div class="mg-variant-placeholder" />').text(this.getText('chooseColorFirst', 'Először válassz színt.')));
            this.setSize('', shouldTriggerSizeChange);
            this.updateSizeChartLink();
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
