<?php
/**
 * Admin REST API for product feeds (namespace adc/v1).
 *
 * Routes:
 *   GET    /feeds                       list feeds
 *   POST   /feeds                       create + queue the first generation
 *   GET    /feeds/{id}                  one feed
 *   PATCH  /feeds/{id}                  rename, enable/disable, replace config
 *   DELETE /feeds/{id}                  delete the feed row
 *   POST   /feeds/{id}/regenerate       queue a regeneration (202, rate limited)
 *   POST   /feeds/{id}/regenerate-slug  rotate the opaque public slug
 *
 * Every route requires manage_options and a valid `wp_rest` nonce. Responses
 * expose only whitelisted fields: no file paths, no storage details, no stack
 * traces. Persistence and generation are delegated to ADC_Feed_Store and
 * ADC_Feed_Generator; they are resolved at call time and a 503 is returned when
 * they are not loaded, so this class can be tested in isolation.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST transport of the feed module.
 */
final class ADC_Feed_Rest
{
    /** REST namespace shared with the consent engine. */
    public const NAMESPACE = 'adc/v1';

    /** Manual regeneration rate limit: REGEN_MAX requests per REGEN_WINDOW seconds per user. */
    public const REGEN_WINDOW = 60;
    public const REGEN_MAX    = 10;

    /** Destinations known to the wizard. Only the available ones can be created. */
    public const CHANNELS = array('google', 'meta', 'tiktok', 'olx', 'custom');

    /** Channels that carry their own config block (exactly one per feed). */
    private const CHANNEL_BLOCKS = array('google', 'meta', 'tiktok');

    /** Meta internal labels: at most 5 per product in the wizard, 110 characters each (Meta limit). */
    public const META_LABELS_MAX = 5;
    public const LABEL_MAX       = 110;
    public const BRAND_MAX       = 100;

    public const STATUSES   = array('active', 'disabled');
    public const CONDITIONS = array('new', 'refurbished', 'used');
    public const SCOPES     = array('all', 'filtered', 'smart');
    public const REFRESHES  = array('manual', 'hourly', 'daily', 'weekly');

    /** Upper bound on category slugs in one feed. */
    public const CATEGORY_MAX = 50;

    /** Upper bound on messages (errors or warnings) returned per run. */
    public const RUN_MESSAGE_MAX = 50;

    /** Diagnostics are data codes only; arbitrary messages and paths never cross the API. */
    private const DIAGNOSTIC_CODES = array(
        'unknown_diagnostic', 'invalid_feed_id', 'feed_not_found', 'feed_disabled', 'unsupported_feed_profile',
        'feed_busy', 'feed_busy_retry_scheduled', 'feed_busy_retry_exhausted', 'feed_busy_retry_failed',
        'feed_dirty_retry_exhausted', 'feed_dirty_retry_failed', 'feed_storage_unavailable', 'feed_private_staging_unavailable',
        'feed_file_open_failed', 'feed_id_index_unavailable', 'feed_id_index_failed', 'feed_lock_lost',
        'feed_write_failed', 'feed_close_failed', 'feed_file_permissions_failed', 'feed_xml_invalid', 'feed_publish_failed',
        'snapshot_record_failed', 'snapshot_update_failed', 'snapshot_insert_failed', 'snapshot_commit_failed',
        'run_record_failed', 'filtered_scope_requires_categories', 'smart_stats_disabled', 'smart_stats_unavailable',
        'smart_stats_no_data', 'smart_stats_limit_reached', 'smart_rule_invalid', 'smart_variations_limit_reached', 'smart_no_products',
        'invalid_google_config', 'tracking_id_incompatible',
        'duplicate_feed_item_id', 'product_validation_failed', 'google_validator_api_missing', 'google_validation_failed',
        'shipping_required_for_ro', 'shipping_country_required', 'shipping_country_invalid',
        'shipping_price_invalid', 'id_missing', 'id_too_long', 'item_group_id_too_long', 'title_missing', 'title_too_long',
        'title_not_plain_text', 'description_missing', 'description_too_long', 'description_not_plain_text', 'link_missing',
        'link_invalid', 'link_https_recommended', 'image_link_missing', 'image_link_invalid', 'additional_images_invalid',
        'additional_images_too_many', 'additional_image_invalid', 'additional_image_url_too_long', 'image_too_small',
        'image_below_upcoming_minimum', 'price_missing', 'price_invalid', 'sale_price_invalid', 'sale_price_above_price',
        'sale_price_equals_price', 'currency_invalid', 'availability_missing', 'availability_invalid',
        'availability_date_required', 'availability_date_invalid', 'condition_invalid', 'identifier_exists_invalid',
        'brand_missing', 'brand_too_long', 'mpn_too_long', 'mpn_not_alphanumeric', 'identifier_missing', 'gtin_too_long',
        'gtin_not_numeric', 'gtin_invalid_length', 'gtin_invalid_checksum', 'google_product_category_missing',
        'google_product_category_format', 'product_type_too_long', 'product_type_format', 'shipping_invalid',
        'shipping_required', 'shipping_country_missing', 'shipping_price_missing', 'shipping_price_out_of_range',
        'custom_labels_invalid', 'custom_labels_too_many', 'custom_label_invalid', 'custom_label_too_long', 'weight_invalid',
        'certification_required_but_missing', 'certification_incomplete', 'unit_pricing_required_but_missing', 'unit_pricing_incomplete',
        'additional_image_format', 'additional_images_missing', 'age_group_invalid', 'availability_needs_mapping', 'category_missing', 'color_too_long',
        'condition_missing', 'currency_missing', 'custom', 'description_all_caps', 'description_contains_html', 'description_contains_links',
        'feed_generation_failed', 'gender_invalid', 'google_product_category_depth', 'gtin_format', 'gtin_length', 'image_link_format',
        'image_link_host_not_allowed', 'internal_label_invalid', 'internal_label_not_lowercase', 'internal_label_separator', 'internal_label_too_long', 'internal_labels_invalid',
        'internal_labels_too_many', 'link_domain_mismatch', 'material_too_long', 'pattern_too_long', 'price_format', 'product_type_depth',
        'sale_price_effective_date_invalid', 'sale_price_effective_date_missing', 'sale_price_not_lower', 'shipping_weight_format', 'shipping_zone_incomplete', 'shipping_zone_invalid',
        'size_too_long', 'sku_id_invalid_encoding', 'sku_id_missing', 'status_invalid', 'tax_invalid', 'title_above_recommended',
        'title_emoji', 'title_promotional', 'video_link_invalid', 'video_link_platform', 'weight_unit_missing',
        'tiktok_market_eligibility_requires_confirmation', 'meta_file_size_limit_unverified', 'id_incompatible', 'feed_output_invalid',
        'no_valid_products', 'invalid_products_excluded',
        'field_registry_unavailable', 'feed_params_invalid', 'param_enabled_invalid', 'param_field_duplicate', 'param_field_unsupported', 'param_required_missing', 'param_row_invalid', 'param_source_invalid', 'param_transform_invalid', 'param_transforms_invalid', 'param_type_mismatch', 'param_value_too_long', 'unsupported_channel', 'params_invalid'
    );

    /** Category map size and the length of one category path (Google taxonomy limit). */
    public const CATEGORY_MAP_MAX = 500;
    public const CATEGORY_MAX_LENGTH = 750;

    /** Parameter table: source types, transform operations and the native fallback list (platform specs, section 6.2). */
    public const SOURCE_TYPES = array('native', 'attribute', 'taxonomy', 'meta', 'constant', 'template', 'category_map');
    public const TRANSFORM_OPS = array('trim', 'strip_html', 'truncate', 'uppercase', 'lowercase', 'prefix', 'suffix', 'default_if_empty', 'replace', 'map', 'price_format');
    public const NATIVE_SOURCES = array('id', 'sku', 'name', 'short_description', 'description', 'permalink', 'image_url', 'gallery_urls', 'regular_price', 'sale_price', 'stock_status', 'stock_quantity', 'weight', 'categories');
    public const PARAMS_MAX = 100;
    public const TRANSFORMS_MAX = 10;
    public const PREVIEW_MAX = 10;
    public const PREVIEW_DEFAULT = 5;
    public const PREVIEW_LIMIT = 20;
    private const FIELD_KEY_RE = '/^[a-z][a-z0-9_]{0,63}$/';
    private const PARAM_KEYS = array('field', 'source', 'transforms', 'enabled');
    private const PREVIEW_KEYS = array('channel', 'format', 'config', 'params', 'n');

