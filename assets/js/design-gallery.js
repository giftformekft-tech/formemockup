(function(){
    function smoothScrollToTop() {
        try {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } catch (e) {
            window.scrollTo(0, 0);
        }
    }

    function triggerChange(select, value) {
        if (!select) {
            return;
        }
        if (typeof value !== 'undefined') {
            select.value = value;
        }
        var event;
        if (typeof Event === 'function') {
            event = new Event('change', { bubbles: true });
        } else {
            event = document.createEvent('Event');
            event.initEvent('change', true, true);
        }
        select.dispatchEvent(event);
    }

    function applySelection(typeSlug, colorSlug) {
        var form = document.querySelector('form.variations_form');
        if (!form) {
            smoothScrollToTop();
            return;
        }

        var typeSelect = form.querySelector('select[name="attribute_pa_termektipus"]');
        var colorSelect = form.querySelector('select[name="attribute_pa_szin"]');

        if (typeSlug && typeSelect) {
            triggerChange(typeSelect, typeSlug);
        }

        if (colorSlug && colorSelect) {
            setTimeout(function () {
                triggerChange(colorSelect, colorSlug);
            }, 30);
        }

        smoothScrollToTop();
    }

    function updateNavState(track, prevBtn, nextBtn) {
        if (!track) {
            return;
        }
        var maxScroll = track.scrollWidth - track.clientWidth;
        if (prevBtn) {
            prevBtn.disabled = track.scrollLeft <= 0;
        }
        if (nextBtn) {
            nextBtn.disabled = track.scrollLeft >= maxScroll - 2;
        }
    }

    function scrollByViewport(track, direction) {
        if (!track) {
            return;
        }
        var amount = track.clientWidth * 0.85;
        track.scrollBy({ left: amount * direction, behavior: 'smooth' });
    }

    function bindGallery(container) {
        var track = container.querySelector('.mg-design-gallery__items');
        if (!track) {
            return;
        }

        var prevBtn = container.querySelector('[data-mg-gallery-prev]');
        var nextBtn = container.querySelector('[data-mg-gallery-next]');

        var onNavClick = function(direction) {
            return function(event) {
                event.preventDefault();
                scrollByViewport(track, direction);
            };
        };

        if (prevBtn) {
            prevBtn.addEventListener('click', onNavClick(-1));
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', onNavClick(1));
        }

        track.addEventListener('scroll', function(){
            updateNavState(track, prevBtn, nextBtn);
        });
        window.addEventListener('resize', function(){
            updateNavState(track, prevBtn, nextBtn);
        });

        track.addEventListener('click', function(event){
            var item = event.target.closest('.mg-design-gallery__item');
            if (!item) {
                return;
            }
            var typeSlug = item.getAttribute('data-type') || '';
            var colorSlug = item.getAttribute('data-color') || '';
            applySelection(typeSlug, colorSlug);
        });

        track.addEventListener('keydown', function(event){
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }
            var item = event.target.closest('.mg-design-gallery__item');
            if (!item) {
                return;
            }
            event.preventDefault();
            var typeSlug = item.getAttribute('data-type') || '';
            var colorSlug = item.getAttribute('data-color') || '';
            applySelection(typeSlug, colorSlug);
        });

        updateNavState(track, prevBtn, nextBtn);
    }

    function init() {
        var galleries = document.querySelectorAll('[data-mg-gallery]');
        if (!galleries.length) {
            return;
        }
        galleries.forEach(bindGallery);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
