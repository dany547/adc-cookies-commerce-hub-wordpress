<?php
/**
 * Admin tab "Jurnale" (docs/ARCHITECTURE.md §4).
 *
 * Outbox table + status counts + retry/purge, consent lookup by cid,
 * "Trimite eveniment test", snippet-conflict detector, Site Health test and
 * the logs settings group (`logs`: level, debug_console).
 *
 * Also owns the documented admin-AJAX endpoints (nonce `adc_admin`,
 * cap `manage_options`): adc_outbox_retry, adc_outbox_purge and
 * adc_test_integration.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logs & debug admin tab.
 */
final class ADC_Admin_Tab_Logs implements ADC_Admin_Tab_Interface, ADC_Module_Interface
{
    use ADC_Admin_Help_Content;

    /**
     * Whether boot() already registered the AJAX handlers.
     *
     * @var bool
     */
    private static bool $booted = false;

    /**
     * Register AJAX + Site Health hooks (called by the module registry).
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_action('adc_register_settings', array(self::class, 'register_settings'));

        add_action('admin_enqueue_scripts', array(self::class, 'enqueue_assets'));

        add_action('wp_ajax_adc_outbox_retry', array(self::class, 'ajax_outbox_retry'));
        add_action('wp_ajax_adc_outbox_purge', array(self::class, 'ajax_outbox_purge'));
        add_action('wp_ajax_adc_test_integration', array(self::class, 'ajax_test_integration'));
        add_action('wp_ajax_adc_send_test_event', array(self::class, 'ajax_send_test_event'));
        add_action('wp_ajax_adc_consent_lookup', array(self::class, 'ajax_consent_lookup'));

        add_filter('site_status_tests', array(self::class, 'site_health_test'));
    }

    /**
     * Enqueue the tab assets on the ADC Marketing admin page only.
     *
     * @param string $hook_suffix Current admin page hook.
     */
    public static function enqueue_assets(string $hook_suffix): void
    {
        if ('toplevel_page_' . ADC_Admin_Shell::PAGE_SLUG !== $hook_suffix || !current_user_can('manage_options')) {
            return;
        }

        $version = defined('ADC_MARKETING_VERSION') ? (string) ADC_MARKETING_VERSION : null;
        $base    = defined('ADC_MARKETING_PLUGIN_URL') ? rtrim(ADC_MARKETING_PLUGIN_URL, '/') . '/assets/' : '';

        if ('' === $base) {
            return;
        }

        wp_enqueue_style('adc-admin-logs', $base . 'css/admin-logs.css', array(), $version);
        wp_enqueue_script(
            'adc-admin-logs',
            $base . 'js/admin-logs.js',
            array('adc-admin-shell'),
            $version,
            array('in_footer' => true, 'strategy' => 'defer')
        );
    }

    /**
     * Register the `logs` settings group (§4).
     */
    public static function register_settings(): void
    {
        ADC_Settings::register_group(
            'logs',
            array(
                'level'         => 'error',
                'debug_console' => false,
            ),
            static function (array $input, array $current): array {
                $level = (string) ($input['level'] ?? '');

                return array(
                    'level'         => in_array($level, array('off', 'error', 'info', 'debug'), true) ? $level : 'error',
                    'debug_console' => !empty($input['debug_console']),
                );
            },
            array()
        );
    }

    /**
     * Tab slug.
     */
    public function slug(): string
    {
        return 'logs';
    }

    /**
     * Tab label (RO).
     */
    public function label(): string
    {
        return __('Jurnale', 'adc-marketing');
    }

    /**
     * Top-level tab.
     */
    public function group(): string
    {
        return '';
    }

    /**
     * Sort order (§2.1: logs 90).
     */
    public function order(): int
    {
        return 90;
    }

