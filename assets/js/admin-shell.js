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

    onReady(function () {
        initSecretToggles();
        initCopyButtons();
    });
}());
