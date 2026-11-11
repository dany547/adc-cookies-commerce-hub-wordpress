<?php
/**
 * Custom event rule schema, validation, migration, and privacy helpers.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Pure helpers for custom event rules. */
final class ADC_Custom_Event_Rule
{
    public const SCHEMA_VERSION = 1;
    public const MAX_RULES = 50;
    public const MAX_PARAMS = 10;
    public const MAX_SELECTOR = 200;
    public const MAX_VISIBLE_RULES = 20;
    public const NAME_RE = '/^custom_[a-z][a-z0-9_]{0,32}$/';
    public const TRIGGERS = array('click', 'form_success', 'visible', 'scroll_depth', 'timer');
    public const CLICK_PRESETS = array('', 'tel', 'mailto', 'whatsapp', 'download', 'outbound');
    public const FORM_PRESETS = array('cf7', 'wpforms', 'elementor', 'woo_coupon');
    public const PLATFORMS = array('ga4', 'sgtm', 'meta');
    public const RESERVED_KEYS = array(
        'event', 'event_id', 'ecommerce', 'items', 'value', 'currency', 'adc', 'adc_rule_id',
        'user_data', 'user_id', 'email', 'phone', 'phone_number', 'first_name', 'last_name',
        'address', 'ip', 'ip_address', 'client_id', 'transaction_id', 'session_id',
        'engagement_time_msec', 'page_location', 'page_referrer', 'debug_mode'
    );

    /** @return array<string, list<string>> Token to allowed trigger types. */
    public static function tokens(): array
    {
        return array(
            '{{click_text}}' => array('click'),
            '{{click_url}}' => array('click'),
            '{{link_domain}}' => array('click'),
            '{{file_ext}}' => array('click'),
            '{{element_id}}' => array('click', 'visible'),
            '{{page_path}}' => self::TRIGGERS,
            '{{form_id}}' => array('form_success'),
            '{{scroll_percent}}' => array('scroll_depth'),
            '{{timer_seconds}}' => array('timer'),
        );
    }

    /** Create a random, opaque rule identifier. */
    public static function new_id(): string
    {
        return 'ce_' . bin2hex(random_bytes(4));
    }

