<?php
/**
 * Guard feed PHP UI against unlocalized English sentence literals.
 * Usage: php tests/feed-english-scan-test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$paths = glob($root . '/includes/feeds/*.php') ?: array();
$paths[] = $root . '/includes/admin/class-adc-admin-tab-feeds.php';
$gettext = array('__', '_e', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e', '_x', 'esc_html_x', 'esc_attr_x');
$english = '/\b(?:the|this|that|with|without|must|should|cannot|does|doesn.t|is not|are not|was not|were not|please|failed|missing|required|invalid|unavailable)\b/i';
$failures = array();

foreach ($paths as $path) {
    $source = file_get_contents($path);
    if (!is_string($source)) {
        continue;
    }
    $tokens = token_get_all($source);
    $previous = array();
    foreach ($tokens as $token) {
        if (is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0]) {
            $literal = substr($token[1], 1, -1);
            $plain_words = preg_match_all('/[A-Za-z]{3,}/', $literal);
            $gettext_context = false;
            for ($i = count($previous) - 1; $i >= 0; --$i) {
                $candidate = $previous[$i];
                if (is_array($candidate) && T_STRING === $candidate[0] && in_array($candidate[1], $gettext, true)) {
                    $gettext_context = true;
                    break;
                }
                if (is_array($candidate) && T_WHITESPACE === $candidate[0]) {
                    continue;
                }
                if (is_string($candidate) && '(' === $candidate) {
                    continue;
                }
                if (is_string($candidate) && in_array($candidate, array(',', '.', ';', ')'), true)) {
                    break;
                }
            }
            $technical_literal = strpbrk($literal, '<>/^=') !== false || str_contains($literal, 'max-age=');
            if (!$gettext_context && !$technical_literal && $plain_words >= 3 && preg_match($english, $literal)) {
                $line = $token[2];
                $failures[] = $path . ':' . $line . ' — ' . $literal;
            }
        }
        $previous[] = $token;
        if (count($previous) > 12) {
            array_shift($previous);
        }
    }
}

if ($failures !== array()) {
    fwrite(STDERR, "Unlocalized English-like literals in feed PHP:\n" . implode("\n", $failures) . "\n");
    exit(1);
}

echo "Feed PHP English-literal scan passed.\n";
