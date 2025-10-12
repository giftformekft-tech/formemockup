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
    var legacyEditorState = {
        previousHandler: null,
        previousEditor: null,
        activeType: null
    };

    function restoreLegacyHandler() {
        if (legacyEditorState.previousHandler && window.wp && wp.media && wp.media.editor) {
            wp.media.editor.send.attachment = legacyEditorState.previousHandler;
        }
        legacyEditorState.previousHandler = null;
        if (typeof window.wpActiveEditor !== 'undefined') {
            window.wpActiveEditor = legacyEditorState.previousEditor || '';
        }
        legacyEditorState.previousEditor = null;
        legacyEditorState.activeType = null;
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

    function openThumbnailPicker($container) {
        var typeKey = $container.data('type');
        if (!typeKey) {
            return;
        }

        if (!window.wp || !wp.media) {
            var unavailableMessage = strings.mediaUnavailable || 'A média könyvtár nem érhető el.';
            window.alert(unavailableMessage);
            return;
        }

        if (typeof wp.media === 'function') {
            var frame = mediaFrames[typeKey];
            if (!frame) {
                frame = wp.media({
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
            return;
        }

        if (wp.media.editor && typeof wp.media.editor.open === 'function') {
            var legacyTarget = $container.data('legacy-target');
            if (!legacyTarget) {
                legacyTarget = 'mgvd-thumbnail-legacy-' + typeKey;
            }

            legacyEditorState.previousHandler = wp.media.editor.send.attachment;
            legacyEditorState.previousEditor = (typeof window.wpActiveEditor !== 'undefined') ? window.wpActiveEditor : null;
            legacyEditorState.activeType = typeKey;

            if (!document.getElementById(legacyTarget)) {
                var legacyTextarea = document.createElement('textarea');
                legacyTextarea.setAttribute('id', legacyTarget);
                legacyTextarea.className = 'mgvd-thumbnail-legacy-target';
                legacyTextarea.setAttribute('aria-hidden', 'true');
                legacyTextarea.style.display = 'none';
                $container.append(legacyTextarea);
            }

            wp.media.editor.send.attachment = function(props, attachment){
                if (legacyEditorState.previousHandler) {
                    legacyEditorState.previousHandler.apply(this, arguments);
                }

                var picked = pickUrlFromAttachment(attachment);
                setThumbnailFields($container, picked.id, picked.url);
                restoreLegacyHandler();
            };

            window.wpActiveEditor = legacyTarget;

            $(document).one('tb_unload.mgvdLegacy', function(){
                restoreLegacyHandler();
                $(document).off('tb_unload.mgvdLegacy');
            });

            wp.media.editor.open(legacyTarget);
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
