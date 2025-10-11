(function($){
    function refreshColorChip($card, attachment) {
        if (!$card || !$card.length) {
            return;
        }
        var $chip = $card.find('.mgvd-color-chip');
        if (!$chip.length) {
            return;
        }
        if (attachment && attachment.url) {
            var url = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
            url = typeof url === 'string' ? url.replace(/"/g, '&quot;') : '';
            if (url) {
                $chip.addClass('has-image').css({
                    'background-image': 'url(' + url + ')',
                    'background-color': '#ffffff'
                });
            }
        } else {
            var color = $card.find('input[type="color"]').val() || '#ffffff';
            $chip.removeClass('has-image').css({
                'background-image': 'none',
                'background-color': color
            });
        }
    }

    function updatePreview($container, attachment) {
        var $preview = $container.find('.mgvd-media__preview');
        var $remove = $container.find('.mgvd-media-remove');
        if (attachment && attachment.url) {
            var url = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
            url = typeof url === 'string' ? url.replace(/"/g, '&quot;') : '';
            $preview.html('<img src="' + url + '" alt="" />');
            $remove.show();
        } else {
            var placeholder = (window.MGVD_Admin && MGVD_Admin.placeholder) ? MGVD_Admin.placeholder : '—';
            $preview.html('<span class="mgvd-media__placeholder">' + placeholder + '</span>');
            $remove.hide();
        }
        refreshColorChip($container.closest('.mgvd-color-card'), attachment || null);
    }

    function ensureMediaAndRun(callback) {
        var attempts = 0;
        function tryRun() {
            attempts++;
            if (typeof wp !== 'undefined' && wp.media && typeof wp.media === 'function') {
                callback();
                return true;
            }
            if (attempts >= 20) {
                var message = (window.MGVD_Admin && MGVD_Admin.mediaError) ? MGVD_Admin.mediaError : 'A média-felület nem érhető el.';
                window.alert(message);
                return true;
            }
            return false;
        }

        if (tryRun()) {
            return;
        }

        var poll = setInterval(function(){
            if (tryRun()) {
                clearInterval(poll);
            }
        }, 150);
    }

    function openMediaFrame($container) {
        if (typeof wp === 'undefined' || !wp.media) {
            return;
        }
        var existing = $container.data('mgvdFrame');
        if (existing) {
            existing.open();
            return;
        }
        var $button = $container.find('.mgvd-media-select');
        var modalTitle = $button.data('modal-title') || (window.MGVD_Admin && MGVD_Admin.select) || 'Válassz képet';
        var frame = wp.media({
            title: modalTitle,
            multiple: false,
            library: { type: 'image' }
        });
        frame.on('select', function(){
            var model = frame.state().get('selection').first();
            if (!model) {
                return;
            }
            var attachment = model.toJSON();
            $container.find('.mgvd-media-id').val(attachment.id || '');
            updatePreview($container, attachment);
        });
        frame.on('open', function(){
            var currentId = parseInt($container.find('.mgvd-media-id').val(), 10);
            if (!currentId) {
                return;
            }
            var selection = frame.state().get('selection');
            var attachment = wp.media.attachment(currentId);
            if (!attachment) {
                return;
            }
            attachment.fetch();
            selection.reset([attachment]);
        });
        $container.data('mgvdFrame', frame);
        frame.open();
    }

    $(document).on('click', '.mgvd-media-select, .mgvd-media__preview', function(e){
        e.preventDefault();
        var $container = $(this).closest('.mgvd-media');
        if (!$container.length) {
            return;
        }
        ensureMediaAndRun(function(){
            openMediaFrame($container);
        });
    });

    $(document).on('keydown', '.mgvd-media__preview', function(e){
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            var $container = $(this).closest('.mgvd-media');
            if ($container.length) {
                ensureMediaAndRun(function(){
                    openMediaFrame($container);
                });
            }
        }
    });

    $(document).on('click', '.mgvd-media-remove', function(e){
        e.preventDefault();
        var $container = $(this).closest('.mgvd-media');
        $container.find('.mgvd-media-id').val('');
        updatePreview($container, null);
    });

    $(document).on('input change', '.mgvd-color-card input[type="color"]', function(){
        var $card = $(this).closest('.mgvd-color-card');
        if (!$card.length) {
            return;
        }
        if ($card.find('.mgvd-media-id').val()) {
            return;
        }
        refreshColorChip($card, null);
    });
})(jQuery);
