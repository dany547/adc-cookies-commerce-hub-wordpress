<?php
/**
 * Offline tests for the "Evenimente custom" admin tab (docs/CUSTOM-EVENTS.md §6, §8.4).
 *
 * Loads the real tab, rule model and custom-events module. WordPress functions and the
 * shell/help/settings collaborators are stubbed in memory, so no database or WP core is needed.
 *
 * Usage: php tests/admin-tab-custom-events-test.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

define('ABSPATH', '/offline/');
define('ADC_MARKETING_PLUGIN_DIR', dirname(__DIR__) . '/');
define('ADC_MARKETING_PLUGIN_URL', 'https://site.test/wp-content/plugins/adc-marketing/');

/* ---------- WordPress function stubs (behaviour mirrors core where the tests depend on it) ---------- */

function __(string $text, string $domain = ''): string { return $text; }
function esc_html__(string $text, string $domain = ''): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_attr__(string $text, string $domain = ''): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_html_e(string $text, string $domain = ''): void { echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_attr_e(string $text, string $domain = ''): void { echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_attr(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_url(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_textarea(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_key(string $key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? ''; }
function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
function wp_parse_url(string $url, int $component = -1) { return parse_url($url, $component); }
function home_url(string $path = '/'): string { return 'https://site.test' . $path; }
function admin_url(string $path = ''): string { return 'https://site.test/wp-admin/' . $path; }
function add_query_arg(array $args, string $url): string { return $url . '?' . http_build_query($args); }
function wp_create_nonce(string $action): string { return 'nonce-' . $action; }
/** Only the nonce 'good-<action>' verifies; anything else fails. */
function wp_verify_nonce(string $nonce, string $action): int|false { return $nonce === 'good-' . $action ? 1 : false; }
function current_user_can(string $capability): bool { return true; }
/** Record asset enqueues so the enqueue_assets() tests can assert them. */
function wp_enqueue_style(string $handle, string $src = '', array $deps = array(), $ver = false, string $media = 'all'): void { $GLOBALS['enqueued'][] = array('type' => 'style', 'handle' => $handle, 'src' => $src); }
function wp_enqueue_script(string $handle, string $src = '', array $deps = array(), $ver = false, $args = array()): void { $GLOBALS['enqueued'][] = array('type' => 'script', 'handle' => $handle, 'src' => $src, 'deps' => $deps); }
/** Record localized data (the picker's adcPickerL10n object). */
function wp_localize_script(string $handle, string $object_name, array $l10n): bool { $GLOBALS['localized'][] = array('handle' => $handle, 'object' => $object_name, 'data' => $l10n); return true; }
/** Stand-in for the real ADC_Picker: only available() matters to the tab's enqueue logic. */
final class ADC_Picker
{
    public static bool $available = false;

    public static function available(): bool
    {
        return self::$available;
    }
}
function get_current_user_id(): int { return 1; }
function get_transient(string $key): mixed { return $GLOBALS['transients'][$key] ?? false; }
function set_transient(string $key, mixed $value, int $expiration = 0): bool { $GLOBALS['transients'][$key] = $value; return true; }
function delete_transient(string $key): bool { unset($GLOBALS['transients'][$key]); return true; }
function checked($checked, $current = true, bool $echo = true): string {
    $result = ((string) $checked === (string) $current) ? " checked='checked'" : '';
    if ($echo) { echo $result; }
    return $result;
}
function selected($selected, $current = true, bool $echo = true): string {
    $result = ((string) $selected === (string) $current) ? " selected='selected'" : '';
    if ($echo) { echo $result; }
    return $result;
}
function disabled($disabled, $current = true, bool $echo = true): string {
    $result = ((string) $disabled === (string) $current) ? " disabled='disabled'" : '';
    if ($echo) { echo $result; }
    return $result;
}

/* ---------- Collaborator stubs (shell, help, settings storage) ---------- */

final class ADC_Admin_Shell
{
    public const PAGE_SLUG = 'adc-marketing';
    public static function asset_version(string $file, ?string $version): string { return (string) $version; }
}

final class ADC_Admin_Help
{
    public static function section(string $title, string $description, string $id, string $body): void
    {
        echo '<h2>' . esc_html($title) . '</h2><p>' . esc_html($description) . '</p>' . $body;
    }
    public static function status(bool $on, string $on_label, string $off_label): string
    {
        return '<span class="adc-status adc-status--' . ($on ? 'on' : 'off') . '">' . esc_html($on ? $on_label : $off_label) . '</span>';
    }
    public static function icon(string $id, string $label, string $text): string { return ''; }
    public static function advanced_start(string $label): void { echo '<details><summary>' . esc_html($label) . '</summary>'; }
    public static function advanced_end(): void { echo '</details>'; }
}

/**
 * In-memory option store. Records every write so tests can assert "nothing written".
 * update() runs the group's real sanitizer (ADC_Custom_Events::sanitize), so a rule the
 * sanitizer drops is really dropped, as in WordPress. $GLOBALS['drop_writes'] simulates a failed write.
 */
final class ADC_Settings
{
    public static function all(string $group): array { return $GLOBALS['store'][$group] ?? array(); }
    public static function secret(string $group, string $key): string { return (string) ($GLOBALS['secrets'][$group . '.' . $key] ?? ''); }
    public static function update(string $group, array $input): array
    {
        $GLOBALS['writes'][] = array('group' => $group, 'input' => $input);
        $current = $GLOBALS['store'][$group] ?? array();
        if (!empty($GLOBALS['drop_writes'])) {
            return $current;
        }
        $GLOBALS['store'][$group] = ADC_Custom_Events::sanitize(array_replace($current, $input), $current);
        return $GLOBALS['store'][$group];
    }
}

/* ---------- Real code under test ---------- */

require ADC_MARKETING_PLUGIN_DIR . 'includes/admin/interface-adc-admin-tab.php';
require ADC_MARKETING_PLUGIN_DIR . 'includes/core/interface-adc-module.php';
require ADC_MARKETING_PLUGIN_DIR . 'includes/admin/trait-adc-admin-help-content.php';
require ADC_MARKETING_PLUGIN_DIR . 'includes/tracking/class-adc-custom-event-rule.php';
require ADC_MARKETING_PLUGIN_DIR . 'includes/tracking/class-adc-custom-events.php';
require ADC_MARKETING_PLUGIN_DIR . 'includes/admin/class-adc-admin-tab-custom-events.php';

/* ---------- Harness ---------- */

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function reset_state(): void
{
    $GLOBALS['store'] = array();
    $GLOBALS['writes'] = array();
    $GLOBALS['transients'] = array();
    $GLOBALS['secrets'] = array();
    $GLOBALS['drop_writes'] = false;
    $GLOBALS['enqueued'] = array();
    $GLOBALS['localized'] = array();
    ADC_Picker::$available = false;
    $_GET = array();
}

/** Return the full <input> tag of one platform checkbox in the rendered editor. */
function platform_checkbox(string $html, string $platform): string
{
    $pattern = '/<input type="checkbox" name="rule\[destinations\]\[platforms\]\[\]" value="' . preg_quote($platform, '/') . '"[^>]*>/';
    check(preg_match($pattern, $html, $m) === 1, "platform checkbox $platform not rendered");
    return $m[0];
}

/** Return the full <input> tag of one trigger-type radio in the rendered editor. */
function trigger_radio(string $html, string $type): string
{
    $pattern = '/<input type="radio" name="rule\[trigger\]\[type\]" value="' . preg_quote($type, '/') . '"[^>]*>/';
    check(preg_match($pattern, $html, $m) === 1, "trigger radio $type not rendered");
    return $m[0];
}

/** Store rules directly (bypasses save()), as if they had been saved earlier. */
function set_store(array $rules, int $revision = 1): void
{
    $GLOBALS['store'][ADC_Custom_Events::GROUP] = array('schema_version' => 1, 'revision' => $revision, 'rules' => $rules);
}

function stored_rules(): array
{
    return $GLOBALS['store'][ADC_Custom_Events::GROUP]['rules'] ?? array();
}

function stored_revision(): int
{
    return (int) ($GLOBALS['store'][ADC_Custom_Events::GROUP]['revision'] ?? 0);
}

function make_rule(array $overrides = array()): array
{
    return array_replace_recursive(array(
        'id' => 'ce_00000001',
        'label' => 'Telefon header',
        'name' => 'custom_phone_header',
        'enabled' => true,
        'trigger' => array(
            'type' => 'click', 'preset' => 'tel', 'selector' => '', 'match_text' => '', 'match_href' => '',
            'form_preset' => '', 'form_id' => '', 'threshold' => 0.5, 'min_visible_ms' => 0, 'depths' => array(), 'seconds' => 0,
        ),
        'fire' => 'once',
        'page_filter' => array('mode' => 'all', 'paths' => array()),
        'params' => array(),
        'value' => null,
        'currency' => '',
        'consent_category' => 'statistics',
        'destinations' => array('server_mirror' => false, 'platforms' => array()),
        'created' => 1,
        'updated' => 1,
    ), $overrides);
}

/** Minimal valid editor POST for a new click/tel rule. */
function editor_post(array $rule_overrides = array()): array
{
    $rule = array_replace_recursive(array(
        'id' => '',
        'label' => 'Telefon footer',
        'name_suffix' => 'phone_footer',
        'enabled' => '1',
        'trigger' => array('type' => 'click', 'preset' => 'tel'),
        'fire' => 'once',
        'page_filter' => array('mode' => 'all'),
        'consent_category' => 'statistics',
    ), $rule_overrides);
    return array('_adc_nonce' => 'good-adc_save_tab_custom-events', 'revision' => (string) stored_revision(), 'rule' => $rule);
}

function notice_messages(array $notices): array
{
    return array_map(static fn(array $n): string => (string) $n['message'], $notices);
}

function render_tab(array $get): string
{
    $_GET = $get;
    ob_start();
    (new ADC_Admin_Tab_Custom_Events())->render();
    return (string) ob_get_clean();
}

function validation_errors(array $raw, array $others = array()): array
{
    return ADC_Custom_Event_Rule::validate($raw, $others)['errors'];
}

/** A raw rule array that validates cleanly, for message-specific checks. */
function valid_raw(array $overrides = array()): array
{
    return array_replace_recursive(array(
        'id' => '',
        'label' => 'Test',
        'name' => 'custom_test_event',
        'enabled' => true,
        'trigger' => array('type' => 'click', 'preset' => 'tel'),
        'fire' => 'once',
        'page_filter' => array('mode' => 'all', 'paths' => array()),
        'params' => array(),
        'consent_category' => 'statistics',
        'destinations' => array('server_mirror' => false, 'platforms' => array()),
    ), $overrides);
}

$tests = 0;
function run(string $name, callable $fn): void
{
    global $tests;
    reset_state();
    try {
        $fn();
    } catch (Throwable $e) {
        fwrite(STDERR, "FAIL: $name: " . $e->getMessage() . ' [' . $e->getFile() . ':' . $e->getLine() . "]\n");
        exit(1);
    }
    $tests++;
}

/* ---------- (A1) Class, registration, status ---------- */

run('class exists and is a tab + module', static function (): void {
    $tab = new ADC_Admin_Tab_Custom_Events();
    check($tab instanceof ADC_Admin_Tab_Interface, 'does not implement ADC_Admin_Tab_Interface');
    check($tab instanceof ADC_Module_Interface, 'does not implement ADC_Module_Interface');
});

run('slug, label, group, order', static function (): void {
    $tab = new ADC_Admin_Tab_Custom_Events();
    check($tab->slug() === 'custom-events', 'slug must be custom-events');
    check($tab->label() === 'Evenimente custom', 'label must be Evenimente custom');
    check($tab->group() === '', 'group must be empty string');
    check($tab->order() === 22, 'order must be 22');
});

run('page_status is null without rules, true with an enabled rule, false when all disabled', static function (): void {
    $tab = new ADC_Admin_Tab_Custom_Events();
    set_store(array());
    check($tab->page_status() === null, 'no rules must give null');
    set_store(array(make_rule(array('enabled' => false))));
    check($tab->page_status() === false, 'only disabled rules must give false');
    set_store(array(make_rule(array('enabled' => false)), make_rule(array('id' => 'ce_00000002', 'name' => 'custom_other', 'enabled' => true))));
    check($tab->page_status() === true, 'one enabled rule must give true');
});

/* ---------- (A2) save(): guards ---------- */

run('save rejects a wrong nonce without writing', static function (): void {
    set_store(array(make_rule()));
    $tab = new ADC_Admin_Tab_Custom_Events();
    $post = array('_adc_nonce' => 'bad', 'revision' => '1', 'adc_ce_op' => 'delete:ce_00000001');
    $notices = $tab->save($post);
    check(count($notices) === 1 && $notices[0]['type'] === 'error', 'wrong nonce must return one error notice');
    check(count($GLOBALS['writes']) === 0, 'wrong nonce must not write');
    check(count(stored_rules()) === 1, 'rule must still exist');
});

run('save rejects a stale revision without any update', static function (): void {
    set_store(array(make_rule()), 3);
    $tab = new ADC_Admin_Tab_Custom_Events();
    $notices = $tab->save(array('_adc_nonce' => 'good-adc_save_tab_custom-events', 'revision' => '2', 'adc_ce_op' => 'toggle-off:ce_00000001'));
    check(notice_messages($notices) === array('Regulile au fost modificate între timp (de exemplu, în alt tab). Reîncarcă pagina și reaplică modificările.'), 'stale revision message must match §6.5 exactly');
    check(count($GLOBALS['writes']) === 0, 'stale revision must not call ADC_Settings::update');
    check(stored_rules()[0]['enabled'] === true, 'stale revision must not change the rule');
});

/* ---------- (A3) save(): list operations ---------- */

run('toggle-off disables the rule and advances the revision', static function (): void {
    set_store(array(make_rule()), 1);
    $notices = (new ADC_Admin_Tab_Custom_Events())->save(array('_adc_nonce' => 'good-adc_save_tab_custom-events', 'revision' => '1', 'adc_ce_op' => 'toggle-off:ce_00000001'));
    check(notice_messages($notices) === array('Regula a fost oprită.'), 'toggle-off message mismatch');
    check(stored_rules()[0]['enabled'] === false, 'rule must be disabled');
    check(stored_revision() === 2, 'revision must advance to 2');
});

run('duplicate creates a disabled _copy with a new id', static function (): void {
    set_store(array(make_rule()), 1);
    $notices = (new ADC_Admin_Tab_Custom_Events())->save(array('_adc_nonce' => 'good-adc_save_tab_custom-events', 'revision' => '1', 'adc_ce_op' => 'duplicate:ce_00000001'));
    check(notice_messages($notices) === array('Regula a fost duplicată (oprită).'), 'duplicate message mismatch');
    $rules = stored_rules();
    check(count($rules) === 2, 'duplicate must add one rule');
    $copy = $rules[1];
    check($copy['name'] === 'custom_phone_header_copy', 'copy name must end with _copy, got ' . $copy['name']);
    check($copy['enabled'] === false, 'copy must be disabled');
    check($copy['id'] !== 'ce_00000001' && preg_match('/^ce_[a-f0-9]{8}$/', $copy['id']) === 1, 'copy must get a new valid id');
});

run('duplicate picks a numeric suffix when _copy is taken', static function (): void {
    set_store(array(
        make_rule(),
        make_rule(array('id' => 'ce_00000002', 'name' => 'custom_phone_header_copy', 'enabled' => false)),
    ), 1);
    (new ADC_Admin_Tab_Custom_Events())->save(array('_adc_nonce' => 'good-adc_save_tab_custom-events', 'revision' => '1', 'adc_ce_op' => 'duplicate:ce_00000001'));
    $names = array_column(stored_rules(), 'name');
    check(in_array('custom_phone_header_copy2', $names, true), 'second copy must be custom_phone_header_copy2, got ' . implode(',', $names));
});

run('delete removes the rule', static function (): void {
    set_store(array(make_rule()), 1);
    $notices = (new ADC_Admin_Tab_Custom_Events())->save(array('_adc_nonce' => 'good-adc_save_tab_custom-events', 'revision' => '1', 'adc_ce_op' => 'delete:ce_00000001'));
    check(notice_messages($notices) === array('Regula a fost ștearsă.'), 'delete message mismatch');
    check(stored_rules() === array(), 'rule must be removed');
});

run('list operation on an unknown id is refused without writing', static function (): void {
    set_store(array(make_rule()), 1);
    $notices = (new ADC_Admin_Tab_Custom_Events())->save(array('_adc_nonce' => 'good-adc_save_tab_custom-events', 'revision' => '1', 'adc_ce_op' => 'delete:ce_ffffffff'));
    check(notice_messages($notices) === array('Regula selectată nu a putut fi găsită.'), 'unknown id message mismatch');
    check(count($GLOBALS['writes']) === 0, 'unknown id must not write');
});

/* ---------- (A4) save(): editor POST ---------- */

run('editor save creates a rule and absent checkboxes normalise to false / []', static function (): void {
    set_store(array(), 1);
    $post = editor_post();
    unset($post['rule']['enabled']);
    $notices = (new ADC_Admin_Tab_Custom_Events())->save($post);
    check(notice_messages($notices) === array('Regula a fost salvată.'), 'editor save message mismatch: ' . json_encode($notices));
    check(count($GLOBALS['writes']) === 1, 'editor save must write exactly once');
    $rule = stored_rules()[0];
    check($rule['enabled'] === false, 'absent enabled checkbox must store false');
    check($rule['destinations']['server_mirror'] === false, 'absent server_mirror must store false');
    check($rule['destinations']['platforms'] === array(), 'absent platform checkboxes must store []');
    check(stored_revision() === 2, 'editor save must advance the revision');
});

run('editor save with a checked enabled box stores true', static function (): void {
    set_store(array(), 1);
    (new ADC_Admin_Tab_Custom_Events())->save(editor_post());
    check(stored_rules()[0]['enabled'] === true, 'checked enabled must store true');
});

run('invalid editor save writes nothing and keeps a draft', static function (): void {
    set_store(array(), 1);
    $notices = (new ADC_Admin_Tab_Custom_Events())->save(editor_post(array('name_suffix' => 'phone_footer', 'label' => '')));
    check(notice_messages($notices)[0] === 'Denumirea internă este obligatorie (maxim 80 de caractere).', 'empty label message mismatch: ' . json_encode(notice_messages($notices)));
    check(count($GLOBALS['writes']) === 0, 'invalid save must not write');
    check(isset($GLOBALS['transients']['adc_ce_draft_1']), 'invalid save must keep a draft transient');
});

run('a 51st rule is rejected', static function (): void {
    $rules = array();
    for ($i = 0; $i < 50; $i++) {
        $rules[] = make_rule(array('id' => sprintf('ce_%08x', $i + 1), 'name' => 'custom_r' . $i, 'label' => 'R' . $i));
    }
    set_store($rules, 1);
    $notices = (new ADC_Admin_Tab_Custom_Events())->save(editor_post());
    check(notice_messages($notices) === array('Ai atins limita de 50 de reguli.'), 'limit message mismatch');
    check(count($GLOBALS['writes']) === 0, 'limit must not write');
    check(count(stored_rules()) === 50, 'rule count must stay 50');
});

run('invalid event name without prefix is rejected by the rule model', static function (): void {
    $errors = validation_errors(valid_raw(array('name' => 'phone_click')));
    check(in_array('Numele evenimentului poate conține doar litere mici, cifre și _ și trebuie să înceapă cu o literă.', $errors, true), 'missing-prefix name message mismatch: ' . json_encode($errors));
});

run('invalid event names from the editor are rejected', static function (): void {
    $expected = 'Numele evenimentului poate conține doar litere mici, cifre și _ și trebuie să înceapă cu o literă.';
    $bad_suffixes = array(
        'Phone' => 'uppercase',
        '1phone' => 'digit first',
        str_repeat('a', 34) => 'longer than 33 characters',
    );
    foreach ($bad_suffixes as $suffix => $why) {
        set_store(array(), 1);
        $notices = (new ADC_Admin_Tab_Custom_Events())->save(editor_post(array('name_suffix' => $suffix)));
        check(in_array($expected, notice_messages($notices), true), "name with $why was not rejected with the §6.6 message");
        check(count($GLOBALS['writes']) === 0, "name with $why must not write");
    }
    set_store(array(), 1);
    $notices = (new ADC_Admin_Tab_Custom_Events())->save(editor_post(array('name_suffix' => str_repeat('a', 33))));
    check(!in_array($expected, notice_messages($notices), true), '33-character suffix must be accepted');
});

run('selector containing "<" is rejected', static function (): void {
    $errors = validation_errors(valid_raw(array('trigger' => array('type' => 'click', 'preset' => '', 'selector' => 'a<script>'))));
    check(in_array('Selectorul CSS conține caractere nepermise sau depășește 200 de caractere.', $errors, true), 'selector with < not rejected with §6.6 message: ' . json_encode($errors));
});

/* ---------- (A5) Exact validation messages (§6.6) ---------- */

run('validation messages match §6.6 exactly', static function (): void {
    $cases = array(
        'Denumirea internă este obligatorie (maxim 80 de caractere).' => valid_raw(array('label' => '')),
        'Selectorul CSS este obligatoriu pentru acest declanșator.' => valid_raw(array('trigger' => array('type' => 'visible', 'selector' => ''))),
        'Alege cel puțin un prag de scroll.' => valid_raw(array('trigger' => array('type' => 'scroll_depth', 'depths' => array()))),
        'Durata trebuie să fie între 5 și 1800 de secunde.' => valid_raw(array('trigger' => array('type' => 'timer', 'seconds' => 3))),
        'Calea „foo” trebuie să înceapă cu / și nu poate conține parametri (?).' => valid_raw(array('page_filter' => array('mode' => 'include', 'paths' => array('foo')))),
        'Poți adăuga cel mult 10 parametri.' => valid_raw(array('params' => array_map(static fn(int $i): array => array('key' => 'p' . $i, 'value' => 'v'), range(1, 11)))),
        'Cheia „event” este rezervată sau nevalidă.' => valid_raw(array('params' => array(array('key' => 'event', 'value' => 'x')))),
        'Cheia „a” apare de două ori.' => valid_raw(array('params' => array(array('key' => 'a', 'value' => '1'), array('key' => 'a', 'value' => '2')))),
        'Token-ul {{scroll_percent}} nu este disponibil pentru declanșatorul ales.' => valid_raw(array('params' => array(array('key' => 'pct', 'value' => '{{scroll_percent}}')))),
        'Moneda este obligatorie când setezi o valoare.' => valid_raw(array('value' => 10, 'currency' => '')),
        'Meta cere categoria de consimțământ Marketing.' => valid_raw(array('destinations' => array('server_mirror' => false, 'platforms' => array('meta')), 'consent_category' => 'statistics')),
        'Numele evenimentului este deja folosit de altă regulă.' => valid_raw(array('name' => 'custom_taken')),
    );
    $others = array(make_rule(array('id' => 'ce_0000000a', 'name' => 'custom_taken')));
    foreach ($cases as $message => $raw) {
        $errors = validation_errors($raw, $others);
        check(in_array($message, $errors, true), 'missing exact message: ' . $message . ' | got: ' . json_encode($errors));
    }
});

/* ---------- (A6) Rendering ---------- */

run('empty list renders the empty state and the adc-ce root', static function (): void {
    set_store(array(), 1);
    $html = render_tab(array('page' => 'adc-marketing', 'tab' => 'custom-events'));
    check(str_contains($html, 'class="adc-ce"'), 'missing div.adc-ce root');
    check(str_contains($html, 'data-ce-view="list"'), 'list view attribute missing');
    check(str_contains($html, 'adc-ce-empty'), 'empty state class missing');
    check(str_contains($html, 'Nu ai încă evenimente custom.'), 'empty state text missing');
});

run('list with rules renders table rows and row operation buttons', static function (): void {
    set_store(array(make_rule()), 1);
    $html = render_tab(array('page' => 'adc-marketing', 'tab' => 'custom-events'));
    check(str_contains($html, 'adc-ce-table'), 'table class missing');
    check(str_contains($html, 'data-ce-id="ce_00000001"'), 'row data-ce-id missing');
    check(str_contains($html, 'value="toggle-off:ce_00000001"'), 'toggle button value missing');
    check(str_contains($html, 'value="duplicate:ce_00000001"'), 'duplicate button value missing');
    check(str_contains($html, 'value="delete:ce_00000001"'), 'delete button value missing');
    check(!str_contains($html, 'adc-ce-empty'), 'empty state must not render when rules exist');
});

run('editor renders div.adc-ce with data-ce attributes', static function (): void {
    set_store(array(make_rule()), 1);
    $html = render_tab(array('page' => 'adc-marketing', 'tab' => 'custom-events', 'rule' => 'ce_00000001'));
    check(str_contains($html, 'class="adc-ce"'), 'editor must render div.adc-ce');
    check(str_contains($html, 'data-ce-view="editor"'), 'editor view attribute missing');
    check(preg_match('/data-ce-ajax="[^"]+"/', $html) === 1, 'data-ce-ajax missing');
    check(preg_match('/data-ce-nonce="[^"]+"/', $html) === 1, 'data-ce-nonce missing');
    check(str_contains($html, 'data-ce-trigger="click"'), 'click fieldset missing');
    check(str_contains($html, 'data-ce-selector-field'), 'selector field missing');
});

run('rule values are escaped in list and editor output', static function (): void {
    $payload = '"><script>alert(1)</script>';
    set_store(array(make_rule(array(
        'label' => $payload,
        'trigger' => array('type' => 'click', 'preset' => '', 'selector' => '.buy', 'match_text' => $payload),
        'params' => array(array('key' => 'pk', 'value' => $payload)),
    ))), 1);
    foreach (array(array('page' => 'adc-marketing', 'tab' => 'custom-events'), array('page' => 'adc-marketing', 'tab' => 'custom-events', 'rule' => 'ce_00000001')) as $get) {
        $html = render_tab($get);
        check(!str_contains($html, '<script>'), 'unescaped <script> in output for ' . json_encode($get));
        check(!str_contains($html, '"><script'), 'unescaped attribute breakout in output for ' . json_encode($get));
    }
    $html = render_tab(array('page' => 'adc-marketing', 'tab' => 'custom-events', 'rule' => 'ce_00000001'));
    check(str_contains($html, '&quot;&gt;'), 'escaped payload must appear in editor output');
});

/* ---------- (A7) Destinations: platform_configured() drives the checkbox state ---------- */

/** Render the editor for one stored rule, with the given platform config in the store. */
function render_editor_for(array $rule): string
{
    set_store(array($rule), 1);
    return render_tab(array('page' => 'adc-marketing', 'tab' => 'custom-events', 'rule' => $rule['id']));
}

run('GA4 checkbox is enabled only when measurement_id and api_secret are set', static function (): void {
    $rule = make_rule(array('consent_category' => 'marketing'));
    $html = render_editor_for($rule);
    check(str_contains(platform_checkbox($html, 'ga4'), 'disabled'), 'GA4 must be disabled when not configured');
    $GLOBALS['store']['ga4'] = array('measurement_id' => 'G-ABC123');
    check(str_contains(platform_checkbox(render_editor_for($rule), 'ga4'), 'disabled'), 'GA4 without api_secret must stay disabled');
    $GLOBALS['secrets']['ga4.api_secret'] = 'secret-value';
    check(!str_contains(platform_checkbox(render_editor_for($rule), 'ga4'), 'disabled'), 'GA4 must be enabled when configured');
});

run('Meta checkbox is enabled only when pixel_id and access_token are set (marketing category)', static function (): void {
    $rule = make_rule(array('consent_category' => 'marketing'));
    check(str_contains(platform_checkbox(render_editor_for($rule), 'meta'), 'disabled'), 'Meta must be disabled when not configured');
    $GLOBALS['store']['meta'] = array('pixel_id' => '1234567890');
    $GLOBALS['secrets']['meta.access_token'] = 'token-value';
    check(!str_contains(platform_checkbox(render_editor_for($rule), 'meta'), 'disabled'), 'Meta must be enabled when configured under marketing');
});

run('sGTM checkbox is enabled only when enabled and server_container_url are set', static function (): void {
    $rule = make_rule(array('consent_category' => 'marketing'));
    check(str_contains(platform_checkbox(render_editor_for($rule), 'sgtm'), 'disabled'), 'sGTM must be disabled when not configured');
    $GLOBALS['store']['sgtm'] = array('enabled' => true, 'server_container_url' => '');
    check(str_contains(platform_checkbox(render_editor_for($rule), 'sgtm'), 'disabled'), 'sGTM without URL must stay disabled');
    $GLOBALS['store']['sgtm'] = array('enabled' => true, 'server_container_url' => 'https://sgtm.example.test');
    check(!str_contains(platform_checkbox(render_editor_for($rule), 'sgtm'), 'disabled'), 'sGTM must be enabled when configured');
});

run('existing parameter rows render a remove button each', static function (): void {
    $rule = make_rule(array('params' => array(array('key' => 'a', 'value' => '1'), array('key' => 'b', 'value' => '2'))));
    $html = render_editor_for($rule);
    check(substr_count($html, 'adc-ce-remove-param') === 2, 'expected 2 remove buttons, got ' . substr_count($html, 'adc-ce-remove-param'));
});

/* ---------- (A8) Duplicate: long labels, persist failure, limit ---------- */

/** Count UTF-8 characters without mbstring (not installed on every test host). */
function utf8_len(string $value): int
{
    return preg_match_all('/./us', $value, $matches) ?: 0;
}

run('duplicate of a 73-character label writes a valid extra rule', static function (): void {
    set_store(array(make_rule(array('label' => str_repeat('a', 73)))), 1);
    $notices = (new ADC_Admin_Tab_Custom_Events())->save(array('_adc_nonce' => 'good-adc_save_tab_custom-events', 'revision' => '1', 'adc_ce_op' => 'duplicate:ce_00000001'));
    check(notice_messages($notices) === array('Regula a fost duplicată (oprită).'), 'duplicate message mismatch');
    $rules = stored_rules();
    check(count($rules) === 2, 'duplicate of a 73-char label must add a rule, got ' . count($rules));
    check(utf8_len($rules[1]['label']) <= 80, 'copy label must be at most 80 characters');
    check(str_ends_with($rules[1]['label'], ' — copie'), 'copy label must end with the copy suffix');
});

run('duplicate of an 80-character label writes a valid extra rule within the 80-character limit', static function (): void {
    set_store(array(make_rule(array('label' => str_repeat('b', 80)))), 1);
    (new ADC_Admin_Tab_Custom_Events())->save(array('_adc_nonce' => 'good-adc_save_tab_custom-events', 'revision' => '1', 'adc_ce_op' => 'duplicate:ce_00000001'));
    $rules = stored_rules();
    check(count($rules) === 2, 'duplicate of an 80-char label must add a rule, got ' . count($rules));
    check(utf8_len($rules[1]['label']) === 80, 'copy label must be exactly 80 characters, got ' . utf8_len($rules[1]['label']));
});

run('duplicate reports an error and keeps the list when the write does not persist', static function (): void {
    set_store(array(make_rule()), 1);
    $GLOBALS['drop_writes'] = true;
    $notices = (new ADC_Admin_Tab_Custom_Events())->save(array('_adc_nonce' => 'good-adc_save_tab_custom-events', 'revision' => '1', 'adc_ce_op' => 'duplicate:ce_00000001'));
    check(notice_messages($notices) === array('Modificările nu au putut fi salvate. Reîncarcă pagina și încearcă din nou.'), 'failed persist must show the error, got ' . json_encode(notice_messages($notices)));
    check(count(stored_rules()) === 1, 'failed persist must leave one rule');
});

run('duplicate at 50 rules is refused with the limit message', static function (): void {
    $rules = array();
    for ($i = 0; $i < 50; $i++) {
        $rules[] = make_rule(array('id' => sprintf('ce_%08x', $i + 1), 'name' => 'custom_r' . $i, 'label' => 'R' . $i));
    }
    set_store($rules, 1);
    $notices = (new ADC_Admin_Tab_Custom_Events())->save(array('_adc_nonce' => 'good-adc_save_tab_custom-events', 'revision' => '1', 'adc_ce_op' => 'duplicate:ce_00000001'));
    check(notice_messages($notices) === array('Ai atins limita de 50 de reguli.'), 'duplicate at 50 must return the limit message');
    check(count(stored_rules()) === 50, 'duplicate at 50 must keep 50 rules');
});

/* ---------- (A9) Editor: presets, malformed input, stored values ---------- */

run('?rule=<id>&preset=timer does not change the type of an existing rule', static function (): void {
    set_store(array(make_rule()), 1);
    $html = render_tab(array('page' => 'adc-marketing', 'tab' => 'custom-events', 'rule' => 'ce_00000001', 'preset' => 'timer'));
    check(str_contains(trigger_radio($html, 'click'), 'checked'), 'click must stay selected');
    check(!str_contains(trigger_radio($html, 'timer'), 'checked'), 'timer must not be selected by the URL preset');
    check(str_contains($html, '<select id="adc-ce-click-preset" name="rule[trigger][preset]">'), 'click preset select missing');
});

run('?preset[]=x does not throw in the editor', static function (): void {
    $html = render_tab(array('page' => 'adc-marketing', 'tab' => 'custom-events', 'rule' => 'new', 'preset' => array('x')));
    check(str_contains($html, 'data-ce-view="editor"'), 'editor must still render with an array preset');
});

run('a stored threshold of 0.3 is shown as a selected "(valoare existentă)" option', static function (): void {
    $rule = make_rule(array('trigger' => array('type' => 'visible', 'threshold' => 0.3, 'selector' => '#hero', 'min_visible_ms' => 0)));
    $html = render_editor_for($rule);
    check(str_contains($html, 'value="0.3" selected=\'selected\'') || str_contains($html, 'value="0.3" selected="selected"'), 'stored 0.3 must be the selected option');
    check(str_contains($html, '30% (valoare existentă)'), 'stored 0.3 must have the "(valoare existentă)" label');
    check(!str_contains($html, 'value="0.1" selected'), 'the 10% option must not be selected for 0.3');
});

/* ---------- (A10) Assets and status labels ---------- */

run('enqueue_assets loads CSS and JS only on the custom-events tab of the plugin screen', static function (): void {
    $_GET = array('page' => 'adc-marketing', 'tab' => 'custom-events');
    ADC_Admin_Tab_Custom_Events::enqueue_assets('toplevel_page_adc-marketing');
    check(count($GLOBALS['enqueued']) === 2, 'expected CSS + JS enqueued, got ' . count($GLOBALS['enqueued']));
    check(str_contains($GLOBALS['enqueued'][0]['src'], 'css/admin-custom-events.css'), 'CSS src mismatch');
    check(str_contains($GLOBALS['enqueued'][1]['src'], 'js/admin-custom-events.js'), 'JS src mismatch');
});

run('enqueue_assets does nothing on another hook or another tab', static function (): void {
    $_GET = array('page' => 'adc-marketing', 'tab' => 'custom-events');
    ADC_Admin_Tab_Custom_Events::enqueue_assets('settings_page_adc-marketing');
    check($GLOBALS['enqueued'] === array(), 'other hook must not enqueue');
    $_GET = array('page' => 'adc-marketing', 'tab' => 'analytics');
    ADC_Admin_Tab_Custom_Events::enqueue_assets('toplevel_page_adc-marketing');
    check($GLOBALS['enqueued'] === array(), 'other tab must not enqueue');
    $_GET = array('page' => 'adc-marketing');
    ADC_Admin_Tab_Custom_Events::enqueue_assets('toplevel_page_adc-marketing');
    check($GLOBALS['enqueued'] === array(), 'missing tab must not enqueue');
});

run('picker assets are enqueued only on the custom-events tab and only when ADC_Picker::available()', static function (): void {
    $_GET = array('page' => 'adc-marketing', 'tab' => 'custom-events');

    // Unavailable (admin and site on different origins): only the tab's own CSS + JS, as before.
    ADC_Picker::$available = false;
    ADC_Admin_Tab_Custom_Events::enqueue_assets('toplevel_page_adc-marketing');
    check(count($GLOBALS['enqueued']) === 2, 'unavailable picker: expected exactly 2 enqueues, got ' . count($GLOBALS['enqueued']));
    check(!in_array('adc-picker-protocol', array_column($GLOBALS['enqueued'], 'handle'), true), 'unavailable picker must not enqueue the protocol');
    check($GLOBALS['localized'] === array(), 'unavailable picker must not localize');

    // Available on the tab: protocol, admin picker, picker CSS, and adcPickerL10n.
    $GLOBALS['enqueued'] = array();
    ADC_Picker::$available = true;
    ADC_Admin_Tab_Custom_Events::enqueue_assets('toplevel_page_adc-marketing');
    $enqueued = $GLOBALS['enqueued'];
    check(count($enqueued) === 5, 'available picker: expected 5 enqueues (CSS, JS, protocol, picker JS, picker CSS), got ' . count($enqueued));
    $protocol = $enqueued[2];
    check($protocol['handle'] === 'adc-picker-protocol' && str_contains($protocol['src'], 'js/picker-protocol.js'), 'protocol handle/src mismatch');
    $picker = $enqueued[3];
    check($picker['handle'] === 'adc-admin-picker' && str_contains($picker['src'], 'js/admin-picker.js'), 'admin picker handle/src mismatch');
    check($picker['deps'] === array('adc-picker-protocol', 'adc-admin-custom-events'), 'admin picker must depend on protocol and the tab script');
    $picker_css = $enqueued[4];
    check($picker_css['handle'] === 'adc-admin-picker' && str_contains($picker_css['src'], 'css/admin-picker.css'), 'picker CSS handle/src mismatch');
    check(count($GLOBALS['localized']) === 1, 'expected one localized object, got ' . count($GLOBALS['localized']));
    $l10n = $GLOBALS['localized'][0];
    check($l10n['handle'] === 'adc-admin-picker' && $l10n['object'] === 'adcPickerL10n', 'adcPickerL10n must be attached to adc-admin-picker');
    foreach (array('title', 'loading', 'timeout', 'popupBlocked', 'openPopup', 'rateLimit', 'tokenError') as $key) {
        check(isset($l10n['data'][$key]) && is_string($l10n['data'][$key]) && '' !== $l10n['data'][$key], "adcPickerL10n is missing a non-empty '$key'");
    }

    // Available, but another tab: nothing from the picker.
    $GLOBALS['enqueued'] = array();
    $GLOBALS['localized'] = array();
    $_GET = array('page' => 'adc-marketing', 'tab' => 'analytics');
    ADC_Admin_Tab_Custom_Events::enqueue_assets('toplevel_page_adc-marketing');
    check($GLOBALS['enqueued'] === array() && $GLOBALS['localized'] === array(), 'picker must not load on another tab');
});

run('page_status_labels returns the Activ / Inactiv pair', static function (): void {
    check((new ADC_Admin_Tab_Custom_Events())->page_status_labels() === array('Activ', 'Inactiv'), 'status labels mismatch');
});

echo "Admin custom-events tab tests passed ($tests cases).\n";
