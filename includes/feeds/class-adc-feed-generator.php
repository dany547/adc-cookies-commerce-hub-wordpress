<?php
/** Queued, atomic Google feed generation. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Builds complete feed snapshots off-request and publishes only valid output. */
final class ADC_Feed_Generator
{
    private const DEBOUNCE_SECONDS = 30;
    private const LOCK_SECONDS = 7200;
    private const MAX_RETRY_SECONDS = 3600;
    private const MAX_RETRY_ATTEMPTS = 7;
    private const MAX_DIAGNOSTIC_CODES = 100;

    /** Diagnostic identifiers emitted directly by generator control flow. */
    public static function diagnostic_codes(): array
    {
        return array(
            'invalid_feed_id', 'feed_not_found', 'feed_disabled', 'unsupported_feed_profile',
            'tiktok_market_eligibility_requires_confirmation', 'meta_file_size_limit_unverified',
            'feed_profile_unavailable', 'feed_busy', 'feed_busy_retry_scheduled',
            'feed_busy_retry_exhausted', 'feed_busy_retry_failed', 'feed_busy_retry_pending',
            'feed_dirty_retry_exhausted', 'feed_dirty_retry_failed', 'tracking_id_incompatible',
            'id_incompatible', 'duplicate_feed_item_id', 'feed_id_index_failed',
            'filtered_scope_requires_categories', 'invalid_google_config', 'feed_storage_unavailable',
            'feed_file_open_failed', 'feed_write_failed', 'feed_lock_lost', 'product_validation_failed',
            'feed_close_failed', 'feed_output_invalid', 'feed_publish_failed', 'no_valid_products',
            'invalid_products_excluded', 'snapshot_record_failed',
            'run_record_failed', 'feed_generation_failed', 'field_registry_unavailable', 'params_invalid',
            'feed_params_invalid', 'param_type_mismatch', 'param_value_too_long', 'param_source_invalid',
            'param_transform_invalid', 'preview_warning', 'unknown_diagnostic',
        );
    }

    /** Preview a bounded catalog sample without writing or publishing a snapshot. */
    public static function preview(array $config, int $n = 5): array
    {
        $channel = (string) ($config['channel'] ?? 'google');
        $n = max(1, min(50, $n));
        if (!class_exists('ADC_Feed_Field_Registry')) {
            return array('rows' => array(), 'warnings' => array('field_registry_unavailable'));
        }
        $params = is_array($config['params'] ?? null) ? $config['params'] : ADC_Feed_Params::defaults_for($channel);
        $config['params'] = $params;
        $rows = array();
        $warnings = array();
        $errors = ADC_Feed_Params::validate($channel, $params);
        if (!empty($errors)) {
            return array('rows' => array(), 'warnings' => array('params_invalid'));
        }
        $channel_spec = ADC_Feed_Channels::get($channel);
        if (null === $channel_spec || !class_exists($channel_spec['profile'])) {
            return array('rows' => array(), 'warnings' => array('feed_profile_unavailable'));
        }
        $catalog_config = self::profile_config($config);
        if (method_exists('ADC_Feed_Catalog', 'reset_parent_cache')) {
            ADC_Feed_Catalog::reset_parent_cache();
        }
        $items = ADC_Feed_Catalog::items($catalog_config, 0, $n);
        foreach ($items as $item) {
            $item = ADC_Feed_Params::apply($item, $params, $channel);
            $warnings = array_merge($warnings, (array) ($item['param_warnings'] ?? array()));
            $profile = $channel_spec['profile'];
            $mapped = $profile::map_item($item, self::profile_config($config));
            $validator = (string) ($channel_spec['validator'] ?? '');
            if ('' !== $validator && class_exists($validator)) {
                $validation_item = 'google' === $channel ? $item : array_merge($item, $mapped);
                $validation_item = array_merge($validation_item, is_array($item['extra'] ?? null) ? $item['extra'] : array());
                $validation = self::validate_item($validation_item, $config, $validator);
                $warnings = array_merge($warnings, (array) ($validation['warnings'] ?? array()), (array) ($validation['errors'] ?? array()));
            }
            $rows[] = array_diff_key($mapped, array('_required_params' => true, 'extra' => true));
        }
        return array('rows' => $rows, 'warnings' => array_values(array_unique($warnings)));
    }

