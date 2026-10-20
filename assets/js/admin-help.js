/**
 * ADC Marketing admin help — vanilla JS, no jQuery.
 *
 * Powers the "info icon + modal" component built by ADC_Admin_Help::icon():
 * clicking an .adc-help-btn moves that option's <template> content into the
 * single shared <dialog> rendered by ADC_Admin_Shell (admin footer), opens it
 * natively (showModal gives focus trapping + Escape for free) and returns
 * focus to the trigger button on close. Backdrop clicks close too.
 *
 * Browsers without native <dialog> support get the <details> fallback that is
 * already in the DOM (html.adc-help-dialog-support hides it only when the
 * modal path works). No user-facing text lives here — it comes from
 * window.adcAdminHelp (ADC_Admin_Help::script_config()).
 */
(function () {
    'use strict';

    var DIALOG_ID = 'adc-help-dialog';

    // Progressive-enhancement flag consumed by admin-help.css.
    var supportsDialog = typeof window.HTMLDialogElement === 'function' &&
        typeof window.HTMLDialogElement.prototype.showModal === 'function';

    var lastTrigger = null;

    function config() {
        return window.adcAdminHelp || {};
    }

    function findDialog() {
        return document.getElementById(DIALOG_ID);
    }

    // Create the shared dialog when the footer markup was not rendered
    // (defensive; ADC_Admin_Shell normally prints it on in_footer priority 99).
    function ensureDialog() {
        var dialog = findDialog();
        if (dialog) {
            return dialog;
        }

        var cfg = config();
        dialog = document.createElement('dialog');
        dialog.id = DIALOG_ID;
        dialog.className = 'adc-help-dialog';
        dialog.setAttribute('aria-labelledby', 'adc-help-dialog-title');

        var inner = document.createElement('div');
        inner.className = 'adc-help-dialog__inner';

        var head = document.createElement('div');
        head.className = 'adc-help-dialog__head';

        var title = document.createElement('h2');
        title.id = 'adc-help-dialog-title';
        title.className = 'adc-help-dialog__title';

        var dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'adc-help-dialog__dismiss';
        dismiss.setAttribute('data-adc-help-close', '');
        dismiss.setAttribute('aria-label', cfg.closeAria || 'Închide');
        dismiss.textContent = '\u00d7'; // ×

        head.appendChild(title);
        head.appendChild(dismiss);

        var body = document.createElement('div');
        body.className = 'adc-help-dialog__body';

        var actions = document.createElement('p');
        actions.className = 'adc-help-dialog__actions';

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'button';
        close.setAttribute('data-adc-help-close', '');
        close.textContent = cfg.closeLabel || 'Închide';

        actions.appendChild(close);

        inner.appendChild(head);
        inner.appendChild(body);
        inner.appendChild(actions);
        dialog.appendChild(inner);
        document.body.appendChild(dialog);

        return dialog;
    }

    function templateFor(btn) {
        var id = btn.getAttribute('data-adc-help');
        if (!id) {
            return null;
        }
        var tpl = document.getElementById('tmpl-adc-help-' + id);
        return tpl && 'TEMPLATE' === tpl.tagName ? tpl : null;
    }

    function open(btn) {
        var tpl = templateFor(btn);
        if (!tpl) {
            return;
        }

        var dialog = ensureDialog();
        var title = document.getElementById('adc-help-dialog-title');
        var body = dialog.querySelector('.adc-help-dialog__body');

        if (title) {
            title.textContent = tpl.getAttribute('data-adc-help-title') || '';
        }

        if (body) {
            body.textContent = '';
            // moveNodes() transfers children into the body and returns the
            // (now empty) template back to its inert state automatically.
            if (typeof body.moveNodes === 'function' && tpl.content) {
                body.moveNodes(tpl.content);
            } else if (tpl.content) {
                while (tpl.content.firstChild) {
                    body.appendChild(tpl.content.firstChild);
                }
            } else {
                body.innerHTML = tpl.innerHTML;
                tpl.innerHTML = '';
            }
        }

        lastTrigger = btn;

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', 'open');
            dialog.style.display = 'block';
        }
    }

    function close() {
        var dialog = findDialog();
        if (!dialog) {
            return;
        }

        if (typeof dialog.close === 'function' && dialog.open) {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
            dialog.style.display = '';
        }
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.closest) {
            return;
        }

        var btn = target.closest('.adc-help-btn');
        if (btn) {
            // The icon can live inside a <label> wrapper (checkbox rows): a click
            // there would otherwise toggle the control it labels.
            event.preventDefault();
            open(btn);
            return;
        }

        if (target.closest('[data-adc-help-close]')) {
            close();
        }
    });

    // Backdrop click closes the modal (Escape is handled natively by <dialog>).
    document.addEventListener('close', function (event) {
        var dialog = event.target;
        if (!dialog || dialog.id !== DIALOG_ID) {
            return;
        }
        if (lastTrigger && document.contains(lastTrigger)) {
            lastTrigger.focus();
        }
        lastTrigger = null;
    }, true);

    document.addEventListener('pointerdown', function (event) {
        var dialog = findDialog();
        if (!dialog || !dialog.open || dialog.contains(event.target)) {
            return;
        }
        // The pointer went down on the backdrop (outside the dialog box).
        event.preventDefault();
        close();
    });

    if (supportsDialog) {
        document.documentElement.classList.add('adc-help-dialog-support');
    }
})();
