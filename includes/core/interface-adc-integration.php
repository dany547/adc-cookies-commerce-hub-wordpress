<?php
/**
 * Platform integration contract (docs/ARCHITECTURE.md §2.1).
 *
 * Each integration implements map + HTTP only: no consent/retry logic, and
 * $event->user attached only when $consent->allows('marketing').
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A tracking platform integration (meta, ga4, sgtm, tiktok).
 *
 * Note: ADC_Consent_State is provided by the consent engine (C2); PHP
 * resolves the type lazily at call time, so this interface is safe to load
 * before that module exists.
 */
interface ADC_Integration_Interface
{
    /**
     * Platform id (also its settings group), e.g. 'meta'|'ga4'|'sgtm'|'tiktok'.
     *
     * @return string
     */
    public static function id(): string;

    /**
     * Human-readable label for the admin UI.
     *
     * @return string
     */
    public function label(): string;

    /**
     * Consent category required to send: 'marketing' | 'statistics'.
     *
     * @return string
     */
    public function required_category(): string;

    /**
     * Server-side sending enabled AND credentials present.
     *
     * @return bool
     */
    public function is_enabled(): bool;

    /**
     * Whether this integration handles the given canonical event name.
     *
     * @param string $event_name Canonical event name.
     * @return bool
     */
    public function supports(string $event_name): bool;

    /**
     * Platform ids whose SERVER sends this one replaces (e.g. sGTM mp_forward => ['ga4']).
     *
     * @return string[]
     */
    public function supersedes(): array;

    /**
     * Map + HTTP. MUST attach $event->user only if $consent->allows('marketing');
     * MUST NOT contain consent/retry logic.
     *
     * @param ADC_Event         $event   Canonical event.
     * @param ADC_Consent_State $consent Current consent state (C2).
     * @return ADC_Send_Result
     */
    public function send(ADC_Event $event, ADC_Consent_State $consent): ADC_Send_Result;

    /**
     * Admin "Test connection"; uses test_event_code where the platform has one.
     *
     * @return ADC_Send_Result
     */
    public function test(): ADC_Send_Result;

    /**
     * Merged into adcConfig.pixels.{id}: ['mode'=>'off|gtm|direct','id'=>..., ...] — no secrets.
     *
     * @return array
     */
    public function browser_config(): array;
}