    /**
     * Validate and normalize one rule. Invalid values are rejected, not repaired.
     *
     * @param list<array> $others Existing rules used for name and ID uniqueness.
     * @return array{rule: ?array, errors: list<string>}
     */
    public static function validate(array $raw, array $others = array()): array
    {
        $errors = array();
        if (count($others) >= self::MAX_RULES) {
            $errors[] = __('Ai atins limita de 50 de reguli.', 'adc-marketing');
        }
        $now = time();
        $id = isset($raw['id']) && is_string($raw['id']) && 1 === preg_match('/^ce_[a-f0-9]{8}$/', $raw['id'])
            ? $raw['id'] : self::new_id();
        $label = sanitize_text_field((string) ($raw['label'] ?? ''));
        $name = is_string($raw['name'] ?? null) ? $raw['name'] : '';
        $trigger_raw = is_array($raw['trigger'] ?? null) ? $raw['trigger'] : array();
        $type = (string) ($trigger_raw['type'] ?? '');
        $enabled = array_key_exists('enabled', $raw) ? (bool) $raw['enabled'] : true;
        if ($enabled && 'visible' === $type) {
            $visible_count = 0;
            foreach ($others as $other) {
                if (is_array($other) && !empty($other['enabled']) && 'visible' === ($other['trigger']['type'] ?? '')) {
                    $visible_count++;
                }
            }
            if ($visible_count >= self::MAX_VISIBLE_RULES) {
                $errors[] = __('Poți activa cel mult 20 de reguli de tip Vizibil.', 'adc-marketing');
            }
        }
        $trigger = array(
            'type' => $type,
            'preset' => '',
            'selector' => '',
            'match_text' => '',
            'match_href' => '',
            'form_preset' => '',
            'form_id' => '',
            'threshold' => 0.5,
            'min_visible_ms' => 0,
            'depths' => array(),
            'seconds' => 0,
        );

        if ('' === $label || self::length($label) > 80) {
            $errors[] = __('Denumirea internă este obligatorie (maxim 80 de caractere).', 'adc-marketing');
        }
        if (1 !== preg_match(self::NAME_RE, $name)) {
            $errors[] = __('Numele evenimentului poate conține doar litere mici, cifre și _ și trebuie să înceapă cu o literă.', 'adc-marketing');
        }
        foreach ($others as $other) {
            if (!is_array($other)) {
                continue;
            }
            if ($name !== '' && ($other['name'] ?? null) === $name && ($other['id'] ?? null) !== ($raw['id'] ?? null)) {
                $errors[] = __('Numele evenimentului este deja folosit de altă regulă.', 'adc-marketing');
                break;
            }
            if (($other['id'] ?? null) === $id && ($other['id'] ?? null) !== ($raw['id'] ?? null)) {
                $id = self::new_id();
            }
        }
        if (!in_array($type, self::TRIGGERS, true)) {
            $errors[] = __('Tipul declanșatorului nu este valid.', 'adc-marketing');
        }

        $selector = in_array($type, array('click', 'visible'), true) && isset($trigger_raw['selector']) && is_string($trigger_raw['selector']) ? trim($trigger_raw['selector']) : '';
        $preset = (string) ($trigger_raw['preset'] ?? '');
        $form_preset = (string) ($trigger_raw['form_preset'] ?? '');
        if ('click' === $type && !in_array($preset, self::CLICK_PRESETS, true)) {
            $errors[] = __('Presetarea pentru click nu este validă.', 'adc-marketing');
        }
        if ('form_success' === $type && !in_array($form_preset, self::FORM_PRESETS, true)) {
            $errors[] = __('Alege tipul formularului.', 'adc-marketing');
        }
        if (('visible' === $type || ('click' === $type && '' === $preset)) && '' === $selector) {
            $errors[] = __('Selectorul CSS este obligatoriu pentru acest declanșator.', 'adc-marketing');
        } elseif ('' !== $selector && !self::is_valid_selector($selector)) {
            $errors[] = __('Selectorul CSS conține caractere nepermise sau depășește 200 de caractere.', 'adc-marketing');
        }
        $trigger['preset'] = 'click' === $type ? $preset : '';
        $trigger['selector'] = in_array($type, array('click', 'visible'), true) ? $selector : '';
        $trigger['match_text'] = 'click' === $type ? sanitize_text_field((string) ($trigger_raw['match_text'] ?? '')) : '';
        $trigger['match_href'] = 'click' === $type ? sanitize_text_field((string) ($trigger_raw['match_href'] ?? '')) : '';
        if (self::length($trigger['match_text']) > 100 || self::length($trigger['match_href']) > 200) {
            $errors[] = __('Filtrul declanșatorului depășește lungimea permisă.', 'adc-marketing');
        }
        $trigger['form_preset'] = 'form_success' === $type ? $form_preset : '';
        $form_id = 'form_success' === $type ? (string) ($trigger_raw['form_id'] ?? '') : '';
        if ('form_success' === $type && (self::length($form_id) > 40 || 1 !== preg_match('/^[A-Za-z0-9_\-]*$/', $form_id))) {
            $errors[] = __('ID-ul formularului nu este valid.', 'adc-marketing');
        }
        $trigger['form_id'] = $form_id;
        $threshold = is_numeric($trigger_raw['threshold'] ?? null) ? (float) $trigger_raw['threshold'] : 0.5;
        if ('visible' === $type && ($threshold < 0.1 || $threshold > 1.0)) {
            $errors[] = __('Pragul de vizibilitate trebuie să fie între 10% și 100%.', 'adc-marketing');
        }
        $trigger['threshold'] = 'visible' === $type ? round($threshold / 0.05) * 0.05 : 0.5;
        $min_visible = (int) ($trigger_raw['min_visible_ms'] ?? 0);
        if ('visible' === $type && ($min_visible < 0 || $min_visible > 10000)) {
            $errors[] = __('Timpul minim de vizibilitate trebuie să fie între 0 și 10.000 ms.', 'adc-marketing');
        }
        $trigger['min_visible_ms'] = 'visible' === $type ? $min_visible : 0;
        $depths = 'scroll_depth' === $type && is_array($trigger_raw['depths'] ?? null) ? $trigger_raw['depths'] : array();
        $clean_depths = array();
        foreach ($depths as $depth) {
            $depth = (int) $depth;
            if (!in_array($depth, array(25, 50, 75, 90), true)) {
                $errors[] = __('Pragul de scroll nu este valid.', 'adc-marketing');
                break;
            }
            $clean_depths[] = $depth;
        }
        $clean_depths = array_values(array_unique($clean_depths));
        sort($clean_depths);
        if ('scroll_depth' === $type && array() === $clean_depths) {
            $errors[] = __('Alege cel puțin un prag de scroll.', 'adc-marketing');
        }
        $trigger['depths'] = 'scroll_depth' === $type ? $clean_depths : array();
        $seconds = (int) ($trigger_raw['seconds'] ?? 0);
        if ('timer' === $type && ($seconds < 5 || $seconds > 1800)) {
            $errors[] = __('Durata trebuie să fie între 5 și 1800 de secunde.', 'adc-marketing');
        }
        $trigger['seconds'] = 'timer' === $type ? $seconds : 0;

        $fire = (string) ($raw['fire'] ?? 'once');
        if (!in_array($fire, array('once', 'every'), true)) {
            $errors[] = __('Frecvența declanșatorului nu este validă.', 'adc-marketing');
        }
        if (in_array($type, array('visible', 'scroll_depth', 'timer'), true)) {
            $fire = 'once';
        }

        $filter_raw = is_array($raw['page_filter'] ?? null) ? $raw['page_filter'] : array();
        $mode = (string) ($filter_raw['mode'] ?? 'all');
        if (!in_array($mode, array('all', 'include', 'exclude'), true)) {
            $errors[] = __('Modul de filtrare a paginilor nu este valid.', 'adc-marketing');
            $mode = 'all';
        }
        $paths = is_array($filter_raw['paths'] ?? null) ? $filter_raw['paths'] : array();
        if (count($paths) > 10) {
            $errors[] = __('Poți adăuga cel mult 10 căi de pagină.', 'adc-marketing');
        }
        $clean_paths = array();
        foreach (array_slice($paths, 0, 10) as $path) {
            $path = (string) $path;
            if (self::length($path) > 200 || 1 !== preg_match('/^\/[A-Za-z0-9\/\-_.~%*]*$/', $path)) {
                $errors[] = sprintf(__('Calea „%s” trebuie să înceapă cu / și nu poate conține parametri (?).', 'adc-marketing'), sanitize_text_field($path));
                continue;
            }
            $clean_paths[] = $path;
        }

        $raw_params = is_array($raw['params'] ?? null) ? $raw['params'] : array();
        if (count($raw_params) > self::MAX_PARAMS) {
            $errors[] = __('Poți adăuga cel mult 10 parametri.', 'adc-marketing');
        }
        $params = array();
        $keys = array();
        $tokens = self::tokens();
        foreach (array_slice($raw_params, 0, self::MAX_PARAMS) as $param) {
            if (!is_array($param)) {
                continue;
            }
            $key = (string) ($param['key'] ?? '');
            $value = sanitize_text_field((string) ($param['value'] ?? ''));
            if (1 !== preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) || self::is_reserved_key($key)) {
                $errors[] = sprintf(__('Cheia „%s” este rezervată sau nevalidă.', 'adc-marketing'), sanitize_text_field($key));
                continue;
            }
            if (isset($keys[$key])) {
                $errors[] = sprintf(__('Cheia „%s” apare de două ori.', 'adc-marketing'), $key);
                continue;
            }
            if (self::length($value) > 100) {
                $errors[] = sprintf(__('Valoarea parametrului „%s” depășește 100 de caractere.', 'adc-marketing'), $key);
                continue;
            }
            preg_match_all('/\{\{[^{}]*\}\}/', $value, $found);
            foreach ($found[0] as $token) {
                if (!isset($tokens[$token]) || !in_array($type, $tokens[$token] ?? array(), true)) {
                    $errors[] = sprintf(__('Token-ul %s nu este disponibil pentru declanșatorul ales.', 'adc-marketing'), $token);
                }
            }
            $keys[$key] = true;
            $params[] = array('key' => $key, 'value' => $value);
        }

