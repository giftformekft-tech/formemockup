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

    var mediaFrames = {};
    var fallbackState = {
        previousSendToEditor: null,
        previousEditorHandler: null,
        previousEditorId: null,
        activeType: null,
        activeContainer: null
    };

    function clearFallbackState() {
        fallbackState.activeType = null;
        fallbackState.activeContainer = null;
    }

    function restoreFallbackHandlers() {
        if (fallbackState.previousEditorHandler && window.wp && wp.media && wp.media.editor) {
            wp.media.editor.send.attachment = fallbackState.previousEditorHandler;
        }
        fallbackState.previousEditorHandler = null;

        if (typeof window.wpActiveEditor !== 'undefined') {
            window.wpActiveEditor = fallbackState.previousEditorId || '';
        }
        fallbackState.previousEditorId = null;

        if (fallbackState.previousSendToEditor) {
            window.send_to_editor = fallbackState.previousSendToEditor;
        }
        fallbackState.previousSendToEditor = null;

        clearFallbackState();
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

    function parseImageHtml(html) {
        if (!html) {
            return { id: '', url: '' };
        }

        var $wrapper = $('<div />').html(html);
        var $img = $wrapper.find('img').first();

        if (!$img.length && $wrapper.is('img')) {
            $img = $wrapper;
        }

        if (!$img.length) {
            return { id: '', url: '' };
        }

        var url = $img.attr('src') || '';
        var id = '';

        var className = $img.attr('class') || '';
        var idMatch = className.match(/wp-image-(\d+)/);
        if (idMatch && idMatch[1]) {
            id = idMatch[1];
        }

        if (!id) {
            var dataId = $img.data('attachment-id') || $img.data('id') || '';
            if (dataId) {
                id = dataId;
            }
        }

        return {
            id: id,
            url: url
        };
    }

    function openWithMediaFrame($container, typeKey) {
        if (!window.wp || typeof wp.media !== 'function') {
            return false;
        }

        var frame = mediaFrames[typeKey];
        if (!frame) {
            frame = wp.media({
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

            frame.on('select', function(){
                var selection = frame.state().get('selection');
                if (!selection || !selection.first) {
                    return;
                }

                var attachment = selection.first().toJSON();
                var picked = pickUrlFromAttachment(attachment);
                setThumbnailFields($container, picked.id, picked.url);
            });

            mediaFrames[typeKey] = frame;
        }

        frame.open();
        return true;
    }

    function ensureLegacyTextarea($container, legacyTarget) {
        if (document.getElementById(legacyTarget)) {
            return;
        }
        var legacyTextarea = document.createElement('textarea');
        legacyTextarea.setAttribute('id', legacyTarget);
        legacyTextarea.className = 'mgvd-thumbnail-legacy-target';
        legacyTextarea.setAttribute('aria-hidden', 'true');
        legacyTextarea.style.display = 'none';
        $container.append(legacyTextarea);
    }

    function openWithMediaEditor($container, typeKey) {
        if (!window.wp || !wp.media || !wp.media.editor || typeof wp.media.editor.open !== 'function') {
            return false;
        }

        var legacyTarget = $container.data('legacy-target');
        if (!legacyTarget) {
            legacyTarget = 'mgvd-thumbnail-legacy-' + typeKey;
        }

        ensureLegacyTextarea($container, legacyTarget);

        fallbackState.previousEditorHandler = wp.media.editor.send.attachment;
        fallbackState.previousEditorId = (typeof window.wpActiveEditor !== 'undefined') ? window.wpActiveEditor : null;
        fallbackState.activeType = typeKey;
        fallbackState.activeContainer = $container;

        wp.media.editor.send.attachment = function(props, attachment){
            if (fallbackState.previousEditorHandler) {
                fallbackState.previousEditorHandler.apply(this, arguments);
            }

            var picked = pickUrlFromAttachment(attachment);
            if (fallbackState.activeContainer) {
                setThumbnailFields(fallbackState.activeContainer, picked.id, picked.url);
            }

            restoreFallbackHandlers();
        };

        window.wpActiveEditor = legacyTarget;

        $(document).one('tb_unload.mgvdLegacyEditor', function(){
            restoreFallbackHandlers();
            $(document).off('tb_unload.mgvdLegacyEditor');
        });

        wp.media.editor.open(legacyTarget);
        return true;
    }

    function buildThickboxUrl(baseUrl) {
        if (!baseUrl) {
            return '';
        }
        var separator = baseUrl.indexOf('?') === -1 ? '?' : '&';
        return baseUrl + separator + 'type=image&TB_iframe=1';
    }

    function openWithThickbox($container, typeKey) {
        if (typeof window.tb_show !== 'function' || typeof window.tb_remove !== 'function') {
            return false;
        }

        var baseUrl = strings.uploadFrameUrl || '';
        if (!baseUrl && window.ajaxurl) {
            baseUrl = window.ajaxurl.replace('admin-ajax.php', 'media-upload.php');
        }

        var thickboxUrl = buildThickboxUrl(baseUrl);
        if (!thickboxUrl) {
            return false;
        }

        fallbackState.previousSendToEditor = window.send_to_editor;
        fallbackState.activeContainer = $container;
        fallbackState.activeType = typeKey;

        window.send_to_editor = function(html) {
            var picked = parseImageHtml(html);
            if (fallbackState.activeContainer && picked.url) {
                setThumbnailFields(fallbackState.activeContainer, picked.id, picked.url);
            }

            if (typeof fallbackState.previousSendToEditor === 'function') {
                fallbackState.previousSendToEditor(html);
            }

            restoreFallbackHandlers();
            try {
                tb_remove();
            } catch (err) {
                // Ignore if Thickbox is already closed.
            }
        };

        $(document).one('tb_unload.mgvdLegacyHtml', function(){
            restoreFallbackHandlers();
            $(document).off('tb_unload.mgvdLegacyHtml');
        });

        tb_show(strings.thumbnailFrameTitle || 'Kiskép feltöltése', thickboxUrl);
        return true;
    }

    function openThumbnailPicker($container) {
        var typeKey = $container.data('type');
        if (!typeKey) {
            return;
        }

        if (openWithMediaFrame($container, typeKey)) {
            return;
        }

        if (openWithMediaEditor($container, typeKey)) {
            return;
        }

        if (openWithThickbox($container, typeKey)) {
            return;
        }

        var unavailableMessage = strings.mediaUnavailable || 'A média könyvtár nem érhető el.';
        window.alert(unavailableMessage);
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
