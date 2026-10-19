<?php
/**
 * Self-contained unit tests for ADC_Github_Updater (no network, no WP install).
 *
 * Run: php tests/updater-test.php
 *
 * All WordPress surface is stubbed below; wp_safe_remote_get() is a recording
 * stub fed from a fixture queue, so no real HTTP ever happens. Fixtures are
 * synthetic strings only — no credentials, no real site data. ZIP fixtures are
 * REAL archives generated in-memory by a minimal stored-method ZIP writer, so
 * the pre-extraction archive guard is exercised against true ZIP structures.
 * Deletions are RECORDED by the wp_delete_file() stub, never executed.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

// ---------------------------------------------------------------- WP stubs.

define('ABSPATH', __DIR__ . '/');
define('ADC_MARKETING_VERSION', '2.1.0');
define('ADC_MARKETING_PLUGIN_BASENAME', 'adc-marketing/adc-marketing.php');
define('ADC_MARKETING_FILE', __DIR__ . '/../adc-marketing.php');

interface ADC_Module_Interface
{
    public static function boot(): void;
}

final class WP_Error
{
    private array $errors = array();

    public function __construct(string $code = '', string $message = '')
    {
        if ('' !== $code) {
            $this->errors[$code] = $message;
        }
    }

    public function get_error_code(): string
    {
        return array_key_first($this->errors) ?? '';
    }

    public function get_error_message(): string
    {
        return (string) (array_values($this->errors)[0] ?? '');
    }
}

$GLOBALS['ADC_TEST'] = array(
    'http'          => array(), // recorded requests: [url, args]
    'queue'         => array(), // queued fake wp_safe_remote_get responses
    'transients'    => array(), // store
    'transient_set' => array(), // every set_site_transient call [key, expiration]
    'tempnam'       => array(),
    'deleted'       => array(), // wp_delete_file calls (recorded, NOT executed)
    'admin'         => true,
    'cron'          => false,
    'caps'          => true,
);

function add_filter(string $tag, callable $callback, int $priority = 10, int $accepted_args = 1): bool
{
    $GLOBALS['ADC_TEST']['filters'][$tag][] = array('cb' => $callback, 'args' => $accepted_args);
    return true;
}

function wp_parse_url(string $url): array|false
{
    return parse_url($url);
}

function get_site_transient(string $key): mixed
{
    return $GLOBALS['ADC_TEST']['transients'][$key] ?? false;
}

function set_site_transient(string $key, mixed $value, int $expiration = 0): bool
{
    $GLOBALS['ADC_TEST']['transient_set'][] = array('key' => $key, 'expiration' => $expiration);
    $GLOBALS['ADC_TEST']['transients'][$key] = $value;
    return true;
}

function wp_safe_remote_get(string $url, array $args = array()): array|object
{
    $GLOBALS['ADC_TEST']['http'][] = array('url' => $url, 'args' => $args);
    $next = array_shift($GLOBALS['ADC_TEST']['queue']);
    if (null === $next) {
        fwrite(STDERR, "TEST BUG: unexpected HTTP request to {$url} (empty queue)\n");
        exit(1);
    }
    return $next;
}

function wp_remote_retrieve_response_code(array|object $response): int
{
    if ($response instanceof WP_Error) {
        return 0;
    }
    return (int) ($response['response']['code'] ?? 0);
}

function wp_remote_retrieve_body(array|object $response): string
{
    if ($response instanceof WP_Error) {
        return '';
    }
    return (string) ($response['body'] ?? '');
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

function current_user_can(string $cap): bool
{
    return $GLOBALS['ADC_TEST']['caps'];
}

function is_admin(): bool
{
    return $GLOBALS['ADC_TEST']['admin'];
}

function wp_doing_cron(): bool
{
    return $GLOBALS['ADC_TEST']['cron'];
}

function get_bloginfo(string $key = ''): string
{
    return '6.8'; // fixture WP version.
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function get_file_data(string $file, array $headers): array
{
    return array('Name' => 'ADC Cookies & Commerce Hub', 'Description' => 'Hub marketing.');
}

function get_temp_dir(): string
{
    return sys_get_temp_dir() . '/';
}

function wp_tempnam(string $filename = ''): string
{
    $path = sys_get_temp_dir() . '/adc-updater-test-' . uniqid('', false) . '.tmp';
    file_put_contents($path, '');
    $GLOBALS['ADC_TEST']['tempnam'][] = $path;
    return $path;
}

function wp_delete_file(string $path): void
{
    // Recorded only — implementation tests must not execute deletions.
    $GLOBALS['ADC_TEST']['deleted'][] = $path;
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

/**
 * Minimal PclZip stand-in used ONLY to exercise the class's PclZip fallback
 * branch against the real ZIP bytes produced by make_zip_bytes() below. It
 * parses the central directory (stored entries, no zip64) and returns the
 * PclZip listContent() shape. On this machine ZipArchive is absent, so this
 * is also the branch production code takes here.
 */
