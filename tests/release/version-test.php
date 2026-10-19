<?php
/**
 * Standalone contract test for the SemVer single-source tooling (no WP, no PHPUnit).
 *
 * Checks:
 *   1. bin/version.sh current/check pass on the real repository.
 *   2. `check` FAILS when any derived location (constant, Stable tag,
 *      CHANGELOG top entry) drifts from the authoritative Version: header.
 *   3. `sync` repairs constant + Stable tag from the header.
 *   4. `bump minor` moves header + constant + Stable tag + CHANGELOG top
 *      together and the result stays self-consistent.
 *
 * Sandbox runs use throwaway copies under a fresh `mktemp -d` tree; the real
 * repository is never modified and nothing is deleted (leftovers live in /tmp).
 *
 * Run: php tests/release/version-test.php
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

/** Run a command, capture merged output + exit code. */
function run(string $cmd): array
{
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

function make_sandbox(string $root): string
{
    [$code, $out] = run('mktemp -d');
    if ($code !== 0) {
        fwrite(STDERR, "FAIL cannot create sandbox: $out\n");
        exit(1);
    }
    $dir = trim($out);
    foreach (['adc-marketing.php', 'README.txt', 'CHANGELOG.md'] as $f) {
        copy("$root/$f", "$dir/$f");
    }
    mkdir("$dir/bin", 0755, true);
    copy("$root/bin/version.sh", "$dir/bin/version.sh");
    chmod("$dir/bin/version.sh", 0755);
    return $dir;
}

function header_version(string $dir): string
{
    $text = file_get_contents("$dir/adc-marketing.php");
    preg_match('/^\s*\*\s+Version:\s*(\d+\.\d+\.\d+)/m', $text, $m);
    return $m[1] ?? '';
}

function bump(string $ver, string $kind): string
{
    [$maj, $min, $pat] = array_map('intval', explode('.', $ver));
    return match ($kind) {
        'major' => ($maj + 1) . '.0.0',
        'minor' => $maj . '.' . ($min + 1) . '.0',
        default => $maj . '.' . $min . '.' . ($pat + 1),
    };
}

// 1) Real repository must be consistent.
[$code, $out] = run("cd " . escapeshellarg($ROOT) . " && bash bin/version.sh check");
if ($code === 0 && str_contains($out, 'OK version')) {
    ok('repo version.sh check');
} else {
    not_ok('repo version.sh check', $out);
}

[$code, $cur] = run("cd " . escapeshellarg($ROOT) . " && bash bin/version.sh current");
$real = trim($cur);
if ($code === 0 && preg_match('/^\d+\.\d+\.\d+$/', $real)) {
    ok("repo version.sh current ($real)");
} else {
    not_ok('repo version.sh current', $cur);
    exit(1);
}

// 2) Drift in each derived location must be caught.
foreach (['constant', 'stable', 'changelog'] as $kind) {
    $sb = make_sandbox($ROOT);
    $main = file_get_contents("$sb/adc-marketing.php");
    $readme = file_get_contents("$sb/README.txt");
    $chlog = file_get_contents("$sb/CHANGELOG.md");
    $drifted = $real . '.9';
    if ('constant' === $kind) {
        $main = str_replace("ADC_MARKETING_VERSION', '$real'", "ADC_MARKETING_VERSION', '$drifted'", $main);
    } elseif ('stable' === $kind) {
        $readme = str_replace("Stable tag: $real", "Stable tag: $drifted", $readme);
    } else {
        $chlog = preg_replace('/^## \[' . preg_quote($real, '/') . '\]/m', "## [$drifted]", $chlog, 1);
    }
    file_put_contents("$sb/adc-marketing.php", $main);
    file_put_contents("$sb/README.txt", $readme);
    file_put_contents("$sb/CHANGELOG.md", $chlog);
    [$code, $out] = run("cd " . escapeshellarg($sb) . " && bash bin/version.sh check");
    if ($code === 1 && str_contains($out, 'FAIL')) {
        ok("drift detected: $kind");
    } else {
        not_ok("drift detected: $kind", "code=$code out=$out");
    }

    // sync must repair constant + stable (changelog drift is reported, never auto-written).
    [$code, $out] = run("cd " . escapeshellarg($sb) . " && bash bin/version.sh sync && bash bin/version.sh check");
    if ('changelog' === $kind) {
        if ($code === 1 && str_contains($out, 'CHANGELOG')) {
            ok('sync leaves CHANGELOG drift flagged (by design)');
        } else {
            not_ok('sync leaves CHANGELOG drift flagged (by design)', "code=$code out=$out");
        }
    } elseif ($code === 0) {
        ok('sync repairs constant + Stable tag');
    } else {
        not_ok('sync repairs constant + Stable tag', "code=$code out=$out");
    }
}

// 3) bump minor keeps everything consistent.
$sb = make_sandbox($ROOT);
[$code, $out] = run("cd " . escapeshellarg($sb) . " && bash bin/version.sh bump minor && bash bin/version.sh check");
$expected = bump($real, 'minor');
if ($code === 0 && header_version($sb) === $expected && str_contains($out, 'OK version')) {
    ok("bump minor $real -> $expected stays consistent");
} else {
    not_ok("bump minor $real -> $expected stays consistent", "code=$code out=$out header=" . header_version($sb));
}

// 4) bump patch from the bumped tree, then --tag check.
[$code, $out] = run("cd " . escapeshellarg($sb) . " && bash bin/version.sh bump patch && bash bin/version.sh check --tag v" . bump($expected, 'patch'));
$expected2 = bump($expected, 'patch');
if ($code === 0 && header_version($sb) === $expected2) {
    ok("bump patch + check --tag v$expected2");
} else {
    not_ok("bump patch + check --tag v$expected2", "code=$code out=$out");
}

// 5) --tag mismatch must fail.
[$code, $out] = run("cd " . escapeshellarg($sb) . " && bash bin/version.sh check --tag v9.9.9");
if ($code === 1) {
    ok('check --tag rejects a mismatched tag');
} else {
    not_ok('check --tag rejects a mismatched tag', "code=$code out=$out");
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
