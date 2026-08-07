/* global jQuery */
(function ($) {
    'use strict';

    /**
     * Helyi készlet mátrix: sor/oszlop tömeges kitöltés, módosítás jelölés,
     * és figyelmeztetés mentetlen változásokra.
     */
    $(function () {
        var $form = $('.mg-ls-form');
        if (!$form.length) {
            return;
        }

        var $grid = $form.find('.mg-ls-grid');

        function markDirty($input) {
            var initial = String($input.data('initial'));
            $input.toggleClass('is-dirty', String($input.val()) !== initial);
        }

        function isDirty() {
            return $grid.find('input.mg-ls-qty.is-dirty').length > 0;
        }

        // Biztonsági készlet mezők ki/be
        $('#mg-ls-show-safety').on('change', function () {
            $form.toggleClass('show-safety', this.checked);
        });

        $grid.on('input change', 'input.mg-ls-qty', function () {
            markDirty($(this));
        });

        // Sor kitöltése egyetlen értékkel
        $grid.on('click', '[data-mg-ls-fill-row]', function () {
            var $row = $(this).closest('tr');
            var value = window.prompt('Mennyi legyen ebben a sorban minden méretnél?', '0');
            if (value === null) {
                return;
            }
            var qty = parseInt(value, 10);
            if (isNaN(qty) || qty < 0) {
                return;
            }
            $row.find('input.mg-ls-qty').each(function () {
                $(this).val(qty);
                markDirty($(this));
            });
        });

        // Oszlop kitöltése egyetlen értékkel
        $grid.on('click', '[data-mg-ls-fill-col]', function () {
            var col = $(this).data('mg-ls-fill-col');
            var value = window.prompt('Mennyi legyen ebben az oszlopban minden színnél?', '0');
            if (value === null) {
                return;
            }
            var qty = parseInt(value, 10);
            if (isNaN(qty) || qty < 0) {
                return;
            }
            $grid.find('td.mg-ls-cell[data-col="' + col + '"]').find('input.mg-ls-qty').each(function () {
                $(this).val(qty);
                markDirty($(this));
            });
        });

        // Minden cella nullázása
        $form.on('click', '[data-mg-ls-zero]', function () {
            if (!window.confirm('Biztosan nullázod az összes cellát ennél a terméktípusnál?')) {
                return;
            }
            $grid.find('input.mg-ls-qty').each(function () {
                $(this).val(0);
                markDirty($(this));
            });
        });

        // Mentetlen változás védelem
        var submitting = false;
        $form.on('submit', function () {
            submitting = true;
        });

        $(window).on('beforeunload', function () {
            if (!submitting && isDirty()) {
                return 'Vannak mentetlen készletváltozások.';
            }
        });
    });
}(jQuery));
