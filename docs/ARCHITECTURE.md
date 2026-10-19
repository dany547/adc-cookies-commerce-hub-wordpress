# ADC Marketing 2.0 — Architecture & Build Spec

Status: **contract v1 (frozen for parallel implementation)**. Target: evolve the current consent-checkbox plugin (v1.0.0) into a **consent manager (CMP) + GTM-ready dataLayer + server-side tracking** plugin for WordPress + WooCommerce.
Platform API specifics (endpoints, payload fields, limits, event-name mappings, API versions) live in `docs/research/PLATFORMS.md` and are referenced here as `→ PLATFORMS §<topic>`; they are **not duplicated**. Where this doc shows a platform name/value, the PLATFORMS doc wins.

Conventions (all chunks): PHP 8.2+, `declare(strict_types=1);` in every PHP file, PSR-12 layout (brace on next line for classes/functions, as the existing code) + WPCS *security / i18n / DB* sniffs, all symbols prefixed `ADC_` / `adc_`, text domain `adc-marketing`, **UI strings in Romanian written directly as the msgid** (`__('Acceptă tot', 'adc-marketing')`), code comments in English, no jQuery in new JS (vanilla, `'use strict'`, no build step), HPOS-safe order access only (`$order->get_meta()/update_meta_data()/save()`, never `get_post_meta` on orders).

---

## 0. The core idea (what must not regress)

One consent state, one writer, many readers.

```
                    ┌────────────────────── ADC_Consent (single source of truth) ──────────────────────┐
 banner/modal/API → │ cookie adc_consent (first-party, server-set)  +  proof log table  +  user meta    │
 /adc/v1/consent    │ order snapshot (_adc_consent_snapshot) taken at checkout                           │
                    └──────┬───────────────────────────────┬─────────────────────────────┬───────────────┘
                           │ client                         │ client                       │ server
                  Consent Mode v2 signals        adcConsent.whenAllowed()        ADC_Dispatcher gate
                  (gtag consent default/update   gates direct pixels & blocked     (re-evaluated at SEND time,
                   BEFORE any tag, dataLayer)    third-party scripts                pending jobs cancelled on withdraw)
```

* Client tags and server sends read the **same** state; there is no second consent store.
* Every conversion event carries an `event_id` shared by browser and server (platform-side dedup).
* **Cookie consent categories** (`necessary`, `preferences`, `statistics`, `marketing`) are **distinct** from the existing **channel consents** (marketing e-mail / marketing SMS, meta `_adc_marketing_*`). They never overwrite each other (§7).

---

## 1. Module map

Autoload rule (`ADC_Autoloader`): class `ADC_Foo_Bar` → file `class-adc-foo-bar.php`; interface `ADC_Foo_Bar_Interface` → `interface-adc-foo-bar.php`; searched in `includes/`, `includes/core/`, `includes/consent/`, `includes/tracking/`, `includes/integrations/`, `includes/admin/`. Existing `ADC_MyAccount_Marketing` → `class-adc-myaccount-marketing.php` already satisfies this (keep that exact casing).

Legend: **[N]** new, **[L]** legacy file (kept, owner modifies in place). Chunk owner in the last column (see §5).

### 1.1 Core (C1)
| File | Class / content | Role |
|---|---|---|
| `adc-marketing.php` [L] | constants, bootstrap | adds `ADC_MARKETING_FILE`, `ADC_MARKETING_DB_VERSION='1'`; registers autoloader; activation → `ADC_Installer::activate()` (still flushes `marketing-consent` endpoint) |
| `includes/class-adc-marketing.php` [L] | `ADC_Marketing` | module registry; boots legacy via `get_instance()`, new via `ADC_Module_Interface::boot()`; skips classes that do not exist yet |
| `includes/core/class-adc-autoloader.php` [N] | `ADC_Autoloader` | rule above |
| `includes/core/interface-adc-module.php` [N] | `ADC_Module_Interface` | `public static function boot(): void;` (idempotent) |
| `includes/core/class-adc-settings.php` [N] | `ADC_Settings` | grouped options, defaults, sanitizers, secrets (§4) |
| `includes/core/class-adc-secrets.php` [N] | `ADC_Secrets` | `seal(string): string`, `open(string): string` (libsodium secretbox, key = `hash('sha256', wp_salt('auth'))`, prefix `enc1:`) |
| `includes/core/class-adc-installer.php` [N] | `ADC_Installer` | tables (dbDelta), `maybe_upgrade()`, activation/deactivation, uninstall helper |
| `includes/core/class-adc-template.php` [N] | `ADC_Template` | overridable templates |
| `includes/core/class-adc-logger.php` [N] | `ADC_Logger` | scrubbed logging via `wc_get_logger()` |
| `includes/core/class-adc-frontend-config.php` [N] | `ADC_Frontend_Config` | builds `window.adcConfig` |
| `includes/core/class-adc-event.php` [N] | `ADC_Event` | canonical event value object |
| `includes/core/class-adc-event-id.php` [N] | `ADC_Event_Id` | event_id generation |
| `includes/core/class-adc-user-data.php` [N] | `ADC_User_Data` | hashed matching fields value object |
| `includes/core/class-adc-send-result.php` [N] | `ADC_Send_Result` | integration send outcome |
| `includes/core/class-adc-outbox-job.php` [N] | `ADC_Outbox_Job` | outbox row DTO |
| `includes/core/interface-adc-integration.php` [N] | `ADC_Integration_Interface` | platform integration contract |
| `includes/admin/class-adc-admin-shell.php` [N] | `ADC_Admin_Shell` | the one admin page + tab router |
| `includes/admin/interface-adc-admin-tab.php` [N] | `ADC_Admin_Tab_Interface` | tab contract |
| `assets/css/admin-shell.css`, `assets/js/admin-shell.js` [N] | — | tab nav, secret-field "replace" toggle, copy buttons |
| `uninstall.php` [L], `phpcs.xml.dist`, `bin/smoke.sh` [N] | — | uninstall (opt-in data wipe), ruleset, smoke harness |

### 1.2 Consent engine (C2) — `includes/consent/`
`class-adc-consent.php` (`ADC_Consent`, facade + module), `class-adc-consent-state.php` (`ADC_Consent_State`), `class-adc-consent-store.php` (`ADC_Consent_Store`), `class-adc-consent-rest.php` (`ADC_Consent_Rest`), `class-adc-consent-mode.php` (`ADC_Consent_Mode`), `class-adc-consent-privacy.php` (`ADC_Consent_Privacy`: WP privacy exporter/eraser + retention cron).

### 1.3 Banner & cookie manager UI (C3)
`includes/consent/class-adc-consent-banner.php` (`ADC_Consent_Banner`), `includes/admin/class-adc-admin-tab-banner.php` (`ADC_Admin_Tab_Banner`), `assets/js/consent-manager.js` (**`window.adcConsent`**), `assets/js/consent-banner.js` (UI), `assets/css/consent-banner.css`, `templates/consent/banner.php`, `templates/consent/preferences.php`, `templates/consent/cookie-list.php`.

### 1.4 dataLayer & e-commerce events (C4) — `includes/tracking/`
`class-adc-datalayer.php` (`ADC_Datalayer`: head bootstrap, page context, script enqueue), `class-adc-gtm.php` (`ADC_Gtm`: container snippet/noscript, sGTM loader URL), `class-adc-event-builder.php` (`ADC_Event_Builder`: WooCommerce objects → `ADC_Event`/GA4 items), `class-adc-event-hooks.php` (`ADC_Event_Hooks`: server-origin triggers), `class-adc-pending-events.php` (`ADC_Pending_Events`: session queue → fragments / footer), `includes/admin/class-adc-admin-tab-events.php` (`ADC_Admin_Tab_Events`), `assets/js/datalayer.js` (**`window.adcTrack`**), `assets/js/events.js` (DOM/Woo auto-events).

### 1.5 Server pipeline, advanced matching, logs (C5)
`includes/tracking/`: `class-adc-dispatcher.php` (`ADC_Dispatcher`), `class-adc-outbox.php` (`ADC_Outbox`), `class-adc-tracking-rest.php` (`ADC_Tracking_Rest`: `/event`, `/identify`), `class-adc-advanced-matching.php` (`ADC_Advanced_Matching`), `class-adc-order-context.php` (`ADC_Order_Context`: attribution capture on order), `class-adc-retention.php` (`ADC_Retention`: outbox purge, privacy eraser for attribution).
`includes/admin/`: `class-adc-admin-tab-matching.php` (`ADC_Admin_Tab_Matching`), `class-adc-admin-tab-logs.php` (`ADC_Admin_Tab_Logs`). `assets/js/identify.js`, `assets/js/admin-logs.js`, `assets/css/admin-logs.css`.

### 1.6 Integrations (C6, C7) — `includes/integrations/`
C6: `class-adc-integration-meta.php` (`ADC_Integration_Meta`), `class-adc-integration-tiktok.php` (`ADC_Integration_Tiktok`); tabs `ADC_Admin_Tab_Meta`, `ADC_Admin_Tab_Tiktok`; `assets/js/pixels/meta.js`, `assets/js/pixels/tiktok.js`.
C7: `class-adc-integration-ga4.php` (`ADC_Integration_Ga4`), `class-adc-integration-sgtm.php` (`ADC_Integration_Sgtm`); tabs `ADC_Admin_Tab_Ga4`, `ADC_Admin_Tab_Sgtm`; `assets/js/pixels/ga4.js`.

### 1.7 Legacy (C8)
Existing classes stay (`ADC_GDPR_Compliance`, `ADC_Consent_Checkout`, `ADC_Consent_Admin`, `ADC_MyAccount_Marketing`, `ADC_Marketing_Export`, `functions.php`) + new `includes/class-adc-channel-consent.php` (`ADC_Channel_Consent`) and `includes/admin/class-adc-admin-tab-marketing.php` (`ADC_Admin_Tab_Marketing`). See §7.

---

## 2. Public interfaces

### 2.1 Core PHP

```php
interface ADC_Module_Interface { public static function boot(): void; }

final class ADC_Settings
{
    /** Called on action `adc_register_settings` (fires on `init` priority 5). */
    public static function register_group(string $group, array $defaults, callable $sanitize, array $secret_keys = []): void;
    // $sanitize signature: fn(array $input, array $current): array  (must return a complete, typed array)
    public static function get(string $group, string $key, mixed $default = null): mixed;
    public static function all(string $group): array;                 // defaults merged with stored
    public static function update(string $group, array $input): array; // sanitizes, keeps secret if input value is '', saves, returns stored
    public static function secret(string $group, string $key): string; // decrypted; constant ADC_{GROUP}_{KEY} (e.g. ADC_META_ACCESS_TOKEN) overrides DB
    public static function option_name(string $group): string;        // 'adc_settings_' . $group
}

final class ADC_Logger  { public static function log(string $level, string $message, array $context = []): void; } // level: error|warning|info|debug; honours logs.level; scrubs e-mails, phones, IPs, tokens; source 'adc-marketing'
final class ADC_Template{ public static function render(string $name, array $args = [], bool $echo = true): string; } // theme override: {theme}/adc-marketing/{$name}.php
final class ADC_Frontend_Config
{
    public static function build(): array;               // base + apply_filters('adc_frontend_config', $base)
    public static function attach(string $script_handle): void; // wp_add_inline_script(..., 'before'); idempotent
}
final class ADC_Event_Id
{
    public static function random(): string;                              // 'adc_' + 16 hex  (server-origin, non-deterministic)
    public static function for_order(int $order_id, string $event = 'purchase'): string; // '{event}.{order_id}'  e.g. purchase.1234
    public static function is_valid(string $id): bool;                    // /^[A-Za-z0-9_.\-]{8,64}$/
}
```

