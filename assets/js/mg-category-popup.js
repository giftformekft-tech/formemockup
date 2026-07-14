(function () {
    'use strict';

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    ready(function () {
        var popup = document.querySelector('.mg-category-popup');
        if (!popup) {
            return;
        }

        var key = popup.getAttribute('data-popup-key');
        try {
            if (key && window.sessionStorage.getItem(key) === 'shown') {
                return;
            }
        } catch (error) {
            // Storage may be disabled; the popup can still function.
        }

        var dialog = popup.querySelector('.mg-category-popup__dialog');
        var closeButtons = popup.querySelectorAll('[data-mg-category-popup-close]');
        var previousFocus = null;

        function closePopup() {
            popup.classList.remove('is-visible');
            popup.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('mg-category-popup-open');
            if (previousFocus && typeof previousFocus.focus === 'function') {
                previousFocus.focus();
            }
        }

        function openPopup() {
            previousFocus = document.activeElement;
            popup.classList.add('is-visible');
            popup.setAttribute('aria-hidden', 'false');
            document.body.classList.add('mg-category-popup-open');
            try {
                if (key) {
                    window.sessionStorage.setItem(key, 'shown');
                }
            } catch (error) {
                // Storage may be disabled; opening must not depend on it.
            }
            var focusTarget = popup.querySelector('.mg-category-popup__close');
            if (focusTarget) {
                focusTarget.focus();
            }
        }

        Array.prototype.forEach.call(closeButtons, function (button) {
            button.addEventListener('click', closePopup);
        });

        document.addEventListener('keydown', function (event) {
            if (!popup.classList.contains('is-visible')) {
                return;
            }
            if (event.key === 'Escape') {
                closePopup();
                return;
            }
            if (event.key === 'Tab' && dialog) {
                var focusable = dialog.querySelectorAll('a[href], button:not([disabled])');
                if (!focusable.length) {
                    return;
                }
                var first = focusable[0];
                var last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });

        window.setTimeout(openPopup, 550);
    });
}());
