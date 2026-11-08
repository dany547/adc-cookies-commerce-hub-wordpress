(function () {
    'use strict';

    var config = window.adcProductStats || {};
    var consent = window.adcConsent;
    var sentView = false;
    var restBlocked = false;

    function allowed() {
        return !!(window.adcConsent && window.adcConsent.has('statistics'));
    }

    function send(metric) {
        if (!allowed() || !config.endpoint || !config.ajaxUrl || !config.token || !Number.isInteger(config.productId) || config.productId < 1 || !window.fetch) {
            return;
        }
        var fields = { metric: metric, product_id: config.productId, token: config.token };
        function sendAjax() {
            var body = new URLSearchParams(fields);
            body.set('action', 'adc_product_stats');
            return window.fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: body.toString()
            }).catch(function () {});
        }
        if (restBlocked) {
            sendAjax();
            return;
        }
        window.fetch(config.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(fields)
        }).then(function (response) {
            if ([401, 403, 404].indexOf(response.status) !== -1) {
                restBlocked = true;
                return sendAjax();
            }
        }).catch(function () {});
    }

    function sendViewWhenAllowed() {
        if (!sentView && allowed()) {
            sentView = true;
            send('view');
        }
    }

    sendViewWhenAllowed();
    document.addEventListener('adc:consent:ready', sendViewWhenAllowed);
    document.addEventListener('adc:consent:change', sendViewWhenAllowed);
}());
