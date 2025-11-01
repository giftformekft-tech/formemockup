(function () {
    var MAX_RETRIES = 10;
    var RETRY_DELAY = 250;

    function insertAfter(reference, element) {
        if (!reference || !reference.parentNode) {
            return;
        }
        if (reference.nextSibling) {
            reference.parentNode.insertBefore(element, reference.nextSibling);
        } else {
            reference.parentNode.appendChild(element);
        }
    }

    function applySectionClasses(block, placement) {
        if (!block || !block.classList) {
            return;
        }
        block.classList.add('mg-variant-section', 'mg-variant-section--custom');
        var sanitized = placement ? placement.toLowerCase().replace(/[^a-z0-9_-]/g, '') : '';
        if (sanitized) {
            block.classList.add('mg-variant-section--custom-' + sanitized);
        }
        if (placement === 'variant_top') {
            block.classList.add('mg-variant-section--custom-top');
        }
        if (placement === 'variant_bottom') {
            block.classList.add('mg-variant-section--custom-bottom');
        }
        var heading = block.querySelector('.mg-custom-fields__title');
        if (heading && heading.classList) {
            heading.classList.add('mg-variant-section__label');
        }
    }

    function moveBlockToPlacement(block, form, variantDisplay) {
        if (!block || block.dataset.mgcfRelocated === '1') {
            return true;
        }
        var placement = block.getAttribute('data-mgcf-placement') || 'variant_bottom';
        applySectionClasses(block, placement);

        if (!variantDisplay) {
            return false;
        }

        var target;
        switch (placement) {
            case 'variant_top':
                if (variantDisplay.firstElementChild) {
                    variantDisplay.insertBefore(block, variantDisplay.firstElementChild);
                } else {
                    variantDisplay.appendChild(block);
                }
                break;
            case 'after_type':
                target = variantDisplay.querySelector('.mg-variant-section--type');
                if (target) {
                    insertAfter(target, block);
                    break;
                }
                variantDisplay.appendChild(block);
                break;
            case 'after_color':
                target = variantDisplay.querySelector('.mg-variant-section--color');
                if (target) {
                    insertAfter(target, block);
                    break;
                }
                variantDisplay.appendChild(block);
                break;
            case 'after_size':
                target = variantDisplay.querySelector('.mg-variant-section--size');
                if (target) {
                    insertAfter(target, block);
                    break;
                }
                variantDisplay.appendChild(block);
                break;
            case 'variant_bottom':
            default:
                variantDisplay.appendChild(block);
                break;
        }

        block.dataset.mgcfRelocated = '1';
        return true;
    }

    function relocateInForm(form) {
        if (!form) {
            return false;
        }
        var blocks = form.querySelectorAll('.mg-custom-fields:not([data-mgcf-relocated="1"])');
        if (!blocks.length) {
            return true;
        }
        var blockArray = Array.prototype.slice.call(blocks);
        blockArray.sort(function (a, b) {
            var orderA = parseInt(a.getAttribute('data-mgcf-order'), 10);
            var orderB = parseInt(b.getAttribute('data-mgcf-order'), 10);
            if (isNaN(orderA)) {
                orderA = 0;
            }
            if (isNaN(orderB)) {
                orderB = 0;
            }
            if (orderA === orderB) {
                var idA = (a.getAttribute('data-field-id') || '').toString();
                var idB = (b.getAttribute('data-field-id') || '').toString();
                return idA.localeCompare(idB);
            }
            return orderA - orderB;
        });
        var variantDisplay = form.querySelector('.mg-variant-display');
        var movedAny = false;
        blockArray.forEach(function (block) {
            var moved = moveBlockToPlacement(block, form, variantDisplay);
            movedAny = movedAny || moved;
        });
        return movedAny && !!variantDisplay;
    }

    function relocateAllForms(attempt) {
        var forms = document.querySelectorAll('form.cart');
        if (!forms.length) {
            return;
        }
        var pending = false;
        Array.prototype.forEach.call(forms, function (form) {
            var variantDisplay = form.querySelector('.mg-variant-display');
            var blocks = form.querySelectorAll('.mg-custom-fields:not([data-mgcf-relocated="1"])');
            if (!blocks.length) {
                return;
            }
            if (!variantDisplay) {
                pending = true;
                return;
            }
            relocateInForm(form);
        });
        if (pending && attempt < MAX_RETRIES) {
            window.setTimeout(function () {
                relocateAllForms(attempt + 1);
            }, RETRY_DELAY);
        }
    }

    function handleVariantReady(event) {
        var form = event && event.detail && event.detail.form ? event.detail.form : null;
        if (form) {
            relocateInForm(form);
        } else {
            relocateAllForms(0);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            relocateAllForms(0);
        });
    } else {
        relocateAllForms(0);
    }

    document.addEventListener('mgVariantReady', handleVariantReady);

    if (typeof window !== 'undefined' && window.jQuery && window.jQuery(document)) {
        window.jQuery(document).on('mgVariantReady', function (_event, $form) {
            if ($form && $form.length) {
                relocateInForm($form[0]);
            } else {
                relocateAllForms(0);
            }
        });
    }
})();