    /** Mark a feed dirty and schedule a debounced background generation. */
    public static function request(int $feed_id): bool
    {
        if ($feed_id <= 0) {
            return false;
        }
        $feed = ADC_Feed_Store::get($feed_id);
        if (null === $feed || 'disabled' === ($feed['status'] ?? '')) {
            return false;
        }

        $dirty_key = self::dirty_key($feed_id);
        set_transient($dirty_key, 1, DAY_IN_SECONDS);
        $scheduled_key = self::request_key($feed_id);
        if (get_transient($scheduled_key)) {
            return true;
        }
        set_transient($scheduled_key, 1, self::DEBOUNCE_SECONDS + 10);

        $when = time() + self::DEBOUNCE_SECONDS;
        if (function_exists('as_schedule_single_action')) {
            $action_id = as_schedule_single_action(
                $when,
                ADC_Feed_Scheduler::JOB_HOOK,
                array($feed_id),
                ADC_Feed_Module::AS_GROUP,
                true
            );
            if (is_numeric($action_id) && (int) $action_id > 0) {
                return true;
            }
            // A unique action already queued is a successful debounce outcome.
            if (self::has_pending_action($feed_id)) {
                return true;
            }
        }

        if (function_exists('wp_schedule_single_event')) {
            $result = wp_schedule_single_event($when, ADC_Feed_Scheduler::JOB_HOOK, array($feed_id));
            if (!function_exists('is_wp_error') || !is_wp_error($result)) {
                if (false !== $result) {
                    return true;
                }
                if (function_exists('wp_next_scheduled') && wp_next_scheduled(ADC_Feed_Scheduler::JOB_HOOK, array($feed_id))) {
                    return true;
                }
            }
        }

        delete_transient($scheduled_key);
        return false;
    }