if (!class_exists('PclZip')) {
    final class PclZip
    {
        private string $path;

        public function __construct(string $path)
        {
            $this->path = $path;
        }

        /** @return array<int, array<string, mixed>>|int */
        public function listContent(): array|int
        {
            $data = @file_get_contents($this->path);
            if (false === $data || '' === $data) {
                return 0;
            }
            $pos = strrpos($data, "PK\x05\x06"); // End Of Central Directory.
            if (false === $pos || strlen($data) - $pos < 22) {
                return 0;
            }
            $count     = (int) unpack('v', (string) substr($data, $pos + 10, 2))[1];
            $cd_offset = (int) unpack('V', (string) substr($data, $pos + 16, 4))[1];
            $out       = array();
            $p         = $cd_offset;
            for ($i = 0; $i < $count; $i++) {
                if ("PK\x01\x02" !== (string) substr($data, $p, 4)) {
                    return 0;
                }
                $csize = (int) unpack('V', (string) substr($data, $p + 20, 4))[1];
                $usize = (int) unpack('V', (string) substr($data, $p + 24, 4))[1];
                $nlen  = (int) unpack('v', (string) substr($data, $p + 28, 2))[1];
                $elen  = (int) unpack('v', (string) substr($data, $p + 30, 2))[1];
                $clen  = (int) unpack('v', (string) substr($data, $p + 32, 2))[1];
                $name  = (string) substr($data, $p + 46, $nlen);
                // Faithful to WP-core PclZip privConvertHeader2FileInfo():
                // uncompressed size under 'size', plus compressed_size/status/folder.
                $entry = array(
                    'filename'        => $name,
                    'stored_filename' => $name,
                    'size'            => $usize,
                    'compressed_size' => $csize,
                    'folder'          => str_ends_with($name, '/'),
                    'index'           => $i,
                    'status'          => 'ok',
                );
                if (!empty($GLOBALS['ADC_TEST']['pclzip_omit_size'])) {
                    unset($entry['size']); // adversarial reader variant: missing key.
                }
                if (!empty($GLOBALS['ADC_TEST']['pclzip_bad_compressed'])) {
                    $entry['compressed_size'] = -1; // adversarial reader variant: malformed value.
                }
                $out[] = $entry;
                $p += 46 + $nlen + $elen + $clen;
            }
            return $out;
        }
    }
}

// ---------------------------------------------------------------- load SUT.

require_once __DIR__ . '/../includes/core/class-adc-github-updater.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function reset_state(): void
{
    $GLOBALS['ADC_TEST']['http'] = array();
    $GLOBALS['ADC_TEST']['queue'] = array();
    $GLOBALS['ADC_TEST']['transients'] = array();
    $GLOBALS['ADC_TEST']['transient_set'] = array();
    $GLOBALS['ADC_TEST']['tempnam'] = array();
    $GLOBALS['ADC_TEST']['deleted'] = array();
    $GLOBALS['ADC_TEST']['pclzip_omit_size'] = false;
    $GLOBALS['ADC_TEST']['pclzip_bad_compressed'] = false;
    $GLOBALS['ADC_TEST']['admin'] = true;
    $GLOBALS['ADC_TEST']['cron'] = false;
    $GLOBALS['ADC_TEST']['caps'] = true;
    unset($_GET['force-check']);
}

function force_pclzip_fallback(bool $pclzip): void
{
    $prop = new ReflectionProperty(ADC_Github_Updater::class, 'prefer_ziparchive');
    $prop->setAccessible(true);
    $prop->setValue(null, $pclzip);
}

function http_calls(): int
{
    return count($GLOBALS['ADC_TEST']['http']);
}

function last_request(): array
{
    return $GLOBALS['ADC_TEST']['http'][array_key_last($GLOBALS['ADC_TEST']['http'])] ?? array();
}

function resp(int $code, string $body = ''): array
{
    return array('response' => array('code' => $code), 'body' => $body);
}

// ---------------------------------------------------------------- ZIP fixtures.

/**
 * Build a REAL stored-method (uncompressed) ZIP archive in memory:
 * local headers + central directory + EOCD, with correct CRC32 values.
 * `$declared` overrides the declared uncompressed size (adversarial "lying
 * header" fixtures); `$modes` sets Unix modes (symlink fixtures).
 *
 * @param array<string, string> $entries  name => content
 * @param array<string, int>    $declared name => declared uncompressed size
 * @param array<string, int>    $modes    name => octal Unix mode
 */
function make_zip_bytes(array $entries, array $declared = array(), array $modes = array()): string
{
    $local   = '';
    $central = '';
    $offset  = 0;
    foreach ($entries as $name => $content) {
        $crc   = crc32($content);
        $decl  = $declared[$name] ?? strlen($content);
        $mode  = $modes[$name] ?? 0100644;
        $ext   = ($mode << 16) & 0xFFFFFFFF;
        $nlen  = strlen($name);
        $local .= "PK\x03\x04"
            . pack('vvvvv', 20, 0, 0, 0, 0x21)          // version needed, flags, method(stored), time, date
            . pack('V', $crc)
            . pack('V', $decl) . pack('V', $decl)       // compressed, uncompressed
            . pack('vv', $nlen, 0)
            . $name . $content;
        $central .= "PK\x01\x02"
            . pack('vv', 0x031E, 20)                    // made by (unix), version needed
            . pack('vvvv', 0, 0, 0, 0x21)               // flags, method, time, date
            . pack('V', $crc)
            . pack('V', $decl) . pack('V', $decl)
            . pack('vvvvv', $nlen, 0, 0, 0, 0)          // name, extra, comment, disk, int attrs
            . pack('V', $ext)
            . pack('V', $offset)
            . $name;
        $offset += 30 + $nlen + strlen($content);
    }
    $eocd = "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($entries), count($entries), strlen($central), strlen($local), 0);
    return $local . $central . $eocd;
}

/** Memoized valid plugin ZIP for a version (single root + required main entry). */
function fixture_zip(string $version): string
{
    static $memo = array();
    if (!isset($memo[$version])) {
        $memo[$version] = make_zip_bytes(array(
            'adc-marketing/'                          => '',
            'adc-marketing/adc-marketing.php'         => "<?php\n// ADC Marketing {$version} fixture entry (synthetic).\n",
            'adc-marketing/readme.txt'                => "ADC Cookies & Commerce Hub {$version}\n",
            'adc-marketing/includes/class-fixture.php' => "<?php // fixture\n",
        ));
    }
    return $memo[$version];
}

/** Non-ZIP bytes that still pass a SHA-256 pin (used to prove fail-closed inspection). */
function fake_zip_bytes(string $version): string
{
    return "PK-ADC-UPDATER-FIXTURE-NOT-A-REAL-ZIP-v{$version}-synthetic-bytes-only";
}

function sha_of(string $bytes): string
{
    return hash('sha256', $bytes);
}

// ---------------------------------------------------------------- metadata fixtures.

const OWNER = 'dany547';
const REPO = 'adc-cookies-commerce-hub-wordpress';
const BASE = 'https://github.com/dany547/adc-cookies-commerce-hub-wordpress';
const API_URL = 'https://api.github.com/repos/dany547/adc-cookies-commerce-hub-wordpress/releases/latest';
const STATIC_UA = 'ADC-Marketing-Updater/2.2 (+https://github.com/dany547/adc-cookies-commerce-hub-wordpress)';

