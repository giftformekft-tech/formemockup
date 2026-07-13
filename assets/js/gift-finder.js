(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-mg-gift-finder]').forEach(function (finder) {
            var steps = Array.prototype.slice.call(finder.querySelectorAll('.mg-gift-step'));
            var progress = finder.querySelectorAll('.mg-gift-progress span');
            var results = finder.querySelector('.mg-gift-results');
            var form = finder.querySelector('.mg-gift-wizard');
            var requestedInitial = parseInt(finder.dataset.initialStep || '0', 10);
            var current = results ? steps.length - 1 : Math.max(0, Math.min(requestedInitial, steps.length - 1));

            function filterOptions(index) {
                var selected = [];
                steps.slice(0, index).forEach(function (step) {
                    var checked = step.querySelector('input[type=radio]:checked');
                    if (checked && checked.value !== '0' && checked.value !== 'all') {
                        var categoryIds = (checked.dataset.categoryIds || checked.value).split(',').filter(Boolean);
                        selected = selected.concat(categoryIds);
                    }
                });
                steps[index].querySelectorAll('.mg-gift-option[data-parent-ids]').forEach(function (option) {
                    var parents = option.dataset.parentIds.split(',').filter(Boolean);
                    var relevant = parents.some(function (id) { return selected.indexOf(id) !== -1; });
                    option.hidden = !relevant;
                    if (!relevant) {
                        var input = option.querySelector('input');
                        if (input) input.checked = false;
                    }
                });
            }

            function show(index) {
                current = Math.max(0, Math.min(index, steps.length - 1));
                filterOptions(current);
                steps.forEach(function (step, i) { step.hidden = i !== current; });
                progress.forEach(function (dot, i) { dot.classList.toggle('is-active', i <= current); });
            }

            function hasRelevantOptions(index) {
                filterOptions(index);
                return !!steps[index].querySelector('.mg-gift-option:not(.mg-gift-option--skip):not([hidden])');
            }

            function nextRelevantIndex(from) {
                for (var i = from; i < steps.length; i++) {
                    if (hasRelevantOptions(i)) {
                        if (progress[i]) progress[i].hidden = false;
                        return i;
                    }
                    if (progress[i]) progress[i].hidden = true;
                }
                return steps.length;
            }

            function previousRelevantIndex(from) {
                for (var i = from; i >= 0; i--) {
                    if (hasRelevantOptions(i)) return i;
                }
                return 0;
            }
            finder.addEventListener('click', function (event) {
                var loadMore = event.target.closest('.mg-gift-load-more');
                if (loadMore) {
                    var hiddenCards = Array.prototype.slice.call(results.querySelectorAll('.mg-gift-product-card[hidden]'));
                    hiddenCards.slice(0, 10).forEach(function (card) { card.hidden = false; });
                    if (hiddenCards.length <= 10) loadMore.hidden = true;
                    return;
                }
                if (event.target.closest('.mg-gift-next')) {
                    var checked = steps[current].querySelector('input[type=radio]:checked');
                    if (!checked) { steps[current].classList.add('is-shaking'); setTimeout(function () { steps[current].classList.remove('is-shaking'); }, 350); return; }
                    var next = nextRelevantIndex(current + 1);
                    if (next >= steps.length) finder.querySelector('form').requestSubmit();
                    else show(next);
                }
                if (event.target.closest('.mg-gift-back')) show(previousRelevantIndex(current - 1));
                if (event.target.closest('.mg-gift-restart')) {
                    if (results) results.hidden = true;
                    if (form) form.hidden = false;
                    finder.querySelectorAll('input[type=radio]').forEach(function (input) { input.checked = false; });
                    show(0);
                    finder.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
            finder.addEventListener('change', function (event) {
                if (!event.target.matches('.mg-gift-type-select')) return;
                var type = event.target.value;
                finder.querySelectorAll('.mg-gift-product-card').forEach(function (card) {
                    var image = card.querySelector('img[data-type-previews]');
                    var link = card.querySelector('a[data-default-url]');
                    if (!image || !link) return;
                    var previews = {};
                    var typeUrls = {};
                    try { previews = JSON.parse(image.dataset.typePreviews || '{}'); } catch (error) { previews = {}; }
                    try { typeUrls = JSON.parse(link.dataset.typeUrls || '{}'); } catch (error) { typeUrls = {}; }
                    var nextSrc = (type && previews[type]) ? previews[type] : image.dataset.defaultSrc;
                    image.classList.add('is-loading');
                    image.src = nextSrc;
                    image.dataset.previewActive = type && previews[type] ? '1' : '0';
                    if (type && typeUrls[type]) {
                        link.href = typeUrls[type];
                    } else {
                        link.href = link.dataset.defaultUrl;
                    }
                });
            });
            finder.querySelectorAll('.mg-gift-product-card img[data-type-previews]').forEach(function (image) {
                image.addEventListener('load', function () { image.classList.remove('is-loading'); });
                image.addEventListener('error', function () {
                    if (image.dataset.previewActive === '1') {
                        image.dataset.previewActive = '0';
                        image.src = image.dataset.defaultSrc;
                    }
                    image.classList.remove('is-loading');
                });
            });
            show(current);
            if (results) {
                if (form) form.hidden = true;
                results.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
})();
