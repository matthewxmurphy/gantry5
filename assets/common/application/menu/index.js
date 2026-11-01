"use strict";

var ready   = require('domready'),
    prime   = require('prime'),
    $       = require('../utils/dollar-extras'),
    zen     = require('elements/zen'),
    bind    = require('mout/function/bind'),
    timeout = require('mout/function/timeout'),
    Bound   = require('prime-util/prime/bound'),
    Options = require('prime-util/prime/options');


var hasTouchEvents = ('ontouchstart' in window) || window.DocumentTouch && document instanceof DocumentTouch;

var Menu = new prime({

    mixin: [Bound, Options],

    options: {
        selectors: {
            mainContainer: '.g-main-nav',
            mobileContainer: '#g-mobilemenu-container',
            topLevel: '.g-toplevel',
            rootItems: '> ul > li',
            parent: '.g-parent',
            item: '.g-menu-item',
            dropdown: '.g-dropdown',
            overlay: '.g-menu-overlay',
            touchIndicator: '.g-menu-parent-indicator',
            linkedParent: '[data-g-menuparent]',
            mobileTarget: '[data-g-mobile-target]'
        },

        states: {
            active: 'g-active',
            inactive: 'g-inactive',
            selected: 'g-selected',
            touchEvents: 'g-menu-hastouch'
        }
    },

    constructor: function(options) {
        this.setOptions(options);

        this.selectors = this.options.selectors;
        this.states = this.options.states;
        this.overlay = zen('div' + this.selectors.overlay);
        this.active = null;
        this.location = [];

        var pageSurround = $('#g-page-surround');
        if (pageSurround) {
            this.overlay.top(pageSurround);
        }

        var mainContainer = $(this.selectors.mainContainer);
        if (!mainContainer) { return; }

        var gHoverExpand  = mainContainer.data('g-hover-expand');

        this.hoverExpand = gHoverExpand === null || gHoverExpand === 'true';
        if (hasTouchEvents || !this.hoverExpand) {
            mainContainer.addClass(this.states.touchEvents);
        }

        this.attach();
    },

    attach: function() {
        var selectors       = this.selectors,
            main            = $(selectors.mainContainer + ' ' + selectors.item),
            mobileContainer = $(selectors.mobileContainer),
            body            = $('body');

        if (!main) { return; }
        if (this.hoverExpand) {
            main.on('mouseenter', this.bound('mouseenter'));
            main.on('mouseleave', this.bound('mouseleave'));
        }

        body.delegate('click', ':not(' + selectors.mainContainer + ') ' + selectors.linkedParent + ', .g-fullwidth .g-sublevel ' + selectors.linkedParent, this.bound('click'));
        body.delegate('click', ':not(' + selectors.mainContainer + ') a[href]', this.bound('resetAfterClick'));
        body.on('keydown', this.bound('keydown'));
        body.on('focusin', this.bound('focusin'));
        this._prepareDisclosures();

        if (hasTouchEvents || !this.hoverExpand) {
            var linkedParent = $(selectors.linkedParent);
            if (linkedParent) {
                linkedParent.on('touchmove', this.bound('touchmove'));
                linkedParent.on('touchend', this.bound('touchend'));
            }
            this.overlay.on('touchend', this.bound('closeAllDropdowns'));
        }

        if (mobileContainer) {
            var query = 'only all and (max-width: ' + this._calculateBreakpoint((mobileContainer.data('g-menu-breakpoint') || '48rem')) + ')',
                match = matchMedia(query);
            if (match.addEventListener) { match.addEventListener('change', this.bound('_checkQuery')); }
            else { match.addListener(this.bound('_checkQuery')); }
            this._checkQuery(match);
        }
    },

    detach: function() {},

    click: function(event) {
        this.touchend(event);
    },

    _prepareDisclosures: function() {
        var self = this, index = 0;
        Array.prototype.forEach.call(document.querySelectorAll(this.selectors.mainContainer + ' ' + this.selectors.parent + ', ' + this.selectors.mobileContainer + ' ' + this.selectors.parent), function(item) {
            var control = item.querySelector('.g-menu-item-container'), dropdown = item.querySelector(self.selectors.dropdown);
            if (!control || !dropdown) { return; }
            if (!dropdown.id) {
                do { index++; } while (document.getElementById('g-menu-dropdown-' + index));
                dropdown.id = 'g-menu-dropdown-' + index;
            }
            control.setAttribute('aria-controls', dropdown.id);
            control.setAttribute('aria-expanded', item.classList.contains(self.states.selected) ? 'true' : 'false');
            if (!control.matches('a[href], button')) {
                if (!control.hasAttribute('role')) { control.setAttribute('role', 'button'); }
                if (!control.hasAttribute('tabindex')) { control.setAttribute('tabindex', '0'); }
            }
        });
    },

    _setExpanded: function(item, expanded) {
        var node = item[0], control = node.querySelector('.g-menu-item-container');
        if (control && node.querySelector(this.selectors.dropdown)) { control.setAttribute('aria-expanded', expanded ? 'true' : 'false'); }
    },

    focusin: function(event) {
        if (!event.target.closest || !event.target.closest(this.selectors.mainContainer + ', ' + this.selectors.mobileContainer)) { this.closeAllDropdowns(); }
    },

    _focusControl: function(control) {
        var panel = control.closest('#g-offcanvas');
        if (!panel) { control.focus(); return; }
        // Sliding levels must not cause the browser to horizontally scroll the drawer.
        control.focus({ preventScroll: true });
        var item = control.getBoundingClientRect(), bounds = panel.getBoundingClientRect();
        if (item.top < bounds.top) { panel.scrollTop += item.top - bounds.top; }
        else if (item.bottom > bounds.bottom) { panel.scrollTop += item.bottom - bounds.bottom; }
    },

    keydown: function(event) {
        var target = event.target, key = event.key;
        if (!target.closest || !target.closest(this.selectors.mainContainer + ', ' + this.selectors.mobileContainer) || target.matches('input, textarea, select, [contenteditable]')) { return; }
        var selected = target.closest('.' + this.states.selected + this.selectors.item),
            item = target.closest(this.selectors.item), control, dropdown, links, current;
        if (key === 'Escape' && selected) {
            event.preventDefault();
            var back = selected.querySelector(this.selectors.dropdown + ' .g-go-back [data-g-menuparent]');
            if (back) {
                this.touchend({ target: back, type: 'keydown', preventDefault: function() {}, stopPropagation: function() {} });
            } else {
                this.closeDropdown($(selected));
                var level = selected.closest('.g-sublevel, .g-toplevel');
                if (level) { level.classList.remove('g-slide-out'); level.style.removeProperty('height'); }
            }
            this.toggleOverlay($(selected.closest(this.selectors.mainContainer)) || $(this.selectors.mobileContainer));
            control = selected.querySelector('.g-menu-item-container');
            if (control) { this._focusControl(control); }
            return;
        }
        if (!item) { return; }
        control = item.querySelector('.g-menu-item-container');
        dropdown = item.querySelector(this.selectors.dropdown);
        if ((key === 'Enter' || key === ' ') && control && control.contains(target) && control.matches('[data-g-menuparent]')) {
            event.preventDefault();
            this.touchend(event);
            return;
        }
        if (key !== 'ArrowDown' && key !== 'ArrowUp' && key !== 'Home' && key !== 'End') { return; }
        var entering = dropdown && control && control.contains(target) && (key === 'ArrowDown' || key === 'ArrowUp');
        if (entering) {
            if (!item.classList.contains(this.states.selected)) { this.touchend(event); }
        } else {
            dropdown = target.closest('.g-sublevel');
        }
        if (!dropdown) { return; }
        event.preventDefault();
        if (entering) {
            // CSS visibility transitions become focusable after the opening frame.
            var self = this;
            window.requestAnimationFrame(function() {
                window.requestAnimationFrame(function() {
                    if (document.activeElement === target && item.classList.contains(self.states.selected)) {
                        self._focusDropdown(dropdown, target, key, true);
                    }
                });
            });
            return;
        }
        this._focusDropdown(dropdown, target, key, false);
    },

    _focusDropdown: function(dropdown, target, key, entering) {
        var links, current;
        var active = this.states.active;
        links = Array.prototype.filter.call(dropdown.querySelectorAll('a[href], button, [tabindex]'), function(node) {
            if ((entering || node.closest('.g-main-nav')) && node.closest('.g-go-back')) { return false; }
            var branch = node.parentElement.closest('.g-dropdown');
            while (branch) {
                if (!branch.classList.contains(active)) { return false; }
                branch = branch.parentElement.closest('.g-dropdown');
            }
            return !node.disabled && node.tabIndex >= 0 && node.getClientRects().length && window.getComputedStyle(node).visibility !== 'hidden';
        });
        if (!links.length) { return; }
        current = links.indexOf(target);
        if (key === 'Home') { current = 0; }
        else if (key === 'End') { current = links.length - 1; }
        else if (key === 'ArrowUp') { current = current < 0 ? links.length - 1 : (current - 1 + links.length) % links.length; }
        else { current = (current + 1) % links.length; }
        this._focusControl(links[current]);
    },

    resetAfterClick: function(event) {
        var target = $(event.target);

        if (target.data('g-menuparent') !== null) {
            return true;
        }

        this.closeDropdown(event);
        if (global.G5 && global.G5.offcanvas) {
            G5.offcanvas.close();
        }
    },

    mouseenter: function(event) {
        var element = $(event.target);
        if (!element.parent(this.options.selectors.mainContainer)) { return; }
        if (element.parent(this.options.selectors.item) && !element.parent('.g-standard')) { return; }

        this.openDropdown(element);
    },

    mouseleave: function(event) {
        var element = $(event.target);
        if (!element.parent(this.options.selectors.mainContainer)) { return; }
        if (element.parent(this.options.selectors.item) && !element.parent('.g-standard')) { return; }

        this.closeDropdown(element);
    },

    touchmove: function(event) {
        var target      = $(event.target);
        target.isMoving = true;
    },

    touchend: function(event) {
        var selectors = this.selectors,
            states    = this.states;

        var target      = $(event.target),
            indicator   = target.parent(selectors.item).find(selectors.touchIndicator),
            menuType    = target.parent('.g-standard') ? 'standard' : 'megamenu',
            isGoingBack = target.parent('.g-go-back'),
            parent, isSelected;

        if (target.isMoving) {
            target.isMoving = false;
            return false;
        }

        target.off('touchmove', this.bound('touchmove'));
        target.isMoving = false;

        if (indicator) {
            target = indicator;
        }

        parent = target.matches(selectors.item) ? target : target.parent(selectors.item);
        isSelected = parent.hasClass(states.selected);

        if (!parent.find(selectors.dropdown) && !indicator) { return true; }

        event.stopPropagation();
        if (!indicator || target.matches(selectors.touchIndicator)) {
            event.preventDefault();
        }

        if (!isSelected) {
            var siblings = parent.siblings();
            if (siblings) {
                var currentlyOpen = siblings.search(selectors.touchIndicator + ' !> * !> ' + selectors.item + '.' + states.selected);
                (currentlyOpen || []).forEach(bind(function(open) {
                    this.closeDropdown(open);
                }, this));
            }
        }

        if ((menuType == 'megamenu' || !parent.parent(selectors.mainContainer)) && (parent.find(' > ' + selectors.dropdown + ', > * > ' + selectors.dropdown) || isGoingBack)) {
            var sublevel = target.parent('.g-sublevel') || target.parent('.g-toplevel'),
                slideout = parent.find('.g-sublevel'),
                columns  = parent.parent('.g-dropdown-column'),
                blocks;

            if (sublevel) {
                var isNavMenu = target.parent(selectors.mainContainer);
                if (!isNavMenu || (isNavMenu && !sublevel.matches('.g-toplevel'))) { this._fixHeights(sublevel, slideout, isGoingBack, isNavMenu); }
                if (!isNavMenu && columns && (blocks = columns.search('> .g-grid > .g-block'))) {
                    if (blocks.length > 1) { sublevel = blocks.search('> .g-sublevel'); }
                }

                sublevel[!isSelected ? 'addClass' : 'removeClass']('g-slide-out');
            }
        }

        this[!isSelected ? 'openDropdown' : 'closeDropdown'](parent);
        if (event.type !== 'click') { this.toggleOverlay(target.parent(selectors.mainContainer)); }
    },

    openDropdown: function(element) {
        element = $(element.target || element);
        var dropdown = element.find(this.selectors.dropdown);

        element.addClass(this.states.selected);
        this._setExpanded(element, true);

        if (dropdown) {
            dropdown.removeClass(this.states.inactive).addClass(this.states.active);
        }
    },

    closeDropdown: function(element) {
        element = $(element.target || element);
        var dropdown = element.find(this.selectors.dropdown);

        element.removeClass(this.states.selected);
        this._setExpanded(element, false);

        if (dropdown) {
            var sublevels = dropdown.search('.g-sublevel'),
                slideouts = dropdown.search('.g-slide-out, .' + this.states.selected),
                actives   = dropdown.search('.' + this.states.active);

            if (sublevels) { sublevels.attribute('style', null); }
            if (slideouts) { slideouts.removeClass('g-slide-out').removeClass(this.states.selected); }
            if (actives) { actives.removeClass(this.states.active).addClass(this.states.inactive); }
            var self = this;
            Array.prototype.forEach.call(dropdown[0].querySelectorAll(this.selectors.parent), function(item) { self._setExpanded($(item), false); });

            dropdown.removeClass(this.states.active).addClass(this.states.inactive);
        }
    },

    closeAllDropdowns: function() {
        var selectors = this.selectors,
            states    = this.states,
            topLevel  = $(selectors.mainContainer + ' > .g-toplevel') || $(selectors.mobileContainer + ' > .g-toplevel'),
            roots     = topLevel ? topLevel.search(' >' + selectors.item) : null;

        if (roots) { roots.removeClass(states.selected); }
        if (topLevel) {
            var allRoots = topLevel.search('> ' + this.options.selectors.item);
            if (allRoots) { allRoots.forEach(this.closeDropdown.bind(this)); }
            this.closeDropdown(topLevel);
        }

        this.toggleOverlay(topLevel);
    },

    resetStates: function(menu) {
        if (!menu) { return; }
        var items   = menu.search('.g-toplevel, .g-dropdown-column, .g-dropdown, .g-selected, .g-active, .g-slide-out'),
            actives = menu.search('.g-active');
        if (!items) { return; }

        menu.attribute('style', null).removeClass('g-selected').removeClass('g-slide-out');
        items.attribute('style', null).removeClass('g-selected').removeClass('g-slide-out');
        if (actives) { actives.removeClass('g-active').addClass('g-inactive'); }
    },

    toggleOverlay: function(menu) {
        if (!menu) { return; }
        var shouldOpen = !!menu.find('.g-active, .g-selected');

        this.overlay[shouldOpen ? 'addClass' : 'removeClass']('g-menu-overlay-open');
        this.overlay[0].style.opacity = shouldOpen ? 1 : 0;
    },

    _fixHeights: function(parent, sublevel, isGoingBack, isNavMenu) {
        if (parent == sublevel) { return; }
        if (isGoingBack) {
            parent.attribute('style', null);
        }

        var parents, heights = {
                from: parent[0].getBoundingClientRect(),
                to: (!isNavMenu ? sublevel.parent('.g-dropdown')[0] : sublevel[0]).getBoundingClientRect()
            },
            height  = Math.max(heights.from.height, heights.to.height);

        if (isGoingBack) {
            parents = parent.parents('[style^="height"]');
            (parents || []).forEach(function(element) {
                element = $(element);
                if (element.parent('.g-toplevel')) {
                    element[0].style.height = heights.from.height + 'px';
                }
            });
        }

        if (!isGoingBack) {
            // if from height is < than to height set the parent height else, set the target
            if (heights.from.height < heights.to.height) {
                parent[0].style.height = height + 'px';

                parents = parent.parents('[style^="height"]');
                (parents || []).forEach(function(element) {
                    element = $(element);
                    if (element.parent('.g-toplevel')) {
                        element[0].style.height = height + 'px';
                    }
                });
            } else if (isNavMenu) {
                sublevel[0].style.height = height + 'px';
            }

            // fix sublevels heights in side menu (offcanvas etc)
            if (!isNavMenu) {
                var maxHeight = height,
                    block     = $(sublevel).parent('.g-block:not(.size-100)'),
                    column    = block ? block.parent('.g-dropdown-column') : null;
                (sublevel.parents('.g-slide-out, .g-dropdown-column') || parent).forEach(function(slideout) {
                    maxHeight = Math.max(height, parseInt(slideout.style.height || 0, 10));
                });

                if (column) {
                    column[0].style.height = maxHeight + 'px';

                    var blocks = column.search('> .g-grid > .g-block'),
                        diff   = maxHeight;

                    blocks.forEach(function(block, i) {
                        if ((i + 1) != blocks.length) {
                            diff -= block.getBoundingClientRect().height;
                        } else {
                            $(block).find('.g-sublevel')[0].style.height = diff + 'px';
                        }
                    });


                } else {
                    sublevel[0].style.height = maxHeight + 'px';
                }
            }
        }
    },

    _calculateBreakpoint: function(value) {
        var match = String(value).match(/^\s*(\d+(?:\.\d+)?|\.\d+)\s*(px|rem|em|vw|vh|vmin|vmax)?\s*$/i);
        if (!match || !isFinite(Number(match[1]))) { return '47.938rem'; }
        var unit = (match[2] || 'px').toLowerCase(), tolerance = /^(rem|em)$/.test(unit) ? 0.062 : 1;
        return Math.max(0, Number((Number(match[1]) - tolerance).toFixed(6))) + unit;
    },

    _checkQuery: function(mq) {
        var selectors       = this.options.selectors,
            mobileContainer = $(selectors.mobileContainer),
            mainContainer   = $(selectors.mainContainer + selectors.mobileTarget) || $(selectors.mainContainer),
            find, dropdowns;

        if (!mobileContainer || !mainContainer) { return; }
        var mainBlock = mainContainer.parent('.g-block'), mobileBlock = mobileContainer.parent('.g-block');

        if (mq.matches) {
            // move to Mobile Container
            find = mainContainer.find(selectors.topLevel);
            if (find) {
                if (mainBlock) { mainBlock.addClass('hidden'); }
                if (mobileBlock) { mobileBlock.removeClass('hidden'); }
                find.top(mobileContainer);
            }
        } else {
            // move back to Original Location
            find = mobileContainer.find(selectors.topLevel);
            if (find) {
                if (mobileBlock) { mobileBlock.addClass('hidden'); }
                if (mainBlock) { mainBlock.removeClass('hidden'); }
                find.top(mainContainer);
            }
        }

        this.resetStates(find);
        this._prepareDisclosures();

        // we need to reintroduce fixed widths for those dropdowns that come with it
        if (!mq.matches && (find && (dropdowns = find.search('[data-g-item-width]')))) {
            dropdowns.forEach(function(dropdown) {
                dropdown = $(dropdown);
                dropdown[0].style.width = dropdown.data('g-item-width');
            });
        }
    },

    _debug: function() {}
});

module.exports = Menu;