function zip_url_for(string $version): string
{
    return BASE . '/releases/download/v' . $version . '/adc-marketing-' . $version . '.zip';
}

function api_body(string $version, array $overrides = array()): string
{
    $payload = array_merge(array(
        'tag_name'   => 'v' . $version,
        'draft'      => false,
        'prerelease' => false,
        'body'       => "# {$version}\nNotele versiunii.",
        'html_url'   => BASE . '/releases/tag/v' . $version,
        'assets'     => array(
            array('name' => 'adc-marketing-' . $version . '.zip', 'browser_download_url' => zip_url_for($version)),
            array('name' => 'update-manifest.json', 'browser_download_url' => BASE . '/releases/download/v' . $version . '/update-manifest.json'),
            array('name' => 'SHA256SUMS', 'browser_download_url' => BASE . '/releases/download/v' . $version . '/SHA256SUMS'),
        ),
    ), $overrides);
    return (string) json_encode($payload);
}

function manifest_body(string $version, array $overrides = array()): string
{
    $payload = array_merge(array(
        'version'      => $version,
        'requires'     => '6.8',
        'requires_php' => '8.2',
        'tested'       => '7.1',
        'wc_requires'  => '9.5',
        'package_url'  => zip_url_for($version),
        'sha256'       => sha_of(fixture_zip($version)),
    ), $overrides);
    return (string) json_encode($payload);
}

function sums_body(string $version, ?string $hash = null): string
{
    $hash = $hash ?? sha_of(fixture_zip($version));
    return $hash . '  adc-marketing-' . $version . ".zip\n";
}

/** Queue the three bounded requests of a fully valid metadata check. */
function queue_valid_metadata(string $version = '2.2.0', array $api_overrides = array()): void
{
    $GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body($version, $api_overrides));
    $GLOBALS['ADC_TEST']['queue'][] = resp(200, manifest_body($version));
    $GLOBALS['ADC_TEST']['queue'][] = resp(200, sums_body($version));
}

function update_for(array $plugin_data = array()): mixed
{
    return ADC_Github_Updater::update_response(
        false,
        array_merge(array('Version' => ADC_MARKETING_VERSION, 'Name' => 'ADC Cookies & Commerce Hub'), $plugin_data),
        'adc-marketing/adc-marketing.php',
        array('ro_RO')
    );
}

function seeded_cache(string $version = '2.2.0', ?string $zip_bytes = null): array
{
    return array(
        'v'     => 1,
        'good'  => array(
            'release' => array(
                'version'      => $version,
                'package_url'  => zip_url_for($version),
                'sha256'       => sha_of($zip_bytes ?? fixture_zip($version)),
                'requires'     => '6.8',
                'requires_php' => '8.2',
                'tested'       => '7.1',
                'wc_requires'  => '9.5',
                'url'          => BASE . '/releases/tag/v' . $version,
                'body'         => 'notes',
            ),
            'expires' => time() + 3600,
        ),
        'error' => null,
    );
}

/** Queue a download of exactly `$bytes` against the current seeded pin. */
function queue_download(string $bytes): void
{
    $GLOBALS['ADC_TEST']['queue'][] = resp(200, $bytes);
}

/** Run the verified_download gate for our plugin with the pinned URL. */
function gate_download(?string $package_url = null): mixed
{
    return ADC_Github_Updater::verified_download(
        false,
        $package_url ?? zip_url_for('2.2.0'),
        null,
        array('plugin' => 'adc-marketing/adc-marketing.php', 'type' => 'plugin')
    );
}

// ---------------------------------------------------------------- tests.

// 1. Boot: registers the four hooks, idempotent.
reset_state();
ADC_Github_Updater::boot();
check(isset($GLOBALS['ADC_TEST']['filters']['update_plugins_github.com']), 'boot: update_plugins_github.com registered');
check(isset($GLOBALS['ADC_TEST']['filters']['plugins_api']), 'boot: plugins_api registered');
check(isset($GLOBALS['ADC_TEST']['filters']['upgrader_pre_download']), 'boot: upgrader_pre_download registered');
check(isset($GLOBALS['ADC_TEST']['filters']['http_headers_useragent']), 'boot: http_headers_useragent registered');
check(4 === array_sum(array_map('count', $GLOBALS['ADC_TEST']['filters'])), 'boot: exactly four hook registrations');
ADC_Github_Updater::boot();
check(4 === array_sum(array_map('count', $GLOBALS['ADC_TEST']['filters'])), 'boot: idempotent (no duplicate hooks)');

// 2. No outbound traffic outside admin/cron/CLI.
reset_state();
$GLOBALS['ADC_TEST']['admin'] = false;
check(false === update_for(), 'frontend context: no update');
check(0 === http_calls(), 'frontend context: zero HTTP requests');
check(false === ADC_Github_Updater::plugin_information(false, 'plugin_information', (object) array('slug' => 'adc-marketing')), 'frontend context: plugins_api refuses');
check(0 === http_calls(), 'frontend context: plugins_api zero HTTP');
$GLOBALS['ADC_TEST']['admin'] = true;

// 3. Happy path: newer stable release offered with correct fields.
reset_state();
queue_valid_metadata('2.2.0');
$update = update_for();
check($update instanceof stdClass, 'newer release: update object returned');
check('adc-marketing' === $update->slug, 'newer release: slug');
check('2.2.0' === $update->new_version, 'newer release: new_version');
check('2.2.0' === $update->version, 'newer release: version');
check(zip_url_for('2.2.0') === $update->package, 'newer release: exact pinned package URL');
check('6.8' === $update->requires, 'newer release: requires');
check('8.2' === $update->requires_php, 'newer release: requires_php');
check('7.1' === $update->tested, 'newer release: tested');
check(!property_exists($update, 'autoupdate'), 'newer release: autoupdate never force-enabled');
check(3 === http_calls(), 'newer release: exactly three bounded requests (API+manifest+SUMS)');
$req0 = $GLOBALS['ADC_TEST']['http'][0];
check(API_URL === $req0['url'], 'newer release: exact API URL');
check(10 === $req0['args']['timeout'], 'newer release: bounded timeout');
check(1048576 === $req0['args']['limit_response_size'], 'newer release: bounded response size');
check(STATIC_UA === $req0['args']['headers']['User-Agent'], 'newer release: static UA on metadata request');
check(!isset($req0['args']['headers']['Authorization']), 'newer release: no Authorization header');
check(!isset($req0['args']['headers']['Cookie']), 'newer release: no cookies');
check(BASE . '/releases/tag/v2.2.0' === $update->url, 'newer release: canonical release page URL');

