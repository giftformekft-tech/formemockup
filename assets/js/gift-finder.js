(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-mg-gift-finder]').forEach(function (finder) {
            var steps = Array.prototype.slice.call(finder.querySelectorAll('.mg-gift-step'));
            var progress = finder.querySelectorAll('.mg-gift-progress span');
            var results = finder.querySelector('.mg-gift-results');
            var requestedInitial = parseInt(finder.dataset.initialStep || '0', 10);
            var current = results ? steps.length - 1 : Math.max(0, Math.min(requestedInitial, steps.length - 1));

            function filterOptions(index) {
                var selected = [];
                steps.slice(0, index).forEach(function (step) {
                    var checked = step.querySelector('input[type=radio]:checked');
                    if (checked && checked.value !== '0' && checked.value !== 'all') selected.push(checked.value);
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
            finder.addEventListener('click', function (event) {
                if (event.target.closest('.mg-gift-next')) {
                    var checked = steps[current].querySelector('input[type=radio]:checked');
                    if (!checked) { steps[current].classList.add('is-shaking'); setTimeout(function () { steps[current].classList.remove('is-shaking'); }, 350); return; }
                    show(current + 1);
                }
                if (event.target.closest('.mg-gift-back')) show(current - 1);
                if (event.target.closest('.mg-gift-restart')) {
                    if (results) results.hidden = true;
                    finder.querySelectorAll('input[type=radio]').forEach(function (input) { input.checked = false; });
                    show(0);
                    finder.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
            show(current);
            if (results) results.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
})();
