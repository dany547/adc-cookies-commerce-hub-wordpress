<?php
/**
 * Checkbox state guard for admin tabs.
 *
 * 1. Static scan: no includes/ file may call checked(<expr>, false, false).
 *    That form inverts the check for boolean checkboxes (the output is
 *    `checked` when the setting is OFF). The correct form is
 *    checked(<expr>, true, false).
 * 2. Render check: the GA4, Server GTM and Advanced matching tabs render
 *    their boolean checkboxes with `checked` when the setting is true and
 *    without it when the setting is false.
 *
 * WordPress functions and the ADC settings/help services are stubbed here so
 * the tab classes can be rendered offline.
 *
 * Usage: php tests/admin-checkbox-state-test.php
 */

declare(strict_types=1);

define('ABSPATH', '/x/');
define('ADC_MARKETING_PLUGIN_DIR', dirname(__DIR__) . '/');

// Turn warnings and notices into failures: a stray undefined key must not pass silently.
set_error_handler(static function (int $no, string $message): bool {
    throw new ErrorException($message, 0, $no);
});

// --- WordPress function stubs (same semantics as core, for the calls used here) ---
function checked($checked, $current = true, bool $echo = true): string
{
    $result = (string) $checked === (string) $current ? " checked='checked'" : '';
    if ($echo) {
        echo $result;
    }
    return $result;
}
function selected($selected, $current = true, bool $echo = true): string
{
    $result = (string) $selected === (string) $current ? " selected='selected'" : '';
    if ($echo) {
        echo $result;
    }
    return $result;
}
function __(string $text, string $domain = 'default'): string { return $text; }
function esc_html__(string $text, string $domain = 'default'): string { return htmlspecialchars($text, ENT_QUOTES); }
function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES); }
function esc_attr(string $text): string { return htmlspecialchars($text, ENT_QUOTES); }
function esc_textarea(string $text): string { return htmlspecialchars($text, ENT_QUOTES); }
function wp_unslash($value) { return $value; }
function admin_url(string $path = ''): string { return 'http://example.test/wp-admin/' . $path; }
function esc_url(string $url): string { return htmlspecialchars($url, ENT_QUOTES); }
function current_user_can(string $cap): bool { return true; }
function wp_create_nonce(string $action = ''): string { return 'nonce'; }
function wp_json_encode($value, int $flags = 0): string|false { return json_encode($value, $flags); }
function wp_script_is(string $handle, string $list = 'enqueued'): bool { return false; }
function wp_add_inline_script(...$args): bool { return true; }
function add_action(...$args): bool { return true; }
function add_filter(...$args): bool { return true; }

// --- Plugin service stubs (defined before any autoload can run) ---
interface ADC_Module_Interface {}

interface ADC_Admin_Tab_Interface
{
    public function slug(): string;
    public function label(): string;
    public function group(): string;
    public function order(): int;
    public function render(): void;
    public function save(array $post): array;
}

final class ADC_Settings
{
    /** @return array<string, mixed> */
    public static function all(string $group): array
    {
        // Non-checkbox keys read by the tabs: empty defaults, so only the booleans under test vary.
        $defaults = array(
            'browser_mode'          => '',
            'endpoint_region'       => '',
            'purchase_channel'      => '',
            'measurement_id'        => '',
            'mode'                  => '',
            'server_container_url'  => '',
            'calling_code'          => '',
            'datalayer_format'      => '',
            'form_selectors'        => '',
            'send_to'               => array(),
            'container_id'          => '',
            'datalayer_name'        => '',
            'env_auth'              => '',
            'env_preview'           => '',
        );
        return array_replace($defaults, $GLOBALS['adc_test_settings'][$group] ?? array());
    }

    public static function secret(string $group, string $key): string
    {
        return '';
    }

    public static function update(string $group, array $value): void
    {
    }
}

/**
 * Renders label + control rows as plain markup, so the controls reach the output.
 */
final class ADC_Admin_Help
{
    public static function section(string $title, string $intro, ?string $help_id = null, ?string $help_body = null): void
    {
        echo '<h2>' . esc_html($title) . '</h2>';
    }

    public static function row(string $label, string $control, string $desc = '', ?string $for = null, ?string $help_id = null, ?string $help_body = null): void
    {
        echo '<div class="adc-help-row">' . $control . '</div>';
    }

    public static function advanced_start(string $summary = '', string $hint = '', bool $open = false): void
    {
        echo '<details' . ($open ? ' open' : '') . '>';
    }

    public static function advanced_end(): void
    {
        echo '</details>';
    }

    public static function status(bool $on, ?string $on_label = null, ?string $off_label = null): string
    {
        return $on ? 'Activ' : 'Inactiv';
    }
}

final class ADC_Integration_Sgtm
{
    public static function server_container_url(): string
    {
        return '';
    }
}

/** Minimal shell double: only the page slug constant the tabs reference when building internal links. */
final class ADC_Admin_Shell
{
    public const PAGE_SLUG = 'adc-marketing';
}