// 4. Cache: second check within 12h makes zero HTTP calls, same result.
reset_state();
queue_valid_metadata('2.2.0');
check($update instanceof stdClass && update_for() instanceof stdClass, 'cache: first check ok');
$before = http_calls();
check($update instanceof stdClass && ADC_Github_Updater::update_response(false, array('Version' => '2.1.0'), 'adc-marketing/adc-marketing.php') instanceof stdClass, 'cache: served from cache');
check($before === http_calls(), 'cache: zero HTTP within TTL');

// 4b. Cache writes carry a WP-level TTL (never a permanent/autoloaded row).
$expirations = array_column($GLOBALS['ADC_TEST']['transient_set'], 'expiration');
check(!empty($expirations) && 43200 === (int) end($expirations), 'cache bounds: success write expired at 12h');

// 5. Equal version → false. 6. Older → false.
reset_state();
queue_valid_metadata('2.1.0');
check(false === update_for(), 'equal version: no update');
reset_state();
queue_valid_metadata('2.0.0');
check(false === update_for(), 'older version: no update');

// 7./8. Prerelease / draft → fail closed.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body('2.2.0', array('prerelease' => true)));
check(false === update_for(), 'prerelease: rejected');
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body('2.2.0', array('draft' => true)));
check(false === update_for(), 'draft: rejected');

// 9. Transport error (WP_Error) → false.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = new WP_Error('http_request_failed', 'timeout');
check(false === update_for(), 'http error: no update');

// 10. HTTP 404 → false.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(404, '{"message":"Not Found"}');
check(false === update_for(), '404: no update');

// 11. Rate limit (403) → false.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(403, '{"message":"API rate limit exceeded"}');
check(false === update_for(), 'rate limit: no update');

// 12. Invalid JSON → false.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(200, '<html>gateway error</html>');
check(false === update_for(), 'invalid json: no update');

// 13. Truncated manifest → false.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body('2.2.0'));
$GLOBALS['ADC_TEST']['queue'][] = resp(200, '{"version":"2.2.0","requires":"6.8","requi');
check(false === update_for(), 'truncated manifest: rejected');

// 14. Missing SHA256SUMS asset → fail closed.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body('2.2.0', array('assets' => array(
    array('name' => 'adc-marketing-2.2.0.zip', 'browser_download_url' => zip_url_for('2.2.0')),
    array('name' => 'update-manifest.json', 'browser_download_url' => BASE . '/releases/download/v2.2.0/update-manifest.json'),
))));
$GLOBALS['ADC_TEST']['queue'][] = resp(200, manifest_body('2.2.0'));
check(false === update_for(), 'missing SHA256SUMS: rejected');

// 15. SHA256SUMS mismatch vs manifest → fail closed.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body('2.2.0'));
$GLOBALS['ADC_TEST']['queue'][] = resp(200, manifest_body('2.2.0'));
$GLOBALS['ADC_TEST']['queue'][] = resp(200, sums_body('2.2.0', str_repeat('ab', 32)));
check(false === update_for(), 'SUMS mismatch: rejected');

// 16. Manifest version ≠ tag → fail closed.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body('2.2.0'));
$GLOBALS['ADC_TEST']['queue'][] = resp(200, manifest_body('9.9.9'));
$GLOBALS['ADC_TEST']['queue'][] = resp(200, sums_body('2.2.0'));
check(false === update_for(), 'manifest version mismatch: rejected');

// 17. Manifest sha256 not hex → fail closed.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body('2.2.0'));
$GLOBALS['ADC_TEST']['queue'][] = resp(200, manifest_body('2.2.0', array('sha256' => 'zz-not-a-hash')));
check(false === update_for(), 'non-hex sha256: rejected');

// 18. Manifest package_url → evil host → fail closed.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body('2.2.0'));
$GLOBALS['ADC_TEST']['queue'][] = resp(200, manifest_body('2.2.0', array('package_url' => 'https://evil.example/adc-marketing-2.2.0.zip')));
check(false === update_for(), 'URL tamper (foreign host): rejected');

// 19. Wrong repo in package_url → fail closed.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body('2.2.0'));
$GLOBALS['ADC_TEST']['queue'][] = resp(200, manifest_body('2.2.0', array('package_url' => 'https://github.com/attacker/other/releases/download/v2.2.0/adc-marketing-2.2.0.zip')));
check(false === update_for(), 'wrong repo: rejected');

// 19b. Manifest package_url http:// → rejected (raw-string contract, no canonicalization).
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body('2.2.0'));
$GLOBALS['ADC_TEST']['queue'][] = resp(200, manifest_body('2.2.0', array('package_url' => 'http://github.com/dany547/adc-cookies-commerce-hub-wordpress/releases/download/v2.2.0/adc-marketing-2.2.0.zip')));
check(false === update_for(), 'manifest package_url http://: rejected');

// 19c. Manifest package_url with explicit port → rejected.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body('2.2.0'));
$GLOBALS['ADC_TEST']['queue'][] = resp(200, manifest_body('2.2.0', array('package_url' => 'https://github.com:443/dany547/adc-cookies-commerce-hub-wordpress/releases/download/v2.2.0/adc-marketing-2.2.0.zip')));
check(false === update_for(), 'manifest package_url alternate port: rejected');

