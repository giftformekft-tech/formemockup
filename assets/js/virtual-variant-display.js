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
            pending: false,
            $button: null,
            $modal: null,
            $backdrop: null,
            $content: null,
            $watermark: null,
            $close: null,
            $canvas: null,
            $fallback: null,
            useCanvas: false,
            pendingPattern: '',
            pendingColor: '',
            pendingWatermark: '',
            renderQueued: false,
            renderTimer: null,
            loadFailed: false,
            failedPattern: ''
        };
        this.previewCache = {};
        this.previewCacheOrder = [];
        this.previewCacheLimit = this.getPreviewCacheLimit();
        this.sizeChart = {
            $link: null,
            $modal: null,
            $title: null,
            $body: null,
            $close: null,
            $modelsButton: null,
            $backButton: null,
            $chartPanel: null,
            $modelsPanel: null,
            $chartBody: null,
            $modelsBody: null,
            view: 'chart'
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
        this.$title = $();
        this.baseTitle = '';
        this.descriptionTargets = [];
        this.isReady = false;
        this.init();
    }

    VirtualVariantDisplay.prototype.getText = function(key, fallback) {
        if (this.config && this.config.text && typeof this.config.text[key] !== 'undefined') {
            return this.config.text[key];
        }
        return fallback;
    };

    VirtualVariantDisplay.prototype.getPreviewCacheLimit = function() {
        var limit = 60;
        if (this.config && typeof this.config.preview_cache_limit !== 'undefined') {
            limit = parseInt(this.config.preview_cache_limit, 10);
        }
        if (!isFinite(limit)) {
            limit = 60;
        }
        return Math.max(0, limit);
    };

    VirtualVariantDisplay.prototype.shouldPreloadPreview = function() {
        if (!this.config || typeof this.config.preview_preload === 'undefined') {
            return true;
        }
        return !!this.config.preview_preload;
    };

    VirtualVariantDisplay.prototype.touchPreviewCacheKey = function(cacheKey) {
        if (!cacheKey || !this.previewCacheOrder.length) {
            return;
        }
        var index = this.previewCacheOrder.indexOf(cacheKey);
        if (index === -1) {
            return;
        }
        this.previewCacheOrder.splice(index, 1);
        this.previewCacheOrder.push(cacheKey);
    };

    VirtualVariantDisplay.prototype.storePreviewCache = function(cacheKey, url) {
        if (!cacheKey || !url) {
            return;
        }
        if (this.previewCacheLimit === 0) {
            return;
        }
        if (this.previewCache[cacheKey]) {
            this.previewCache[cacheKey] = url;
            this.touchPreviewCacheKey(cacheKey);
            return;
        }
        this.previewCache[cacheKey] = url;
        this.previewCacheOrder.push(cacheKey);
        if (this.previewCacheOrder.length > this.previewCacheLimit) {
            var oldestKey = this.previewCacheOrder.shift();
            if (oldestKey && this.previewCache[oldestKey]) {
                delete this.previewCache[oldestKey];
            }
        }
    };

    VirtualVariantDisplay.prototype.supportsCanvas = function() {
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

    VirtualVariantDisplay.prototype.getTypeLabel = function(typeSlug) {
        if (!typeSlug) {
            return '';
        }
        if (this.config && this.config.types && this.config.types[typeSlug]) {
            return this.config.types[typeSlug].label || typeSlug;
        }
        return typeSlug;
    };

    VirtualVariantDisplay.prototype.init = function() {
        if (!this.$wrapper.length || !this.$typeInput.length || !this.$colorInput.length || !this.$sizeInput.length) {
            return;
        }
        this.captureTitle();
        this.buildLayout();
        this.captureDescriptionTargets();
        this.bindEvents();
        // Preview canvas is intentionally disabled to reduce frontend memory usage.
        this.syncDefaults();
        this.updatePrice();
        this.refreshAddToCartState();
        this.markReady();
    };

    VirtualVariantDisplay.prototype.markReady = function() {
        if (this.isReady) {
            return;
        }
        this.isReady = true;
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

    VirtualVariantDisplay.prototype.buildLayout = function() {
        var wrapper = $('<div class="mg-variant-display" />');
        this.$variantWrapper = wrapper;

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
        this.sizeChart.$link = $('<button type="button" class="mg-size-chart-link" aria-disabled="true" aria-expanded="false" />').text(this.getText('sizeChartLink', '📏 Mérettáblázat megnyitása'));
        var sizeLabel = $('<div class="mg-variant-section__label mg-variant-section__label--with-action" />');
        sizeLabel.append($('<span class="mg-variant-section__label-text" />').text(this.getText('size', 'Méret')));
        sizeLabel.append(this.sizeChart.$link);
        sizeSection.append(sizeLabel);
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
        this.createSizeChartModal();
        this.buildTypeOptions();
        this.rebuildColorOptions();
        this.rebuildSizeOptions();
        this.updateSizeChartLink();
        this.refreshPreviewState();
    };

    VirtualVariantDisplay.prototype.createPatternPreview = function() {
        if (this.preview.$button) {
            return null;
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
        this.preview.$close = $close;
        this.preview.$canvas = $canvas;
        this.preview.$fallback = $fallback;
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

        $(document).on('keydown.mgVirtualPatternPreview', function(event){
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

        return $buttonWrap;
    };

    VirtualVariantDisplay.prototype.createPatternPreview = function() {
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
        this.preview.$close = $close;
        this.preview.$canvas = $canvas;
        this.preview.$fallback = $fallback;
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

    VirtualVariantDisplay.prototype.createPatternPreview = function() {
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
        this.preview.$close = $close;
        this.preview.$canvas = $canvas;
        this.preview.$fallback = $fallback;
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
        } else if (this.$variantWrapper && this.$variantWrapper.length) {
            this.$variantWrapper.prepend($buttonWrap);
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

    VirtualVariantDisplay.prototype.createPatternPreview = function() {
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
        this.preview.$close = $close;
        this.preview.$canvas = $canvas;
        this.preview.$fallback = $fallback;
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
        } else if (this.$variantWrapper && this.$variantWrapper.length) {
            this.$variantWrapper.prepend($buttonWrap);
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

    VirtualVariantDisplay.prototype.createPatternPreview = function() {
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
        this.preview.$close = $close;
        this.preview.$canvas = $canvas;
        this.preview.$fallback = $fallback;
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
        } else if (this.$variantWrapper && this.$variantWrapper.length) {
            this.$variantWrapper.prepend($buttonWrap);
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
        if (this.sizeChart.$link) {
            this.sizeChart.$link.on('click', function(e){
                e.preventDefault();
                if ($(this).hasClass('is-disabled')) {
                    return;
                }
                self.showSizeChart();
            });
        }
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

    VirtualVariantDisplay.prototype.captureTitle = function() {
        if (this.$title && this.$title.length) {
            return;
        }
        var $scope = this.$form.closest('.product');
        var $title = $scope.find('.product_title').first();
        if (!$title.length) {
            $title = $scope.find('.entry-title').first();
        }
        if (!$title.length) {
            $title = $('.product_title').first();
        }
        this.$title = $title;
        if ($title.length) {
            this.baseTitle = ($title.text() || '').trim();
            this.normalizeBaseTitle();
        }
    };

    VirtualVariantDisplay.prototype.normalizeBaseTitle = function() {
        if (!this.baseTitle || !this.config || !this.config.types) {
            return;
        }
        var suffix = ' póló pulcsi';
        if (this.baseTitle.slice(-suffix.length) === suffix) {
            this.baseTitle = this.baseTitle.slice(0, -suffix.length).trim();
        }
        var self = this;
        var labels = Object.keys(this.config.types).map(function(slug){
            return self.getTypeLabel(slug).trim();
        }).filter(function(label){ return label; });
        labels.forEach(function(label){
            var dashed = ' - ' + label;
            var spaced = ' ' + label;
            if (self.baseTitle.slice(-dashed.length) === dashed) {
                self.baseTitle = self.baseTitle.slice(0, -dashed.length).trim();
            } else if (self.baseTitle.slice(-spaced.length) === spaced) {
                self.baseTitle = self.baseTitle.slice(0, -spaced.length).trim();
            }
        });
    };

    VirtualVariantDisplay.prototype.updateTitleForType = function() {
        if (!this.$title || !this.$title.length || !this.baseTitle) {
            return;
        }
        var typeLabel = this.getTypeLabel(this.state.type);
        var nextTitle = this.baseTitle;
        if (typeLabel) {
            nextTitle = this.baseTitle + ' ' + typeLabel;
        }
        this.$title.text(nextTitle);
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
        this.refreshPreview();
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
        this.updateTitleForType();
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
        this.updateSizeChartLink();
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

    VirtualVariantDisplay.prototype.createSizeChartModal = function() {
        if (this.sizeChart.$modal) {
            return;
        }
        var $modal = $('<div class="mg-size-chart-modal" role="dialog" aria-modal="true" aria-hidden="true" />');
        var $dialog = $('<div class="mg-size-chart-modal__dialog" role="document" />');
        var $header = $('<div class="mg-size-chart-modal__header" />');
        var $headerMeta = $('<div class="mg-size-chart-modal__meta" />');
        var $headerActions = $('<div class="mg-size-chart-modal__actions" />');
        this.sizeChart.$title = $('<h3 class="mg-size-chart-modal__title" />').text(this.getText('sizeChartTitle', 'Mérettáblázat'));
        this.sizeChart.$close = $('<button type="button" class="mg-size-chart-modal__close" />').text(this.getText('sizeChartClose', 'Bezárás'));
        var $body = $('<div class="mg-size-chart-modal__body" />');
        var $chartPanel = $('<div class="mg-size-chart-modal__panel mg-size-chart-modal__panel--chart" />');
        var $modelsPanel = $('<div class="mg-size-chart-modal__panel mg-size-chart-modal__panel--models" />');
        var $chartBody = $('<div class="mg-size-chart-modal__content" />');
        var $modelsBody = $('<div class="mg-size-chart-modal__content" />');
        this.sizeChart.$modelsButton = $('<button type="button" class="mg-size-chart-modal__switch" />').text(this.getText('sizeChartModelsLink', 'Nézd meg modelleken'));
        this.sizeChart.$backButton = $('<button type="button" class="mg-size-chart-modal__back" />').text(this.getText('sizeChartBack', 'Vissza a mérettáblázatra'));

        $headerMeta.append(this.sizeChart.$title);
        $headerMeta.append($headerActions);
        $header.append($headerMeta);
        $header.append(this.sizeChart.$close);
        $dialog.append($header);
        $dialog.append($body);
        $modal.append($dialog);

        $('body').append($modal);

        this.sizeChart.$modal = $modal;
        this.sizeChart.$body = $body;
        this.sizeChart.$chartPanel = $chartPanel;
        this.sizeChart.$modelsPanel = $modelsPanel;
        this.sizeChart.$chartBody = $chartBody;
        this.sizeChart.$modelsBody = $modelsBody;

        $chartPanel.append($chartBody);
        $headerActions.append(this.sizeChart.$modelsButton);
        $headerActions.append(this.sizeChart.$backButton);
        $modelsPanel.append($modelsBody);
        $body.append($chartPanel);
        $body.append($modelsPanel);

        var self = this;
        this.sizeChart.$close.on('click', function(){
            self.hideSizeChart();
        });

        this.sizeChart.$modelsButton.on('click', function(){
            self.showSizeChartModels();
        });

        this.sizeChart.$backButton.on('click', function(){
            self.showSizeChart();
        });

        $modal.on('click', function(event){
            if ($(event.target).is($modal)) {
                self.hideSizeChart();
            }
        });

        $(document).on('keydown.mgVirtualSizeChart', function(event){
            if (event.key === 'Escape' && self.sizeChart.$modal && self.sizeChart.$modal.hasClass('is-open')) {
                self.hideSizeChart();
            }
        });
    };

    VirtualVariantDisplay.prototype.updateSizeChartLink = function() {
        if (!this.sizeChart.$link) {
            return;
        }
        var chart = this.getSizeChartContent();
        var models = this.getSizeChartModelsContent();
        var hasContent = !!chart || !!models;
        this.sizeChart.$link.toggleClass('is-disabled', !hasContent);
        this.sizeChart.$link.attr('aria-disabled', hasContent ? 'false' : 'true');
        this.sizeChart.$link.prop('disabled', !hasContent);
        this.sizeChart.$link.attr('aria-expanded', this.sizeChart.$modal && this.sizeChart.$modal.hasClass('is-open') ? 'true' : 'false');
    };

    VirtualVariantDisplay.prototype.getSizeChartContent = function() {
        if (!this.state.type) {
            return '';
        }
        var typeMeta = this.config.types[this.state.type];
        if (!typeMeta || !typeMeta.size_chart) {
            return '';
        }
        return typeMeta.size_chart;
    };

    VirtualVariantDisplay.prototype.getSizeChartModelsContent = function() {
        if (!this.state.type) {
            return '';
        }
        var typeMeta = this.config.types[this.state.type];
        if (!typeMeta || !typeMeta.size_chart_models) {
            return '';
        }
        return typeMeta.size_chart_models;
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

    VirtualVariantDisplay.prototype.showSizeChart = function() {
        if (!this.sizeChart.$modal) {
            return;
        }
        var chartContent = this.getSizeChartContent();
        var modelsContent = this.getSizeChartModelsContent();
        if (!chartContent && !modelsContent) {
            return;
        }
        this.sizeChart.view = chartContent ? 'chart' : 'models';
        this.updateSizeChartPanels();
        this.sizeChart.$modal.addClass('is-open').attr('aria-hidden', 'false');
        this.sizeChart.$link.attr('aria-expanded', 'true');
        this.sizeChart.$close.trigger('focus');
        this.updateSizeChartLink();
    };

    VirtualVariantDisplay.prototype.showSizeChartModels = function() {
        if (!this.sizeChart.$modal) {
            return;
        }
        var modelsContent = this.getSizeChartModelsContent();
        if (!modelsContent) {
            return;
        }
        this.sizeChart.view = 'models';
        this.updateSizeChartPanels();
        if (!this.sizeChart.$modal.hasClass('is-open')) {
            this.sizeChart.$modal.addClass('is-open').attr('aria-hidden', 'false');
        }
        if (this.sizeChart.$backButton) {
            this.sizeChart.$backButton.trigger('focus');
        }
    };

    VirtualVariantDisplay.prototype.hideSizeChart = function() {
        if (!this.sizeChart.$modal) {
            return;
        }
        this.sizeChart.$modal.removeClass('is-open').attr('aria-hidden', 'true');
        if (this.sizeChart.$chartBody) {
            this.sizeChart.$chartBody.empty();
        }
        if (this.sizeChart.$modelsBody) {
            this.sizeChart.$modelsBody.empty();
        }
        this.sizeChart.view = 'chart';
        if (this.sizeChart.$link) {
            this.sizeChart.$link.attr('aria-expanded', 'false');
            this.sizeChart.$link.trigger('focus');
        }
        this.updateSizeChartLink();
    };

    VirtualVariantDisplay.prototype.updateSizeChartPanels = function() {
        var chartContent = this.getSizeChartContent();
        var modelsContent = this.getSizeChartModelsContent();
        var hasModels = !!modelsContent;
        var view = this.sizeChart.view || 'chart';
        if (view === 'models' && !hasModels) {
            view = 'chart';
        }
        this.sizeChart.view = view;

        if (this.sizeChart.$chartBody) {
            this.sizeChart.$chartBody.html(chartContent || '');
        }
        if (this.sizeChart.$modelsBody) {
            this.sizeChart.$modelsBody.html(modelsContent || '');
        }
        if (this.sizeChart.$modelsButton) {
            this.sizeChart.$modelsButton.toggleClass('is-disabled', !hasModels);
            this.sizeChart.$modelsButton.prop('disabled', !hasModels);
            this.sizeChart.$modelsButton.attr('aria-disabled', hasModels ? 'false' : 'true');
            this.sizeChart.$modelsButton.toggle(view === 'chart');
        }
        if (this.sizeChart.$backButton) {
            this.sizeChart.$backButton.toggle(view === 'models');
        }

        var typeLabel = this.state.type && this.config.types[this.state.type] ? this.config.types[this.state.type].label : '';
        var titleKey = view === 'models' ? 'sizeChartModelsTitle' : 'sizeChartTitle';
        var titleFallback = view === 'models' ? 'Modelleken' : 'Mérettáblázat';
        var titleBase = this.getText(titleKey, titleFallback);
        if (typeLabel) {
            this.sizeChart.$title.text(titleBase + ' – ' + typeLabel);
        } else {
            this.sizeChart.$title.text(titleBase);
        }

        if (this.sizeChart.$chartPanel) {
            this.sizeChart.$chartPanel.toggleClass('is-active', view === 'chart');
        }
        if (this.sizeChart.$modelsPanel) {
            this.sizeChart.$modelsPanel.toggleClass('is-active', view === 'models');
        }
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
        this.updateSizeChartLink();
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
            this.refreshPreviewState();
            return;
        }
        var cacheKey = this.state.type + '|' + this.state.color;
        if (this.previewCache[cacheKey]) {
            this.preview.activeUrl = this.previewCache[cacheKey];
            this.$previewInput.val(this.previewCache[cacheKey]);
            this.swapGalleryImage(this.previewCache[cacheKey]);
            this.touchPreviewCacheKey(cacheKey);
            this.refreshPreviewState();
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
            self.storePreviewCache(cacheKey, url);
            self.preview.activeUrl = url;
            self.$previewInput.val(url);
            if (self.shouldPreloadPreview() && typeof Image !== 'undefined') {
                var preload = new Image();
                preload.src = url;
            }
            self.swapGalleryImage(url);
            self.refreshPreviewState();
        }).always(function(){
            self.preview.pending = false;
        });
    };

    VirtualVariantDisplay.prototype.getPreviewColor = function() {
        if (!this.state.type || !this.state.color || !this.config.types || !this.config.types[this.state.type]) {
            return '';
        }
        var typeMeta = this.config.types[this.state.type];
        if (!typeMeta.colors || !typeMeta.colors[this.state.color]) {
            return '';
        }
        return typeMeta.colors[this.state.color].swatch || '';
    };

    VirtualVariantDisplay.prototype.getPreviewPattern = function() {
        if (this.config && this.config.visuals && this.config.visuals.defaults && this.config.visuals.defaults.pattern) {
            return this.config.visuals.defaults.pattern;
        }
        return '';
    };

    VirtualVariantDisplay.prototype.refreshPreviewState = function() {
        if (!this.preview || !this.preview.$modal) {
            return;
        }

        var pattern = this.getPreviewPattern();
        var hasPattern = !!pattern;
        var colorHex = this.getPreviewColor();
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

    VirtualVariantDisplay.prototype.applyPreviewWatermark = function(hasPattern, colorHex, watermarkText) {
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

    VirtualVariantDisplay.prototype.queueCanvasRender = function(patternUrl, colorHex, watermarkText) {
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

    VirtualVariantDisplay.prototype.paintCanvasWatermark = function(ctx, width, height, text) {
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

    VirtualVariantDisplay.prototype.renderCanvasPattern = function() {
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
        var maxWidth = 800;
        var maxHeight = 800;
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

    VirtualVariantDisplay.prototype.showPatternPreview = function() {
        if (!this.preview.$modal) {
            return;
        }
        this.preview.$modal.addClass('is-open').attr('aria-hidden', 'false');
        if (this.preview.$close) {
            this.preview.$close.trigger('focus');
        }
        this.refreshPreviewState();
    };

    VirtualVariantDisplay.prototype.hidePatternPreview = function() {
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
