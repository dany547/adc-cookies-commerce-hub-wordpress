<?php
/**
 * Standalone contract test for the deterministic release pipeline
 * (bin/build-release.sh + bin/lib/build_release.py + bin/verify-release.sh).
 *
 * Checks (real repository, read-only; builds go to fresh mktemp dirs that are
 * intentionally left in /tmp — nothing is deleted or overwritten by this test):
 *   1. build succeeds, produces adc-marketing-<ver>.zip + update-manifest.json
 *      + SHA256SUMS, verify-release.sh passes.
 *   2. the zip has the single root folder adc-marketing/, contains the runtime
 *      allowlist (entry, includes, assets, templates, languages, README.txt,
 *      LICENSE) and excludes dev/internal paths.
 *   3. rebuilding into a second fresh dir yields the SAME sha256
 *      (deterministic output).
 *   4. rebuilding into the SAME dir is refused (no overwrite, non-zero exit).
 *   5. manifest matches the plugin header + canonical package_url + digest.
 *
 * Requires: bash, python3, sha256sum. No PHP zip extension needed.
 *
 * Run: php tests/release/build-test.php
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
$PASS = 0;
$FAIL = 0;

function ok(string $name): void
{
    global $PASS;
    $PASS++;
    echo "OK   $name\n";
}

function not_ok(string $name, string $detail = ''): void
{
    global $FAIL;
    $FAIL++;
    echo "FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
}

function run(string $cmd): array
{
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

function sha256(string $file): string
{
    [$code, $out] = run('sha256sum ' . escapeshellarg($file));
    return $code === 0 ? explode(' ', trim($out))[0] : '';
}

[$code, $out] = run("cd " . escapeshellarg($ROOT) . " && bash bin/version.sh current");
$VERSION = trim($out);
if ($code !== 0 || !preg_match('/^\d+\.\d+\.\d+$/', $VERSION)) {
    not_ok('resolve current version', $out);
    exit(1);
}
$ZIP = "adc-marketing-$VERSION.zip";

// Fresh build dirs (kept as artifacts in /tmp; never removed here).
[$code, $out] = run('mktemp -d');
$DIR1 = trim($out);
[$code, $out] = run('mktemp -d');
$DIR2 = trim($out);

// 1) build + verify
[$code, $out] = run("cd " . escapeshellarg($ROOT) . " && bash bin/build-release.sh --out " . escapeshellarg($DIR1));
if ($code === 0 && str_contains($out, 'OK release artifact verified')) {
    ok('build-release.sh --out new dir (includes verify pass)');
} else {
    not_ok('build-release.sh --out new dir', "code=$code\n$out");
    exit(1);
}
foreach ([$ZIP, 'update-manifest.json', 'SHA256SUMS'] as $artifact) {
    if (is_file("$DIR1/$artifact")) {
        ok("artifact present: $artifact");
    } else {
        not_ok("artifact present: $artifact");
    }
}

// 2) zip structure: root folder + allowlist + exclusions.
[$code, $listing] = run('zipinfo -1 ' . escapeshellarg("$DIR1/$ZIP"));
$names = explode("\n", trim($listing));
$root_ok = true;
foreach ($names as $n) {
    if (!str_starts_with($n, 'adc-marketing/')) {
        $root_ok = false;
        break;
    }
}
$root_ok ? ok('all entries under root adc-marketing/') : not_ok('all entries under root adc-marketing/');

$must_have = [
    'adc-marketing/adc-marketing.php',
    'adc-marketing/uninstall.php',
    'adc-marketing/LICENSE',
    'adc-marketing/README.txt',
    'adc-marketing/includes/class-adc-marketing.php',
    'adc-marketing/includes/core/class-adc-github-updater.php', // contract (GLM) — required once shipped
    'adc-marketing/assets/css/admin-shell.css',
    'adc-marketing/assets/js/datalayer.js',
    'adc-marketing/templates/myaccount/marketing-consent.php',
    'adc-marketing/languages/adc-marketing-ro_RO.mo',
];
$optional_missing = [];
foreach ($must_have as $m) {
    if (!in_array($m, $names, true)) {
        $optional_missing[] = $m;
    }
}
$core_expected = array_diff($must_have, ['adc-marketing/includes/core/class-adc-github-updater.php']);
$core_missing = array_intersect($core_expected, $optional_missing);
$core_missing === []
    ? ok('runtime allowlist present (entry/includes/assets/templates/languages/docs)')
    : not_ok('runtime allowlist present', implode(', ', $core_missing));
in_array('adc-marketing/includes/core/class-adc-github-updater.php', $names, true)
    ? ok('updater class shipped in zip')
    : ok('updater class NOT yet shipped (GLM dependency — reported, not failed)');

$forbidden_prefixes = ['adc-marketing/tests/', 'adc-marketing/docs/', 'adc-marketing/bin/', 'adc-marketing/.github/', 'adc-marketing/.git', 'adc-marketing/dist/', 'adc-marketing/vendor/', 'adc-marketing/node_modules/'];
$forbidden_names = ['adc-marketing/AGENTS.md', 'adc-marketing/phpcs.xml.dist', 'adc-marketing/README.md', 'adc-marketing/CHANGELOG.md', 'adc-marketing/GDPR-COMPLIANCE.md', 'adc-marketing/.gitignore'];
$leaks = [];
foreach ($names as $n) {
    foreach ($forbidden_prefixes as $p) {
        if (str_starts_with($n, $p)) {
            $leaks[] = $n;
        }
    }
    if (in_array($n, $forbidden_names, true)) {
        $leaks[] = $n;
    }
}
$leaks === [] ? ok('no dev/internal paths packaged') : not_ok('no dev/internal paths packaged', implode(', ', $leaks));

// CRC check (unzip -t) as independent CRC verification.
[$code, $out] = run('unzip -tqq ' . escapeshellarg("$DIR1/$ZIP"));
$code === 0 ? ok('zip CRC integrity (unzip -t)') : not_ok('zip CRC integrity', $out);

// 3) determinism: identical bytes on second build.
[$code, $out] = run("cd " . escapeshellarg($ROOT) . " && bash bin/build-release.sh --out " . escapeshellarg($DIR2));
$sha1 = sha256("$DIR1/$ZIP");
$sha2 = sha256("$DIR2/$ZIP");
if ($code === 0 && $sha1 !== '' && $sha1 === $sha2) {
    ok("deterministic rebuild (sha256 $sha1)");
} else {
    not_ok('deterministic rebuild', "code=$code sha1=$sha1 sha2=$sha2\n$out");
}

// 4) refusal: same dir again must fail without touching files.
$sha_before = sha256("$DIR1/$ZIP");
[$code, $out] = run("cd " . escapeshellarg($ROOT) . " && bash bin/build-release.sh --out " . escapeshellarg($DIR1));
if ($code !== 0 && str_contains(strtolower($out), 'refusing to overwrite') && $sha_before === sha256("$DIR1/$ZIP")) {
    ok('second build into same dir refused, files untouched');
} else {
    not_ok('second build into same dir refused', "code=$code out=$out");
}

// 5) manifest contract.
$manifest = json_decode((string) file_get_contents("$DIR1/update-manifest.json"), true);
$header = (string) file_get_contents("$ROOT/adc-marketing.php");
preg_match('/^\s*\*\s+Requires at least:\s*(.+)$/m', $header, $m_req);
preg_match('/^\s*\*\s+Requires PHP:\s*(.+)$/m', $header, $m_php);
preg_match('/^\s*\*\s+Tested up to:\s*(.+)$/m', $header, $m_tested);
preg_match('/^\s*\*\s+WC requires at least:\s*(.+)$/m', $header, $m_wc);
$expect = [
    'version' => $VERSION,
    'requires' => trim($m_req[1] ?? ''),
    'requires_php' => trim($m_php[1] ?? ''),
    'tested' => trim($m_tested[1] ?? ''),
    'wc_requires' => trim($m_wc[1] ?? ''),
    'sha256' => $sha1,
];
$bad = [];
foreach ($expect as $k => $v) {
    if (($manifest[$k] ?? null) !== $v) {
        $bad[] = "$k=" . var_export($manifest[$k] ?? null, true) . " != $v";
    }
}
$expect_url = "https://github.com/dany547/adc-cookies-commerce-hub-wordpress/releases/download/v$VERSION/adc-marketing-$VERSION.zip";
if (($manifest['package_url'] ?? '') !== $expect_url) {
    $bad[] = 'package_url not canonical';
}
$bad === [] ? ok('update-manifest.json contract fields') : not_ok('update-manifest.json contract fields', implode('; ', $bad));

$sums = (string) file_get_contents("$DIR1/SHA256SUMS");
str_contains($sums, "$sha1  $ZIP")
    ? ok('SHA256SUMS carries zip digest')
    : not_ok('SHA256SUMS carries zip digest', $sums);

echo "\n$PASS passed, $FAIL failed\n";
echo "Build artifacts kept at: $DIR1 and $DIR2 (not deleted by this test)\n";
exit($FAIL > 0 ? 1 : 0);
