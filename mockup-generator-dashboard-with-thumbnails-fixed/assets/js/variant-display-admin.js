(function($){
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
    }

    $(document).on('click', '.mgvd-media-select', function(e){
        e.preventDefault();
        var $button = $(this);
        var $container = $button.closest('.mgvd-media');
        var frame = wp.media({
            title: $button.data('modal-title') || (MGVD_Admin && MGVD_Admin.select) || 'Válassz képet',
            multiple: false,
            library: { type: 'image' }
        });
        frame.on('select', function(){
            var attachment = frame.state().get('selection').first().toJSON();
            $container.find('.mgvd-media-id').val(attachment.id);
            updatePreview($container, attachment);
        });
        frame.open();
    });

    $(document).on('click', '.mgvd-media-remove', function(e){
        e.preventDefault();
        var $container = $(this).closest('.mgvd-media');
        $container.find('.mgvd-media-id').val('');
        updatePreview($container, null);
    });
})(jQuery);
