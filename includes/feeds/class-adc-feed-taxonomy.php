<?php
declare(strict_types=1);

/** Pure parser and in-memory search helpers for Google product taxonomy text files. */
final class ADC_Feed_Taxonomy
{
    private const MAX_BYTES = 2097152;
    private const MAX_ROWS = 10000;
    private const MAX_PATH_LENGTH = 750;
    private const MAX_SEARCH_RESULTS = 50;

    /** Parse a complete taxonomy file; any invalid row rejects the complete input. */
    public static function parse(string $input): array
    {
        if (strlen($input) > self::MAX_BYTES) {
            return self::failure('input_too_large');
        }
        if (1 !== preg_match('//u', $input)) {
            return self::failure('invalid_utf8');
        }

        $lines = preg_split('/\r?\n/', $input);
        if (!is_array($lines) || empty($lines)) {
            return self::failure('invalid_header');
        }
        if (end($lines) === '') {
            array_pop($lines);
        }
        $header = array_shift($lines);
        if (is_string($header) && str_starts_with($header, "\xEF\xBB\xBF")) {
            $header = substr($header, 3);
        }
        if (!is_string($header) || 1 !== preg_match('/^# Google_Product_Taxonomy_Version: (\d{4})-(\d{2})-(\d{2})$/D', $header, $version_parts)
            || !checkdate((int) $version_parts[2], (int) $version_parts[3], (int) $version_parts[1])) {
            return self::failure('invalid_header');
        }

        $version = $version_parts[1] . '-' . $version_parts[2] . '-' . $version_parts[3];
        $items = array();
        foreach ($lines as $line) {
            if ('' === $line) {
                return self::failure('invalid_row');
            }
            if (count($items) >= self::MAX_ROWS) {
                return self::failure('too_many_rows');
            }
            // IDs use canonical decimal notation; leading zeroes are rejected to avoid aliases.
            if (1 !== preg_match('/^(0|[1-9]\d*) - (.+)$/uD', $line, $matches)) {
                return self::failure('invalid_row');
            }
            $id = $matches[1];
            $path = $matches[2];
            if (isset($items[$id])) {
                return self::failure('duplicate_id');
            }
            if ('' === trim($path) || preg_match('/\p{Cc}/u', $path)) {
                return self::failure('invalid_path');
            }
            preg_match_all('/./us', $path, $characters);
            if (count($characters[0]) > self::MAX_PATH_LENGTH) {
                return self::failure('path_too_long');
            }
            $items[$id] = array('id' => $id, 'path' => $path);
        }

        return array('ok' => true, 'version' => $version, 'items' => array_values($items));
    }

    /** Search parsed paths case-insensitively and return no more than 50 rows. */
    public static function search(array $parsed, string $query, int $limit = self::MAX_SEARCH_RESULTS): array
    {
        if (empty($parsed['ok']) || !is_array($parsed['items'] ?? null) || '' === trim($query)) {
            return array();
        }
        $needle = self::fold(trim($query));
        $limit = max(0, min(self::MAX_SEARCH_RESULTS, $limit));
        if (0 === $limit) {
            return array();
        }
        $matches = array();
        foreach ($parsed['items'] as $item) {
            if (is_array($item) && is_string($item['path'] ?? null)
                && str_contains(self::fold($item['path']), $needle)) {
                $matches[] = $item;
                if (count($matches) >= $limit) {
                    break;
                }
            }
        }
        return $matches;
    }

    private static function fold(string $value): string
    {
        $value = strtr($value, array(
            'Ă' => 'ă', 'Â' => 'â', 'Î' => 'î', 'Ș' => 'ș', 'Ş' => 'ș', 'Ț' => 'ț', 'Ţ' => 'ț',
        ));
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }

    private static function failure(string $code): array
    {
        return array('ok' => false, 'error' => $code, 'version' => null, 'items' => array());
    }
}
