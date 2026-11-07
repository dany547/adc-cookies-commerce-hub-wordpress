/* ADC Marketing 2.0 — admin shell enhancements (vanilla JS, no jQuery, defer).
 *
 * Behaviours:
 * 1. Tab nav is plain links (no JS needed).
 * 2. Secret-field replace toggle:
 *      <span class="adc-secret-field">
 *        <input class="adc-secret-input" type="password" readonly value="••••• (salvat)" data-adc-mask="••••• (salvat)">
 *        <button type="button" class="button adc-secret-replace">Înlocuiește</button>
 *      </span>
 *    Unlocking clears the value so an empty secret keeps the stored one server-side.
 * 3. Copy buttons:
 *      <button type="button" class="button adc-copy-btn" data-adc-copy="#selector-or-literal">Copiază</button>
 */
(function () {
    'use strict';

    var MASK_DEFAULT = '••••• (salvat)';
    var COPY_LABEL = 'Copiază';
    var COPIED_LABEL = 'Copiat!';
    var COPIED_CLASS = 'is-copied';
    var COPIED_MS = 1500;

    function onReady(fn) {
        if ('loading' !== document.readyState) {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function findInput(field) {
        return field ? field.querySelector('.adc-secret-input') : null;
    }

    function unlock(button, input) {
        input.readOnly = false;
        input.type = 'text';
        input.value = '';
        button.textContent = 'Renunță';
        button.setAttribute('aria-pressed', 'true');
        input.focus();
    }

    function relatch(button, input) {
        input.readOnly = true;
        input.type = 'password';
        input.value = input.getAttribute('data-adc-mask') || MASK_DEFAULT;
        button.textContent = 'Înlocuiește';
        button.setAttribute('aria-pressed', 'false');
    }

    function initSecretToggles() {
        var buttons = document.querySelectorAll('.adc-secret-replace');
        Array.prototype.forEach.call(buttons, function (button) {
            var input = findInput(button.closest('.adc-secret-field'));
            if (!input) {
                return;
            }

            button.setAttribute('aria-pressed', input.readOnly ? 'false' : 'true');
            button.addEventListener('click', function () {
                if (input.readOnly) {
                    unlock(button, input);
                } else {
                    relatch(button, input);
                }
            });
        });
    }

    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', 'readonly');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            try {
                document.execCommand('copy') ? resolve() : reject(new Error('copy failed'));
            } catch (err) {
                reject(err);
            } finally {
                document.body.removeChild(area);
            }
        });
    }

    function statesEqual(current, initial) {
        return current.length === initial.length && current.every(function (value, index) {
            return value === initial[index];
        });
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { statesEqual: statesEqual };
    }

    function controlState(control) {
        if (control.type === 'checkbox' || control.type === 'radio') {
            return control.checked ? '1' : '0';
        }
        if (control.type === 'select-multiple') {
            return Array.prototype.map.call(control.options, function (option) {
                return option.selected ? '1' : '0';
            }).join('');
        }
        return String(control.value);
    }

    function initUnsavedChanges() {
        var bar = document.querySelector('.adc-admin-save-bar');
        if (!bar) {
            return;
        }

        var form = bar.closest('form') || document.querySelector('.adc-admin-tab-content form');
        if (!form) {
            return;
        }

        var controls = Array.prototype.filter.call(form.querySelectorAll('input[name], select[name], textarea[name]'), function (control) {
            return control.type !== 'button' && control.type !== 'submit' && control.type !== 'reset' && control.type !== 'image';
        });
        if (!controls.length) {
            return;
        }

        var initial = controls.map(controlState);
        var status = document.createElement('span');
        status.className = 'adc-unsaved-status';
        status.setAttribute('aria-live', 'polite');
        status.hidden = true;

        var dot = document.createElement('span');
        dot.className = 'adc-unsaved-status__dot';
        dot.setAttribute('aria-hidden', 'true');
        status.appendChild(dot);
        status.appendChild(document.createTextNode('Ai modificări nesalvate'));
        bar.insertBefore(status, bar.firstChild);

        function update() {
            var changed = !statesEqual(controls.map(controlState), initial);
            status.hidden = !changed;
            Array.prototype.forEach.call(bar.querySelectorAll('button[type="submit"], input[type="submit"], .button-primary'), function (button) {
                button.classList.toggle('adc-unsaved-save', changed);
            });
        }

        controls.forEach(function (control) {
            control.addEventListener('input', update);
            control.addEventListener('change', update);
        });
        function warnBeforeUnload(event) {
            if (!statesEqual(controls.map(controlState), initial)) {
                event.preventDefault();
                event.returnValue = '';
            }
        }

        window.addEventListener('beforeunload', warnBeforeUnload);
        form.addEventListener('submit', function () {
            window.removeEventListener('beforeunload', warnBeforeUnload);
        });
    }

    function initCopyButtons() {
        var buttons = document.querySelectorAll('.adc-copy-btn[data-adc-copy]');
        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener('click', function () {
                var target = button.getAttribute('data-adc-copy') || '';
                var source = target.charAt(0) === '#' ? document.querySelector(target) : null;
                var text = source ? (source.value || source.textContent || '') : target;

                copyText(String(text).trim()).then(function () {
                    button.textContent = COPIED_LABEL;
                    button.classList.add(COPIED_CLASS);
                    window.setTimeout(function () {
                        button.textContent = COPY_LABEL;
                        button.classList.remove(COPIED_CLASS);
                    }, COPIED_MS);
                });
            });
        });
    }

    // Promo banner: dismiss in place; the link still works without JS.
    function initPromoDismiss() {
        var link = document.querySelector('[data-adc-promo-dismiss]');
        if (!link || typeof window.fetch !== 'function') {
            return;
        }
        link.addEventListener('click', function (event) {
            event.preventDefault();
            var banner = link.closest('[data-adc-promo]');
            window.fetch(link.href, { credentials: 'same-origin', redirect: 'manual' }).catch(function () {});
            if (banner) {
                var next = document.querySelector('.adc-admin-nav a');
                banner.parentNode.removeChild(banner);
                if (next) {
                    next.focus();
                }
            }
        });
    }

    if (typeof document !== 'undefined') {
        onReady(function () {
            initSecretToggles();
            initCopyButtons();
            initUnsavedChanges();
            initPromoDismiss();
        });
    }
}());
