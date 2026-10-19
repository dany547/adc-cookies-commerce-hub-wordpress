<?php
/**
 * Google Consent Mode v2 mapping + the cache-safe head bootstrap script
 * (docs/ARCHITECTURE.md §2.2, §3.1; D17, D19).
 *
 * The inline script printed by ADC_Datalayer::print_head must be byte-identical
 * for every visitor (page-cache safe): it reads document.cookie at runtime and
 * never contains user data, nonces or per-user values.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Consent Mode v2 helpers.
 */
final class ADC_Consent_Mode
{
    /**
     * Category -> Google signals it switches (§2.2 table).
     *
     * @return array<string, string[]>
     */
    public static function map(): array
    {
        return array(
            'necessary'   => array('security_storage'),
            'preferences' => array('functionality_storage', 'personalization_storage'),
            'statistics'  => array('analytics_storage'),
            'marketing'   => array('ad_storage', 'ad_user_data', 'ad_personalization'),
        );
    }

    /**
     * Full Consent Mode signal map for a state. Defaults (null / undecided):
     * everything denied except security_storage (always granted, D19).
     *
     * @param ADC_Consent_State|null $state Current state; null = defaults.
     * @return array<string, string> Signal => 'granted'|'denied'.
     */
    public static function signals(?ADC_Consent_State $state = null): array
    {
        $signals = array(
            'ad_storage'              => 'denied',
            'ad_user_data'            => 'denied',
            'ad_personalization'      => 'denied',
            'analytics_storage'       => 'denied',
            'functionality_storage'   => 'denied',
            'personalization_storage' => 'denied',
            'security_storage'        => 'granted',
        );

        if (null === $state || !$state->decided) {
            return $signals;
        }

        $signals['functionality_storage']   = $state->allows('preferences') ? 'granted' : 'denied';
        $signals['personalization_storage'] = $state->allows('preferences') ? 'granted' : 'denied';
        $signals['analytics_storage']       = $state->allows('statistics') ? 'granted' : 'denied';
        $signals['ad_storage']              = $state->allows('marketing') ? 'granted' : 'denied';
        $signals['ad_user_data']            = $state->allows('marketing') ? 'granted' : 'denied';
        $signals['ad_personalization']      = $state->allows('marketing') ? 'granted' : 'denied';

        return $signals;
    }

    /**
     * Stored `consent.regional_defaults` (validated subset of the schema,
     * §4: [{regions:['US'], state:{analytics_storage:'granted',…}}, …]).
     * An entry with an empty `regions` list is the global (no-region) default.
     *
     * @return array<int, array{regions: string[], state: array<string, string>}>
     */
    public static function regional_defaults(): array
    {
        $raw = ADC_Settings::get('consent', 'regional_defaults', array());

        return self::sanitize_regional_defaults(is_array($raw) ? $raw : array());
    }

    /**
     * Validate a raw regional-defaults list (schema §4). An entry with an
     * empty `regions` list is the global (no-region) default override.
     *
     * @param array $raw Raw list of entries.
     * @return array<int, array{regions: string[], state: array<string, string>}>
     */
    public static function sanitize_regional_defaults(array $raw): array
    {
        $out = array();
        foreach (array_slice($raw, 0, 20) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $regions = self::sanitize_regions(is_array($entry['regions'] ?? null) ? $entry['regions'] : array());
            $state   = self::sanitize_gcm_state(is_array($entry['state'] ?? null) ? $entry['state'] : array());

            if (array() === $state) {
                continue;
            }

            $out[] = array(
                'regions' => $regions,
                'state'   => $state,
            );
        }

        return $out;
    }

    /**
     * "EEA + RO denied, rest granted" preset (D19) offered by the Banner tab.
     * First entry (empty regions) sets the global default to granted; the
     * second narrows the EEA down to denied.
     *
     * @return array<int, array{regions: string[], state: array<string, string>}>
     */
    public static function regional_defaults_preset(): array
    {
        $granted = array(
            'ad_storage'              => 'granted',
            'ad_user_data'            => 'granted',
            'ad_personalization'      => 'granted',
            'analytics_storage'       => 'granted',
            'functionality_storage'   => 'granted',
            'personalization_storage' => 'granted',
            'security_storage'        => 'granted',
        );

        $denied = array(
            'ad_storage'              => 'denied',
            'ad_user_data'            => 'denied',
            'ad_personalization'      => 'denied',
            'analytics_storage'       => 'denied',
            'functionality_storage'   => 'denied',
            'personalization_storage' => 'denied',
            'security_storage'        => 'granted',
        );

        return array(
            array(
                'regions' => array(),
                'state'   => $granted,
            ),
            array(
                'regions' => self::eea_regions(),
                'state'   => $denied,
            ),
        );
    }