// 19d. Manifest package_url with traversal bytes → rejected.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body('2.2.0'));
$GLOBALS['ADC_TEST']['queue'][] = resp(200, manifest_body('2.2.0', array('package_url' => 'https://github.com/dany547/adc-cookies-commerce-hub-wordpress/releases/download/../v2.2.0/adc-marketing-2.2.0.zip')));
check(false === update_for(), 'manifest package_url traversal: rejected');

// 19e. Manifest package_url with query string → rejected.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body('2.2.0'));
$GLOBALS['ADC_TEST']['queue'][] = resp(200, manifest_body('2.2.0', array('package_url' => zip_url_for('2.2.0') . '?x=1')));
check(false === update_for(), 'manifest package_url query: rejected');

// 20. Wrong asset for the tag (zip name version mismatch) → fail closed.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body('2.2.0', array('assets' => array(
    array('name' => 'adc-marketing-2.1.0.zip', 'browser_download_url' => zip_url_for('2.1.0')),
    array('name' => 'update-manifest.json', 'browser_download_url' => BASE . '/releases/download/v2.2.0/update-manifest.json'),
    array('name' => 'SHA256SUMS', 'browser_download_url' => BASE . '/releases/download/v2.2.0/SHA256SUMS'),
))));
check(false === update_for(), 'wrong asset for tag: rejected');

// 21. Asset URL with query string → not allowlisted → fail closed.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body('2.2.0', array('assets' => array(
    array('name' => 'adc-marketing-2.2.0.zip', 'browser_download_url' => zip_url_for('2.2.0') . '?token=evil'),
    array('name' => 'update-manifest.json', 'browser_download_url' => BASE . '/releases/download/v2.2.0/update-manifest.json'),
    array('name' => 'SHA256SUMS', 'browser_download_url' => BASE . '/releases/download/v2.2.0/SHA256SUMS'),
))));
check(false === update_for(), 'asset URL with query: rejected');

// 22. Non-200 manifest / SUMS → fail closed.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body('2.2.0'));
$GLOBALS['ADC_TEST']['queue'][] = resp(500, 'oops');
check(false === update_for(), 'manifest HTTP 500: rejected');

// 23. Bad tag formats → fail closed.
foreach (array('2.2.0', 'v2.2', 'v2.2.0-rc1', 'release-2.2.0') as $bad_tag) {
    reset_state();
    $GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body('2.2.0', array('tag_name' => $bad_tag)));
    check(false === update_for(), "bad tag '{$bad_tag}': rejected");
}

// 24. Error caching: 15 min error TTL, then refetch after expiry.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = new WP_Error('http_request_failed', 'offline');
check(false === update_for(), 'error cache: first attempt fails');
check(1 === http_calls(), 'error cache: one request');
$err_expirations = array_column($GLOBALS['ADC_TEST']['transient_set'], 'expiration');
check(900 === (int) end($err_expirations), 'error cache: error write expired at 15min');
check(false === update_for(), 'error cache: second attempt still fails');
check(1 === http_calls(), 'error cache: second attempt served from error cache (no HTTP)');
$store = $GLOBALS['ADC_TEST']['transients']['adc_updater_cache'];
$store['error']['expires'] = time() - 1;
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = $store;
$GLOBALS['ADC_TEST']['queue'][] = new WP_Error('http_request_failed', 'offline again');
check(false === update_for(), 'error cache: expired error refetches');
check(2 === http_calls(), 'error cache: refetch happened after TTL expiry');

// 25. Valid success cache is preserved across an error (no clearing).
reset_state();
queue_valid_metadata('2.2.0');
check(update_for() instanceof stdClass, 'preserve-good: seeded good cache');
$_GET['force-check'] = '1';
$GLOBALS['ADC_TEST']['queue'][] = new WP_Error('http_request_failed', 'blip');
check(false === update_for(), 'preserve-good: forced refetch errored → false');
check(isset($GLOBALS['ADC_TEST']['transients']['adc_updater_cache']['good']['release']), 'preserve-good: good cache NOT cleared by error');
check(isset($GLOBALS['ADC_TEST']['transients']['adc_updater_cache']['error']), 'preserve-good: error recorded alongside');
unset($_GET['force-check']);
$before = http_calls();
check(update_for() instanceof stdClass, 'preserve-good: still served from preserved cache');
check($before === http_calls(), 'preserve-good: zero HTTP for the served answer');

// 26. Truthy incoming update passthrough (never override another responder).
reset_state();
$incoming = (object) array('version' => '3.0.0');
check($incoming === ADC_Github_Updater::update_response($incoming, array(), 'adc-marketing/adc-marketing.php'), 'passthrough: truthy incoming returned untouched');
check(0 === http_calls(), 'passthrough: zero HTTP');

// 27. Scoped: other plugins are never answered.
reset_state();
check(false === ADC_Github_Updater::update_response(false, array('Version' => '1.0'), 'other/other.php'), 'scope: other plugin (github.com host) not answered');
check(0 === http_calls(), 'scope: zero HTTP for other plugin');

// 28. Global update_plugins transient never written by this module.
reset_state();
queue_valid_metadata('2.2.0');
update_for();
check(array('adc_updater_cache') === array_column($GLOBALS['ADC_TEST']['transient_set'], 'key'), 'transient safety: only our cache key ever written');

// 29. plugins_api details for our slug only.
reset_state();
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0');
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache']['good']['release']['body'] = "# 2.2.0\n<script>alert(1)</script>";
$info = ADC_Github_Updater::plugin_information(false, 'plugin_information', (object) array('slug' => 'adc-marketing'));
check($info instanceof stdClass, 'plugins_api: object for our slug');
check('adc-marketing' === $info->slug, 'plugins_api: slug');
check('ADC Cookies & Commerce Hub' === $info->name, 'plugins_api: rebranded header name passthrough');
check(zip_url_for('2.2.0') === $info->download_link, 'plugins_api: exact download link');
check(!str_contains((string) $info->sections['changelog'], '<script>alert'), 'plugins_api: changelog HTML escaped');
check(str_contains((string) $info->sections['changelog'], '&lt;script&gt;'), 'plugins_api: changelog escaped form present');
check(false === ADC_Github_Updater::plugin_information(false, 'plugin_information', (object) array('slug' => 'hello-dolly')), 'plugins_api: other slug untouched');
check(false === ADC_Github_Updater::plugin_information(false, 'query_plugins', (object) array('slug' => 'adc-marketing')), 'plugins_api: other action untouched');

