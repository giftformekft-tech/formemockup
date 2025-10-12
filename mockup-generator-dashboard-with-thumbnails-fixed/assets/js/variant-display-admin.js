(function($){
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

    function updateIconPreview($container, iconUrl) {
        var $preview = $container.find('.mgvd-type-icon__preview');
        if (!$preview.length) {
            return;
        }

        var label = $container.data('label') || '';
        var placeholder = $container.data('placeholder') || '';
        $preview.removeClass('has-icon');

        if (iconUrl) {
            var $img = $('<img />');
            $img.attr('src', iconUrl);
            if (label) {
                $img.attr('alt', label);
            }
            $preview.empty().append($img);
            $preview.addClass('has-icon');
        } else {
            $preview.empty();
            if (placeholder) {
                $preview.append($('<span class="mgvd-type-icon__placeholder" />').text(placeholder));
            }
        }

        var $removeBtn = $container.find('.mgvd-icon-button--remove');
        if ($removeBtn.length) {
            $removeBtn.prop('disabled', !iconUrl);
        }
    }

    function setIconFields($container, attachmentId, iconUrl) {
        $container.find('.mgvd-icon-field--id').val(attachmentId || '');
        $container.find('.mgvd-icon-field--url').val(iconUrl || '');
        updateIconPreview($container, iconUrl);
    }

    var mediaFrames = {};

    function openIconPicker($container) {
        if (!window.wp || !wp.media || typeof wp.media !== 'function') {
            window.alert('A média könyvtár nem érhető el.');
            return;
        }

        var typeKey = $container.data('type');
        if (!typeKey) {
            return;
        }

        var frame = mediaFrames[typeKey];
        if (!frame) {
            frame = wp.media({
                title: 'Ikon kiválasztása',
                button: {
                    text: 'Kiválasztás'
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
                setIconFields($container, attachmentId, url);
            });

            mediaFrames[typeKey] = frame;
        }

        frame.open();
    }

    $(document).on('click', '.mgvd-icon-button--select', function(e){
        e.preventDefault();
        var $container = $(this).closest('.mgvd-type-icon');
        if (!$container.length) {
            return;
        }
        openIconPicker($container);
    });

    $(document).on('click', '.mgvd-icon-button--remove', function(e){
        e.preventDefault();
        var $container = $(this).closest('.mgvd-type-icon');
        if (!$container.length) {
            return;
        }
        setIconFields($container, '', '');
    });

    $(document).ready(function(){
        $('.mgvd-type-icon').each(function(){
            var $container = $(this);
            var iconUrl = $container.find('.mgvd-icon-field--url').val();
            if (!iconUrl) {
                var attachmentId = $container.find('.mgvd-icon-field--id').val();
                if (!attachmentId) {
                    updateIconPreview($container, '');
                    return;
                }
            }
            updateIconPreview($container, iconUrl);
        });
    });
})(jQuery);
