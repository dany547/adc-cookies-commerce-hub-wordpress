/**
 * ADC Marketing export links: add the optional date range to the export URLs
 * and confirm before a range-limited export.
 *
 * Vanilla JS, loaded with defer. The export links are plain admin-post.php
 * URLs that already carry the nonce, so this script only appends dates.
 */

(function () {
    'use strict';

    // All UI copy comes from PHP (wp_localize_script -> window.adcExportI18n);
    // nothing is translated here, so no interface text lives in this file.
    var i18n = window.adcExportI18n || {};

    var links = document.querySelectorAll('a[data-adc-export]');
    var startInput = document.getElementById('adc_export_start_date');
    var endInput = document.getElementById('adc_export_end_date');

    if (!links.length || !startInput || !endInput) {
        return;
    }

    links.forEach(function (link) {
        link.addEventListener('click', function (event) {
            var startDate = startInput.value;
            var endDate = endInput.value;
            var url = new URL(link.href);

            if (startDate || endDate) {
                if (i18n.confirmRange && !window.confirm(i18n.confirmRange)) {
                    event.preventDefault();
                    return;
                }
            }

            setOrDelete(url.searchParams, 'adc_export_start_date', startDate);
            setOrDelete(url.searchParams, 'adc_export_end_date', endDate);

            event.preventDefault();
            window.location.assign(url.toString());
        });
    });

    /**
     * Set a query parameter, or remove it when the value is empty.
     *
     * @param {URLSearchParams} params Search parameters to update.
     * @param {string} name            Parameter name.
     * @param {string} value           Parameter value ('' removes it).
     */
    function setOrDelete(params, name, value) {
        if (value) {
            params.set(name, value);
        } else {
            params.delete(name);
        }
    }
})();