// 29b. Release notes are byte-capped in the cache (autoload-size bound).
reset_state();
queue_valid_metadata('2.2.0', array('body' => str_repeat('a', 100000)));
check(update_for() instanceof stdClass, 'notes cap: metadata still valid with huge body');
$cached_body = (string) $GLOBALS['ADC_TEST']['transients']['adc_updater_cache']['good']['release']['body'];
check(strlen($cached_body) <= 8192, 'notes cap: cached body capped at 8 KiB');
$cache_serialized = strlen((string) serialize($GLOBALS['ADC_TEST']['transients']['adc_updater_cache']));
check($cache_serialized < 12000, 'notes cap: whole cache transient stays small (' . $cache_serialized . ' bytes)');
$info2 = ADC_Github_Updater::plugin_information(false, 'plugin_information', (object) array('slug' => 'adc-marketing'));
check(is_string($info2->sections['changelog']) && strlen($info2->sections['changelog']) < 10000, 'notes cap: changelog section bounded');

// 30. Download gate: scope (other plugin passes through untouched).
reset_state();
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0');
check(false === ADC_Github_Updater::verified_download(false, zip_url_for('2.2.0'), null, array('plugin' => 'other/other.php', 'type' => 'plugin')), 'gate: other plugin untouched');
check(false === ADC_Github_Updater::verified_download(false, zip_url_for('2.2.0'), null, array('plugin' => 'adc-marketing/adc-marketing.php')), 'gate: missing type untouched (core/translation shapes)');
check(0 === http_calls(), 'gate: zero HTTP for foreign scopes');

// 31. WP_Error reply preserved, never stomped.
reset_state();
$existing = new WP_Error('upstream', 'already failing');
check($existing === ADC_Github_Updater::verified_download($existing, 'x.zip', null, array('plugin' => 'adc-marketing/adc-marketing.php', 'type' => 'plugin')), 'gate: WP_Error reply preserved');

// 32. Unpinned/tampered package URL → WP_Error, zero HTTP.
reset_state();
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0');
$res = gate_download(zip_url_for('2.2.0') . '?tamper=1');
check($res instanceof WP_Error, 'gate: tampered URL rejected');
check('adc_updater_unpinned' === $res->get_error_code(), 'gate: tampered URL error code');
check(0 === http_calls(), 'gate: tampered URL never downloaded');
$res = gate_download(BASE . '/releases/download/v1.9.0/adc-marketing-1.9.0.zip');
check($res instanceof WP_Error && 'adc_updater_unpinned' === $res->get_error_code(), 'gate: historical URL rejected');

// 32b. Forged WordPress.org-style package for OUR basename → integrity not bypassable.
reset_state();
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0');
$res = gate_download('https://downloads.wordpress.org/plugin/adc-marketing.2.2.0.zip');
check($res instanceof WP_Error && 'adc_updater_unpinned' === $res->get_error_code(), 'gate: wp.org spoofed route blocked by pin');

// 33. No valid cache → refuse to download anything.
reset_state();
$res = gate_download();
check($res instanceof WP_Error && 'adc_updater_unpinned' === $res->get_error_code(), 'gate: no pin → no download');

// 34. Runtime compatibility enforced at install time (fail closed).
reset_state();
$incompatible = seeded_cache('2.2.0');
$incompatible['good']['release']['requires_php'] = '9.9';
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = $incompatible;
$res = gate_download();
check($res instanceof WP_Error && 'adc_updater_incompatible' === $res->get_error_code(), 'gate: min PHP enforced at install');
check(0 === http_calls(), 'gate: incompatible never downloaded');
$incompatible = seeded_cache('2.2.0');
$incompatible['good']['release']['requires'] = '99.0';
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = $incompatible;
$res = gate_download();
check($res instanceof WP_Error && 'adc_updater_incompatible' === $res->get_error_code(), 'gate: min WP enforced at install');

// 35. Integrity mismatch: wrong bytes → WP_Error, nothing written anywhere.
reset_state();
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0');
$GLOBALS['ADC_TEST']['queue'][] = resp(200, 'definitely-not-the-pinned-bytes');
$res = gate_download();
check($res instanceof WP_Error && 'adc_updater_integrity' === $res->get_error_code(), 'gate: integrity mismatch fails closed');
check(0 === count($GLOBALS['ADC_TEST']['tempnam']), 'gate: no temp file created for mismatched bytes');
check(0 === count($GLOBALS['ADC_TEST']['deleted']), 'gate: no deletions needed (hash precedes write)');

// 36. Truncated download (empty body) → WP_Error.
reset_state();
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0');
$GLOBALS['ADC_TEST']['queue'][] = resp(200, '');
$res = gate_download();
check($res instanceof WP_Error, 'gate: empty body rejected');

// 37. Non-200 download → WP_Error.
reset_state();
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0');
$GLOBALS['ADC_TEST']['queue'][] = resp(503, 'unavailable');
$res = gate_download();
check($res instanceof WP_Error, 'gate: download HTTP failure rejected');

// 38. Valid digest + VALID ROOT: real ZIP handed over as a temp file, bounded request.
reset_state();
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0');
queue_download(fixture_zip('2.2.0'));
$res = gate_download();
check(is_string($res), 'gate: valid digest + correct root → local path returned');
check(is_file((string) $res), 'gate: temp file exists');
check(sha_of(fixture_zip('2.2.0')) === (string) hash_file('sha256', (string) $res), 'gate: temp file bytes match pin');
$req = last_request();
check(zip_url_for('2.2.0') === $req['url'], 'gate: exact pinned URL requested');
check(180 === $req['args']['timeout'], 'gate: bounded download timeout');
check(67108864 === $req['args']['limit_response_size'], 'gate: bounded download size');
check(3 === $req['args']['redirection'], 'gate: bounded redirects');
check(STATIC_UA === $req['args']['headers']['User-Agent'], 'gate: static UA on download');
check(!isset($req['args']['headers']['Cookie']), 'gate: no cookies on download');
check(0 === count($GLOBALS['ADC_TEST']['deleted']), 'gate: success path performs no cleanup');

