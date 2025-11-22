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

    function buildToggle(hasPatterns) {
        var $wrapper = $('<div class="mg-archive-toggle" role="group" />');
        var $label = $('<span class="mg-archive-toggle__label" />').text(texts.viewLabel || 'Nézet');
        var $mockupBtn = $('<button type="button" class="mg-archive-toggle__btn" />')
            .attr('data-mode', 'mockup')
            .text(texts.mockup || 'Mockup');
        var $patternBtn = $('<button type="button" class="mg-archive-toggle__btn" />')
            .attr('data-mode', 'pattern')
            .text(texts.pattern || 'Minta');

        if (!hasPatterns) {
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
            canvasWrap: $canvasWrap,
            canvas: $canvas,
            fallback: $fallback
        };
    }

    function renderPattern(entry, $canvas, $fallback, $wrap) {
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

            if ($wrap && $wrap.length) {
                var ratio = targetH / targetW;
                $wrap.css('padding-top', (ratio * 100) + '%');
            }

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

    function applyMode($card, mode, entry) {
        var isPattern = (mode === 'pattern' && entry.pattern);
        $card.toggleClass('mg-archive-mode-pattern', !!isPattern);
        $card.toggleClass('mg-archive-mode-mockup', !isPattern);
    }

    function initCard($card) {
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

        var $mockup = wrapThumbnail($link);
        var pattern = createPatternContainer($link, $mockup);

        $card.data('mgArchivePattern', pattern);
        $card.data('mgArchiveEntry', entry);
        $card.data('mgArchiveBound', true);
    }

    function bindCards($newCards, currentMode, toggleState) {
        if (!$newCards || !$newCards.length) {
            return false;
        }

        var foundPattern = false;

        $newCards.each(function(){
            var $card = $(this);
            if (!$card.length) {
                return;
            }

            initCard($card);
            if (!$card.data('mgArchiveBound')) {
                return;
            }

            var entry = $card.data('mgArchiveEntry');
            if (entry && entry.pattern && toggleState.patternBtn && !toggleState.hasPatterns) {
                toggleState.hasPatterns = true;
                foundPattern = true;
                toggleState.patternBtn.removeAttr('aria-disabled').removeClass('is-disabled');
            }

            var cardMode = (currentMode === 'pattern' && entry && entry.pattern) ? 'pattern' : 'mockup';
            applyMode($card, cardMode, entry);

            if (cardMode === 'pattern') {
                var pattern = $card.data('mgArchivePattern');
                if (pattern && pattern.canvas) {
                    renderPattern(entry, pattern.canvas, pattern.fallback, pattern.canvasWrap);
                }
            }
        });

        return foundPattern;
    }

    $(function(){
        if (!products || !Object.keys(products).length) {
            return;
        }

        var initialMode = getStoredMode();
        var currentMode = initialMode;
        var hasPatterns = false;
        var $cards = $('.woocommerce ul.products li.product, ul.products li.product, .products li.product');

        $cards.each(function(){
            var $card = $(this);
            var productId = findProductId($card);
            if (productId && products[productId] && products[productId].pattern) {
                hasPatterns = true;
            }
            initCard($card);
        });

        if (!hasPatterns) {
            saveMode('mockup');
            initialMode = 'mockup';
        }

        var toggle = buildToggle(hasPatterns);
        var $grid = $('.woocommerce ul.products, ul.products, .products').first();

        if ($grid.length) {
            $grid.before(toggle.container);
        }

        function setMode(mode) {
            var targetMode = (mode === 'pattern' && hasPatterns) ? 'pattern' : 'mockup';
            currentMode = targetMode;
            saveMode(targetMode);
            applyActive(toggle, targetMode);

            $cards.each(function(){
                var $card = $(this);
                if (!$card.data('mgArchiveBound')) {
                    return;
                }
                var entry = $card.data('mgArchiveEntry');
                var pattern = $card.data('mgArchivePattern');
                var cardMode = (targetMode === 'pattern' && entry.pattern) ? 'pattern' : 'mockup';
                applyMode($card, cardMode, entry);
                if (cardMode === 'pattern' && pattern && pattern.canvas) {
                    renderPattern(entry, pattern.canvas, pattern.fallback, pattern.canvasWrap);
                }
            });
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

        if ($grid.length && window.MutationObserver) {
            var observer = new MutationObserver(function(mutations){
                mutations.forEach(function(mutation){
                    if (!mutation.addedNodes || !mutation.addedNodes.length) {
                        return;
                    }
                    var $products = $();
                    $(mutation.addedNodes).each(function(){
                        var $node = $(this);
                        if ($node.hasClass('product')) {
                            $products = $products.add($node);
                        }
                        if ($node.find) {
                            $products = $products.add($node.find('li.product'));
                        }
                    });

                    if ($products.length) {
                        $cards = $cards.add($products);
                        var newlyFound = bindCards($products, currentMode, { hasPatterns: hasPatterns, patternBtn: toggle.patternBtn });
                        if (newlyFound) {
                            hasPatterns = true;
                            applyActive(toggle, currentMode);
                            if (currentMode === 'pattern') {
                                setMode('pattern');
                            }
                        }
                    }
                });
            });

            observer.observe($grid.get(0), { childList: true, subtree: true });
        }

        setMode(initialMode);
    });
})(jQuery);