**`ADC_Event`** (canonical, platform-neutral; GA4 naming; JSON-serialisable; **never holds raw PII**):

```php
final class ADC_Event
{
    public function __construct(
        public readonly string $name,            // GA4 canonical: page_view|view_item_list|view_item|add_to_cart|remove_from_cart|view_cart|begin_checkout|add_shipping_info|add_payment_info|purchase|refund|search|sign_up|login|generate_lead|identify|custom_*
        public readonly string $event_id,        // ADC_Event_Id
        public readonly int $time,               // unix seconds
        public readonly string $origin,          // 'server' | 'browser'
        public readonly string $source_url = '',
        public readonly string $currency = '',
        public readonly ?float $value = null,
        public readonly array $items = [],       // GA4 item objects (see 2.4)
        public readonly int $order_id = 0,
        public readonly ?ADC_User_Data $user = null,
        public readonly array $attribution = [], // fbp, fbc, ttp, ttclid, gclid, ga_client_id, ga_session_id, client_ip, user_agent
        public readonly string $cid = '',        // consent id (uuid) of the visitor/order
        public readonly array $consent = [],     // snapshot: preferences, statistics, marketing (bool), ts (int), decided (bool)
        public readonly array $params = []       // transaction_id, tax, shipping, coupon, search_term, method, ...
    ) {}
    public function to_array(): array;
    public static function from_array(array $data): self;
}
```

**`ADC_User_Data`** (`final readonly class`): all values already normalised + SHA-256 hex (lowercase) except `external_id` (hashed too). Fields: `em, ph, fn, ln, ct, st, zp, country, external_id` (nullable strings). Methods: `to_array(): array` (only non-null), `is_empty(): bool`, `merge(?self $other): self` (other wins), `static from_array(array $a): self` (drops values that are not 64-hex).

```php
final class ADC_Send_Result
{
    public function __construct(public readonly string $status /* sent|retry|dead|skipped */, public readonly int $http_code = 0, public readonly string $message = '') {} // message: scrubbed, <=255 chars
    public static function sent(int $code): self;  public static function retry(int $code, string $msg): self;
    public static function dead(int $code, string $msg): self;  public static function skipped(string $reason): self;
}

interface ADC_Integration_Interface
{
    public static function id(): string;                 // 'meta'|'ga4'|'sgtm'|'tiktok' (also settings group)
    public function label(): string;
    public function required_category(): string;         // 'marketing' | 'statistics'
    public function is_enabled(): bool;                  // server-side sending enabled + credentials present
    public function supports(string $event_name): bool;
    public function supersedes(): array;                 // platform ids whose SERVER sends this one replaces (sgtm mp_forward => ['ga4'])
    /** Map + HTTP. MUST attach $event->user only if $consent->allows('marketing'); MUST NOT contain consent/retry logic. */
    public function send(ADC_Event $event, ADC_Consent_State $consent): ADC_Send_Result;
    public function test(): ADC_Send_Result;             // admin "Test connection"; uses test_event_code where the platform has one
    public function browser_config(): array;             // merged into adcConfig.pixels.{id}: ['mode'=>'off|gtm|direct','id'=>..., ...] — no secrets
}
```

Registration: `add_filter('adc_integrations', fn(array $classes) => [...$classes, ADC_Integration_Meta::class])`, done in each integration's file-level boot (`ADC_Module_Interface` implemented by a tiny static `ADC_Integration_Meta::boot()` that adds the filter and registers its settings group).

**Admin shell / tabs**

```php
interface ADC_Admin_Tab_Interface
{
    public function slug(): string;      // 'banner','events','meta','ga4','sgtm','tiktok','matching','marketing','logs'
    public function label(): string;
    public function group(): string;     // '' or 'integrations' (rendered as sub-nav)
    public function order(): int;
    public function render(): void;      // inner HTML only; NO <form> tag, NO nonce (shell wraps them)
    public function save(array $post): array; // $post = wp_unslash($_POST); returns [['type'=>'success|error|warning','message'=>string], ...]; sanitize via ADC_Settings::update
}
```
`ADC_Admin_Shell`: top-level menu slug **`adc-marketing`** (unchanged URL `admin.php?page=adc-marketing`), icon `dashicons-email`, position 56, cap `manage_options`; tab = `&tab=<slug>`; tabs collected from filter `adc_admin_tabs` (array of class names); default tab = lowest `order()`. Saves via `admin-post.php?action=adc_save_tab&tab=<slug>`, nonce action `adc_save_tab_<slug>` (field `_adc_nonce`), cap re-checked, then redirect back with notices in a 60 s per-user transient (`adc_notices_{user_id}`). Tabs that need their own endpoints (export, test connection) register them themselves with their own nonce.
Tab order: banner 10, events 20, meta 30, ga4 40, sgtm 50, tiktok 60 (the last four `group()='integrations'`), matching 70, marketing 80, logs 90.

### 2.2 Consent

**Categories (fixed, no filter)**: `necessary` (always true), `preferences`, `statistics`, `marketing`.
**Consent Mode v2 mapping** (`ADC_Consent_Mode::map()`):

| Category | Google signals |
|---|---|
| necessary | `security_storage` = granted always |
| preferences | `functionality_storage`, `personalization_storage` |
| statistics | `analytics_storage` |
| marketing | `ad_storage`, `ad_user_data`, `ad_personalization` |

Global Privacy Control (`navigator.globalPrivacyControl` / `Sec-GPC: 1`) → `marketing=false` as default when `consent.honor_gpc`. Signal value on the wire: `'granted'|'denied'`.

**Cookie `adc_consent`** (first-party; set **both** client-side for immediacy and server-side via `Set-Cookie` in the `/consent` response because Safari ITP caps script-set cookies at 7 days):
`value = rawurlencode( http_build_query )` of  
`v=1&p=<0|1>&s=<0|1>&m=<0|1>&pv=<policy int>&ts=<unix>&cid=<uuid v4>&src=<banner|modal|api|gpc|account|checkout>`  
Attributes: `Path=/; SameSite=Lax; Secure` (when https); not HttpOnly; `Max-Age = consent.cookie_days*86400` (default 180); `Domain` unset by default (`consent.cookie_domain` optional for sub-domain sharing). Versioning: `v` is the **format** version (parser rejects unknown `v` ⇒ treated as undecided). `pv` is the **policy** version (`consent.policy_version`); `pv < current` ⇒ treated as undecided and banner re-prompts. `cid` is created **only when the visitor decides** (no pre-consent identifier).

```php
final readonly class ADC_Consent_State
{
    public function __construct(public bool $preferences = false, public bool $statistics = false, public bool $marketing = false,
        public bool $decided = false, public int $ts = 0, public int $policy = 0, public string $cid = '', public string $source = '') {}
    public function allows(string $category): bool;          // 'necessary' => true; unknown => false; !decided => false
    public function to_array(): array;                        // ['preferences'=>bool,...,'decided'=>bool,'ts'=>int,'policy'=>int,'cid'=>string,'source'=>string]
    public function to_cookie_value(): string;
    public static function from_cookie_value(string $raw): ?self; // null if malformed / unknown v / bad cid
    public static function denied(): self;                    // undecided, everything false
}

final class ADC_Consent implements ADC_Module_Interface        // facade
{
    public static function current(): ADC_Consent_State;      // request cookie → (logged-in) user meta if newer → denied(); never creates a cid
    public static function allows(string $category, ?ADC_Consent_State $state = null): bool;
    public static function policy_version(): int;
    /** Persist a decision: validates, writes log row, user meta (if logged in), cookie header (if headers not sent), fires actions. */
    public static function record(array $categories, string $source, array $ctx = []): ADC_Consent_State; // $categories: ['preferences'=>bool,'statistics'=>bool,'marketing'=>bool]
    public static function gcm_signals(?ADC_Consent_State $state = null): array;  // ['analytics_storage'=>'granted',...]
    public static function snapshot_to_order(WC_Order $order): void;  // meta _adc_consent_snapshot (json of state->to_array()) + _adc_consent_cid; no-op if already set
    public static function for_order(int|WC_Order $order): ?ADC_Consent_State;
    /** Newest of ($fallback snapshot, latest consent_log row for $cid). Used by the dispatcher at SEND time. */
    public static function effective(string $cid, ?ADC_Consent_State $fallback = null): ADC_Consent_State;
}
```
`ADC_Consent::boot()` registers: group `consent`, `rest_api_init`, `woocommerce_checkout_create_order` (prio 20) **and** `woocommerce_store_api_checkout_order_processed` → `snapshot_to_order`, `wp_login` (cookie↔user-meta hydration; newest `ts` wins), privacy exporter/eraser, daily cron `adc_consent_cleanup`.

`ADC_Consent_Mode`: `map(): array`, `render_head_script(): string` (the inline `<script>` block used by `ADC_Datalayer`; see 3.1), `regional_defaults(): array`.

**Actions**
* `do_action('adc_consent_changed', ADC_Consent_State $new, ?ADC_Consent_State $prev, string $source)` — after log row written.
* `do_action('adc_consent_withdrawn', ADC_Consent_State $new, array $revoked_categories)` — fired in addition when any category goes true→false; `ADC_Dispatcher` listens → `cancel_pending($cid, $revoked)`.

**REST** namespace `adc/v1` (public endpoints use `permission_callback => '__return_true'` **deliberately**: pages are cacheable so WP nonces would be stale; protection = strict arg validation, payload size cap 4 KB, rate limit via transient `adc_rl_{sha256(ip|route)}` 30 req/min, light HMAC token `X-ADC-Token` = `hash_hmac('sha256', floor(time()/604800), wp_salt('nonce'))` accepted for current and previous week, issued in `adcConfig.token`; responses `Cache-Control: no-store`).

| Route | Method | Body | Result |
|---|---|---|---|
| `/consent` | POST | `{preferences,statistics,marketing: 0\|1, source, policy:int}` | `200 {state: State}` + `Set-Cookie adc_consent`; logs; fires actions |
| `/consent` | GET | — | `200 {state}` (hydration for logged-in cross-device; no-store) |
| `/event` | POST | `{event_id, name, items:[{id,variation_id?,qty}], value?, currency?, source_url, params?}` | `202 {}`; requires valid consent cookie allowing ≥1 of statistics/marketing; server **rebuilds items/prices from catalog ids** (client prices ignored) |
| `/identify` | POST | `{email?, phone?, first_name?, last_name?, country?, source}` or `{source:'account'}` (logged-in) | `200 {user_data: {…hashed…}}`; `403 consent_required` if `marketing` not granted |

Admin AJAX (nonce `adc_admin`, cap `manage_options`): `adc_test_integration` (`platform` → `ADC_Send_Result` JSON), `adc_outbox_retry` (`id`), `adc_outbox_purge`.

### 2.3 JavaScript globals