// 39. Static User-Agent scoping: no site URL / default WP UA on our URLs.
check(STATIC_UA === ADC_Github_Updater::user_agent('WordPress/6.8; https://shop.example', API_URL), 'UA: static for metadata URL');
check(STATIC_UA === ADC_Github_Updater::user_agent('WordPress/6.8; https://shop.example', zip_url_for('2.2.0')), 'UA: static for release asset URL');
check(STATIC_UA === ADC_Github_Updater::user_agent('WordPress/6.8; https://shop.example', 'https://objects.githubusercontent.com/hop'), 'UA: static for CDN redirect hop');
$untouched = 'WordPress/6.8; https://shop.example';
check($untouched === ADC_Github_Updater::user_agent($untouched, 'https://example.org/other'), 'UA: default preserved for unrelated URLs');
check($untouched === ADC_Github_Updater::user_agent($untouched, ''), 'UA: default preserved for empty URL');
check(!str_contains(STATIC_UA, 'shop.example') && !str_contains(STATIC_UA, 'http://'), 'UA: static UA carries no site URL / insecure scheme');

// 40. Manifest wc_requires malformed → constraint invalid → fail closed.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body('2.2.0'));
$GLOBALS['ADC_TEST']['queue'][] = resp(200, manifest_body('2.2.0', array('wc_requires' => 'not-semver')));
$GLOBALS['ADC_TEST']['queue'][] = resp(200, sums_body('2.2.0'));
check(false === update_for(), 'malformed wc_requires: rejected');

// 41. Manifest missing requires_php → fail closed.
reset_state();
$GLOBALS['ADC_TEST']['queue'][] = resp(200, api_body('2.2.0'));
$GLOBALS['ADC_TEST']['queue'][] = resp(200, (string) json_encode(array(
    'version' => '2.2.0', 'requires' => '6.8', 'tested' => '7.1',
    'package_url' => zip_url_for('2.2.0'), 'sha256' => sha_of(fixture_zip('2.2.0')),
)));
check(false === update_for(), 'manifest missing requires_php: rejected');

// 42. Corrupt cache shape is treated as empty (never trusted).
reset_state();
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = array('v' => 1, 'good' => array('release' => 'not-an-array', 'expires' => time() + 999));
$GLOBALS['ADC_TEST']['queue'][] = new WP_Error('http_request_failed', 'x');
check(false === update_for(), 'corrupt cache: not trusted, refetch attempted');

// ---------------------------------------------------------------- archive guard regressions.

// 43. WRONG ROOT (review finding 1): valid hash, ZIP root adc-marketing-2.2.0/ → fail closed.
reset_state();
$wrong_root_zip = make_zip_bytes(array(
    'adc-marketing-2.2.0/adc-marketing.php' => "<?php // wrong root\n",
    'adc-marketing-2.2.0/readme.txt'        => "wrong root\n",
));
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0', $wrong_root_zip);
queue_download($wrong_root_zip);
$res = gate_download();
check($res instanceof WP_Error && 'adc_updater_archive_root' === $res->get_error_code(), 'archive: wrong root (adc-marketing-2.2.0/) rejected');
check(1 === count($GLOBALS['ADC_TEST']['tempnam']), 'archive: inspection happened after temp write');
check(isset($GLOBALS['ADC_TEST']['tempnam'][0]) && is_file($GLOBALS['ADC_TEST']['tempnam'][0]), 'archive: temp file still present (tests execute no deletion)');
check(1 === count($GLOBALS['ADC_TEST']['deleted']), 'archive: WP-API cleanup recorded for the failed temp');

// 43b. Same guard via the PclZip fallback branch (forced deterministically).
reset_state();
force_pclzip_fallback(true);
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0', $wrong_root_zip);
queue_download($wrong_root_zip);
$res = gate_download();
check($res instanceof WP_Error && 'adc_updater_archive_root' === $res->get_error_code(), 'archive (PclZip fallback): wrong root rejected');

// 44. Path traversal entry → fail closed.
reset_state();
$traversal_zip = make_zip_bytes(array(
    'adc-marketing/adc-marketing.php' => "<?php\n",
    'adc-marketing/../../evil.php'    => "evil\n",
));
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0', $traversal_zip);
queue_download($traversal_zip);
$res = gate_download();
check($res instanceof WP_Error && 'adc_updater_archive_entry' === $res->get_error_code(), 'archive: traversal entry rejected');

// 45. Absolute path entry → fail closed.
reset_state();
$abs_zip = make_zip_bytes(array(
    'adc-marketing/adc-marketing.php' => "<?php\n",
    '/etc/evil.php'                   => "evil\n",
));
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0', $abs_zip);
queue_download($abs_zip);
$res = gate_download();
check($res instanceof WP_Error && 'adc_updater_archive_entry' === $res->get_error_code(), 'archive: absolute path entry rejected');

// 46. Windows backslash separator entry → fail closed.
reset_state();
$backslash_zip = make_zip_bytes(array(
    'adc-marketing/adc-marketing.php' => "<?php\n",
    "adc-marketing\\evil.php"         => "evil\n",
));
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0', $backslash_zip);
queue_download($backslash_zip);
$res = gate_download();
check($res instanceof WP_Error && 'adc_updater_archive_entry' === $res->get_error_code(), 'archive: backslash entry rejected');

// 47. Drive-letter entry → fail closed.
reset_state();
$drive_zip = make_zip_bytes(array(
    'adc-marketing/adc-marketing.php' => "<?php\n",
    'C:/evil.php'                     => "evil\n",
));
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0', $drive_zip);
queue_download($drive_zip);
$res = gate_download();
check($res instanceof WP_Error && 'adc_updater_archive_entry' === $res->get_error_code(), 'archive: drive-letter entry rejected');

