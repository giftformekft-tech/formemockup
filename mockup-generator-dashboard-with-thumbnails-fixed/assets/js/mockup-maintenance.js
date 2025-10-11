(function(){
    document.addEventListener('DOMContentLoaded', function(){
        var scope = document.querySelector('.mg-maintenance-table');
        if (!scope) {
            return;
        }
        var selectAll = scope.querySelector('.mg-select-all');
        if (selectAll) {
            selectAll.addEventListener('change', function(){
                var checkboxes = scope.querySelectorAll('tbody input[type="checkbox"][name="mockup_keys[]"]');
                checkboxes.forEach(function(cb){ cb.checked = selectAll.checked; });
            });
        }
        scope.addEventListener('click', function(evt){
            var target = evt.target;
            if (target.classList.contains('mg-row-action')) {
                var form = target.closest('form');
                if (form) {
                    var hiddenAction = form.querySelector('input[name="mg_mockup_action"]');
                    if (hiddenAction) {
                        hiddenAction.value = 'bulk_regenerate';
                    }
                }
            }
        });
    });
})();