`window.adcConfig` (inline JSON before `adc-consent`, built by `ADC_Frontend_Config`; keys owned: `consent` C2, `banner` C3, `gtm`/`events` C4, `matching`/`rest`/`token` C5, `pixels.{meta,tiktok,ga4,sgtm}` C6/C7):
```
{ v:1, debug:bool, rest:'https://site/wp-json/adc/v1/', token:'…',
  consent:{ cookie:'adc_consent', days:180, policy:N, gpc:bool, gcm:{preferences:[…],statistics:[…],marketing:[…]}, inventory:[{name,category,provider,purpose,duration,wildcard?}] },
  banner:{ …texts, layout, enabled }, gtm:{ id, mode, serverUrl }, events:{ <name>:bool, itemIdSource:'id|sku' },
  matching:{ enabled:bool, require:'marketing', callingCode:'40', selectors:[…] },
  pixels:{ meta:{mode,id}, tiktok:{mode,id}, ga4:{mode,id}, sgtm:{…} } }
```

**`window.adcConsent`** (C3, `assets/js/consent-manager.js`, script handle `adc-consent`, loaded in `<head>` with `strategy=defer`; works with no DOM dependency):
```
adcConsent.version                         // 1
adcConsent.get()                           // {categories:{necessary:true,preferences,statistics,marketing}, decided, ts, policy, cid, source}
adcConsent.has(category)                   // boolean; 'necessary' => true; !decided => false
adcConsent.whenAllowed(category, cb)       // cb() once, now or when granted; returns unsubscribe fn
adcConsent.onChange(cb)                    // cb(state, prev); returns unsubscribe fn
adcConsent.set({preferences?,statistics?,marketing?}, source='api') → Promise<State>
adcConsent.acceptAll(source?) / rejectAll(source?) / withdraw()   // withdraw = rejectAll + log action 'withdraw' + cookie cleanup
adcConsent.openPreferences()               // opens modal (no-op if banner disabled)
adcConsent.reset()                         // clear decision, re-prompt
adcConsent.gcm()                           // current Consent Mode signals map
```
On `set`: (1) write cookie, (2) `gtag('consent','update',…)`, (3) `dataLayer.push({event:'adc_consent_update',…})`, (4) dispatch `document` CustomEvent `adc:consent:change` (`detail:{state,prev}`), (5) `POST /consent` (keepalive; failure retried once, never blocks UI), (6) on any category true→false: delete cookies listed in `adcConfig.consent.inventory` for that category (exact + wildcard, current host and parent domain). `document` event `adc:consent:ready` fires once after init (`detail:{state}`).
Gated third-party markup (any plugin/theme): `<script type="text/plain" data-adc-category="marketing" src="…">` and `<iframe data-adc-category="marketing" data-src="…">` are activated when the category is granted (and re-blocked only on reload). PHP helper shortcode `[adc_consent_gate category="marketing"]…[/adc_consent_gate]`.

**`window.adcTrack`** (C4, `assets/js/datalayer.js`, handle `adc-datalayer`, deps `adc-consent`):
```
adcTrack.newEventId()                      // 'adc_' + 16 hex (crypto.getRandomValues)
adcTrack.event(name, data, opts)           // data = {currency?,value?,items?,params?}; opts = {eventId?, mirror:true|false(default per catalog), origin:'browser'}
                                           //   pushes {ecommerce:null} then the GA4-shaped event to dataLayer; if mirror && consent allows statistics|marketing → sendBeacon /event
adcTrack.pushServerEvents(list)            // used with server-origin events delivered via fragments/footer (already have event_id; never mirrored)
adcTrack.identify(fields, source)          // C5's identify.js implements; resolves with hashed user_data and pushes adc_identify
adcTrack.debug                              // true if ?adc_debug=1 and admin-bar user; logs every push
```
Pixel loaders (`adc-pixel-*`, deps `adc-datalayer`) read `adcConfig.pixels.<id>`; when `mode==='direct'` they load the vendor script **only inside** `adcConsent.whenAllowed(<required_category>, …)`, call the vendor consent API on change (→ PLATFORMS §Pixel consent APIs), and subscribe to dataLayer events via `adcTrack` (single event source, same `event_id`). `mode==='gtm'` ⇒ loader is a no-op (GTM owns the tag). `off` ⇒ not enqueued.

### 2.4 dataLayer event contract

Initial pushes, in this exact order, from inline head script (3.1): `consent default` command → (cookie valid) `consent update` command → `{event:'adc_consent_init', adc_consent:{…}, gcm:{…}}` → GTM snippet.

```js
// consent change
{ event:'adc_consent_update', adc_consent:{preferences:bool,statistics:bool,marketing:bool,decided:bool,ts:int,policy:int,source:'banner'}, gcm:{ad_storage:'granted',…} }
// page context (once per full page load, after adc_consent_init; cache-safe: no user data)
{ event:'adc_page', page_type:'home|product|category|cart|checkout|order_received|account|search|page|post', language:'ro-RO', currency:'RON', logged_in:bool }
// identity (only after /identify succeeded, i.e. consent.marketing==true) — field names follow GTM "user-provided data"
{ event:'adc_identify', user_data:{ sha256_email_address, sha256_phone_number, address:{ sha256_first_name, sha256_last_name, sha256_street?, city?, region?, postal_code?, country } }, external_id:'<sha256>' }
// e-commerce (GA4 standard names; each preceded by {ecommerce:null})
{ event:'add_to_cart', event_id:'adc_9f2c…', ecommerce:{ currency:'RON', value:49.9, items:[ITEM] }, adc:{ origin:'server|browser', page_type:'product' } }
```
`ITEM` = GA4 item: `item_id` (per `events.item_id_source`), `item_name`, `item_brand?`, `item_category`…`item_category5`, `item_variant?`, `price` (float, ex-tax unless `events.value_mode` says otherwise), `quantity`, `discount?`, `coupon?`, `index?`, `item_list_id?`, `item_list_name?`. Purchase adds `ecommerce.transaction_id`, `tax`, `shipping`, `coupon`.
Rules: `event_id` mandatory on every ecommerce event; no raw PII anywhere in `dataLayer`; custom namespace key `adc` for plugin metadata; dataLayer variable name configurable (`gtm.datalayer_name`, default `dataLayer`) — all JS reads it from `adcConfig`.

**Event catalogue** (name = GA4 canonical; platform names in → PLATFORMS §Event mapping):

| Event | Origin (cache-safe rule) | Trigger | Browser dL | Server mirror |
|---|---|---|---|---|
| page_view | browser | every page, after `adc_page` | yes | only if `events.server_page_view` (default off) |
| view_item_list | browser | category/shop/search/related loops (DOM data attrs) | yes | no |
| select_item | browser | click on product link in loop | yes | no |
| view_item | browser | single product (item data embedded in page, price-per-id cacheable) | yes | yes |
| add_to_cart | **server** for classic (AJAX `wc-ajax=add_to_cart` and non-AJAX), **browser** for Store API/blocks (`wc-blocks_added_to_cart`) | `woocommerce_add_to_cart` | yes | yes (server events are enqueued directly) |
| remove_from_cart | same split as add_to_cart | `woocommerce_remove_cart_item` | yes | no |
| view_cart | browser | cart page | yes | no |
| begin_checkout | browser (classic + blocks, page has no per-user data) | checkout page load | yes | yes |
| add_shipping_info / add_payment_info | browser | change of shipping/payment method | yes | payment_info only |
| purchase | **server**, id `purchase.<order_id>` | browser: order-received page (once per order, guarded by order meta `_adc_purchase_pushed`); server: order status ∈ `events.purchase_statuses` (once, guarded by `_adc_purchase_sent`) after `events.server_delay_seconds` (default 60) | yes | yes |
| refund | server, id `refund.<refund_id>` | `woocommerce_order_refunded` | no | yes (GA4 only) |
| search | server (id random, no browser counterpart needed) + browser push | `?s=` results | yes | yes |
| sign_up | server `sign_up.<user_id>` | `woocommerce_created_customer` / `user_register` | yes (next page) | yes |
| login | server | `wp_login` | yes (next page) | GA4 only |
| generate_lead | browser | newsletter/contact forms matched by `matching.form_selectors`; also `adcTrack.event('generate_lead',…)` | yes | yes |

Origin resolution rule (prevents double counting): for a given request exactly one origin emits the event. Server-origin events are placed in `ADC_Pending_Events` (WC session) and delivered to the browser via the `adc_events` cart fragment (classic AJAX) or an inline footer script on the next full page; the browser pushes them with the server's `event_id` and **never mirrors** them. Cached-page events (view_item, begin_checkout, …) have `event_id`s generated in the browser, so a cached HTML page never replays an old id.

### 2.5 Server pipeline

```php
final class ADC_Dispatcher implements ADC_Module_Interface
{
    /** Entry point; also bound to action `adc_track_event`. Returns platform => 'queued'|'skipped_no_consent'|'skipped_disabled'|'skipped_filtered'|'duplicate'. */
    public static function enqueue(ADC_Event $event): array;
    public static function process_job(int $job_id): void;            // Action Scheduler callback for hook `adc_dispatch_job`
    public static function cancel_pending(string $cid, array $revoked_categories): int; // marks pending rows 'skipped_no_consent'
    /** @return array<string, ADC_Integration_Interface> keyed by id, enabled only, supersedes applied */
    public static function integrations(): array;
}
```
`enqueue()` algorithm: for each enabled integration that `supports()` the event → require `consent->allows(required_category())` (state = `$event->consent` captured at creation) → apply `apply_filters('adc_event_should_send', true, $event, $platform)` → `ADC_Outbox::insert()` (UNIQUE `(event_id, platform)` ⇒ duplicate-safe) → `as_schedule_single_action($ts, 'adc_dispatch_job', ['job_id'=>$id], 'adc-marketing')` (`$ts = now`, purchase: `now + events.server_delay_seconds`). Fallback if Action Scheduler is missing: `wp_schedule_single_event`.
`process_job()`: load job → rebuild `ADC_Event::from_array(payload)` → **`ADC_Consent::effective($cid, snapshot)`** → re-check category (withdrawn meanwhile ⇒ `skipped_no_consent`) → `integration->send()` → `sent` ⇒ status `sent`, payload cleared (`NULL`), `sent_at`; `retry` ⇒ attempts+1, reschedule per `adc_outbox_retry_schedule` filter (default `[60, 300, 1800, 7200, 43200]` s) then `dead`; `dead` ⇒ `dead`. Never blocks a frontend request: **no HTTP in frontend requests**, only inside Action Scheduler runners.

