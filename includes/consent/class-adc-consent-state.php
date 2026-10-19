<?php
/**
 * Immutable consent state + `adc_consent` cookie codec (docs/ARCHITECTURE.md §2.2).
 *
 * Cookie value wire format (versioned):
 *   v=1&p=<0|1>&s=<0|1>&m=<0|1>&pv=<policy int>&ts=<unix>&cid=<uuid v4>&src=<source>
 * The stored cookie value is rawurlencode(http_build_query(...)).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable snapshot of the visitor consent decision.
 */
final readonly class ADC_Consent_State
{
    /**
     * Fixed category list (order kept stable for serialisation).
     */
    public const CATEGORIES = array('necessary', 'preferences', 'statistics', 'marketing');

    /**
     * Cookie format version understood by this parser.
     */
    public const FORMAT_VERSION = 1;

    /**
     * Allowed cookie `src` values (mirrors the DB column, varchar(24)).
     */
    public const SOURCES = array('banner', 'modal', 'api', 'gpc', 'account', 'checkout');

    /**
     * @param bool   $preferences Preference cookies granted.
     * @param bool   $statistics  Statistics cookies granted.
     * @param bool   $marketing   Marketing cookies granted.
     * @param bool   $decided     Whether the visitor made a choice at all.
     * @param int    $ts          Unix timestamp of the decision.
     * @param int    $policy      Policy version shown at decision time.
     * @param string $cid         Consent id (uuid v4), created only on decision.
     * @param string $source      Where the decision came from (see SOURCES).
     */
    public function __construct(
        public bool $preferences = false,
        public bool $statistics = false,
        public bool $marketing = false,
        public bool $decided = false,
        public int $ts = 0,
        public int $policy = 0,
        public string $cid = '',
        public string $source = '',
    ) {
    }

    /**
     * Gate a category. 'necessary' is always true; unknown categories and an
     * undecided state deny everything.
     *
     * @param string $category necessary|preferences|statistics|marketing.
     * @return bool
     */
    public function allows(string $category): bool
    {
        if ('necessary' === $category) {
            return true;
        }

        if (!$this->decided) {
            return false;
        }

        return match ($category) {
            'preferences' => $this->preferences,
            'statistics'  => $this->statistics,
            'marketing'   => $this->marketing,
            default       => false,
        };
    }

    /**
     * Plain-array representation (cache/JSON-safe).
     *
     * @return array<string, mixed>
     */
    public function to_array(): array
    {
        return array(
            'preferences' => $this->preferences,
            'statistics'  => $this->statistics,
            'marketing'   => $this->marketing,
            'decided'     => $this->decided,
            'ts'          => $this->ts,
            'policy'      => $this->policy,
            'cid'         => $this->cid,
            'source'      => $this->source,
        );
    }

    /**
     * Rebuild from a plain array (order snapshot, user meta, REST payload).
     * Invalid fields fall back to safe defaults (undecided / denied).
     *
     * @param array $data Raw array.
     * @return self
     */
    public static function from_array(array $data): self
    {
        $state = new self(
            (bool) ($data['preferences'] ?? false),
            (bool) ($data['statistics'] ?? false),
            (bool) ($data['marketing'] ?? false),
            (bool) ($data['decided'] ?? false),
            (int) ($data['ts'] ?? 0),
            (int) ($data['policy'] ?? 0),
            (string) ($data['cid'] ?? ''),
            (string) ($data['source'] ?? ''),
        );

        if (!self::is_uuid($state->cid)) {
            $state = new self(
                $state->preferences,
                $state->statistics,
                $state->marketing,
                $state->decided,
                $state->ts,
                $state->policy,
                '',
                $state->source,
            );
        }

        return $state;
    }

    /**
     * Encode to the `adc_consent` cookie value (urlencoded query string).
     *
     * @return string
     */
    public function to_cookie_value(): string
    {
        return rawurlencode(
            http_build_query(
                array(
                    'v'   => self::FORMAT_VERSION,
                    'p'   => $this->preferences ? 1 : 0,
                    's'   => $this->statistics ? 1 : 0,
                    'm'   => $this->marketing ? 1 : 0,
                    'pv'  => $this->policy,
                    'ts'  => $this->ts,
                    'cid' => $this->cid,
                    'src' => $this->source,
                ),
                '',
                '&',
                PHP_QUERY_RFC3986
            )
        );
    }

    /**
     * Parse a raw `adc_consent` cookie value. Tolerates both urlencoded and
     * plain query-string input (rawurlencode is idempotent-safe here because
     * '=' / '&' only appear as separators after one decode pass).
     *
     * @param string $raw Cookie value as found in $_COOKIE.
     * @return self|null Null when malformed, unknown format version or bad cid.
     */
    public static function from_cookie_value(string $raw): ?self
    {
        if ('' === $raw || strlen($raw) > 512) {
            return null;
        }

        $decoded = rawurldecode($raw);
        parse_str($decoded, $vars);

        if (!is_array($vars) || !isset($vars['v'])) {
            return null;
        }

        if (self::FORMAT_VERSION !== (int) $vars['v']) {
            return null; // Unknown format version => treated as undecided upstream.
        }

        foreach (array('p', 's', 'm') as $flag) {
            if (!isset($vars[$flag]) || !in_array((string) $vars[$flag], array('0', '1'), true)) {
                return null;
            }
        }

        if (!isset($vars['ts']) || !ctype_digit((string) $vars['ts'])) {
            return null;
        }

        if (!isset($vars['pv']) || !ctype_digit((string) $vars['pv'])) {
            return null;
        }

        $cid = isset($vars['cid']) ? (string) $vars['cid'] : '';
        if (!self::is_uuid($cid)) {
            return null;
        }

        $source = isset($vars['src']) ? (string) $vars['src'] : '';
        if (!in_array($source, self::SOURCES, true)) {
            return null;
        }

        return new self(
            '1' === (string) $vars['p'],
            '1' === (string) $vars['s'],
            '1' === (string) $vars['m'],
            true,
            (int) $vars['ts'],
            (int) $vars['pv'],
            $cid,
            $source,
        );
    }

    /**
     * Undecided state, everything denied, no cid.
     *
     * @return self
     */
    public static function denied(): self
    {
        return new self();
    }

    /**
     * Strict uuid (v4-shaped) check; also guards the DB char(36) column.
     *
     * @param string $value Candidate uuid.
     * @return bool
     */
    public static function is_uuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }
}