        $value = null;
        if (null !== ($raw['value'] ?? null) && '' !== $raw['value']) {
            if (!is_numeric($raw['value']) || (float) $raw['value'] < 0 || (float) $raw['value'] > 1000000) {
                $errors[] = __('Valoarea trebuie să fie între 0 și 1.000.000.', 'adc-marketing');
            } else {
                $value = round((float) $raw['value'], 2);
            }
        }
        $currency = strtoupper((string) ($raw['currency'] ?? ''));
        if ('' !== $currency && 1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors[] = __('Moneda nu este validă.', 'adc-marketing');
        }
        if (null !== $value && '' === $currency) {
            $errors[] = __('Moneda este obligatorie când setezi o valoare.', 'adc-marketing');
        }
        $category = (string) ($raw['consent_category'] ?? 'statistics');
        if (!in_array($category, array('statistics', 'marketing'), true)) {
            $errors[] = __('Categoria de consimțământ nu este validă.', 'adc-marketing');
            $category = 'statistics';
        }
        $dest_raw = is_array($raw['destinations'] ?? null) ? $raw['destinations'] : array();
        $raw_platforms = is_array($dest_raw['platforms'] ?? null) ? $dest_raw['platforms'] : array();
        $platforms = array_values(array_unique(array_filter($raw_platforms, static fn($platform): bool => is_string($platform) && in_array($platform, self::PLATFORMS, true))));
        if (count($platforms) !== count($raw_platforms)) {
            $errors[] = __('Una sau mai multe destinații nu sunt valide.', 'adc-marketing');
        }
        if (in_array('meta', $platforms, true) && 'marketing' !== $category) {
            $errors[] = __('Meta cere categoria de consimțământ Marketing.', 'adc-marketing');
        }
        $server_mirror = !empty($dest_raw['server_mirror']) || !empty($platforms);
        $rule = array(
            'id' => $id,
            'label' => $label,
            'name' => $name,
            'enabled' => $enabled,
            'trigger' => $trigger,
            'fire' => $fire,
            'page_filter' => array('mode' => $mode, 'paths' => array_values($clean_paths)),
            'params' => $params,
            'value' => $value,
            'currency' => $currency,
            'consent_category' => $category,
            'destinations' => array('datalayer' => true, 'server_mirror' => $server_mirror, 'platforms' => $platforms),
            'created' => isset($raw['created']) && is_numeric($raw['created']) && (int) $raw['created'] > 0 ? (int) $raw['created'] : $now,
            'updated' => isset($raw['updated']) && is_numeric($raw['updated']) && (int) $raw['updated'] > 0 ? (int) $raw['updated'] : $now,
        );

