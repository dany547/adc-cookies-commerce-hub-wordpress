<?php
/**
 * Checks that every extracted source string has a complete English translation.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$potPath = $root . '/languages/adc-marketing.pot';
$poPath = $root . '/languages/adc-marketing-en_US.po';

function adc_i18n_parse(string $path): array
{
    $entries = [];
    $current = [];
    $field = null;
    $decode = static function (string $value): string {
        $decoded = json_decode($value, true);
        return is_string($decoded) ? $decoded : '';
    };

    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (trim($line) === '') {
            if (isset($current['msgid'])) {
                $entries[] = $current;
            }
            $current = [];
            $field = null;
            continue;
        }
        if (str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^(msgid_plural|msgid|msgstr(?:\[\d+\])?)\s+(".*")$/', $line, $match)) {
            $field = $match[1];
            $current[$field] = $decode($match[2]);
        } elseif ($field !== null && preg_match('/^\s*(".*")$/', $line, $match)) {
            $current[$field] = ($current[$field] ?? '') . $decode($match[1]);
        }
    }
    if (isset($current['msgid'])) {
        $entries[] = $current;
    }
    return $entries;
}

function adc_i18n_placeholders(string $value): array
{
    preg_match_all('/%(?:\d+\$)?[bcdeEfFgGosuxX]/', $value, $matches);
    $result = $matches[0];
    sort($result);
    return $result;
}

if (!is_file($potPath) || !is_file($poPath)) {
    fwrite(STDERR, "Missing POT or en_US PO catalog.\n");
    exit(1);
}

$potEntries = adc_i18n_parse($potPath);
$poEntries = adc_i18n_parse($poPath);
$translations = [];
foreach ($poEntries as $entry) {
    $translations[($entry['msgid'] ?? '') . "\0" . ($entry['msgid_plural'] ?? '')] = $entry;
}

$errors = [];
foreach ($potEntries as $entry) {
    $id = $entry['msgid'] ?? '';
    if ($id === '') {
        continue;
    }
    if (preg_match('/[şţŞŢ]/u', $id)) {
        $errors[] = "Source msgid uses cedilla diacritics: {$id}";
    }
    $plural = $entry['msgid_plural'] ?? '';
    $translation = $translations[$id . "\0" . $plural] ?? null;
    if ($translation === null) {
        $errors[] = "Missing translation: {$id}";
        continue;
    }
    $forms = $plural === ''
        ? [['source' => $id, 'translation' => ($translation['msgstr'] ?? '')]]
        : array_map(
            static fn(string $key, string $source): array => [
                'source' => $source,
                'translation' => $translation[$key] ?? '',
            ],
            ['msgstr[0]', 'msgstr[1]'],
            [$id, $plural]
        );
    foreach ($forms as $form) {
        if ($form['translation'] === '') {
            $errors[] = "Empty translation: {$id}";
        }
        if (adc_i18n_placeholders($form['source']) !== adc_i18n_placeholders($form['translation'])) {
            $errors[] = "Placeholder mismatch: {$id}";
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
echo 'i18n completeness OK (' . (count($potEntries) - 1) . " source entries).\n";
