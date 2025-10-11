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
        this.syncing = {
            type: false,
            color: false,
            size: false
        };
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
        this.bindEvents();
        this.initialSync();
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
        wrapper.append(sizeSection);

        this.$form.find('.variations').addClass('mg-variant-hidden').before(wrapper);

        var typeOrder = (this.config.order && this.config.order.types && this.config.order.types.length) ? this.config.order.types : Object.keys(this.config.types);
        var self = this;
        $.each(typeOrder, function(_, typeSlug){
            var meta = self.config.types[typeSlug];
            if (!meta) {
                return;
            }
            var $btn = $('<button type="button" class="mg-variant-option mg-variant-option--type" aria-pressed="false" />');
            $btn.attr('data-value', typeSlug);
            if (meta.icon) {
                var $icon = $('<span class="mg-variant-option__icon" />');
                $icon.append($('<img />', { src: meta.icon, alt: '' }));
                $btn.append($icon);
            }
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

        this.$typeSelect.on('change', function(){
            if (self.syncing.type) {
                return;
            }
            self.state.type = $(this).val() || '';
            self.updateTypeUI();
        });

        this.$colorSelect.on('change', function(){
            if (self.syncing.color) {
                return;
            }
            self.state.color = $(this).val() || '';
            self.updateColorUI();
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
    };

    VariantDisplay.prototype.syncFromSelects = function(useDefaults) {
        var typeVal = this.$typeSelect.val() || (useDefaults && this.config.default ? this.config.default.type : '');
        var colorVal = this.$colorSelect.val() || (useDefaults && this.config.default ? this.config.default.color : '');
        var sizeVal = this.$sizeSelect.val() || (useDefaults && this.config.default ? this.config.default.size : '');

        this.setType(typeVal, false);
        this.setColor(colorVal, false);
        this.setSize(sizeVal, false);

        this.updateTypeUI();
    };

    VariantDisplay.prototype.setType = function(value, triggerChange) {
        value = value || '';
        if (this.state.type === value) {
            if (triggerChange) {
                this.syncing.type = true;
                this.$typeSelect.trigger('change');
                this.syncing.type = false;
            }
            this.updateTypeUI();
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
        this.updateTypeUI();
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
            this.updateColorUI();
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
        this.updateColorUI();
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

    VariantDisplay.prototype.updateTypeUI = function() {
        var self = this;
        this.$typeOptions.find('.mg-variant-option').each(function(){
            var $btn = $(this);
            var value = $btn.attr('data-value') || '';
            var isActive = value === self.state.type;
            $btn.toggleClass('is-selected', isActive);
            $btn.attr('aria-pressed', isActive ? 'true' : 'false');
        });
        this.rebuildColorOptions();
    };

    VariantDisplay.prototype.updateColorUI = function() {
        var self = this;
        this.$colorOptions.find('.mg-variant-option').each(function(){
            var $btn = $(this);
            var value = $btn.attr('data-value') || '';
            var isActive = value === self.state.color;
            $btn.toggleClass('is-selected', isActive);
            $btn.attr('aria-pressed', isActive ? 'true' : 'false');
        });
        this.rebuildSizeOptions();
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

    VariantDisplay.prototype.rebuildColorOptions = function() {
        this.$colorOptions.empty();
        if (!this.state.type || !this.config.types[this.state.type]) {
            this.$colorOptions.append($('<div class="mg-variant-placeholder" />').text(this.getText('chooseTypeFirst', 'Először válassz terméktípust.')));
            this.setColor('', this.state.color ? true : false);
            this.rebuildSizeOptions();
            return;
        }

        var typeMeta = this.config.types[this.state.type];
        var colors = typeMeta.colors || {};
        var order = (typeMeta.color_order && typeMeta.color_order.length) ? typeMeta.color_order : Object.keys(colors);
        var self = this;

        if (!order.length) {
            this.$colorOptions.append($('<div class="mg-variant-placeholder" />').text(this.getText('noColors', 'Ehhez a terméktípushoz nincs elérhető szín.')));
            this.setColor('', this.state.color ? true : false);
            this.rebuildSizeOptions();
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
            if (meta.image) {
                $swatch.addClass('has-image').css('background-image', 'url(' + meta.image + ')');
            } else if (meta.swatch) {
                $swatch.css('background-color', meta.swatch);
            }
            $btn.append($swatch);
            $btn.append($('<span class="mg-variant-option__label" />').text(meta.label || colorSlug));
            if (!availableSizes.length) {
                $btn.addClass('is-disabled').attr('aria-disabled', 'true');
            }
            self.$colorOptions.append($btn);
        });

        if (!colors[this.state.color]) {
            this.setColor('', this.state.color ? true : false);
        }

        this.updateColorUI();
    };

    VariantDisplay.prototype.rebuildSizeOptions = function() {
        this.$sizeOptions.empty();
        if (!this.state.type) {
            this.$sizeOptions.append($('<div class="mg-variant-placeholder" />').text(this.getText('chooseTypeFirst', 'Először válassz terméktípust.')));
            this.setSize('', false);
            return;
        }
        if (!this.state.color || !this.config.types[this.state.type] || !this.config.types[this.state.type].colors[this.state.color]) {
            this.$sizeOptions.append($('<div class="mg-variant-placeholder" />').text(this.getText('chooseColorFirst', 'Először válassz színt.')));
            this.setSize('', false);
            return;
        }

        var colorMeta = this.config.types[this.state.type].colors[this.state.color];
        var sizes = colorMeta.sizes || [];
        var self = this;

        if (!sizes.length) {
            this.$sizeOptions.append($('<div class="mg-variant-placeholder" />').text(this.getText('noSizes', 'Ehhez a kombinációhoz nincs elérhető méret.')));
            this.setSize('', false);
            return;
        }

        $.each(sizes, function(_, sizeValue){
            var $btn = $('<button type="button" class="mg-variant-option mg-variant-option--size" aria-pressed="false" />');
            $btn.attr('data-value', sizeValue);
            $btn.append($('<span class="mg-variant-option__label" />').text(sizeValue));
            var availability = self.getAvailability(self.state.type, self.state.color, sizeValue);
            if (!availability.in_stock && !availability.is_purchasable) {
                $btn.addClass('is-disabled').attr('aria-disabled', 'true');
            }
            self.$sizeOptions.append($btn);
        });

        if (sizes.indexOf(this.state.size) === -1) {
            this.setSize('', false);
        }

        this.updateSizeUI();
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
})(jQuery);