    /**
     * Generate and atomically publish one Google RSS snapshot.
     *
     * @return array{ok:bool,exported:int,examined:int,errors:array,warnings:array}
     */
    public static function run(int $feed_id): array
    {
        $result = array('ok' => false, 'exported' => 0, 'examined' => 0, 'errors' => array(), 'warnings' => array());
        if ($feed_id <= 0) {
            self::add_diagnostic($result, 'errors', 'invalid_feed_id');
            return $result;
        }

        $feed = ADC_Feed_Store::get($feed_id);
        if (null === $feed) {
            self::add_diagnostic($result, 'errors', 'feed_not_found');
            return $result;
        }
        if ('active' !== ($feed['status'] ?? '')) {
            self::add_diagnostic($result, 'errors', 'feed_disabled');
            return $result;
        }
        $channel = (string) ($feed['channel'] ?? '');
        $format = (string) ($feed['format'] ?? '');
        $channel_spec = ADC_Feed_Channels::get($channel);
        if (null === $channel_spec || !in_array($format, $channel_spec['formats'], true)
            && !('google' === $channel && 'rss' === $format)) {
            self::add_diagnostic($result, 'errors', 'unsupported_feed_profile');
            return $result;
        }
        $profile_class = $channel_spec['profile'];
        $validator_class = $channel_spec['validator'];
        if ('tiktok' === $channel) {
            self::add_diagnostic($result, 'warnings', 'tiktok_market_eligibility_requires_confirmation');
        }
        if ('meta' === $channel) {
            self::add_diagnostic($result, 'warnings', 'meta_file_size_limit_unverified');
        }
        if (!class_exists($profile_class) || !class_exists($validator_class)) {
            self::add_diagnostic($result, 'errors', 'feed_profile_unavailable');
            return $result;
        }

        $lock_key = self::lock_key($feed_id);
        delete_transient(self::request_key($feed_id));
        $lock_token = self::acquire_lock($feed_id);
        if ('' === $lock_token) {
            self::add_diagnostic($result, 'errors', 'feed_busy');
            set_transient(self::dirty_key($feed_id), 1, DAY_IN_SECONDS);
            $retry_state = self::schedule_retry($feed_id);
            if ('scheduled' !== $retry_state) {
                self::add_diagnostic($result, 'errors', 'feed_busy_retry_' . $retry_state);
            }
            self::record_busy_run($feed_id, $result);
            return $result;
        }
        delete_transient(self::dirty_key($feed_id));

        $run_id = 0;
        $temp_path = '';
        $published_path = '';
        $writer = null;
        $id_table = '';
        $checksum = '';
        $retry_error = '';
        $invalid_excluded = 0;
        $wpdb = $GLOBALS['wpdb'] ?? null;
        $runs_table = ADC_Feed_Schema::table(ADC_Feed_Schema::RUNS);
        $snapshots_table = ADC_Feed_Schema::table(ADC_Feed_Schema::SNAPSHOTS);
        $started_at = current_time('mysql', true);

        try {
            $inserted = $wpdb->insert(
                $runs_table,
                array(
                    'feed_id'            => $feed_id,
                    'started_at'         => $started_at,
                    'finished_at'        => null,
                    'status'             => 'running',
                    'products_examined'  => 0,
                    'products_exported'  => 0,
                    'error_summary'      => '',
                    'warning_summary'    => '',
                    'snapshot_hash'      => '',
                ),
                array('%d', '%s', null, '%s', '%d', '%d', '%s', '%s', '%s')
            );
            if (false === $inserted) {
                throw new RuntimeException('run_record_failed');
            }
            $run_id = (int) $wpdb->insert_id;

            $config = is_array($feed['config'] ?? null) ? $feed['config'] : array();
            $params = array();
            if (class_exists('ADC_Feed_Field_Registry')) {
                $params = is_array($config['params'] ?? null) && !empty($config['params'])
                    ? $config['params']
                    : ADC_Feed_Params::defaults_for($channel);
                $param_errors = ADC_Feed_Params::validate($channel, $params);
                if (!empty($param_errors)) {
                    throw new RuntimeException('feed_params_invalid');
                }
                $config['params'] = $params;
            }
            $config = self::profile_config($config);
            if ('filtered' === ($config['scope'] ?? '') && empty($config['categories'])) {
                throw new RuntimeException('filtered_scope_requires_categories');
            }
            if ('google' === $channel && method_exists($profile_class, 'validate_config')) {
                $profile_errors = $profile_class::validate_config($config);
                if (!empty($profile_errors)) {
                    foreach ($profile_errors as $code) {
                        self::add_diagnostic($result, 'errors', $code);
                    }
                    throw new RuntimeException('invalid_google_config');
                }
            }

            $directory = self::prepare_directory();
            if ('' === $directory) {
                throw new RuntimeException('feed_storage_unavailable');
            }
            $random = bin2hex(random_bytes(16));
            $temp_path = $directory . DIRECTORY_SEPARATOR . '.' . $random . '.php';
            $published_path = $directory . DIRECTORY_SEPARATOR . $random . '.php';
            if (!self::initialize_guarded_snapshot($temp_path)) {
                throw new RuntimeException('feed_file_open_failed');
            }

            if ('csv' === $format) {
                $writer = new ADC_Feed_Writer_Csv();
                $opened = $writer->open($temp_path, $profile_class, $config, true);
            } else {
                $writer = new ADC_Feed_Writer_Rss();
                $opened = $writer->open($temp_path, (string) $feed['name'], true, $profile_class, $config);
            }
            if (!$opened) {
                throw new RuntimeException('feed_file_open_failed');
            }
            $id_table = self::create_id_table($wpdb, $run_id);
            if ('' === $id_table) {
                throw new RuntimeException('feed_id_index_unavailable');
            }

            $chunk_size = (int) ADC_Settings::get('feeds', 'chunk_size', 200);
            $chunk_size = max(100, min(500, $chunk_size));
            $offset = 0;
            if (method_exists('ADC_Feed_Catalog', 'reset_parent_cache')) {
                ADC_Feed_Catalog::reset_parent_cache();
            }
            do {
                if (!self::renew_lock($feed_id, $lock_token)) {
                    throw new RuntimeException('feed_lock_lost');
                }
                $items = ADC_Feed_Catalog::items($config, $offset, $chunk_size);
                $examined = ADC_Feed_Catalog::last_examined();
                $result['examined'] += $examined;
                foreach ($items as $item) {
                    if (class_exists('ADC_Feed_Params')) {
                        $item = ADC_Feed_Params::apply($item, $params, $channel);
                    }
                    foreach ((array) ($item['param_warnings'] ?? array()) as $warning) {
                        self::add_diagnostic($result, 'warnings', (string) $warning);
                    }
                    $required_params = is_array($item['_required_params'] ?? null) ? $item['_required_params'] : array();
                    $feed_item_id = (string) ($required_params['id'] ?? $item['feed_item_id'] ?? '');
                    $channel_item_id = 'tiktok' === $channel
                        ? (string) ($required_params['sku_id'] ?? $item['sku_id'] ?? '')
                        : $feed_item_id;
                    if ('' === $channel_item_id) {
                        self::add_diagnostic($result, 'errors', 'tracking_id_incompatible');
                        continue;
                    }
                    $id_limit = (int) $channel_spec['id_limit'];
                    if ($id_limit > 0 && self::text_length($channel_item_id) > $id_limit) {
                        self::add_diagnostic($result, 'errors', 'id_incompatible');
                        continue;
                    }
                    $id_status = self::reserve_item_id($wpdb, $id_table, $channel_item_id);
                    if ('duplicate' === $id_status) {
                        self::add_diagnostic($result, 'errors', 'duplicate_feed_item_id');
                        continue;
                    }
                    if ('error' === $id_status) {
                        throw new RuntimeException('feed_id_index_failed');
                    }
                    if (method_exists($profile_class, 'diagnostics')) {
                        foreach ($profile_class::diagnostics($item) as $warning) {
                            self::add_diagnostic($result, 'warnings', $warning);
                        }
                    }
                    $mapped_item = $profile_class::map_item($item, $config);
                    unset($mapped_item['price'], $mapped_item['sale_price']);
                    $validation_item = 'google' === $channel ? $item : array_merge($item, $mapped_item);
                    $validation_item = array_merge($validation_item, is_array($item['extra'] ?? null) ? $item['extra'] : array());
                    $validation = self::validate_item($validation_item, $config, $validator_class);
                    foreach ($validation['warnings'] ?? array() as $warning) {
                        self::add_diagnostic($result, 'warnings', (string) $warning);
                    }
                    if (!empty($validation['errors'])) {
                        if (($config['exclude_invalid'] ?? true) === true) {
                            ++$invalid_excluded;
                            continue;
                        }
                        foreach ($validation['errors'] as $error_code) {
                            self::add_diagnostic($result, 'errors', (string) $error_code);
                        }
                        continue;
                    }
                    if (!$writer->write_item($item)) {
                        throw new RuntimeException('feed_write_failed');
                    }
                    ++$result['exported'];
                }
                $offset += $examined;
                if (0 === $examined) {
                    break;
                }
            } while (ADC_Feed_Catalog::has_more());

            if ($invalid_excluded > 0) {
                $result['warnings'][] = array('code' => 'invalid_products_excluded', 'count' => $invalid_excluded);
            }
            if (!empty($result['errors'])) {
                throw new RuntimeException('product_validation_failed');
            }
            if (0 === $result['exported']) {
                throw new RuntimeException('no_valid_products');
            }
            if (!self::renew_lock($feed_id, $lock_token)) {
                throw new RuntimeException('feed_lock_lost');
            }
            if (!$writer->close()) {
                $writer = null;
                throw new RuntimeException('feed_close_failed');
            }
            $writer = null;

            if ('csv' === $format ? !self::is_valid_csv($temp_path) : !self::is_parseable_xml($temp_path)) {
                throw new RuntimeException('feed_output_invalid');
            }
            $digest = self::snapshot_xml_digest($temp_path);
            if (false === $digest || !rename($temp_path, $published_path)) {
                throw new RuntimeException('feed_publish_failed');
            }
            $checksum = $digest['checksum'];
            $bytes = $digest['bytes'];
            $temp_path = '';

            if (!self::publish_snapshot($wpdb, $snapshots_table, $feed_id, $published_path, (int) $bytes, $checksum)) {
                throw new RuntimeException('snapshot_record_failed');
            }
            $published_path = '';

            $result['ok'] = true;
            self::finish_run($wpdb, $runs_table, $run_id, 'complete', $result, $checksum);
            self::retain_snapshots($wpdb, $snapshots_table, $feed_id, 3);
        } catch (Throwable $error) {
            if (is_object($writer)) {
                try {
                    $writer->close();
                } catch (Throwable) {
                    // Keep the original failure as the run result.
                }
            }
            $result['ok'] = false;
            if (empty($result['errors'])) {
                self::add_diagnostic($result, 'errors', self::error_code($error->getMessage()));
            }
            if ('' !== $temp_path && is_file($temp_path)) {
                unlink($temp_path);
            }
            if ('' !== $published_path && is_file($published_path) && !$result['ok']) {
                unlink($published_path);
            }
            if ($run_id > 0) {
                self::finish_run($wpdb, $runs_table, $run_id, 'failed', $result, '');
            }
        } finally {
            if ('' !== $id_table && is_object($wpdb)) {
                self::drop_id_table($wpdb, $id_table);
            }
            self::release_lock($lock_key, $lock_token);
            if (get_transient(self::dirty_key($feed_id))) {
                $retry_error = self::schedule_retry($feed_id);
            } else {
                delete_transient(self::retry_key($feed_id));
            }
        }

        if (in_array($retry_error, array('exhausted', 'failed'), true)) {
            self::add_diagnostic($result, 'errors', 'feed_dirty_retry_' . $retry_error);
            $result['ok'] = false;
            self::finish_run($wpdb, $runs_table, $run_id, 'failed', $result, $checksum);
        }
        return $result;
    }

