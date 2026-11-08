/**
 * ADC Marketing — Catalog ▸ Feeduri: lista de feeduri și wizardul „Feed nou”.
 *
 * Vanilla JS, fără jQuery. Fiecare cerere merge la adc/v1/feeds cu nonce-ul
 * wp_rest în X-WP-Nonce. Textul venit de la server se scrie doar cu textContent.
 * Lista de destinații vine din tab (data-channels, din ADC_Feed_Channels); fallback-ul
 * de mai jos e folosit doar când atributul lipsește. Helperii de la început nu ating
 * DOM-ul și sunt exportați pentru Node (tests/admin-feeds-wizard-test.js).
 */
(function () {
    'use strict';

    var FEED_STRINGS_DEFAULT = {
        "feed_ui_001": "Recondiționat",
        "feed_ui_002": "Destinație",
        "feed_ui_003": "Setări destinație",
        "feed_ui_004": "Verificare și publicare",
        "feed_ui_005": "Reușită",
        "feed_ui_006": "Eșuată",
        "feed_ui_007": "În curs",
        "feed_ui_008": "Finalizată",
        "feed_ui_009": "Nu s-a putut contacta serverul. Verifică conexiunea și încearcă din nou.",
        "feed_ui_010": "A apărut o eroare. Încearcă din nou.",
        "feed_ui_011": "Sesiunea a expirat. Reîncarcă pagina și încearcă din nou.",
        "feed_ui_012": "Câmp nativ",
        "feed_ui_013": "Câmp personalizat (meta)",
        "feed_ui_014": "Valoare fixă",
        "feed_ui_015": "Șablon",
        "feed_ui_016": "Taie spațiile",
        "feed_ui_017": "Elimină HTML",
        "feed_ui_018": "Trunchiază",
        "feed_ui_019": "Adaugă prefix",
        "feed_ui_020": "Adaugă sufix",
        "feed_ui_021": "Valoare implicită dacă e gol",
        "feed_ui_022": "Înlocuiește",
        "feed_ui_023": "Format preț",
        "feed_ui_024": "” nu este validă.",
        "feed_ui_025": "Alege o destinație disponibilă.",
        "feed_ui_026": "Alege un format disponibil pentru destinația aleasă.",
        "feed_ui_027": "Numele feedului trebuie să aibă între 1 și 120 de caractere.",
        "feed_ui_028": "Alege ce produse includi în feed.",
        "feed_ui_029": "Adaugă cel puțin o categorie (slug-uri separate prin virgulă).",
        "feed_ui_030": "Poți adăuga cel mult 50 de categorii.",
        "feed_ui_031": "Categoriile trebuie scrise ca slug-uri: litere mici, cifre și cratime.",
        "feed_ui_032": "Alege condiția implicită a produselor.",
        "feed_ui_033": "Codul țării de livrare trebuie să aibă două litere (ex. RO).",
        "feed_ui_034": "Prețul de livrare trebuie scris cu punct, ex. 15.00.",
        "feed_ui_035": "Serviciul de livrare trebuie să aibă între 1 și 100 de caractere.",
        "feed_ui_036": "Marca implicită trebuie să aibă între 1 și 100 de caractere.",
        "feed_ui_037": "Poți adăuga cel mult 5 etichete interne.",
        "feed_ui_038": "Fiecare etichetă internă are cel mult 110 caractere.",
        "feed_ui_039": "Categoria implicită poate avea cel mult 750 de caractere.",
        "feed_ui_040": "Fiecare categorie mapată poate avea cel mult 750 de caractere.",
        "feed_ui_041": "Necunoscută",
        "feed_ui_042": "și încă ",
        "feed_ui_043": "Nicio rulare încă.",
        "feed_ui_044": " Adresa publică: ",
        "feed_ui_045": "Nu ai creat încă niciun feed.",
        "feed_ui_046": "Apasă „Feed nou” ca să creezi primul feed de produse.",
        "feed_ui_047": "Dezactivează",
        "feed_ui_048": "Activează",
        "feed_ui_049": "Copiază",
        "feed_ui_050": "Copiază adresa feedului %s",
        "feed_ui_051": "Adresa feedului a fost copiată.",
        "feed_ui_052": "Adresa nu a putut fi copiată. Selecteaz-o manual.",
        "feed_ui_053": "Editează",
        "feed_ui_054": "Regenerează",
        "feed_ui_055": "Regenerarea a fost programată. Feedul se actualizează în câteva minute.",
        "feed_ui_056": "Starea feedului a fost actualizată.",
        "feed_ui_057": "Șterge",
        "feed_ui_058": "Ștergi feedul „",
        "feed_ui_059": "”? Adresa publică nu va mai funcționa.",
        "feed_ui_060": "Feedul a fost șters.",
        "feed_ui_061": "Editează feedul",
        "feed_ui_062": "Înapoi",
        "feed_ui_063": "Renunță",
        "feed_ui_064": "Continuă",
        "feed_ui_065": "Se salvează…",
        "feed_ui_066": "Salvează modificările",
        "feed_ui_067": "Publică feedul",
        "feed_ui_068": "Format fișier",
        "feed_ui_069": "Categorii (slug-uri separate prin virgulă)",
        "feed_ui_070": "Include doar produsele în stoc",
        "feed_ui_071": "Țara de livrare",
        "feed_ui_072": "Preț livrare",
        "feed_ui_073": "Folosește punctul ca separator, ex. 15.00.",
        "feed_ui_074": "Condiție implicită",
        "feed_ui_075": "Marcă implicită",
        "feed_ui_076": "Marca folosită când produsul nu are una.",
        "feed_ui_077": "Separate prin virgulă; cel mult 5 etichete, fiecare până la 110 caractere.",
        "feed_ui_078": "Folosește maparea categoriilor din Setări canal.",
        "feed_ui_079": "Șterge transformarea %s",
        "feed_ui_080": "Operație de transformare",
        "feed_ui_081": "Adaugă transformare",
        "feed_ui_082": "Tip de sursă",
        "feed_ui_083": "Sursă",
        "feed_ui_084": "Transformări",
        "feed_ui_085": "Toți parametrii disponibili sunt deja în tabel.",
        "feed_ui_086": "Adaugă parametru",
        "feed_ui_087": "Nu există produse de previzualizat.",
        "feed_ui_088": "Se încarcă parametrii…",
        "feed_ui_089": "Fiecare rând spune ce câmp al feedului completezi și de unde citește valoarea. Parametrii obligatorii nu pot fi șterși.",
        "feed_ui_090": "Se previzualizează…",
        "feed_ui_091": "Previzualizează",
        "feed_ui_092": "Setări canal",
        "feed_ui_093": "Categorie implicită",
        "feed_ui_094": "Folosită pentru produsele fără categorie mapată mai jos.",
        "feed_ui_095": "Nu există categorii de produse în WooCommerce; folosește categoria implicită.",
        "feed_ui_096": " · doar produse în stoc",
        "feed_ui_097": " · validare strictă",
        "feed_ui_098": "Feedul se generează în fundal. Adresa publică funcționează după prima generare reușită.",
        "feed_ui_100": "Format personalizat",
        "feed_ui_101": "Nou",
        "feed_ui_102": "Second-hand",
        "feed_ui_103": "Produse",
        "feed_ui_104": "Activ",
        "feed_ui_105": "Dezactivat",
        "feed_ui_106": "Diagnostic necunoscut (%s)",
        "feed_ui_107": "Erori",
        "feed_ui_108": "Avertismente",
        "feed_ui_109": " caz",
        "feed_ui_110": " cazuri",
        "feed_ui_111": "Ultima rulare: ",
        "feed_ui_112": " din ",
        "feed_ui_113": " produse exportate",
        "feed_ui_114": "Feedul a fost actualizat.",
        "feed_ui_115": "Feedul a fost creat.",
        "feed_ui_116": "Catalog",
        "feed_ui_117": "Feed nou",
        "feed_ui_118": " · Actualizat: ",
        "feed_ui_119": "Transformarea pentru „",
        "feed_ui_120": "Parametrul „",
        "feed_ui_121": "” este obligatoriu.",
        "feed_ui_122": "Tipul sursei pentru „",
        "feed_ui_123": "” nu este valid.",
        "feed_ui_124": "Alege sursa pentru „",
        "feed_ui_125": "Produse incluse",
        "feed_ui_126": "Nume feed",
        "feed_ui_127": "Toate produsele publicate",
        "feed_ui_128": "Doar anumite categorii",
        "feed_ui_129": "incaltaminte, accesorii",
        "feed_ui_130": "Omite produsele invalide",
        "feed_ui_131": "Serviciu de livrare",
        "feed_ui_132": "Etichete interne",
        "feed_ui_133": "vara, promo",
        "feed_ui_134": "Export CSV compatibil cu Excel",
        "feed_ui_135": "Valoarea sursei",
        "feed_ui_136": "— alege —",
        "feed_ui_137": "Argument",
        "feed_ui_138": "Obligatoriu",
        "feed_ui_139": "Parametru",
        "feed_ui_140": "Activ",
        "feed_ui_141": "Parametru nou",
        "feed_ui_142": "Parametri feed",
        "feed_ui_143": "Nume",
        "feed_ui_144": "Format",
        "feed_ui_145": "Livrare",
        "feed_ui_146": "CSV compatibil Excel",
        "feed_ui_147": "Da",
        "feed_ui_148": "Nu",
        "feed_ui_149": "Parametri",
        "feed_ui_150": " activi",
        "feed_ui_151": "Categorii mapate",
        "feed_ui_152": "Categorii: ",
        "feed_ui_153": " · omit produsele invalide",
        "feed_ui_154": "Pentru categoria „%s”",
        "feed_ui_155": "Motorul de feeduri nu este disponibil.",
        "feed_ui_156": "Atribut WooCommerce",
        "feed_ui_157": "Taxonomie",
        "feed_ui_158": "Maparea categoriilor",
        "feed_ui_159": "Majuscule",
        "feed_ui_160": "Minuscule",
        "feed_ui_161": "Mapare valori",
        "feed_ui_162": "Categorii",
        "feed_ui_163": "Ștergi feedul „%s”? Adresa publică nu va mai funcționa.",
        "feed_ui_164": "Selectare inteligentă (statistici)",
        "feed_ui_165": "Statisticile per produs sunt dezactivate. Selectarea inteligentă nu va afișa produse până la activarea statisticilor.",
        "feed_ui_166": "Deschide setările statisticilor",
        "feed_ui_167": "Reîmprospătare automată",
        "feed_ui_168": "Alege intervalul de reîmprospătare.",
        "feed_ui_169": "Regula inteligentă nu este validă.",
        "feed_ui_170": "Manual",
        "feed_ui_171": "La fiecare oră",
        "feed_ui_172": "Zilnic",
        "feed_ui_173": "Săptămânal",
        "feed_ui_174": "Achiziții",
        "feed_ui_175": "Vizualizări",
        "feed_ui_176": "Adăugări în coș",
        "feed_ui_177": "Începuturi de finalizare a comenzii",
        "feed_ui_178": "Regulă de selectare",
        "feed_ui_179": "Top produse",
        "feed_ui_180": "Prag minim al valorii",
        "feed_ui_181": "Conversie scăzută (maximum 2%)",
        "feed_ui_182": "Metrică",
        "feed_ui_183": "Perioadă",
        "feed_ui_184": "zile",
        "feed_ui_185": "Număr maxim de produse (1–500)",
        "feed_ui_186": "Valoare minimă",
        "feed_ui_187": "Cele mai vândute · 30 zile",
        "feed_ui_188": "Cele mai vizualizate · 14 zile",
        "feed_ui_189": "Conversie scăzută · 30 zile",
        "feed_ui_190": "Adăugate în coș · 30 zile",
        "feed_ui_191": "„Conversie scăzută” selectează produsele cu cel mult 2% achiziții raportate la vizualizări. Celulele de achiziții suprimate de prag sunt tratate ca zero; apartenența la feed poate dezvălui indirect această limită.",
        "feed_ui_192": "Reguli inteligente",
        "feed_ui_193": "Valoare statistică",
        "feed_ui_194": "Opțional: limitează produsele selectate la aceste categorii.",
        "feed_ui_195": "Feedul a fost salvat, dar reîmprospătarea recurentă nu a putut fi programată. Verifică Action Scheduler.",
        "feed_ui_196": "Procent maxim de conversie (0–100)",
        "feed_ui_197": "Smart: %s · %s · %s zile · reîmprospătare %s",
        "feed_ui_198": "categorii: %s",
        "feed_ui_099": "în curând"
    };
    var FEED_STRINGS = Object.assign({}, FEED_STRINGS_DEFAULT,
        typeof window !== 'undefined' && window.ADC_FEEDS_STRINGS ? window.ADC_FEEDS_STRINGS : {});

    function t(key) {
        return Object.prototype.hasOwnProperty.call(FEED_STRINGS, key) ? FEED_STRINGS[key] : key;
    }

    /** Fallback destinations, used only when data-channels is missing. Formats mirror the registry contract. */
    var DEFAULT_CHANNELS = [
        { id: 'google', label: 'Google Shopping', available: true, formats: ['xml'] },
        { id: 'meta', label: 'Meta', available: true, formats: ['csv', 'xml'] },
        { id: 'tiktok', label: 'TikTok', available: true, formats: ['csv', 'xml'] },
        { id: 'olx', label: 'OLX', available: false, formats: [] },
        { id: 'custom', label: t('feed_ui_100'), available: false, formats: [] }
    ];

    var FORMAT_LABELS = { xml: 'XML (RSS)', csv: 'CSV', tsv: 'TSV' };

    var CONDITIONS = [
        { id: 'new', label: t('feed_ui_101') },
        { id: 'refurbished', label: t('feed_ui_001') },
        { id: 'used', label: t('feed_ui_102') }
    ];

    var STEPS = [t('feed_ui_002'), t('feed_ui_103'), t('feed_ui_003'), t('feed_ui_004')];

    var COUNTRY_RE = /^[A-Z]{2}$/;
    var PRICE_RE = /^(0|[1-9][0-9]{0,5})(\.[0-9]{1,2})?$/;
    var SLUG_RE = /^[a-z0-9-]{1,100}$/;

    /** Limits mirrored from ADC_Feed_Rest (META_LABELS_MAX, LABEL_MAX, BRAND_MAX). */
    var META_LABELS_MAX = 5;
    var LABEL_MAX = 110;
    var BRAND_MAX = 100;

    /** Messages shown per list (errors, warnings) in a feed's last run; the rest is counted. */
    var MAX_MESSAGES = 10;

    /** Diagnostic codes: lowercase identifiers, the same shape ADC_Feed_Rest accepts. */
    var CODE_RE = /^[a-z][a-z0-9_]{0,79}$/;

    /** Run statuses from ADC_Feed_Store::last_run(), with their label and badge tone. */
    var RUN_STATUS = {
        success: { label: t('feed_ui_005'), tone: 'ok' },
        ok: { label: t('feed_ui_005'), tone: 'ok' },
        failed: { label: t('feed_ui_006'), tone: 'error' },
        error: { label: t('feed_ui_006'), tone: 'error' },
        running: { label: t('feed_ui_007'), tone: 'neutral' },
        complete: { label: t('feed_ui_008'), tone: 'ok' }
    };

    var MSG_NETWORK = t('feed_ui_009');
    var MSG_GENERIC = t('feed_ui_010');
    var MSG_EXPIRED = t('feed_ui_011');
    var MSG_UNAVAILABLE = t('feed_ui_155');

    /** Source types of the parameter table (same list as ADC_Feed_Rest::SOURCE_TYPES). */
    var SOURCE_TYPES = [
        { id: 'native', label: t('feed_ui_012') },
        { id: 'attribute', label: t('feed_ui_156') },
        { id: 'taxonomy', label: t('feed_ui_157') },
        { id: 'meta', label: t('feed_ui_013') },
        { id: 'constant', label: t('feed_ui_014') },
        { id: 'template', label: t('feed_ui_015') },
        { id: 'category_map', label: t('feed_ui_158') }
    ];

    /** Transform operations (same list as ADC_Feed_Rest::TRANSFORM_OPS). */
    var TRANSFORM_OPS = [
        { id: 'trim', label: t('feed_ui_016') },
        { id: 'strip_html', label: t('feed_ui_017') },
        { id: 'truncate', label: t('feed_ui_018') },
        { id: 'uppercase', label: t('feed_ui_159') },
        { id: 'lowercase', label: t('feed_ui_160') },
        { id: 'prefix', label: t('feed_ui_019') },
        { id: 'suffix', label: t('feed_ui_020') },
        { id: 'default_if_empty', label: t('feed_ui_021') },
        { id: 'replace', label: t('feed_ui_022') },
        { id: 'map', label: t('feed_ui_161') },
        { id: 'price_format', label: t('feed_ui_023') }
    ];

    // -----------------------------------------------------------------------
    // Channels and wizard state.
    // -----------------------------------------------------------------------

    function channelById(channels, id) {
        var list = channels || DEFAULT_CHANNELS;
        return list.filter(function (c) { return c.id === id; })[0];
    }

    /** First format a channel allows, or '' when the channel is unknown. */
    function defaultFormat(channels, id) {
        var channel = channelById(channels, id);
        return channel && channel.formats.length ? channel.formats[0] : '';
    }

    /** Wizard state before the first answer. */
    function emptyWizard(channels) {
        return {
            step: 1,
            channel: 'google',
            format: defaultFormat(channels, 'google') || 'xml',
            name: '',
            scope: 'all',
            categories: '',
            refresh: 'manual',
            smartMetric: 'purchases',
            smartMode: 'top_n',
            smartDays: 30,
            smartLimit: 50,
            smartThreshold: 10,
            smartConversion: 2,
            inStock: false,
            country: 'RO',
            price: '0.00',
            service: 'Standard',
            condition: 'new',
            brand: '',
            labels: '',
            csvExcelSafe: true,
            excludeInvalid: true,
            defaultCategory: '',
            categoryMap: {},
            params: null,
            fields: []
        };
    }

    /** A clean new-feed draft; opening Feed nou must never reuse an edit or prior draft. */
    function resetWizardDraft(channels) {
        return emptyWizard(channels);
    }

    /** Split a comma-separated list of category slugs: trimmed, lowercase, unique. */
    function parseCategories(text) {
        var seen = {};
        var out = [];
        String(text || '').split(',').forEach(function (raw) {
            var slug = raw.trim().toLowerCase();
            if (slug && !seen[slug]) {
                seen[slug] = true;
                out.push(slug);
            }
        });
        return out;
    }

    /** Meta internal labels from the comma-separated field: trimmed, lowercase, unique, non-empty. */
    function parseInternalLabels(text) {
        var seen = {};
        var out = [];
        String(text || '').split(',').forEach(function (raw) {
            var label = raw.trim().toLowerCase();
            if (label && !seen[label]) {
                seen[label] = true;
                out.push(label);
            }
        });
        return out;
    }

    /** Source lists from data-sources: {native, attribute, taxonomy} as [{value, label}]. */
    function parseSourceLists(raw) {
        var out = { native: [], attribute: [], taxonomy: [] };
        try {
            var parsed = JSON.parse(raw || '{}');
            if (!parsed || typeof parsed !== 'object') {
                return out;
            }
            Object.keys(out).forEach(function (kind) {
                out[kind] = Array.isArray(parsed[kind]) ? parsed[kind].filter(function (c) {
                    return c && typeof c.value === 'string' && c.value !== '';
                }).map(function (c) {
                    return { value: c.value, label: typeof c.label === 'string' && c.label !== '' ? c.label : c.value };
                }) : [];
            });
        } catch (e) {
            return out;
        }
        return out;
    }

    /** Prefer the REST source registry; keep server-rendered sources only as a compatibility fallback. */
    function mergeSourceLists(fallback, response) {
        var merged = {
            native: (fallback && fallback.native) || [],
            attribute: (fallback && fallback.attribute) || [],
            taxonomy: (fallback && fallback.taxonomy) || []
        };
        var sourceData = response && response.sources;
        if (sourceData && Object.prototype.hasOwnProperty.call(sourceData, 'native') && Array.isArray(sourceData.native)) {
            merged.native = parseSourceLists(JSON.stringify({ native: sourceData.native })).native;
        }
        return merged;
    }

    /** Value choices for a source type, or null when the value is free text. */
    function sourceChoices(type, sources) {
        var lists = sources || {};
        if ((type === 'native' || type === 'attribute' || type === 'taxonomy') && lists[type] && lists[type].length) {
            return lists[type];
        }
        return null;
    }

    function cloneSource(source) {
        return { type: String((source && source.type) || ''), value: String((source && source.value) || '') };
    }

    /** New parameter row for a registry field: its default source, or a native source with no value yet. */
    function newRow(field) {
        return {
            field: field.key,
            source: field.default_source ? cloneSource(field.default_source) : { type: 'native', value: '' },
            transforms: [],
            enabled: true
        };
    }

    /** Rows for a new feed: the server defaults, plus each required field without a row. Required rows stay enabled. */
    function initialParams(defaults, fields) {
        var list = fields || [];
        var rows = (defaults || []).map(function (r) {
            return {
                field: String(r.field),
                source: cloneSource(r.source),
                transforms: (r.transforms || []).map(function (t) { return { op: String(t.op), arg: String(t.arg || '') }; }),
                enabled: r.enabled !== false
            };
        });
        var have = rows.map(function (r) { return r.field; });
        list.forEach(function (f) {
            if (f.required && have.indexOf(f.key) === -1) {
                rows.push(newRow(f));
            }
        });
        rows.forEach(function (r) {
            list.forEach(function (f) {
                if (f.key === r.field && f.required) {
                    r.enabled = true;
                }
            });
        });
        return rows;
    }

    /** Keep the Smart rule's default label in the ordinary editable parameter mapping table. */
    function ensureSmartLabelRow(state) {
        if (!state || !Array.isArray(state.params)) {
            return;
        }
        var field = state.channel === 'meta' ? 'internal_label' : 'custom_label_0';
        if (state.scope !== 'smart') {
            if (state.smartAutoLabel) {
                state.params = state.params.filter(function (row) {
                    return !(row.field === field && row.source && row.source.type === 'constant' && row.source.value === state.smartAutoLabel);
                });
                state.smartAutoLabel = '';
            }
            return;
        }
        if (!(state.fields || []).some(function (entry) { return entry.key === field; })) {
            return;
        }
        var rule = normalize(state).smartRule;
        var metric = rule.mode === 'low_conversion' ? 'views_low_conversion' : rule.metric;
        var label = (metric + '_' + rule.period_days + 'd' + (rule.mode === 'min_value' ? '_min' + rule.threshold : '')).toLowerCase().replace(/[^a-z0-9_]/g, '').slice(0, 100);
        var row = state.params.filter(function (entry) { return entry.field === field; })[0];
        if (row) {
            if (row.source.type === 'constant' && state.smartAutoLabel && row.source.value === state.smartAutoLabel) {
                row.source.value = label;
                state.smartAutoLabel = label;
            } else if (row.source.type === 'constant' && row.source.value === label) {
                state.smartAutoLabel = label;
            }
            return;
        }
        state.params.push({ field: field, source: { type: 'constant', value: label }, transforms: [], enabled: true });
        state.smartAutoLabel = label;
    }

    /** Options of a field dropdown: fields used by another row are disabled (currentField is this row's own field). */
    function fieldOptions(fields, rows, currentField) {
        return (fields || []).map(function (f) {
            var used = (rows || []).some(function (r) { return r.field === f.key && r.field !== currentField; });
            return { key: f.key, label: f.label, help: f.help || '', required: !!f.required, disabled: used };
        });
    }

    function fieldLabel(key, fields) {
        var found = (fields || []).filter(function (f) { return f.key === key; })[0];
        return found ? found.label : String(key);
    }

    /** Errors (RO) for the parameter table: required fields present and enabled, sources set, known transforms. */
    function validateParams(rows, fields) {
        var errors = [];
        var list = rows || [];
        (fields || []).forEach(function (f) {
            if (f.required && !list.some(function (r) { return r.field === f.key && r.enabled; })) {
                errors.push(t('feed_ui_120') + f.label + t('feed_ui_121'));
            }
        });
        list.forEach(function (r) {
            var label = fieldLabel(r.field, fields);
            if (!SOURCE_TYPES.some(function (t) { return t.id === r.source.type; })) {
                errors.push(t('feed_ui_122') + label + t('feed_ui_123'));
            } else if (r.source.type !== 'category_map' && String(r.source.value || '').trim() === '') {
                errors.push(t('feed_ui_124') + label + '”.');
            }
            (r.transforms || []).forEach(function (transform) {
                if (!TRANSFORM_OPS.some(function (op) { return op.id === transform.op; })) {
                    errors.push(t('feed_ui_119') + label + t('feed_ui_024'));
                }
            });
        });
        return errors;
    }

    /** Request body of the parameter table: trimmed values, rows without a field dropped. */
    function normalizeParams(rows) {
        return (rows || []).filter(function (r) { return r.field; }).map(function (r) {
            return {
                field: r.field,
                source: { type: r.source.type, value: String(r.source.value || '').trim() },
                transforms: (r.transforms || []).map(function (t) { return { op: t.op, arg: String(t.arg || '').trim() }; }),
                enabled: r.enabled !== false
            };
        });
    }

    function transformLabel(t) {
        var op = TRANSFORM_OPS.filter(function (o) { return o.id === t.op; })[0];
        return (op ? op.label : String(t.op)) + (t.arg ? ' — ' + t.arg : '');
    }

    /** Category map without empty values: { termId: platform category }, both trimmed. */
    function cleanCategoryMap(map) {
        var out = {};
        Object.keys(map || {}).forEach(function (key) {
            var value = map[key] === null || map[key] === undefined ? '' : String(map[key]).trim();
            if (value !== '') {
                out[key] = value;
            }
        });
        return out;
    }

    /** Trimmed, normalized copy of the wizard answers (what gets validated and sent). */
    function normalize(state) {
        var format = String(state.format || '');
        return {
            name: String(state.name || '').trim(),
            channel: state.channel,
            format: format,
            scope: state.scope,
            categories: state.scope === 'filtered' || state.scope === 'smart' ? parseCategories(state.categories) : [],
            refresh: String(state.refresh || 'manual'),
            smartRule: {
                metric: String(state.smartMetric || 'purchases'),
                mode: String(state.smartMode || 'top_n'),
                period_days: Number(state.smartDays === undefined || state.smartDays === '' ? 30 : state.smartDays),
                limit: Number(state.smartLimit === undefined || state.smartLimit === '' ? 50 : state.smartLimit),
                threshold: Number(state.smartThreshold === undefined || state.smartThreshold === '' ? 10 : state.smartThreshold),
                conversion_percent: Number(state.smartConversion === undefined || state.smartConversion === '' ? 2 : state.smartConversion)
            },
            inStock: !!state.inStock,
            country: String(state.country || '').trim().toUpperCase(),
            price: String(state.price || '').trim(),
            service: String(state.service || '').trim(),
            condition: state.condition,
            brand: String(state.brand || '').trim(),
            labels: state.channel === 'meta' ? parseInternalLabels(state.labels) : [],
            csvExcelSafe: format === 'csv' && !!state.csvExcelSafe,
            excludeInvalid: state.excludeInvalid !== false,
            defaultCategory: String(state.defaultCategory || '').trim(),
            categoryMap: cleanCategoryMap(state.categoryMap)
        };
    }

    /** Render the product row in the review step for each selection scope. */
    function productSummary(n, translate) {
        var text = typeof translate === 'function' ? translate : t;
        var products;
        if (n.scope === 'smart') {
            var rule = n.smartRule || {};
            var metricKeys = { purchases: 'feed_ui_174', views: 'feed_ui_175', add_to_cart: 'feed_ui_176', begin_checkout: 'feed_ui_177' };
            var modeKeys = { top_n: 'feed_ui_179', min_value: 'feed_ui_180', low_conversion: 'feed_ui_181' };
            var refreshKeys = { manual: 'feed_ui_170', hourly: 'feed_ui_171', daily: 'feed_ui_172', weekly: 'feed_ui_173' };
            products = text('feed_ui_197')
                .replace('%s', text(metricKeys[rule.metric] || rule.metric || ''))
                .replace('%s', text(modeKeys[rule.mode] || rule.mode || ''))
                .replace('%s', String(rule.period_days || 30))
                .replace('%s', text(refreshKeys[n.refresh] || n.refresh || ''));
            if (n.categories && n.categories.length) {
                products += ' · ' + text('feed_ui_198').replace('%s', n.categories.join(', '));
            }
        } else if (n.scope === 'filtered') {
            products = text('feed_ui_152') + (n.categories || []).join(', ');
        } else {
            products = text('feed_ui_127');
        }
        if (n.inStock) { products += text('feed_ui_096'); }
        products += n.excludeInvalid ? text('feed_ui_153') : text('feed_ui_097');
        return products;
    }

    /** Keep one copy of each identical validation message. */
    function uniqueMessages(messages) {
        return Array.from(new Set(messages));
    }

    /** Errors (RO) for one wizard step. An empty array means the step is valid. */
    function validateStep(state, step, channels) {
        var n = normalize(state);
        var errors = [];
        var channel = channelById(channels, n.channel);

        if (step === 1) {
            if (!channel || !channel.available) {
                errors.push(t('feed_ui_025'));
            } else if (channel.formats.indexOf(n.format) === -1) {
                errors.push(t('feed_ui_026'));
            }
            if (n.name.length < 1 || n.name.length > 120) {
                errors.push(t('feed_ui_027'));
            }
        }

        if (step === 2) {
            if (n.scope !== 'all' && n.scope !== 'filtered' && n.scope !== 'smart') {
                errors.push(t('feed_ui_028'));
            } else if (n.scope === 'filtered' && n.categories.length === 0) {
                errors.push(t('feed_ui_029'));
            }
            if (n.scope === 'filtered' || (n.scope === 'smart' && n.categories.length > 0)) {
                if (n.categories.length > 50) {
                    errors.push(t('feed_ui_030'));
                } else if (n.categories.some(function (s) { return !SLUG_RE.test(s); })) {
                    errors.push(t('feed_ui_031'));
                }
            }
            if (!['manual', 'hourly', 'daily', 'weekly'].includes(n.refresh)) {
                errors.push(t('feed_ui_168'));
            }
            if (n.scope === 'smart') {
                if (!['views', 'add_to_cart', 'begin_checkout', 'purchases'].includes(n.smartRule.metric)
                    || !['top_n', 'min_value', 'low_conversion'].includes(n.smartRule.mode)
                    || ![7, 14, 30, 90].includes(n.smartRule.period_days)
                    || !Number.isInteger(n.smartRule.limit) || n.smartRule.limit < 1 || n.smartRule.limit > 500
                    || (n.smartRule.mode === 'low_conversion' && n.smartRule.metric !== 'views')
                    || (n.smartRule.mode === 'min_value' && (!Number.isInteger(n.smartRule.threshold) || n.smartRule.threshold < 1 || n.smartRule.threshold > 1000000))
                    || (n.smartRule.mode === 'low_conversion' && (!Number.isInteger(n.smartRule.conversion_percent) || n.smartRule.conversion_percent < 0 || n.smartRule.conversion_percent > 100))) {
                    errors.push(t('feed_ui_169'));
                }
            }
        }

        if (step === 3) {
            if (!CONDITIONS.some(function (c) { return c.id === n.condition; })) {
                errors.push(t('feed_ui_032'));
            }
            if (n.channel === 'google') {
                if (!COUNTRY_RE.test(n.country)) {
                    errors.push(t('feed_ui_033'));
                }
                if (!PRICE_RE.test(n.price)) {
                    errors.push(t('feed_ui_034'));
                }
                if (n.service.length < 1 || n.service.length > 100) {
                    errors.push(t('feed_ui_035'));
                }
            }
            if (n.channel === 'meta' || n.channel === 'tiktok') {
                if (n.brand.length < 1 || n.brand.length > BRAND_MAX) {
                    errors.push(t('feed_ui_036'));
                }
            }
            if (n.channel === 'meta') {
                if (n.labels.length > META_LABELS_MAX) {
                    errors.push(t('feed_ui_037'));
                }
                if (n.labels.some(function (label) { return label.length > LABEL_MAX; })) {
                    errors.push(t('feed_ui_038'));
                }
            }
        }

        if (step === 3 && Array.isArray(state.params)) {
            errors = errors.concat(validateParams(state.params, state.fields || []));
        }

        if (step === 3 && (n.channel === 'google' || n.channel === 'meta' || n.channel === 'tiktok')) {
            if (n.defaultCategory.length > 750) {
                errors.push(t('feed_ui_039'));
            }
            if (Object.keys(n.categoryMap).some(function (key) { return n.categoryMap[key].length > 750; })) {
                errors.push(t('feed_ui_040'));
            }
        }

        return uniqueMessages(errors);
    }

    /** Errors for all steps, in order. Used before publishing. */
    function validateAll(state, channels) {
        return Array.from(new Set([1, 2, 3].reduce(function (acc, step) {
            return acc.concat(validateStep(state, step, channels));
        }, [])));
    }

    /**
     * Request body for POST /feeds, matching ADC_Feed_Rest::validate_create(): the config
     * carries exactly the block of the chosen channel.
     */
    function wizardFromFeed(feed, channels) {
        var config = feed && feed.config && typeof feed.config === 'object' ? feed.config : {};
        var block = config[feed.channel] && typeof config[feed.channel] === 'object' ? config[feed.channel] : {};
        return Object.assign(emptyWizard(channels), {
            channel: String(feed.channel || 'google'),
            format: String(feed.format || defaultFormat(channels, feed.channel) || 'xml'),
            name: String(feed.name || ''),
            scope: config.scope === 'filtered' || config.scope === 'smart' ? config.scope : 'all',
            categories: Array.isArray(config.categories) ? config.categories.join(', ') : '',
            refresh: String(config.refresh || 'manual'),
            smartMetric: String(config.smart_rule && config.smart_rule.metric || 'purchases'),
            smartMode: String(config.smart_rule && config.smart_rule.mode || 'top_n'),
            smartDays: Number(config.smart_rule && config.smart_rule.period_days || 30),
            smartLimit: Number(config.smart_rule && config.smart_rule.limit || 50),
            smartThreshold: Number(config.smart_rule && config.smart_rule.threshold || 10),
            smartConversion: Number(config.smart_rule && config.smart_rule.conversion_percent !== undefined ? config.smart_rule.conversion_percent : 2),
            inStock: !!config.in_stock_only,
            csvExcelSafe: !!config.csv_excel_safe,
            excludeInvalid: config.exclude_invalid !== false,
            condition: String(block.condition || 'new'),
            country: String(block.shipping_country || 'RO'),
            price: String(block.shipping_price || '0.00'),
            service: String(block.shipping_service || 'Standard'),
            brand: String(block.brand || ''),
            labels: Array.isArray(block.internal_labels) ? block.internal_labels.join(', ') : '',
            defaultCategory: String(block.default_category || ''),
            categoryMap: block.category_map && typeof block.category_map === 'object' ? block.category_map : {},
            params: null,
            fields: []
        });
    }

    /** Release loading state before drawing edit controls, which derive disabled state from it. */
    function completeEditLoad(data, releaseBusy, startEdit) {
        if (!data || !data.feed) {
            throw new Error(MSG_GENERIC);
        }
        releaseBusy();
        startEdit(data.feed);
    }

    function buildPatchPayload(state, channels) {
        var payload = buildPayload(state, channels);
        delete payload.channel;
        delete payload.type;
        delete payload.format;
        return payload;
    }

    function buildPayload(state, channels) {
        var n = normalize(state);
        var config = {
            scope: n.scope,
            categories: n.categories,
            in_stock_only: n.inStock,
            csv_excel_safe: n.csvExcelSafe,
            exclude_invalid: n.excludeInvalid,
            refresh: n.refresh
        };
        if (n.scope === 'smart') {
            config.smart_rule = n.smartRule.mode === 'min_value'
                ? { metric: n.smartRule.metric, mode: n.smartRule.mode, period_days: n.smartRule.period_days, limit: n.smartRule.limit, threshold: n.smartRule.threshold }
                : n.smartRule.mode === 'low_conversion'
                    ? { metric: n.smartRule.metric, mode: n.smartRule.mode, period_days: n.smartRule.period_days, limit: n.smartRule.limit, conversion_percent: n.smartRule.conversion_percent }
                    : { metric: n.smartRule.metric, mode: n.smartRule.mode, period_days: n.smartRule.period_days, limit: n.smartRule.limit };
        }
        var categories = { default_category: n.defaultCategory, category_map: n.categoryMap };
        if (n.channel === 'google') {
            config.google = Object.assign({
                shipping_country: n.country,
                shipping_price: n.price,
                shipping_service: n.service,
                condition: n.condition
            }, categories);
        } else if (n.channel === 'meta') {
            config.meta = Object.assign({ condition: n.condition, brand: n.brand, internal_labels: n.labels }, categories);
        } else if (n.channel === 'tiktok') {
            config.tiktok = Object.assign({ condition: n.condition, brand: n.brand }, categories);
        }

        var payload = {
            name: n.name,
            channel: n.channel,
            type: 'standard',
            format: n.format,
            config: config
        };
        if (Array.isArray(state.params)) {
            ensureSmartLabelRow(state);
            payload.params = normalizeParams(state.params);
        }
        return payload;
    }

    // -----------------------------------------------------------------------
    // REST client and labels.
    // -----------------------------------------------------------------------

    /** Message shown for a failed REST call. Server messages are our own RO strings. */
    function messageFor(status, data) {
        if (status === 0) {
            return MSG_NETWORK;
        }
        if (data && typeof data.message === 'string' && data.message !== '') {
            return data.message;
        }
        if (status === 403) {
            return MSG_EXPIRED;
        }
        if (status === 503) {
            return MSG_UNAVAILABLE;
        }
        return MSG_GENERIC;
    }

    /**
     * JSON call to the feeds REST API. Rejects with an Error whose message is
     * safe to display and whose `status` is the HTTP status (0 = network).
     */
    function apiRequest(fetchImpl, url, method, nonce, body) {
        var init = {
            method: method,
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce, Accept: 'application/json' }
        };
        if (body !== undefined) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(body);
        }

        return Promise.resolve()
            .then(function () { return fetchImpl(url, init); })
            .then(
                function (response) {
                    return Promise.resolve()
                        .then(function () { return response.json(); })
                        .catch(function () { return null; })
                        .then(function (data) {
                            if (response.ok) {
                                return data;
                            }
                            var err = new Error(messageFor(response.status, data));
                            err.status = response.status;
                            err.errors = data && data.data && Array.isArray(data.data.errors) ? data.data.errors : [];
                            throw err;
                        });
                },
                function () {
                    var err = new Error(MSG_NETWORK);
                    err.status = 0;
                    throw err;
                }
            );
    }

    /** Copy text with the async Clipboard API. Resolves false when it is unavailable. */
    function copyText(text, clipboard) {
        if (!clipboard || typeof clipboard.writeText !== 'function') {
            return Promise.resolve(false);
        }
        return clipboard.writeText(text).then(
            function () { return true; },
            function () { return false; }
        );
    }

    function statusLabel(status) {
        if (status === 'active') {
            return t('feed_ui_104');
        }
        if (status === 'disabled') {
            return t('feed_ui_105');
        }
        return String(status || '');
    }

    function channelLabel(id, channels) {
        var found = channelById(channels, id);
        return found ? found.label : String(id || '');
    }

    /** File format label (XML (RSS), CSV, ...); unknown formats are shown upper-cased. */
    function formatLabel(format) {
        return Object.prototype.hasOwnProperty.call(FORMAT_LABELS, format) ? FORMAT_LABELS[format] : String(format || '').toUpperCase();
    }

    /** data-categories attribute (JSON array of {id, name}) to a clean list; [] when unusable. */
    function parseCategoryList(raw) {
        try {
            var parsed = JSON.parse(raw || '[]');
            if (!Array.isArray(parsed)) {
                return [];
            }
            return parsed.filter(function (c) {
                return c && Number.isInteger(c.id) && c.id > 0 && typeof c.name === 'string';
            }).map(function (c) {
                return { id: c.id, name: c.name };
            });
        } catch (e) {
            return [];
        }
    }

    /** data-channels attribute (JSON array) to a clean list; null when unusable. */
    function parseChannels(raw) {
        try {
            var parsed = JSON.parse(raw || 'null');
            if (!Array.isArray(parsed)) {
                return null;
            }
            var out = parsed.filter(function (c) { return c && typeof c.id === 'string'; }).map(function (c) {
                return {
                    id: c.id,
                    label: typeof c.label === 'string' && c.label !== '' ? c.label : c.id,
                    available: c.available === true,
                    formats: Array.isArray(c.formats) ? c.formats.filter(function (f) { return typeof f === 'string'; }) : []
                };
            });
            return out.length ? out : null;
        } catch (e) {
            return null;
        }
    }

    /** "2026-11-07 17:52:07" -> "2026-11-07 17:52" (no timezone guessing). */
    function shortDate(value) {
        return String(value || '').slice(0, 16).replace('T', ' ');
    }

    /** First `max` items of a list, and how many were left out. */
    function limitItems(list, max) {
        var items = Array.isArray(list) ? list : [];
        return { shown: items.slice(0, max), hidden: Math.max(0, items.length - max) };
    }

    function runStatusLabel(status) {
        if (Object.prototype.hasOwnProperty.call(RUN_STATUS, status)) {
            return RUN_STATUS[status].label;
        }
        return t('feed_ui_041');
    }

    /**
     * View model of a feed's last run, or null when there is none. Counters are
     * numbers; each message list keeps at most MAX_MESSAGES items.
     */
    function formatRun(run, labels) {
        if (!run || typeof run !== 'object') {
            return null;
        }
        var known = Object.prototype.hasOwnProperty.call(RUN_STATUS, run.status) ? RUN_STATUS[run.status] : null;
        var errors = limitItems(diagnosticRows(run.errors, labels), MAX_MESSAGES);
        var warnings = limitItems(diagnosticRows(run.warnings, labels), MAX_MESSAGES);
        return {
            statusLabel: runStatusLabel(run.status),
            tone: known ? known.tone : 'neutral',
            startedAt: shortDate(run.started_at),
            examined: Number(run.examined) || 0,
            exported: Number(run.exported) || 0,
            errors: errors.shown,
            errorsHidden: errors.hidden,
            warnings: warnings.shown,
            warningsHidden: warnings.hidden
        };
    }

    /** Label for a diagnostic code; unknown codes get a visible fallback that names the code. */
    function diagnosticLabel(code, labels) {
        if (labels && Object.prototype.hasOwnProperty.call(labels, code) && typeof labels[code] === 'string' && labels[code] !== '') {
            return labels[code];
        }
        return t('feed_ui_106').replace('%s', code);
    }

    /**
     * {code, count} rows from REST, one per code, with the count summed and the
     * label attached. Entries whose code is not a plain identifier are dropped.
     */
    function diagnosticRows(list, labels) {
        var seen = Object.create(null);
        var rows = [];
        (Array.isArray(list) ? list : []).forEach(function (item) {
            if (!item || typeof item.code !== 'string' || !CODE_RE.test(item.code)) {
                return;
            }
            var count = Math.max(1, Number(item.count) || 1);
            if (seen[item.code]) {
                seen[item.code].count += count;
                return;
            }
            seen[item.code] = { code: item.code, count: count, label: diagnosticLabel(item.code, labels) };
            rows.push(seen[item.code]);
        });
        return rows;
    }

    /** data-diagnostic-labels attribute (JSON object) to a plain object; {} when unreadable. */
    function parseLabels(raw) {
        try {
            var parsed = JSON.parse(raw || '{}');
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    // -----------------------------------------------------------------------
    // DOM layer (browser only).
    // -----------------------------------------------------------------------

    /** createElement helper: attrs (null/false skipped, `text` sets textContent, `on` binds events). */
    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            var value = attrs[key];
            if (value === null || value === undefined || value === false) {
                return;
            }
            if (key === 'text') {
                node.textContent = String(value);
            } else if (key === 'on') {
                Object.keys(value).forEach(function (type) { node.addEventListener(type, value[type]); });
            } else if (key === 'className') {
                node.className = String(value);
            } else {
                node.setAttribute(key, value === true ? '' : String(value));
            }
        });
        (children || []).forEach(function (child) {
            if (child === null || child === undefined || child === false) {
                return;
            }
            node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
        });
        return node;
    }

    /** A <details> list of diagnostics (t('feed_ui_107') or t('feed_ui_108')): label and count per row, capped. */
    function messageList(kind, title, items, hidden) {
        var list = el('ul', { className: 'adc-feeds__run-list' }, items.map(function (row) {
            if (row.code === 'invalid_products_excluded') {
                return el('li', { text: row.label.replace('%d', String(row.count)) });
            }
            return el('li', { text: row.label + ' — ' + row.count + (row.count === 1 ? t('feed_ui_109') : t('feed_ui_110')) });
        }));
        if (hidden > 0) {
            list.appendChild(el('li', { className: 'adc-feeds__note', text: t('feed_ui_042') + hidden + '…' }));
        }
        return el('details', { className: 'adc-feeds__run-details adc-feeds__run-details--' + kind }, [
            el('summary', { text: title + ' (' + (items.length + hidden) + ')' }),
            list
        ]);
    }

    /** Last-run block of a feed card: status line, errors (red) and warnings (yellow). */
    function runNode(run, labels) {
        var view = formatRun(run, labels);
        if (!view) {
            return el('p', { className: 'adc-feeds__note', text: t('feed_ui_043') });
        }
        var children = [
            el('p', { className: 'adc-feeds__run-line' }, [
                el('span', { className: 'adc-feeds__badge adc-feeds__badge--' + view.tone, text: t('feed_ui_111') + view.statusLabel }),
                el('span', {
                    className: 'adc-feeds__text',
                    text: (view.startedAt ? view.startedAt + ' · ' : '') + view.exported + t('feed_ui_112') + view.examined + t('feed_ui_113')
                })
            ])
        ];
        if (view.errors.length || view.errorsHidden) {
            children.push(messageList('error', t('feed_ui_107'), view.errors, view.errorsHidden));
        }
        if (view.warnings.length || view.warningsHidden) {
            children.push(messageList('warning', t('feed_ui_108'), view.warnings, view.warningsHidden));
        }
        return el('div', { className: 'adc-feeds__run' }, children);
    }

    /** Clipboard with a textarea fallback for older browsers. */
    function copyToClipboard(text) {
        return copyText(text, navigator.clipboard).then(function (ok) {
            if (ok) {
                return true;
            }
            var area = el('textarea', { readonly: true });
            area.value = text;
            area.setAttribute('style', 'position:fixed;opacity:0;top:0;left:0;');
            document.body.appendChild(area);
            area.select();
            var done = false;
            try {
                done = document.execCommand('copy');
            } catch (e) {
                done = false;
            }
            document.body.removeChild(area);
            return done;
        });
    }

    function mount(root) {
        var restUrl = root.getAttribute('data-rest-url') || '';
        var nonce = root.getAttribute('data-nonce') || '';
        var labels = parseLabels(root.getAttribute('data-diagnostic-labels'));
        var channels = parseChannels(root.getAttribute('data-channels')) || DEFAULT_CHANNELS;
        var categories = parseCategoryList(root.getAttribute('data-categories'));
        var sources = parseSourceLists(root.getAttribute('data-sources'));
        var statsEnabled = root.getAttribute('data-stats-enabled') === '1';
        var statsSettingsUrl = root.getAttribute('data-stats-settings-url') || '';

        var app = {
            feeds: [],
            view: 'list',
            busy: false,
            notice: null,
            wizard: emptyWizard(channels),
            errors: [],
            focusSelector: null,
            focusTitle: false,
            preview: null,
            editingId: null,
            pendingParams: null
        };

        function call(method, url, body) {
            return apiRequest(window.fetch.bind(window), url, method, nonce, body);
        }

        function setBusy(on) {
            app.busy = on;
            root.setAttribute('aria-busy', on ? 'true' : 'false');
        }

        function setNotice(type, text) {
            app.notice = text ? { type: type, text: text } : null;
        }

        function draw() {
            root.textContent = '';
            root.appendChild(app.view === 'wizard' ? wizardView() : listView());
            if (app.focusTitle) {
                app.focusTitle = false;
                var title = root.querySelector('#adc-feeds-wizard-title');
                if (title) {
                    title.focus();
                }
            }
            if (app.focusSelector) {
                var target = root.querySelector(app.focusSelector);
                app.focusSelector = null;
                if (target) {
                    target.focus();
                }
            }
        }

        /** Reload the list from REST, then draw. */
        function load() {
            setBusy(true);
            return call('GET', restUrl)
                .then(function (data) {
                    app.feeds = data && Array.isArray(data.items) ? data.items : [];
                    app.view = 'list';
                })
                .catch(function (err) {
                    setNotice('error', err.message);
                })
                .then(function () {
                    setBusy(false);
                    draw();
                });
        }

        /** Run one list action (regenerate, toggle, delete), then reload the list. */
        function action(task, success) {
            setBusy(true);
            setNotice(null, null);
            return Promise.resolve()
                .then(task)
                .then(function (result) {
                    setNotice('success', typeof success === 'function' ? success(result) : success);
                }, function (err) {
                    setNotice('error', err.message || MSG_GENERIC);
                })
                .then(function () {
                    setBusy(false);
                    return load();
                });
        }

        function feedUrl(id) {
            return restUrl + '/' + encodeURIComponent(String(id));
        }

        function startWizard() {
            app.wizard = resetWizardDraft(channels);
            app.editingId = null;
            app.pendingParams = null;
            app.preview = null;
            app.view = 'wizard';
            app.errors = [];
            app.focusTitle = true;
            setNotice(null, null);
            draw();
        }

        function startEdit(feed) {
            app.wizard = wizardFromFeed(feed, channels);
            app.editingId = Number(feed.id);
            app.pendingParams = Array.isArray(feed.params) ? feed.params : null;
            app.preview = null;
            app.errors = [];
            app.view = 'wizard';
            app.focusTitle = true;
            setNotice(null, null);
            goToStep(1);
        }

        function loadForEdit(feed) {
            setBusy(true);
            return call('GET', feedUrl(feed.id))
                .then(function (data) {
                    completeEditLoad(data, function () { setBusy(false); }, startEdit);
                })
                .catch(function (err) {
                    setBusy(false);
                    setNotice('error', err.message || MSG_GENERIC);
                    draw();
                });
        }

        function goToStep(step) {
            app.wizard.step = step;
            app.errors = [];
            app.focusTitle = true;
            if (step === 3 && app.wizard.params === null) {
                loadParams();
                return;
            }
            if (step === 3) {
                ensureSmartLabelRow(app.wizard);
            }
            draw();
        }

        function nextStep() {
            var errors = validateStep(app.wizard, app.wizard.step, channels);
            if (errors.length) {
                app.errors = errors;
                draw();
                return;
            }
            goToStep(app.wizard.step + 1);
        }

        function publish() {
            var errors = validateAll(app.wizard, channels);
            if (errors.length) {
                app.errors = errors;
                draw();
                return;
            }

            setBusy(true);
            app.errors = [];
            var payload = buildPayload(app.wizard, channels);
            var editId = app.editingId;
            var method = editId ? 'PATCH' : 'POST';
            if (editId) {
                payload = buildPatchPayload(app.wizard, channels);
            }
            call(method, editId ? feedUrl(editId) : restUrl, payload)
                .then(function (data) {
                    var url = data && data.feed ? data.feed.url : '';
                    var refreshWarning = payload.config.refresh !== 'manual' && data && data.refresh_scheduled === false ? ' ' + t('feed_ui_195') : '';
                    setNotice(refreshWarning ? 'warning' : 'success', (editId ? t('feed_ui_114') : t('feed_ui_115')) + (url ? t('feed_ui_044') + url : '') + refreshWarning);
                    app.view = 'list';
                    setBusy(false);
                    return load();
                }, function (err) {
                    setBusy(false);
                    app.errors = [err.message || MSG_GENERIC];
                    draw();
                });
        }

        // --- list view ------------------------------------------------------

        function listView() {
            var children = [
                el('div', { className: 'adc-feeds__head' }, [
                    el('h3', { className: 'adc-feeds__title', text: t('feed_ui_116') }),
                    el('button', {
                        type: 'button',
                        className: 'button button-primary',
                        disabled: app.busy,
                        text: t('feed_ui_117'),
                        on: { click: startWizard }
                    })
                ])
            ];

            if (app.notice) {
                children.push(noticeNode(app.notice));
            }

            if (app.feeds.length === 0) {
                children.push(el('p', { className: 'adc-feeds__text', text: t('feed_ui_045') }));
                children.push(el('p', { className: 'adc-feeds__note', text: t('feed_ui_046') }));
            } else {
                children.push(el('ul', { className: 'adc-feeds__list' }, app.feeds.map(feedCard)));
            }

            return el('div', { className: 'adc-feeds__view' }, children);
        }

        function noticeNode(notice) {
            return el('p', {
                className: 'adc-feeds__notice adc-feeds__notice--' + notice.type,
                role: notice.type === 'error' ? 'alert' : 'status',
                text: notice.text
            });
        }

        function feedCard(feed) {
            var toggleLabel = feed.status === 'active' ? t('feed_ui_047') : t('feed_ui_048');
            var nextStatus = feed.status === 'active' ? 'disabled' : 'active';

            return el('li', { className: 'adc-feeds__item' }, [
                el('div', { className: 'adc-feeds__item-head' }, [
                    el('strong', { className: 'adc-feeds__name', text: feed.name }),
                    el('span', {
                        className: 'adc-feeds__badge adc-feeds__badge--' + feed.status,
                        text: statusLabel(feed.status)
                    })
                ]),
                el('p', {
                    className: 'adc-feeds__text',
                    text: channelLabel(feed.channel, channels) + ' · ' + formatLabel(feed.format) + t('feed_ui_118') + shortDate(feed.updated_at)
                }),
                runNode(feed.last_run, labels),
                el('div', { className: 'adc-feeds__url' }, [
                    el('code', { text: feed.url }),
                    el('button', {
                        type: 'button',
                        className: 'button',
                        text: t('feed_ui_049'),
                        'aria-label': t('feed_ui_050').replace('%s', feed.name),
                        disabled: app.busy,
                        on: {
                            click: function () {
                                copyToClipboard(feed.url).then(function (ok) {
                                    setNotice(ok ? 'success' : 'error', ok ? t('feed_ui_051') : t('feed_ui_052'));
                                    draw();
                                });
                            }
                        }
                    })
                ]),
                el('div', { className: 'adc-feeds__actions' }, [
                    el('button', {
                        type: 'button',
                        className: 'button',
                        text: t('feed_ui_053'),
                        disabled: app.busy,
                        on: { click: function () { loadForEdit(feed); } }
                    }),
                    el('button', {
                        type: 'button',
                        className: 'button',
                        text: t('feed_ui_054'),
                        disabled: app.busy,
                        on: {
                            click: function () {
                                action(
                                    function () { return call('POST', feedUrl(feed.id) + '/regenerate'); },
                                    t('feed_ui_055')
                                );
                            }
                        }
                    }),
                    el('button', {
                        type: 'button',
                        className: 'button',
                        text: toggleLabel,
                        disabled: app.busy,
                        on: {
                            click: function () {
                                action(
                                    function () { return call('PATCH', feedUrl(feed.id), { status: nextStatus }); },
                                    t('feed_ui_056')
                                );
                            }
                        }
                    }),
                    el('button', {
                        type: 'button',
                        className: 'button button-link-delete',
                        text: t('feed_ui_057'),
                        disabled: app.busy,
                        on: {
                            click: function () {
                                if (!window.confirm(t('feed_ui_163').replace('%s', feed.name))) {
                                    return;
                                }
                                action(
                                    function () { return call('DELETE', feedUrl(feed.id)); },
                                    t('feed_ui_060')
                                );
                            }
                        }
                    })
                ])
            ]);
        }

        // --- wizard view ----------------------------------------------------

        function wizardView() {
            var w = app.wizard;
            var title = el('h3', {
                className: 'adc-feeds__title',
                id: 'adc-feeds-wizard-title',
                tabindex: '-1',
                text: (app.editingId ? t('feed_ui_061') : t('feed_ui_117')) + ' · ' + STEPS[w.step - 1]
            });

            var stepper = el('ol', { className: 'adc-feeds__steps' }, STEPS.map(function (label, i) {
                var n = i + 1;
                return el('li', {
                    className: 'adc-feeds__step' + (n === w.step ? ' is-current' : '') + (n < w.step ? ' is-done' : '')
                }, [el('button', {
                    type: 'button',
                    className: 'adc-feeds__step-button',
                    'aria-current': n === w.step ? 'step' : null,
                    disabled: app.busy,
                    text: n + '. ' + label,
                    on: { click: function () { goToStep(n); } }
                })]);
            }));

            var children = [
                el('div', { className: 'adc-feeds__head' }, [title]),
                stepper
            ];

            if (app.errors.length) {
                children.push(el('ul', { className: 'adc-feeds__errors', role: 'alert' }, app.errors.map(function (message) {
                    return el('li', { text: message });
                })));
            }

            children.push(el('div', { className: 'adc-feeds__step-body' }, [stepBody(w.step)]));
            children.push(wizardNav(w.step));

            return el('div', { className: 'adc-feeds__view adc-feeds__wizard' }, children);
        }

        function wizardNav(step) {
            var buttons = [];
            if (step > 1) {
                buttons.push(el('button', {
                    type: 'button',
                    className: 'button',
                    text: t('feed_ui_062'),
                    disabled: app.busy,
                    on: { click: function () { goToStep(step - 1); } }
                }));
            }
            buttons.push(el('button', {
                type: 'button',
                className: 'button',
                text: t('feed_ui_063'),
                disabled: app.busy,
                on: { click: function () { app.view = 'list'; app.errors = []; load(); } }
            }));
            if (step < 4) {
                buttons.push(el('button', {
                    type: 'button',
                    className: 'button button-primary',
                    text: t('feed_ui_064'),
                    disabled: app.busy,
                    on: { click: nextStep }
                }));
            } else {
                buttons.push(el('button', {
                    type: 'button',
                    className: 'button button-primary',
                    text: app.busy ? t('feed_ui_065') : (app.editingId ? t('feed_ui_066') : t('feed_ui_067')),
                    disabled: app.busy,
                    on: { click: publish }
                }));
            }
            return el('div', { className: 'adc-feeds__actions' }, buttons);
        }

        function stepBody(step) {
            if (step === 1) {
                var children = [
                    el('fieldset', { className: 'adc-feeds__fieldset' }, [
                        el('legend', { text: t('feed_ui_002') }),
                        el('div', { className: 'adc-feeds__options' }, channels.map(channelOption))
                    ])
                ];
                var chosen = channelById(channels, app.wizard.channel);
                if (chosen && chosen.available && chosen.formats.length) {
                    children.push(el('fieldset', { className: 'adc-feeds__fieldset' }, [
                        el('legend', { text: t('feed_ui_068') }),
                        el('div', { className: 'adc-feeds__options' }, chosen.formats.map(function (format) {
                            return radio('adc-feeds-format', format, formatLabel(format), app.wizard.format === format, function () {
                                app.wizard.format = format;
                            }, !!app.editingId);
                        }))
                    ]));
                }
                children.push(textField(t('feed_ui_126'), 'name', app.wizard.name, { maxlength: '120', required: true }));
                return el('div', { className: 'adc-feeds__fields' }, children);
            }

            if (step === 2) {
                var filtered = app.wizard.scope === 'filtered';
                var smart = app.wizard.scope === 'smart';
                var productFields = [
                    el('fieldset', { className: 'adc-feeds__fieldset' }, [
                        el('legend', { text: t('feed_ui_125') }),
                        radio('adc-feeds-scope', 'all', t('feed_ui_127'), app.wizard.scope === 'all', function () {
                            app.wizard.scope = 'all';
                            draw();
                        }),
                        radio('adc-feeds-scope', 'filtered', t('feed_ui_128'), filtered, function () {
                            app.wizard.scope = 'filtered';
                            draw();
                        }),
                        radio('adc-feeds-scope', 'smart', t('feed_ui_164'), smart, function () {
                            app.wizard.scope = 'smart';
                            draw();
                        })
                    ])
                ];
                if (filtered || smart) {
                    productFields.push(textField(t('feed_ui_069'), 'categories', app.wizard.categories, {
                        placeholder: t('feed_ui_129'),
                        onInput: function (value) { app.wizard.categories = value; }
                    }));
                    if (smart) {
                        productFields.push(el('p', { className: 'adc-feeds__note', text: t('feed_ui_194') }));
                    }
                }
                if (smart) {
                    productFields.push(smartRuleFields());
                    if (!statsEnabled) {
                        productFields.push(el('p', { className: 'adc-feeds__notice adc-feeds__notice--warning', role: 'status' }, [
                            document.createTextNode(t('feed_ui_165') + ' '),
                            el('a', { href: statsSettingsUrl, text: t('feed_ui_166') })
                        ]));
                    }
                }
                productFields.push(selectField(t('feed_ui_167'), 'refresh', app.wizard.refresh, [
                    { id: 'manual', label: t('feed_ui_170') },
                    { id: 'hourly', label: t('feed_ui_171') },
                    { id: 'daily', label: t('feed_ui_172') },
                    { id: 'weekly', label: t('feed_ui_173') }
                ]));
                productFields.push(checkbox(t('feed_ui_070'), app.wizard.inStock, function (checked) {
                    app.wizard.inStock = checked;
                }));
                productFields.push(checkbox(t('feed_ui_130'), app.wizard.excludeInvalid, function (checked) {
                    app.wizard.excludeInvalid = checked;
                }));
                return el('div', { className: 'adc-feeds__fields' }, productFields);
            }

            if (step === 3) {
                return step3Body();
            }

            return summary();
        }

        function smartRuleFields() {
            var metricChoices = [
                { id: 'purchases', label: t('feed_ui_174') },
                { id: 'views', label: t('feed_ui_175') },
                { id: 'add_to_cart', label: t('feed_ui_176') },
                { id: 'begin_checkout', label: t('feed_ui_177') }
            ];
            var fields = [
                selectField(t('feed_ui_178'), 'smartMode', app.wizard.smartMode, [
                    { id: 'top_n', label: t('feed_ui_179') },
                    { id: 'min_value', label: t('feed_ui_180') },
                    { id: 'low_conversion', label: t('feed_ui_181') }
                ]),
                selectField(t('feed_ui_182'), 'smartMetric', app.wizard.smartMetric, metricChoices),
                selectField(t('feed_ui_183'), 'smartDays', String(app.wizard.smartDays), [7, 14, 30, 90].map(function (days) { return { id: String(days), label: String(days) + ' ' + t('feed_ui_184') }; }))
            ];
            fields.push(textField(t('feed_ui_185'), 'smartLimit', String(app.wizard.smartLimit), { inputmode: 'numeric' }));
            if (app.wizard.smartMode === 'min_value') {
                fields.push(textField(t('feed_ui_186'), 'smartThreshold', String(app.wizard.smartThreshold), { inputmode: 'numeric' }));
            } else if (app.wizard.smartMode === 'low_conversion') {
                fields.push(textField(t('feed_ui_196'), 'smartConversion', String(app.wizard.smartConversion), { inputmode: 'numeric', min: '0', max: '100' }));
            }
            fields.push(el('div', { className: 'adc-feeds__actions adc-feeds__presets' }, [
                presetButton('purchases', 'top_n', 30, 50, t('feed_ui_187')),
                presetButton('views', 'top_n', 14, 50, t('feed_ui_188')),
                presetButton('views', 'low_conversion', 30, 50, t('feed_ui_189')),
                presetButton('add_to_cart', 'top_n', 30, 50, t('feed_ui_190'))
            ]));
            fields.push(el('p', { className: 'adc-feeds__note', text: t('feed_ui_191') }));
            return el('fieldset', { className: 'adc-feeds__fieldset' }, [el('legend', { text: t('feed_ui_192') })].concat(fields));
        }

        function presetButton(metric, mode, days, limit, label) {
            return el('button', { type: 'button', className: 'button', text: label, disabled: app.busy, on: { click: function () {
                app.wizard.smartMetric = metric;
                app.wizard.smartMode = mode;
                app.wizard.smartDays = days;
                app.wizard.smartLimit = limit;
                draw();
            } } });
        }

        /** Step 3 depends on the destination: Google shipping, or Meta/TikTok catalogue defaults. */
        function channelSettings() {
            var w = app.wizard;
            var fields = [];
            if (w.channel === 'google') {
                fields.push(
                    textField(t('feed_ui_071'), 'country', w.country, { maxlength: '2', placeholder: 'RO' }),
                    textField(t('feed_ui_072'), 'price', w.price, {
                        inputmode: 'decimal',
                        help: t('feed_ui_073')
                    }),
                    textField(t('feed_ui_131'), 'service', w.service, { maxlength: '100' }),
                    selectField(t('feed_ui_074'), 'condition', w.condition, CONDITIONS)
                );
            } else if (w.channel === 'meta' || w.channel === 'tiktok') {
                fields.push(
                    selectField(t('feed_ui_074'), 'condition', w.condition, CONDITIONS),
                    textField(t('feed_ui_075'), 'brand', w.brand, { maxlength: String(BRAND_MAX), help: t('feed_ui_076') })
                );
            }
            if (w.channel === 'meta') {
                fields.push(textField(t('feed_ui_132'), 'labels', w.labels, {
                    placeholder: t('feed_ui_133'),
                    help: t('feed_ui_077')
                }));
            }
            if (w.format === 'csv' && (w.channel === 'meta' || w.channel === 'tiktok')) {
                fields.push(checkbox(t('feed_ui_134'), w.csvExcelSafe, function (checked) {
                    app.wizard.csvExcelSafe = checked;
                }));
            }
            if (w.channel === 'google' || w.channel === 'meta' || w.channel === 'tiktok') {
                fields.push(categoryFields());
            }
            return el('div', { className: 'adc-feeds__fields' }, fields);
        }

        // --- parameter table ------------------------------------------------

        function fieldByKey(key) {
            return app.wizard.fields.filter(function (f) { return f.key === key; })[0];
        }

        function helpFor(key) {
            var field = fieldByKey(key);
            return field && field.help ? field.help : '';
        }

        /** Registry for the chosen destination and the server defaults; then the table is built. */
        function loadParams() {
            setBusy(true);
            app.errors = [];
            return call('GET', restUrl + '/fields?channel=' + encodeURIComponent(app.wizard.channel))
                .then(function (data) {
                    var items = data && Array.isArray(data.items) ? data.items : [];
                    sources = mergeSourceLists(sources, data);
                    app.wizard.fields = items;
                    app.wizard.params = app.pendingParams
                        ? initialParams(app.pendingParams, items)
                        : initialParams(data && Array.isArray(data.defaults) ? data.defaults : [], items);
                    ensureSmartLabelRow(app.wizard);
                    app.pendingParams = null;
                }, function (err) {
                    app.wizard.step = 2;
                    app.errors = [err.message || MSG_GENERIC];
                })
                .then(function () {
                    setBusy(false);
                    draw();
                });
        }

        function sourceValueNode(row, index) {
            var type = row.source.type;
            if (type === 'category_map') {
                return el('p', { className: 'adc-feeds__note', text: t('feed_ui_078') });
            }
            var choices = sourceChoices(type, sources);
            if (choices) {
                return el('select', {
                    'aria-label': t('feed_ui_135'),
                    name: 'param-value-' + index,
                    disabled: app.busy,
                    on: { change: function (event) { row.source.value = event.target.value; } }
                }, [el('option', { value: '', text: t('feed_ui_136') })].concat(choices.map(function (c) {
                    return el('option', { value: c.value, selected: c.value === row.source.value, text: c.label });
                })));
            }
            return el('input', {
                type: 'text',
                'aria-label': t('feed_ui_135'),
                name: 'param-value-' + index,
                value: row.source.value,
                maxlength: '300',
                disabled: app.busy,
                on: { input: function (event) { row.source.value = event.target.value; } }
            });
        }

        function transformsNode(row) {
            var items = row.transforms.map(function (transform, i) {
                return el('li', { className: 'adc-feeds__run-line' }, [
                    el('span', { text: transformLabel(transform) }),
                    el('button', {
                        type: 'button',
                        className: 'button',
                        text: t('feed_ui_057'),
                        'aria-label': t('feed_ui_079').replace('%s', transformLabel(transform)),
                        disabled: app.busy,
                        on: { click: function () { row.transforms.splice(i, 1); draw(); } }
                    })
                ]);
            });
            var opSelect = el('select', { 'aria-label': t('feed_ui_080'), disabled: app.busy },
                TRANSFORM_OPS.map(function (op) { return el('option', { value: op.id, text: op.label }); }));
            var argInput = el('input', { type: 'text', 'aria-label': t('feed_ui_137'), maxlength: '200', disabled: app.busy });
            return el('div', { className: 'adc-feeds__transforms' }, [
                el('ul', { className: 'adc-feeds__run-list' }, items),
                el('div', { className: 'adc-feeds__inline' }, [
                    opSelect,
                    argInput,
                    el('button', {
                        type: 'button',
                        className: 'button',
                        text: t('feed_ui_081'),
                        disabled: app.busy,
                        on: { click: function () { row.transforms.push({ op: opSelect.value, arg: argInput.value }); draw(); } }
                    })
                ])
            ]);
        }

        function paramRow(row, index) {
            var rows = app.wizard.params;
            var field = fieldByKey(row.field);
            var required = !!(field && field.required);

            var fieldCell;
            if (required) {
                fieldCell = el('div', null, [
                    el('strong', { text: field.label }),
                    el('span', { className: 'adc-feeds__badge adc-feeds__badge--ok', text: t('feed_ui_138') }),
                    el('p', { className: 'adc-feeds__note', text: field.help || '' })
                ]);
            } else {
                var options = fieldOptions(app.wizard.fields, rows, row.field).map(function (o) {
                    return el('option', { value: o.key, selected: o.key === row.field, disabled: o.disabled, text: o.label });
                });
                fieldCell = el('div', null, [
                    el('select', {
                        'aria-label': t('feed_ui_139'),
                        disabled: app.busy,
                        on: {
                            change: function (event) {
                                var next = fieldByKey(event.target.value);
                                row.field = event.target.value;
                                row.source = next && next.default_source ? cloneSource(next.default_source) : { type: 'native', value: '' };
                                row.transforms = [];
                                draw();
                            }
                        }
                    }, options),
                    el('p', { className: 'adc-feeds__note', text: helpFor(row.field) })
                ]);
            }

            var sourceCell = el('div', { className: 'adc-feeds__inline' }, [
                el('select', {
                    'aria-label': t('feed_ui_082'),
                    disabled: app.busy,
                    on: { change: function (event) { row.source = { type: event.target.value, value: '' }; draw(); } }
                }, SOURCE_TYPES.map(function (t) {
                    return el('option', { value: t.id, selected: t.id === row.source.type, text: t.label });
                })),
                sourceValueNode(row, index)
            ]);

            var deleteCell = required ? el('span', { text: '' }) : el('button', {
                type: 'button',
                className: 'button button-link-delete',
                text: t('feed_ui_057'),
                disabled: app.busy,
                on: { click: function () { rows.splice(index, 1); draw(); } }
            });

            return el('tr', null, [
                el('td', { 'data-label': t('feed_ui_139') }, [fieldCell]),
                el('td', { 'data-label': t('feed_ui_083') }, [sourceCell]),
                el('td', { 'data-label': t('feed_ui_084') }, [transformsNode(row)]),
                el('td', { 'data-label': t('feed_ui_104') }, [el('input', {
                    type: 'checkbox',
                    'aria-label': t('feed_ui_104'),
                    checked: row.enabled,
                    disabled: required || app.busy,
                    on: { change: function (event) { row.enabled = event.target.checked; } }
                })]),
                el('td', { 'data-label': t('feed_ui_057') }, [deleteCell])
            ]);
        }

        function addParamControl() {
            var rows = app.wizard.params;
            var options = fieldOptions(app.wizard.fields, rows, null).filter(function (o) { return !o.disabled && !o.required; });
            if (options.length === 0) {
                return el('p', { className: 'adc-feeds__note', text: t('feed_ui_085') });
            }
            var help = el('p', { className: 'adc-feeds__note', text: helpFor(options[0].key) });
            var select = el('select', {
                'aria-label': t('feed_ui_141'),
                disabled: app.busy,
                on: { change: function (event) { help.textContent = helpFor(event.target.value); } }
            }, options.map(function (o) { return el('option', { value: o.key, text: o.label }); }));
            return el('div', { className: 'adc-feeds__add' }, [
                el('div', { className: 'adc-feeds__inline' }, [
                    select,
                    el('button', {
                        type: 'button',
                        className: 'button',
                        text: t('feed_ui_086'),
                        disabled: app.busy,
                        on: {
                            click: function () {
                                var next = fieldByKey(select.value);
                                if (next) {
                                    rows.push(newRow(next));
                                    draw();
                                }
                            }
                        }
                    })
                ]),
                help
            ]);
        }

        function paramsTable() {
            var head = el('thead', null, [el('tr', null, [t('feed_ui_139'), t('feed_ui_083'), t('feed_ui_084'), t('feed_ui_104'), t('feed_ui_057')].map(function (t) {
                return el('th', { scope: 'col', text: t });
            }))]);
            var body = el('tbody', null, app.wizard.params.map(paramRow));
            return el('div', { className: 'adc-feeds__stack' }, [
                el('div', { className: 'adc-feeds__ptable-wrap' }, [el('table', { className: 'adc-feeds__ptable' }, [head, body])]),
                addParamControl()
            ]);
        }

        function runPreview() {
            ensureSmartLabelRow(app.wizard);
            var payload = buildPayload(app.wizard, channels);
            setBusy(true);
            app.preview = null;
            call('POST', restUrl + '/preview', {
                channel: payload.channel,
                format: payload.format,
                config: payload.config,
                params: payload.params || [],
                n: 5
            }).then(function (data) {
                app.preview = { rows: data && Array.isArray(data.rows) ? data.rows : [], selection: data && Array.isArray(data.selection) ? data.selection : [], warnings: data && Array.isArray(data.warnings) ? data.warnings : [] };
            }, function (err) {
                app.preview = { error: err.message || MSG_GENERIC, errors: err.errors || [] };
            }).then(function () {
                setBusy(false);
                draw();
            });
        }

        function previewNode() {
            var p = app.preview;
            if (!p) {
                return null;
            }
            if (p.error) {
                return el('div', { className: 'adc-feeds__stack' }, [
                    noticeNode({ type: 'error', text: p.error }),
                    p.errors.length ? el('ul', { className: 'adc-feeds__errors' }, diagnosticRows(p.errors.map(function (code) {
                        return { code: code, count: 1 };
                    }), labels).map(function (row) { return el('li', { text: row.label }); })) : null
                ]);
            }
            if (p.rows.length === 0) {
                var emptyChildren = [el('p', { className: 'adc-feeds__note', text: t('feed_ui_087') })];
                var emptyWarnings = diagnosticRows(p.warnings, labels);
                if (emptyWarnings.length) {
                    emptyChildren.push(messageList('warning', t('feed_ui_108'), emptyWarnings, 0));
                }
                return el('div', { className: 'adc-feeds__stack' }, emptyChildren);
            }
            var keys = [];
            p.rows.forEach(function (row) {
                Object.keys(row).forEach(function (k) { if (keys.indexOf(k) === -1) { keys.push(k); } });
            });
            var hasSelection = Array.isArray(p.selection) && p.selection.length > 0;
            var headers = keys.map(function (k) { return el('th', { scope: 'col', text: k }); });
            if (hasSelection) {
                headers.push(el('th', { scope: 'col', text: t('feed_ui_193') }));
            }
            var table = el('table', { className: 'adc-feeds__ptable adc-feeds__preview' }, [
                el('thead', null, [el('tr', null, headers)]),
                el('tbody', null, p.rows.map(function (row, index) {
                    var cells = keys.map(function (k) {
                        return el('td', { 'data-label': k, text: row[k] === undefined ? '' : String(row[k]) });
                    });
                    if (hasSelection) {
                        var selected = p.selection[index] || {};
                        var metricText = selected.metric ? ({ views: t('feed_ui_175'), purchases: t('feed_ui_174'), add_to_cart: t('feed_ui_176'), begin_checkout: t('feed_ui_177') }[selected.metric] || selected.metric) : '';
                        cells.push(el('td', { 'data-label': t('feed_ui_193'), text: metricText ? metricText + ': ' + selected.value : '' }));
                    }
                    return el('tr', null, cells);
                }))
            ]);
            var children = [el('div', { className: 'adc-feeds__ptable-wrap' }, [table])];
            var warnings = diagnosticRows(p.warnings, labels);
            if (warnings.length) {
                children.push(messageList('warning', t('feed_ui_108'), warnings, 0));
            }
            return el('div', { className: 'adc-feeds__stack' }, children);
        }

        /** Step 3: the parameter table and the preview, then the destination settings card. */
        function step3Body() {
            var w = app.wizard;
            var parts = [el('h4', { className: 'adc-feeds__subtitle', text: t('feed_ui_142') })];
            if (!Array.isArray(w.params)) {
                parts.push(el('p', { className: 'adc-feeds__status', text: t('feed_ui_088') }));
            } else {
                parts.push(el('p', { className: 'adc-feeds__note', text: t('feed_ui_089') }));
                parts.push(paramsTable());
                parts.push(el('div', { className: 'adc-feeds__inline' }, [
                    el('button', {
                        type: 'button',
                        className: 'button',
                        text: app.busy ? t('feed_ui_090') : t('feed_ui_091'),
                        disabled: app.busy,
                        on: { click: runPreview }
                    })
                ]));
                var preview = previewNode();
                if (preview) {
                    parts.push(preview);
                }
            }
            parts.push(el('fieldset', { className: 'adc-feeds__fieldset' }, [
                el('legend', { text: t('feed_ui_092') }),
                channelSettings()
            ]));
            return el('div', { className: 'adc-feeds__stack' }, parts);
        }

        /** Default category, and one input per WooCommerce category for the platform category path. */
        function categoryFields() {
            var w = app.wizard;
            var rows = [
                textField(t('feed_ui_093'), 'defaultCategory', w.defaultCategory, {
                    maxlength: '750',
                    help: t('feed_ui_094')
                })
            ];
            if (categories.length === 0) {
                rows.push(el('p', { className: 'adc-feeds__note', text: t('feed_ui_095') }));
            }
            categories.forEach(function (cat) {
                var key = String(cat.id);
                rows.push(textField(t('feed_ui_154').replace('%s', cat.name), 'map-' + key, w.categoryMap[key] || '', {
                    maxlength: '750',
                    onInput: function (value) { app.wizard.categoryMap[key] = value; }
                }));
            });
            return el('fieldset', { className: 'adc-feeds__fieldset' }, [
                el('legend', { text: t('feed_ui_162') }),
                el('div', { className: 'adc-feeds__fields' }, rows)
            ]);
        }

        function summary() {
            var n = normalize(app.wizard);
            var channel = channelById(channels, n.channel);
            var products = productSummary(n);
            var condition = CONDITIONS.filter(function (c) { return c.id === n.condition; })[0];

            var rows = [
                [t('feed_ui_143'), n.name],
                [t('feed_ui_002'), channel ? channel.label : n.channel],
                [t('feed_ui_144'), formatLabel(n.format)],
                [t('feed_ui_103'), products]
            ];
            if (n.channel === 'google') {
                rows.push([t('feed_ui_145'), n.country + ' · ' + n.price + ' · ' + n.service]);
            }
            if (n.channel === 'meta' || n.channel === 'tiktok') {
                rows.push([t('feed_ui_075'), n.brand]);
            }
            if (n.channel === 'meta') {
                rows.push([t('feed_ui_132'), n.labels.length ? n.labels.join(', ') : '—']);
            }
            rows.push([t('feed_ui_074'), condition ? condition.label : n.condition]);
            if (n.format === 'csv' && (n.channel === 'meta' || n.channel === 'tiktok')) {
                rows.push([t('feed_ui_146'), n.csvExcelSafe ? t('feed_ui_147') : t('feed_ui_148')]);
            }

            if (Array.isArray(app.wizard.params)) {
                rows.push([t('feed_ui_149'), String(app.wizard.params.filter(function (r) { return r.enabled; }).length) + t('feed_ui_150')]);
            }
            if (n.channel === 'google' || n.channel === 'meta' || n.channel === 'tiktok') {
                rows.push([t('feed_ui_093'), n.defaultCategory || '—'], [t('feed_ui_151'), String(Object.keys(n.categoryMap).length)]);
            }

            return el('div', { className: 'adc-feeds__fields' }, [
                el('dl', { className: 'adc-feeds__summary' }, rows.reduce(function (acc, row) {
                    acc.push(el('dt', { text: row[0] }), el('dd', { text: row[1] }));
                    return acc;
                }, [])),
                el('p', {
                    className: 'adc-feeds__note',
                    text: t('feed_ui_098')
                })
            ]);
        }

        function channelOption(channel) {
            var checked = app.wizard.channel === channel.id;
            var children = [
                el('input', {
                    type: 'radio',
                    name: 'adc-feeds-channel',
                    value: channel.id,
                    checked: checked,
                    disabled: !channel.available || !!app.editingId,
                    on: {
                        change: function () {
                            // The format list depends on the destination: reset it and redraw.
                            app.wizard.channel = channel.id;
                            app.wizard.format = defaultFormat(channels, channel.id) || app.wizard.format;
                            app.wizard.params = null;
                            app.wizard.fields = [];
                            app.preview = null;
                            app.focusSelector = 'input[name="adc-feeds-channel"][value="' + channel.id + '"]';
                            draw();
                        }
                    }
                }),
                el('span', { text: channel.label })
            ];
            if (!channel.available) {
                children.push(el('span', { className: 'adc-feeds__soon', text: t('feed_ui_099') }));
            }
            return el('label', { className: 'adc-feeds__option' + (channel.available ? '' : ' is-disabled') }, children);
        }

        function radio(name, value, label, checked, onChange, disabled) {
            return el('label', { className: 'adc-feeds__option' + (disabled ? ' is-disabled' : '') }, [
                el('input', { type: 'radio', name: name, value: value, checked: checked, disabled: !!disabled, on: { change: onChange } }),
                el('span', { text: label })
            ]);
        }

        function checkbox(label, checked, onChange) {
            return el('label', { className: 'adc-feeds__option' }, [
                el('input', {
                    type: 'checkbox',
                    checked: checked,
                    on: { change: function (event) { onChange(event.target.checked); } }
                }),
                el('span', { text: label })
            ]);
        }

        function textField(label, name, value, options) {
            var opts = options || {};
            var id = 'adc-feeds-' + name;
            var input = el('input', {
                type: 'text',
                id: id,
                name: name,
                value: value,
                maxlength: opts.maxlength,
                placeholder: opts.placeholder,
                inputmode: opts.inputmode,
                required: opts.required,
                'aria-describedby': opts.help ? id + '-help' : null,
                on: {
                    input: function (event) {
                        var v = event.target.value;
                        if (opts.onInput) {
                            opts.onInput(v);
                        } else {
                            app.wizard[name] = v;
                        }
                    }
                }
            });
            return el('div', { className: 'adc-feeds__field' }, [
                el('label', { for: id, text: label }),
                input,
                opts.help ? el('p', { id: id + '-help', className: 'adc-feeds__note', text: opts.help }) : null
            ]);
        }

        function selectField(label, name, value, choices) {
            var id = 'adc-feeds-' + name;
            return el('div', { className: 'adc-feeds__field' }, [
                el('label', { for: id, text: label }),
                el('select', {
                    id: id,
                    name: name,
                    on: { change: function (event) {
                        app.wizard[name] = event.target.value;
                        if (name === 'smartMode') {
                            if (event.target.value === 'low_conversion') {
                                app.wizard.smartMetric = 'views';
                            }
                            draw();
                        }
                    } }
                }, choices.map(function (choice) {
                    return el('option', { value: choice.id, selected: String(choice.id) === String(value), text: choice.label });
                }))
            ]);
        }

        // Start with the list. Only the wrapper is server-rendered.
        load();
    }

    var api = {
        CHANNELS: DEFAULT_CHANNELS,
        CONDITIONS: CONDITIONS,
        emptyWizard: emptyWizard,
        resetWizardDraft: resetWizardDraft,
        parseCategories: parseCategories,
        parseInternalLabels: parseInternalLabels,
        parseCategoryList: parseCategoryList,
        parseSourceLists: parseSourceLists,
        mergeSourceLists: mergeSourceLists,
        sourceChoices: sourceChoices,
        initialParams: initialParams,
        fieldOptions: fieldOptions,
        validateParams: validateParams,
        normalizeParams: normalizeParams,
        SOURCE_TYPES: SOURCE_TYPES,
        TRANSFORM_OPS: TRANSFORM_OPS,
        cleanCategoryMap: cleanCategoryMap,
        normalize: normalize,
        productSummary: productSummary,
        validateStep: validateStep,
        validateAll: validateAll,
        uniqueMessages: uniqueMessages,
        buildPayload: buildPayload,
        buildPatchPayload: buildPatchPayload,
        wizardFromFeed: wizardFromFeed,
        completeEditLoad: completeEditLoad,
        messageFor: messageFor,
        apiRequest: apiRequest,
        copyText: copyText,
        statusLabel: statusLabel,
        channelLabel: channelLabel,
        formatLabel: formatLabel,
        parseChannels: parseChannels,
        limitItems: limitItems,
        runStatusLabel: runStatusLabel,
        formatRun: formatRun,
        diagnosticLabel: diagnosticLabel,
        diagnosticRows: diagnosticRows,
        parseLabels: parseLabels,
        mount: mount,
        MAX_MESSAGES: MAX_MESSAGES
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }

    if (typeof document !== 'undefined') {
        var start = function () {
            var roots = document.querySelectorAll('[data-adc-feeds]');
            Array.prototype.forEach.call(roots, mount);
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start);
        } else {
            start();
        }
    }
}());
