(function($){
    function VirtualVariantDisplay($form, config) {
        this.$form = $form;
        this.config = config || {};
        this.$wrapper = $form.find('.mg-virtual-variant');
        this.$typeInput = $form.find('input[name="mg_product_type"]');
        this.$colorInput = $form.find('input[name="mg_color"]');
        this.$sizeInput = $form.find('input[name="mg_size"]');
        this.$previewInput = $form.find('input[name="mg_preview_url"]');
        this.state = {
            type: '',
            color: '',
            size: ''
        };
        this.preview = {
            activeUrl: '',
            pending: false
        };
        this.$typeOptions = $();
        this.$colorOptions = $();
        this.$sizeOptions = $();
        this.$typeValue = $();
        this.$colorLabelValue = $();
        this.$availability = $();
        this.$availabilityValue = $();
        this.$typeModal = $();
        this.$typeTrigger = $();
        this.$addToCart = $form.find('button.single_add_to_cart_button');
        this.$price = $form.closest('.summary').find('.price').first();
        this.originalPriceHtml = this.$price.length ? this.$price.html() : '';
        this.descriptionTargets = [];
        this.init();
    }

    VirtualVariantDisplay.prototype.getText = function(key, fallback) {
        if (this.config && this.config.text && typeof this.config.text[key] !== 'undefined') {
            return this.config.text[key];
        }
        return fallback;
    };

    VirtualVariantDisplay.prototype.init = function() {
        if (!this.$wrapper.length || !this.$typeInput.length || !this.$colorInput.length || !this.$sizeInput.length) {
            return;
        }
        this.buildLayout();
        this.bindEvents();
        this.captureDescriptionTargets();
        this.syncDefaults();
        this.updatePrice();
        this.refreshAddToCartState();
    };

    VirtualVariantDisplay.prototype.buildLayout = function() {
        var wrapper = $('<div class="mg-variant-display" />');

        var typeSection = $('<div class="mg-variant-section mg-variant-section--type" />');
        typeSection.append($('<div class="mg-variant-section__label" />').text(this.getText('typePrompt', 'Válassz terméket:')));
        var $typeTrigger = $('<button type="button" class="mg-variant-type-trigger" aria-haspopup="dialog" aria-expanded="false" />');
        $typeTrigger.append($('<span class="mg-variant-type-trigger__label" />').text(this.getText('type', 'Terméktípus')));
        this.$typeValue = $('<span class="mg-variant-type-trigger__value" />').text(this.getText('typePlaceholder', 'Válassz terméktípust'));
        $typeTrigger.append(this.$typeValue);
        $typeTrigger.append($('<span class="mg-variant-type-trigger__chevron" aria-hidden="true">▾</span>'));
        typeSection.append($typeTrigger);
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
        this.$availability = $('<div class="mg-variant-availability" />');
        this.$availability.append($('<span class="mg-variant-availability__label" />').text(this.getText('availability', 'Elérhetőség') + ': '));
        this.$availabilityValue = $('<span class="mg-variant-availability__value" />');
        this.$availability.append(this.$availabilityValue);
        sizeSection.append(this.$availability);
        wrapper.append(sizeSection);

        this.$wrapper.append(wrapper);

        this.createTypeModal($typeTrigger);
        this.buildTypeOptions();
        this.rebuildColorOptions();
        this.rebuildSizeOptions();
    };

    VirtualVariantDisplay.prototype.createTypeModal = function($trigger) {
        var $modal = $('<div class="mg-variant-type-modal" aria-hidden="true" role="dialog" />');
        var $backdrop = $('<div class="mg-variant-type-modal__backdrop" />');
        var $panel = $('<div class="mg-variant-type-modal__panel" role="document" />');
        var $header = $('<div class="mg-variant-type-modal__header" />');
        var $title = $('<h3 class="mg-variant-type-modal__title" />').text(this.getText('typeModalTitle', 'Válaszd ki a terméktípust'));
        var $close = $('<button type="button" class="mg-variant-type-modal__close" aria-label="' + this.getText('typeModalClose', 'Bezárás') + '">×</button>');
        var $body = $('<div class="mg-variant-type-modal__body" />');
        var $list = $('<div class="mg-variant-type-list" role="radiogroup" />');

        $header.append($title).append($close);
        $body.append($list);
        $panel.append($header).append($body);
        $modal.append($backdrop).append($panel);
        $('body').append($modal);

        this.$typeOptions = $list;

        var self = this;
        this.$typeModal = $modal;
        this.$typeTrigger = $trigger;

        $trigger.on('click', function(e){
            e.preventDefault();
            $modal.addClass('is-open').attr('aria-hidden', 'false');
            $trigger.attr('aria-expanded', 'true');
            $('body').addClass('mg-variant-modal-open');
            $close.trigger('focus');
        });
        $close.on('click', function(){
            self.hideTypeModal($modal, $trigger);
        });
        $modal.on('click', function(event){
            if ($(event.target).is($modal) || $(event.target).is($backdrop)) {
                self.hideTypeModal($modal, $trigger);
            }
        });
        $(document).on('keydown.mgVirtualTypeModal', function(event){
            if (event.key === 'Escape' && $modal.hasClass('is-open')) {
                self.hideTypeModal($modal, $trigger);
            }
        });
    };

    VirtualVariantDisplay.prototype.hideTypeModal = function($modal, $trigger) {
        $modal.removeClass('is-open').attr('aria-hidden', 'true');
        $trigger.attr('aria-expanded', 'false');
        $('body').removeClass('mg-variant-modal-open');
        $trigger.trigger('focus');
    };

    VirtualVariantDisplay.prototype.buildTypeOptions = function() {
        var self = this;
        this.$typeOptions.empty();
        var typeOrder = (this.config.order && this.config.order.types && this.config.order.types.length) ? this.config.order.types : Object.keys(this.config.types || {});
        $.each(typeOrder, function(_, typeSlug){
            var meta = self.config.types[typeSlug];
            if (!meta) {
                return;
            }
            var $btn = $('<button type="button" class="mg-variant-type-option" aria-pressed="false" />');
            $btn.attr('data-value', typeSlug);
            var label = meta.label || typeSlug;
            var mockup = self.getTypeMockup(typeSlug);
            if (mockup) {
                var $thumb = $('<span class="mg-variant-type-option__thumb" />');
                var $img = $('<img loading="lazy" decoding="async" alt="" />').attr('src', mockup).attr('alt', label + ' előnézet');
                $thumb.append($img);
                $btn.append($thumb);
            }
            $btn.append($('<span class="mg-variant-type-option__label" />').text(label));
            $btn.append($('<span class="mg-variant-type-option__check" aria-hidden="true">✓</span>'));
            self.$typeOptions.append($btn);
        });
    };

    VirtualVariantDisplay.prototype.getTypeMockup = function(typeSlug) {
        if (!typeSlug || !this.config || !this.config.visuals) {
            return '';
        }
        if (this.config.visuals.typeMockups && this.config.visuals.typeMockups[typeSlug]) {
            return this.config.visuals.typeMockups[typeSlug];
        }
        return '';
    };

    VirtualVariantDisplay.prototype.bindEvents = function() {
        var self = this;
        this.$typeOptions.on('click', '.mg-variant-type-option', function(e){
            e.preventDefault();
            var value = $(this).attr('data-value') || '';
            self.setType(value);
            if (self.$typeModal.length && self.$typeTrigger.length) {
                self.hideTypeModal(self.$typeModal, self.$typeTrigger);
            }
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
            self.setColor(value);
        });

        this.$colorOptions.on('mouseenter focus', '.mg-variant-option', function(){
            if (!self.state.type || $(this).hasClass('is-disabled')) {
                return;
            }
            var label = $(this).attr('data-label') || '';
            self.setColorLabelText(label);
        });

        this.$colorOptions.on('mouseleave blur', '.mg-variant-option', function(){
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
            self.setSize(value);
        });
    };

    VirtualVariantDisplay.prototype.getTypeFromUrl = function() {
        if (typeof window === 'undefined' || !window.location) {
            return '';
        }
        try {
            var params = new URLSearchParams(window.location.search || '');
            var candidate = params.get('mg_type') || '';
            return candidate;
        } catch (err) {
            return '';
        }
    };

    VirtualVariantDisplay.prototype.updateUrlForType = function(typeSlug) {
        if (typeof window === 'undefined' || !window.history) {
            return;
        }
        var urlMap = this.config.typeUrls || {};
        if (typeSlug && urlMap[typeSlug]) {
            if (window.location && window.location.href !== urlMap[typeSlug]) {
                window.location.href = urlMap[typeSlug];
                return;
            }
            window.history.replaceState({}, '', urlMap[typeSlug]);
            return;
        }
        try {
            var url = new URL(window.location.href);
            if (typeSlug) {
                url.searchParams.set('mg_type', typeSlug);
            } else {
                url.searchParams.delete('mg_type');
            }
            window.history.replaceState({}, '', url.toString());
        } catch (err) {
        }
    };

    VirtualVariantDisplay.prototype.syncDefaults = function() {
        var defaults = this.config.default || {};
        var urlType = this.getTypeFromUrl();
        var initialType = urlType || defaults.type || '';
        this.setType(initialType);
        if (urlType && this.config.typeUrls && this.config.typeUrls[urlType]) {
            this.updateUrlForType(urlType);
        }
        if (defaults.color) {
            this.setColor(defaults.color);
        }
        if (defaults.size) {
            this.setSize(defaults.size);
        }
        this.refreshAddToCartState();
    };

    VirtualVariantDisplay.prototype.setType = function(value) {
        value = value || '';
        if (this.state.type === value) {
            return;
        }
        this.state.type = value;
        this.$typeInput.val(value).trigger('change');
        var label = value && this.config.types && this.config.types[value] ? this.config.types[value].label : this.getText('typePlaceholder', 'Válassz terméktípust');
        this.$typeValue.text(label || value);
        this.updateUrlForType(value);
        this.updateDescription();
        this.$typeOptions.find('.mg-variant-type-option').each(function(){
            var $btn = $(this);
            var isActive = ($btn.attr('data-value') || '') === value;
            $btn.toggleClass('is-selected', isActive);
            $btn.attr('aria-pressed', isActive ? 'true' : 'false');
        });
        this.setColor('');
        this.rebuildColorOptions();
        this.updatePrice();
        this.refreshAddToCartState();
    };

    VirtualVariantDisplay.prototype.captureDescriptionTargets = function() {
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

    VirtualVariantDisplay.prototype.updateDescription = function() {
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

        $(document).trigger('mg:virtualVariantDescriptionChange', {
            form: this.$form,
            type: this.state.type,
            html: html,
            hasCustomDescription: hasHtml
        });
    };

    VirtualVariantDisplay.prototype.setColor = function(value) {
        value = value || '';
        if (!this.state.type) {
            value = '';
        }
        if (this.state.color === value) {
            this.refreshColorLabel();
            return;
        }
        this.state.color = value;
        this.$colorInput.val(value).trigger('change');
        this.$colorOptions.find('.mg-variant-option').each(function(){
            var $btn = $(this);
            var isActive = ($btn.attr('data-value') || '') === value;
            $btn.toggleClass('is-selected', isActive);
            $btn.attr('aria-pressed', isActive ? 'true' : 'false');
        });
        this.refreshColorLabel();
        this.setSize('');
        this.rebuildSizeOptions();
        this.refreshPreview();
        this.updatePrice();
        this.refreshAddToCartState();
    };

    VirtualVariantDisplay.prototype.setSize = function(value) {
        value = value || '';
        if (!this.state.type || !this.state.color) {
            value = '';
        }
        if (this.state.size === value) {
            return;
        }
        this.state.size = value;
        this.$sizeInput.val(value).trigger('change');
        this.$sizeOptions.find('.mg-variant-option').each(function(){
            var $btn = $(this);
            var isActive = ($btn.attr('data-value') || '') === value;
            $btn.toggleClass('is-selected', isActive);
            $btn.attr('aria-pressed', isActive ? 'true' : 'false');
        });
        this.updateAvailabilityText();
        this.updatePrice();
        this.refreshAddToCartState();
    };

    VirtualVariantDisplay.prototype.updatePrice = function() {
        if (!this.$price.length) {
            return;
        }
        if (!this.state.type || !this.config.types || !this.config.types[this.state.type]) {
            this.$price.html(this.originalPriceHtml);
            return;
        }
        var typeMeta = this.config.types[this.state.type];
        var basePrice = this.getTypeBasePrice(typeMeta);
        var colorSurcharge = this.getColorSurcharge(typeMeta);
        var sizeSurcharge = this.getSizeSurcharge(typeMeta);
        var total = Math.max(0, basePrice + colorSurcharge + sizeSurcharge);
        var formatted = this.formatPrice(total);
        if (formatted) {
            this.$price.html(formatted);
        }
    };

    VirtualVariantDisplay.prototype.getTypeBasePrice = function(typeMeta) {
        if (!typeMeta || typeof typeMeta.price !== 'number') {
            return 0;
        }
        return typeMeta.price;
    };

    VirtualVariantDisplay.prototype.getColorSurcharge = function(typeMeta) {
        if (!typeMeta || !typeMeta.colors || !this.state.color || !typeMeta.colors[this.state.color]) {
            return 0;
        }
        var surcharge = typeMeta.colors[this.state.color].surcharge;
        return (typeof surcharge === 'number') ? surcharge : 0;
    };

    VirtualVariantDisplay.prototype.getSizeSurcharge = function(typeMeta) {
        if (!typeMeta || !typeMeta.size_surcharges || !this.state.size) {
            return 0;
        }
        var surcharge = typeMeta.size_surcharges[this.state.size];
        return (typeof surcharge === 'number') ? surcharge : 0;
    };

    VirtualVariantDisplay.prototype.formatPrice = function(amount) {
        var format = this.config.priceFormat || {};
        var decimals = (typeof format.decimals === 'number') ? format.decimals : 2;
        var decimalSeparator = (typeof format.decimalSeparator === 'string') ? format.decimalSeparator : '.';
        var thousandSeparator = (typeof format.thousandSeparator === 'string') ? format.thousandSeparator : ',';
        var priceFormat = (typeof format.priceFormat === 'string') ? format.priceFormat : '%1$s%2$s';
        var currencySymbol = (typeof format.currencySymbol === 'string') ? format.currencySymbol : '';

        var fixed = amount.toFixed(decimals);
        var parts = fixed.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandSeparator);
        var formattedNumber = parts.join(decimalSeparator);
        var currencyHtml = '<span class="woocommerce-Price-currencySymbol">' + currencySymbol + '</span>';
        var priceHtml = priceFormat.replace('%1$s', currencyHtml).replace('%2$s', formattedNumber);
        return '<span class="woocommerce-Price-amount amount"><bdi>' + priceHtml + '</bdi></span>';
    };

    VirtualVariantDisplay.prototype.setColorLabelText = function(label) {
        if (!this.$colorLabelValue.length) {
            return;
        }
        var text = label ? '"' + label + '"' : '""';
        this.$colorLabelValue.text(text);
    };

    VirtualVariantDisplay.prototype.refreshColorLabel = function() {
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

    VirtualVariantDisplay.prototype.rebuildColorOptions = function() {
        this.$colorOptions.empty();
        if (!this.state.type || !this.config.types[this.state.type]) {
            this.$colorOptions.append($('<div class="mg-variant-placeholder" />').text(this.getText('chooseTypeFirst', 'Először válassz terméktípust.')));
            this.refreshPreview();
            return;
        }
        var typeMeta = this.config.types[this.state.type];
        var colors = typeMeta.colors || {};
        var order = (typeMeta.color_order && typeMeta.color_order.length) ? typeMeta.color_order : Object.keys(colors);
        var self = this;
        var fallbackColor = '';
        if (!order.length) {
            this.$colorOptions.append($('<div class="mg-variant-placeholder" />').text(this.getText('noColors', 'Ehhez a terméktípushoz nincs elérhető szín.')));
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

        if (!this.state.color && fallbackColor) {
            this.setColor(fallbackColor);
            return;
        }

        this.refreshColorLabel();
    };

    VirtualVariantDisplay.prototype.rebuildSizeOptions = function() {
        this.$sizeOptions.empty();
        if (!this.state.type) {
            this.$sizeOptions.append($('<div class="mg-variant-placeholder" />').text(this.getText('chooseTypeFirst', 'Először válassz terméktípust.')));
            this.updateAvailabilityText();
            return;
        }
        if (!this.state.color || !this.config.types[this.state.type] || !this.config.types[this.state.type].colors[this.state.color]) {
            this.$sizeOptions.append($('<div class="mg-variant-placeholder" />').text(this.getText('chooseColorFirst', 'Először válassz színt.')));
            this.updateAvailabilityText();
            return;
        }
        var colorMeta = this.config.types[this.state.type].colors[this.state.color];
        var sizes = colorMeta.sizes || [];
        var self = this;
        var fallbackSize = '';
        if (!sizes.length) {
            this.$sizeOptions.append($('<div class="mg-variant-placeholder" />').text(this.getText('noSizes', 'Ehhez a kombinációhoz nincs elérhető méret.')));
            this.updateAvailabilityText();
            return;
        }
        $.each(sizes, function(_, sizeValue){
            var $btn = $('<button type="button" class="mg-variant-option mg-variant-option--size" aria-pressed="false" />');
            $btn.attr('data-value', sizeValue);
            $btn.append($('<span class="mg-variant-option__label" />').text(sizeValue));
            var available = self.getAvailability(self.state.type, self.state.color, sizeValue);
            if (!available) {
                $btn.addClass('is-disabled').attr('aria-disabled', 'true');
            } else if (!fallbackSize) {
                fallbackSize = sizeValue;
            }
            self.$sizeOptions.append($btn);
        });

        if (!this.state.size && fallbackSize) {
            this.setSize(fallbackSize);
        }
        this.updateAvailabilityText();
    };

    VirtualVariantDisplay.prototype.getAvailableSizes = function(type, color) {
        if (!this.config.types[type] || !this.config.types[type].colors[color]) {
            return [];
        }
        return this.config.types[type].colors[color].sizes || [];
    };

    VirtualVariantDisplay.prototype.getAvailability = function(type, color, size) {
        return true;
    };

    VirtualVariantDisplay.prototype.updateAvailabilityText = function() {
        if (!this.$availabilityValue.length) {
            return;
        }
        if (!this.state.type) {
            this.$availabilityValue.text(this.getText('chooseTypeFirst', 'Először válassz terméktípust.'));
            this.$availability.removeClass('is-out-of-stock is-in-stock').addClass('is-pending');
            return;
        }
        if (!this.state.color) {
            this.$availabilityValue.text(this.getText('chooseColorFirst', 'Először válassz színt.'));
            this.$availability.removeClass('is-out-of-stock is-in-stock').addClass('is-pending');
            return;
        }
        if (!this.state.size) {
            this.$availabilityValue.text(this.getText('chooseSizeFirst', 'Válassz méretet.'));
            this.$availability.removeClass('is-out-of-stock is-in-stock').addClass('is-pending');
            return;
        }
        this.$availabilityValue.text(this.getText('inStock', 'Raktáron'));
        this.$availability.removeClass('is-out-of-stock is-pending').addClass('is-in-stock');
    };

    VirtualVariantDisplay.prototype.refreshAddToCartState = function() {
        if (!this.$addToCart.length) {
            return;
        }
        var ready = this.state.type && this.state.color && this.state.size;
        this.$addToCart.prop('disabled', !ready);
        this.$addToCart.toggleClass('disabled', !ready);
    };

    VirtualVariantDisplay.prototype.refreshPreview = function() {
        if (!this.state.type || !this.state.color) {
            this.preview.activeUrl = '';
            this.$previewInput.val('');
            return;
        }
        if (!this.config.ajax || !this.config.ajax.url || !this.config.ajax.nonce) {
            return;
        }
        var self = this;
        this.preview.pending = true;
        $.post(this.config.ajax.url, {
            action: 'mg_virtual_preview',
            nonce: this.config.ajax.nonce,
            product_id: this.config.product ? this.config.product.id : 0,
            product_type: this.state.type,
            color: this.state.color
        }).done(function(response){
            if (!response || !response.success || !response.data) {
                return;
            }
            var url = response.data.preview_url || '';
            if (!url) {
                return;
            }
            self.preview.activeUrl = url;
            self.$previewInput.val(url);
            self.swapGalleryImage(url);
        }).always(function(){
            self.preview.pending = false;
        });
    };

    VirtualVariantDisplay.prototype.swapGalleryImage = function(url) {
        if (!url) {
            return;
        }
        var $gallery = $('.woocommerce-product-gallery');
        if (!$gallery.length) {
            return;
        }
        var $img = $gallery.find('.woocommerce-product-gallery__image img').first();
        if (!$img.length) {
            $img = $gallery.find('img').first();
        }
        if (!$img.length) {
            return;
        }
        $img.attr('src', url);
        $img.attr('srcset', url);
        $img.attr('data-src', url);
    };

    $(function(){
        if (!window.MG_VIRTUAL_VARIANTS || !MG_VIRTUAL_VARIANTS.types) {
            return;
        }
        $('form.cart').each(function(){
            var instance = new VirtualVariantDisplay($(this), MG_VIRTUAL_VARIANTS);
            window.MG_VIRTUAL_VARIANT_INSTANCES = window.MG_VIRTUAL_VARIANT_INSTANCES || [];
            window.MG_VIRTUAL_VARIANT_INSTANCES.push(instance);
        });
    });
})(jQuery);
