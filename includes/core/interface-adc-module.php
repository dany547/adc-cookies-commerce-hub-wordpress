<?php
/**
 * Module contract: every new (2.0) module boots itself, idempotently.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A self-booting plugin module.
 */
interface ADC_Module_Interface
{
    /**
     * Register hooks for this module. Must be idempotent
     * (calling it twice must not double-register anything harmful).
     */
    public static function boot(): void;
}