    /** Adapt the admin wizard config into the normalized profile config. */
    private static function profile_config(array $config): array
    {
        $google = is_array($config['google'] ?? null) ? $config['google'] : array();
        if (!empty($google)) {
            $config['default_category'] = (string) ($google['default_category'] ?? '');
            $config['category_map'] = is_array($google['category_map'] ?? null) ? $google['category_map'] : array();
            $country = strtoupper((string) ($google['shipping_country'] ?? 'RO'));
            $shipping = array(
                'country' => $country,
                'price' => (string) ($google['shipping_price'] ?? ''),
                'service' => (string) ($google['shipping_service'] ?? ''),
            );
            $config['country'] = $country;
            $config['shipping'] = array($shipping);
            $config['condition'] = (string) ($google['condition'] ?? 'new');
        }
        $meta = is_array($config['meta'] ?? null) ? $config['meta'] : array();
        $tiktok = is_array($config['tiktok'] ?? null) ? $config['tiktok'] : array();
        if (!empty($meta)) {
            $config['default_category'] = (string) ($meta['default_category'] ?? '');
            $config['category_map'] = is_array($meta['category_map'] ?? null) ? $meta['category_map'] : array();
            $config['smart_labels'] = is_array($meta['internal_labels'] ?? null) ? $meta['internal_labels'] : array();
            $config['meta_brand'] = (string) ($meta['brand'] ?? '');
            $config['condition'] = (string) ($meta['condition'] ?? 'new');
        }
        if (!empty($tiktok)) {
            $config['default_category'] = (string) ($tiktok['default_category'] ?? '');
            $config['category_map'] = is_array($tiktok['category_map'] ?? null) ? $tiktok['category_map'] : array();
            $config['tiktok_brand'] = (string) ($tiktok['brand'] ?? '');
            $config['condition'] = (string) ($tiktok['condition'] ?? 'new');
        }
        return $config;
    }