    /**
     * Render the tab inner HTML (no form, no nonce — the shell wraps them).
     */
    public function render(): void
    {
        $settings = ADC_Settings::all('logs');
        $counts   = ADC_Outbox::counts();
        $jobs     = ADC_Outbox::recent(200);

        ?>

        <h3 class="title"><?php esc_html_e('Evenimente trimise', 'adc-marketing'); ?> <?php echo ADC_Admin_Help::icon('adc-help-outbox', __('Evenimente trimise', 'adc-marketing'), $this->help_outbox()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes its own output. ?></h3>
        <p class="description"><?php esc_html_e('Numărătoarea pe stări a cozii de expediere: „În așteptare”, „Trimis”, „Eșuat definitiv” și „Omis” (fără consimțământ / integrare dezactivată).', 'adc-marketing'); ?></p>
        <table class="widefat striped adc-logs-counts" role="presentation">
            <tbody>
                <?php foreach (ADC_Outbox::STATUSES as $status) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html(self::status_label($status)); ?></th>
                        <td><?php echo esc_html((string) ($counts[$status] ?? 0)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="adc-logs-actions">
            <button type="button" class="button" id="adc-outbox-purge" data-confirm="<?php esc_attr_e('Ștergi definitiv intrările trimise/dead din jurnal?', 'adc-marketing'); ?>">
                <?php esc_html_e('Curăță jurnalul (retenție)', 'adc-marketing'); ?>
            </button>
            <button type="button" class="button button-secondary" id="adc-send-test-event" data-confirm="<?php esc_attr_e('Trimiți un eveniment de test către platformele activate (conform consimțământului browserului tău)?', 'adc-marketing'); ?>">
                <?php esc_html_e('Trimite eveniment test', 'adc-marketing'); ?>
            </button>
            <?php echo ADC_Admin_Help::icon('adc-help-test-event', __('Trimite eveniment test', 'adc-marketing'), $this->help_test_event()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes its own output. ?>
            <span class="adc-logs-result" id="adc-logs-result" aria-live="polite"></span>
        </p>
        <p class="description"><?php esc_html_e('„Curăță jurnalul” aplică imediat limitele de retenție (rândurile trimise/dead se șterg); „Trimite eveniment test” trece prin pipeline-ul real ca să verifici configurarea.', 'adc-marketing'); ?></p>

        <h3 class="title"><?php esc_html_e('Ultimele 200 intrări', 'adc-marketing'); ?></h3>
        <p class="description"><?php esc_html_e('Ultimele evenimente înregistrate în coadă, cu starea, încercările și eroarea finală; „Reîncearcă” reprogramează imediat o intrare eșuată.', 'adc-marketing'); ?></p>
        <table class="widefat striped adc-logs-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('ID', 'adc-marketing'); ?></th>
                    <th><?php esc_html_e('Eveniment', 'adc-marketing'); ?></th>
                    <th><?php esc_html_e('Platformă', 'adc-marketing'); ?></th>
                    <th><?php esc_html_e('Comandă', 'adc-marketing'); ?></th>
                    <th><?php esc_html_e('Stare', 'adc-marketing'); ?></th>
                    <th><?php esc_html_e('Încercări', 'adc-marketing'); ?></th>
                    <th><?php esc_html_e('HTTP', 'adc-marketing'); ?></th>
                    <th><?php esc_html_e('Programat', 'adc-marketing'); ?></th>
                    <th><?php esc_html_e('Acțiuni', 'adc-marketing'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (array() === $jobs) : ?>
                    <tr><td colspan="9"><?php esc_html_e('Nicio intrare în jurnal.', 'adc-marketing'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($jobs as $job) : ?>
                        <tr>
                            <td><?php echo esc_html((string) $job->id); ?></td>
                            <td><?php echo esc_html($job->event_name); ?></td>
                            <td><?php echo esc_html($job->platform); ?></td>
                            <td><?php echo $job->order_id > 0 ? esc_html((string) $job->order_id) : '—'; ?></td>
                            <td><span class="adc-status adc-status--<?php echo esc_attr($job->status); ?>"><?php echo esc_html(self::status_label($job->status)); ?></span></td>
                            <td><?php echo esc_html((string) $job->attempts); ?></td>
                            <td><?php echo $job->http_code > 0 ? esc_html((string) $job->http_code) : '—'; ?></td>
                            <td><?php echo esc_html($job->scheduled_at); ?></td>
                            <td>
                                <?php if ('sent' !== $job->status) : ?>
                                    <button type="button" class="button button-small adc-outbox-retry" data-id="<?php echo esc_attr((string) $job->id); ?>">
                                        <?php esc_html_e('Reîncearcă', 'adc-marketing'); ?>
                                    </button>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ('' !== $job->last_error) : ?>
                            <tr class="adc-logs-error-row">
                                <td colspan="9"><code><?php echo esc_html($job->last_error); ?></code></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php ADC_Admin_Help::advanced_start(__('Instrumente și setări avansate', 'adc-marketing')); ?>

        <h3 class="title"><?php esc_html_e('Căutare consimțământ după cid', 'adc-marketing'); ?> <?php echo ADC_Admin_Help::icon('adc-help-consent-lookup', __('Căutare consimțământ după cid', 'adc-marketing'), $this->help_consent_lookup()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes its own output. ?></h3>
        <p class="description"><?php esc_html_e('Lipește identificatorul anonim de consimțământ (UUID) al unui vizitator pentru a-i vedea deciziile efectiv valabile — util pentru solicitări GDPR.', 'adc-marketing'); ?></p>
        <p>
            <input type="text" id="adc-consent-cid" class="regular-text" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
            <button type="button" class="button" id="adc-consent-lookup"><?php esc_html_e('Caută', 'adc-marketing'); ?></button>
        </p>
        <div id="adc-consent-lookup-result" class="adc-consent-lookup-result" aria-live="polite"></div>

        <h3 class="title"><?php esc_html_e('Setări jurnal', 'adc-marketing'); ?></h3>

        <?php
        echo '<div class="adc-help-options">';
        ADC_Admin_Help::row(
            __('Nivel jurnal', 'adc-marketing'),
            '<select id="adc-logs-level" name="level">'
                . '<option value="off" ' . selected('off', (string) $settings['level'], false) . '>' . esc_html__('Dezactivat', 'adc-marketing') . '</option>'
                . '<option value="error" ' . selected('error', (string) $settings['level'], false) . '>' . esc_html__('Doar erori', 'adc-marketing') . '</option>'
                . '<option value="info" ' . selected('info', (string) $settings['level'], false) . '>' . esc_html__('Informativ', 'adc-marketing') . '</option>'
                . '<option value="debug" ' . selected('debug', (string) $settings['level'], false) . '>' . esc_html__('Debug', 'adc-marketing') . '</option>'
            . '</select>',
            __('Cât de detaliat scrie pluginul în jurnalul PHP al serverului; „Debug” doar pentru depanare temporară, nu în producție.', 'adc-marketing'),
            'adc-logs-level'
        );
        ADC_Admin_Help::row(
            __('Consolă dataLayer', 'adc-marketing'),
            '<label><input type="checkbox" name="debug_console" value="1" ' . checked(!empty($settings['debug_console']), true, false) . ' /> ' . esc_html__('Afișează evenimentele dataLayer în consolă (doar pentru utilizatori autentificați ca admin)', 'adc-marketing') . '</label>',
            __('Vizualizorii autentificați cu rol de admin văd în consola browserului fiecare eveniment trimis în dataLayer; vizitatorii obișnuiți nu.', 'adc-marketing')
        );
        echo '</div>';
        ?>

        <?php self::render_conflicts(); ?>

        <?php ADC_Admin_Help::advanced_end(); ?>

        <?php self::localize_config(); ?>
        <?php
    }

    /**
     * Save the logs settings group.
     *
     * @param array $post wp_unslash($_POST).
     * @return array<int, array{type: string, message: string}>
     */
    public function save(array $post): array
    {
        ADC_Settings::update(
            'logs',
            array(
                'level'         => (string) ($post['level'] ?? ''),
                'debug_console' => !empty($post['debug_console']),
            )
        );

        return array(
            array(
                'type'    => 'success',
                'message' => __('Setările de jurnal au fost salvate.', 'adc-marketing'),
            ),
        );
    }

    /* ---------------------------------------------------------------------
     * AJAX endpoints (nonce adc_admin, cap manage_options).
     * ------------------------------------------------------------------- */

    /**
     * Verify nonce + capability for every AJAX handler.
     */
    private static function verify_request(): void
    {
        check_ajax_referer('adc_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Nu aveți permisiunea necesară.', 'adc-marketing')), 403);
        }
    }

    /**
     * Re-queue one outbox row immediately.
     */
    public static function ajax_outbox_retry(): void
    {
        self::verify_request();

        $id = isset($_POST['id']) ? absint(wp_unslash($_POST['id'])) : 0;
        $job = ADC_Outbox::get($id);

        if (null === $job || 'sent' === $job->status) {
            wp_send_json_error(array('message' => __('Intrarea nu poate fi reîncercată.', 'adc-marketing')), 400);
        }

        if (!ADC_Outbox::reset_for_retry($id)) {
            wp_send_json_error(array('message' => __('Reprogramarea a eșuat.', 'adc-marketing')), 500);
        }

        // Schedule immediately (AS group adc-marketing).
        if (class_exists('ActionScheduler')) {
            as_schedule_single_action(time(), ADC_Dispatcher::JOB_HOOK, array('job_id' => $id), ADC_Dispatcher::GROUP);
        } else {
            wp_schedule_single_event(time(), ADC_Dispatcher::JOB_HOOK, array('job_id' => $id));
        }

        wp_send_json_success(array('message' => __('Intrarea a fost reprogramată.', 'adc-marketing')));
    }

    /**
     * Purge sent/dead rows per retention settings.
     */
    public static function ajax_outbox_purge(): void
    {
        self::verify_request();

        $sent_days = max(1, (int) ADC_Settings::get('events', 'outbox_sent_days', 14));
        $dead_days = max(1, (int) ADC_Settings::get('events', 'outbox_dead_days', 30));

        $deleted = ADC_Outbox::purge($sent_days, $dead_days);

        wp_send_json_success(array(
            'deleted' => $deleted,
            'message' => sprintf(
                /* translators: %d: number of deleted rows. */
                _n('%d intrare a fost ștearsă.', '%d intrări au fost șterse.', $deleted, 'adc-marketing'),
                $deleted
            ),
        ));
    }

    /**
     * Platform connection test (documented admin AJAX, used by integration tabs).
     *
     * The tab script sends the unsaved values of its own fields as a JSON
     * object in `fields`; they are sanitised against a closed per-platform
     * allowlist and applied in memory only for this request (never saved,
     * never logged, never echoed back). An empty secret keeps the stored one.
     */
    public static function ajax_test_integration(): void
    {
        self::verify_request();

        $platform = isset($_POST['platform']) ? sanitize_key(wp_unslash($_POST['platform'])) : '';
        $integration = self::integration_by_id($platform);

        if (null === $integration) {
            wp_send_json_error(array('message' => __('Platforma nu este disponibilă.', 'adc-marketing')), 400);
        }

        $overrides = self::sanitize_test_fields($platform);
        $group     = self::test_group($platform);

        // Unsaved form values shadow the stored ones in memory for this test
        // only: nothing is written to the database and nothing is echoed back.
        if (array() !== $overrides) {
            ADC_Settings::override_group($group, $overrides);
        }

        try {
            $result = $integration->test();
        } finally {
            ADC_Settings::clear_override($group);
        }

        wp_send_json_success(array(
            'status'    => $result->status,
            'http_code' => $result->http_code,
            'from_form' => array() !== $overrides,
            'message'   => 'skipped' === $result->status ? self::skip_message($result->message) : $result->message,
        ));
    }

    /**
     * Settings group a connection test reads for a platform.
     *
     * @param string $platform Platform id.
     * @return string Group slug.
     */
    private static function test_group(string $platform): string
    {
        $spec = self::test_field_spec($platform);

        return null !== $spec ? $spec['group'] : $platform;
    }

    /**
     * Closed allowlist of the fields a connection test may override, per
     * platform: settings group + accepted key => sanitiser kind and length.
     *
     * @param string $platform Platform id.
     * @return array{group: string, fields: array<string, array{kind: string, max: int}>}|null
     */
    private static function test_field_spec(string $platform): ?array
    {
        $specs = array(
            'meta'   => array(
                'group'  => 'meta',
                'fields' => array(
                    'pixel_id'        => array('kind' => 'id', 'max' => 30),
                    'test_event_code' => array('kind' => 'id', 'max' => 40),
                    'access_token'    => array('kind' => 'secret', 'max' => 512),
                ),
            ),
            'tiktok' => array(
                'group'  => 'tiktok',
                'fields' => array(
                    'pixel_code'      => array('kind' => 'id', 'max' => 40),
                    'test_event_code' => array('kind' => 'id', 'max' => 40),
                    'access_token'    => array('kind' => 'secret', 'max' => 512),
                ),
            ),
            'ga4'    => array(
                'group'  => 'ga4',
                'fields' => array(
                    'measurement_id' => array('kind' => 'id', 'max' => 20),
                    'api_secret'     => array('kind' => 'secret', 'max' => 128),
                ),
            ),
            'sgtm'   => array(
                'group'  => 'sgtm',
                'fields' => array(
                    'server_container_url' => array('kind' => 'url', 'max' => 200),
                    'measurement_id'       => array('kind' => 'id', 'max' => 20),
                    'mode'                 => array('kind' => 'mode', 'max' => 16),
                    'api_secret'           => array('kind' => 'secret', 'max' => 128),
                ),
            ),
        );

        return $specs[$platform] ?? null;
    }

    /**
     * Sanitise the unsaved tab values sent with a connection test. Only
     * allowlisted keys are considered; an empty secret is dropped so the
     * stored secret is used (secrets are never echoed back into the form).
     *
     * @param string $platform Platform id.
     * @return array<string, mixed> Sanitised overrides (empty when none).
     */
    private static function sanitize_test_fields(string $platform): array
    {
        $spec = self::test_field_spec($platform);
        if (null === $spec) {
            return array();
        }

        $raw = isset($_POST['fields']) ? wp_unslash((string) $_POST['fields']) : '';
        if (!is_string($raw) || '' === trim($raw) || strlen($raw) > 8192) {
            return array();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return array();
        }

        $overrides = array();
        foreach ($spec['fields'] as $key => $rule) {
            if (!array_key_exists($key, $decoded) || !is_scalar($decoded[$key])) {
                continue;
            }

            $value = self::sanitize_test_value($rule['kind'], (string) $decoded[$key], (int) $rule['max']);

            // 'secret': empty means "keep the stored secret". 'mode': an invalid
            // choice keeps the stored mode. Everything else mirrors the form.
            if (('secret' === $rule['kind'] || 'mode' === $rule['kind']) && '' === $value) {
                continue;
            }

            $overrides[$key] = $value;
        }

        return $overrides;
    }

    /**
     * Per-field sanitiser for test values.
     *
     * @param string $kind  Sanitiser kind (id|secret|url|mode).
     * @param string $value Raw value.
     * @param int    $max   Maximum accepted length.
     * @return string Sanitised value ('' when it must not be used).
     */
    private static function sanitize_test_value(string $kind, string $value, int $max): string
    {
        $value = trim($value);

        return match ($kind) {
            'secret' => substr(sanitize_text_field($value), 0, $max),
            'url'    => self::sanitize_test_url($value, $max),
            'mode'   => in_array($value, array('loader', 'mp_forward'), true) ? $value : '',
            default  => substr((string) preg_replace('/[^0-9A-Za-z._-]/', '', sanitize_text_field($value)), 0, $max),
        };
    }

    /**
     * https-only URL sanitiser (same SSRF baseline as the settings sanitizer).
     *
     * @param string $value Raw URL.
     * @param int    $max   Maximum accepted length.
     * @return string Sanitised URL, '' when it is not a valid https URL.
     */
    private static function sanitize_test_url(string $value, int $max): string
    {
        $url = substr((string) esc_url_raw($value), 0, $max);

        if ('' === $url || !str_starts_with($url, 'https://') || !wp_http_validate_url($url)) {
            return '';
        }

        return rtrim($url, '/');
    }

    /**
     * Romanian text for known integration skip slugs surfaced by the test
     * endpoint (ADC_Send_Result::skipped() stores the raw slug as message);
     * unknown slugs fall back to the raw value.
     *
     * @param string $slug Skip reason slug.
     * @return string
     */
    private static function skip_message(string $slug): string
    {
        $messages = array(
            'disabled'                     => __('Integrarea este dezactivată. Activează platforma din setări și testează din nou.', 'adc-marketing'),
            'consent_denied'               => __('Browserul curent nu a acordat consimțământ pentru măsurare. Acordă-l din bannerul de cookie-uri și testează din nou.', 'adc-marketing'),
            'purchase_browser_channel'     => __('Comenzile (purchase) se trimit doar prin canalul server, deci testul din browser este omis intenționat.', 'adc-marketing'),
            'no_client_id'                 => __('Lipsește identificatorul de client al browserului (client_id). Vizitează site-ul cu consimțământ acordat și testează din nou.', 'adc-marketing'),
            'measurement_id_missing'       => __('Lipsește ID-ul de măsurare GA4 (Measurement ID). Completează-l, salvează și testează din nou.', 'adc-marketing'),
            'api_secret_missing'           => __('Lipsește API secret pentru GA4. Completează-l, salvează și testează din nou.', 'adc-marketing'),
            'loader_mode'                  => __('Containerul server GTM rulează în mod „loader”, deci evenimentele nu se trimit direct. Testul este omis.', 'adc-marketing'),
            'server_container_url_missing' => __('Lipsește URL-ul containerului server (sGTM). Completează-l, salvează și testează din nou.', 'adc-marketing'),
            'insufficient_user_data'       => __('Datele despre utilizator sunt insuficiente pentru potrivirea avansată. Folosește un test cu e-mail și telefon completate.', 'adc-marketing'),
            'pixel_id_missing'             => __('Lipsește ID-ul de pixel Meta. Completează-l, salvează și testează din nou.', 'adc-marketing'),
            'access_token_missing'         => __('Lipsește token-ul de acces. Completează-l, salvează și testează din nou.', 'adc-marketing'),
            'test_event_code_missing'      => __('Lipsește codul de test al evenimentelor (Test Event Code) din Meta Events Manager. Completează-l, salvează și testează din nou.', 'adc-marketing'),
            'pixel_code_missing'           => __('Lipsește codul de pixel TikTok (Pixel Code). Completează-l, salvează și testează din nou.', 'adc-marketing'),
        );

        return $messages[$slug] ?? $slug;
    }

    /**
     * Enqueue one test event through the real pipeline (Logs tab endpoint,
     * own AJAX action per §2.1 "tabs register their own endpoints").
     */
    public static function ajax_send_test_event(): void
    {
        self::verify_request();

        $consent = class_exists('ADC_Consent') ? ADC_Consent::current() : null;

        if (null === $consent || (!$consent->allows('marketing') && !$consent->allows('statistics'))) {
            wp_send_json_error(array('message' => __('Browserul tău nu are consimțământ acordat pentru măsurare. Acordă consimțământul pe site și reia testul.', 'adc-marketing')), 403);
        }

        $event = new ADC_Event(
            name: 'generate_lead',
            event_id: ADC_Event_Id::random(),
            time: time(),
            origin: 'server',
            source_url: home_url('/'),
            currency: function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '',
            cid: $consent->cid,
            consent: $consent->to_array(),
            params: array('method' => 'adc_test'),
        );

        $results = ADC_Dispatcher::enqueue($event);

        wp_send_json_success(array(
            'event_id' => $event->event_id,
            'results'  => $results,
            'message'  => array() === $results
                ? __('Nicio platformă activă pentru acest eveniment.', 'adc-marketing')
                : __('Evenimentul a fost adăugat în coadă. Rulează procesarea Action Scheduler pentru livrare.', 'adc-marketing'),
        ));
    }

    /* ---------------------------------------------------------------------
     * Consent lookup by cid.
     * ------------------------------------------------------------------- */

    /**
     * Effective consent state for one cid (documented facade; rendered via
     * AJAX from admin-logs.js).
     */
    public static function ajax_consent_lookup(): void
    {
        self::verify_request();

        $cid = isset($_POST['cid']) ? sanitize_text_field(wp_unslash($_POST['cid'])) : '';
        if (!preg_match('/^[0-9a-fA-F-]{8,64}$/', $cid)) {
            wp_send_json_error(array('message' => __('Identificator invalid.', 'adc-marketing')), 400);
        }

        if (!class_exists('ADC_Consent')) {
            wp_send_json_error(array('message' => __('Motorul de consimțământ nu este disponibil.', 'adc-marketing')), 400);
        }

        $state = ADC_Consent::effective($cid);

        wp_send_json_success(array(
            'state' => array(
                'preferences' => $state->allows('preferences'),
                'statistics'  => $state->allows('statistics'),
                'marketing'   => $state->allows('marketing'),
                'decided'     => $state->decided,
                'ts'          => $state->ts,
                'policy'      => $state->policy,
                'source'      => $state->source,
            ),
        ));
    }

    /* ---------------------------------------------------------------------
     * Assets.
     * ------------------------------------------------------------------- */

    /**
     * Inline config for admin-logs.js (nonce + endpoints + i18n).
     */
    private static function localize_config(): void
    {
        $config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('adc_admin'),
            'i18n'    => array(
                'retrying'      => __('Se reprogramează…', 'adc-marketing'),
                'purging'       => __('Se curăță…', 'adc-marketing'),
                'testing'       => __('Se trimite…', 'adc-marketing'),
                'lookingUp'     => __('Se caută…', 'adc-marketing'),
                'lookupFound'   => __('Consimțământ efectiv pentru acest cid:', 'adc-marketing'),
                'lookupDenied'  => __('Nicio decizie de consimțământ pentru acest cid.', 'adc-marketing'),
                'granted'       => __('acordat', 'adc-marketing'),
                'denied'        => __('refuzat', 'adc-marketing'),
                'unknownError'  => __('A apărut o eroare. Reîncarcă pagina.', 'adc-marketing'),
            ),
        );

        $json = wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (is_string($json)) {
            wp_print_inline_script_tag('window.adcAdminLogs = ' . $json . ';');
        }
    }

    /* ---------------------------------------------------------------------
     * Conflict detector + Site Health.
     * ------------------------------------------------------------------- */

    /**
     * Scan the home page HTML for duplicate tracking snippets / foreign CMPs.
     * Cached 12 h in a transient; detection only — never auto-disables (§7.11).
     */
    private static function render_conflicts(): void
    {
        $findings = get_transient('adc_conflict_scan');
        if (!is_array($findings)) {
            $findings = self::scan_home_html();
            set_transient('adc_conflict_scan', $findings, 12 * HOUR_IN_SECONDS);
        }

        if (array() === $findings) {
            return;
        }
        ?>
        <h3 class="title"><?php esc_html_e('Detectare conflicte (prima pagină)', 'adc-marketing'); ?></h3>
        <table class="widefat striped adc-logs-conflicts">
            <tbody>
                <?php foreach ($findings as $finding) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($finding['label']); ?></strong></td>
                        <td><?php echo esc_html($finding['detail']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Fetch the home page once and look for known snippet fingerprints.
     *
     * @return array<int, array{label: string, detail: string}>
     */
    private static function scan_home_html(): array
    {
        $response = wp_remote_get(
            home_url('/'),
            array('timeout' => 5, 'redirection' => 0, 'sslverify' => true)
        );

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return array();
        }

        $html = (string) wp_remote_retrieve_body($response);
        if ('' === $html) {
            return array();
        }

        $signals = array(
            'googletagmanager.com/gtm.js' => __('GTM snippet prezent', 'adc-marketing'),
            'gtag('                       => __('Apel direct gtag()', 'adc-marketing'),
            'connect.facebook.net'        => __('Meta Pixel direct', 'adc-marketing'),
            'analytics.tiktok.com'        => __('TikTok Pixel direct', 'adc-marketing'),
            'google-analytics.com'        => __('Google Analytics direct', 'adc-marketing'),
            'complianz'                   => __('Alt CMP activ: Complianz', 'adc-marketing'),
            'cookiebot'                   => __('Alt CMP activ: Cookiebot', 'adc-marketing'),
            'cookieyes'                   => __('Alt CMP activ: CookieYes', 'adc-marketing'),
        );

        $findings = array();
        foreach ($signals as $needle => $label) {
            $count = substr_count(strtolower($html), strtolower($needle));
            if ($count > 0) {
                /* translators: 1: signal label, 2: occurrence count. */
                $findings[] = array(
                    'label'  => $label,
                    'detail' => sprintf(_n('1 apariție în HTML.', '%d apariții în HTML.', $count, 'adc-marketing'), $count),
                );
            }
        }

        return $findings;
    }

    /**
     * Site Health test: pipeline readiness (Action Scheduler + outbox state).
     *
     * @param array $tests Existing tests.
     * @return array
     */
    public static function site_health_test(array $tests): array
    {
        $tests['direct']['adc_marketing_tracking'] = array(
            'label' => __('Adcelerum Commerce Hub: pipeline de urmărire', 'adc-marketing'),
            'test'  => array(self::class, 'run_site_health_check'),
        );

        return $tests;
    }

    /**
     * Site Health check callback.
     *
     * @return array{label: string, status: string, badge: array{label: string, color: string}, test: string}
     */
    public static function run_site_health_check(): array
    {
        $result = array(
            'label'       => __('Adcelerum Commerce Hub: pipeline-ul de trimitere este operațional', 'adc-marketing'),
            'status'      => 'good',
            'badge'       => array(
                'label' => __('Adcelerum Commerce Hub', 'adc-marketing'),
                'color' => 'green',
            ),
            'test'        => 'adc_marketing_tracking',
        );

        if (!class_exists('ActionScheduler')) {
            $result['status']      = 'recommended';
            $result['label']       = __('Action Scheduler nu este disponibil — coada folosește WP-Cron ca rezervă', 'adc-marketing');
            $result['badge']['color'] = 'orange';

            return $result;
        }

        $counts = ADC_Outbox::counts();
        if (($counts['dead'] ?? 0) > 0) {
            $result['status']      = 'recommended';
            $result['label']       = sprintf(
                /* translators: %d: number of dead jobs. */
                _n('%d eveniment a eșuat definitiv (dead).', '%d evenimente au eșuat definitiv (dead).', (int) $counts['dead'], 'adc-marketing'),
                (int) $counts['dead']
            );
            $result['badge']['color'] = 'orange';
        }

        return $result;
    }

    /* ---------------------------------------------------------------------
     * Helpers.
     * ------------------------------------------------------------------- */

    /**
     * Find one integration instance by platform id (from the documented filter).
     *
     * @param string $platform Platform id.
     * @return ADC_Integration_Interface|null
     */
    private static function integration_by_id(string $platform): ?ADC_Integration_Interface
    {
        $classes = apply_filters('adc_integrations', array());
        $classes = is_array($classes) ? $classes : array();

        foreach ($classes as $class) {
            if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, ADC_Integration_Interface::class)) {
                continue;
            }

            $instance = new $class();
            if ($instance instanceof ADC_Integration_Interface && $instance->id() === $platform) {
                return $instance;
            }
        }

        return null;
    }

    /**
     * Human-readable status label.
     *
     * @param string $status Raw status.
     * @return string
     */
    private static function status_label(string $status): string
    {
        $labels = array(
            'pending'             => __('În așteptare', 'adc-marketing'),
            'sent'                => __('Trimis', 'adc-marketing'),
            'dead'                => __('Eșuat definitiv', 'adc-marketing'),
            'skipped'             => __('Omis', 'adc-marketing'),
            'skipped_no_consent'  => __('Fără consimțământ', 'adc-marketing'),
            'skipped_disabled'    => __('Integrare dezactivată', 'adc-marketing'),
        );

        return $labels[$status] ?? $status;
    }
}
