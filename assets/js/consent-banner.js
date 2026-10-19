/**
 * ADC Marketing cookie banner, preferences modal and reopen button.
 *
 * UI layer over window.adcConsent (consent-manager.js). Vanilla JS, no jQuery.
 * Escape closes the preferences modal and never accepts. The modal, and the
 * banner in "modal" layout, trap focus. Motion is disabled in CSS for
 * prefers-reduced-motion.
 */
(function () {
    'use strict';

    var consent = window.adcConsent;
    var banner = document.getElementById('adc-consent-banner');
    var modal = document.getElementById('adc-consent-modal');
    var fab = document.querySelector('.adc-consent-fab');

    if (!consent || !banner || !modal) {
        return;
    }

    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    var layout = banner.getAttribute('data-layout') || 'bar_bottom';
    var isCenterModal = layout === 'center_modal';
    var active = null;      // dialog that currently traps focus
    var returnFocus = null; // element to focus when the open dialog closes

    function focusables(root) {
        return Array.prototype.slice.call(root.querySelectorAll(FOCUSABLE)).filter(function (element) {
            return !element.closest('[hidden]');
        });
    }

    function openDialog(dialog) {
        returnFocus = document.activeElement;
        dialog.hidden = false;
        active = dialog;
        if (isCenterModal) {
            document.body.style.overflow = 'hidden';
        }

        var items = focusables(dialog);
        if (items.length) {
            items[0].focus();
        }
    }

    function resumeBanner() {
        if (!banner.hidden && isCenterModal && modal.hidden) {
            active = banner;
        }
    }

    function closeDialog(dialog) {
        dialog.hidden = true;
        if (isCenterModal) {
            document.body.style.overflow = '';
        }

        if (active === dialog) {
            active = null;
        }

        if (returnFocus && typeof returnFocus.focus === 'function' && document.contains(returnFocus)) {
            returnFocus.focus();
        }
        returnFocus = null;

        resumeBanner();
    }

    function closeModal() {
        if (!modal.hidden) {
            closeDialog(modal);
        }
    }

    function openPreferences() {
        if (!modal.hidden) {
            return;
        }

        syncCheckboxes(consent.get());
        openDialog(modal);
    }

    function syncCheckboxes(state) {
        modal.querySelectorAll('input[data-adc-category]').forEach(function (input) {
            input.checked = state.categories[input.getAttribute('data-adc-category')] === true;
        });
    }

    function savePreferences() {
        var changes = {};

        modal.querySelectorAll('input[data-adc-category]').forEach(function (input) {
            changes[input.getAttribute('data-adc-category')] = input.checked;
        });

        consent.set(changes, 'modal');
        closeModal();
    }

    /**
     * Show the banner while there is no decision; keep the reopen button in sync.
     */
    function sync() {
        var state = consent.get();
        var show = !state.decided;
        var wasHidden = banner.hidden;

        banner.hidden = !show;

        if (fab) {
            fab.hidden = show;
        }

        if (show && wasHidden && isCenterModal && modal.hidden) {
            openDialog(banner);
        }

        if (!show) {
            if (active === banner) {
                active = null;
            }

            // Keep keyboard focus on the page when the banner disappears under it.
            if (fab && banner.contains(document.activeElement)) {
                fab.focus();
            }
        }

        syncCheckboxes(state);
    }

    function trapFocus(event, dialog) {
        var items = focusables(dialog);
        var first;
        var last;
        var current = document.activeElement;
        var inside = dialog.contains(current);

        if (!items.length) {
            event.preventDefault();
            return;
        }

        first = items[0];
        last = items[items.length - 1];

        if (event.shiftKey && (current === first || !inside)) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (current === last || !inside)) {
            event.preventDefault();
            first.focus();
        }
    }

    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element
            ? event.target.closest('[data-adc-action], [data-adc-open-preferences]')
            : null;
        var action;
        var source;

        if (!target) {
            return;
        }

        if (target.hasAttribute('data-adc-open-preferences')) {
            event.preventDefault();
            openPreferences();
            return;
        }

        action = target.getAttribute('data-adc-action');
        source = modal.contains(target) ? 'modal' : 'banner';

        switch (action) {
            case 'accept':
                consent.acceptAll(source);
                closeModal();
                break;
            case 'reject':
                consent.rejectAll(source);
                closeModal();
                break;
            case 'customize':
                openPreferences();
                break;
            case 'save':
                savePreferences();
                break;
            case 'close':
                closeModal();
                break;
            default:
                break;
        }
    });

    document.addEventListener('keydown', function (event) {
        if (!active || (active === banner && !isCenterModal)) {
            return;
        }

        if (event.key === 'Escape') {
            // Escape closes the preferences modal only. It never accepts.
            if (active === modal) {
                event.preventDefault();
                closeModal();
            }
            return;
        }

        if (event.key === 'Tab') {
            trapFocus(event, active);
        }
    });

    document.addEventListener('adc:consent:change', sync);
    document.addEventListener('adc:consent:open', openPreferences);

    sync();
})();