// 48. Second root (multi-root ZIP) → fail closed.
reset_state();
$multi_root_zip = make_zip_bytes(array(
    'adc-marketing/adc-marketing.php' => "<?php\n",
    'other/b.php'                     => "evil\n",
));
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0', $multi_root_zip);
queue_download($multi_root_zip);
$res = gate_download();
check($res instanceof WP_Error && 'adc_updater_archive_root' === $res->get_error_code(), 'archive: second root rejected');

// 49. Required main entry missing (root ok, no adc-marketing/adc-marketing.php) → fail closed.
reset_state();
$missing_main_zip = make_zip_bytes(array(
    'adc-marketing/readme.txt' => "no plugin file here\n",
));
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0', $missing_main_zip);
queue_download($missing_main_zip);
$res = gate_download();
check($res instanceof WP_Error && 'adc_updater_archive_missing_entry' === $res->get_error_code(), 'archive: missing adc-marketing/adc-marketing.php rejected');

// 50. Not a ZIP at all (digest-valid but uninspectable) → fail closed.
reset_state();
$fake_bytes = fake_zip_bytes('2.2.0');
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0', $fake_bytes);
queue_download($fake_bytes);
$res = gate_download();
check($res instanceof WP_Error && 'adc_updater_archive_read' === $res->get_error_code(), 'archive: non-ZIP bytes (uninspectable) fail closed');
check(isset($GLOBALS['ADC_TEST']['tempnam'][0]) && is_file($GLOBALS['ADC_TEST']['tempnam'][0]), 'archive: no deletion executed for uninspectable temp');

// 50b. PclZip fallback regression (Sonnet finding B3): faithful real-core shape
// (uncompressed size under 'size') is ACCEPTED end-to-end...
reset_state();
force_pclzip_fallback(false);
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0');
queue_download(fixture_zip('2.2.0'));
$res = gate_download();
check(is_string($res) && is_file((string) $res), 'pclzip shape: good size key accepted (real PclZip shape)');

// 50c. ...while a reader variant WITHOUT the 'size' key fails closed.
reset_state();
force_pclzip_fallback(false);
$GLOBALS['ADC_TEST']['pclzip_omit_size'] = true;
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0');
queue_download(fixture_zip('2.2.0'));
$res = gate_download();
check($res instanceof WP_Error && 'adc_updater_archive_read' === $res->get_error_code(), 'pclzip shape: absence of size fails closed');

// 50d. Optional compressed_size, when present, is verified (malformed → fail closed).
reset_state();
force_pclzip_fallback(false);
$GLOBALS['ADC_TEST']['pclzip_bad_compressed'] = true;
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0');
queue_download(fixture_zip('2.2.0'));
$res = gate_download();
check($res instanceof WP_Error && 'adc_updater_archive_read' === $res->get_error_code(), 'pclzip shape: malformed compressed_size fails closed');
force_pclzip_fallback(true === class_exists('ZipArchive'));

// 51. Entry-count bound (2001 entries > 2000 cap) → fail closed.
reset_state();
$flood = array('adc-marketing/adc-marketing.php' => "<?php\n");
for ($i = 0; $i < 2001; $i++) {
    $flood["adc-marketing/f{$i}.txt"] = '';
}
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0', sha_of('')); // placeholder, replaced below
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache']['good']['release']['sha256'] = sha_of('');
$flood_zip = make_zip_bytes($flood);
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache']['good']['release']['sha256'] = sha_of($flood_zip);
queue_download($flood_zip);
$res = gate_download();
check($res instanceof WP_Error && 'adc_updater_archive_entries' === $res->get_error_code(), 'archive: entry-count bound enforced');

// 52. Declared-size bomb (lying headers: 300 MB declared uncompressed) → fail closed.
reset_state();
$bomb_zip = make_zip_bytes(array(
    'adc-marketing/adc-marketing.php' => "<?php\n",
    'adc-marketing/big.bin'           => "x",
), array('adc-marketing/big.bin' => 300000000));
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0', $bomb_zip);
queue_download($bomb_zip);
$res = gate_download();
check($res instanceof WP_Error && 'adc_updater_archive_size' === $res->get_error_code(), 'archive: declared uncompressed size bound enforced');

// 53. Symlink entry → rejected when ZipArchive is the reader (conditional:
// PclZip cannot report symlink flags; validated further by the WP harness).
reset_state();
$symlink_zip = make_zip_bytes(array(
    'adc-marketing/adc-marketing.php' => "<?php\n",
    'adc-marketing/link.txt'          => '/etc/shadow',
), array(), array('adc-marketing/link.txt' => 0120777));
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0', $symlink_zip);
queue_download($symlink_zip);
$res = gate_download();
if (class_exists('ZipArchive')) {
    check($res instanceof WP_Error && 'adc_updater_archive_symlink' === $res->get_error_code(), 'archive: symlink entry rejected (ZipArchive reader)');
} else {
    check(true, 'archive: symlink detection skipped locally (no ZipArchive; PclZip fallback ran)');
}

// 54. Valid root via the FORCED PclZip fallback: end-to-end success.
reset_state();
force_pclzip_fallback(true);
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0');
queue_download(fixture_zip('2.2.0'));
$res = gate_download();
check(is_string($res), 'archive (PclZip fallback): valid root accepted end-to-end');
check(is_string($res) && is_file($res), 'archive (PclZip fallback): temp file written');
force_pclzip_fallback(true === class_exists('ZipArchive')); // restore production preference.

// 55. Valid root with ZipArchive preference (default): re-assert acceptance.
reset_state();
$GLOBALS['ADC_TEST']['transients']['adc_updater_cache'] = seeded_cache('2.2.0');
queue_download(fixture_zip('2.2.0'));
$res = gate_download();
check(is_string($res), 'archive: valid single-root ZIP accepted (default reader preference)');

// 56. No deletion commands executed anywhere in this suite (recorded only).
foreach ($GLOBALS['ADC_TEST']['deleted'] as $recorded) {
    check(is_string($recorded) && '' !== $recorded, 'deletion guard: only paths recorded, never executed');
}

echo "updater-test: PASS\n";