    /** Validate an item with the optional Google validator when it is present. */
    private static function validate_item(array $item, array $config, string $validator_class): array
    {
        if (!class_exists($validator_class)) {
            return array('errors' => array(), 'warnings' => array());
        }

        $class = $validator_class;
        $method = null;
        foreach (array('validate_item', 'validate') as $candidate) {
            if (method_exists($class, $candidate)) {
                $method = $candidate;
                break;
            }
        }
        if (null === $method) {
            return array('errors' => array('feed_validator_api_missing'), 'warnings' => array());
        }

        try {
            $reflection = new ReflectionMethod($class, $method);
            $validator = $reflection->isStatic() ? $class : new $class();
            $arguments = array($item);
            if ($reflection->getNumberOfParameters() >= 2) {
                $arguments[] = $config;
            }
            $response = $reflection->invokeArgs($reflection->isStatic() ? null : $validator, $arguments);
            if (is_bool($response)) {
                return $response
                    ? array('errors' => array(), 'warnings' => array())
                    : array('errors' => array('feed_validation_failed'), 'warnings' => array());
            }
            if (is_array($response) && (array_key_exists('errors', $response) || array_key_exists('warnings', $response))) {
                return array(
                    'errors'   => self::string_list($response['errors'] ?? array()),
                    'warnings' => self::string_list($response['warnings'] ?? array()),
                );
            }
            if (is_array($response)) {
                $errors = array();
                $warnings = array();
                foreach ($response as $finding) {
                    if (!is_array($finding)) {
                        continue;
                    }
                    $code = (string) ($finding['code'] ?? 'feed_validation_failed');
                    if ('error' === ($finding['level'] ?? '')) {
                        $errors[] = $code;
                    } elseif ('warning' === ($finding['level'] ?? '')) {
                        $warnings[] = $code;
                    }
                }
                return array('errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings)));
            }
            return array('errors' => array(), 'warnings' => array());
        } catch (Throwable) {
            return array('errors' => array('feed_validation_failed'), 'warnings' => array());
        }
    }

    /** Prepare persistent uploads storage and run the non-blocking exposure diagnostic. */
    private static function prepare_directory(): string
    {
        $directory = ADC_Feed_Store::prepare_storage_directory();
        if ('' !== $directory) {
            ADC_Feed_Store::storage_autotest();
        }
        return $directory;
    }

    /** Publish the new pointer in one DB transaction; rollback leaves prior snapshot current. */
    private static function publish_snapshot(object $wpdb, string $table, int $feed_id, string $path, int $bytes, string $checksum): bool
    {
        $wpdb->query('START TRANSACTION');
        try {
            $updated = $wpdb->update($table, array('is_current' => 0), array('feed_id' => $feed_id, 'is_current' => 1), array('%d'), array('%d', '%d'));
            if (false === $updated) {
                throw new RuntimeException('snapshot_update_failed');
            }
            $inserted = $wpdb->insert(
                $table,
                array(
                    'feed_id' => $feed_id,
                    'file_path' => $path,
                    'bytes' => $bytes,
                    'checksum' => $checksum,
                    'created_at' => current_time('mysql', true),
                    'is_current' => 1,
                ),
                array('%d', '%s', '%d', '%s', '%s', '%d')
            );
            if (false === $inserted) {
                throw new RuntimeException('snapshot_insert_failed');
            }
            $committed = $wpdb->query('COMMIT');
            if (false === $committed) {
                throw new RuntimeException('snapshot_commit_failed');
            }
            return true;
        } catch (Throwable) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    private static function record_busy_run(int $feed_id, array $result): void
    {
        global $wpdb;

        if (!is_object($wpdb)) {
            return;
        }
        $table = ADC_Feed_Schema::table(ADC_Feed_Schema::RUNS);
        $started = current_time('mysql', true);
        $inserted = $wpdb->insert(
            $table,
            array(
                'feed_id' => $feed_id,
                'started_at' => $started,
                'finished_at' => null,
                'status' => 'running',
                'products_examined' => 0,
                'products_exported' => 0,
                'error_summary' => '',
                'warning_summary' => '',
                'snapshot_hash' => '',
            ),
            array('%d', '%s', null, '%s', '%d', '%d', '%s', '%s', '%s')
        );
        if (false !== $inserted) {
            self::finish_run($wpdb, $table, (int) $wpdb->insert_id, 'failed', $result, '');
        }
    }

    private static function finish_run(object $wpdb, string $table, int $run_id, string $status, array $result, string $checksum): void
    {
        if ($run_id <= 0) {
            return;
        }
        $errors = function_exists('wp_json_encode')
            ? wp_json_encode(array_values($result['errors']))
            : json_encode(array_values($result['errors']));
        $warnings = function_exists('wp_json_encode')
            ? wp_json_encode(array_values($result['warnings']))
            : json_encode(array_values($result['warnings']));
        $wpdb->update(
            $table,
            array(
                'finished_at' => current_time('mysql', true),
                'status' => $status,
                'products_examined' => (int) $result['examined'],
                'products_exported' => (int) $result['exported'],
                'error_summary' => is_string($errors) ? $errors : '',
                'warning_summary' => is_string($warnings) ? $warnings : '',
                'snapshot_hash' => $checksum,
            ),
            array('id' => $run_id),
            array('%s', '%s', '%d', '%d', '%s', '%s', '%s'),
            array('%d')
        );
    }

    /** Keep only the three newest snapshots and remove only files in our managed directory. */
    private static function retain_snapshots(object $wpdb, string $table, int $feed_id, int $keep): void
    {
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT id, file_path FROM $table WHERE feed_id = %d ORDER BY id DESC", $feed_id),
            ARRAY_A
        );
        if (!is_array($rows) || count($rows) <= $keep) {
            return;
        }
        foreach (array_slice($rows, $keep) as $row) {
            $wpdb->delete($table, array('id' => (int) $row['id']), array('%d'));
            ADC_Feed_Store::delete_snapshot_file((string) $row['file_path']);
        }
    }

    /** Create the public staging file with its executable exit guard before any XML bytes are written. */
    private static function initialize_guarded_snapshot(string $path): bool
    {
        $stream = @fopen($path, 'xb');
        if (false === $stream) {
            return false;
        }
        $guard = "<?php exit; ?>\n";
        $written = fwrite($stream, $guard);
        $ok = strlen($guard) === $written && fflush($stream);
        fclose($stream);
        return $ok && (DIRECTORY_SEPARATOR === '\\' || @chmod($path, 0600));
    }

    /** Hash and count the XML payload only, streaming past the PHP guard. */
    private static function snapshot_xml_digest(string $path): array|false
    {
        $stream = @fopen($path, 'rb');
        $guard = "<?php exit; ?>\n";
        if (false === $stream || fread($stream, strlen($guard)) !== $guard) {
            if (is_resource($stream)) { fclose($stream); }
            return false;
        }
        $context = hash_init('sha256');
        $bytes = 0;
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if (false === $chunk) {
                fclose($stream);
                return false;
            }
            if ('' !== $chunk) {
                hash_update($context, $chunk);
                $bytes += strlen($chunk);
            }
        }
        fclose($stream);
        return array('checksum' => hash_final($context), 'bytes' => $bytes);
    }

