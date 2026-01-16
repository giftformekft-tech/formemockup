(function($){
    'use strict';

    var data = window.MGSurchargeProduct || null;
    var $box = $('.mg-surcharge-box[data-context="product"]');
    if (!data || !$box.length) {
        return;
    }

    var $form = $box.closest('form.cart');
    if (!$form.length) {
        return;
    }

    var headingText = $.trim($box.data('title') || '');
    if (!headingText) {
        var $title = $box.find('.mg-surcharge-box__title');
        if ($title.length) {
            headingText = $.trim($title.text());
        }
    }

    var $optionsContainer = $box.find('.mg-surcharge-box__options');
    if (!$optionsContainer.length) {
        $optionsContainer = $box;
    }

    var $variantDisplay = null;
    var $messageAnchor = $box;
    var variantEmbedded = false;
    var $message = null;

    function embedIntoVariantDisplay() {
        if (variantEmbedded) {
            return true;
        }
        var $display = $form.find('.mg-variant-display');
        if (!$display.length) {
            return false;
        }
        var $section = $('<div class="mg-surcharge-box mg-surcharge-box--embedded mg-variant-section mg-variant-section--surcharges" />');
        var contextAttr = $box.attr('data-context');
        if (contextAttr) {
            $section.attr('data-context', contextAttr);
        }
        if (headingText) {
            $section.append($('<div class="mg-variant-section__label" />').text(headingText));
        }
        var $variantOptions = $('<div class="mg-variant-options mg-variant-options--surcharges" />');
        $variantOptions.append($optionsContainer.children().detach());
        $section.append($variantOptions);
        var $sizeSection = $display.find('.mg-variant-section--size');
        if ($sizeSection.length) {
            $section.insertAfter($sizeSection);
        } else {
            $display.append($section);
        }
        $box.remove();
        $box = $section;
        $optionsContainer = $variantOptions;
        $messageAnchor = $section;
        $variantDisplay = $display;
        variantEmbedded = true;
        if ($message) {
            $message.addClass('mg-surcharge-warning--embedded');
            $message.detach().insertAfter($messageAnchor);
        }
        return true;
    }

    if (!embedIntoVariantDisplay()) {
        if (typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(function() {
                if (embedIntoVariantDisplay()) {
                    observer.disconnect();
                }
            });
            observer.observe($form.get(0), { childList: true, subtree: true });
        } else {
            var intervalId = setInterval(function(){
                if (embedIntoVariantDisplay()) {
                    clearInterval(intervalId);
                }
            }, 100);
        }
    }

    var currentVariation = null;
    var messageText = data.messages ? data.messages.required : '';
    var warningClass = 'mg-surcharge-warning' + (variantEmbedded ? ' mg-surcharge-warning--embedded' : '');
    $message = $('<div></div>').addClass(warningClass).insertAfter($messageAnchor).hide();

    function cloneAttributes(obj){
        var clone = {};
        $.each(obj, function(key, arr){
            clone[key] = Array.isArray(arr) ? arr.slice() : [];
        });
        return clone;
    }

    function buildContext(){
        var baseAttributes = cloneAttributes(data.context.base_attributes || {});
        var context = {
            product_id: data.context.product_id,
            variation_id: 0,
            categories: Array.isArray(data.context.categories) ? data.context.categories.slice() : [],
            attributes: cloneAttributes(data.context.attributes || {})
        };
        if (currentVariation && currentVariation.variation_id) {
            context.variation_id = currentVariation.variation_id;
        }
        var variationAttributes = currentVariation && currentVariation.attributes ? currentVariation.attributes : {};
        $.each(variationAttributes, function(key, value){
            if (!value) {
                return;
            }
            var tax = key.replace('attribute_', '');
            context.attributes[tax] = [value];
        });
        $form.find('[name^="attribute_"]').each(function(){
            var val = $(this).val();
            if (!val) {
                return;
            }
            var tax = this.name.replace('attribute_', '');
            context.attributes[tax] = [val];
        });
        var selectedType = $form.find('[name="mg_product_type"]').val();
        if (selectedType) {
            context.attributes['pa_termektipus'] = [selectedType];
            context.attributes['pa_product_type'] = [selectedType];
        }
        var selectedColor = $form.find('[name="mg_color"]').val();
        if (selectedColor) {
            context.attributes['pa_szin'] = [selectedColor];
            context.attributes['pa_color'] = [selectedColor];
        }
        var selectedSize = $form.find('[name="mg_size"]').val();
        if (selectedSize) {
            context.attributes.meret = [selectedSize];
            context.attributes['pa_meret'] = [selectedSize];
            context.attributes['pa_size'] = [selectedSize];
        }
        ['pa_termektipus', 'pa_product_type'].forEach(function(tax){
            if (!context.attributes[tax] || !context.attributes[tax].length) {
                if (baseAttributes[tax] && baseAttributes[tax].length) {
                    context.attributes[tax] = baseAttributes[tax].slice();
                }
            }
        });
        if (!data.context.is_variable) {
            $.each(baseAttributes, function(key, values){
                if (!context.attributes[key] || !context.attributes[key].length) {
                    context.attributes[key] = values.slice();
                }
            });
        }
        return context;
    }

    function listMatches(required, values){
        if (!required || !required.length) {
            return true;
        }
        if (!values || !values.length) {
            return false;
        }
        for (var i = 0; i < required.length; i++) {
            if (values.indexOf(required[i]) !== -1) {
                return true;
            }
        }
        return false;
    }

    function optionMatches(option, context){
        if (!option || !option.conditions) {
            return true;
        }
        if (option.conditions.products && option.conditions.products.length) {
            var allowed = option.conditions.products.map(function(val){ return parseInt(val, 10); });
            if (allowed.indexOf(context.variation_id) === -1 && allowed.indexOf(context.product_id) === -1) {
                return false;
            }
        }
        if (option.conditions.categories && option.conditions.categories.length) {
            var cats = (context.categories || []).map(function(val){ return parseInt(val, 10); });
            var match = option.conditions.categories.some(function(cat){
                return cats.indexOf(parseInt(cat, 10)) !== -1;
            });
            if (!match) {
                return false;
            }
        }
        var typeValues = [].concat(context.attributes['pa_termektipus'] || [], context.attributes['pa_product_type'] || []);
        if (!listMatches(option.conditions.product_types, typeValues)) {
            return false;
        }
        var colorValues = [].concat(context.attributes['pa_szin'] || [], context.attributes['pa_color'] || []);
        if (!listMatches(option.conditions.colors, colorValues)) {
            return false;
        }
        var sizeValues = [].concat(context.attributes['pa_meret'] || [], context.attributes['pa_size'] || [], context.attributes['meret'] || []);
        if (!listMatches(option.conditions.sizes, sizeValues)) {
            return false;
        }
        return true;
    }

    function disableOption($option){
        $option.addClass('is-disabled');
        var $inputs = $option.find('input');
        $inputs.prop('disabled', true);
        $inputs.filter('[type="checkbox"]').prop('checked', false);
        if ($inputs.filter('[type="radio"]').length) {
            $inputs.filter('[type="radio"]').prop('checked', false);
        }
    }

    function enableOption($option){
        $option.removeClass('is-disabled');
        $option.find('input').prop('disabled', false);
    }

    function updateOptions(){
        var context = buildContext();
        var requiredMissing = false;
        $box.find('.mg-surcharge-option').each(function(){
            var $option = $(this);
            var optionId = $option.data('id');
            var option = null;
            for (var i = 0; i < data.options.length; i++) {
                if (data.options[i].id === optionId) {
                    option = data.options[i];
                    break;
                }
            }
            if (!option) {
                return;
            }
            if (optionMatches(option, context)) {
                enableOption($option);
            } else {
                disableOption($option);
                return;
            }
            var $inputs = $option.find('input');
            if ($inputs.filter(':checked').length === 0) {
                if (option.require_choice) {
                    requiredMissing = true;
                }
            }
        });
        toggleButton(requiredMissing);
    }

    function toggleButton(disable){
        var $button = $form.find('[type="submit"]');
        if (!$button.length) {
            return;
        }
        if (disable) {
            $button.prop('disabled', true);
            if (messageText) {
                $message.text(messageText).show();
            }
        } else {
            $button.prop('disabled', false);
            $message.hide();
        }
    }

    updateOptions();

    $form.on('change', '.mg-surcharge-option input', function(){
        updateOptions();
    });

    $(document.body).on('found_variation', '.variations_form', function(event, variation){
        currentVariation = variation || null;
        updateOptions();
    });

    $(document.body).on('reset_data', '.variations_form', function(){
        currentVariation = null;
        updateOptions();
    });

    $(document.body).on('woocommerce_variation_has_changed', function(){
        updateOptions();
    });

})(jQuery);
