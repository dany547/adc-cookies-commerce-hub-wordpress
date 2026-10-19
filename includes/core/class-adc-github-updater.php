<?php
/**
 * Secure, additive GitHub release updater (docs: release contract v1).
 *
 * Design goals (supply-chain first — this class downloads executable code):
 *
 *  1. SCOPED. Uses the WordPress 5.8+ `update_plugins_{$hostname}` filter, which
 *     core only fires for plugins whose `Update URI:` header host matches. We
 *     additionally bail unless the plugin file is ours, so other plugins that
 *     happen to use a github.com Update URI are never answered by us. The global
 *     `update_plugins` transient is never written by this class.
 *  2. PINNED. Every outbound URL is a compile-time constant (metadata API) or a
 *     validated exact release-asset URL (scheme https, host, owner/repo, tag and
 *     file name all checked against an allowlist pattern). No branch zipballs,
 *     no source archives, no arbitrary download hosts, no fallbacks.
 *  3. VERIFIED. The release must carry a strictly-shaped `update-manifest.json`
 *     asset (version == tag, requires/requires_php/tested, package_url, sha256)
 *     and a `SHA256SUMS` asset whose entry for the ZIP equals the manifest hash.
 *     The downloaded ZIP bytes are re-hashed before any temp file is handed to
 *     the core upgrader; any mismatch or missing hash fails closed.
 *  4. PRIVATE. Static User-Agent for our pinned URLs only (the default WP UA
 *     leaks the site URL). No cookies, no tokens, no telemetry, no PII, no
 *     site-identifying data in any request. Outbound traffic happens only in
 *     admin/cron/CLI update contexts and for admin-requested upgrades.
 *  5. CONSERVATIVE. Only newer stable semver releases (vX.Y.Z) are offered.
 *     Drafts, prereleases and malformed responses are ignored. Compatibility
 *     (requires/requires_php) is surfaced to core so WordPress' own auto-update
 *     policy and warnings apply, and is re-enforced at download time (fail
 *     closed). Auto-updates are never force-enabled (`autoupdate` is not set).
 *
 * Release contract (produced by the release pipeline, repo
 * `dany547/adc-cookies-commerce-hub-wordpress`):
 *  - stable tag `vX.Y.Z`, ZIP asset `adc-marketing-X.Y.Z.zip` with root dir
 *    `adc-marketing/`. The digest alone does NOT prove a correct root (a
 *    manifest built around a wrong-root ZIP would match its own hash), so a
 *    pre-extraction archive inspection enforces the layout: single root
 *    `adc-marketing/`, required `adc-marketing/adc-marketing.php`, no
 *    absolute/traversal/backslash/drive/symlink entries, bounded entry count
 *    and declared uncompressed size (read-only listing — ZipArchive when
 *    available, else WP core PclZip; inspection impossible ⇒ fail closed).
 *  - `SHA256SUMS` asset (standard `sha256sum -b` lines),
 *  - `update-manifest.json` asset: {version, requires, requires_php, tested,
 *    wc_requires, package_url, sha256} — package_url must itself be an exact
 *    https allowlisted asset URL (raw-string validation, not canonicalized).
 *
 * Trust root note: manifest, SHA256SUMS and ZIP all come from the same GitHub
 * release, so the SHA-256 checks provide INTEGRITY, not authenticity — the
 * authenticity root is write access to the repository itself.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub release updater for ADC Marketing ("ADC Cookies & Commerce Hub").
 */
final class ADC_Github_Updater implements ADC_Module_Interface
{
    // ------------------------------------------------------------- contract.

    private const HOST            = 'github.com';
    private const CDN_HOST        = 'objects.githubusercontent.com';
    private const OWNER           = 'dany547';
    private const REPO            = 'adc-cookies-commerce-hub-wordpress';
    private const SLUG            = 'adc-marketing';
    private const PLUGIN_DIR_NAME = 'adc-marketing';                   // required single ZIP root.
    private const PLUGIN_BASENAME = 'adc-marketing/adc-marketing.php';
    private const REQUIRED_ENTRY  = 'adc-marketing/adc-marketing.php'; // required ZIP entry.

    /** Exact Update URI the plugin header must declare (host part drives the filter). */
    private const UPDATE_URI = 'https://github.com/dany547/adc-cookies-commerce-hub-wordpress';

    /** Authoritative metadata endpoint. Public repo: no token is ever sent. */
    private const API_LATEST = 'https://api.github.com/repos/dany547/adc-cookies-commerce-hub-wordpress/releases/latest';

    /** Static, non-identifying UA (default WP UA would leak the site URL). */
    private const USER_AGENT = 'ADC-Marketing-Updater/2.2 (+https://github.com/dany547/adc-cookies-commerce-hub-wordpress)';

    // ------------------------------------------------------------- limits.

    private const TTL_OK_SEC      = 43200;    // 12 h success cache.
    private const TTL_ERROR_SEC   = 900;      // 15 min error cache.
    private const TIMEOUT_META    = 10;       // metadata requests (seconds).
    private const TIMEOUT_ZIP     = 180;      // package download (seconds).
    private const MAX_API_BYTES   = 1048576;  // 1 MiB API response cap.
    private const MAX_JSON_BYTES  = 65536;    // 64 KiB manifest/SUMS cap.
    private const MAX_ZIP_BYTES   = 67108864; // 64 MiB package cap (bounds DoS; digest still gates).
    private const MAX_NOTES_BYTES = 8192;     // changelog body cached in the site transient (autoload-size bound).
    private const MAX_ARCHIVE_ENTRIES = 2000;      // pre-extraction listing cap.
    private const MAX_ARCHIVE_BYTES   = 268435456; // 256 MiB declared uncompressed total (zip-bomb bound).

