(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-mg-gift-finder]').forEach(function (finder) {
            var steps = Array.prototype.slice.call(finder.querySelectorAll('.mg-gift-step'));
            var progress = finder.querySelectorAll('.mg-gift-progress span');
            var results = finder.querySelector('.mg-gift-results');
            var current = results ? steps.length - 1 : 0;

            function show(index) {
                current = Math.max(0, Math.min(index, steps.length - 1));
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
                if (event.target.closest('.mg-gift-restart')) { if (results) results.hidden = true; show(0); finder.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            });
            show(current);
            if (results) results.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
})();
