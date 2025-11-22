(function($){
    var config = window.MG_ARCHIVE_PREVIEW || {};
    var products = config.products || {};
    var texts = config.text || {};
    var storageKey = config.storageKey || 'mgArchiveViewMode';

    function getStoredMode() {
        var fallback = 'mockup';
        try {
            var stored = window.localStorage ? window.localStorage.getItem(storageKey) : null;
            if (stored === 'mockup' || stored === 'pattern') {
                return stored;
            }
        } catch (err) {}
        return fallback;
    }

    function saveMode(mode) {
        try {
            if (window.localStorage) {
                window.localStorage.setItem(storageKey, mode);
            }
        } catch (err) {}
    }

    function findProductId($card) {
        var marker = $card.find('.mg-archive-product-marker[data-product-id]').first();
        if (marker.length) {
            return marker.attr('data-product-id');
        }

        var addToCart = $card.find('.add_to_cart_button[data-product_id]').first();
        if (addToCart.length) {
            return addToCart.attr('data-product_id');
        }

        var classMatch = ($card.attr('class') || '').match(/product-([0-9]+)/);
        if (classMatch && classMatch[1]) {
            return classMatch[1];
        }

        return null;
    }

    function buildToggle(entry) {
        var $wrapper = $('<div class="mg-archive-toggle" role="group" />');
        var $label = $('<span class="mg-archive-toggle__label" />').text(texts.viewLabel || 'Nézet');
        var $mockupBtn = $('<button type="button" class="mg-archive-toggle__btn" />')
            .attr('data-mode', 'mockup')
            .text(texts.mockup || 'Mockup');
        var $patternBtn = $('<button type="button" class="mg-archive-toggle__btn" />')
            .attr('data-mode', 'pattern')
            .text(texts.pattern || 'Minta');

        if (!entry.pattern) {
            $patternBtn.attr('aria-disabled', 'true').addClass('is-disabled');
        }

        $wrapper.append($label, $mockupBtn, $patternBtn);

        return {
            container: $wrapper,
            mockupBtn: $mockupBtn,
            patternBtn: $patternBtn
        };
    }

    function applyActive(toggle, mode) {
        toggle.mockupBtn.removeClass('is-active').attr('aria-pressed', 'false');
        toggle.patternBtn.removeClass('is-active').attr('aria-pressed', 'false');

        if (mode === 'pattern') {
            toggle.patternBtn.addClass('is-active').attr('aria-pressed', 'true');
        } else {
            toggle.mockupBtn.addClass('is-active').attr('aria-pressed', 'true');
        }
    }

    function wrapThumbnail($link) {
        var $img = $link.find('img').first();
        var $mockup = $('<div class="mg-archive-view__mockup" />');
        if ($img.length) {
            $img.before($mockup);
            $mockup.append($img);
        } else {
            $link.prepend($mockup);
        }
        return $mockup;
    }

    function createPatternContainer($link, $before) {
        var $pattern = $('<div class="mg-archive-view__pattern" aria-live="polite" />');
        var $canvasWrap = $('<div class="mg-archive-view__pattern-canvas" />');
        var $canvas = $('<canvas class="mg-archive-view__canvas" />');
        var $fallback = $('<div class="mg-archive-view__fallback" />');

        $canvasWrap.append($canvas);
        $pattern.append($canvasWrap, $fallback);
        if ($before && $before.length) {
            $pattern.insertBefore($before);
        } else {
            $link.prepend($pattern);
        }

        return {
            container: $pattern,
            canvas: $canvas,
            fallback: $fallback
        };
    }

    function renderPattern(entry, $canvas, $fallback) {
        if (!entry.pattern) {
            $fallback.text(texts.patternMissing || '').addClass('is-visible');
            $canvas.addClass('is-hidden');
            return;
        }

        var canvas = $canvas.get(0);
        if (!canvas || !canvas.getContext) {
            $fallback.text(texts.patternMissing || '').addClass('is-visible');
            $canvas.addClass('is-hidden');
            return;
        }

        var ctx = canvas.getContext('2d');
        var img = new Image();
        var completed = false;
        var missingColorMessage = (!entry.color && texts.colorMissing) ? texts.colorMissing : '';
        var timer = window.setTimeout(function(){
            if (completed) {
                return;
            }
            $fallback.text(texts.patternMissing || '').addClass('is-visible');
            $canvas.addClass('is-hidden');
        }, 1500);

        function finishWithFallback(message) {
            completed = true;
            window.clearTimeout(timer);
            $fallback.text(message || texts.patternMissing || '').addClass('is-visible');
            $canvas.addClass('is-hidden');
        }

        img.onload = function(){
            completed = true;
            window.clearTimeout(timer);
            var maxSide = 900;
            var width = img.naturalWidth || img.width || 1;
            var height = img.naturalHeight || img.height || 1;
            var scale = 1;
            if (width > maxSide || height > maxSide) {
                scale = maxSide / Math.max(width, height);
            }
            var targetW = Math.max(1, Math.round(width * scale));
            var targetH = Math.max(1, Math.round(height * scale));

            canvas.width = targetW;
            canvas.height = targetH;

            ctx.fillStyle = entry.color || '#f7f7f7';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

            $canvas.removeClass('is-hidden');
            if (missingColorMessage) {
                $fallback.text(missingColorMessage).addClass('is-visible');
            } else {
                $fallback.removeClass('is-visible').text('');
            }
        };

        img.onerror = function(){
            finishWithFallback(texts.patternMissing || '');
        };

        try {
            if (entry.pattern.indexOf('data:') !== 0) {
                img.crossOrigin = 'anonymous';
            }
        } catch (err) {}

        img.src = entry.pattern;
    }

    function applyMode($card, mode, entry, toggle) {
        var isPattern = (mode === 'pattern' && entry.pattern);
        $card.toggleClass('mg-archive-mode-pattern', !!isPattern);
        $card.toggleClass('mg-archive-mode-mockup', !isPattern);
        applyActive(toggle, isPattern ? 'pattern' : 'mockup');
    }

    function initCard($card, initialMode) {
        var productId = findProductId($card);
        if (!productId || !products[productId]) {
            return;
        }
        if ($card.data('mgArchiveBound')) {
            return;
        }

        var entry = products[productId];
        var $link = $card.find('.woocommerce-LoopProduct-link, .woocommerce-loop-product__link').first();
        if (!$link.length) {
            return;
        }

        $card.addClass('mg-archive-view-card');
        $link.addClass('mg-archive-view__link');

        var toggle = buildToggle(entry);
        $card.prepend(toggle.container);

        var $mockup = wrapThumbnail($link);
        var pattern = createPatternContainer($link, $mockup);

        var mode = initialMode === 'pattern' && entry.pattern ? 'pattern' : 'mockup';

        function setMode(nextMode) {
            mode = (nextMode === 'pattern' && entry.pattern) ? 'pattern' : 'mockup';
            saveMode(mode);
            applyMode($card, mode, entry, toggle);
            if (mode === 'pattern') {
                renderPattern(entry, pattern.canvas, pattern.fallback);
            }
        }

        toggle.mockupBtn.on('click', function(event){
            event.preventDefault();
            setMode('mockup');
        });
        toggle.patternBtn.on('click', function(event){
            event.preventDefault();
            if ($(this).is('[aria-disabled="true"]')) {
                return;
            }
            setMode('pattern');
        });

        setMode(mode);
        $card.data('mgArchiveBound', true);
    }

    $(function(){
        if (!products || !Object.keys(products).length) {
            return;
        }

        var initialMode = getStoredMode();
        $('.woocommerce ul.products li.product, ul.products li.product, .products li.product').each(function(){
            initCard($(this), initialMode);
        });
    });
})(jQuery);