    /** Keys accepted at each level of the config object (strict: unknown keys are rejected). */
    private const CONFIG_KEYS = array('scope', 'categories', 'in_stock_only', 'csv_excel_safe', 'exclude_invalid', 'refresh', 'smart_rule', 'google', 'meta', 'tiktok');
    private const GOOGLE_KEYS = array('shipping_country', 'shipping_price', 'shipping_service', 'condition', 'default_category', 'category_map');
    private const META_KEYS   = array('condition', 'brand', 'internal_labels', 'default_category', 'category_map');
    private const TIKTOK_KEYS = array('condition', 'brand', 'default_category', 'category_map');

    /** Keys accepted in the create / update bodies. */
    private const CREATE_KEYS = array('name', 'channel', 'type', 'format', 'config', 'params');
    private const UPDATE_KEYS = array('name', 'status', 'config', 'params');

    /** Register the REST hook. Called from the feed module bootstrap. */
    public static function boot(): void
    {
        add_action('rest_api_init', array(self::class, 'register_routes'));
    }

    /** Register the /feeds routes. */
    public static function register_routes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            '/feeds',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => self::localized_callback(array(self::class, 'handle_list')),
                    'permission_callback' => self::localized_callback(array(self::class, 'can_manage')),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => self::localized_callback(array(self::class, 'handle_create')),
                    'permission_callback' => self::localized_callback(array(self::class, 'can_manage')),
                    'args'                => array(
                        'name'    => array('type' => 'string', 'required' => true),
                        'channel' => array('type' => 'string', 'required' => true, 'enum' => self::CHANNELS),
                        'type'    => array('type' => 'string', 'enum' => array('standard')),
                        'format'  => array('type' => 'string'),
                        'config'  => array('type' => 'object', 'required' => true),
                        'params'  => array('type' => 'array'),
                    ),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/feeds/(?P<id>\d+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => self::localized_callback(array(self::class, 'handle_get')),
                    'permission_callback' => self::localized_callback(array(self::class, 'can_manage')),
                    'args'                => array('id' => array('validate_callback' => array(self::class, 'is_valid_id'))),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => self::localized_callback(array(self::class, 'handle_update')),
                    'permission_callback' => self::localized_callback(array(self::class, 'can_manage')),
                    'args'                => array(
                        'id'     => array('validate_callback' => array(self::class, 'is_valid_id')),
                        'name'   => array('type' => 'string'),
                        'status' => array('type' => 'string', 'enum' => self::STATUSES),
                        'config' => array('type' => 'object'),
                    ),
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => self::localized_callback(array(self::class, 'handle_delete')),
                    'permission_callback' => self::localized_callback(array(self::class, 'can_manage')),
                    'args'                => array('id' => array('validate_callback' => array(self::class, 'is_valid_id'))),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/feeds/(?P<id>\d+)/regenerate',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => self::localized_callback(array(self::class, 'handle_regenerate')),
                    'permission_callback' => self::localized_callback(array(self::class, 'can_manage')),
                    'args'                => array('id' => array('validate_callback' => array(self::class, 'is_valid_id'))),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/feeds/(?P<id>\d+)/regenerate-slug',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => self::localized_callback(array(self::class, 'handle_regenerate_slug')),
                    'permission_callback' => self::localized_callback(array(self::class, 'can_manage')),
                    'args'                => array('id' => array('validate_callback' => array(self::class, 'is_valid_id'))),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/feeds/fields',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => self::localized_callback(array(self::class, 'handle_fields')),
                    'permission_callback' => self::localized_callback(array(self::class, 'can_manage')),
                    'args'                => array('channel' => array('type' => 'string', 'required' => true, 'enum' => self::CHANNELS)),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/feeds/preview',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => self::localized_callback(array(self::class, 'handle_preview')),
                    'permission_callback' => self::localized_callback(array(self::class, 'can_manage')),
                ),
            )
        );
    }

    /** Wrap a REST callback in the current administrator's locale, restoring it even on errors. */
    private static function localized_callback(callable $callback): \Closure
    {
        return static function (WP_REST_Request $request) use ($callback): mixed {
            $switched = switch_to_user_locale(get_current_user_id());
            try {
                return $callback($request);
            } finally {
                if ($switched) {
                    restore_previous_locale();
                }
            }
        };
    }

    /**
     * Permission check: manage_options AND a valid REST nonce.
     *
     * @return true|WP_Error
     */
    public static function can_manage(WP_REST_Request $request): bool|WP_Error
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'adc_feed_forbidden',
                __('Nu ai permisiunea necesară pentru feeduri.', 'adc-marketing'),
                array('status' => 403)
            );
        }

        $nonce = $request->get_header('x_wp_nonce');
        if (!is_string($nonce) || '' === $nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error(
                'adc_feed_bad_nonce',
                __('Sesiunea a expirat. Reîncarcă pagina și încearcă din nou.', 'adc-marketing'),
                array('status' => 403)
            );
        }

        return true;
    }

    /**
     * Callback-level guard: the same check as permission_callback, repeated so
     * that no handler can run unauthenticated even if the route is registered
     * differently. Returns null when the request may proceed.
     */
    private static function guard(WP_REST_Request $request): ?WP_Error
    {
        $result = self::can_manage($request);

        return true === $result ? null : $result;
    }

    /** Route argument: a positive integer id. */
    public static function is_valid_id(mixed $value): bool
    {
        return is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0;
    }

    /** GET /feeds */
    public static function handle_list(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (null !== ($denied = self::guard($request))) {
            return $denied;
        }
        unset($request);
        $store = self::service('ADC_Feed_Store');
        if ($store instanceof WP_Error) {
            return $store;
        }

        $items = array();
        foreach (array_values((array) $store::all()) as $row) {
            if (is_array($row)) {
                $items[] = self::with_last_run(self::format_feed($row), (int) ($row['id'] ?? 0));
            }
        }

        return new WP_REST_Response(array('items' => $items), 200);
    }

    /** GET /feeds/{id} */
    public static function handle_get(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (null !== ($denied = self::guard($request))) {
            return $denied;
        }
        $id = self::route_id($request);
        $store = self::service('ADC_Feed_Store');
        if ($store instanceof WP_Error) {
            return $store;
        }

        $row = $store::get($id);
        if (!is_array($row)) {
            return self::not_found();
        }

        return new WP_REST_Response(array('feed' => self::with_last_run(self::format_feed($row), $id)), 200);
    }

    /** GET /feeds/fields?channel=: the parameter registry of one destination, with its defaults. */
    public static function handle_fields(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (null !== ($denied = self::guard($request))) {
            return $denied;
        }
        $channel = (string) $request->get_param('channel');
        if (!in_array($channel, self::CHANNELS, true)) {
            return self::invalid('adc_feed_invalid_channel', __('Destinația feedului nu este validă.', 'adc-marketing'));
        }
        if (null === self::channel_info($channel)) {
            return self::channel_unavailable();
        }
        $registry = self::registry_or_error('ADC_Feed_Field_Registry', 'fields');
        if ($registry instanceof WP_Error) {
            return $registry;
        }

        $items = array();
        foreach ((array) ADC_Feed_Field_Registry::fields($channel) as $field) {
            if (is_array($field) && is_string($field['key'] ?? null) && 1 === preg_match(self::FIELD_KEY_RE, $field['key'])) {
                $items[] = self::format_field($field);
            }
        }

        $sources = class_exists('ADC_Feed_Params') && method_exists('ADC_Feed_Params', 'native_sources')
            ? ADC_Feed_Params::native_sources()
            : null;

        return new WP_REST_Response(
            array(
                'items' => $items,
                'defaults' => self::default_params($channel),
                'sources' => null === $sources ? array() : array('native' => $sources),
            ),
            200
        );
    }

    /** POST /feeds/preview: the first n products through the real generator. Nothing is stored. */
    public static function handle_preview(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (null !== ($denied = self::guard($request))) {
            return $denied;
        }
        $body = self::payload($request);
        if (null !== ($unknown = self::unknown_keys($body, self::PREVIEW_KEYS))) {
            return $unknown;
        }

        $channel = $body['channel'] ?? null;
        if (!is_string($channel) || !in_array($channel, self::CHANNELS, true)) {
            return self::invalid('adc_feed_invalid_channel', __('Destinația feedului nu este validă.', 'adc-marketing'));
        }
        $info = self::channel_info($channel);
        if (null === $info) {
            return self::channel_unavailable();
        }

        $format = $body['format'] ?? $info['formats'][0];
        if (!is_string($format) || !in_array($format, $info['formats'], true)) {
            return new WP_Error('adc_feed_format_not_allowed', __('Formatul nu este disponibil pentru această destinație.', 'adc-marketing'), array('status' => 422));
        }

        $config = self::validate_config($body['config'] ?? null, $channel, $format);
        if ($config instanceof WP_Error) {
            return $config;
        }
        $params = array_key_exists('params', $body)
            ? self::validate_params($channel, $body['params'])
            : self::default_params($channel);
        if ($params instanceof WP_Error) {
            return $params;
        }
        $params = self::ensure_smart_label($params, $config, $channel);

        $n = $body['n'] ?? self::PREVIEW_DEFAULT;
        if (!is_int($n) && !(is_string($n) && ctype_digit($n))) {
            $n = 0;
        }
        $n = (int) $n;
        if ($n < 1 || $n > self::PREVIEW_MAX) {
            return self::invalid('adc_feed_invalid_preview', __('Previzualizarea poate afișa între 1 și 10 produse.', 'adc-marketing'));
        }

        $budget = self::consume_budget('adc_feed_preview_', self::PREVIEW_LIMIT, __('Ai făcut prea multe previzualizări. Așteaptă un minut și încearcă din nou.', 'adc-marketing'));
        if ($budget instanceof WP_Error) {
            return $budget;
        }
        $generator = self::registry_or_error('ADC_Feed_Generator', 'preview');
        if ($generator instanceof WP_Error) {
            return $generator;
        }

        // The generator reads the feed config flat: the channel block sits next to the scope fields.
        $result = ADC_Feed_Generator::preview(
            array_merge($config, array('channel' => $channel, 'format' => $format, 'params' => $params)),
            $n
        );
        $result = is_array($result) ? $result : array();

        return new WP_REST_Response(
            array(
                'rows'      => self::preview_rows($result['rows'] ?? array()),
                'selection' => self::selection_rows($result['selection'] ?? array()),
                'warnings'  => self::diagnostics($result['warnings'] ?? array()),
            ),
            200
        );
    }

    /** POST /feeds: create the feed, then queue its first generation. */
    public static function handle_create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (null !== ($denied = self::guard($request))) {
            return $denied;
        }
        $data = self::validate_create(self::payload($request));
        if ($data instanceof WP_Error) {
            return $data;
        }

        $store = self::service('ADC_Feed_Store');
        if ($store instanceof WP_Error) {
            return $store;
        }

        $id = (int) $store::create($data);
        if ($id <= 0) {
            return new WP_Error(
                'adc_feed_create_failed',
                __('Feedul nu a putut fi creat. Încearcă din nou.', 'adc-marketing'),
                array('status' => 500)
            );
        }

        $stored = $store::get($id);
        $feed = self::feed_or_fallback($stored, $id);
        $refresh = is_array($stored) ? (string) ($stored['config']['refresh'] ?? 'manual') : 'manual';
        $refresh_scheduled = class_exists('ADC_Feed_Scheduler')
            && ADC_Feed_Scheduler::sync_refresh($id, 'active', $refresh);

        return new WP_REST_Response(
            array(
                'feed' => $feed,
                'queued' => self::queue_generation($id),
                'refresh_scheduled' => $refresh_scheduled,
            ),
            201
        );
    }

    /** PATCH /feeds/{id} */
    public static function handle_update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (null !== ($denied = self::guard($request))) {
            return $denied;
        }
        $id = self::route_id($request);
        $store = self::service('ADC_Feed_Store');
        if ($store instanceof WP_Error) {
            return $store;
        }
        $before = $store::get($id);
        if (!is_array($before)) {
            return self::not_found();
        }

        $changes = self::validate_update(
            self::payload($request),
            (string) ($before['channel'] ?? 'google'),
            (string) ($before['format'] ?? 'xml')
        );
        if ($changes instanceof WP_Error) {
            return $changes;
        }
        $target_config = is_array($changes['config'] ?? null)
            ? $changes['config']
            : (is_array($before['config'] ?? null) ? $before['config'] : array());
        if ('smart' === ($target_config['scope'] ?? '') && (array_key_exists('config', $changes) || array_key_exists('params', $changes))) {
            $params = is_array($changes['params'] ?? null)
                ? $changes['params']
                : (is_array($target_config['params'] ?? null) ? $target_config['params'] : self::default_params((string) $before['channel']));
            $changes['params'] = self::ensure_smart_label($params, $target_config, (string) $before['channel'], is_array($before['config'] ?? null) ? $before['config'] : array());
        }

        $status = $changes['status'] ?? null;
        unset($changes['status']);
        $final_status = null !== $status ? (string) $status : (string) ($before['status'] ?? '');
        $should_regenerate = ('active' === $final_status && (array_key_exists('config', $changes) || array_key_exists('params', $changes)))
            || ('active' === $status && 'active' !== (string) ($before['status'] ?? ''));

        if (null !== $status && !$store::set_status($id, (string) $status)) {
            return self::write_failed();
        }
        if ($changes !== array() && !$store::update($id, $changes)) {
            return self::write_failed();
        }
        if (null === $status && $changes === array()) {
            return new WP_Error(
                'adc_feed_nothing_to_update',
                __('Nu ai trimis nicio modificare.', 'adc-marketing'),
                array('status' => 400)
            );
        }

        $stored = $store::get($id);
        $stored_config = is_array($stored['config'] ?? null) ? $stored['config'] : array();
        $refresh_scheduled = class_exists('ADC_Feed_Scheduler')
            && ADC_Feed_Scheduler::sync_refresh($id, $final_status, (string) ($stored_config['refresh'] ?? 'manual'));
        $queued = $should_regenerate ? self::queue_generation($id) : false;
        return new WP_REST_Response(
            array(
                'feed' => self::feed_or_fallback($stored, $id),
                'queued' => $queued,
                'refresh_scheduled' => $refresh_scheduled,
            ),
            200
        );
    }

    /** DELETE /feeds/{id} */
    public static function handle_delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (null !== ($denied = self::guard($request))) {
            return $denied;
        }
        $id = self::route_id($request);
        $store = self::service('ADC_Feed_Store');
        if ($store instanceof WP_Error) {
            return $store;
        }
        if (!is_array($store::get($id))) {
            return self::not_found();
        }
        if (!$store::delete($id)) {
            return self::write_failed();
        }
        if (class_exists('ADC_Feed_Scheduler')) {
            ADC_Feed_Scheduler::sync_refresh($id, 'disabled', 'manual');
        }

        return new WP_REST_Response(array('deleted' => true, 'id' => $id), 200);
    }

    /** POST /feeds/{id}/regenerate: queued, never synchronous. */
    public static function handle_regenerate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (null !== ($denied = self::guard($request))) {
            return $denied;
        }
        $id = self::route_id($request);
        $store = self::service('ADC_Feed_Store');
        if ($store instanceof WP_Error) {
            return $store;
        }
        if (!is_array($store::get($id))) {
            return self::not_found();
        }

        $limited = self::consume_regen_budget();
        if ($limited instanceof WP_Error) {
            return $limited;
        }

        if (!self::queue_generation($id)) {
            return new WP_Error(
                'adc_feed_regenerate_failed',
                __('Regenerarea nu a putut fi pusă în coadă.', 'adc-marketing'),
                array('status' => 500)
            );
        }

        return new WP_REST_Response(array('queued' => true, 'id' => $id), 202);
    }

    /** POST /feeds/{id}/regenerate-slug: the old public URL stops working. */
    public static function handle_regenerate_slug(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (null !== ($denied = self::guard($request))) {
            return $denied;
        }
        $id = self::route_id($request);
        $store = self::service('ADC_Feed_Store');
        if ($store instanceof WP_Error) {
            return $store;
        }
        if (!is_array($store::get($id))) {
            return self::not_found();
        }

        $feed = $store::get($id);
        $slug = (string) $store::regenerate_slug($id);
        if ('' === $slug) {
            return self::write_failed();
        }
        $queued = is_array($feed) && 'active' === ($feed['status'] ?? '') ? self::queue_generation($id) : false;

        return new WP_REST_Response(
            array(
                'id'     => $id,
                'slug'   => $slug,
                'url'    => self::public_url($slug, (string) ($feed['format'] ?? 'xml')),
                'queued' => $queued,
            ),
            200
        );
    }

    /**
     * Validate a create body. Returns the clean store payload or WP_Error.
     *
     * @param array<string,mixed> $in Request body without underscore-prefixed keys.
     * @return array<string,mixed>|WP_Error
     */
    public static function validate_create(array $in): array|WP_Error
    {
        $unknown = self::unknown_keys($in, self::CREATE_KEYS);
        if (null !== $unknown) {
            return $unknown;
        }

        $name = self::clean_name($in['name'] ?? null);
        if ($name instanceof WP_Error) {
            return $name;
        }

        $channel = $in['channel'] ?? null;
        if (!is_string($channel) || !in_array($channel, self::CHANNELS, true)) {
            return self::invalid('adc_feed_invalid_channel', __('Destinația feedului nu este validă.', 'adc-marketing'));
        }
        $info = self::channel_info($channel);
        if (null === $info) {
            return new WP_Error(
                'adc_feed_channel_unavailable',
                __('Această destinație va fi disponibilă în curând.', 'adc-marketing'),
                array('status' => 422)
            );
        }

        $type = $in['type'] ?? 'standard';
        if ('standard' !== $type) {
            return self::invalid('adc_feed_invalid_type', __('Tipul feedului nu este valid.', 'adc-marketing'));
        }

        // The format must be one the destination supports; anything else is a forbidden combination.
        $format = $in['format'] ?? $info['formats'][0];
        if (!is_string($format) || !in_array($format, $info['formats'], true)) {
            return new WP_Error(
                'adc_feed_format_not_allowed',
                __('Formatul nu este disponibil pentru această destinație.', 'adc-marketing'),
                array('status' => 422)
            );
        }

        $config = self::validate_config($in['config'] ?? null, $channel, $format);
        if ($config instanceof WP_Error) {
            return $config;
        }

        $params = array_key_exists('params', $in)
            ? self::validate_params($channel, $in['params'])
            : self::default_params($channel);
        if ($params instanceof WP_Error) {
            return $params;
        }
        $params = self::ensure_smart_label($params, $config, $channel);

        return array(
            'name'    => $name,
            'channel' => $channel,
            'type'    => 'standard',
            'format'  => $format,
            'config'  => $config,
            'params'  => $params,
            'status'  => 'active',
        );
    }

    /**
     * Validate a PATCH body. Returns only the keys that were sent, clean.
     *
     * @param array<string,mixed> $in Request body.
     * @return array<string,mixed>|WP_Error
     */
    public static function validate_update(array $in, string $channel = 'google', string $format = 'xml'): array|WP_Error
    {
        $unknown = self::unknown_keys($in, self::UPDATE_KEYS);
        if (null !== $unknown) {
            return $unknown;
        }

        $out = array();

        if (array_key_exists('name', $in)) {
            $name = self::clean_name($in['name']);
            if ($name instanceof WP_Error) {
                return $name;
            }
            $out['name'] = $name;
        }

        if (array_key_exists('status', $in)) {
            if (!is_string($in['status']) || !in_array($in['status'], self::STATUSES, true)) {
                return self::invalid('adc_feed_invalid_status', __('Starea feedului nu este validă.', 'adc-marketing'));
            }
            $out['status'] = $in['status'];
        }

        if (array_key_exists('config', $in)) {
            $config = self::validate_config($in['config'], $channel, $format);
            if ($config instanceof WP_Error) {
                return $config;
            }
            $out['config'] = $config;
        }

        if (array_key_exists('params', $in)) {
            $params = self::validate_params($channel, $in['params']);
            if ($params instanceof WP_Error) {
                return $params;
            }
            $out['params'] = $params;
        }

        return $out;
    }

    /** Validate a bounded Smart Feed rule; unsupported combinations are rejected, never silently broadened. */
    private static function validate_smart_rule(mixed $raw): array|WP_Error
    {
        if (!is_array($raw) || null !== self::unknown_keys($raw, array('metric', 'mode', 'period_days', 'limit', 'threshold', 'conversion_percent'))) {
            return self::invalid_config();
        }
        $metric = $raw['metric'] ?? null;
        $mode = $raw['mode'] ?? null;
        $days = $raw['period_days'] ?? null;
        $limit = $raw['limit'] ?? null;
        if (!is_string($metric) || !in_array($metric, ADC_Feed_Smart::METRICS, true)
            || !is_string($mode) || !in_array($mode, ADC_Feed_Smart::MODES, true)
            || !is_int($days) || !in_array($days, ADC_Feed_Smart::PERIODS, true)
            || !is_int($limit) || $limit < 1 || $limit > ADC_Feed_Smart::MAX_LIMIT
            || ('low_conversion' === $mode && 'views' !== $metric)) {
            return self::invalid_config();
        }
        if ('min_value' === $mode) {
            $threshold = $raw['threshold'] ?? null;
            if (array_key_exists('conversion_percent', $raw) || !is_int($threshold) || $threshold < 1 || $threshold > 1000000) {
                return self::invalid_config();
            }
            return array('metric' => $metric, 'mode' => $mode, 'period_days' => $days, 'limit' => $limit, 'threshold' => $threshold);
        }
        if ('low_conversion' === $mode) {
            $conversion_percent = $raw['conversion_percent'] ?? 2;
            if (!is_int($conversion_percent) || $conversion_percent < 0 || $conversion_percent > 100 || array_key_exists('threshold', $raw)) {
                return self::invalid_config();
            }
            return array('metric' => $metric, 'mode' => $mode, 'period_days' => $days, 'limit' => $limit, 'conversion_percent' => $conversion_percent);
        }
        if (array_key_exists('threshold', $raw) || array_key_exists('conversion_percent', $raw)) {
            return self::invalid_config();
        }
        return array('metric' => $metric, 'mode' => $mode, 'period_days' => $days, 'limit' => $limit);
    }

    /** Add one editable constant parameter row for the rule label when the channel supports it. */
    private static function ensure_smart_label(array $params, array $config, string $channel, array $previous_config = array()): array
    {
        if ('smart' !== ($config['scope'] ?? '') || !class_exists('ADC_Feed_Smart') || !class_exists('ADC_Feed_Field_Registry')) {
            return $params;
        }
        $field = 'meta' === $channel ? 'internal_label' : 'custom_label_0';
        foreach (ADC_Feed_Field_Registry::fields($channel) as $definition) {
            if (($definition['key'] ?? '') !== $field) {
                continue;
            }
            foreach ($params as &$row) {
                if (is_array($row) && ($row['field'] ?? '') === $field) {
                    $previous_rule = is_array($previous_config['smart_rule'] ?? null) ? $previous_config['smart_rule'] : array();
                    $previous_label = '' !== (string) ($previous_rule['metric'] ?? '') ? ADC_Feed_Smart::label_for_rule($previous_rule) : '';
                    if ('' !== $previous_label && ($row['source']['type'] ?? '') === 'constant'
                        && ($row['source']['value'] ?? '') === $previous_label) {
                        $row['source']['value'] = ADC_Feed_Smart::label_for_rule($config['smart_rule'] ?? array());
                    }
                    unset($row);
                    return $params;
                }
            }
            unset($row);
            $params[] = array(
                'field' => $field,
                'source' => array('type' => 'constant', 'value' => ADC_Feed_Smart::label_for_rule($config['smart_rule'] ?? array())),
                'transforms' => array(),
                'enabled' => true,
            );
            break;
        }
        return $params;
    }

    /**
     * Validate the full feed config (the wizard always sends all of it).
     *
     * @param mixed $raw Raw config value.
     * @return array<string,mixed>|WP_Error
     */
    public static function validate_config(mixed $raw, string $channel = 'google', string $format = 'xml'): array|WP_Error
    {
        if (!is_array($raw)) {
            return self::invalid_config();
        }
        if (null !== self::unknown_keys($raw, self::CONFIG_KEYS)) {
            return self::invalid_config();
        }
        if (!in_array($channel, self::CHANNEL_BLOCKS, true)) {
            return self::invalid_config();
        }

        $scope = $raw['scope'] ?? null;
        if (!is_string($scope) || !in_array($scope, self::SCOPES, true)) {
            return self::invalid_config();
        }

        $categories = $raw['categories'] ?? array();
        if (!is_array($categories) || count($categories) > self::CATEGORY_MAX) {
            return self::invalid_config();
        }
        $clean_categories = array();
        foreach ($categories as $slug) {
            if (!is_string($slug) || 1 !== preg_match('/^[a-z0-9-]{1,100}$/', $slug)) {
                return self::invalid_config();
            }
            $clean_categories[$slug] = $slug;
        }
        $clean_categories = array_values($clean_categories);

        // "all" means no category filter: a non-empty list would be ambiguous.
        // "filtered" with no category is rejected rather than silently exporting everything.
        if (('all' === $scope && array() !== $clean_categories)
            || ('filtered' === $scope && array() === $clean_categories)) {
            return self::invalid_config();
        }

        $in_stock = $raw['in_stock_only'] ?? false;
        if (!is_bool($in_stock)) {
            return self::invalid_config();
        }
        $exclude_invalid = $raw['exclude_invalid'] ?? true;
        if (!is_bool($exclude_invalid)) {
            return self::invalid_config();
        }

        // CSV-only option: Excel-safe quoting. Rejected for any other format.
        $csv = $raw['csv_excel_safe'] ?? ('csv' === $format);
        if (!is_bool($csv) || ($csv && 'csv' !== $format)) {
            return self::invalid_config();
        }
        $refresh = $raw['refresh'] ?? 'manual';
        if (!is_string($refresh) || !in_array($refresh, self::REFRESHES, true)) {
            return self::invalid_config();
        }
        $smart_rule = null;
        if ('smart' === $scope) {
            $smart_rule = self::validate_smart_rule($raw['smart_rule'] ?? null);
            if ($smart_rule instanceof WP_Error) {
                return $smart_rule;
            }
        } elseif (array_key_exists('smart_rule', $raw)) {
            return self::invalid_config();
        }

        // Exactly the block of the feed's own channel: others are rejected, the own one is required.
        foreach (self::CHANNEL_BLOCKS as $block) {
            if ($block !== $channel && array_key_exists($block, $raw)) {
                return self::invalid_config();
            }
        }
        $block_value = match ($channel) {
            'google' => self::validate_google($raw['google'] ?? null),
            'meta'   => self::validate_meta($raw['meta'] ?? null),
            default  => self::validate_tiktok($raw['tiktok'] ?? null),
        };
        if ($block_value instanceof WP_Error) {
            return $block_value;
        }

        return array(
            'scope'          => $scope,
            'categories'     => $clean_categories,
            'in_stock_only'  => $in_stock,
            'csv_excel_safe' => $csv,
            'exclude_invalid' => $exclude_invalid,
            'refresh'         => $refresh,
            ...(null === $smart_rule ? array() : array('smart_rule' => $smart_rule)),
            $channel          => $block_value,
        );
    }

    /**
     * Meta block: condition, default brand and up to 5 internal labels.
     *
     * @param mixed $raw Raw meta block.
     * @return array<string,mixed>|WP_Error
     */
    private static function validate_meta(mixed $raw): array|WP_Error
    {
        if (!is_array($raw) || null !== self::unknown_keys($raw, self::META_KEYS)) {
            return self::invalid_config();
        }

        $condition = $raw['condition'] ?? null;
        if (!is_string($condition) || !in_array($condition, self::CONDITIONS, true)) {
            return self::invalid('adc_feed_invalid_condition', __('Condiția produsului nu este validă.', 'adc-marketing'));
        }

        $brand = self::clean_brand($raw['brand'] ?? null);
        if ($brand instanceof WP_Error) {
            return $brand;
        }

        $labels = self::clean_labels($raw['internal_labels'] ?? array());
        if ($labels instanceof WP_Error) {
            return $labels;
        }

        $categories = self::validate_category_fields($raw);
        if ($categories instanceof WP_Error) {
            return $categories;
        }

        return array('condition' => $condition, 'brand' => $brand, 'internal_labels' => $labels) + $categories;
    }

    /**
     * TikTok block: condition and default brand.
     *
     * @param mixed $raw Raw tiktok block.
     * @return array<string,string>|WP_Error
     */
    private static function validate_tiktok(mixed $raw): array|WP_Error
    {
        if (!is_array($raw) || null !== self::unknown_keys($raw, self::TIKTOK_KEYS)) {
            return self::invalid_config();
        }

        $condition = $raw['condition'] ?? null;
        if (!is_string($condition) || !in_array($condition, self::CONDITIONS, true)) {
            return self::invalid('adc_feed_invalid_condition', __('Condiția produsului nu este validă.', 'adc-marketing'));
        }

        $brand = self::clean_brand($raw['brand'] ?? null);
        if ($brand instanceof WP_Error) {
            return $brand;
        }

        $categories = self::validate_category_fields($raw);
        if ($categories instanceof WP_Error) {
            return $categories;
        }

        return array('condition' => $condition, 'brand' => $brand) + $categories;
    }

    /**
     * Category fields shared by the Google, Meta and TikTok blocks: a default category
     * (empty allowed) and a map term_id => platform category (empty values are dropped).
     *
     * @param array<string,mixed> $raw Raw block.
     * @return array{default_category: string, category_map: array<int, string>}|WP_Error
     */
    private static function validate_category_fields(array $raw): array|WP_Error
    {
        $default = self::clean_category($raw['default_category'] ?? '');
        if ($default instanceof WP_Error) {
            return $default;
        }

        $map_raw = $raw['category_map'] ?? array();
        if (!is_array($map_raw) || count($map_raw) > self::CATEGORY_MAP_MAX) {
            return self::invalid('adc_feed_invalid_category_map', __('Maparea categoriilor nu este validă.', 'adc-marketing'));
        }

        $map = array();
        foreach ($map_raw as $term_id => $value) {
            if (!is_int($term_id) || $term_id <= 0) {
                return self::invalid('adc_feed_invalid_category_map', __('Maparea categoriilor nu este validă.', 'adc-marketing'));
            }
            $clean = self::clean_category($value);
            if ($clean instanceof WP_Error) {
                return $clean;
            }
            if ('' !== $clean) {
                $map[$term_id] = $clean;
            }
        }

        return array('default_category' => $default, 'category_map' => $map);
    }

    /** One platform category path: sanitized text, empty allowed, at most CATEGORY_MAX_LENGTH characters. */
    private static function clean_category(mixed $raw): string|WP_Error
    {
        if (!is_string($raw)) {
            return self::invalid('adc_feed_invalid_category', __('Categoria trebuie să fie text.', 'adc-marketing'));
        }

        $text   = trim(sanitize_text_field($raw));
        $length = (int) preg_match_all('/./us', $text);
        if ($length > self::CATEGORY_MAX_LENGTH) {
            return self::invalid('adc_feed_invalid_category', __('Categoria poate avea cel mult 750 de caractere.', 'adc-marketing'));
        }

        return $text;
    }

    /** Default brand: 1..100 characters after sanitizing. */
    private static function clean_brand(mixed $raw): string|WP_Error
    {
        if (!is_string($raw)) {
            return self::invalid('adc_feed_invalid_brand', __('Marca implicită este obligatorie.', 'adc-marketing'));
        }

        $brand  = trim(sanitize_text_field($raw));
        $length = (int) preg_match_all('/./us', $brand);
        if ($length < 1 || $length > self::BRAND_MAX) {
            return self::invalid('adc_feed_invalid_brand', __('Marca trebuie să aibă între 1 și 100 de caractere.', 'adc-marketing'));
        }

        return $brand;
    }

    /**
     * Meta internal labels: a list of at most META_LABELS_MAX lowercase labels,
     * each 1..LABEL_MAX characters of letters, digits, spaces or # _ : -.
     * Duplicates are removed.
     *
     * @return array<int,string>|WP_Error
     */
    private static function clean_labels(mixed $raw): array|WP_Error
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::META_LABELS_MAX) {
            return self::invalid('adc_feed_invalid_labels', __('Etichetele interne trebuie să fie o listă de cel mult 5 valori.', 'adc-marketing'));
        }

        $out = array();
        foreach ($raw as $label) {
            $clean  = self::lowercase(trim(sanitize_text_field(is_string($label) ? $label : '')));
            $length = (int) preg_match_all('/./us', $clean);
            // Uppercase left after lowercasing (no mbstring) means the label is not stored lowercase.
            if ($length < 1 || $length > self::LABEL_MAX
                || 1 !== preg_match('/^[\p{L}\p{N}#_:\- ]+$/u', $clean)
                || 1 === preg_match('/\p{Lu}/u', $clean)) {
                return self::invalid('adc_feed_invalid_label', __('Eticheta internă nu este validă: 1 până la 110 de caractere, litere, cifre, spații sau # _ : -.', 'adc-marketing'));
            }
            $out[$clean] = $clean;
        }

        return array_values($out);
    }

    /** Lowercase with mbstring when present; ASCII-only otherwise. */
    private static function lowercase(string $text): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }

    /**
     * Channel descriptor from the registry (ADC_Feed_Channels::get), or null when the
     * destination is not available. Without the registry only Google with XML is offered.
     *
     * @return array{label:string,formats:array<int,string>}|null
     */
    public static function channel_info(string $slug): ?array
    {
        if (class_exists('ADC_Feed_Channels') && method_exists('ADC_Feed_Channels', 'get')) {
            return self::normalize_channel(ADC_Feed_Channels::get($slug));
        }

        return self::available_channels()[$slug] ?? null;
    }

    /**
     * Every available channel from the registry (ADC_Feed_Channels::all), keyed by slug.
     *
     * @return array<string,array{label:string,formats:array<int,string>}>
     */
    public static function available_channels(): array
    {
        if (!class_exists('ADC_Feed_Channels') || !method_exists('ADC_Feed_Channels', 'all')) {
            return array('google' => array('label' => 'Google Shopping', 'formats' => array('xml')));
        }

        $out = array();
        foreach ((array) ADC_Feed_Channels::all() as $slug => $info) {
            $clean = self::normalize_channel($info);
            if (is_string($slug) && null !== $clean && in_array($slug, self::CHANNELS, true)) {
                $out[$slug] = $clean;
            }
        }

        return $out;
    }

    /**
     * Keeps label and formats (plain identifiers) of one registry entry; null when unusable.
     *
     * @return array{label:string,formats:array<int,string>}|null
     */
    private static function normalize_channel(mixed $info): ?array
    {
        if (!is_array($info)) {
            return null;
        }

        $formats = array_values(array_filter(
            is_array($info['formats'] ?? null) ? $info['formats'] : array(),
            static fn ($format): bool => is_string($format) && 1 === preg_match('/^[a-z0-9]{1,10}$/', $format)
        ));
        if ($formats === array()) {
            return null;
        }

        return array(
            'label'   => is_string($info['label'] ?? null) ? $info['label'] : '',
            'formats' => $formats,
        );
    }

    /**
     * Validate the Google-specific block: shipping (country, price, service) and default condition.
     *
     * @param mixed $raw Raw google block.
     * @return array<string,string>|WP_Error
     */
    private static function validate_google(mixed $raw): array|WP_Error
    {
        if (!is_array($raw) || null !== self::unknown_keys($raw, self::GOOGLE_KEYS)) {
            return self::invalid_config();
        }

        $country = $raw['shipping_country'] ?? null;
        if (!is_string($country) || 1 !== preg_match('/^[A-Z]{2}$/', $country)) {
            return self::invalid('adc_feed_invalid_country', __('Codul țării de livrare nu este valid (ex. RO).', 'adc-marketing'));
        }

        $price = self::normalize_price($raw['shipping_price'] ?? null);
        if (null === $price) {
            return self::invalid('adc_feed_invalid_price', __('Prețul de livrare nu este valid (ex. 15.00).', 'adc-marketing'));
        }

        $service = self::clean_name($raw['shipping_service'] ?? null);
        if ($service instanceof WP_Error) {
            return self::invalid('adc_feed_invalid_service', __('Serviciul de livrare nu este valid.', 'adc-marketing'));
        }

        $condition = $raw['condition'] ?? null;
        if (!is_string($condition) || !in_array($condition, self::CONDITIONS, true)) {
            return self::invalid('adc_feed_invalid_condition', __('Condiția produsului nu este validă.', 'adc-marketing'));
        }

        $categories = self::validate_category_fields($raw);
        if ($categories instanceof WP_Error) {
            return $categories;
        }

        return array(
            'shipping_country' => $country,
            'shipping_price'   => $price,
            'shipping_service' => $service,
            'condition'        => $condition,
        ) + $categories;
    }

    /**
     * Price as a plain decimal string with at most two decimals, or null when invalid.
     *
     * @param mixed $raw Number or string.
     */
    private static function normalize_price(mixed $raw): ?string
    {
        if (is_int($raw) || is_float($raw)) {
            $raw = number_format((float) $raw, 2, '.', '');
        }
        if (!is_string($raw)) {
            return null;
        }

        $raw = trim($raw);
        if (1 !== preg_match('/^(0|[1-9][0-9]{0,5})(\.[0-9]{1,2})?$/', $raw)) {
            return null;
        }

        return $raw;
    }

    /**
     * Sanitized one-line name (1..120 characters).
     *
     * @param mixed $raw Raw value.
     * @return string|WP_Error
     */
    private static function clean_name(mixed $raw): string|WP_Error
    {
        if (!is_string($raw)) {
            return self::invalid('adc_feed_invalid_name', __('Numele feedului este obligatoriu.', 'adc-marketing'));
        }

        $name = trim(sanitize_text_field($raw));
        // Count code points without relying on mbstring (not guaranteed on every host).
        $length = (int) preg_match_all('/./us', $name);
        if ($length < 1 || $length > 120) {
            return self::invalid('adc_feed_invalid_name', __('Numele feedului trebuie să aibă între 1 și 120 de caractere.', 'adc-marketing'));
        }

        return $name;
    }

    /**
     * Whitelisted public view of a stored feed row. No paths, no internal fields.
     *
     * @param array<string,mixed> $row Stored row.
     * @return array<string,mixed>
     */
    public static function format_feed(array $row): array
    {
        $slug    = (string) ($row['slug'] ?? '');
        $channel = (string) ($row['channel'] ?? '');
        $format  = '' !== (string) ($row['format'] ?? '') ? (string) $row['format'] : 'xml';
        $config = $row['config'] ?? array();
        $config = is_array($config) ? $config : array();
        // Store hydration keeps mappings under config.params; older rows may expose
        // them separately. Validate the delivery config without this internal key.
        $params = self::stored_params(array('params' => $config['params'] ?? ($row['params'] ?? array())));
        unset($config['params']);
        $clean = self::validate_config($config, $channel, $format);
        $public_config = $clean instanceof WP_Error ? self::safe_stored_config($config, $channel) : $clean;
        $public_config['params'] = $params;

        return array(
            'id'         => (int) ($row['id'] ?? 0),
            'slug'       => $slug,
            'name'       => (string) ($row['name'] ?? ''),
            'type'       => (string) ($row['type'] ?? ''),
            'channel'    => $channel,
            'format'     => $format,
            'status'     => (string) ($row['status'] ?? ''),
            'config'     => $public_config,
            'params'     => $params,
            'url'        => '' === $slug ? '' : self::public_url($slug, $format),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        );
    }

    /**
     * Adds the latest run summary (or null) to a formatted feed.
     *
     * @param array<string,mixed> $feed Formatted feed.
     * @param int                 $id   Feed id.
     * @return array<string,mixed>
     */
    private static function with_last_run(array $feed, int $id): array
    {
        $feed['last_run'] = self::last_run($id);

        return $feed;
    }

    /**
     * Latest run of a feed, whitelisted. Null when there is none or the store
     * does not provide last_run() yet.
     *
     * @return array<string,mixed>|null
     */
    private static function last_run(int $id): ?array
    {
        if (!class_exists('ADC_Feed_Store') || !method_exists('ADC_Feed_Store', 'last_run')) {
            return null;
        }

        $run = ADC_Feed_Store::last_run($id);

        return is_array($run) ? self::format_run($run) : null;
    }

    /**
     * Whitelisted view of one run: status, timestamps, counters and capped message lists.
     * No paths, no traces, no internal fields.
     *
     * @param array<string,mixed> $run Stored run summary.
     * @return array<string,mixed>
     */
    public static function format_run(array $run): array
    {
        return array(
            'status'      => self::clip((string) ($run['status'] ?? ''), 32),
            'started_at'  => self::clip((string) ($run['started_at'] ?? ''), 40),
            'finished_at' => self::clip((string) ($run['finished_at'] ?? ''), 40),
            'examined'    => max(0, (int) ($run['examined'] ?? 0)),
            'exported'    => max(0, (int) ($run['exported'] ?? 0)),
            'errors'      => self::diagnostics($run['errors'] ?? array()),
            'warnings'    => self::diagnostics($run['warnings'] ?? array()),
        );
    }

    /**
     * Diagnostics as {code, count} rows, one row per code, capped at RUN_MESSAGE_MAX.
     * A plain string counts as one occurrence of that code. Anything that is not a
     * code (sentences, paths, markup) is dropped, so no text from the run reaches the client.
     *
     * @param mixed $raw Stored list.
     * @return array<int, array{code: string, count: int}>
     */
    private static function diagnostics(mixed $raw): array
    {
        if (!is_array($raw)) {
            return array();
        }

        $known  = self::known_diagnostic_codes();
        $counts = array();
        foreach ($raw as $item) {
            $candidate = is_array($item) ? ($item['code'] ?? null) : $item;
            $code = is_string($candidate) && in_array($candidate, $known, true)
                ? $candidate
                : 'unknown_diagnostic';
            $count = is_array($item) ? (int) ($item['count'] ?? 1) : 1;
            $counts[$code] = ($counts[$code] ?? 0) + max(1, $count);
        }

        $out = array();
        foreach ($counts as $code => $count) {
            $out[] = array('code' => (string) $code, 'count' => $count);
            if (count($out) >= self::RUN_MESSAGE_MAX) {
                break;
            }
        }

        return $out;
    }

    /**
     * Parameter rows of a channel from ADC_Feed_Params::defaults_for(), structurally clean. [] when unavailable.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function default_params(string $channel): array
    {
        if (!class_exists('ADC_Feed_Params') || !method_exists('ADC_Feed_Params', 'defaults_for')) {
            return array();
        }
        $clean = self::structural_params(ADC_Feed_Params::defaults_for($channel));

        return $clean instanceof WP_Error ? array() : $clean;
    }

    /**
     * Parameter rows from a request: structure checked here, then ADC_Feed_Params::validate() when loaded.
     * The 422 carries the error codes only.
     *
     * @return array<int, array<string, mixed>>|WP_Error
     */
    private static function validate_params(string $channel, mixed $raw): array|WP_Error
    {
        $clean = self::structural_params($raw);
        if ($clean instanceof WP_Error) {
            return $clean;
        }

        if (class_exists('ADC_Feed_Params') && method_exists('ADC_Feed_Params', 'validate')) {
            // The validator answers with a list of error rows; an empty list means the params are valid.
            $errors = ADC_Feed_Params::validate($channel, $clean);
            if (is_array($errors) && $errors !== array()) {
                $codes = array_map(static fn ($error): mixed => is_array($error) ? ($error['code'] ?? null) : $error, $errors);

                return new WP_Error(
                    'adc_feed_invalid_params',
                    __('Parametrii feedului nu sunt valizi.', 'adc-marketing'),
                    array('status' => 422, 'errors' => array_column(self::diagnostics($codes), 'code'))
                );
            }
        }

        return $clean;
    }

    /** Keep only known config keys when legacy storage fails current validation. */
    private static function safe_stored_config(array $config, string $channel): array
    {
        $safe = array_intersect_key($config, array_flip(self::CONFIG_KEYS));
        $block_keys = match ($channel) {
            'google' => self::GOOGLE_KEYS,
            'meta' => self::META_KEYS,
            'tiktok' => self::TIKTOK_KEYS,
            default => array(),
        };
        foreach (self::CHANNEL_BLOCKS as $block) {
            if ($block !== $channel) {
                unset($safe[$block]);
            }
        }
        if (isset($safe[$channel]) && is_array($safe[$channel])) {
            $safe[$channel] = array_intersect_key($safe[$channel], array_flip($block_keys));
        }

        return $safe;
    }

    /** Stored parameter rows, re-checked structurally on read. [] when the row has none. */
    private static function stored_params(array $row): array
    {
        $clean = self::structural_params($row['params'] ?? array());

        return $clean instanceof WP_Error ? array() : $clean;
    }

    /**
     * Structural check of a parameter list: at most PARAMS_MAX rows, one row per field.
     *
     * @return array<int, array<string, mixed>>|WP_Error
     */
    private static function structural_params(mixed $raw): array|WP_Error
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::PARAMS_MAX) {
            return self::invalid('adc_feed_invalid_params', __('Lista de parametri nu este validă.', 'adc-marketing'));
        }

        $out  = array();
        $seen = array();
        foreach ($raw as $row) {
            $clean = self::clean_param_row($row);
            if ($clean instanceof WP_Error) {
                return $clean;
            }
            if (isset($seen[$clean['field']])) {
                return self::invalid('adc_feed_duplicate_param', __('Același parametru apare de două ori.', 'adc-marketing'));
            }
            $seen[$clean['field']] = true;
            $out[] = $clean;
        }

        return $out;
    }

    /** One parameter row: field key, source, transforms, enabled. Unknown keys are rejected. */
    private static function clean_param_row(mixed $raw): array|WP_Error
    {
        if (!is_array($raw) || null !== self::unknown_keys($raw, self::PARAM_KEYS)) {
            return self::invalid('adc_feed_invalid_param', __('Un parametru nu are o structură validă.', 'adc-marketing'));
        }

        $field = $raw['field'] ?? null;
        if (!is_string($field) || 1 !== preg_match(self::FIELD_KEY_RE, $field)) {
            return self::invalid('adc_feed_invalid_param', __('Numele parametrului nu este valid.', 'adc-marketing'));
        }

        $source = self::clean_source($raw['source'] ?? null);
        if ($source instanceof WP_Error) {
            return $source;
        }

        $transforms_raw = $raw['transforms'] ?? array();
        if (!is_array($transforms_raw) || !array_is_list($transforms_raw) || count($transforms_raw) > self::TRANSFORMS_MAX) {
            return self::invalid('adc_feed_invalid_param', __('Transformările parametrului nu sunt valide.', 'adc-marketing'));
        }
        $transforms = array();
        foreach ($transforms_raw as $transform) {
            $clean = self::clean_transform($transform);
            if ($clean instanceof WP_Error) {
                return $clean;
            }
            $transforms[] = $clean;
        }

        $enabled = $raw['enabled'] ?? true;
        if (!is_bool($enabled)) {
            return self::invalid('adc_feed_invalid_param', __('Un parametru nu are o stare validă.', 'adc-marketing'));
        }

        return array('field' => $field, 'source' => $source, 'transforms' => $transforms, 'enabled' => $enabled);
    }

    /** Source {type, value}: type from SOURCE_TYPES, value short text (may be empty for category_map). */
    private static function clean_source(mixed $raw): array|WP_Error
    {
        if (!is_array($raw) || null !== self::unknown_keys($raw, array('type', 'value'))) {
            return self::invalid('adc_feed_invalid_param', __('Sursa parametrului nu este validă.', 'adc-marketing'));
        }

        $type = $raw['type'] ?? null;
        // The field registry names native product fields 'product'; the parameter table calls them 'native'.
        if ('product' === $type) {
            $type = 'native';
        }
        if (!is_string($type) || !in_array($type, self::SOURCE_TYPES, true)) {
            return self::invalid('adc_feed_invalid_param', __('Tipul sursei nu este valid.', 'adc-marketing'));
        }

        $value = $raw['value'] ?? '';
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return self::invalid('adc_feed_invalid_param', __('Valoarea sursei nu este validă.', 'adc-marketing'));
        }

        return array('type' => $type, 'value' => self::clip(trim(sanitize_text_field($value)), 300));
    }

    /** Transform {op, arg}: op from TRANSFORM_OPS, arg short text. */
    private static function clean_transform(mixed $raw): array|WP_Error
    {
        if (!is_array($raw) || null !== self::unknown_keys($raw, array('op', 'arg'))) {
            return self::invalid('adc_feed_invalid_param', __('Transformarea nu este validă.', 'adc-marketing'));
        }

        $op = $raw['op'] ?? null;
        if (!is_string($op) || !in_array($op, self::TRANSFORM_OPS, true)) {
            return self::invalid('adc_feed_invalid_param', __('Operația de transformare nu este validă.', 'adc-marketing'));
        }

        $arg = $raw['arg'] ?? '';
        if (is_int($arg)) {
            $arg = (string) $arg;
        }
        if (!is_string($arg)) {
            return self::invalid('adc_feed_invalid_param', __('Argumentul transformării nu este valid.', 'adc-marketing'));
        }

        return array('op' => $op, 'arg' => self::clip($arg, 200));
    }

    /**
     * One field of the registry, whitelisted: key, label, required, type, enum, max_length, help, default_source, repeatable.
     *
     * @param array<string,mixed> $field Registry entry.
     * @return array<string,mixed>
     */
    private static function format_field(array $field): array
    {
        $enum = array();
        foreach (array_slice(is_array($field['enum'] ?? null) ? $field['enum'] : array(), 0, 50) as $value) {
            if (is_string($value) && '' !== trim($value)) {
                $enum[] = self::clip(trim($value), 60);
            }
        }

        $default = null;
        if (is_array($field['default_source'] ?? null)) {
            $source = self::clean_source($field['default_source']);
            $default = $source instanceof WP_Error ? null : $source;
        }

        return array(
            'key'            => (string) $field['key'],
            'label'          => self::clip((string) ($field['label'] ?? ''), 120),
            'required'       => (bool) ($field['required'] ?? false),
            'type'           => self::clip((string) ($field['type'] ?? ''), 32),
            'enum'           => $enum,
            'max_length'     => max(0, (int) ($field['max_length'] ?? 0)),
            'help'           => self::clip((string) ($field['help'] ?? ''), 300),
            'default_source' => $default,
            'repeatable'     => (bool) ($field['repeatable'] ?? false),
        );
    }

    /**
     * Preview rows: at most PREVIEW_MAX rows, 50 cells each, keys as identifiers, values as short text.
     *
     * @return array<int, array<string, string>>
     */
    private static function selection_rows(mixed $raw): array
    {
        if (!is_array($raw)) {
            return array();
        }
        $rows = array();
        foreach (array_slice($raw, 0, self::PREVIEW_MAX) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['product_id'] ?? 0);
            $metric = (string) ($row['metric'] ?? '');
            $value = (int) ($row['value'] ?? 0);
            if ($id > 0 && in_array($metric, ADC_Feed_Smart::METRICS, true) && $value > 0) {
                $rows[] = array('product_id' => $id, 'metric' => $metric, 'value' => $value);
            }
        }
        return $rows;
    }

    private static function preview_rows(mixed $raw): array
    {
        if (!is_array($raw)) {
            return array();
        }

        $rows = array();
        foreach (array_slice(array_values($raw), 0, self::PREVIEW_MAX) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = array();
            foreach (array_slice($row, 0, 50, true) as $key => $value) {
                if (!is_string($key) || 1 !== preg_match('/^[a-z0-9_]{1,64}$/', $key)) {
                    continue;
                }
                if (is_scalar($value) || null === $value) {
                    $cells[$key] = self::clip((string) $value, 300);
                }
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /** Registry class and method, or 503 when they are not loaded. */
    private static function registry_or_error(string $class, string $method): string|WP_Error
    {
        if (class_exists($class) && method_exists($class, $method)) {
            return $class;
        }

        return new WP_Error('adc_feeds_unavailable', __('Motorul de feeduri nu este disponibil.', 'adc-marketing'), array('status' => 503));
    }

    private static function channel_unavailable(): WP_Error
    {
        return new WP_Error('adc_feed_channel_unavailable', __('Această destinație va fi disponibilă în curând.', 'adc-marketing'), array('status' => 422));
    }

    /**
     * Diagnostic codes the API passes through: the static list plus ADC_Feed_Generator::diagnostic_codes()
     * when the generator provides it.
     *
     * @return array<int, string>
     */
    private static function known_diagnostic_codes(): array
    {
        $codes = self::DIAGNOSTIC_CODES;
        if (class_exists('ADC_Feed_Generator') && method_exists('ADC_Feed_Generator', 'diagnostic_codes')) {
            $codes = array_merge($codes, (array) ADC_Feed_Generator::diagnostic_codes());
        }

        return array_values(array_unique(array_filter($codes, 'is_string')));
    }

    /** First $max characters (code points, valid UTF-8 kept). */
    private static function clip(string $text, int $max): string
    {
        return preg_match('/\A.{0,' . $max . '}/us', $text, $m) === 1 ? $m[0] : '';
    }

    /**
     * Formatted row, or a minimal view built from the id when the re-read fails.
     *
     * @param mixed $row Value returned by the store's get().
     * @return array<string,mixed>
     */
    private static function feed_or_fallback(mixed $row, int $id): array
    {
        return is_array($row) ? self::format_feed($row) : array('id' => $id);
    }

    /** Public feed URL for a slug, with the extension of the feed's format. */
    public static function public_url(string $slug, string $format = 'xml'): string
    {
        return home_url('/adc-feed/' . $slug . '.' . $format);
    }

    /**
     * Consume one unit of the manual regeneration budget for the current user.
     *
     * @return true|WP_Error
     */
    private static function consume_regen_budget(): bool|WP_Error
    {
        return self::consume_budget('adc_feed_regen_', self::REGEN_MAX, __('Ai făcut prea multe regenerări. Așteaptă un minut și încearcă din nou.', 'adc-marketing'));
    }

    /**
     * One unit of a per-user budget: at most $max calls per REGEN_WINDOW seconds under $prefix.
     *
     * @return true|WP_Error
     */
    private static function consume_budget(string $prefix, int $max, string $message): bool|WP_Error
    {
        $key   = $prefix . (string) get_current_user_id();
        $state = get_transient($key);
        $now   = time();

        if (!is_array($state) || ($now - (int) ($state['started'] ?? 0)) >= self::REGEN_WINDOW) {
            $state = array('count' => 0, 'started' => $now);
        }

        if ((int) $state['count'] >= $max) {
            return new WP_Error('adc_feed_rate_limited', $message, array('status' => 429));
        }

        $state['count'] = (int) $state['count'] + 1;
        set_transient($key, $state, self::REGEN_WINDOW);

        return true;
    }

    /** Queue generation through ADC_Feed_Generator when it is loaded. */
    private static function queue_generation(int $id): bool
    {
        $generator = self::service('ADC_Feed_Generator');
        if ($generator instanceof WP_Error) {
            return false;
        }

        return (bool) $generator::request($id);
    }

    /**
     * Class name of a feed service, or 503 when the service is not loaded.
     *
     * @return string|WP_Error
     */
    private static function service(string $class): string|WP_Error
    {
        if (class_exists($class)) {
            return $class;
        }

        return new WP_Error(
            'adc_feeds_unavailable',
            __('Motorul de feeduri nu este disponibil.', 'adc-marketing'),
            array('status' => 503)
        );
    }

    /** Route id from the URL, as int. */
    private static function route_id(WP_REST_Request $request): int
    {
        return (int) $request->get_param('id');
    }

    /**
     * Body parameters without underscore-prefixed transport keys (_wpnonce, _locale).
     *
     * @return array<string,mixed>
     */
    private static function payload(WP_REST_Request $request): array
    {
        $params = (array) $request->get_params();
        unset($params['id']);

        return array_filter(
            $params,
            static fn ($key): bool => !str_starts_with((string) $key, '_'),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Error for the first key outside the allowed list, or null.
     *
     * @param array<string,mixed> $in      Input.
     * @param array<int,string>   $allowed Allowed keys.
     */
    private static function unknown_keys(array $in, array $allowed): ?WP_Error
    {
        foreach (array_keys($in) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                return self::invalid('adc_feed_unknown_field', __('Câmp necunoscut în cerere.', 'adc-marketing'));
            }
        }

        return null;
    }

    private static function invalid(string $code, string $message): WP_Error
    {
        return new WP_Error($code, $message, array('status' => 400));
    }

    private static function invalid_config(): WP_Error
    {
        return self::invalid('adc_feed_invalid_config', __('Configurația feedului nu este validă.', 'adc-marketing'));
    }

    private static function not_found(): WP_Error
    {
        return new WP_Error('adc_feed_not_found', __('Feedul nu există.', 'adc-marketing'), array('status' => 404));
    }

    private static function write_failed(): WP_Error
    {
        return new WP_Error('adc_feed_write_failed', __('Modificarea nu a putut fi salvată.', 'adc-marketing'), array('status' => 500));
    }
}
