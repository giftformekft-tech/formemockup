(function () {
    function relocateFields() {
        var forms = document.querySelectorAll('form.cart');
        if (!forms.length) {
            return;
        }
        Array.prototype.forEach.call(forms, function (form) {
            var aboveBlocks = form.querySelectorAll('.mg-custom-fields--placement-above_variants');
            if (!aboveBlocks.length) {
                return;
            }
            var reference = form.querySelector('.variations');
            Array.prototype.forEach.call(aboveBlocks, function (block) {
                if (block.dataset.mgcfRelocated === '1') {
                    return;
                }
                if (reference && reference.parentNode) {
                    reference.parentNode.insertBefore(block, reference);
                } else if (form.firstElementChild) {
                    form.insertBefore(block, form.firstElementChild);
                } else {
                    form.appendChild(block);
                }
                block.dataset.mgcfRelocated = '1';
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', relocateFields);
    } else {
        relocateFields();
    }
})();
