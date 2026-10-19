<?php
/**
 * Standalone referer classifier test (no WordPress needed).
 *
 * Run: php tests/analytics/referer-test.php
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

if (!function_exists('wp_parse_url')) {
    /** Stub: WordPress wrapper over parse_url(). */
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('wp_unslash')) {
    /** Stub: WordPress wrapper; the classifier does not need unslashing. */
    function wp_unslash(mixed $value): mixed
    {
        return $value;
    }
}

require_once __DIR__ . '/../../includes/core/interface-adc-module.php';
require_once __DIR__ . '/../../includes/analytics/class-adc-analytics-traffic.php';

$allowed = array('direct', 'unknown', 'internal', 'search', 'social', 'referral');
$cases   = require __DIR__ . '/../fixtures/analytics-referers.php';

$failures = 0;

foreach ($cases as $index => $case) {
    $actual = ADC_Analytics_Traffic::channel_from_referer($case['referer'], $case['site_host']);

    if ($actual !== $case['expected'] || !in_array($actual, $allowed, true)) {
        ++$failures;
        printf("FAIL #%d referer=%s expected=%s actual=%s\n", $index, json_encode($case['referer']), $case['expected'], $actual);
    }
}

// The output must be a bare channel label: no URL, path, query or host is ever returned.
$leak = ADC_Analytics_Traffic::channel_from_referer('https://www.google.com/search?q=secret', 'example.ro');
if (str_contains($leak, 'google') || str_contains($leak, 'secret') || str_contains($leak, '?')) {
    ++$failures;
    printf("FAIL leak check returned %s\n", $leak);
}

printf("%d referer cases, %d failure(s)\n", count($cases) + 1, $failures);
exit($failures === 0 ? 0 : 1);
