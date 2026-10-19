<?php
/**
 * Admin tab contract (docs/ARCHITECTURE.md §2.1).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One tab of the ADC Marketing admin shell.
 */
interface ADC_Admin_Tab_Interface
{
    /**
     * Tab slug (URL parameter): 'banner','events','meta','ga4','sgtm','tiktok','matching','marketing','logs'.
     *
     * @return string
     */
    public function slug(): string;

    /**
     * Tab label (Romanian, shown in the nav).
     *
     * @return string
     */
    public function label(): string;

    /**
     * '' (top-level) or 'integrations' (rendered as sub-nav).
     *
     * @return string
     */
    public function group(): string;

    /**
     * Sort order (banner 10, events 20, meta 30, ga4 40, sgtm 50, tiktok 60, matching 70, marketing 80, logs 90).
     *
     * @return int
     */
    public function order(): int;

    /**
     * Render inner HTML only. NO <form> tag, NO nonce field — the shell wraps them.
     */
    public function render(): void;

    /**
     * Handle the save POST.
     *
     * @param array $post wp_unslash($_POST).
     * @return array<int, array{type: string, message: string}> Notices (type: success|error|warning).
     */
    public function save(array $post): array;
}
