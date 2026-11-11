/* ADC Marketing — visual selector admin panel. */
(function (root) {
    'use strict';

    var doc = root && root.document;
    var container = doc && doc.querySelector('.adc-ce[data-ce-view="editor"]');
    var l10n = root && root.adcPickerL10n ? root.adcPickerL10n : {};
    var protocol = root && root.adcPickerProtocol;
    var available = Boolean(container && container.getAttribute('data-picker-available') === '1' && protocol);
    var state = null;

    function text(key) { return typeof l10n[key] === 'string' ? l10n[key] : ''; }
    function element(tag, className, value) {
        var node = doc.createElement(tag);
        if (className) { node.className = className; }
        if (typeof value === 'string') { node.textContent = value; }
        return node;
    }
    function button(label, className, action) {
        var node = element('button', className || 'button', label);
        node.type = 'button';
        node.addEventListener('click', action);
        return node;
    }
    function make(tag, attrs, content) {
        var node = element(tag, attrs.className || '', content || '');
        Object.keys(attrs).forEach(function (key) {
            if (key !== 'className') { node.setAttribute(key, attrs[key]); }
        });
        return node;
    }
    function message(node, value) { node.textContent = value; }
    function post(data, done) {
        var form = new root.FormData();
        Object.keys(data).forEach(function (key) { form.append(key, data[key]); });
        root.fetch(container.getAttribute('data-ce-ajax'), { method: 'POST', credentials: 'same-origin', body: form })
            .then(function (response) { return response.json().then(function (json) { return { status: response.status, json: json }; }); })
            .then(function (result) { done(null, result); })
            .catch(function () { done(new Error('request')); });
    }
    function revoke(token) {
        if (!token) { return; }
        post({ action: 'adc_picker_revoke', nonce: container.getAttribute('data-ce-nonce'), token: token }, function () {});
    }
    function safePath(path) {
        if (typeof path !== 'string' || !/^\/(?!\/)[^\\\s\x00-\x1f]{0,199}$/.test(path)) { return null; }
        try {
            var url = new root.URL(path, state.homeOrigin);
            if (url.origin !== state.homeOrigin) { return null; }
            url.searchParams.set('adc_picker', state.token);
            url.searchParams.set('adc_ts', String(Date.now()));
            return url;
        } catch (error) { return null; }
    }
    function randomChannel() {
        var bytes = new Uint8Array(16);
        root.crypto.getRandomValues(bytes);
        return Array.prototype.map.call(bytes, function (value) { return value.toString(16).padStart(2, '0'); }).join('');
    }
    function clearTimer(name) {
        if (state[name]) { root.clearTimeout(state[name]); state[name] = 0; }
    }
    function stopTimers() {
        ['timeout', 'renew', 'freezeTimer', 'testTimer'].forEach(clearTimer);
        if (state && state.popupCheck) { root.clearInterval(state.popupCheck); state.popupCheck = 0; }
        if (state && state.rateTimer) { root.clearInterval(state.rateTimer); state.rateTimer = 0; }
    }
    function setLoading() {
        state.ready = false;
        state.toolbar.disabled = true;
        Array.prototype.forEach.call(state.toolbar.querySelectorAll('button,select,input'), function (control) { control.disabled = !control.classList.contains('adc-pk-close'); });
        state.loading.hidden = false;
        state.timeoutBox.hidden = true;
        message(state.status, text('loading'));
    }
    function enableToolbar() {
        state.toolbar.disabled = false;
        Array.prototype.forEach.call(state.toolbar.querySelectorAll('button,select,input'), function (control) { control.disabled = false; });
        state.ready = true;
        syncMode();
    }
    function beginTimeout() {
        clearTimer('timeout');
        state.timeout = root.setTimeout(function () {
            state.ready = false;
            state.toolbar.disabled = true;
            Array.prototype.forEach.call(state.toolbar.querySelectorAll('button,select,input'), function (control) { control.disabled = !control.classList.contains('adc-pk-close'); });
            state.loading.hidden = true;
            state.timeoutBox.hidden = false;
            message(state.status, text('timeout'));
        }, 8000);
    }
    function send(type, payload) {
        if (!state || !state.ready || !state.target || protocol.TYPES_TO_FRAME.indexOf(type) === -1) { return false; }
        try {
            state.outSeq += 1;
            var envelope = protocol.make(type, state.ch, state.outSeq, payload);
            var checked = protocol.validate(envelope, protocol.DIR_TO_FRAME, { ch: state.ch, seq: state.outSeq - 1 });
            if (!checked.ok) { return false; }
            state.target.postMessage(envelope, state.homeOrigin);
            return true;
        } catch (error) { return false; }
    }
    function initFrame() {
        var envelope = protocol.make('init', state.ch, ++state.outSeq, {
            ch: state.ch,
            mode: state.mode === 'frozen' ? 'select' : state.mode,
            viewport: state.viewport
        });
        var checked = protocol.validate(envelope, protocol.DIR_TO_FRAME, { ch: state.ch, seq: state.outSeq - 1 });
        if (checked.ok && state.target) { state.target.postMessage(envelope, state.homeOrigin); }
    }
    function showError(code) {
        state.status.textContent = code === 'internal' ? text('errorInternal') : text('invalid');
    }
    function syncMode() {
        if (!state || !state.ready) { return; }
        state.modeSelect.value = state.mode === 'navigate' ? 'navigate' : 'select';
        state.freeze.textContent = state.mode === 'frozen' ? text('unfreeze') : text('freeze');
        state.toolbar.appendChild(state.freezeCountdown);
        state.freeze.setAttribute('aria-pressed', state.mode === 'frozen' ? 'true' : 'false');
        Array.prototype.forEach.call(state.viewportButtons, function (control) { control.disabled = state.mode === 'frozen' || !state.iframe; });
    }
    function pathReady(path) {
        state.ready = true;
        clearTimer('timeout');
        state.loading.hidden = true;
        state.timeoutBox.hidden = true;
        state.path.value = path;
        state.lastPath = path;
        enableToolbar();
        message(state.status, '');
        if (state.oldToken) { revoke(state.oldToken); state.oldToken = ''; }
    }
    function displayHover(summary) {
        if (!summary) { return; }
        var pieces = [summary.tag];
        if (summary.id) { pieces.push('#' + summary.id); }
        if (summary.classes.length) { pieces.push('.' + summary.classes.join('.')); }
        state.hover.textContent = text('underCursor') + ': ' + pieces.join('');
    }
    function reorder(scope) {
        if (!state.candidates.length) { return; }
        var preferred = scope === 'similar' ? 'similar' : 'unique';
        state.candidates.sort(function (a, b) {
            if (a.scope !== b.scope) { return a.scope === preferred ? -1 : 1; }
            return b.score - a.score || a.selector.length - b.selector.length;
        });
        renderCandidates();
    }
    function renderSummary(summary) {
        while (state.summary.firstChild) { state.summary.removeChild(state.summary.firstChild); }
        if (!summary) { state.summary.textContent = text('noSelection'); return; }
        var title = element('strong', 'adc-pk-summary-title', '<' + summary.tag + '>');
        state.summary.appendChild(title);
        if (summary.id) { state.summary.appendChild(element('span', 'adc-pk-summary-id', text('idLabel') + ': ' + summary.id)); }
        if (summary.classes.length) { state.summary.appendChild(element('span', 'adc-pk-summary-classes', text('classLabel') + ': ' + summary.classes.join(' '))); }
        if (summary.text) { state.summary.appendChild(element('span', 'adc-pk-summary-text', summary.text)); }
    }
    function renderCandidates() {
        while (state.candidateList.firstChild) { state.candidateList.removeChild(state.candidateList.firstChild); }
        state.candidateInputs = [];
        state.candidates.forEach(function (candidate, index) {
            var row = element('div', 'adc-pk-candidate');
            var label = element('label', 'adc-pk-candidate-label');
            var radio = element('input', 'adc-pk-candidate-radio');
            radio.type = 'radio'; radio.name = 'adc-picker-candidate'; radio.value = String(index);
            radio.addEventListener('change', function () { if (radio.checked) { selectCandidate(candidate); } });
            state.candidateInputs.push(radio);
            var code = element('code', 'adc-pk-selector', candidate.selector);
            label.appendChild(radio); label.appendChild(code);
            row.appendChild(label);
            row.appendChild(element('span', 'adc-pk-count', format(text('matches'), candidate.count)));
            row.appendChild(element('span', 'adc-pk-strategy', strategyLabel(candidate.strategy)));
            var score = element('span', 'adc-pk-score', String(candidate.score)); score.setAttribute('aria-hidden', 'true');
            row.appendChild(score);
            if (candidate.scope === 'similar') { row.appendChild(element('p', 'adc-pk-warning', text('similarWarning'))); }
            state.candidateList.appendChild(row);
        });
    }
    function strategyLabel(strategy) {
        var key = 'strategy_' + strategy;
        return text(key) || strategy;
    }
    function format(pattern, value) { return pattern.replace('%s', String(value)); }
    function selectCandidate(candidate) {
        state.selector.value = candidate.selector;
        state.selectedCandidate = candidate.selector;
        send('highlight', { selector: candidate.selector });
        state.useButton.disabled = false;
        state.selector.focus();
    }
    function clearSelection(sendClear) {
        state.candidates = [];
        state.selectedCandidate = '';
        state.picked = false;
        renderSummary(null);
        renderCandidates();
        state.selector.value = '';
        state.testResult.textContent = '';
        state.clearButton.disabled = true;
        state.useButton.disabled = true;
        if (sendClear) { send('clear', {}); }
    }
    function testSelector() {
        var value = state.selector.value;
        if (!value || value.length > 200) { message(state.testResult, text('invalid')); return; }
        var now = Date.now();
        state.testTimes = state.testTimes.filter(function (time) { return now - time < 1000; });
        if (state.testTimes.length >= 20) { message(state.testResult, text('rateLimited')); return; }
        state.testTimes.push(now);
        clearTimer('testTimer');
        state.requestId += 1;
        state.lastRequest = state.requestId;
        send('test_selector', { req: state.requestId, selector: value });
        message(state.testResult, text('testing'));
        state.testTimer = root.setTimeout(function () {
            if (state.lastRequest === state.requestId) { message(state.testResult, text('testTimeout')); }
        }, 2000);
    }
    function receive(event) {
        if (!state || event.origin !== state.homeOrigin || event.source !== state.target) { return; }
        var data = event.data;
        if (!data || typeof data !== 'object') { return; }
        var isHello = data.type === 'hello';
        var checked;
        try {
            checked = protocol.validate(data, protocol.DIR_TO_ADMIN, isHello ? {} : { ch: state.ch, seq: state.lastSeq });
        } catch (error) { return; }
        if (!checked.ok) { return; }
        if (isHello) {
            state.lastSeq = data.seq;
            initFrame();
            return;
        }
        if (!state.ch || data.ch !== state.ch) { return; }
        state.lastSeq = data.seq;
        if (checked.type === 'ready') { pathReady(checked.payload.href_path); return; }
        if (!state.ready) { return; }
        var payload = checked.payload;
        if (checked.type === 'hover') { displayHover(payload.summary); }
        else if (checked.type === 'pick') {
            state.picked = true;
            state.candidates = payload.candidates.slice();
            renderSummary(payload.summary);
            renderCandidates();
            state.clearButton.disabled = false;
            state.useButton.disabled = true;
            state.notice.textContent = payload.notice ? text('notice_' + payload.notice) : '';
            reorder(state.scope.value);
        } else if (checked.type === 'mode_changed') {
            state.mode = payload.mode;
            state.freezeUntil = 0;
            clearTimer('freezeTimer');
            state.freezeCountdown.textContent = '';
            syncMode();
        } else if (checked.type === 'test_result') {
            if (payload.req !== state.lastRequest) { return; }
            clearTimer('testTimer');
            if (!payload.valid) { message(state.testResult, text('invalid')); send('clear', {}); return; }
            if (payload.count > 0) { send('highlight', { selector: state.selector.value }); message(state.testResult, format(text('matchCount'), payload.count)); }
            else { message(state.testResult, text('zeroMatches')); }
            if (!payload.matches_picked) { state.testResult.textContent += ' ' + text('notPicked'); }
            state.useButton.disabled = false;
        } else if (checked.type === 'blocked') { message(state.status, text('blocked')); }
        else if (checked.type === 'nav') {
            state.lastPath = payload.href_path;
            setLoading();
            beginTimeout();
        } else if (checked.type === 'error') { showError(payload.code); }
    }
    function loadPath(path) {
        var url = safePath(path);
        if (!url) { message(state.status, text('invalidPath')); return false; }
        state.lastPath = path;
        setLoading();
        beginTimeout();
        if (state.popup) { try { state.popup.location = url.href; } catch (error) { message(state.status, text('popupBlocked')); } }
        else if (state.iframe) { state.iframe.src = url.href; }
        return true;
    }
    function requestToken(done) {
        post({ action: 'adc_picker_token', nonce: container.getAttribute('data-ce-nonce') }, function (error, result) {
            if (error) { done(error); return; }
            if (result.status === 429) { done(new Error('rate')); return; }
            var body = result.json && result.json.data;
            if (!result.json || !result.json.success || !body || typeof body.token !== 'string' || !/^[a-f0-9]{32}$/.test(body.token)) { done(new Error('token')); return; }
            done(null, body);
        });
    }
    function renewToken() {
        if (!state || state.closed) { return; }
        requestToken(function (error, tokenData) {
            if (error || !state || state.closed) { if (state) { message(state.status, error && error.message === 'rate' ? text('rateLimit') : text('renewError')); } return; }
            state.oldToken = state.token;
            state.token = tokenData.token;
            state.tokenCreated = Date.now();
            loadPath(state.lastPath);
            state.renew = root.setTimeout(renewToken, 540000);
        });
    }
    function openPopup() {
        if (!state || !state.token) { return; }
        var url = safePath(state.lastPath);
        if (!url) { message(state.status, text('invalidPath')); return; }
        var popup = root.open(url.href, 'adc-picker', 'popup,width=1280,height=900');
        if (!popup) { message(state.status, text('popupBlocked')); return; }
        state.popup = popup;
        state.target = popup;
        if (state.iframe) { state.iframe.src = 'about:blank'; state.iframe.remove(); state.iframe = null; }
        state.preview.appendChild(element('p', 'adc-pk-popup-note', text('popupNote')));
        Array.prototype.forEach.call(state.viewportButtons, function (control) { control.disabled = true; });
        setLoading(); beginTimeout();
        state.popupCheck = root.setInterval(function () {
            try { if (popup.closed) { close(false); } } catch (error) { close(false); }
        }, 1000);
    }
    function close(apply) {
        if (!state || state.closed) { return; }
        var current = state;
        if (apply && current.picked && !current.used && !root.confirm(text('confirmClose'))) { return; }
        current.closed = true;
        stopTimers();
        root.removeEventListener('message', receive, false);
        if (current.iframe) { current.iframe.src = 'about:blank'; current.iframe.remove(); }
        if (current.popup) { try { current.popup.close(); } catch (error) {} }
        if (current.dialog.open && typeof current.dialog.close === 'function') { current.dialog.close(); }
        if (current.dialog.parentNode) { current.dialog.parentNode.removeChild(current.dialog); }
        if (current.token) { revoke(current.token); }
        if (current.oldToken) { revoke(current.oldToken); }
        state = null;
        if (current.returnFocus && typeof current.returnFocus.focus === 'function') { current.returnFocus.focus(); }
    }
    function createDialog(options) {
        var dialog = element('dialog', 'adc-pk-dialog');
        dialog.setAttribute('aria-labelledby', 'adc-pk-title');
        var layout = element('div', 'adc-pk-layout');
        var main = element('div', 'adc-pk-main');
        var toolbar = element('div', 'adc-pk-toolbar');
        var pathLabel = element('label', 'adc-pk-path-label', text('pathLabel'));
        var path = element('input', 'adc-pk-path'); path.type = 'text'; path.value = options.path; path.maxLength = 200; pathLabel.appendChild(path);
        var load = button(text('load'), 'button adc-pk-load', function () { loadPath(path.value); });
        var viewportButtons = [];
        [['desktop', 'desktop'], ['tablet', 'tablet'], ['mobile', 'mobile']].forEach(function (entry) {
            var control = button(text(entry[1]), 'button adc-pk-viewport', function () {
                if (!state.ready || state.mode === 'frozen' || !state.iframe) { return; }
                state.viewport = entry[0];
                state.iframe.style.width = entry[0] === 'tablet' ? '768px' : (entry[0] === 'mobile' ? '390px' : '100%');
                Array.prototype.forEach.call(viewportButtons, function (item) { item.setAttribute('aria-pressed', item === control ? 'true' : 'false'); });
            });
            control.setAttribute('aria-pressed', entry[0] === 'desktop' ? 'true' : 'false'); viewportButtons.push(control); toolbar.appendChild(control);
        });
        var modeLabel = element('label', 'adc-pk-mode-label', text('modeLabel'));
        var modeSelect = element('select', 'adc-pk-mode');
        [['select', 'modeSelect'], ['navigate', 'modeNavigate']].forEach(function (item) { var option = element('option', '', text(item[1])); option.value = item[0]; modeSelect.appendChild(option); });
        modeSelect.addEventListener('change', function () {
            if (state.ready) { send('set_mode', { mode: modeSelect.value }); }
            modeSelect.value = state.mode === 'navigate' ? 'navigate' : 'select';
        });
        modeLabel.appendChild(modeSelect);
        var freeze = button(text('freeze'), 'button adc-pk-freeze', function () {
            if (!state.ready) { return; }
            if (state.mode === 'frozen') { send('set_mode', { mode: 'unfreeze' }); return; }
            send('freeze_in', { seconds: 3 });
            state.freezeUntil = Date.now() + 3000;
            (function tick() {
                if (!state || !state.freezeUntil) { return; }
                var remaining = Math.max(0, Math.ceil((state.freezeUntil - Date.now()) / 1000));
                state.freezeCountdown.textContent = remaining ? format(text('freezing'), remaining) : '';
                if (remaining) { state.freezeTimer = root.setTimeout(tick, 250); }
            }());
        });
        var closeButton = button(text('close'), 'button adc-pk-close', function () { close(true); });
        var freezeCountdown = element('span', 'adc-pk-freeze-countdown');
        toolbar.appendChild(pathLabel); toolbar.appendChild(load); toolbar.appendChild(modeLabel); toolbar.appendChild(freeze); toolbar.appendChild(freezeCountdown); toolbar.appendChild(closeButton);
        main.appendChild(toolbar);
        var loading = make('p', { className: 'adc-pk-loading', 'aria-live': 'polite' }, text('loading'));
        main.appendChild(loading);
        var timeoutBox = element('div', 'adc-pk-timeout'); timeoutBox.hidden = true;
        timeoutBox.appendChild(element('p', 'adc-pk-timeout-message', text('timeout')));
        timeoutBox.appendChild(button(text('retry'), 'button adc-pk-retry', function () { loadPath(state.lastPath); }));
        timeoutBox.appendChild(button(text('openPopup'), 'button adc-pk-open-popup', openPopup));
        main.appendChild(timeoutBox);
        var preview = element('div', 'adc-pk-preview');
        var iframe = element('iframe', 'adc-pk-iframe');
        iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin'); iframe.setAttribute('referrerpolicy', 'no-referrer'); iframe.setAttribute('title', text('frameTitle'));
        iframe.style.width = '100%'; preview.appendChild(iframe); main.appendChild(preview);
        var side = element('aside', 'adc-pk-panel');
        var notice = element('p', 'adc-pk-notice'); side.appendChild(notice);
        var status = make('p', { className: 'adc-pk-status', 'aria-live': 'polite' }, text('loading')); side.appendChild(status);
        var hover = element('p', 'adc-pk-hover', text('underCursor')); side.appendChild(hover);
        side.appendChild(element('p', 'adc-pk-auth-note', text('authNote')));
        side.appendChild(element('p', 'adc-pk-navigation-note', text('navigationNote')));
        var summary = element('div', 'adc-pk-summary', text('noSelection')); side.appendChild(summary);
        var scope = element('select', 'adc-pk-scope'); scope.setAttribute('aria-label', text('scopeLabel'));
        [['unique', 'thisOnly'], ['similar', 'allSimilar']].forEach(function (item) { var option = element('option', '', text(item[1])); option.value = item[0]; scope.appendChild(option); });
        scope.addEventListener('change', function () { reorder(scope.value); }); side.appendChild(scope);
        var candidateList = element('div', 'adc-pk-candidates'); candidateList.setAttribute('role', 'radiogroup'); candidateList.setAttribute('aria-label', text('candidates')); side.appendChild(candidateList);
        var selectorLabel = element('label', 'adc-pk-selector-label', text('editSelector'));
        var selector = element('input', 'adc-pk-selector-input'); selector.type = 'text'; selector.maxLength = 200; selectorLabel.appendChild(selector); side.appendChild(selectorLabel);
        var testResult = make('p', { className: 'adc-pk-test-result', 'aria-live': 'polite' }, ''); side.appendChild(testResult);
        var testButton = button(text('test'), 'button adc-pk-test', testSelector); side.appendChild(testButton);
        var clearButton = button(text('clearSelection'), 'button adc-pk-clear', function () { clearSelection(true); }); clearButton.disabled = true; side.appendChild(clearButton);
        var useButton = button(text('useSelector'), 'button button-primary adc-pk-use', function () {
            if (!state || state.used || !state.selector.value || state.selector.value.length > 200) { return; }
            state.used = true;
            var callback = state.onPick;
            var selected = state.selector.value;
            close(false);
            if (typeof callback === 'function') { callback(selected); }
        }); useButton.disabled = true; side.appendChild(useButton);
        layout.appendChild(main); layout.appendChild(side); dialog.appendChild(element('h2', 'adc-pk-title', text('title'))); dialog.firstChild.id = 'adc-pk-title'; dialog.appendChild(layout);
        dialog.addEventListener('cancel', function (event) { event.preventDefault(); close(true); });
        dialog.addEventListener('keydown', function (event) { if (event.key === 'Escape') { event.preventDefault(); close(true); } });
        doc.body.appendChild(dialog);
        return { dialog: dialog, toolbar: toolbar, path: path, viewportButtons: viewportButtons, modeSelect: modeSelect, freeze: freeze, freezeCountdown: freezeCountdown, loading: loading, timeoutBox: timeoutBox, preview: preview, iframe: iframe, status: status, hover: hover, notice: notice, summary: summary, scope: scope, candidateList: candidateList, selector: selector, testResult: testResult, clearButton: clearButton, useButton: useButton };
    }
    function open(options) {
        if (!available || state) { return; }
        options = options || {};
        var origin = container.getAttribute('data-home-origin');
        try { if (!origin || new root.URL(origin).origin !== origin) { return; } } catch (error) { return; }
        var requestedPath = typeof options.path === 'string' ? options.path : (container.getAttribute('data-picker-home-path') || '/');
        if (!/^\/(?!\/)[^\\\s\x00-\x1f]{0,199}$/.test(requestedPath)) { return; }
        var ui = createDialog({ path: requestedPath });
        state = {
            homeOrigin: origin, token: '', oldToken: '', tokenCreated: 0, ch: randomChannel(), outSeq: 0, lastSeq: 0,
            mode: 'select', viewport: 'desktop', ready: false, target: ui.iframe.contentWindow, iframe: ui.iframe, popup: null,
            lastPath: requestedPath, onPick: options.onPick, used: false, picked: false, candidates: [], selectedCandidate: '',
            testTimes: [], requestId: 0, lastRequest: 0, closed: false, returnFocus: doc.activeElement,
            dialog: ui.dialog, toolbar: ui.toolbar, path: ui.path, viewportButtons: ui.viewportButtons, modeSelect: ui.modeSelect,
            freeze: ui.freeze, freezeCountdown: ui.freezeCountdown, loading: ui.loading, timeoutBox: ui.timeoutBox,
            preview: ui.preview, status: ui.status, hover: ui.hover, notice: ui.notice, summary: ui.summary,
            scope: ui.scope, candidateList: ui.candidateList, selector: ui.selector, testResult: ui.testResult,
            clearButton: ui.clearButton, useButton: ui.useButton
        };
        state.toolbar.appendChild(state.freezeCountdown);
        state.path.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); loadPath(state.path.value); } });
        state.selector.addEventListener('input', function () { state.useButton.disabled = !state.picked || !state.selector.value || state.selector.value.length > 200; });
        root.addEventListener('message', receive, false);
        try { state.dialog.showModal(); } catch (error) { state.dialog.setAttribute('open', 'open'); }
        requestToken(function (error, data) {
            if (!state || state.closed) { return; }
            if (error) {
                message(state.status, error.message === 'rate' ? text('rateLimit') : text('tokenError'));
                return;
            }
            state.token = data.token; state.tokenCreated = Date.now();
            loadPath(state.lastPath);
            state.renew = root.setTimeout(renewToken, 540000);
        });
    }
    function bindButton() {
        if (!available) { return; }
        var trigger = container.querySelector('.adc-ce-picker');
        if (!trigger) { return; }
        trigger.hidden = false;
        trigger.addEventListener('click', function () {
            var api = root.adcCustomEventsAdmin;
            open({ onPick: api && typeof api.setSelector === 'function' ? api.setSelector : null });
        });
    }
    var api = { available: available, open: open, close: function () { close(true); } };
    if (root) { root.adcPicker = api; }
    if (doc && available) { bindButton(); }
}(typeof window !== 'undefined' ? window : null));
