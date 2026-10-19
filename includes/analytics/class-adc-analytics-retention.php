<?php
/** Bounded daily retention scheduler for analytics tables. */
declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

final class ADC_Analytics_Retention implements ADC_Module_Interface
{
    public const HOOK = 'adc_analytics_cleanup';
    public static function boot(): void
    {
        add_action('init', [self::class, 'schedule']);
        add_action(self::HOOK, [self::class, 'run']);
    }
    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) { wp_schedule_event(strtotime('tomorrow 03:00', current_time('timestamp')), 'daily', self::HOOK); }
    }
    public static function run(): void
    {
        if (!class_exists('ADC_Settings')) { return; }
        // Single source of truth: the analytics settings (ledger >= commerce is enforced there).
        if (class_exists('ADC_Analytics_Settings')) {
            $traffic = ADC_Analytics_Settings::retention_days('traffic');
            $commerce = ADC_Analytics_Settings::retention_days('commerce');
            $ledger = ADC_Analytics_Settings::retention_days('ledger');
        } else {
            $traffic = max(1, min(3650, (int) ADC_Settings::get('analytics', 'retention_traffic_days', 90)));
            $commerce = max(1, min(3650, (int) ADC_Settings::get('analytics', 'retention_commerce_days', 365)));
            $ledger = max($commerce, min(3650, (int) ADC_Settings::get('analytics', 'retention_ledger_days', max($commerce, 400))));
        }
        $today = new DateTimeImmutable(current_time('Y-m-d'), wp_timezone());
        $trafficBefore = $today->modify('-' . $traffic . ' days')->format('Y-m-d');
        $commerceBefore = $today->modify('-' . $commerce . ' days')->format('Y-m-d');
        $ledgerBefore = $today->modify('-' . $ledger . ' days')->format('Y-m-d');
        foreach ([ADC_Analytics_Schema::TRAFFIC, ADC_Analytics_Schema::INTERACTIONS, ADC_Analytics_Schema::CAMPAIGNS] as $key) { ADC_Analytics_Store::purge($key, $trafficBefore); }
        foreach ([ADC_Analytics_Schema::COMMERCE, ADC_Analytics_Schema::PRODUCTS] as $key) { ADC_Analytics_Store::purge($key, $commerceBefore); }
        ADC_Analytics_Store::purge('ledger', $ledgerBefore);
    }
}