    /** Cache schema version (invalidates stale shapes lazily on read). */
    private const CACHE_VERSION = 1;
    private const CACHE_KEY     = 'adc_updater_cache';

    /**
     * Boot guard (ADC_Module_Interface idempotency).
     *
     * @var bool
     */
    private static bool $booted = false;

    /**
     * Prefer ZipArchive for the read-only archive inspection when both readers
     * are available (deterministic branch selection; the PclZip fallback is
     * used whenever ZipArchive is absent). Production behavior is `true`.
     *
     * @var bool
     */
    private static bool $prefer_ziparchive = true;

    // ------------------------------------------------------------- boot.

    /**
     * Register the updater hooks. Idempotent.
     *
     * Qwen wiring: add `'ADC_Github_Updater'` to the `$modules` list in
     * ADC_Marketing::boot_modules() (includes/class-adc-marketing.php); the
     * autoloader resolves includes/core/class-adc-github-updater.php.
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        // Modern (WP 5.8+) scoped update check; core fires this only for plugins
        // whose `Update URI:` header host is github.com. Core does the final
        // version_compare and transient assembly — we never touch the transient.
        add_filter('update_plugins_github.com', array(self::class, 'update_response'), 10, 4);

        // "View details" dialog (plugins_api), answered only for our slug.
        add_filter('plugins_api', array(self::class, 'plugin_information'), 10, 3);

        // Integrity-verified download for OUR plugin only (fail closed).
        add_filter('upgrader_pre_download', array(self::class, 'verified_download'), 10, 4);

        // Static UA for our pinned URLs only (site-URL privacy).
        add_filter('http_headers_useragent', array(self::class, 'user_agent'), 10, 2);
    }

    // ------------------------------------------------------------- update check.

    /**
     * `update_plugins_github.com` handler.
     *
     * Contract (wp-includes/update.php, wp_update_plugins()):
     * params: (false|object $update, array $plugin_data, string $plugin_file, string[] $locales).
     * Return falsy → "nothing from us" (core moves on); return an object with at
     * least `version`/`new_version` and a `package` → core validates and files it.
     * We return false on every failure mode — never a bool true, never a partial
     * object, never a corrupted transient (core owns the transient).
     *
     * @param mixed       $update      Incoming value (always false from core; passthrough if not).
     * @param mixed[]     $plugin_data Plugin headers.
     * @param string|null $plugin_file Plugin basename.
     * @param mixed       $locales     Installed locales (unused).
     * @return mixed False (no update) or the update object.
     */
    public static function update_response(mixed $update = false, array $plugin_data = array(), ?string $plugin_file = null, mixed $locales = array()): mixed
    {
        if (!empty($update)) {
            return $update; // Someone already answered — never override.
        }

        // Privacy/traffic gate: outbound requests only in admin, cron or CLI
        // contexts (wp_update_plugins() itself never runs on plain page views;
        // this guard additionally refuses to answer if something invokes it there).
        if (!self::allowed_context()) {
            return false;
        }

        // Scope: only our plugin. Other plugins sharing the github.com Update URI
        // host must not receive answers built from our release contract.
        if (self::plugin_basename() !== $plugin_file) {
            return false;
        }

        // Admin "Check again" force-refresh (bypasses our cache TTL, not core's).
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- core's own "force-check" convention; existence check only (read-only, never stored), triggers a refetch of the pinned metadata URL.
        $force = isset($_GET['force-check'])
            && is_admin()
            && function_exists('current_user_can')
            && current_user_can('update_plugins');

        $release = self::release_metadata($force);
        if (null === $release) {
            return false; // No validated release available — fail closed, silently.
        }

        // Only notify strictly newer stable versions.
        $current = isset($plugin_data['Version']) && is_string($plugin_data['Version']) && '' !== $plugin_data['Version']
            ? $plugin_data['Version']
            : self::current_version();
        if (!version_compare($release['version'], $current, '>')) {
            return false;
        }

        return self::build_update_object($release);
    }

    /**
     * Build the core-compatible update object from validated release metadata.
     *
     * `requires`, `requires_php` and `tested` are passed through so WordPress
     * renders its own compatibility warnings and applies its auto-update policy.
     * `autoupdate` is deliberately NOT set: auto-updates stay an admin opt-in.
     *
     * @param array<string, string> $release Validated release metadata.
     * @return object
     */
    private static function build_update_object(array $release): object
    {
        $object = new stdClass();

        $object->slug        = self::SLUG;
        $object->version     = $release['version'];
        $object->new_version = $release['version'];
        $object->url         = $release['url'];
        $object->package     = $release['package_url'];
        $object->requires    = $release['requires'];
        $object->requires_php = $release['requires_php'];
        $object->tested      = $release['tested'];

        return $object;
    }

    // ------------------------------------------------------------- plugins_api.

    /**
     * `plugins_api` handler: "View version details" dialog, scoped to our slug.
     *
     * Returns false untouched for anything that is not exactly our slug so the
     * WordPress.org flow for other plugins is unchanged. On any error we return
     * the incoming value (false) — core then falls back to its own behaviour.
     *
     * @param mixed       $result Incoming value (false from core; passthrough if set).
     * @param string      $action API action.
     * @param object|null $args   API arguments (->slug).
     * @return mixed False, or a details object for our slug.
     */
    public static function plugin_information(mixed $result = false, string $action = '', ?object $args = null): mixed
    {
        if (!empty($result)) {
            return $result;
        }
        if ('plugin_information' !== $action || null === $args || !isset($args->slug) || self::SLUG !== $args->slug) {
            return $result;
        }
        if (!self::allowed_context()) {
            return $result;
        }

        $release = self::release_metadata(false);
        if (null === $release) {
            return $result;
        }

        $info                 = new stdClass();
        $info->name           = self::display_name();
        $info->slug           = self::SLUG;
        $info->version        = $release['version'];
        $info->requires       = $release['requires'];
        $info->requires_php   = $release['requires_php'];
        $info->tested         = $release['tested'];
        $info->download_link  = $release['package_url'];
        $info->homepage       = self::UPDATE_URI;
        // Release notes are maintainer-authored but treated as untrusted input:
        // escaped before embedding into the (core-rendered) HTML sections.
        $info->sections       = array(
            'changelog' => '<h4>' . esc_html($release['version']) . '</h4><pre>' . esc_html($release['body']) . '</pre>',
        );

        return $info;
    }