```php
final class ADC_Outbox   // repository over {prefix}adc_event_outbox; uses $wpdb->prepare (custom table: documented exception to "no direct DB")
{
    public static function insert(ADC_Event $e, string $platform, int $scheduled_at): int; // 0 if duplicate
    public static function get(int $id): ?ADC_Outbox_Job;
    public static function mark(int $id, string $status, int $http_code = 0, string $error = '', bool $clear_payload = false): void;
    public static function schedule_retry(int $id, int $when, string $error, int $http_code): void;
    public static function recent(int $limit = 200, array $filters = []): array;      // for Logs tab
    public static function counts(): array;                                           // status => n
    public static function purge(int $sent_days, int $dead_days): int;
}

final class ADC_Advanced_Matching
{
    public static function normalize(string $field, string $value, string $country = ''): string; // em: trim+lowercase; ph: digits only, E.164 using matching.calling_code when no '+'/country; fn/ln/ct: lowercase, strip punctuation+whitespace; st/country: lowercase 2-letter; zp: lowercase no spaces
    public static function hash(string $normalized): string;                                       // hash('sha256', …) lowercase hex; '' for ''
    public static function from_array(array $raw, string $country = ''): ADC_User_Data;            // raw -> hashed (raw never leaves this call)
    public static function from_order(WC_Order $order): ADC_User_Data;
    public static function from_user(int $user_id): ADC_User_Data;
    public static function from_session(): ?ADC_User_Data;                                          // WC()->session 'adc_identity' (hashes only)
    public static function remember(ADC_User_Data $u): void;                                        // into WC session; creates customer session cookie lazily
    public static function enabled(): bool;                                                         // matching.enabled && consent not required to exist
}

final class ADC_Order_Context   // captures attribution at order creation (hook woocommerce_checkout_create_order prio 30 + store api hook)
{
    public static function capture(WC_Order $order): void; // meta _adc_attribution (json): stored ONLY fields allowed by snapshot: marketing ⇒ fbp,fbc,ttp,ttclid,gclid,client_ip,user_agent; statistics ⇒ ga_client_id,ga_session_id; plus source_url
    public static function read(WC_Order $order): array;
}
```
Capture hooks that feed identity as early as possible (server side, no JS needed): `woocommerce_checkout_update_order_review` (parses `post_data` for `billing_email|billing_phone|billing_first_name|billing_last_name|billing_country`, runs on every checkout field refresh) and `woocommerce_created_customer`, `wp_login` — all **no-op unless `ADC_Consent::allows('marketing')`**.

Hooks catalogue (all fixed contract names):

| Hook | Type | Args | Owner |
|---|---|---|---|
| `adc_register_settings` | action | — | C1 fires, all register |
| `adc_admin_tabs` | filter | `string[] $classes` | C1 |
| `adc_integrations` | filter | `string[] $classes` | C5 applies |
| `adc_frontend_config` | filter | `array $config` | C1 applies |
| `adc_consent_changed`, `adc_consent_withdrawn` | action | see 2.2 | C2 |
| `adc_track_event` | action | `ADC_Event` | C5 listens; C4/REST fire |
| `adc_event_should_send` | filter | `bool, ADC_Event, string $platform` | C5 |
| `adc_event_items` | filter | `array $items, WC_Product $product` | C4 |
| `adc_datalayer_event` | filter | `array $push, ADC_Event` | C4 |
| `adc_user_data` | filter | `ADC_User_Data, ADC_Event` | C5 |
| `adc_cookie_inventory` | filter | `array $rows` | C3 |
| `adc_outbox_retry_schedule` | filter | `int[] $seconds` | C5 |
| `adc_dispatch_job` | AS action | `int $job_id` | C5 |
| `adc_consent_cleanup`, `adc_outbox_cleanup` | cron (daily) | — | C2, C5 |

Constant kill-switch: `define('ADC_MARKETING_DISABLE_TRACKING', true)` ⇒ no dataLayer/GTM/pixel output, no server sends (banner and consent log still work).

---

## 3. Data flow diagrams

### 3.1 Page load (cache-safe: HTML identical for all visitors)
```
Browser ─GET /product/x→ WP (page cache may serve static HTML)
  <head> (wp_head priority 0, ADC_Datalayer::print_head)
    inline#1 (≈1.5 KB, no deps):
      window.dataLayer=[]; function gtag(){dataLayer.push(arguments)}
      gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',
                                functionality_storage:'denied',personalization_storage:'denied',security_storage:'granted',wait_for_update:500})
      [+ regional overrides from consent.regional_defaults]; gtag('set','url_passthrough',true); gtag('set','ads_data_redaction',true)
      c = parse(document.cookie.adc_consent); if valid(v=1) && c.pv>=policy → gtag('consent','update',mapped(c))
      dataLayer.push({event:'adc_consent_init',…})
    GTM snippet (if gtm.id and gtm.mode='always_with_defaults'; 'after_consent' ⇒ loaded by datalayer.js on first granted non-necessary category)
    <script src=consent-manager.js defer>  (adc-consent, with window.adcConfig inline before)
  footer: hidden banner markup (static), page context JSON (page_type, item data of THIS page — no user data)
  deferred JS: consent-manager.js init → read cookie → GPC check → show banner iff !decided or pv stale → fire adc:consent:ready
               datalayer.js: push adc_page; push browser-origin events (view_item …) with fresh adcTrack.newEventId()
               if has(statistics|marketing): sendBeacon POST /event {event_id,name,items:[{id,qty}]} ─→ ADC_Tracking_Rest
                  → validate token+rate limit+consent cookie → rebuild items from catalog → ADC_Event(origin 'browser', cid from cookie)
                  → do_action('adc_track_event') → ADC_Dispatcher::enqueue → outbox rows (per allowed platform) → AS job
               pixel loaders: mode 'direct' ⇒ whenAllowed(category) then load vendor script
```
GTM tags (Meta/GA4/TikTok in GTM) are gated by **Consent Mode settings on each tag** (built-in consent checks / additional consent), not by plugin code.

### 3.2 Add to cart (classic, non-cached because cart cookies bypass cache)
```
click → wc-ajax=add_to_cart ─→ WC adds item → hook woocommerce_add_to_cart
   ADC_Event_Hooks: build ADC_Event(add_to_cart, id=ADC_Event_Id::random(), origin 'server', consent=ADC_Consent::current(), user=from_session())
     ├─ ADC_Pending_Events::push(event)            (WC session)  ──→ response fragment `adc_events` ──→ adcTrack.pushServerEvents → dataLayer (same event_id)
     └─ do_action('adc_track_event') → Dispatcher::enqueue → (consent gate) → outbox → AS → platform (same event_id ⇒ platform dedup)
Store API / blocks: server hook sees is_rest_api_request ⇒ does NOT emit; JS `wc-blocks_added_to_cart` ⇒ adcTrack.event('add_to_cart', …, {mirror:true}) ⇒ beacon /event (server rebuilds from product id)
```

### 3.3 Checkout e-mail (advanced matching, earliest possible)
```
User types e-mail → blur/change (debounced 400 ms, regex-valid)
 A) server side, JS-free: WC AJAX update_order_review posts post_data on every field change
      hook woocommerce_checkout_update_order_review → if allows('marketing'): ADC_Advanced_Matching::from_array(parse post_data) → remember() (session, hashes only)
 B) client: identify.js → adcConsent.whenAllowed('marketing') → POST /identify {email,phone,first_name,last_name,country,source:'checkout'} (+token, same-origin, keepalive)
      server: token+rate limit; ADC_Consent::allows('marketing') else 403 consent_required; normalize+hash; remember(); response {user_data hashes}
      client: dataLayer.push({event:'adc_identify', user_data, external_id})  → GTM Enhanced Conversions / pixel advanced matching (direct mode: fbq init em / ttq.identify)
 C) logged-in: on first page of session identify.js sends {source:'account'} (no body); server derives from user record (sessionStorage flag, one call/session)
 D) other forms: selectors in matching.form_selectors (newsletter etc.) → on submit/blur same path with source 'form' (+ generate_lead event)
 No raw value is logged or stored; response never discloses whether the e-mail belongs to an account.
 Subsequent server events in the session (begin_checkout, add_payment_info, …) attach ADC_User_Data::from_session(); purchase uses from_order() (billing data) merged over session.
```

### 3.4 Purchase (browser + server, one event_id)
```
Checkout submit → woocommerce_checkout_create_order
    ADC_Consent::snapshot_to_order()   (prio 20)  → _adc_consent_snapshot, _adc_consent_cid
    ADC_Order_Context::capture()       (prio 30)  → _adc_attribution (only fields the snapshot permits)
    existing ADC_Consent_Checkout saves _adc_marketing_consent* (UNCHANGED, channel consent)
Order status → processing|completed (events.purchase_statuses), guard meta _adc_purchase_sent
    ADC_Event_Hooks: ADC_Event(purchase, id='purchase.<order_id>', origin 'server', value/tax/shipping from order, items from order,
                               user=from_order()+session merge, attribution=read(order), consent=for_order(order), cid)
    → adc_track_event → Dispatcher::enqueue (scheduled now+60 s)
    → AS: process_job → ADC_Consent::effective(cid, snapshot)  (user may have changed mind on thank-you page)
         → integration->send()  [Meta CAPI / GA4 MP / sGTM / TikTok Events API] with event_id=purchase.<order_id>
Order-received page (non-cacheable) → ADC_Event_Hooks: push purchase to dataLayer ONCE (guard _adc_purchase_pushed) with the SAME id
    → GTM tags / direct pixels fire browser side (Consent Mode / whenAllowed gate)
Platforms dedup browser+server on (event_name, event_id)  → PLATFORMS §Deduplication
```

### 3.5 Consent change / withdrawal
```
User toggles in modal/“Cookie settings” → adcConsent.set({...})
  1 write cookie  2 gtag consent update  3 dataLayer adc_consent_update  4 DOM event adc:consent:change
  5 POST /consent → ADC_Consent::record(): validate → insert adc_consent_log row (action grant|update|withdraw) → user meta _adc_cookie_consent (logged in)
                    → Set-Cookie header (ITP) → do_action('adc_consent_changed') [+ 'adc_consent_withdrawn' if any true→false]
  6 withdrawn: ADC_Dispatcher::cancel_pending(cid, revoked) ⇒ queued jobs for platforms whose category was revoked → 'skipped_no_consent'
  7 client: delete inventory cookies of revoked categories (_ga*, _gid, _fbp, _fbc, _ttp, _gcl_* …); pixel consent APIs (fbq consent revoke / ttq.revokeConsent / gtag denied)
  8 identity: if marketing revoked → ADC_Advanced_Matching session identity cleared on next request (hook on adc_consent_withdrawn)
Sent events are not recalled; stored order attribution is erased by privacy eraser/retention, not by withdrawal.
Granting later: only future events; past skipped rows stay skipped.
```

---

## 4. Settings schema

Storage: one option per group, `adc_settings_<group>` (array, `autoload=yes` for small groups, `no` for `banner`), written **only** through `ADC_Settings::update()`. Secrets stored sealed (`enc1:…`), never echoed back (UI shows "••••• (salvat)" + replace toggle), overridable by constant `ADC_<GROUP>_<KEY>` in `wp-config.php`. Other options: `adc_db_version`, `adc_marketing_consent_message` (**legacy, untouched**), `adc_migrations` (array of done migration ids).

