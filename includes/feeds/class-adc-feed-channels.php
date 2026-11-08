<?php
/** Feed channel registry for supported and upcoming catalog integrations. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Describes formats and runtime classes for each feed channel. */
final class ADC_Feed_Channels
{
    /** @return array<string,array{label:string,formats:array<int,string>,id_limit:int,profile:class-string,validator:class-string}> */
    public static function all(): array
    {
        return array(
            'google' => array(
                'label' => function_exists('__') ? __('Google Merchant Center', 'adc-marketing') : 'Google Merchant Center',
                'formats' => array('xml'),
                'id_limit' => 50,
                'profile' => ADC_Feed_Profile_Google::class,
                'validator' => 'ADC_Feed_Validator_Google',
            ),
            'meta' => array(
                'label' => function_exists('__') ? __('Meta Commerce Catalog', 'adc-marketing') : 'Meta Commerce Catalog',
                'formats' => array('xml', 'csv'),
                'id_limit' => 100,
                'profile' => 'ADC_Feed_Profile_Meta',
                'validator' => 'ADC_Feed_Validator_Meta',
            ),
            'tiktok' => array(
                'label' => function_exists('__') ? __('TikTok Catalog', 'adc-marketing') : 'TikTok Catalog',
                'formats' => array('csv', 'xml'),
                'id_limit' => 0,
                'profile' => 'ADC_Feed_Profile_Tiktok',
                'validator' => 'ADC_Feed_Validator_Tiktok',
            ),
        );
    }

    /** Return channel metadata, or null for unsupported/future channels. */
    public static function get(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }
}
