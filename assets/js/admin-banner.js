/* ADC Marketing — live preview for the "Aspect și poziție" section.
 * Vanilla JS; jQuery is used only as thin glue for wp-color-picker. */
(function () {
  'use strict';

  var root = document.getElementById('adc-banner-appearance');
  if (!root) {
    return;
  }

  var presets = window.adcBannerPresets && typeof window.adcBannerPresets === 'object'
    ? window.adcBannerPresets
    : {};

  var preview = document.getElementById('adc-consent-banner');
  var screenOverlay = document.getElementById('adc-banner-preview-overlay');
  var previewActions = document.getElementById('adc-banner-preview-actions');
  var orderSelect = document.getElementById('adc-banner-button-order');
  var gapInput = document.getElementById('adc-banner-accept-gap');
  var gapVal = document.getElementById('adc-banner-accept-gap-val');
  var compliancePanel = document.getElementById('adc-banner-compliance');
  var complianceList = document.getElementById('adc-banner-compliance-list');
  var compliance = window.adcBannerCompliance && typeof window.adcBannerCompliance === 'object'
    ? window.adcBannerCompliance
    : null;
  var overlayRow = root.querySelector('.adc-overlay-row');
  var radiusInput = document.getElementById('adc-banner-radius');
  var radiusVal = document.getElementById('adc-banner-radius-val');
  var shadowInput = document.getElementById('adc-banner-shadow');
  var overlayInput = document.getElementById('adc-banner-overlay');
  var resetButton = document.getElementById('adc-banner-reset');
  var accentInput = document.getElementById('adc-banner-color-accent');

  var applying = false;
  var activePreset = 'light';

  function each(list, fn) {
    Array.prototype.forEach.call(list, fn);
  }

  function presetRadios() {
    return root.querySelectorAll('input[name="adc_banner[preset]"]');
  }

  function layoutRadios() {
    return root.querySelectorAll('input[name="adc_banner[layout]"]');
  }

  function checkedRadio(list) {
    var found = null;
    each(list, function (input) {
      if (input.checked) {
        found = input;
      }
    });
    return found;
  }

  function colorKey(input) {
    var match = /adc_banner\[colors\]\[([a-z_]+)\]/.exec(input.name || '');
    return match ? match[1] : '';
  }

  function colorInput(key) {
    if (key === 'accent') {
      return accentInput;
    }
    return document.getElementById('adc-banner-color-' + key);
  }

  function varName(key) {
    return '--adc-' + key.replace(/_/g, '-');
  }

  function setVar(key, value) {
    if (preview) {
      preview.style.setProperty(varName(key), value);
    }
  }

  function currentLayout() {
    var checked = checkedRadio(layoutRadios());
    return checked ? checked.value : 'bar_bottom';
  }

  function checkPreset(slug) {
    each(presetRadios(), function (input) {
      input.checked = input.value === slug;
    });
    if (preview) {
      preview.setAttribute('data-preset', slug);
    }
  }

  function markCustom() {
    if (applying) {
      return;
    }
    checkPreset('custom');
  }

  function setPickerValue(input, value) {
    if (!input) {
      return;
    }
    input.value = value;
    try {
      if (window.jQuery && window.jQuery(input).wpColorPicker) {
        window.jQuery(input).iris('color', value);
      }
    } catch (ignore) {
      // The picker is not initialised for this field; plain value is enough.
    }
  }

  function applyPreset(slug) {
    var definition = presets[slug];
    if (!definition || typeof definition.colors !== 'object') {
      return;
    }
    applying = true;
    Object.keys(definition.colors).forEach(function (key) {
      var input = colorInput(key);
      setPickerValue(input, definition.colors[key]);
      setVar(key, definition.colors[key]);
    });
    if (typeof definition.radius !== 'undefined' && radiusInput) {
      radiusInput.value = String(definition.radius);
      syncRadius(false);
    }
    applying = false;
    activePreset = slug;
    checkPreset(slug);
    updateWarnings();
  }

  function syncRadius(mark) {
    if (!radiusInput) {
      return;
    }
    var value = Math.max(0, Math.min(24, parseInt(radiusInput.value, 10) || 0));
    if (preview) {
      preview.style.setProperty('--adc-radius', value + 'px');
    }
    if (radiusVal) {
      radiusVal.textContent = value + 'px';
    }
    if (mark !== false) {
      markCustom();
    }
  }

  function syncShadow() {
    if (preview) {
      preview.style.setProperty(
        '--adc-shadow',
        shadowInput && shadowInput.checked ? '0 10px 30px rgba(0, 0, 0, 0.25)' : 'none'
      );
    }
  }

  function syncLayout() {
    var layout = currentLayout();
    if (preview) {
      preview.setAttribute('data-layout', layout);
    }
    var centered = layout === 'center_modal';
    if (overlayRow) {
      overlayRow.hidden = !centered;
    }
    if (screenOverlay) {
      screenOverlay.hidden = !(centered && overlayInput && overlayInput.checked);
    }
  }

  function luminance(hex) {
    var full = normalizeHex(hex);
    var m = /^#([0-9a-f]{6})$/i.exec(full || '');
    if (!m) {
      return null;
    }
    var channels = [0, 2, 4].map(function (i) {
      var c = parseInt(m[1].substr(i, 2), 16) / 255;
      return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
  }

  function contrastRatio(bg, fg) {
    var l1 = luminance(bg);
    var l2 = luminance(fg);
    if (l1 === null || l2 === null) {
      return null;
    }
    var lighter = Math.max(l1, l2);
    var darker = Math.min(l1, l2);
    return (lighter + 0.05) / (darker + 0.05);
  }

  function updateWarnings() {
    each(root.querySelectorAll('.adc-contrast-warning'), function (warning) {
      var bgInput = colorInput(warning.getAttribute('data-bg-key') || '');
      var fgInput = colorInput(warning.getAttribute('data-fg-key') || '');
      if (!bgInput || !fgInput) {
        warning.hidden = true;
        return;
      }
      var ratio = contrastRatio(bgInput.value, fgInput.value);
      warning.hidden = !(ratio !== null && ratio < 4.5);
    });

    updateCompliance();
  }

  // ---- Accept/Reject prominence audit (mirrors ADC_Consent_Banner::prominence_audit) ----

  function normalizeHex(value) {
    var hex = String(value || '').trim().toLowerCase();
    var m = /^#([0-9a-f])([0-9a-f])([0-9a-f])$/.exec(hex);
    if (m) {
      return '#' + m[1] + m[1] + m[2] + m[2] + m[3] + m[3];
    }
    return /^#[0-9a-f]{6}$/.test(hex) ? hex : '';
  }

  function hexOf(key) {
    var input = colorInput(key);
    return input ? normalizeHex(input.value) : '';
  }

  function ratioText(ratio) {
    return (Math.round(ratio * 10) / 10).toFixed(1).replace('.', ',');
  }

  // Positional placeholders (%1$s, %2$s) filled the same way PHP fills them.
  function fill(template, args) {
    var text = String(template);
    (args || []).forEach(function (value, index) {
      text = text.split('%' + (index + 1) + '$s').join(String(value));
    });
    return text;
  }

  function auditPalette() {
    if (!compliance || !compliance.messages) {
      return [];
    }

    var bg = hexOf('bg');
    var acceptBg = hexOf('accept_bg');
    var acceptText = hexOf('accept_text');
    var rejectBg = hexOf('reject_bg');
    var rejectText = hexOf('reject_text');

    if (!bg || !acceptBg || !rejectBg) {
      return [];
    }

    var messages = compliance.messages;
    var findings = [];
    var acceptVis = contrastRatio(acceptBg, bg);
    var rejectVis = contrastRatio(rejectBg, bg);

    function add(code, args) {
      if (messages[code]) {
        findings.push(fill(messages[code], args || []));
      }
    }

    if (acceptBg === rejectBg && (!acceptText || !rejectText || acceptText === rejectText)) {
      add('indistinguishable');
      return findings;
    }

    if (rejectVis < compliance.blendContrast) {
      add('reject_blends', [rejectBg, bg, ratioText(rejectVis)]);
    } else if (rejectVis < compliance.uiContrast && acceptVis >= compliance.uiContrast) {
      add('reject_faint', [ratioText(acceptVis), ratioText(rejectVis), ratioText(compliance.uiContrast)]);
    } else if (rejectVis >= compliance.uiContrast && acceptVis / rejectVis >= compliance.prominenceGap) {
      add('prominence_gap', [ratioText(acceptVis), ratioText(rejectVis)]);
    }

    if (acceptText && rejectText) {
      var acceptLegible = contrastRatio(acceptText, acceptBg);
      var rejectLegible = contrastRatio(rejectText, rejectBg);
      if (rejectLegible < compliance.textContrast && rejectLegible < acceptLegible) {
        add('reject_illegible', [ratioText(rejectLegible), ratioText(acceptLegible)]);
      }
    }

    return findings;
  }

  function updateCompliance() {
    if (!compliancePanel || !complianceList) {
      return;
    }

    var findings = auditPalette();
    complianceList.textContent = '';

    findings.forEach(function (message) {
      var item = document.createElement('li');
      item.textContent = message;
      complianceList.appendChild(item);
    });

    compliancePanel.hidden = findings.length === 0;
  }

  // ---- Button order + Accept spacing ----

  function applyOrder() {
    if (!previewActions || !orderSelect || !orderSelect.selectedOptions || !orderSelect.selectedOptions.length) {
      return;
    }

    var keys = String(orderSelect.selectedOptions[0].getAttribute('data-order') || '').split(/\s+/).filter(Boolean);
    keys.forEach(function (key) {
      var button = previewActions.querySelector('[data-adc-order="' + key + '"]');
      if (button) {
        previewActions.appendChild(button);
      }
    });
  }

  function syncGap() {
    var value = gapInput ? Math.max(0, Math.min(48, parseInt(gapInput.value, 10) || 0)) : 16;
    if (preview) {
      preview.style.setProperty('--adc-accept-gap', value + 'px');
    }
    if (gapVal) {
      gapVal.textContent = value + 'px';
    }
  }

  function onColorEdited(input) {
    var key = colorKey(input);
    if (key && key !== 'accent') {
      setVar(key, input.value);
    }
    if (key === 'accent' && checkedRadio(presetRadios()) && checkedRadio(presetRadios()).value === 'brand') {
      // The "brand" preset derives its action colours from the accent.
      var link = colorInput('link');
      var acceptBg = colorInput('accept_bg');
      setPickerValue(link, input.value);
      setPickerValue(acceptBg, input.value);
      setVar('link', input.value);
      setVar('accept_bg', input.value);
    }
    markCustom();
    updateWarnings();
  }

  // Preset choice fills every colour.
  each(presetRadios(), function (input) {
    input.addEventListener('change', function () {
      if (input.checked && input.value !== 'custom') {
        applyPreset(input.value);
      } else if (input.checked && preview) {
        preview.setAttribute('data-preset', 'custom');
      }
    });
  });

  // Manual colour edits switch the preset to "custom".
  each(root.querySelectorAll('.adc-color-field'), function (input) {
    input.addEventListener('input', function () {
      onColorEdited(input);
    });
  });

  // wp-color-picker glue (the only jQuery in this file).
  function initPickers() {
    if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.wpColorPicker) {
      return;
    }
    window.jQuery('.adc-color-field').wpColorPicker({
      change: function (event, ui) {
        var input = event && event.target ? event.target : null;
        if (input) {
          onColorEdited(input);
        } else if (ui && ui.color) {
          updateWarnings();
        }
      },
      clear: function (event) {
        if (event && event.target) {
          onColorEdited(event.target);
        }
      }
    });
  }

  if (radiusInput) {
    radiusInput.addEventListener('input', function () {
      syncRadius(true);
    });
  }
  if (gapInput) {
    gapInput.addEventListener('input', syncGap);
  }
  if (orderSelect) {
    orderSelect.addEventListener('change', applyOrder);
  }
  if (shadowInput) {
    shadowInput.addEventListener('change', syncShadow);
  }
  if (overlayInput) {
    overlayInput.addEventListener('change', syncLayout);
  }
  each(layoutRadios(), function (input) {
    input.addEventListener('change', syncLayout);
  });
  if (resetButton) {
    resetButton.addEventListener('click', function () {
      applyPreset(activePreset);
    });
  }

  // Live text sync between the "Texte" fields and the preview.
  var textMap = [
    ['adc-banner-title', 'adc-banner-preview-title'],
    ['adc-banner-message', 'adc-banner-preview-message'],
    ['adc-banner-accept_label', 'adc-banner-preview-accept'],
    ['adc-banner-reject_label', 'adc-banner-preview-reject'],
    ['adc-banner-customize_label', 'adc-banner-preview-customize']
  ];
  textMap.forEach(function (pair) {
    var source = document.getElementById(pair[0]);
    var target = document.getElementById(pair[1]);
    if (source && target) {
      source.addEventListener('input', function () {
        target.textContent = source.value;
      });
    }
  });

  // Initial state.
  var initial = checkedRadio(presetRadios());
  if (initial && initial.value !== 'custom' && presets[initial.value]) {
    activePreset = initial.value;
  }
  syncRadius(false);
  syncGap();
  applyOrder();
  syncShadow();
  syncLayout();
  updateWarnings();
  initPickers();
})();
