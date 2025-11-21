/**
 * Pattern Showcase Frontend JavaScript
 *
 * Handles carousel/grid interactions with mobile touch gestures
 *
 * @package MockupGenerator
 * @since 1.3.0
 */

(function($) {
    'use strict';

    /**
     * Carousel Class
     */
    var Carousel = function($wrapper) {
        this.$wrapper = $wrapper;
        this.$container = $wrapper.find('.mg-carousel-container');
        this.$track = $wrapper.find('.mg-carousel-track');
        this.$items = $wrapper.find('.mg-carousel-item');
        this.$prevBtn = $wrapper.find('.mg-carousel-prev');
        this.$nextBtn = $wrapper.find('.mg-carousel-next');
        this.$dots = $wrapper.find('.mg-carousel-dot');

        this.currentIndex = 0;
        this.totalItems = this.$items.length;
        this.isAnimating = false;

        // Touch gesture properties
        this.touchStartX = 0;
        this.touchEndX = 0;
        this.touchStartY = 0;
        this.touchEndY = 0;
        this.isDragging = false;
        this.dragThreshold = 50; // Minimum drag distance to trigger slide

        this.init();
    };

    Carousel.prototype = {
        init: function() {
            if (this.totalItems <= 1) {
                return; // No need for carousel with single item
            }

            this.bindEvents();
            this.updateCarousel();
            this.setupAutoHeight();
        },

        bindEvents: function() {
            var self = this;

            // Navigation buttons
            this.$prevBtn.on('click', function(e) {
                e.preventDefault();
                self.prev();
            });

            this.$nextBtn.on('click', function(e) {
                e.preventDefault();
                self.next();
            });

            // Dots
            this.$dots.on('click', function(e) {
                e.preventDefault();
                var index = $(this).data('index');
                self.goToSlide(index);
            });

            // Touch events (mobile swipe)
            this.$container[0].addEventListener('touchstart', function(e) {
                self.handleTouchStart(e);
            }, { passive: true });

            this.$container[0].addEventListener('touchmove', function(e) {
                self.handleTouchMove(e);
            }, { passive: false });

            this.$container[0].addEventListener('touchend', function(e) {
                self.handleTouchEnd(e);
            }, { passive: true });

            // Mouse events (desktop drag)
            this.$container.on('mousedown', function(e) {
                self.handleMouseDown(e);
            });

            $(document).on('mousemove', function(e) {
                self.handleMouseMove(e);
            });

            $(document).on('mouseup', function(e) {
                self.handleMouseEnd(e);
            });

            // Keyboard navigation
            $(document).on('keydown', function(e) {
                if (!self.$wrapper.is(':visible')) return;

                if (e.key === 'ArrowLeft') {
                    self.prev();
                } else if (e.key === 'ArrowRight') {
                    self.next();
                }
            });

            // Window resize
            $(window).on('resize', function() {
                self.updateCarousel();
            });
        },

        handleTouchStart: function(e) {
            this.touchStartX = e.touches[0].clientX;
            this.touchStartY = e.touches[0].clientY;
            this.isDragging = true;
            this.$track.addClass('dragging');
        },

        handleTouchMove: function(e) {
            if (!this.isDragging) return;

            this.touchEndX = e.touches[0].clientX;
            this.touchEndY = e.touches[0].clientY;

            // Calculate deltas
            var deltaX = Math.abs(this.touchEndX - this.touchStartX);
            var deltaY = Math.abs(this.touchEndY - this.touchStartY);

            // If horizontal movement is greater, prevent vertical scroll
            if (deltaX > deltaY) {
                e.preventDefault();
            }
        },

        handleTouchEnd: function(e) {
            if (!this.isDragging) return;

            this.isDragging = false;
            this.$track.removeClass('dragging');

            var deltaX = this.touchEndX - this.touchStartX;
            var deltaY = Math.abs(this.touchEndY - this.touchStartY);

            // Only trigger if horizontal swipe is dominant
            if (Math.abs(deltaX) > deltaY && Math.abs(deltaX) > this.dragThreshold) {
                if (deltaX > 0) {
                    this.prev(); // Swipe right
                } else {
                    this.next(); // Swipe left
                }
            }

            this.touchStartX = 0;
            this.touchEndX = 0;
            this.touchStartY = 0;
            this.touchEndY = 0;
        },

        handleMouseDown: function(e) {
            // Prevent drag on images
            if ($(e.target).is('img')) {
                e.preventDefault();
            }

            this.touchStartX = e.clientX;
            this.touchStartY = e.clientY;
            this.isDragging = true;
            this.$track.addClass('dragging');
        },

        handleMouseMove: function(e) {
            if (!this.isDragging) return;

            this.touchEndX = e.clientX;
            this.touchEndY = e.clientY;
        },

        handleMouseEnd: function(e) {
            if (!this.isDragging) return;

            this.isDragging = false;
            this.$track.removeClass('dragging');

            var deltaX = this.touchEndX - this.touchStartX;

            if (Math.abs(deltaX) > this.dragThreshold) {
                if (deltaX > 0) {
                    this.prev();
                } else {
                    this.next();
                }
            }

            this.touchStartX = 0;
            this.touchEndX = 0;
        },

        prev: function() {
            if (this.isAnimating) return;

            this.currentIndex--;
            if (this.currentIndex < 0) {
                this.currentIndex = this.totalItems - 1;
            }

            this.updateCarousel();
        },

        next: function() {
            if (this.isAnimating) return;

            this.currentIndex++;
            if (this.currentIndex >= this.totalItems) {
                this.currentIndex = 0;
            }

            this.updateCarousel();
        },

        goToSlide: function(index) {
            if (this.isAnimating || index === this.currentIndex) return;

            this.currentIndex = index;
            this.updateCarousel();
        },

        updateCarousel: function() {
            var self = this;
            this.isAnimating = true;

            // Calculate transform
            var itemWidth = this.$items.first().outerWidth(true);
            var offset = -1 * this.currentIndex * itemWidth;

            // Apply transform
            this.$track.css({
                'transform': 'translateX(' + offset + 'px)'
            });

            // Update active states
            this.$items.removeClass('active')
                .eq(this.currentIndex)
                .addClass('active');

            this.$dots.removeClass('active')
                .eq(this.currentIndex)
                .addClass('active');

            // Update button states
            this.updateButtonStates();

            // Reset animation lock
            setTimeout(function() {
                self.isAnimating = false;
            }, 300); // Match CSS transition duration
        },

        updateButtonStates: function() {
            // Optional: disable buttons at ends (for non-looping carousel)
            // Currently set to loop, so buttons always enabled
            this.$prevBtn.prop('disabled', false);
            this.$nextBtn.prop('disabled', false);
        },

        setupAutoHeight: function() {
            var self = this;

            // Set container height based on active item
            function updateHeight() {
                var $activeItem = self.$items.eq(self.currentIndex);
                var height = $activeItem.outerHeight();
                self.$container.css('height', height + 'px');
            }

            // Update on slide change
            this.$track.on('transitionend', function() {
                updateHeight();
            });

            // Initial height
            updateHeight();

            // Update on window resize
            $(window).on('resize', function() {
                updateHeight();
            });

            // Update when images load
            this.$items.find('img').on('load', function() {
                updateHeight();
            });
        }
    };

    /**
     * Grid Class (with lazy loading)
     */
    var Grid = function($container) {
        this.$container = $container;
        this.$items = $container.find('.mg-grid-item');

        this.init();
    };

    Grid.prototype = {
        init: function() {
            this.setupLazyLoading();
            this.setupMasonry();
        },

        setupLazyLoading: function() {
            // Use Intersection Observer for lazy loading
            if ('IntersectionObserver' in window) {
                var imageObserver = new IntersectionObserver(function(entries, observer) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            var $img = $(entry.target);
                            var src = $img.attr('src');

                            // If using data-src pattern (optional)
                            if ($img.attr('data-src')) {
                                $img.attr('src', $img.attr('data-src'));
                            }

                            $img.addClass('loaded');
                            observer.unobserve(entry.target);
                        }
                    });
                }, {
                    rootMargin: '50px' // Load 50px before entering viewport
                });

                this.$items.find('img').each(function() {
                    imageObserver.observe(this);
                });
            }
        },

        setupMasonry: function() {
            // Simple CSS Grid-based masonry
            // Using grid-auto-flow: dense for better packing
            // Already handled in CSS
        }
    };

    /**
     * Initialize Pattern Showcases
     */
    var PatternShowcase = {
        init: function() {
            $('.mg-pattern-showcase').each(function() {
                var $showcase = $(this);
                var layout = $showcase.data('layout');

                if (layout === 'carousel') {
                    $showcase.find('.mg-carousel-wrapper').each(function() {
                        new Carousel($(this));
                    });
                } else if (layout === 'grid') {
                    $showcase.find('.mg-grid-container').each(function() {
                        new Grid($(this));
                    });
                }
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        PatternShowcase.init();
    });

    // Re-initialize on dynamic content load (AJAX, etc.)
    $(document).on('mg-pattern-showcase-loaded', function() {
        PatternShowcase.init();
    });

    // Expose for external use
    window.MGPatternShowcase = PatternShowcase;

})(jQuery);