    /** Count identifiers by Unicode characters where mbstring is available. */
    private static function text_length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        if (function_exists('iconv_strlen')) {
            $length = iconv_strlen($value, 'UTF-8');
            if (false !== $length) {
                return $length;
            }
        }
        $matched = preg_match_all('/./us', $value, $matches);
        return false !== $matched ? $matched : strlen($value);
    }

    /** Confirm that a generated CSV has a readable header and every row is valid CSV. */
    private static function is_valid_csv(string $path): bool
    {
        $stream = @fopen($path, 'rb');
        if (false === $stream) {
            return false;
        }
        $guard = "<?php exit; ?>\n";
        if (fread($stream, strlen($guard)) !== $guard) {
            fclose($stream);
            return false;
        }
        $prefix = fread($stream, 3);
        if ("\xEF\xBB\xBF" !== $prefix) {
            fclose($stream);
            return false;
        }
        $header = fgetcsv($stream, null, ',', '"', '');
        $valid = is_array($header) && !empty($header);
        while ($valid && !feof($stream)) {
            $row = fgetcsv($stream, null, ',', '"', '');
            if (false === $row || (array(null) === $row && feof($stream))) {
                break;
            }
            $valid = is_array($row) && count($row) === count($header);
        }
        fclose($stream);
        return $valid;
    }

    /** Parse the guarded XML incrementally; no parser means a controlled generation failure. */
    private static function is_parseable_xml(string $path): bool
    {
        if (!is_file($path) || filesize($path) < 20 || !class_exists('XMLReader')
            || !function_exists('libxml_use_internal_errors') || !function_exists('libxml_get_errors')) {
            return false;
        }
        if (!in_array('adc_feed_strip_snapshot_guard', stream_get_filters(), true)) {
            @stream_filter_register('adc_feed_strip_snapshot_guard', ADC_Feed_XML_Guard_Filter::class);
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader();
        $opened = false;
        $root_seen = false;
        $valid = false;
        try {
            $uri = 'php://filter/read=adc_feed_strip_snapshot_guard/resource=' . $path;
            $opened = @$reader->open($uri, 'UTF-8', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (!$opened) {
                return false;
            }
            while ($reader->read()) {
                if (XMLReader::DOC_TYPE === $reader->nodeType) {
                    return false;
                }
                if (XMLReader::ELEMENT === $reader->nodeType && 0 === $reader->depth) {
                    if ('rss' !== $reader->localName) {
                        return false;
                    }
                    $root_seen = true;
                }
            }
            $valid = $root_seen && array() === libxml_get_errors();
        } catch (Throwable) {
            $valid = false;
        } finally {
            if ($opened) {
                $reader->close();
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $valid;
    }

    /** Add one occurrence while keeping diagnostic memory bounded by distinct codes. */
    private static function add_diagnostic(array &$result, string $type, string $code): void
    {
        if (!in_array($type, array('errors', 'warnings'), true)) {
            return;
        }
        $code = preg_match('/^[a-z][a-z0-9_]{0,79}$/', $code) ? $code : 'unknown_diagnostic';
        foreach ($result[$type] as &$entry) {
            if (is_array($entry) && ($entry['code'] ?? '') === $code) {
                $entry['count'] = max(1, (int) ($entry['count'] ?? 1)) + 1;
                unset($entry);
                return;
            }
        }
        unset($entry);
        if (count($result[$type]) < self::MAX_DIAGNOSTIC_CODES - 1) {
            $result[$type][] = array('code' => $code, 'count' => 1);
            return;
        }
        foreach ($result[$type] as &$entry) {
            if (($entry['code'] ?? '') === 'unknown_diagnostic') {
                ++$entry['count'];
                unset($entry);
                return;
            }
        }
        unset($entry);
        $last = array_pop($result[$type]);
        $result[$type][] = array('code' => 'unknown_diagnostic', 'count' => 1 + max(1, (int) ($last['count'] ?? 1)));
    }

    private static function string_list(mixed $values): array
    {
        if (!is_array($values)) {
            return array();
        }
        return array_values(array_filter(array_map(
            static fn(mixed $value): string => is_scalar($value) ? (string) $value : '',
            $values
        )));
    }

    private static function error_code(string $message): string
    {
        return preg_match('/^[a-z0-9_]+$/', $message) ? $message : 'feed_generation_failed';
    }

    private static function acquire_lock(int $feed_id): string
    {
        global $wpdb;

        if (!function_exists('add_option') || !function_exists('get_option') || !is_object($wpdb) || empty($wpdb->options)) {
            return '';
        }
        $name = self::lock_key($feed_id);
        $token = bin2hex(random_bytes(16));
        $lease = array('token' => $token, 'expires' => time() + self::LOCK_SECONDS);
        if (add_option($name, $lease, '', 'no')) {
            return $token;
        }

        $existing = get_option($name, array());
        if (!is_array($existing) || (int) ($existing['expires'] ?? 0) >= time()) {
            return '';
        }
        return self::compare_swap_lock($wpdb, $name, $existing, $lease) ? $token : '';
    }

    /** Renew only the exact unexpired lease still owned by this token. */
    private static function renew_lock(int $feed_id, string $token): bool
    {
        global $wpdb;

        if ('' === $token || !is_object($wpdb) || empty($wpdb->options) || !function_exists('get_option')) {
            return false;
        }
        $name = self::lock_key($feed_id);
        $existing = get_option($name, array());
        if (!is_array($existing) || !isset($existing['token'], $existing['expires'])
            || !hash_equals($token, (string) $existing['token']) || (int) $existing['expires'] < time()) {
            return false;
        }
        $renewed = array('token' => $token, 'expires' => max(time() + self::LOCK_SECONDS, (int) $existing['expires'] + 1));
        return self::compare_swap_lock($wpdb, $name, $existing, $renewed);
    }

    /** Compare-and-swap an option row so an expired lease cannot be stolen or released by a stale owner. */
    private static function compare_swap_lock(object $wpdb, string $name, array $expected, array $replacement): bool
    {
        if (!function_exists('maybe_serialize')) {
            return false;
        }
        $updated = $wpdb->update(
            $wpdb->options,
            array('option_value' => maybe_serialize($replacement)),
            array('option_name' => $name, 'option_value' => maybe_serialize($expected)),
            array('%s'),
            array('%s', '%s')
        );
        if (1 !== $updated) {
            return false;
        }
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($name, 'options');
        }
        return true;
    }

    private static function release_lock(string $name, string $token): void
    {
        global $wpdb;

        if ('' === $token || !is_object($wpdb) || empty($wpdb->options) || !function_exists('get_option') || !function_exists('maybe_serialize')) {
            return;
        }
        $existing = get_option($name, array());
        if (!is_array($existing) || !isset($existing['token']) || !hash_equals($token, (string) $existing['token'])) {
            return;
        }
        $deleted = $wpdb->delete(
            $wpdb->options,
            array('option_name' => $name, 'option_value' => maybe_serialize($existing)),
            array('%s', '%s')
        );
        if (1 === $deleted && function_exists('wp_cache_delete')) {
            wp_cache_delete($name, 'options');
        }
    }

    /** Create a connection-local exact ID index to detect duplicate/truncated IDs at catalog scale. */
    private static function create_id_table(object $wpdb, int $run_id): string
    {
        $prefix = (string) ($wpdb->prefix ?? '');
        if ('' === $prefix || !preg_match('/^[A-Za-z0-9_]+$/', $prefix) || $run_id <= 0) {
            return '';
        }
        $table = $prefix . 'adc_feed_item_ids_' . $run_id;
        $wpdb->query('DROP TEMPORARY TABLE IF EXISTS `' . $table . '`');
        $created = $wpdb->query(
            'CREATE TEMPORARY TABLE `' . $table . '` ('
            . 'item_id_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'PRIMARY KEY (item_id_hash)) ENGINE=InnoDB'
        );
        return false === $created ? '' : $table;
    }

    /** Reserve an ID in the temporary unique index; never publish duplicate offers. */
    private static function reserve_item_id(object $wpdb, string $table, string $item_id): string
    {
        $query = $wpdb->prepare('INSERT IGNORE INTO `' . $table . '` (item_id_hash) VALUES (%s)', hash('sha256', $item_id));
        $inserted = $wpdb->query($query);
        if (false === $inserted) {
            return 'error';
        }
        return 1 === $inserted ? 'ok' : 'duplicate';
    }

    private static function drop_id_table(object $wpdb, string $table): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            $wpdb->query('DROP TEMPORARY TABLE IF EXISTS `' . $table . '`');
        }
    }

    /** Retry dirty work with bounded attempts and exponential backoff. */
    private static function schedule_retry(int $feed_id): string
    {
        $feed = ADC_Feed_Store::get($feed_id);
        if (null === $feed || 'active' !== ($feed['status'] ?? '')) {
            return 'failed';
        }
        if (self::has_pending_action($feed_id)) {
            return 'scheduled';
        }
        $attempt = max(0, (int) get_transient(self::retry_key($feed_id)));
        if ($attempt >= self::MAX_RETRY_ATTEMPTS) {
            return 'exhausted';
        }
        $delay = min(self::MAX_RETRY_SECONDS, self::DEBOUNCE_SECONDS * (2 ** $attempt));
        $when = time() + $delay;
        set_transient(self::request_key($feed_id), 1, $delay + 60);

        if (function_exists('as_schedule_single_action')) {
            $action_id = as_schedule_single_action($when, ADC_Feed_Scheduler::JOB_HOOK, array($feed_id), ADC_Feed_Module::AS_GROUP, true);
            if ((is_numeric($action_id) && (int) $action_id > 0) || self::has_pending_action($feed_id)) {
                set_transient(self::retry_key($feed_id), $attempt + 1, DAY_IN_SECONDS);
                return 'scheduled';
            }
        }
        if (function_exists('wp_schedule_single_event')) {
            $scheduled = wp_schedule_single_event($when, ADC_Feed_Scheduler::JOB_HOOK, array($feed_id));
            if ((!function_exists('is_wp_error') || !is_wp_error($scheduled)) && false !== $scheduled) {
                set_transient(self::retry_key($feed_id), $attempt + 1, DAY_IN_SECONDS);
                return 'scheduled';
            }
            if (function_exists('wp_next_scheduled') && wp_next_scheduled(ADC_Feed_Scheduler::JOB_HOOK, array($feed_id))) {
                return 'scheduled';
            }
        }
        delete_transient(self::request_key($feed_id));
        return 'failed';
    }

    /** Running Action Scheduler actions are not future work; only pending actions satisfy this check. */
    private static function has_pending_action(int $feed_id): bool
    {
        if (!function_exists('as_get_scheduled_actions')) {
            return false;
        }
        $actions = as_get_scheduled_actions(
            array(
                'hook' => ADC_Feed_Scheduler::JOB_HOOK,
                'args' => array($feed_id),
                'group' => ADC_Feed_Module::AS_GROUP,
                'status' => 'pending',
                'per_page' => 1,
            ),
            'ids'
        );
        return is_array($actions) && !empty($actions);
    }

    private static function lock_key(int $id): string { return 'adc_feed_lock_' . $id; }
    private static function dirty_key(int $id): string { return 'adc_feed_dirty_' . $id; }
    private static function request_key(int $id): string { return 'adc_feed_request_' . $id; }
    private static function retry_key(int $id): string { return 'adc_feed_retry_' . $id; }
}

