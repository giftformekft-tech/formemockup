(function($){
    $(document).ready(function(){
        var $table = $('.mg-matrix-table');
        if (!$table.length) return;

        // Add visual cues
        $table.find('th').css('cursor', 'pointer').attr('title', 'Kattints az összes kijelöléséhez/megszüntetéséhez');
        $table.find('td:first-child').css('cursor', 'pointer').attr('title', 'Kattints az összes kijelöléséhez/megszüntetéséhez');

        // Column Toggle
        $table.find('thead th').each(function(colIndex){
            if (colIndex === 0) return; // Skip first column (headers)
            $(this).on('click', function(){
                var $checkboxes = $table.find('tbody tr').map(function(){
                    return $(this).find('td').eq(colIndex).find('input[type="checkbox"]');
                });
                
                var allChecked = true;
                $checkboxes.each(function(){ if(!$(this).prop('checked')) allChecked = false; });
                
                $checkboxes.each(function(){ $(this).prop('checked', !allChecked); });
            });
        });

        // Row Toggle
        $table.find('tbody tr').each(function(){
            var $row = $(this);
            $row.find('td').eq(0).on('click', function(){
                var $checkboxes = $row.find('input[type="checkbox"]');
                var allChecked = true;
                $checkboxes.each(function(){ if(!$(this).prop('checked')) allChecked = false; });
                $checkboxes.each(function(){ $(this).prop('checked', !allChecked); });
            });
        });

        // Global Actions
        var $actions = $('<div class="mg-matrix-actions" style="margin-bottom: 10px;"></div>');
        var $selectAll = $('<button type="button" class="button">Összes kijelölése</button>');
        var $deselectAll = $('<button type="button" class="button">Összes törlése</button>');
        
        $selectAll.on('click', function(){ $table.find('input[type="checkbox"]').prop('checked', true); });
        $deselectAll.on('click', function(){ $table.find('input[type="checkbox"]').prop('checked', false); });

        $actions.append($selectAll).append(' ').append($deselectAll);
        $table.before($actions);
    });
})(jQuery);