        return array('rule' => array() === $errors ? $rule : null, 'errors' => array_values(array_unique($errors)));
    }

    /** Validate the restricted CSS selector grammar accepted in storage. */
    public static function is_valid_selector(string $selector): bool
    {
        $length = self::length($selector);
        if ($length < 1 || $length > self::MAX_SELECTOR || 1 === preg_match('/[<{};`@\x00-\x1F\x7F]/u', $selector)) {
            return false;
        }
        if (1 === preg_match('/::|:has\s*\(/i', $selector)) {
            return false;
        }
        $stack = array();
        $quote = '';
        $escaped = false;
        $pairs = array(']' => '[', ')' => '(');
        for ($i = 0; $i < strlen($selector); $i++) {
            $char = $selector[$i];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ('\\' === $char) {
                $escaped = true;
                continue;
            }
            if ('' !== $quote) {
                if ($char === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ('"' === $char || "'" === $char) {
                $quote = $char;
                continue;
            }
            if ('[' === $char || '(' === $char) {
                $stack[] = $char;
            } elseif (']' === $char || ')' === $char) {
                if (array_pop($stack) !== $pairs[$char]) {
                    return false;
                }
            }
        }
        return '' === $quote && array() === $stack && !$escaped;
    }

    /** Remove common direct identifiers and long opaque tokens from text. */
    public static function scrub(string $value): string
    {
        $value = preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/u', '[email]', $value) ?? $value;
        $value = preg_replace('/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/i', '[iban]', $value) ?? $value;
        $value = preg_replace_callback('/\d(?:[\d\s\-.()]*\d)?/u', static function (array $match): string {
            $digits = preg_replace('/\D/', '', $match[0]) ?? '';
            return strlen($digits) >= 7 ? '[nr]' : $match[0];
        }, $value) ?? $value;
        return preg_replace('/\b[A-Za-z0-9_\-]{32,}\b/', '[id]', $value) ?? $value;
    }

    /** Normalize stored schema data; future versions are returned locked. */
    public static function migrate(array $stored): array
    {
        $version = (int) ($stored['schema_version'] ?? self::SCHEMA_VERSION);
        if ($version > self::SCHEMA_VERSION) {
            return array(
                'schema_version' => $version,
                'revision' => max(0, (int) ($stored['revision'] ?? 0)),
                'rules' => array(),
                'locked' => true,
            );
        }
        $revision = max(0, (int) ($stored['revision'] ?? 0));
        $rules = array();
        $seen_ids = array();
        $seen_names = array();
        foreach (array_slice(is_array($stored['rules'] ?? null) ? $stored['rules'] : array(), 0, self::MAX_RULES) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $raw_id = $raw['id'] ?? null;
            if (is_string($raw_id) && isset($seen_ids[$raw_id])) {
                continue;
            }
            $result = self::validate($raw, $rules);
            $rule = $result['rule'];
            if (null === $rule || isset($seen_ids[$rule['id']]) || isset($seen_names[$rule['name']])) {
                continue;
            }
            $seen_ids[$rule['id']] = true;
            $seen_names[$rule['name']] = true;
            $rules[] = $rule;
        }
        return array('schema_version' => self::SCHEMA_VERSION, 'revision' => $revision, 'rules' => $rules);
    }

    /** Build the privacy-minimized public configuration for one rule. */
    public static function to_frontend(array $rule): array
    {
        $type = (string) ($rule['trigger']['type'] ?? '');
        $fields = match ($type) {
            'click' => array('type', 'preset', 'selector', 'match_text', 'match_href'),
            'form_success' => array('type', 'form_preset', 'form_id'),
            'visible' => array('type', 'selector', 'threshold', 'min_visible_ms'),
            'scroll_depth' => array('type', 'depths'),
            'timer' => array('type', 'seconds'),
            default => array('type'),
        };
        $trigger = array();
        foreach ($fields as $field) {
            if (array_key_exists($field, $rule['trigger'] ?? array())) {
                $trigger[$field] = $rule['trigger'][$field];
            }
        }
        $params = array();
        foreach ($rule['params'] ?? array() as $param) {
            if (is_array($param) && isset($param['key']) && array_key_exists('value', $param)) {
                $params[] = array((string) $param['key'], (string) $param['value']);
            }
        }
        return array(
            'id' => (string) ($rule['id'] ?? ''),
            'name' => (string) ($rule['name'] ?? ''),
            'trigger' => $trigger,
            'fire' => (string) ($rule['fire'] ?? 'once'),
            'page_filter' => array(
                'mode' => (string) ($rule['page_filter']['mode'] ?? 'all'),
                'paths' => array_values($rule['page_filter']['paths'] ?? array()),
            ),
            'params' => $params,
            'value' => $rule['value'] ?? null,
            'currency' => (string) ($rule['currency'] ?? ''),
            'category' => (string) ($rule['consent_category'] ?? 'statistics'),
            'mirror' => !empty($rule['destinations']['server_mirror']),
        );
    }

    /** Count characters when mbstring is available, with a safe UTF-8 fallback. */
    private static function length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        $characters = preg_match_all('/./us', $value);
        return false === $characters ? strlen($value) : $characters;
    }

    /** Check the fixed and reserved namespace list for parameter keys. */
    private static function is_reserved_key(string $key): bool
    {
        if (in_array($key, self::RESERVED_KEYS, true)) {
            return true;
        }
        foreach (array('gtm', 'ga_', 'google_', 'firebase_') as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