/** Streaming PHP user filter that removes and verifies the exact snapshot guard for XMLReader. */
if (!class_exists('ADC_Feed_XML_Guard_Filter')) {
    final class ADC_Feed_XML_Guard_Filter extends php_user_filter
    {
        private string $prefix_buffer = '';
        private bool $validated = false;
        private const GUARD = "<?php exit; ?>\n";

        public function filter($in, $out, &$consumed, $closing): int
        {
            while ($bucket = stream_bucket_make_writeable($in)) {
                $consumed += $bucket->datalen;
                $data = $bucket->data;
                if (!$this->validated) {
                    $this->prefix_buffer .= $data;
                    if (strlen($this->prefix_buffer) < strlen(self::GUARD)) {
                        continue;
                    }
                    if (!str_starts_with($this->prefix_buffer, self::GUARD)) {
                        return PSFS_ERR_FATAL;
                    }
                    $data = substr($this->prefix_buffer, strlen(self::GUARD));
                    $this->prefix_buffer = '';
                    $this->validated = true;
                }
                if ('' !== $data) {
                    $bucket->data = $data;
                    $bucket->datalen = strlen($data);
                    stream_bucket_append($out, $bucket);
                }
            }
            if ($closing && !$this->validated) {
                return PSFS_ERR_FATAL;
            }
            return PSFS_PASS_ON;
        }
    }
}