    /**
     * The inline head bootstrap (§3.1), in this exact order:
     * consent default (denied everywhere + regional overrides) ->
     * url_passthrough / ads_data_redaction -> cookie-driven consent update ->
     * `adc_consent_init` dataLayer push. Returns RAW JavaScript (no <script>
     * tag): ADC_Datalayer prints it via wp_print_inline_script_tag.
     *
     * @return string JavaScript block, cache-safe (identical for all visitors).
     */
    public static function render_head_script(): string
    {
        $policy = max(1, (int) ADC_Settings::get('consent', 'policy_version', 1));
        $days   = self::cookie_days();
        $wait   = max(0, min(5000, (int) ADC_Settings::get('consent', 'wait_for_update', 500)));

        $gtm        = get_option('adc_settings_gtm', array());
        $data_layer = 'dataLayer';
        if (is_array($gtm) && !empty($gtm['datalayer_name'])) {
            $candidate              = preg_replace('/[^A-Za-z0-9_]/', '', (string) $gtm['datalayer_name']) ?? '';
            $data_layer             = '' !== $candidate ? $candidate : 'dataLayer';
        }

        $default = self::signals(null);
        $default['wait_for_update'] = $wait;

        $regional = array();
        foreach (self::regional_defaults() as $entry) {
            if (array() === $entry['regions']) {
                // Global default override (e.g. the D19 "rest granted" preset).
                $default = array_merge($default, $entry['state']);
                $default['wait_for_update'] = $wait;
            } else {
                $regional[] = array_merge(array('region' => $entry['regions']), $entry['state']);
            }
        }

        $js  = 'window["' . $data_layer . '"]=window["' . $data_layer . '"]||[];';
        $js .= 'function gtag(){window["' . $data_layer . '"].push(arguments);}';
        $js .= "gtag('consent','default'," . self::json($default) . ');';

        foreach ($regional as $override) {
            $js .= "gtag('consent','default'," . self::json($override) . ');';
        }

        if ((bool) ADC_Settings::get('consent', 'url_passthrough', true)) {
            $js .= "gtag('set','url_passthrough',true);";
        }
        if ((bool) ADC_Settings::get('consent', 'ads_data_redaction', true)) {
            $js .= "gtag('set','ads_data_redaction',true);";
        }

        // Cookie read + consent update + adc_consent_init push (runtime, per visitor).
        $js .= '(function(){var w=window,c=null,'
            . 'm=/(?:^|;\\s*)adc_consent=([^;]+)/.exec(document.cookie);'
            . 'if(m){try{var q={};decodeURIComponent(m[1]).split("&").forEach(function(p){'
            . 'var i=p.indexOf("=");if(i>0){q[p.slice(0,i)]=decodeURIComponent(p.slice(i+1));}});c=q;}catch(e){c=null;}}'
            . 'var now=Math.floor(Date.now()/1000),'
            . 'ok=!!c&&c.v==="1"&&(+c.pv||0)>=' . $policy . '&&(+c.ts||0)>0&&(+c.ts||0)+' . $days . '*86400>=now,'
            . 'st={preferences:false,statistics:false,marketing:false,decided:false,ts:0,policy:' . $policy . ',source:""},'
            . 'g={ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied",'
            . 'analytics_storage:"denied",functionality_storage:"denied",personalization_storage:"denied",'
            . 'security_storage:"granted"};'
            . 'if(ok){st={preferences:c.p==="1",statistics:c.s==="1",marketing:c.m==="1",decided:true,'
            . 'ts:(+c.ts||0),policy:(+c.pv||0),source:c.src||""};'
            . 'g.ad_storage=g.ad_user_data=g.ad_personalization=st.marketing?"granted":"denied";'
            . 'g.analytics_storage=st.statistics?"granted":"denied";'
            . 'g.functionality_storage=g.personalization_storage=st.preferences?"granted":"denied";'
            . "gtag('consent','update',g);}"
            . 'window["' . $data_layer . '"].push({event:"adc_consent_init",adc_consent:st,gcm:g});})();';

        return $js;
    }

    /**
     * Effective cookie lifetime in days, clamped 30–365 (D16).
     *
     * @return int
     */
    public static function cookie_days(): int
    {
        return max(30, min(365, (int) ADC_Settings::get('consent', 'cookie_days', 180)));
    }

    /**
     * EEA member states (incl. RO; IS/LI/NO included) — D19 preset.
     *
     * @return string[]
     */
    private static function eea_regions(): array
    {
        return array(
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE',
            'GR', 'HU', 'IS', 'IE', 'IT', 'LI', 'LT', 'LU', 'MT', 'NL', 'NO',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES',
        );
    }

    /**
     * Keep only known signals with granted|denied values.
     *
     * @param array $state Raw signal map.
     * @return array<string, string>
     */
    private static function sanitize_gcm_state(array $state): array
    {
        $known  = array_keys(self::signals(null));
        $clean  = array();
        foreach ($known as $signal) {
            if (!isset($state[$signal])) {
                continue;
            }
            $value = (string) $state[$signal];
            if (in_array($value, array('granted', 'denied'), true)) {
                $clean[$signal] = $value;
            }
        }

        return $clean;
    }

    /**
     * Keep only ISO 3166-1 alpha-2 region codes (uppercased).
     *
     * @param array $regions Raw region list.
     * @return string[]
     */
    private static function sanitize_regions(array $regions): array
    {
        $clean = array();
        foreach (array_slice($regions, 0, 300) as $region) {
            $region = strtoupper(substr(sanitize_text_field((string) $region), 0, 2));
            if (2 === strlen($region) && ctype_alpha($region)) {
                $clean[] = $region;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * JSON-encode inline-JS-safe (hex-escaped) payload.
     *
     * @param array $payload Payload to encode.
     * @return string
     */
    private static function json(array $payload): string
    {
        return (string) wp_json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