| Group (owner · tab) | Key → default |
|---|---|
| `consent` (C2 · Banner) | `enabled`=false · `policy_version`=1 · `cookie_days`=180 · `cookie_domain`='' · `honor_gpc`=true · `regional_defaults`=[] (`[{regions:['US'],state:{analytics_storage:'granted',…}}]`) · `wait_for_update`=500 · `url_passthrough`=true · `ads_data_redaction`=true · `log_retention_months`=36 · `delete_data_on_uninstall`=false |
| `banner` (C3 · Banner) | `layout`=`box_bottom_left`\|`bar_bottom`\|`modal` (def. `bar_bottom`) · `button_order`=`accept_customize_reject` (def.)\|`customize_accept_reject`\|`accept_reject_customize` — DOM order = visual/tab order · `accept_gap`=16 (0–48 px, extra spacing after Accept; CSS caps it at 12% of the action row and gives each equal button half of it back as minimum width, so it can never break the line) · `title`='Folosim cookie-uri' · `message`=RO default · `accept_label`='Acceptă tot' · `reject_label`='Refuză tot' (**always shown, equal prominence**) · `customize_label`='Personalizează' · `save_label`='Salvează alegerile' · `category_labels`/`category_descriptions` (4 each) · `privacy_page_id`=0→WP privacy page · `cookie_page_id`=0 · `show_floating_button`=true · `colors`={bg,text,accent} · `inventory`=[ ] (built-in rows for _ga*, _gid, _fbp, _fbc, _ttp, _gcl_*, woocommerce_*, wp_woocommerce_session_*, adc_consent; editable) |
| `gtm` (C4 · Events) | `container_id`='' (GTM-XXXX) · `mode`=`always_with_defaults`\|`after_consent` (def. first) · `server_container_url`='' (sGTM first-party loader; used only if non-empty valid https) · `datalayer_name`='dataLayer' · `env_auth`='' · `env_preview`='' |
| `events` (C4 · Events) | per-event booleans (all true except `page_view`_server): `view_item_list, select_item, view_item, add_to_cart, remove_from_cart, view_cart, begin_checkout, add_shipping_info, add_payment_info, purchase, refund, search, sign_up, login, generate_lead` · `server_page_view`=false · `item_id_source`=`id`\|`sku`\|`gla` (def. `id`) · `value_mode`=`excl_tax_excl_shipping` (def.) \| `incl_tax` · `purchase_statuses`=['processing','completed'] · `server_delay_seconds`=60 · `exclude_roles`=['administrator','shop_manager'] · `brand_taxonomy`='product_brand' · `outbox_sent_days`=14 · `outbox_dead_days`=30 |
| `matching` (C5 · Advanced matching) | `enabled`=false · `require`='marketing' (fixed) · `capture_checkout`=true · `capture_account`=true · `form_selectors`='' (CSS selectors, one per line) · `calling_code`='40' · `send_to`=['meta','tiktok','ga4'] · `use_external_id`=true |
| `meta` (C6 · Integrări) | `enabled_server`=false · `browser_mode`=`off`\|`gtm`\|`direct` (def. `gtm` if GTM id else `off`) · `pixel_id`='' · `access_token`=secret · `test_event_code`='' · `api_version`=per PLATFORMS · `action_source`='website' |
| `tiktok` (C6) | `enabled_server`=false · `browser_mode` · `pixel_code`='' · `access_token`=secret · `test_event_code`='' |
| `ga4` (C7) | `enabled_server`=false · `browser_mode` · `measurement_id`='' · `api_secret`=secret · `send_when_denied`=false · `debug`=false (uses validation endpoint) · `fallback_client_id`=false |
| `sgtm` (C7) | `enabled`=false · `server_container_url`='' (**feature inert unless set**) · `mode`=`loader`\|`mp_forward` (def. `loader`: only changes GTM/gtag transport; `mp_forward`: server events go to the container, `supersedes()=['ga4']`) · `api_secret`=secret (if container requires) · `measurement_id` (falls back to `ga4`) |
| `logs` (C5 · Logs/Debug) | `level`=`error` (`off\|error\|info\|debug`) · `debug_console`=false (admin-only dataLayer console logging) |

Validation rules: IDs matched against vendor patterns (GTM `^GTM-[A-Z0-9]+$`, GA4 `^G-[A-Z0-9]+$`, Meta/TikTok numeric/alnum); server URLs: `https` only, `wp_http_validate_url`, host must resolve to a public IP (SSRF guard at save and at send), no redirects. Saving `consent` fields that change legal text (banner texts, categories, inventory) offers a "Cere din nou consimțământul (crește versiunea politicii)" checkbox that increments `policy_version`.

**Admin tabs (labels RO):** Banner consimțământ (`banner`) · Evenimente & GTM (`events`) · Integrări ▸ Meta (`meta`), GA4 (`ga4`), Server GTM (`sgtm`), TikTok (`tiktok`) · Potrivire avansată (`matching`) · Mesaje marketing & Export (`marketing`: existing consent message editor + export form + GDPR status table, moved verbatim) · Jurnale & Debug (`logs`: outbox table + status counts + retry/purge, consent-log lookup by `cid`, "Trimite eveniment test", conflict detector (duplicate GTM/GA/Meta snippets detected by scanning the home page HTML via `wp_remote_get`), Site Health test, live dataLayer console toggle).

**DB tables** (created by `ADC_Installer` with `dbDelta`; prefix `$wpdb->prefix`; charset from `$wpdb->get_charset_collate()`; all timestamps UTC):
```sql
adc_consent_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  cid char(36) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(16) NOT NULL,            -- grant|update|withdraw|reset|policy
  cat_preferences tinyint(1) NOT NULL DEFAULT 0,
  cat_statistics tinyint(1) NOT NULL DEFAULT 0,
  cat_marketing tinyint(1) NOT NULL DEFAULT 0,
  policy_version int(10) unsigned NOT NULL DEFAULT 1,
  text_hash char(64) NOT NULL DEFAULT '', -- sha256 of rendered banner text + category labels at that moment (proof of what was shown)
  source varchar(24) NOT NULL DEFAULT '',
  gpc tinyint(1) NOT NULL DEFAULT 0,
  ip_hash char(64) NOT NULL DEFAULT '',   -- HMAC-SHA256(ip, wp_salt('auth')) — pseudonymised, no raw IP
  ua_hash char(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id), KEY cid (cid), KEY user_id (user_id), KEY order_id (order_id), KEY created_at (created_at) )

adc_event_outbox (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id varchar(64) NOT NULL, platform varchar(16) NOT NULL, event_name varchar(40) NOT NULL,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0, cid char(36) NOT NULL DEFAULT '',
  status varchar(24) NOT NULL DEFAULT 'pending',   -- pending|sent|dead|skipped_no_consent
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  payload longtext NULL,                           -- ADC_Event::to_array() JSON (hashed identifiers only); NULLed after 'sent'
  http_code smallint(5) unsigned NOT NULL DEFAULT 0, last_error varchar(255) NOT NULL DEFAULT '',
  scheduled_at datetime NOT NULL, sent_at datetime NULL, created_at datetime NOT NULL,
  PRIMARY KEY  (id), UNIQUE KEY event_platform (event_id, platform), KEY status_sched (status, scheduled_at), KEY cid (cid), KEY order_id (order_id) )
```
Meta keys introduced: order `_adc_consent_snapshot`, `_adc_consent_cid`, `_adc_attribution`, `_adc_purchase_pushed`, `_adc_purchase_sent`; user `_adc_cookie_consent` (json of state). Existing `_adc_marketing_*` keys unchanged.

---

## 5. Work breakdown (8 chunks, 4 waves, disjoint file ownership)