require ADC_MARKETING_PLUGIN_DIR . 'includes/admin/trait-adc-admin-help-content.php';
require ADC_MARKETING_PLUGIN_DIR . 'includes/admin/class-adc-admin-tab-ga4.php';
// The GTM tab renders the gtm + sgtm groups; the legacy sgtm tab delegates to it.
require ADC_MARKETING_PLUGIN_DIR . 'includes/admin/class-adc-admin-tab-gtm.php';
require ADC_MARKETING_PLUGIN_DIR . 'includes/admin/class-adc-admin-tab-sgtm.php';
require ADC_MARKETING_PLUGIN_DIR . 'includes/admin/class-adc-admin-tab-matching.php';

$failed = 0;

/**
 * Reports one check result.
 *
 * @param bool   $ok      Whether the check passed.
 * @param string $message Description printed on success or failure.
 */
function report(bool $ok, string $message): void
{
    global $failed;
    echo ($ok ? 'OK      ' : 'FAIL    ') . $message . "\n";
    if (!$ok) {
        $failed++;
    }
}

// --- 1. Static scan for the inverted checked(…, false, false) form ---

/**
 * Regex for the inverted form: checked(<args>, false, false).
 * Allows one level of nested parentheses inside the first argument.
 */
const ADC_INVERTED_CHECKED_PATTERN = '/\bchecked\s*\((?:[^()]|\([^()]*\))*,\s*false\s*,\s*false\s*\)/';

// Self-check of the pattern, so a broken regex cannot make the scan pass silently.
report(
    1 === preg_match(ADC_INVERTED_CHECKED_PATTERN, "checked(!empty(\$s['enabled']), false, false)"),
    'pattern detects checked(…, false, false)'
);
report(
    0 === preg_match(ADC_INVERTED_CHECKED_PATTERN, "checked(!empty(\$s['enabled']), true, false)"),
    'pattern ignores checked(…, true, false)'
);
report(
    0 === preg_match(ADC_INVERTED_CHECKED_PATTERN, "checked(in_array(\$slug, \$sel, true), true, false)"),
    'pattern ignores checked(in_array(…), true, false)'
);

$offenders = array();
$iterator  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ADC_MARKETING_PLUGIN_DIR . 'includes', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || 'php' !== $file->getExtension()) {
        continue;
    }
    $contents = (string) file_get_contents($file->getPathname());
    if (preg_match_all(ADC_INVERTED_CHECKED_PATTERN, $contents, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as [$snippet, $offset]) {
            $line        = substr_count(substr($contents, 0, (int) $offset), "\n") + 1;
            $relative    = substr($file->getPathname(), strlen(ADC_MARKETING_PLUGIN_DIR));
            $offenders[] = $relative . ':' . $line . ' ' . $snippet;
        }
    }
}
report(array() === $offenders, 'includes/ has no checked(…, false, false) on boolean checkboxes' . (array() === $offenders ? '' : "\n        " . implode("\n        ", $offenders)));

// --- 2. Render: checkboxes reflect enabled=true / enabled=false ---

/**
 * Returns whether the input with the given name is rendered `checked`.
 *
 * @return bool|null null when the input is not in the markup at all.
 */
function adc_test_is_checked(string $html, string $name): ?bool
{
    if (1 !== preg_match('/<input[^>]*\bname="' . preg_quote($name, '/') . '"[^>]*>/', $html, $m)) {
        return null;
    }
    return str_contains($m[0], "checked='checked'");
}

// 'boxes' maps each settings group to the checkbox input names (as in the markup) it renders.
$cases = array(
    array(
        'class' => 'ADC_Admin_Tab_Ga4',
        'boxes' => array('ga4' => array('enabled_server', 'fallback_client_id', 'send_when_denied', 'debug')),
    ),
    array(
        'class' => 'ADC_Admin_Tab_Gtm',
        'boxes' => array(
            'gtm'  => array('gtm[web_enabled]'),
            'sgtm' => array('sgtm[enabled]'),
        ),
    ),
    array(
        'class' => 'ADC_Admin_Tab_Matching',
        'boxes' => array('matching' => array('enabled', 'capture_checkout', 'capture_account', 'use_external_id')),
    ),
);

foreach ($cases as $case) {
    foreach (array(true, false) as $enabled) {
        // Each group gets every boolean under test set to $enabled.
        $GLOBALS['adc_test_settings'] = array();
        foreach ($case['boxes'] as $group => $names) {
            $settings = array();
            foreach ($names as $name) {
                $key              = preg_replace('/^\w+\[(\w+)\]$/', '$1', $name);
                $settings[$key]   = $enabled;
            }
            $GLOBALS['adc_test_settings'][$group] = $settings;
        }

        ob_start();
        (new $case['class']())->render();
        $html = (string) ob_get_clean();

        foreach ($case['boxes'] as $names) {
            foreach ($names as $name) {
                $state = adc_test_is_checked($html, $name);
                $label = $case['class'] . ' ' . $name . ' enabled=' . ($enabled ? 'true' : 'false');
                if (null === $state) {
                    report(false, $label . ' — input not rendered');
                    continue;
                }
                report($state === $enabled, $label . ' — ' . ($state ? 'checked' : 'not checked'));
            }
        }
    }
}

echo $failed > 0 ? "FAIL: {$failed} check(s) failed\n" : "PASS\n";
exit($failed > 0 ? 1 : 0);