    // ------------------------------------------------------------- verified download.

    /**
     * `upgrader_pre_download` handler: integrity gate for OUR plugin only.
     *
     * Core contract (WP_Upgrader::download_package()): return non-false to
     * short-circuit the default download — a string is used as the package file
     * path, a WP_Error aborts the upgrade. We return the incoming `$reply`
     * untouched for anything that is not this plugin's pinned package, so other
     * updates (plugins/themes/core/translations) are never intercepted.
     *
     * Fail-closed matrix: unrecognised/historical/foreign URL → WP_Error; no
     * valid cached pin → WP_Error; runtime incompatible with the pinned
     * requires/requires_php → WP_Error; HTTP failure → WP_Error; downloaded
     * bytes not matching the pinned SHA-256 → WP_Error (nothing is written to
     * the plugin directory, the previous install stays intact).
     *
     * @param mixed            $reply      Incoming value (false; passthrough otherwise).
     * @param string|null      $package    Package URL WP is about to download.
     * @param object|null      $upgrader   WP_Upgrader instance (unused).
     * @param array<string,mixed> $hook_extra Hook context (expects ['plugin' => basename]).
     * @return mixed False (not ours → default flow), local file path, or WP_Error.
     */
    public static function verified_download(mixed $reply = false, ?string $package = null, ?object $upgrader = null, array $hook_extra = array()): mixed
    {
        // Preserve an error raised by an earlier filter — never stomp it.
        if (is_wp_error($reply)) {
            return $reply;
        }

        // Scope: plugin updates of OUR plugin only. Anything else (themes,
        // other plugins, core, translations, missing context) passes through.
        if (
            !isset($hook_extra['plugin'], $hook_extra['type'])
            || 'plugin' !== $hook_extra['type']
            || self::plugin_basename() !== $hook_extra['plugin']
        ) {
            return $reply;
        }

        // The URL must be byte-identical to the pinned package_url from the last
        // validated metadata check. This rejects transient tampering, replayed
        // historical URLs, and any URL we did not pin ourselves.
        $release = self::valid_cached_release();
        if (
            null === $release
            || !is_string($package)
            || '' === $package
            || !hash_equals($release['package_url'], $package)
        ) {
            return self::download_error('adc_updater_unpinned', 'Actualizare anulată: pachetul nu corespunde verificării de securitate. Verifică din nou actualizările.');
        }

        // Re-enforce compatibility at install time (WP only warns; we refuse).
        if (
            !version_compare(PHP_VERSION, $release['requires_php'], '>=')
            || !version_compare(self::wp_version(), $release['requires'], '>=')
        ) {
            return self::download_error('adc_updater_incompatible', 'Actualizare anulată: versiunea necesită PHP ' . $release['requires_php'] . ' și WordPress ' . $release['requires'] . ' sau mai nou.');
        }

        $response = wp_safe_remote_get($package, array(
            'timeout'             => self::TIMEOUT_ZIP,
            'redirection'         => 3,
            'limit_response_size' => self::MAX_ZIP_BYTES,
            'headers'             => array(
                'Accept'     => 'application/octet-stream',
                // Explicit static UA (belt to the http_headers_useragent braces);
                // headers are carried through redirect hops by WP_Http.
                'User-Agent' => self::USER_AGENT,
            ),
        ));
        if (is_wp_error($response)) {
            return self::download_error('adc_updater_http', 'Descărcarea actualizării a eșuat (rețea).');
        }
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return self::download_error('adc_updater_http_status', 'Descărcarea actualizării a eșuat (răspuns HTTP neașteptat).');
        }
        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || '' === $body) {
            return self::download_error('adc_updater_empty', 'Descărcarea actualizării a eșuat (conținut gol).');
        }

        // THE supply-chain gate: re-hash the exact bytes we are about to hand to
        // the upgrader. Anything else (truncated, corrupted, swapped, tampered
        // redirect) fails closed before a single byte reaches the filesystem
        // location the upgrader unpacks from.
        $actual = hash('sha256', $body);
        if (!hash_equals($release['sha256'], $actual)) {
            return self::download_error('adc_updater_integrity', 'Actualizare anulată: verificarea integrității pachetului (SHA256) a eșuat.');
        }

        // Write the verified bytes to a bounded temp file; the core upgrader
        // unpacks it and cleans it up through WP_Filesystem (standard lifecycle).
        $tmp = function_exists('wp_tempnam')
            ? wp_tempnam(self::SLUG . '-' . $release['version'] . '.zip')
            : (string) tempnam(self::temp_dir(), 'adc-updater-');
        if (!is_string($tmp) || '' === $tmp) {
            return self::download_error('adc_updater_temp', 'Actualizare anulată: fișierul temporar nu a putut fi creat.');
        }
        $written = file_put_contents($tmp, $body); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- verified temp artifact, mirroring download_url().
        if (false === $written || $written !== strlen($body)) {
            self::cleanup_temp($tmp);
            return self::download_error('adc_updater_temp_write', 'Actualizare anulată: fișierul temporar nu a putut fi scris integral.');
        }

        // Pre-extraction archive guard (review hardening): a matching digest
        // does NOT prove a correct layout — a manifest built around a
        // wrong-root ZIP matches its own hash. Before anything is handed to the
        // core upgrader, list the archive read-only and enforce: single root
        // `adc-marketing/`, required `adc-marketing/adc-marketing.php`, no
        // absolute/traversal/backslash/drive/symlink entries, bounded entry
        // count and declared uncompressed size. Uninspectable ⇒ fail closed.
        // Core's Plugin_Upgrader is never given a package that would install a
        // differently-named folder (and thus remove/deactivate the plugin).
        $inspection = self::inspect_archive($tmp);
        if (is_wp_error($inspection)) {
            self::cleanup_temp($tmp);
            return $inspection;
        }

        return $tmp;
    }

    // ------------------------------------------------------------- archive inspection.

    /**
     * Read-only layout inspection of the downloaded ZIP (NO extraction).
     *
     * Enforces, in order: readable listing, bounded entry count, per-entry
     * path safety (NUL/backslash/absolute/drive/traversal segments), single
     * root `adc-marketing/`, bounded declared uncompressed total, and the
     * required `adc-marketing/adc-marketing.php` entry. Read via ZipArchive
     * when available (symlink detection included), else WP core PclZip
     * `listContent()` (symlink flags not reported by PclZip — documented
     * limitation of the fallback). No reader ⇒ fail closed.
     *
     * @param string $path Temp ZIP path.
     * @return bool|WP_Error True when the layout matches the contract.
     */
    private static function inspect_archive(string $path): bool|object
    {
        $entries = self::archive_entries($path);
        if (is_wp_error($entries)) {
            return self::download_error('adc_updater_archive_read', 'Actualizare anulată: arhiva nu a putut fi inspectată în siguranță (structură ilegibilă).');
        }
        if (count($entries) > self::MAX_ARCHIVE_ENTRIES) {
            return self::download_error('adc_updater_archive_entries', 'Actualizare anulată: arhiva conține prea multe intrări.');
        }

        $total      = 0;
        $main_found = false;
        foreach ($entries as $entry) {
            if (!empty($entry['symlink'])) {
                return self::download_error('adc_updater_archive_symlink', 'Actualizare anulată: arhiva conține link-uri simbolice.');
            }
            $problem = self::entry_problem((string) $entry['name']);
            if (null !== $problem) {
                return 'entry_root' === $problem
                    ? self::download_error('adc_updater_archive_root', 'Actualizare anulată: arhiva nu are rădăcina unică „adc-marketing/”.')
                    : self::download_error('adc_updater_archive_entry', 'Actualizare anulată: arhiva conține căi de fișier nesigure.');
            }
            $total += (int) $entry['size'];
            if ($total > self::MAX_ARCHIVE_BYTES) {
                return self::download_error('adc_updater_archive_size', 'Actualizare anulată: dimensiunea declarată a arhivei este suspect de mare.');
            }
            if (self::REQUIRED_ENTRY === (string) $entry['name']) {
                $main_found = true;
            }
        }
        if (!$main_found) {
            return self::download_error('adc_updater_archive_missing_entry', 'Actualizare anulată: lipsește fișierul principal al pluginului din arhivă.');
        }

        return true;
    }

    /**
     * Classify one archive entry name; returns the problem code or null.
     *
     * Windows separators are rejected outright (ZIP spec says "/"), as are
     * NUL bytes, absolute paths (leading "/", UNC "//"), drive letters
     * ("C:"), empty/"."/".." segments, and any first segment other than the
     * plugin's own folder name.
     *
     * @param string $raw Raw entry name from the archive listing.
     * @return string|null 'entry_root', 'entry_unsafe', or null when safe.
     */
    private static function entry_problem(string $raw): ?string
    {
        if ('' === $raw || str_contains($raw, "\0") || str_contains($raw, '\\')) {
            return 'entry_unsafe';
        }
        if ((bool) preg_match('#^([A-Za-z]:|/)#', $raw)) {
            return 'entry_unsafe'; // absolute, UNC, or drive-letter path.
        }
        // Directory entries may carry exactly ONE trailing slash — strip it;
        // anything left (bare root "/", interior "//", ".", "..") stays unsafe.
        $normalized = str_ends_with($raw, '/') ? substr($raw, 0, -1) : $raw;
        if ('' === $normalized) {
            return 'entry_unsafe';
        }
        $segments = explode('/', $normalized);
        if (!isset($segments[0]) || self::PLUGIN_DIR_NAME !== $segments[0]) {
            return 'entry_root'; // single-root rule: every entry lives under adc-marketing/.
        }
        foreach ($segments as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return 'entry_unsafe';
            }
        }
        return null;
    }

    /**
     * List archive entries read-only: array{name: string, size: int, symlink: bool}[]
     * or WP_Error when the archive cannot be inspected (fail closed upstream).
     *
     * @param string $path ZIP path.
     * @return array<int, array{name: string, size: int, symlink: bool}>|WP_Error
     */
    private static function archive_entries(string $path): array|object
    {
        if (self::$prefer_ziparchive && class_exists('ZipArchive')) {
            return self::entries_via_ziparchive($path);
        }
        return self::entries_via_pclzip($path);
    }

    /**
     * ZipArchive listing (also detects Unix symlink entries via external attrs).
     *
     * @param string $path ZIP path.
     * @return array<int, array{name: string, size: int, symlink: bool}>|WP_Error
     */
    private static function entries_via_ziparchive(string $path): array|object
    {
        $zip = new ZipArchive();
        // Explicit read-only open: inspection never mutates the archive.
        if (true !== $zip->open($path, defined('ZipArchive::RDONLY') ? ZipArchive::RDONLY : 0)) {
            return new WP_Error('adc_updater_zip_open', 'not a readable zip archive');
        }
        $entries = array();
        $count   = (int) $zip->numFiles;
        for ($i = 0; $i < $count; $i++) {
            $name = $zip->getNameIndex($i);
            $stat = $zip->statIndex($i);
            if (!is_string($name) || '' === $name || !is_array($stat) || !isset($stat['size']) || (int) $stat['size'] < 0) {
                $zip->close();
                return new WP_Error('adc_updater_zip_entry', 'unreadable archive entry');
            }
            $symlink = false;
            $opsys   = 0;
            $attr    = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && 3 === (int) $opsys) {
                $symlink = 0xA000 === ((((int) $attr >> 16) & 0xFFFF) & 0xF000); // S_IFLNK.
            }
            $entries[] = array('name' => $name, 'size' => (int) $stat['size'], 'symlink' => $symlink);
        }
        $zip->close();
        return $entries;
    }

    /**
     * WP core PclZip listing fallback (read-only; no extraction, no writes).
     *
     * @param string $path ZIP path.
     * @return array<int, array{name: string, size: int, symlink: bool}>|WP_Error
     */
    private static function entries_via_pclzip(string $path): array|object
    {
        if (!class_exists('PclZip')) {
            $pcl = defined('ABSPATH') ? rtrim((string) constant('ABSPATH'), '/') . '/wp-admin/includes/class-pclzip.php' : '';
            if ('' !== $pcl && is_readable($pcl)) {
                require_once $pcl; // phpcs:ignore WordPress.WP.AlternativeFunctions.require_once -- WP-core bundled library load.
            }
        }
        if (!class_exists('PclZip')) {
            return new WP_Error('adc_updater_no_reader', 'no archive reader available');
        }
        $archive = new PclZip($path);
        $list    = $archive->listContent();
        if (!is_array($list) || array() === $list) {
            return new WP_Error('adc_updater_zip_list', 'archive listing failed');
        }
        $entries = array();
        foreach ($list as $item) {
            // Real PclZip listContent() shape (privConvertHeader2FileInfo):
            // filename, stored_filename, size (UNCOMPRESSED), compressed_size,
            // mtime, comment, folder, index, status, crc. The uncompressed
            // size lives under 'size' — absence of it fails closed.
            if (
                !is_array($item)
                || !isset($item['filename'], $item['size'])
                || !is_string($item['filename'])
                || '' === $item['filename']
                || !is_int($item['size'])
                || $item['size'] < 0
            ) {
                return new WP_Error('adc_updater_zip_entry', 'unreadable archive entry');
            }
            // Optional verification: when PclZip reports compressed_size it
            // must be a sane non-negative int (always set by real PclZip;
            // tolerated absent for resilience against reader variants).
            if (isset($item['compressed_size']) && (!is_int($item['compressed_size']) || $item['compressed_size'] < 0)) {
                return new WP_Error('adc_updater_zip_entry', 'unreadable archive entry');
            }
            // PclZip does not surface symlink flags (documented limitation).
            $entries[] = array('name' => $item['filename'], 'size' => $item['size'], 'symlink' => false);
        }
        return $entries;
    }

    // ------------------------------------------------------------- user agent.

    /**
     * `http_headers_useragent` handler: static UA for our pinned URLs only.
     *
     * The default WordPress UA contains the site URL; it must not be sent with
     * our requests. The filter receives the URL as second argument (WP 5.1+), so
     * we scope strictly: exact metadata URL, our repo's release-download prefix,
     * and GitHub's release CDN host (our asset redirect hops land there and the
     * original URL is no longer visible). Everything else passes through
     * untouched, including other plugins' requests to unrelated hosts.
     *
     * @param string      $default_ua Default UA string from core.
     * @param string|null $url        Request URL.
     * @return string
     */
    public static function user_agent(string $default_ua = '', ?string $url = null): string
    {
        if (!is_string($url) || '' === $url) {
            return $default_ua;
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || !is_string($parts['host'])) {
            return $default_ua;
        }
        $host = strtolower($parts['host']);
        $path = is_string($parts['path'] ?? null) ? $parts['path'] : '';

        $is_our_api    = ('api.github.com' === $host && self::API_PATH === $path);
        $is_our_assets = (self::HOST === $host && str_starts_with($path, self::ASSET_PATH_PREFIX));
        $is_cdn_hop    = (self::CDN_HOST === $host);

        if ($is_our_api || $is_our_assets || $is_cdn_hop) {
            return self::USER_AGENT;
        }

        return $default_ua;
    }

    // ------------------------------------------------------------- release metadata.

    /**
     * Fetch + validate the latest stable release, with layered caching.
     *
     * Success is cached 12 h, errors 15 min; an error never evicts a still-valid
     * success entry (availability without staleness). Returns null on any
     * failure — callers treat null as "no update information".
     *
     * @param bool $force Bypass the success TTL (admin "Check again").
     * @return array<string, string>|null Validated release metadata, or null.
     */
    private static function release_metadata(bool $force = false): ?array
    {
        $cache = self::read_cache();
        $now   = time();

        if (!$force && isset($cache['good']['release'], $cache['good']['expires']) && $now < (int) $cache['good']['expires']) {
            return $cache['good']['release'];
        }
        if (!$force && isset($cache['error']['expires']) && $now < (int) $cache['error']['expires']) {
            return null;
        }

        $validated = self::fetch_and_validate_release();

        if (is_array($validated)) {
            self::write_cache(
                array(
                    'good'  => array('release' => $validated, 'expires' => $now + self::TTL_OK_SEC),
                    'error' => null,
                    'v'     => self::CACHE_VERSION,
                ),
                self::TTL_OK_SEC // WP-level expiration too (no permanent/autoloaded row).
            );
            return $validated;
        }

        // Error: cache for 15 min but PRESERVE the previous good entry untouched
        // (a transient GitHub outage must not invalidate a fresh, verified pin).
        $cache['v']     = self::CACHE_VERSION;
        $cache['error'] = array('code' => 'fetch_failed', 'expires' => $now + self::TTL_ERROR_SEC);
        self::write_cache($cache, self::TTL_ERROR_SEC);

        return null;
    }

    /**
     * Return the still-valid cached release pin (used by the download gate).
     *
     * @return array<string, string>|null
     */
    private static function valid_cached_release(): ?array
    {
        $cache = self::read_cache();
        if (isset($cache['good']['release'], $cache['good']['expires']) && time() < (int) $cache['good']['expires']) {
            return $cache['good']['release'];
        }
        return null;
    }

    /**
     * Fetch and strictly validate the whole release: API → tag → assets →
     * manifest → checksums. Any deviation fails closed (null).
     *
     * @return array<string, string>|null
     */
    private static function fetch_and_validate_release(): ?array
    {
        // --- 1. Latest release from the exact API URL.
        $api = self::http_get(self::API_LATEST, self::MAX_API_BYTES, self::TIMEOUT_META, 'application/vnd.github+json');
        if (null === $api || wp_remote_retrieve_response_code($api) !== 200) {
            return null;
        }
        $payload = json_decode((string) wp_remote_retrieve_body($api), true);
        if (!is_array($payload)) {
            return null; // 404, rate limit, offline, malformed JSON, … all fail closed.
        }

        // --- 2. Channel + tag: stable, published, strict vX.Y.Z only.
        if (isset($payload['draft']) && true === $payload['draft']) {
            return null;
        }
        if (isset($payload['prerelease']) && true === $payload['prerelease']) {
            return null;
        }
        $tag = isset($payload['tag_name']) && is_string($payload['tag_name']) ? $payload['tag_name'] : '';
        if (!preg_match('/^v(\d+\.\d+\.\d+)$/', $tag, $m)) {
            return null;
        }
        $version = $m[1];

        // --- 3. Assets: exact expected names, allowlisted exact URLs.
        $assets = $payload['assets'] ?? null;
        if (!is_array($assets)) {
            return null;
        }
        $zip_name     = self::SLUG . '-' . $version . '.zip';
        $zip_url      = null;
        $manifest_url = null;
        $sums_url     = null;
        $html_url     = null;
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = isset($asset['name']) && is_string($asset['name']) ? $asset['name'] : '';
            $url  = isset($asset['browser_download_url']) && is_string($asset['browser_download_url']) ? $asset['browser_download_url'] : '';
            if ('' === $url || !self::is_allowlisted_asset_url($url, $tag, $name)) {
                continue; // Foreign/malformed asset URLs are ignored, never used.
            }
            $asset_url = self::normalize_asset_url($url);
            if ($name === $zip_name) {
                $zip_url = $asset_url;
            } elseif ('update-manifest.json' === $name) {
                $manifest_url = $asset_url;
            } elseif ('SHA256SUMS' === $name) {
                $sums_url = $asset_url;
            }
        }
        if (null === $zip_url || null === $manifest_url || null === $sums_url) {
            return null; // Release does not match the contract (wrong/missing assets).
        }

        // Canonical release page URL for core's "details" link (validated; strict
        // fallback to the constant repo URL if the API returns something odd).
        $candidate = isset($payload['html_url']) && is_string($payload['html_url']) ? $payload['html_url'] : '';
        $parts     = is_string($candidate) && '' !== $candidate ? wp_parse_url($candidate) : null;
        $html_url  = (is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === self::HOST
            && str_starts_with((string) ($parts['path'] ?? ''), '/' . self::OWNER . '/' . self::REPO . '/'))
            ? $candidate
            : self::UPDATE_URI;

        // --- 4. Manifest: strict shape; ties version, package URL and digest.
        $manifest_raw = self::http_body($manifest_url, self::MAX_JSON_BYTES, self::TIMEOUT_META, 'application/json');
        if (null === $manifest_raw) {
            return null;
        }
        $manifest = json_decode($manifest_raw, true);
        if (!is_array($manifest)) {
            return null; // Includes truncated bodies (json_decode fails).
        }
        $version_ok = isset($manifest['version']) && is_string($manifest['version']) && $manifest['version'] === $version;
        $sha256     = isset($manifest['sha256']) && is_string($manifest['sha256']) ? strtolower($manifest['sha256']) : '';
        $sha256_ok  = (bool) preg_match('/^[0-9a-f]{64}$/', $sha256);
        $package_ok = isset($manifest['package_url'])
            && is_string($manifest['package_url'])
            // RAW-string contract: package_url must itself be the exact https
            // allowlisted asset URL (no canonicalization — http://, ports,
            // userinfo, query, fragment or foreign repo/tag/name are refused).
            && self::is_allowlisted_asset_url($manifest['package_url'], $tag, $zip_name)
            && hash_equals($zip_url, self::normalize_asset_url($manifest['package_url']));

        $requires    = self::constraint_string($manifest['requires'] ?? null);
        $requires_php = self::constraint_string($manifest['requires_php'] ?? null);
        $tested      = self::constraint_string($manifest['tested'] ?? null);
        $wc_requires = self::constraint_string($manifest['wc_requires'] ?? null);

        // All contract fields (incl. the WooCommerce minimum) must validate;
        // an out-of-contract release is treated as no release (fail closed).
        if (!$version_ok || !$sha256_ok || !$package_ok || null === $requires || null === $requires_php || null === $tested || null === $wc_requires) {
            return null;
        }

        // --- 5. SHA256SUMS: independent attestation that must agree with the manifest.
        $sums_raw = self::http_body($sums_url, self::MAX_JSON_BYTES, self::TIMEOUT_META, 'text/plain');
        if (null === $sums_raw || !self::sums_contain($sums_raw, $zip_name, $sha256)) {
            return null;
        }

        // --- 6. Release notes for the details dialog: byte-capped (bounds the
        // cached site transient; the WP-level TTL below limits its lifetime too).
        // Untrusted input → escaped at render time.
        $body = isset($payload['body']) && is_string($payload['body']) ? $payload['body'] : '';
        $body = substr($body, 0, self::MAX_NOTES_BYTES);

        return array(
            'version'      => $version,
            'package_url'  => $zip_url,
            'sha256'       => $sha256,
            'requires'     => $requires,
            'requires_php' => $requires_php,
            'tested'       => $tested,
            'wc_requires'  => $wc_requires,
            'url'          => $html_url,
            'body'         => $body,
        );
    }

    // ------------------------------------------------------------- HTTP helpers.

    /**
     * Bounded GET via the WordPress HTTP API (TLS-verified, size-capped).
     *
     * @param string $url                 Exact, previously-allowlisted URL.
     * @param int    $limit_response_size Max bytes.
     * @param int    $timeout             Seconds.
     * @param string $accept              Accept header value.
     * @return array<string, mixed>|WP_Error|null Core response array, WP_Error, or null on unexpected shape.
     */
    private static function http_get(string $url, int $limit_response_size, int $timeout, string $accept): array|object|null
    {
        $response = wp_safe_remote_get($url, array(
            'timeout'             => $timeout,
            'redirection'         => 2,
            'limit_response_size' => $limit_response_size,
            'headers'             => array(
                'Accept'     => $accept,
                // Explicit static UA (belt to the http_headers_useragent braces).
                'User-Agent' => self::USER_AGENT,
            ),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        return is_array($response) ? $response : null;
    }

    /**
     * GET a small text body (manifest / checksums); null on any failure.
     *
     * @param string $url  Allowlisted asset URL.
     * @param int    $max  Max bytes.
     * @param int    $timeout  Seconds.
     * @param string $accept  Accept header value.
     * @return string|null
     */
    private static function http_body(string $url, int $max, int $timeout, string $accept): ?string
    {
        $response = self::http_get($url, $max, $timeout, $accept);
        if (null === $response || is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $body = wp_remote_retrieve_body($response);
        return (is_string($body) && '' !== $body) ? $body : null;
    }

    // ------------------------------------------------------------- URL allowlist.

    /** Path of the metadata endpoint (for UA scoping). */
    private const API_PATH = '/repos/dany547/adc-cookies-commerce-hub-wordpress/releases/latest';

    /** Prefix of our release-asset download URLs (for UA scoping). */
    private const ASSET_PATH_PREFIX = '/dany547/adc-cookies-commerce-hub-wordpress/releases/download/';

    /**
     * True when $url is EXACTLY the canonical download URL of a release asset:
     * https + github.com + /{owner}/{repo}/releases/download/{tag}/{name}.
     *
     * @param string $url  Candidate URL.
     * @param string $tag  Expected tag (vX.Y.Z).
     * @param string $name Expected asset file name.
     * @return bool
     */
    private static function is_allowlisted_asset_url(string $url, string $tag, string $name): bool
    {
        if (false !== strpos($url, '@') || false !== stripos($url, 'javascript:')) {
            return false; // userinfo or exotic scheme fragments — refuse outright.
        }
        if (str_contains($url, '..')) {
            return false; // path-traversal bytes anywhere — refuse outright (defense in depth).
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        if (($parts['scheme'] ?? null) !== 'https') {
            return false;
        }
        if (strtolower((string) ($parts['host'] ?? '')) !== self::HOST) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $expected_path = '/' . self::OWNER . '/' . self::REPO . '/releases/download/' . rawurlencode($tag) . '/' . rawurlencode($name);
        return hash_equals($expected_path, (string) ($parts['path'] ?? ''));
    }

    /**
     * Canonical (decoded) asset URL for storage/comparison. HTTPS-only: any
     * non-https input yields '' so it can never masquerade as a pinned URL.
     *
     * @param string $url Allowlisted (or candidate) URL.
     * @return string
     */
    private static function normalize_asset_url(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return '';
        }
        $path = rawurldecode((string) ($parts['path'] ?? ''));
        return 'https://' . strtolower((string) ($parts['host'] ?? '')) . $path;
    }

    // ------------------------------------------------------------- checksums.

    /**
     * True when the `sha256sum`-style listing pins $name to exactly $sha256.
     *
     * Lines look like "<64 hex>  <name>" (or " *<name>" for binary mode). Only a
     * match for the exact file name counts; mismatches fail closed upstream.
     *
     * @param string $sums   Raw SHA256SUMS contents.
     * @param string $name   Asset file name.
     * @param string $sha256 Expected lowercase hex digest.
     * @return bool
     */
    private static function sums_contain(string $sums, string $name, string $sha256): bool
    {
        $pattern = '/^[0-9a-fA-F]{64}[ \t]+\*?' . preg_quote($name, '/') . '[ \t]*$/m';
        if (!preg_match($pattern, $sums, $m2)) {
            return false;
        }
        // Extract the digest from the matched line and compare exactly.
        if (!preg_match('/^([0-9a-fA-F]{64})/m', $m2[0], $digest)) {
            return false;
        }
        return hash_equals($sha256, strtolower($digest[1]));
    }

    // ------------------------------------------------------------- small helpers.

    /**
     * Validate a semver-ish constraint string from the manifest ("6.8", "8.2").
     *
     * @param mixed $value Raw manifest value.
     * @return string|null Validated string or null.
     */
    private static function constraint_string(mixed $value): ?string
    {
        return (is_string($value) && (bool) preg_match('/^\d+\.\d+$/', $value)) ? $value : null;
    }

    /**
     * Read + shape-validate our cache (corrupt shapes are treated as empty).
     *
     * @return array<string, mixed>
     */
    private static function read_cache(): array
    {
        $raw = get_site_transient(self::CACHE_KEY);
        if (!is_array($raw) || ($raw['v'] ?? null) !== self::CACHE_VERSION) {
            return array('v' => self::CACHE_VERSION, 'good' => null, 'error' => null);
        }
        $cache = array('v' => self::CACHE_VERSION, 'good' => null, 'error' => null);

        $good = $raw['good'] ?? null;
        if (is_array($good) && isset($good['release'], $good['expires']) && is_array($good['release']) && self::release_shape_valid($good['release'])) {
            $cache['good'] = $good;
        }
        $error = $raw['error'] ?? null;
        if (is_array($error) && isset($error['expires'])) {
            $cache['error'] = $error;
        }

        return $cache;
    }

    /**
     * Persist the cache through the object-cache-aware site transient API,
     * with a WP-level expiration (keeps the row from living forever as an
     * autoloaded option on older WordPress versions).
     *
     * @param array<string, mixed> $cache      Cache payload.
     * @param int                  $expiration Seconds until WP-level expiry.
     */
    private static function write_cache(array $cache, int $expiration = 0): void
    {
        set_site_transient(self::CACHE_KEY, $cache, $expiration);
    }

    /**
     * Validate the internal release-array shape (defense against corrupt cache).
     *
     * @param mixed $release Candidate release array.
     * @return bool
     */
    private static function release_shape_valid(mixed $release): bool
    {
        foreach (array('version', 'package_url', 'sha256', 'requires', 'requires_php', 'tested', 'wc_requires', 'url') as $key) {
            if (!isset($release[$key]) || !is_string($release[$key]) || '' === $release[$key]) {
                return false;
            }
        }
        return (bool) preg_match('/^\d+\.\d+\.\d+$/', (string) $release['version'])
            && (bool) preg_match('/^[0-9a-f]{64}$/', (string) $release['sha256'])
            && (bool) preg_match('/^\d+\.\d+$/', (string) $release['requires'])
            && (bool) preg_match('/^\d+\.\d+$/', (string) $release['requires_php'])
            && (bool) preg_match('/^\d+\.\d+$/', (string) $release['wc_requires']);
    }

    /**
     * Outbound-context gate: admin, cron or CLI only — never storefront traffic.
     *
     * @return bool
     */
    private static function allowed_context(): bool
    {
        if (is_admin() || wp_doing_cron()) {
            return true;
        }
        return defined('WP_CLI') && constant('WP_CLI');
    }

    /**
     * Plugin basename (identity preserved: derived from the plugin constant).
     *
     * @return string
     */
    private static function plugin_basename(): string
    {
        return defined('ADC_MARKETING_PLUGIN_BASENAME') ? (string) constant('ADC_MARKETING_PLUGIN_BASENAME') : self::PLUGIN_BASENAME;
    }

    /**
     * Currently installed plugin version (plugin header constant).
     *
     * @return string
     */
    private static function current_version(): string
    {
        return defined('ADC_MARKETING_VERSION') ? (string) constant('ADC_MARKETING_VERSION') : '0.0.0';
    }

    /**
     * Running WordPress version.
     *
     * @return string
     */
    private static function wp_version(): string
    {
        $version = get_bloginfo('version');
        return is_string($version) && '' !== $version ? $version : '0.0';
    }

    /**
     * Plugin display name, read from the plugin header (single source of truth
     * for the rebrand), with a neutral fallback.
     *
     * @return string
     */
    private static function display_name(): string
    {
        $name = '';
        if (defined('ADC_MARKETING_FILE') && function_exists('get_file_data')) {
            $headers = get_file_data(constant('ADC_MARKETING_FILE'), array('Name' => 'Plugin Name'));
            if (is_array($headers) && isset($headers['Name']) && is_string($headers['Name'])) {
                $name = trim($headers['Name']);
            }
        }
        return '' !== $name ? $name : self::SLUG;
    }

    /**
     * Temp directory (WP helper when available).
     *
     * @return string
     */
    private static function temp_dir(): string
    {
        if (function_exists('get_temp_dir')) {
            $dir = get_temp_dir();
            return is_string($dir) && '' !== $dir ? $dir : (sys_get_temp_dir() . '/');
        }
        return sys_get_temp_dir() . '/';
    }

    /**
     * Delete our own temp artifact through the WP API (lifecycle cleanup only).
     *
     * @param string $path Temp file path.
     */
    private static function cleanup_temp(string $path): void
    {
        if (is_string($path) && '' !== $path && is_file($path)) {
            if (function_exists('wp_delete_file')) {
                wp_delete_file($path);
            } else {
                unlink($path); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_unlink -- non-WP test/bootstrap fallback for our own temp file only.
            }
        }
    }

    /**
     * Consistent WP_Error builder for the download gate (localized, text domain).
     *
     * @param string $code    Error code.
     * @param string $message Romanian message (admin-facing).
     * @return WP_Error
     */
    private static function download_error(string $code, string $message): object
    {
        return new WP_Error($code, __('ADC Marketing: ', 'adc-marketing') . $message);
    }
}
