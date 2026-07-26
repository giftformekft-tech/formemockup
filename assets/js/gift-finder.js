(function () {
    'use strict';

    var LIST_NAME = 'Ajándékkereső';

    /** A Meta egyedi eseménynevei CamelCase alakúak. */
    function metaEventName(name) {
        return name.split('_').map(function (part) {
            return part.charAt(0).toUpperCase() + part.slice(1);
        }).join('');
    }

    /**
     * Egy eseményt egyszerre juttat el a dataLayerbe (GTM/GA4), a gtaghez és –
     * megadott hozzájárulás esetén – a Meta Pixelhez. A Google Consent Mode
     * maga dönt a cookie-s vagy cookie nélküli küldésről, a Pixel viszont
     * hozzájárulás nélkül nem mérhet.
     */
    function track(name, params) {
        var payload = params || {};
        try {
            window.dataLayer = window.dataLayer || [];
            var entry = {event: name};
            Object.keys(payload).forEach(function (key) { entry[key] = payload[key]; });
            window.dataLayer.push(entry);
        } catch (error) {}
        try {
            if (typeof window.gtag === 'function') window.gtag('event', name, payload);
        } catch (error) {}
        try {
            if (window.mgFbConsentGranted === true && typeof window.fbq === 'function') {
                window.fbq('trackCustom', metaEventName(name), payload);
            }
        } catch (error) {}
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-mg-gift-finder]').forEach(function (finder) {
            var steps = Array.prototype.slice.call(finder.querySelectorAll('.mg-gift-step'));
            var progress = finder.querySelectorAll('.mg-gift-progress span');
            var status = finder.querySelector('.mg-gift-status');
            var results = finder.querySelector('.mg-gift-results');
            var form = finder.querySelector('.mg-gift-wizard');
            var requestedInitial = parseInt(finder.dataset.initialStep || '0', 10);
            var current = results ? steps.length - 1 : Math.max(0, Math.min(requestedInitial, steps.length - 1));
            var started = false;
            var trackedSteps = {};

            /** Lépésenként egyszer mér, akár a Tovább, akár a küldés váltja ki. */
            function trackStep(index) {
                var step = steps[index];
                if (!step || trackedSteps[index]) return;
                trackedSteps[index] = true;
                track('gift_finder_step', {
                    gift_step: parseInt(step.dataset.step || (index + 1), 10),
                    gift_question: step.dataset.question || '',
                    gift_answer: selectedAnswer(index)
                });
            }

            function announce(message) {
                if (status) status.textContent = message;
            }

            function stepLabel(index) {
                var step = steps[index];
                if (!step) return '';
                return (step.dataset.step || (index + 1)) + '/' + steps.length + ' – ' + (step.dataset.title || '');
            }

            function selectedAnswer(index) {
                var checked = steps[index] ? steps[index].querySelector('input[type=radio]:checked') : null;
                if (!checked) return '';
                var label = checked.closest('.mg-gift-option');
                var text = label ? label.querySelector('span') : null;
                return text ? text.textContent.trim() : checked.value;
            }

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

            function show(index, moveFocus) {
                current = Math.max(0, Math.min(index, steps.length - 1));
                filterOptions(current);
                steps.forEach(function (step, i) { step.hidden = i !== current; });
                progress.forEach(function (dot, i) { dot.classList.toggle('is-active', i <= current); });
                announce(stepLabel(current));
                // Billentyűzettel és képernyőolvasóval a fókusz különben az
                // előző, már elrejtett lépésen ragadna.
                if (moveFocus && steps[current]) {
                    steps[current].focus({preventScroll: true});
                }
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
                // A chip elhagyása navigáció, ezért az eseményt még a lapváltás
                // előtt kell kiküldeni – enélkül a mérés elveszne.
                var chipRemove = event.target.closest('.mg-gift-chip__remove');
                if (chipRemove) {
                    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
                    event.preventDefault();
                    track('gift_finder_chip_removed', {gift_question: chipRemove.dataset.question || ''});
                    var chipTarget = chipRemove.href;
                    window.setTimeout(function () { window.location.href = chipTarget; }, 120);
                    return;
                }
                var loadMore = event.target.closest('.mg-gift-load-more');
                if (loadMore) {
                    var hiddenCards = Array.prototype.slice.call(results.querySelectorAll('.mg-gift-product-card[hidden]'));
                    hiddenCards.slice(0, 10).forEach(function (card) { card.hidden = false; });
                    if (hiddenCards.length <= 10) loadMore.hidden = true;
                    track('gift_finder_load_more', {gift_revealed: Math.min(10, hiddenCards.length)});
                    return;
                }
                var productLink = event.target.closest('.mg-gift-product-card a[data-product-id]');
                if (productLink) {
                    track('select_item', {
                        item_list_name: LIST_NAME,
                        items: [{
                            item_id: productLink.dataset.productId,
                            item_name: productLink.dataset.productName,
                            index: parseInt(productLink.dataset.position || '0', 10)
                        }]
                    });
                    return;
                }
                if (event.target.closest('.mg-gift-next')) {
                    var checked = steps[current].querySelector('input[type=radio]:checked');
                    if (!checked) {
                        steps[current].classList.add('is-shaking');
                        announce('Válassz egy lehetőséget a továbblépéshez.');
                        setTimeout(function () { steps[current].classList.remove('is-shaking'); }, 350);
                        return;
                    }
                    trackStep(current);
                    var next = nextRelevantIndex(current + 1);
                    if (next >= steps.length) finder.querySelector('form').requestSubmit();
                    else show(next, true);
                }
                if (event.target.closest('.mg-gift-back')) show(previousRelevantIndex(current - 1), true);
                if (event.target.closest('.mg-gift-restart')) {
                    if (results) results.hidden = true;
                    if (form) form.hidden = false;
                    finder.querySelectorAll('input[type=radio]').forEach(function (input) { input.checked = false; });
                    started = false;
                    trackedSteps = {};
                    track('gift_finder_restart', {});
                    show(0, true);
                    finder.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
            function updateProductPreviews(type, color) {
                finder.querySelectorAll('.mg-gift-product-card').forEach(function (card) {
                    var image = card.querySelector('img[data-default-src]');
                    var link = card.querySelector('a[data-default-url]');
                    if (!image || !link) return;
                    var typeUrls = {};
                    try { typeUrls = JSON.parse(link.dataset.typeUrls || '{}'); } catch (error) { typeUrls = {}; }
                    var base = (image.dataset.previewBase || '').replace(/\/$/, '');
                    var sku = image.dataset.productSku || '';
                    var hasPreview = !!(type && color && base && sku);
                    var filename = sku + '_' + type + '_' + color + '_front.webp';
                    var nextSrc = hasPreview ? base + '/' + encodeURIComponent(sku) + '/' + encodeURIComponent(filename) : image.dataset.defaultSrc;
                    image.classList.add('is-loading');
                    image.src = nextSrc;
                    image.dataset.previewActive = hasPreview ? '1' : '0';
                    if (type && typeUrls[type]) {
                        link.href = typeUrls[type];
                    } else {
                        link.href = link.dataset.defaultUrl;
                    }
                });
            }

            finder.addEventListener('change', function (event) {
                if (event.target.matches('.mg-gift-option input[type="radio"]')) {
                    if (!started) {
                        started = true;
                        track('gift_finder_start', {
                            gift_question: steps[current] ? (steps[current].dataset.question || '') : ''
                        });
                    }
                    if (window.matchMedia('(max-width: 520px)').matches) {
                        var selectedStep = event.target.closest('.mg-gift-step');
                        var stepActions = selectedStep ? selectedStep.querySelector('.mg-gift-step__actions') : null;
                        if (stepActions) {
                            window.setTimeout(function () {
                                stepActions.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }, 80);
                        }
                    }
                    return;
                }

                var typeSelect = finder.querySelector('.mg-gift-type-select');
                var colorSelect = finder.querySelector('.mg-gift-color-select');
                var colorPicker = finder.querySelector('.mg-gift-color-picker');
                if (!typeSelect || !colorSelect || !colorPicker) return;

                if (event.target.matches('.mg-gift-type-select')) {
                    var typeColors = {};
                    try { typeColors = JSON.parse(typeSelect.dataset.typeColors || '{}'); } catch (error) { typeColors = {}; }
                    var colors = typeColors[typeSelect.value] || {};
                    colorSelect.innerHTML = '';
                    Object.keys(colors).forEach(function (slug) {
                        var option = document.createElement('option');
                        option.value = slug;
                        option.textContent = colors[slug];
                        colorSelect.appendChild(option);
                    });
                    colorPicker.hidden = !typeSelect.value || !colorSelect.options.length;
                    colorSelect.disabled = colorPicker.hidden;
                    updateProductPreviews(typeSelect.value, colorSelect.value);
                    return;
                }

                if (event.target.matches('.mg-gift-color-select')) {
                    updateProductPreviews(typeSelect.value, colorSelect.value);
                }
            });
            finder.querySelectorAll('.mg-gift-product-card img[data-default-src]').forEach(function (image) {
                image.addEventListener('load', function () { image.classList.remove('is-loading'); });
                image.addEventListener('error', function () {
                    if (image.dataset.previewActive === '1') {
                        image.dataset.previewActive = '0';
                        image.src = image.dataset.defaultSrc;
                    }
                    image.classList.remove('is-loading');
                });
            });
            // Az utolsó lépésen a küldés gombja zárja a folyamatot, nem a Tovább.
            if (form) form.addEventListener('submit', function () { trackStep(current); });

            // A főoldali teaserről érkezve az első kérdésre már megvan a válasz –
            // enélkül a tölcsér eleje hiányozna ezeknél a látogatóknál.
            if (!results) {
                for (var preIndex = 0; preIndex < current; preIndex++) {
                    if (!steps[preIndex].querySelector('input[type=radio]:checked')) continue;
                    if (!started) {
                        started = true;
                        track('gift_finder_start', {gift_question: steps[preIndex].dataset.question || ''});
                    }
                    trackStep(preIndex);
                }
            }

            show(current, false);
            if (results) {
                if (form) form.hidden = true;

                var resultCount = parseInt(results.dataset.resultCount || '0', 10);
                var choiceCount = parseInt(results.dataset.choiceCount || '0', 10);
                var maxScore = parseInt(results.dataset.maxScore || '0', 10);
                track(resultCount ? 'gift_finder_results' : 'gift_finder_no_results', {
                    gift_result_count: resultCount,
                    gift_choice_count: choiceCount,
                    gift_match_max: maxScore
                });

                // A lazítás azt méri, hol nincs elég termék a kínálatban:
                // melyik kombinációnál mit kellett feloldani.
                var releasedCount = parseInt(results.dataset.releasedCount || '0', 10);
                if (releasedCount > 0) {
                    track('gift_finder_relaxed', {
                        gift_relaxed_count: releasedCount,
                        gift_relaxed_questions: results.dataset.releasedQuestions || '',
                        gift_strict_count: parseInt(results.dataset.strictCount || '0', 10),
                        gift_result_count: resultCount
                    });
                }
                if (resultCount) {
                    var items = Array.prototype.slice.call(results.querySelectorAll('a[data-product-id]')).slice(0, 20).map(function (link) {
                        return {
                            item_id: link.dataset.productId,
                            item_name: link.dataset.productName,
                            index: parseInt(link.dataset.position || '0', 10)
                        };
                    });
                    track('view_item_list', {item_list_name: LIST_NAME, items: items});
                }

                announce(resultCount
                    ? resultCount + ' találat készült el.'
                    : 'Erre a kombinációra nincs találat.');
                results.scrollIntoView({ behavior: 'smooth', block: 'start' });
                var heading = results.querySelector('.mg-gift-results__heading h2');
                if (heading) heading.focus({preventScroll: true});
            }
        });
    });
})();
