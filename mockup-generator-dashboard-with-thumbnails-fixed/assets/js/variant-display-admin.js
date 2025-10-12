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
})(jQuery);