Rules for every chunk: edit **only owned files**; read others only through the interfaces in §2; if a contract must change, stop and report (do not edit this doc or another chunk's files); new classes are discovered by the autoloader, so no one touches `class-adc-marketing.php` except C1 (which pre-lists **every** module class below, guarded by `class_exists`). Each chunk loads the `memoria` skill, logs one episodic event with `file_relationships`.

Verification common to all (`$FILES` = chunk's PHP files; `$WP` = WordPress path, optional): `for f in $FILES; do php -l "$f"; done` · `node --check <js>` for every owned JS file · `phpcs --standard=phpcs.xml.dist $FILES` if `phpcs` is on `PATH`/`vendor/bin` (else state "phpcs unavailable") · wp-cli smoke only when `$WP` is set.

| Wave | Chunk | Owned files (exclusive) | Depends on |
|---|---|---|---|
| **1** | **C1 Core + Admin shell** | `adc-marketing.php`, `uninstall.php`, `phpcs.xml.dist`, `bin/smoke.sh`, `includes/class-adc-marketing.php`, `includes/core/*` (autoloader, module iface, settings, secrets, installer, template, logger, frontend-config, event, event-id, user-data, send-result, outbox-job, integration iface), `includes/admin/class-adc-admin-shell.php`, `includes/admin/interface-adc-admin-tab.php`, `assets/css/admin-shell.css`, `assets/js/admin-shell.js` | this doc |
| 2 | **C2 Consent engine** | `includes/consent/class-adc-consent.php`, `-consent-state.php`, `-consent-store.php`, `-consent-rest.php`, `-consent-mode.php`, `-consent-privacy.php` | C1 |
| 2 | **C3 Banner & cookie manager** | `includes/consent/class-adc-consent-banner.php`, `includes/admin/class-adc-admin-tab-banner.php`, `assets/js/consent-manager.js`, `assets/js/consent-banner.js`, `assets/css/consent-banner.css`, `templates/consent/{banner,preferences,cookie-list}.php` | C1; contract C2 (REST + cookie format) |
| 2 | **C4 dataLayer & events** | `includes/tracking/class-adc-{datalayer,gtm,event-builder,event-hooks,pending-events}.php`, `includes/admin/class-adc-admin-tab-events.php`, `assets/js/datalayer.js`, `assets/js/events.js` | C1; contracts C2 (`ADC_Consent_Mode::render_head_script`, `ADC_Consent::current`), C5 (`adc_track_event`) |
| 2 | **C5 Pipeline, matching, logs** | `includes/tracking/class-adc-{dispatcher,outbox,tracking-rest,advanced-matching,order-context,retention}.php`, `includes/admin/class-adc-admin-tab-{matching,logs}.php`, `assets/js/identify.js`, `assets/js/admin-logs.js`, `assets/css/admin-logs.css` | C1; contracts C2, C4 |
| 2 | **C8 Legacy compat & docs** | `includes/class-adc-{consent-admin,consent-checkout,myaccount-marketing,marketing-export,gdpr-compliance}.php`, `includes/functions.php`, `includes/class-adc-channel-consent.php`, `includes/admin/class-adc-admin-tab-marketing.php`, `templates/myaccount/marketing-consent.php`, `assets/css/{checkout-consent,admin-settings,myaccount-marketing}.css`, `assets/js/{checkout-consent,admin-export}.js`, `languages/*`, `README.txt`, `GDPR-COMPLIANCE.md`, `AGENTS.md`, `docs/GHID-UTILIZARE.md` | C1 |
| 3 | **C6 Meta + TikTok** | `includes/integrations/class-adc-integration-{meta,tiktok}.php`, `includes/admin/class-adc-admin-tab-{meta,tiktok}.php`, `assets/js/pixels/{meta,tiktok}.js` | C1, C5, C2; `docs/research/PLATFORMS.md` |
| 3 | **C7 GA4 + sGTM** | `includes/integrations/class-adc-integration-{ga4,sgtm}.php`, `includes/admin/class-adc-admin-tab-{ga4,sgtm}.php`, `assets/js/pixels/ga4.js` | C1, C5, C2, C4 (`ADC_Gtm` for sGTM loader URL); PLATFORMS |
| **4** | **Verification & finalisation** (no new owner; C8 resumes for `languages/*` + `README.txt` regeneration; orchestrator runs checks) | — | all |

(`bin/smoke.sh <wp_path>` from C1 runs: `php -l` over all `*.php`, `node --check` over all `*.js`, phpcs if available, and the wp-cli checks below.)

**Per-chunk verification commands**

* **C1** — `php -l` on owned files; `phpcs --standard=phpcs.xml.dist adc-marketing.php includes/core includes/admin`; with `$WP`: `wp --path=$WP plugin activate adc-marketing && wp --path=$WP db query "SHOW TABLES LIKE '%adc_%'"` (expects `adc_consent_log`, `adc_event_outbox`), `wp --path=$WP option get adc_db_version` (= `1`), `wp --path=$WP eval 'var_dump(ADC_Settings::get("consent","cookie_days"));'` (= `int(180)`), `wp --path=$WP eval 'var_dump((new ReflectionClass("ADC_Event"))->isFinal());'`.
* **C2** — `php -l`; `wp eval '$s=ADC_Consent_State::from_cookie_value(rawurlencode("v=1&p=0&s=1&m=0&pv=1&ts=".time()."&cid=3f2a9b1c-1d2e-4f3a-8b4c-5d6e7f8a9b0c&src=banner")); var_dump($s->allows("statistics"), $s->allows("marketing"));'` (true,false); `wp eval 'var_dump(ADC_Consent_Mode::map()["marketing"]);'` (ad_storage, ad_user_data, ad_personalization); `curl -s -X POST $SITE/wp-json/adc/v1/consent -H 'Content-Type: application/json' -d '{"preferences":0,"statistics":1,"marketing":0,"source":"api","policy":1}' -i` (200 + `Set-Cookie: adc_consent=`); `wp db query "SELECT action,cat_statistics FROM wp_adc_consent_log ORDER BY id DESC LIMIT 1"`; malformed body ⇒ 400.
* **C3** — `php -l`, `node --check assets/js/consent-manager.js assets/js/consent-banner.js`; `wp eval 'echo strlen(ADC_Template::render("consent/banner.php",[],false)) > 0 ? "ok" : "empty";'`; load a page with `curl -s $SITE/ | grep -c 'adc-consent'` (≥1 when `consent.enabled`); manual/Playwright: `window.adcConsent.acceptAll()` ⇒ cookie `adc_consent` has `m=1`, `adc_consent_update` in `dataLayer`, `adcConsent.has('marketing')===true`; banner shows "Refuză tot" with same visual weight as "Acceptă tot".
* **C4** — `php -l`, `node --check assets/js/datalayer.js assets/js/events.js`; `curl -s $SITE/ | grep -n "adc_consent_init"` appears **before** `googletagmanager.com/gtm.js` (order check: `curl -s $SITE/ | grep -bo -e adc_consent_init -e 'gtm.js' | sort -t: -k1 -n`); `wp eval 'var_dump(ADC_Event_Id::for_order(12));'` (`purchase.12`); `wp wc shop_order create …` then `wp eval` calling `ADC_Event_Builder::from_order()` and asserting items/value; classic add-to-cart request returns fragment key `adc_events`.
* **C5** — `php -l`, `node --check assets/js/identify.js assets/js/admin-logs.js`; `wp eval 'var_dump(ADC_Advanced_Matching::hash(ADC_Advanced_Matching::normalize("em"," Test@Example.com ")));'` = `sha256("test@example.com")`; `…normalize("ph","0721 234 567")` with calling code 40 = `40721234567`; dispatcher unit smoke: `wp eval` enqueue the same `ADC_Event` twice ⇒ second returns `duplicate`; with consent denied ⇒ `skipped_no_consent` and **no** row with payload; `wp action-scheduler run --group=adc-marketing` then outbox row `sent|dead`; `curl POST /identify` without consent ⇒ 403.
* **C6/C7** — `php -l`, `node --check`; `wp eval 'var_dump((new ADC_Integration_Meta)->supports("purchase"));'`; **mock send** (filter `pre_http_request` returning 200) via `wp eval` calling `->send($event,$consent)` ⇒ `ADC_Send_Result` `sent`, and assert request body contains `event_id` = `purchase.<id>` and **no** raw e-mail (`strpos($body,'@')===false`); with `$consent->marketing=false` ⇒ body has no `user_data`; `wp eval 'var_dump((new ADC_Integration_Sgtm)->is_enabled());'` = `false` when URL empty; test-connection button path `wp eval 'echo json_encode((new ADC_Integration_Meta)->test());'` (uses `test_event_code`).
* **C8** — `php -l`; `phpcs`; `wp eval 'var_dump(class_exists("ADC_Channel_Consent"), has_action("woocommerce_review_order_before_submit"));'`; `wp post-type`/`wp wc shop_order list` unaffected; verify HPOS hooks: `wp eval 'var_dump(has_filter("manage_woocommerce_page_wc-orders_columns"));'` (non-false); `msgfmt -c languages/adc-marketing-ro_RO.po -o languages/adc-marketing-ro_RO.mo`; grep that no `get_post_meta(` on orders remains.

**Wave 4 end-to-end checks (orchestrator):** (1) fresh activation + upgrade-from-1.0.0 activation both leave current behaviour identical with all new features off; (2) enable banner → inline consent default precedes GTM; (3) place a classic order with `m=1` and with `m=0` → outbox rows exist only for allowed platforms, `purchase.<id>` identical in dataLayer and outbox payload; (4) withdraw before AS run → row `skipped_no_consent`; (5) HPOS on and off; (6) block checkout page renders, `begin_checkout` fires, order snapshot written via Store API hook; (7) `grep -rn "get_post_meta\|wp_remote_" includes` shows HTTP only inside integration `send()/test()`.

---

## 6. Security / GDPR checklist

**Access control & input**
- [ ] Every admin write: capability `manage_options` + nonce (`adc_save_tab_<slug>` / `adc_export_nonce` / `adc_admin`); no `$_POST` use before `wp_unslash` + sanitize; no `$_REQUEST`.
- [ ] Public REST routes: `__return_true` permission is justified in a code comment; each arg has `validate_callback` + `sanitize_callback`; body ≤ 4 KB; rate limit; token; `Cache-Control: no-store`; no user enumeration (identify never reveals account existence).
- [ ] All output escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`); inline JSON via `wp_json_encode($v, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)`; inline scripts through `wp_print_inline_script_tag` / `wp_add_inline_script` (CSP-nonce friendly).
- [ ] SQL only in `ADC_Installer`, `ADC_Consent_Store`, `ADC_Outbox` with `$wpdb->prepare`; no string-built queries.
- [ ] Server URLs (sGTM): https only, public-IP check, `redirection=>0`, timeout 5 s, response body capped.
- [ ] Secrets sealed at rest, constant override, never in JS config, never logged, never in URLs (tokens go in headers/body per PLATFORMS).

**Hashing / PII**
- [ ] Only SHA-256 of normalised values leave the site (platform-required exceptions: client IP + user agent for CAPI, sent only when `marketing` consent exists, taken from the order-time capture).
- [ ] Raw e-mail/phone/name exist only inside the request that supplied them; not in session, logs, outbox, dataLayer, transients. Session holds hashes only.
- [ ] No PII in logs: `ADC_Logger` scrubs e-mails, phone-like digit runs, IPv4/6, tokens; platform error strings truncated to 255 chars; outbox `last_error` goes through the same scrub.
- [ ] Consent log stores IP/UA as keyed HMAC, never raw.

**Consent semantics**
- [ ] Nothing non-necessary runs or is sent before a decision: Consent Mode defaults denied, no `cid` before decision, server sends gated on `ADC_Consent` at enqueue **and** at send time.
- [ ] Reject as easy as accept (same step, equal prominence), no pre-ticked boxes, no cookie wall, withdrawal always reachable (floating button, `[adc_consent_settings]`, My Account link), policy-version re-prompt, proof of consent (log + banner `text_hash`), GPC honoured. Default button order Acceptă · Setări · Respinge (`banner.button_order`); `ADC_Consent_Banner::prominence_audit()` warns the admin in the banner tab (on save + live in the preview) when the chosen colours make refusal harder to see than acceptance.
- [ ] Purchase consent = order snapshot, refreshed from the log at send time; guest and logged-in handled identically.
- [ ] Channel consents (e-mail/SMS) are not derived from cookie categories or vice-versa.
- [ ] WP privacy tools: exporter + eraser for `adc_consent_log` (by user), `_adc_attribution`, `_adc_consent_snapshot`, channel meta (fixes the broken `wp_ajax_adc_export_user_data` hook); `delete_user` cleans user meta (existing) plus log rows anonymised (`user_id=0`, `cid` kept as proof unless erasure requested for that cid).
- [ ] Retention: consent log `consent.log_retention_months`; outbox sent payload NULLed immediately, row purged after `outbox_sent_days`, dead after `outbox_dead_days`; attribution meta removed after 30 days via `ADC_Retention` (orders keep amounts/ids).

**Reliability / performance**
- [ ] No outbound HTTP on frontend requests; Action Scheduler group `adc-marketing`, exponential retry, `dead` after 5 attempts, 429/5xx retry, other 4xx dead; idempotent via UNIQUE `(event_id, platform)`.
- [ ] Frontend payload budget: head inline < 2 KB, `consent-manager.js` < 10 KB gz; banner CSS only loaded when enabled; no server work on cached HTML.
- [ ] HPOS: `FeaturesUtil::declare_compatibility('custom_order_tables')` retained; only CRUD order API; admin list hooks for both legacy and HPOS screens; cart/checkout blocks declared compatible (`cart_checkout_blocks`).
- [ ] Debug via `wc_get_logger()` only, never `error_log()` of payloads.

---

## 7. Migration plan (keep existing features working)

Principle: **zero behaviour change on upgrade**; every new capability starts disabled (`consent.enabled=false`, all integrations off, matching off).

1. **Upgrade routine** (`ADC_Installer::maybe_upgrade()` on `plugins_loaded`, compare `adc_db_version`): create two tables, register nothing else, record `adc_migrations`. No existing option or meta key is renamed or deleted. `adc_marketing_consent_message` stays the source of truth for the checkout checkbox text.
2. **Checkout checkbox** (`ADC_Consent_Checkout`): unchanged hooks and meta (`_adc_marketing_consent*`, `_adc_consent_text_version`). C8 additions: also write `_adc_consent_text_hash` (sha256), stop writing the raw IP for **new** orders (`_adc_consent_ip_address` ⇒ `_adc_consent_ip_hash`, legacy values left until eraser), and register the Store API/blocks counterpart as *out of scope for this release* (documented gap). The checkbox stays **optional** (GDPR-correct; `AGENTS.md` still says "required" and is corrected by C8).
3. **Admin menu**: `ADC_Admin_Shell` owns `add_menu_page('adc-marketing')`; `ADC_Consent_Admin::add_admin_menu()` becomes a no-op when `class_exists('ADC_Admin_Shell')`. Its settings form, GDPR status table and export form move into `ADC_Admin_Tab_Marketing` (content verbatim; fixes the current double-save: `options.php` form **plus** an inline `$_POST['submit']` handler). The GDPR notice screen id `toplevel_page_adc-marketing` is preserved. Order-screen items stay (single order box, user profile section).
4. **HPOS-correct order list**: C8 adds the HPOS variants (`manage_woocommerce_page_wc-orders_columns`, `manage_woocommerce_page_wc-orders_custom_column`, `woocommerce_order_list_table_restrict_manage_orders`, `woocommerce_order_list_table_prepare_items_query_args`) next to the legacy `manage_edit-shop_order_columns` / `manage_shop_order_posts_custom_column` / `restrict_manage_posts` / `woocommerce_admin_orders_query` ones (current code only has the legacy ones).
5. **My Account** (`ADC_MyAccount_Marketing`, endpoint `marketing-consent`, template `templates/myaccount/marketing-consent.php`): unchanged behaviour; template gains a second section "Preferințe cookie" that renders `do_shortcode('[adc_consent_settings]')` (empty if C3 inactive). Saving e-mail/SMS preferences continues to update `_adc_marketing_consent` per the existing rule.
6. **Export** (`ADC_Marketing_Export`, `admin_post_adc_export_*`, filenames, `;` CSV with BOM): unchanged.
7. **Channel vs cookie consent**: `ADC_Channel_Consent::has(int $user_id, 'email'|'sms'): bool` is a read-only wrapper over the existing meta, used only to add `adc.channel_email_optin` / `adc.channel_sms_optin` booleans to the `purchase`/`generate_lead` dataLayer `adc` object when cookie `marketing` is granted (for CRM triggers in GTM). It never writes cookie consent, and cookie consent never writes `_adc_marketing_*`.
8. **GDPR class fixes** (C8): `ADC_GDPR_Compliance::export_user_data(int)` is hooked to `wp_ajax_adc_export_user_data` with no argument (would throw `ArgumentCountError`) → replace by a `wp_privacy_personal_data_exporters` callback; keep `handle_user_deletion` and `verify_compliance`.
9. **Uninstall**: `uninstall.php` keeps current behaviour (does not delete user/order meta); additionally, only when `consent.delete_data_on_uninstall=true`, drops the two tables and `adc_settings_*` options.
10. **Rollback**: set `consent.enabled=false` (banner/CMP off) or `define('ADC_MARKETING_DISABLE_TRACKING', true)`; downgrading to 1.0.0 leaves extra tables/options inert.
11. **Compatibility notes**: warn in Logs/Debug when another plugin prints GTM/GA/Meta pixels (double firing) or another CMP is active (Complianz, Cookiebot, CookieYes) — modules do not auto-disable them; the admin chooses.

---

## 8. Assumptions & open points (for the orchestrator)

* Action Scheduler ships with WooCommerce 9.5+, so it is a hard dependency of the server pipeline (fallback to WP-Cron only as safety net).
* Default tab after upgrade is `banner` (lowest order). If the product owner wants the old landing page, set `ADC_Admin_Tab_Marketing::order()` to 5.
* Block-checkout marketing-email/SMS checkbox (Additional Checkout Fields API) is a follow-up, not in this plan; tracking and consent snapshot **do** support blocks.
* Item `price` semantics and `value` rules follow `events.value_mode`; GA4/Meta/TikTok value equality across browser and server is enforced by using one builder (`ADC_Event_Builder`) for both sides.
* Anything platform-specific (API versions, field limits, TikTok/Meta event names, GA4 MP batching/limits, sGTM request paths, vendor consent APIs) must be taken from `docs/research/PLATFORMS.md`; if it contradicts a literal value in this document, PLATFORMS wins and the integration chunk reports the delta in its episodic log.

---

## 9. Reconciliation with PLATFORMS.md

Reconciled 2026-10-18 against `docs/research/PLATFORMS.md` (retrieved 2026-10-18). Sections 1–8 above are **unchanged**; where this section differs from them, **§9 wins** (it is the verified value). Delta ids `D#` are used in chunk logs. API versions/endpoints are re-verified by the integration chunk at implementation time (PLATFORMS "currency warning").

### 9.1 Value deltas (ARCHITECTURE said X → correct value; chunks affected)

| # | Topic | ARCHITECTURE said | Correct (PLATFORMS) | Chunks |
|---|---|---|---|---|
| D1 | `event_id` format | `purchase.<order_id>`, `refund.<id>`, `sign_up.<id>` | **`purchase_<order_id>`**, `refund_<refund_id>`, `sign_up_<user_id>` (underscore; PLATFORMS §2.7/§4.5/candidate table). Same regex `^[A-Za-z0-9_.\-]{8,64}$` still valid. `ADC_Event_Id::for_order()` returns the underscore form. C4 verify expects `purchase_12`, not `purchase.12`. | C1, C4, C5, C6, C7 |
| D2 | Meta Graph version | "per PLATFORMS" | `api_version` default **`v26.0`** (latest at retrieval; Meta's own CAPI curl still shows v25.0 — both supported ≥2 yrs). Endpoint `POST https://graph.facebook.com/{ver}/{PIXEL_OR_DATASET_ID}/events`. Label the field "Pixel / Dataset ID". | C6 |
| D3 | Meta request shape/auth | "tokens in headers/body" | `access_token` is a **form field/query param** (no header option). Send as `application/x-www-form-urlencoded`/multipart fields `data=<json array>`, `access_token`, optional `test_event_code` in the **body**, never in the URL string, so logs/URLs stay clean. One event per request (a single invalid event rejects the whole batch; max 1,000). | C6 |
| D4 | Max event age | not specified | Meta: `event_time` ≤ **7 days** old else the whole request fails ⇒ `dead` without sending. GA4 MP: backdating ≤ **72 h** ⇒ `dead`. TikTok: no limit documented (use 7 d). Integrations return `ADC_Send_Result::dead(0,'event_too_old')`. | C5, C6, C7 |
| D5 | Hash normalisation is **platform-specific** | one `ADC_User_Data` (`em`,`ph`,…) shared by all platforms | Meta `ph` = digits incl. country code, **no `+`**; GA4 MP and TikTok `ph` = **`+`E.164** digits; GA4 `em` also strips dots before `@` for `gmail.com`/`googlemail.com` (Meta/TikTok: trim+lowercase only). GA4 `city/region/postal_code/country` are **plain** (not hashed). ⇒ `ADC_User_Data` gains `ph_e164` and `em_ga` (sha256 variants) next to `em`/`ph`; `ADC_Advanced_Matching::normalize()` gets 4th arg `string $platform = 'meta'` (`meta`\|`tiktok`\|`ga4`); `from_array()/from_order()` fill all variants. GA4 plain address fields are read from the order at send time (`$event->order_id`), never stored in session/outbox. Never reuse one hash across platforms. Names: `mb_strtolower`, keep UTF-8 letters (Romanian diacritics), drop punctuation/digits. | C1 (class), C5 (normalize), C6, C7 |
| D6 | GA4 purchase channel | purchase sent browser **and** server, "platforms dedup" | **GA4 has no documented MP↔gtag dedup** (only `transaction_id` against retries). Default: GA4 `purchase` from **one** channel. New `ga4.purchase_channel` = `browser` (default; server `supports('purchase')` returns false) \| `server` (then the browser GA4 tag/`ga4.js` must skip `purchase`; admin warning + GTM note). `refund` stays server-only. Meta/TikTok stay redundant browser+server (they dedup on `event_id`). `transaction_id` = order id on every GA4 purchase/refund. | C7, C4 (marker `adc.purchase_channel` in push), C8 (guide) |
| D7 | GA4 MP credentials | "never in URLs" | `measurement_id` + `api_secret` **must** be query params (`/mp/collect?measurement_id=&api_secret=`). Never log the URL; `ADC_Logger` scrubs `api_secret=`. Endpoint `https://www.google-analytics.com/mp/collect`; EU `https://region1.google-analytics.com/mp/collect` (new `ga4.endpoint_region`=`eu` default, `global` option); validation `…/debug/mp/collect` (only in `test()`/`ga4.debug`; prod endpoint gives no validation feedback). | C7 |
| D8 | GA4 MP body requirements | `client_id`/`session_id` captured | Also required: `engagement_time_msec` (default `100`) + `session_id` (regex `^\d+$`) in event params for real-time/reports; `timestamp_micros`; **explicit `consent` object on every MP request** `{ad_user_data, ad_personalization}` with **uppercase** `GRANTED`\|`DENIED` (gtag/dataLayer stay lowercase) so a server event never upgrades a denied browser; `user_data` only if `user_id` is sent and `ad_user_data=GRANTED` (guest purchases: omit `user_data`, send the rest). Limits: 25 events/request, 25 params/event, names ≤40 chars, param value ≤100 chars (truncate `item_name` etc.), items ≤200, body <130 kB, ≤10 custom params/item. | C7 |
| D9 | Platform event-name map (authoritative) | "see PLATFORMS" | See table 9.1a. `refund` → GA4 only. | C6, C7 |
| D10 | TikTok transport | unspecified | `POST https://business-api.tiktok.com/open_api/v1.3/event/track/`, header `Access-Token`, JSON body `{event_source:'web', event_source_id:<pixel code>, data:[…]}`; each event needs `event`, `event_time` (s), `user`, `page.url`, `event_id`. **HTTP 200 with `code != 0` is a failure** (parse body; success = `code:0`). `user.email`/`phone`(+E.164)/`external_id` hashed; `ip`, `user_agent`, `ttp` (`_ttp`), `ttclid` unhashed. Dedup key `event_source_id+event+event_id`, 48 h. Do not target Events API 1.0 endpoints. | C6 |
| D11 | Meta payload minimums | unspecified | Required: `event_name`, `event_time`, `action_source:'website'`, `event_source_url`, `client_user_agent`, `user_data`. An event whose only identifiers are ct/st/zp/country/ge/UA (or db+UA, fn+ge, ln+ge) is **invalid** ⇒ send only if at least one of `em`,`ph`,`external_id`,`fbp`,`fbc` is present, else `skipped('insufficient_user_data')` (no retry). `client_ip_address`, `client_user_agent`, `fbp`, `fbc` **never hashed**. `custom_data`: `content_ids`, `contents:[{id,quantity}]`, `content_type:'product'`, `value`, `currency`, `num_items`. **No LDU / `data_processing_options`** (US-state only; not an EU mechanism) and no `opt_out` — if marketing consent is absent, do not send. Setting `test_event_code` ⇒ events are *not dropped* ⇒ admin warning "remove in production". | C6 |
| D12 | Purchase `value` per platform | one `value` (`events.value_mode`) | GA4 `value` = Σ price×qty (+`tax`,`shipping` separate). Meta/TikTok Purchase `value` = order **total incl. tax/fees/shipping**. ⇒ `ADC_Event->value` stays the GA4 value; `params['order_total']` carries the total; dataLayer push adds `adc.order_total`; C6 + `meta.js`/`tiktok.js` use `order_total`; marketers map GTM Meta/TikTok tags to `adc.order_total` (documented in the guide). | C4, C6, C7, C8 |
| D13 | Consent snapshot vs send-time | `ADC_Consent::effective()` = **newest** of snapshot/log (late grants upgrade) | PLATFORMS §7.6: purchase must use consent **at order placement**. Safe rule: **`effective = snapshot ∧ latest-log`** — withdrawal after the order always wins; a grant *after* order creation does **not** upgrade (drop the "thank-you page grant" case from §3.4). `server_delay_seconds` stays (gateway status settling), no longer consent-related. | C2, C5 |
| D14 | Purchase send trigger | order status ∈ `purchase_statuses` | Add `woocommerce_payment_complete` **and** `woocommerce_order_status_changed` (to paid statuses) as triggers; COD/bank-transfer gateways never call `payment_complete`. Classic + block both covered by status/payment hooks (block checkout does **not** fire `woocommerce_checkout_order_processed`; snapshot/attribution use `woocommerce_checkout_create_order` + `woocommerce_store_api_checkout_order_processed` as already specified). Marker `_adc_purchase_sent` stores the unix timestamp; one `$order->save()` per flow. | C4 |
| D15 | Pixel consent APIs | `fbq consent revoke`, `ttq.revokeConsent` | **Meta** direct: marketing denied ⇒ **do not load the pixel at all**; on grant `fbq('consent','grant')` (verified); `fbq('consent','revoke')` is **not verified** ⇒ call only behind `typeof fbq==='function'`, and on withdrawal also stop all sends + delete `_fbp/_fbc`. **TikTok** direct (default opt-out flow): add `holdConsent/revokeConsent/grantConsent` to `ttq.methods`; `ttq.holdConsent()` **before** `ttq.load()`; `grantConsent()` on marketing allow (flushes queue); `revokeConsent()` on withdrawal; event id via `ttq.track(name, props, {event_id})`. With GTM mode, loaders are no-ops (and per TikTok must not call these APIs). | C6, C3 (cookie cleanup list) |
| D16 | Consent cookie lifetime | `cookie_days` default 180, no bound | Keep 180 (≈6 months, CNIL practice); **clamp 30–365**; **never auto-renew** (no sliding expiry; `GET /consent` must not extend); re-prompt at expiry or `pv` change. | C2 |
| D17 | WP Consent API | not mentioned | Add (only when `function_exists('wp_has_consent')` / JS `wp_set_consent`): declare `add_filter('wp_consent_api_registered_'.ADC_MARKETING_PLUGIN_BASENAME,'__return_true')`; `wp_get_consent_type` filter ⇒ `'optin'` (when `consent.enabled`); JS: set `window.wp_consent_type='optin'`, dispatch `wp_consent_type_defined`, on every change call `wp_set_consent(cat,'allow'\|'deny')`; map `necessary→functional`, `statistics→statistics` **and** `statistics-anonymous`, `preferences→preferences`, `marketing→marketing`; register inventory via `wp_add_cookie_info()`. Caution: with the API installed and no CMP active it returns `true` for everything — our CMP must be enabled. `ADC_Consent` stays the source of truth. | C2 (PHP), C3 (JS + inventory) |
| D18 | Proof of consent | `text_hash` only | EDPB: store **what was shown**. C3 appends each distinct banner text set to option `adc_consent_texts` (`hash => {ts, policy, texts{…}}`, autoload `no`, append-only); the log row's `text_hash` resolves into it; C2 exporter includes the text. C1 uninstall removes the option only under `delete_data_on_uninstall`. | C3, C2, C1 |
| D19 | Sign-off values already consistent | — | Consent Mode signals (`ad_storage`, `analytics_storage`, `ad_user_data`, `ad_personalization`, `granted\|denied`), `wait_for_update` 500, `url_passthrough` set before any config, `ads_data_redaction`, region scoping, advanced mode ≙ `gtm.mode=always_with_defaults`, `functionality/personalization/security_storage` (not covered by the digest; kept). Keep denied-everywhere default (`regional_defaults=[]`); add an "EEA+RO denied / rest granted" preset button. **Test checkout with `url_passthrough` on** (query-param risk). | C2, C3, Wave 4 |
| D20 | sGTM `mp_forward` | mode offered | PLATFORMS verifies only gtag `server_container_url` transport (GA4 client pre-installed); **sending MP payloads to the container is not documented there**. ⇒ `sgtm.mode='loader'` only by default; `mp_forward` hidden behind "experimental" and must be confirmed against Google docs by C7 before enabling. Inject `server_container_url` only when the user supplied it; hint when host is a provider default domain (e.g. `*.run.app`: no server-set cookie benefit). If the URL is removed, `process_job` re-checks `is_enabled()` ⇒ `skipped_disabled`. | C7, C5 |
| D21 | Identity in dataLayer | hashed `sha256_*` keys in `adc_identify` | Google's convention is **plain** `user_data` (the tag hashes; "do not double-hash"). Default stays **hashed** (invariant "no raw PII in `dataLayer`"; `sha256_*` keys are the verified MP names, GTM acceptance of pre-hashed values is **not verified here**). Add `matching.datalayer_format`=`hashed`(default)\|`plain` (plain only with marketing consent, documented trade-off). | C5 (setting), C4 (push) |
| D22 | Meta `external_id` | — | PLATFORMS suggests order id; we keep `sha256(user_id or cid)` (identifies the person); order id is the `event_id`. Deliberate non-adoption. | C5, C6 |
| D23 | Mandatory marketing checkbox | optional | PLATFORMS §7.3/7.6 suggests blocking via `onCheckoutValidation`. **Not adopted**: marketing consent must stay optional (GDPR); AGENTS.md "required" is corrected by C8. | C8 |
| D24 | Banner button order + colour freedom | free order, free colours, admin warned | Order is **Acceptă · Setări · Respinge** by default and configurable (`banner.button_order`), rendered in DOM order so tab/screen-reader order matches. Geometry stays locked equal (`.adc-consent-btn--equal`); the configurable `accept_gap` is margin-only. Colours remain free, but `ADC_Consent_Banner::prominence_audit()` measures them (WCAG 1.4.3 text 4.5:1, 1.4.11 non-text 3:1, blend 1.35:1, accept:reject prominence 4x) and the admin is told, on save, in the tab and live in the preview, that a de-emphasised „Refuză” (e.g. white on a white banner) can invalidate consent. Only the refusal side is audited — a shy Accept button is always conservative. | C3 (banner class, banner tab) |

**9.1a Platform event names (D9):**

| ADC / GA4 | Meta CAPI | TikTok | GA4 server |
|---|---|---|---|
| page_view | `PageView` *(not in digest; only if `server_page_view`)* | `Pageview` *(not in digest)* | opt. |
| view_item | `ViewContent` | `ViewContent` | opt. |
| add_to_cart | `AddToCart` | `AddToCart` | opt. |
| begin_checkout | `InitiateCheckout` | `InitiateCheckout` | opt. |
| add_payment_info | `AddPaymentInfo` | `AddPaymentInfo` | opt. |
| purchase | `Purchase` | `Purchase` | per D6 |
| sign_up | `CompleteRegistration` | `CompleteRegistration` | opt. |
| generate_lead | `Lead` | `Lead` (alt. `SubmitApplication`) | opt. |
| search | `Search` | `Search` | opt. |
| refund | — (D/U1) | — (U3) | yes (`transaction_id` required) |
| view_item_list, select_item, view_cart, remove_from_cart, add_shipping_info, login | — | — | GA4 only |

`PageView`/`Pageview` are not in PLATFORMS; default `server_page_view=false` keeps them unused. `add_shipping_info` is the GA4 name (PLATFORMS §8.2 prints `add_to_shipping_info`, a typo contradicted by its own §3.4). dataLayer keeps the `ecommerce:null` + wrapper pattern (§2.4); `ga4.js` direct mode flattens it to `gtag('event', name, {currency,value,items})`.

### 9.2 `UNVERIFIED` items and safe defaults

`grep -c UNVERIFIED` = 15 hits = 2 legend lines + 13 uses, i.e. **9 distinct decisions** (line refs → PLATFORMS):

| U | Item (PLATFORMS lines) | Safe default | Chunks |
|---|---|---|---|
| U1 | Meta refund (241, 1031, 1046) | Do **not** send refund to Meta (`supports('refund')=false`); no negative `Purchase`. | C6 |
| U2 | GA4 MP vs gtag dedup (378, 1042) | One channel for GA4 purchase (D6); `transaction_id` only guards retries. | C7, C4 |
| U3 | TikTok refund (521, 1031) | Do not send refund to TikTok. | C6 |
| U4 | RO retention number (720) | 6 months cookie (D16); consent log 36 months = **policy**, configurable, state in privacy text. | C2, C8 |
| U5 | `view_item` on block product page (795, 1048) | Emit **client-side** only (already browser-origin, §2.4); no PHP hook assumed. | C4 |
| U6 | `add_shipping_info`/`add_payment_info` server hook on blocks (799) | Client-side from block events (`onPaymentSetup`/shipping-rate events) with browser event_id; classic: browser change events; no server-origin for these. | C4 |
| U7 | `generate_lead` as Woo concept (803) | Never auto-fired; only via `matching.form_selectors` or explicit `adcTrack.event('generate_lead')`. | C4, C5 |
| U8 | HPOS orders-list filter hook name (887) | C8 confirms the four HPOS hooks (§7.4) in the running WC (`has_filter`/WC source). If a hook is absent: ship the **column**, drop the **filter**, keep legacy hooks. Display callbacks accept `WP_Post\|WC_Order`. | C8 |
| U9 | `ecommerce:null` + wrapper (941) | Keep wrapper + clear-before-push (§2.4); one style only. | C4, C7 |

Open questions 8–10 of PLATFORMS (legal basis for hashed PII / existing customers; `fbc` synthesis from `fbclid`; sGTM URL ownership) → defaults: server hashed PII only with `marketing` consent captured at order time; synthesize `fbc` from `fbclid` **only if marketing is granted at capture time** (C5 `ADC_Order_Context`, C6); sGTM per D20. The legal-basis question stays a product-owner decision.

### 9.3 Per-chunk addendum (what each chunk must apply from §9)

* **C1** — D1 (`for_order`), D5 (`ADC_User_Data` + `ph_e164`, `em_ga`), D18 (uninstall).
* **C2** — D13 (`effective`), D16 (clamp, no renew), D17 (WP Consent API PHP), D18 (exporter), D19 (preset).
* **C3** — D15 (inventory cookie cleanup incl. `_fbp,_fbc,_ttp,_ga*,_gcl_*`), D17 (JS bridge), D18 (text versions).
* **C4** — D1, D12 (`adc.order_total`, `adc.purchase_channel`), D14 (triggers), D21, U5–U7, U9.
* **C5** — D1, D4 (age guard in `process_job`), D5 (`normalize(…, $platform)`), D13, D20 (`is_enabled` re-check), D21/D22, `ADC_Tracking_Rest` fills `client_ip`/`user_agent` from the request for browser-origin events (marketing consent only). **Verify with official vectors:** Meta `em` `John_Smith@gmail.com`→`62a14e44f765419d10fea99367361a727c12365e2520f32218d505ed9aa0f62f`; Meta `ph` `(650)555-1212` (country US)→`16505551212`→`e323ec62…b3176`; `fn` `Mary`→`6915771b…f4ec`; TikTok `external_id` `user_123`→`80fba0ae…ab8b`; TikTok phone `(+1)2133734253`→`+12133734253`→`9f7ec22d…dd2fd`.
* **C6** — D1–D4, D10–D12, D15, D22, U1, U3. **C7** — D4, D6–D8, D20, U2. **C8** — D12 (guide), D23, U4, U8.
