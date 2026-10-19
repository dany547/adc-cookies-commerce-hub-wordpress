<?php
/**
 * Scrubbed logging via wc_get_logger() (docs/ARCHITECTURE.md §2.1, §6).
 *
 * Honours the `logs` settings group `level` (off|error|info|debug, default
 * 'error'); scrubs e-mails, phone-like digit runs, IPv4/IPv6 addresses and
 * long token-like strings from message and context. Source: 'adc-marketing'.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central logging entry point. Never logs raw PII.
 */
final class ADC_Logger
{
    /**
     * Message severity ranks (higher = more verbose).
     *
     * @var array<string, int>
     */
    private const RANKS = array(
        'off'     => 0,
        'error'   => 10,
        'warning' => 20,
        'info'    => 30,
        'debug'   => 40,
    );

    /**
     * Log a message when the configured level allows it.
     *
     * @param string $level   error|warning|info|debug.
     * @param string $message Human-readable message (scrubbed before logging).
     * @param array  $context Structured context (string values scrubbed recursively).
     */
    public static function log(string $level, string $message, array $context = array()): void
    {
        if (!function_exists('wc_get_logger')) {
            return; // WooCommerce provides the logger; without it there is nothing safe to fall back to.
        }

        $level     = in_array($level, array('error', 'warning', 'info', 'debug'), true) ? $level : 'info';
        $threshold = self::threshold();

        if (self::RANKS[$level] > $threshold) {
            return;
        }

        wc_get_logger()->log(
            $level,
            self::scrub($message),
            array(
                'source'  => 'adc-marketing',
                'context' => self::scrub_context($context),
            )
        );
    }

    /**
     * Scrub PII-looking substrings from arbitrary text (also used for
     * platform error strings, e.g. ADC_Send_Result::$message).
     *
     * @param string $text Raw text.
     * @return string Scrubbed text.
     */
    public static function scrub(string $text): string
    {
        if ('' === $text) {
            return '';
        }

        $replacements = array(
            // E-mails.
            '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/' => '[email]',
            // IPv4 addresses.
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/'                          => '[ip]',
            // IPv6-ish / time-like hex:colon runs (conservative, safety over fidelity).
            '/\b(?:[0-9A-Fa-f]{1,4}:){2,7}[0-9A-Fa-f]{1,4}\b/'       => '[ip]',
            // Phone-like digit runs (8-15 digits, optional +/separators).
            '/(?<![\d])\+?\d(?:[\s.\-()]?\d){7,14}(?![\d])/'          => '[phone]',
            // Long token-like strings (API keys, hashes).
            '/\b[A-Za-z0-9+\/_\-]{32,}\b/'                           => '[token]',
        );

        return (string) preg_replace(array_keys($replacements), array_values($replacements), $text);
    }

    /**
     * Recursively scrub string values of a context array (keys untouched).
     *
     * @param array  $context Context array.
     * @param int    $depth   Recursion guard.
     * @return array
     */
    private static function scrub_context(array $context, int $depth = 0): array
    {
        if ($depth >= 4) {
            return array();
        }

        $clean = array();
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $clean[$key] = self::scrub($value);
            } elseif (is_array($value)) {
                $clean[$key] = self::scrub_context($value, $depth + 1);
            } else {
                $clean[$key] = $value; // int/float/bool/null carry no PII patterns worth breaking.
            }
        }

        return $clean;
    }

    /**
     * Configured verbosity threshold (option read directly to avoid the
     * settings-registration cycle in early code paths).
     *
     * @return int
     */
    private static function threshold(): int
    {
        $options = get_option('adc_settings_logs', array());
        $level   = (is_array($options) && isset($options['level'])) ? (string) $options['level'] : 'error';

        return self::RANKS[$level] ?? self::RANKS['error'];
    }
}
