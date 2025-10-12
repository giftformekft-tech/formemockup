(function($){
    var strings = window.MGVDAdminL10n || {};

    function refreshColorChip($card) {
        if (!$card || !$card.length) {
            return;
        }
        var $chip = $card.find('.mgvd-color-chip');
        if (!$chip.length) {
            return;
        }
        var color = $card.find('input[type="color"]').val() || '#ffffff';
        $chip.css({
            'background-color': color,
            'background-image': 'none'
        });
    }

    $(document).ready(function(){
        $('.mgvd-color-card').each(function(){
            refreshColorChip($(this));
        });
    });

    $(document).on('input change', '.mgvd-color-card input[type="color"]', function(){
        var $card = $(this).closest('.mgvd-color-card');
        refreshColorChip($card);
    });

    function updateThumbnailPreview($container, thumbnailUrl) {
        var $preview = $container.find('.mgvd-type-thumbnail__preview');
        if (!$preview.length) {
            return;
        }

        var label = $container.data('label') || '';
        var placeholder = $container.data('placeholder') || '';
        $preview.removeClass('has-thumbnail');

        if (thumbnailUrl) {
            var $img = $('<img />');
            $img.attr('src', thumbnailUrl);
            if (label) {
                $img.attr('alt', label);
            }
            $preview.empty().append($img);
            $preview.addClass('has-thumbnail');
        } else {
            $preview.empty();
            if (placeholder) {
                $preview.append($('<span class="mgvd-type-thumbnail__placeholder" />').text(placeholder));
            }
        }

        var $removeBtn = $container.find('.mgvd-thumbnail-button--remove');
        if ($removeBtn.length) {
            $removeBtn.prop('disabled', !thumbnailUrl);
        }
    }

    function setThumbnailFields($container, attachmentId, thumbnailUrl) {
        $container.find('.mgvd-thumbnail-field--id').val(attachmentId || '');
        $container.find('.mgvd-thumbnail-field--url').val(thumbnailUrl || '');
        updateThumbnailPreview($container, thumbnailUrl);
    }

    function pickUrlFromAttachment(attachment) {
        if (!attachment) {
            return { id: '', url: '' };
        }

        var attachmentId = attachment.id || '';
        var url = '';

        if (attachment.sizes) {
            if (attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) {
                url = attachment.sizes.thumbnail.url;
            } else if (attachment.sizes.medium && attachment.sizes.medium.url) {
                url = attachment.sizes.medium.url;
            }
        }

        if (!url && attachment.url) {
            url = attachment.url;
        }

        return {
            id: attachmentId,
            url: url
        };
    }

    var mediaFrames = {};

    function ensureMediaReady() {
        return !!(window.wp && typeof wp.media === 'function');
    }

    function resolveSelectionData(selection) {
        if (!selection || typeof selection.first !== 'function') {
            return { id: '', url: '' };
        }

        var first = selection.first();
        if (!first || typeof first.toJSON !== 'function') {
            return { id: '', url: '' };
        }

        return pickUrlFromAttachment(first.toJSON());
    }

    function getMediaFrame(typeKey) {
        if (!mediaFrames[typeKey]) {
            mediaFrames[typeKey] = wp.media({
                frame: 'select',
                title: strings.thumbnailFrameTitle || 'Kiskép feltöltése',
                button: {
                    text: strings.thumbnailFrameButton || 'Kiskép feltöltése'
                },
                library: {
                    type: 'image'
                },
                multiple: false
            });
        }

        return mediaFrames[typeKey];
    }

    function openThumbnailPicker($container) {
        var typeKey = $container.data('type');
        if (!typeKey) {
            return;
        }

        if (!ensureMediaReady()) {
            var unavailableMessage = strings.mediaUnavailable || 'A média könyvtár nem érhető el.';
            window.alert(unavailableMessage);
            return;
        }

        var frame = getMediaFrame(typeKey);
        frame.mgvdTarget = $container;

        frame.off('select.mgvd').on('select.mgvd', function(){
            var picked = resolveSelectionData(frame.state().get('selection'));
            if (frame.mgvdTarget && picked.url) {
                setThumbnailFields(frame.mgvdTarget, picked.id, picked.url);
            } else if (frame.mgvdTarget) {
                setThumbnailFields(frame.mgvdTarget, '', '');
            }
        });

        frame.off('open.mgvd').on('open.mgvd', function(){
            if (!frame.mgvdTarget) {
                return;
            }

            var currentId = parseInt(frame.mgvdTarget.find('.mgvd-thumbnail-field--id').val(), 10);
            var selection = frame.state().get('selection');
            if (!currentId) {
                if (selection && typeof selection.reset === 'function') {
                    selection.reset();
                }
                return;
            }

            if (!selection || typeof selection.reset !== 'function' || !wp.media || typeof wp.media.attachment !== 'function') {
                return;
            }

            var attachment = wp.media.attachment(currentId);
            if (attachment && typeof attachment.fetch === 'function') {
                attachment.fetch();
            }
            selection.reset(attachment ? [attachment] : []);
        });

        frame.open();
    }

    $(document).on('click', '.mgvd-thumbnail-button--select', function(e){
        e.preventDefault();
        var $container = $(this).closest('.mgvd-type-thumbnail');
        if (!$container.length) {
            return;
        }
        openThumbnailPicker($container);
    });

    $(document).on('click', '.mgvd-thumbnail-button--remove', function(e){
        e.preventDefault();
        var $container = $(this).closest('.mgvd-type-thumbnail');
        if (!$container.length) {
            return;
        }
        setThumbnailFields($container, '', '');
    });

    $(document).ready(function(){
        $('.mgvd-type-thumbnail').each(function(){
            var $container = $(this);
            var thumbnailUrl = $container.find('.mgvd-thumbnail-field--url').val();
            if (!thumbnailUrl) {
                var attachmentId = $container.find('.mgvd-thumbnail-field--id').val();
                if (!attachmentId) {
                    updateThumbnailPreview($container, '');
                    return;
                }
            }
            updateThumbnailPreview($container, thumbnailUrl);
        });
    });
})(jQuery);
