<?php
/**
 * Integration send outcome (docs/ARCHITECTURE.md §2.1).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable result of a platform send attempt.
 */
final class ADC_Send_Result
{
    /**
     * Maximum message length stored alongside a result.
     */
    private const MAX_MESSAGE = 255;

    /**
     * Scrubbed, truncated message (<= 255 chars).
     *
     * @var string
     */
    public readonly string $message;

    /**
     * @param string $status    sent|retry|dead|skipped.
     * @param int    $http_code Platform HTTP response code (0 when none).
     * @param string $message   Human-readable detail; scrubbed of PII, max 255 chars.
     */
    public function __construct(
        public readonly string $status,
        public readonly int $http_code = 0,
        string $message = ''
    ) {
        $this->message = substr(ADC_Logger::scrub($message), 0, self::MAX_MESSAGE);
    }

    /**
     * Successful send.
     *
     * @param int $code Platform HTTP code.
     * @return self
     */
    public static function sent(int $code): self
    {
        return new self('sent', $code, '');
    }

    /**
     * Transient failure: retry later (429/5xx).
     *
     * @param int    $code Platform HTTP code.
     * @param string $msg  Scrubbed failure detail.
     * @return self
     */
    public static function retry(int $code, string $msg): self
    {
        return new self('retry', $code, $msg);
    }

    /**
     * Permanent failure: do not retry (other 4xx).
     *
     * @param int    $code Platform HTTP code.
     * @param string $msg  Scrubbed failure detail.
     * @return self
     */
    public static function dead(int $code, string $msg): self
    {
        return new self('dead', $code, $msg);
    }

    /**
     * Nothing was sent (e.g. no consent).
     *
     * @param string $reason Scrubbed reason.
     * @return self
     */
    public static function skipped(string $reason): self
    {
        return new self('skipped', 0, $reason);
    }
}
