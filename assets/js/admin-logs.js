/* ADC Marketing 2.0 — Logs & Debug tab (vanilla JS, no jQuery, defer).
 *
 * Behaviours (endpoints registered server-side by ADC_Admin_Tab_Logs):
 * 1. Retry one outbox row            -> wp_ajax adc_outbox_retry
 * 2. Purge sent/dead rows            -> wp_ajax adc_outbox_purge
 * 3. Send a test event               -> wp_ajax adc_send_test_event
 * 4. Consent lookup by cid           -> wp_ajax adc_consent_lookup
 *
 * Config: window.adcAdminLogs = { ajaxUrl, nonce, i18n } (inline before this file).
 */
(function () {
    'use strict';

    var CFG = window.adcAdminLogs || {};
    var I18N = CFG.i18n || {};
    var resultBox = null;

    function onReady(fn) {
        if ('loading' !== document.readyState) {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function i18n(key) {
        return I18N[key] || '';
    }

    function post(action, data) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', CFG.nonce || '');
        Object.keys(data || {}).forEach(function (key) {
            body.append(key, String(data[key]));
        });

        return fetch(CFG.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        }).then(function (response) {
            return response.json().catch(function () {
                throw new Error('bad_json');
            });
        });
    }

    function showResult(message, isError) {
        if (!resultBox) {
            return;
        }
        resultBox.textContent = message || '';
        resultBox.classList.toggle('is-error', Boolean(isError));
    }

    function setBusy(button, busy) {
        if (!button) {
            return;
        }
        if (busy) {
            button.dataset.adcLabel = button.textContent;
            button.textContent = i18n('testing') || '…';
            button.disabled = true;
        } else {
            if (button.dataset.adcLabel) {
                button.textContent = button.dataset.adcLabel;
            }
            button.disabled = false;
        }
    }

    function bindRetryButtons() {
        document.querySelectorAll('.adc-outbox-retry').forEach(function (button) {
            button.addEventListener('click', function () {
                var id = parseInt(button.getAttribute('data-id'), 10);
                if (!id) {
                    return;
                }
                setBusy(button, true);
                showResult(i18n('retrying'));

                post('adc_outbox_retry', { id: id }).then(function (json) {
                    setBusy(button, false);
                    if (json && json.success) {
                        showResult((json.data && json.data.message) || '');
                        window.setTimeout(function () {
                            window.location.reload();
                        }, 800);
                    } else {
                        showResult((json && json.data && json.data.message) || i18n('unknownError'), true);
                    }
                }).catch(function () {
                    setBusy(button, false);
                    showResult(i18n('unknownError'), true);
                });
            });
        });
    }

    function bindPurge() {
        var button = document.getElementById('adc-outbox-purge');
        if (!button) {
            return;
        }
        button.addEventListener('click', function () {
            var confirmText = button.getAttribute('data-confirm');
            if (confirmText && !window.confirm(confirmText)) {
                return;
            }
            setBusy(button, true);
            showResult(i18n('purging'));

            post('adc_outbox_purge', {}).then(function (json) {
                setBusy(button, false);
                if (json && json.success) {
                    showResult((json.data && json.data.message) || '');
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 800);
                } else {
                    showResult((json && json.data && json.data.message) || i18n('unknownError'), true);
                }
            }).catch(function () {
                setBusy(button, false);
                showResult(i18n('unknownError'), true);
            });
        });
    }

    function bindTestEvent() {
        var button = document.getElementById('adc-send-test-event');
        if (!button) {
            return;
        }
        button.addEventListener('click', function () {
            var confirmText = button.getAttribute('data-confirm');
            if (confirmText && !window.confirm(confirmText)) {
                return;
            }
            setBusy(button, true);
            showResult(i18n('testing'));

            post('adc_send_test_event', {}).then(function (json) {
                setBusy(button, false);
                if (json && json.success) {
                    var results = (json.data && json.data.results) || {};
                    var parts = Object.keys(results).map(function (platform) {
                        return platform + ': ' + results[platform];
                    });
                    showResult(((json.data && json.data.message) || '') + (parts.length ? ' [' + parts.join(', ') + ']' : ''));
                } else {
                    showResult((json && json.data && json.data.message) || i18n('unknownError'), true);
                }
            }).catch(function () {
                setBusy(button, false);
                showResult(i18n('unknownError'), true);
            });
        });
    }

    function bindConsentLookup() {
        var button = document.getElementById('adc-consent-lookup');
        var input = document.getElementById('adc-consent-cid');
        var output = document.getElementById('adc-consent-lookup-result');
        if (!button || !input || !output) {
            return;
        }

        button.addEventListener('click', function () {
            var cid = (input.value || '').trim();
            if (!cid) {
                return;
            }
            setBusy(button, true);
            output.textContent = i18n('lookingUp');

            post('adc_consent_lookup', { cid: cid }).then(function (json) {
                setBusy(button, false);
                if (!json || !json.success) {
                    output.textContent = (json && json.data && json.data.message) || i18n('unknownError');
                    return;
                }

                var state = (json.data && json.data.state) || {};
                if (!state.decided) {
                    output.textContent = i18n('lookupDenied');
                    return;
                }

                output.textContent = i18n('lookupFound') + ' ' +
                    'preferences: ' + (state.preferences ? i18n('granted') : i18n('denied')) + ', ' +
                    'statistics: ' + (state.statistics ? i18n('granted') : i18n('denied')) + ', ' +
                    'marketing: ' + (state.marketing ? i18n('granted') : i18n('denied')) + ', ' +
                    'source: ' + (state.source || '-');
            }).catch(function () {
                setBusy(button, false);
                output.textContent = i18n('unknownError');
            });
        });
    }

    onReady(function () {
        resultBox = document.getElementById('adc-logs-result');
        bindRetryButtons();
        bindPurge();
        bindTestEvent();
        bindConsentLookup();
    });
})();
