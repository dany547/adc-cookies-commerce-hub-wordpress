/* ADC Marketing — custom-event editor interactions. */
(function (root, factory) {
    'use strict';

    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    if (root && root.document) {
        api.init(root);
        root.adcCustomEventsAdmin = {
            setSelector: function (value) { api.setSelector(root, value); }
        };
    }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    var MAX_PARAMS = 10;
    var TRIGGERS = ['click', 'form_success', 'visible', 'scroll_depth', 'timer'];
    var PRESETS = {
        tel: ['click', 'tel'], mailto: ['click', 'mailto'], whatsapp: ['click', 'whatsapp'],
        download: ['click', 'download'], outbound: ['click', 'outbound'],
        cf7: ['form_success', 'cf7'], wpforms: ['form_success', 'wpforms'],
        elementor: ['form_success', 'elementor'], scroll: ['scroll_depth', ''], timer: ['timer', '']
    };

    function canAddParam(count) {
        return Number(count) < MAX_PARAMS;
    }

    function reindexParams(tbody) {
        if (!tbody || !tbody.querySelectorAll) return 0;
        var rows = tbody.querySelectorAll('.adc-ce-param');
        Array.prototype.forEach.call(rows, function (row, index) {
            var key = row.querySelector('input[name$="[key]"]');
            var value = row.querySelector('input[name$="[value]"]');
            if (key) key.setAttribute('name', 'rule[params][' + index + '][key]');
            if (value) value.setAttribute('name', 'rule[params][' + index + '][value]');
        });
        return rows.length;
    }

    function setTriggerType(container, type) {
        if (!container || TRIGGERS.indexOf(type) === -1) return false;
        var fields = container.querySelectorAll('[data-ce-trigger]');
        Array.prototype.forEach.call(fields, function (fieldset) {
            fieldset.hidden = fieldset.getAttribute('data-ce-trigger') !== type;
        });
        var selector = container.querySelector('[data-ce-selector-field]');
        if (selector) selector.hidden = type !== 'click' && type !== 'visible';
        var tokens = container.querySelectorAll('[data-ce-token-types]');
        Array.prototype.forEach.call(tokens, function (button) {
            button.hidden = button.getAttribute('data-ce-token-types').split(/\s+/).indexOf(type) === -1;
        });
        var fire = container.querySelectorAll('input[name="rule[fire]"]');
        var fixed = ['visible', 'scroll_depth', 'timer'].indexOf(type) !== -1;
        Array.prototype.forEach.call(fire, function (input) {
            input.disabled = fixed;
            if (fixed && input.value === 'once') input.checked = true;
        });
        return true;
    }

    function setSelector(root, value) {
        var field = root && root.document ? root.document.querySelector('[name="rule[trigger][selector]"]') : null;
        if (!field) return false;
        field.value = String(value == null ? '' : value);
        if (typeof field.focus === 'function') field.focus();
        return true;
    }

    function applyPreset(root, preset) {
        var entry = PRESETS[preset];
        if (!entry || !root || !root.document) return false;
        var doc = root.document;
        var type = doc.querySelector('input[name="rule[trigger][type]"][value="' + entry[0] + '"]');
        if (type) type.checked = true;
        if (entry[0] === 'click') {
            var click = doc.querySelector('[name="rule[trigger][preset]"]');
            if (click) click.value = entry[1];
        } else if (entry[0] === 'form_success') {
            var form = doc.querySelector('[name="rule[trigger][form_preset]"]');
            if (form) form.value = entry[1];
        } else if (preset === 'scroll') {
            Array.prototype.forEach.call(doc.querySelectorAll('input[name="rule[trigger][depths][]"]'), function (item) { item.checked = true; });
        } else if (preset === 'timer') {
            var seconds = doc.querySelector('[name="rule[trigger][seconds]"]');
            if (seconds) seconds.value = '30';
        }
        setTriggerType(doc, entry[0]);
        return true;
    }

    function insertAtCursor(field, token) {
        if (!field) return;
        var start = typeof field.selectionStart === 'number' ? field.selectionStart : field.value.length;
        var end = typeof field.selectionEnd === 'number' ? field.selectionEnd : start;
        field.value = field.value.slice(0, start) + token + field.value.slice(end);
        var caret = start + token.length;
        if (typeof field.setSelectionRange === 'function') field.setSelectionRange(caret, caret);
        field.focus();
    }

    function updateDestinations(container) {
        var platform = container.querySelectorAll('input[name="rule[destinations][platforms][]"]');
        var server = container.querySelector('input[name="rule[destinations][server_mirror]"][type="checkbox"]');
        var routed = false;
        Array.prototype.forEach.call(platform, function (item) {
            if (item.checked) routed = true;
            var category = container.querySelector('input[name="rule[consent_category]"]:checked');
            var metaBlocked = item.value === 'meta' && category && category.value === 'statistics';
            item.disabled = Boolean(item._adcUnavailable || metaBlocked);
            if (item.disabled && item.value === 'meta') item.checked = false;
        });
        if (server) {
            server.checked = server.checked || routed;
            server.disabled = routed;
        }
        var category = container.querySelector('input[name="rule[consent_category]"]:checked');
    }

    function updateExample(container) {
        var example = container.querySelector('.adc-ce-example');
        if (!example) return;
        var name = container.querySelector('[name="rule[name_suffix]"]');
        var params = container.querySelectorAll('.adc-ce-param');
        var parts = [];
        Array.prototype.forEach.call(params, function (row) {
            var key = row.querySelector('input[name$="[key]"]');
            var value = row.querySelector('input[name$="[value]"]');
            if (key && key.value) parts.push('  ' + JSON.stringify(key.value) + ': ' + JSON.stringify(value ? value.value : ''));
        });
        example.textContent = 'dataLayer.push({ event: ' + JSON.stringify('custom_' + (name ? name.value : '')) + (parts.length ? ',\n' + parts.join(',\n') + '\n' : '') + ' });';
    }

    function showError(container, message, invalid) {
        var summary = container.querySelector('.adc-ce-errors');
        if (!summary) return;
        while (summary.firstChild) summary.removeChild(summary.firstChild);
        var text = container.ownerDocument.createElement('p');
        text.textContent = message;
        summary.appendChild(text);
        summary.hidden = false;
        if (invalid) {
            invalid.setAttribute('aria-invalid', 'true');
            invalid.setAttribute('aria-describedby', 'adc-ce-client-error');
            text.id = 'adc-ce-client-error';
        }
        if (typeof summary.focus === 'function') {
            summary.setAttribute('tabindex', '-1');
            summary.focus();
        }
    }

    function init(root) {
        var doc = root.document;
        doc.addEventListener('click', function (event) {
            var target = event.target;
            if (target && target.matches && target.matches('.adc-ce-row button[name="adc_ce_op"][value^="delete:"]')) {
                if (!root.confirm('Ștergi această regulă?')) { event.preventDefault(); event.stopPropagation(); }
            }
        });
        var container = doc.querySelector('.adc-ce[data-ce-view="editor"]');
        if (!container) return;
        var tbody = container.querySelector('.adc-ce-params tbody');
        var paramsSection = container.querySelector('.adc-ce-params');
        var focusedParam = null;
        var paramsCount = reindexParams(tbody);
        var typeInputs = container.querySelectorAll('input[name="rule[trigger][type]"]');
        Array.prototype.forEach.call(typeInputs, function (input) {
            if (input.checked) setTriggerType(container, input.value);
            input.addEventListener('change', function () { setTriggerType(container, input.value); updateExample(container); });
        });
        try {
            var preset = new URLSearchParams(root.location.search).get('preset');
            if (preset && container.getAttribute('data-ce-rule-new') === '1') applyPreset(root, preset);
        } catch (error) { /* Invalid URL state is ignored safely. */ }
        var platforms = container.querySelectorAll('input[name="rule[destinations][platforms][]"]');
        Array.prototype.forEach.call(platforms, function (item) {
            var note = item.parentNode && item.parentNode.nextElementSibling;
            item._adcUnavailable = Boolean(item.disabled && note && note.textContent.indexOf('Integrarea nu este configurată.') !== -1);
        });
        updateDestinations(container);
        updateExample(container);

        container.addEventListener('focusin', function (event) {
            if (paramsSection && paramsSection.contains(event.target) && event.target.matches('input')) focusedParam = event.target;
        });
        container.addEventListener('input', function (event) {
            if (event.target.matches('.adc-ce-param input, [name="rule[name_suffix]"]')) updateExample(container);
        });
        container.addEventListener('change', function (event) {
            if (event.target.matches('input[name="rule[destinations][platforms][]"], input[name="rule[consent_category]"]')) updateDestinations(container);
            if (event.target.matches('input[name="rule[consent_category]"]')) updateExample(container);
        });
        var add = container.querySelector('.adc-ce-add-param');
        if (add && tbody && !canAddParam(paramsCount)) {
            add.disabled = true;
            add.textContent = 'Ai atins limita de 10 parametri.';
            add.setAttribute('aria-label', 'Ai atins limita de 10 parametri.');
        }
        if (add && tbody) add.addEventListener('click', function () {
            paramsCount = reindexParams(tbody);
            if (!canAddParam(paramsCount)) {
                add.setAttribute('aria-label', 'Ai atins limita de 10 parametri.');
                add.textContent = 'Ai atins limita de 10 parametri.';
                add.disabled = true;
                return;
            }
            var index = paramsCount;
            var row = doc.createElement('tr'); row.className = 'adc-ce-param';
            ['key', 'value'].forEach(function (part) {
                var cell = doc.createElement('td');
                var input = doc.createElement('input');
                input.type = 'text'; input.maxLength = part === 'key' ? 40 : 100;
                input.setAttribute('name', 'rule[params][' + index + '][' + part + ']');
                input.setAttribute('aria-label', part === 'key' ? 'Cheie parametru' : 'Valoare parametru');
                cell.appendChild(input); row.appendChild(cell);
            });
            var cell = doc.createElement('td'); var remove = doc.createElement('button');
            remove.type = 'button'; remove.className = 'button button-small adc-ce-remove-param';
            remove.textContent = 'Șterge'; remove.setAttribute('aria-label', 'Șterge parametrul ' + (index + 1));
            cell.appendChild(remove); row.appendChild(cell); tbody.appendChild(row);
            reindexParams(tbody); row.querySelector('input').focus(); updateExample(container);
        });
        container.addEventListener('click', function (event) {
            var target = event.target;
            if (target.matches('.adc-ce-remove-param')) {
                var row = target.closest('.adc-ce-param');
                if (row) row.parentNode.removeChild(row);
                reindexParams(tbody); paramsCount = reindexParams(tbody);
                if (add && canAddParam(paramsCount)) { add.disabled = false; add.textContent = 'Adaugă parametru'; add.removeAttribute('aria-label'); }
                updateExample(container);
            }
            if (target.matches('[data-ce-token]')) {
                var field = focusedParam && paramsSection.contains(focusedParam) ? focusedParam : paramsSection.querySelector('.adc-ce-param input');
                insertAtCursor(field, target.getAttribute('data-ce-token'));
            }
        });
        var form = container.closest('form');
        if (form) form.addEventListener('submit', function (event) {
            var invalid = container.querySelector('[name="rule[label]"]');
            if (invalid && !invalid.value.trim()) {
                event.preventDefault(); showError(container, 'Denumirea internă este obligatorie.', invalid);
            }
        });
    }

    return { init: init, reindexParams: reindexParams, canAddParam: canAddParam, setTriggerType: setTriggerType, applyPreset: applyPreset, setSelector: setSelector, updateDestinations: updateDestinations, updateExample: updateExample };
}));
