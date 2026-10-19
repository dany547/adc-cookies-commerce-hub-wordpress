<?php
/**
 * Server-side dispatch pipeline (docs/ARCHITECTURE.md §2.5, §3.4).
 *
 * Contract highlights:
 * - enqueue(): per-platform consent gate at CREATION time (event snapshot),
 *   `adc_event_should_send` filter, duplicate-safe outbox insert, one
 *   Action Scheduler job per row (purchase delayed by events.server_delay_seconds).
 * - process_job(): consent RE-CHECK at SEND time via ADC_Consent::effective()
 *   (D13: withdrawal always wins), event-age guard (D4), is_enabled re-check
 *   (D20), retry ladder via `adc_outbox_retry_schedule` (429/5xx), no HTTP on
 *   frontend requests.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Listens to `adc_track_event`, queues outbox rows and processes them.
 */
final class ADC_Dispatcher implements ADC_Module_Interface
{
    /**
     * Action Scheduler hook (§2.5).
     */
    public const JOB_HOOK = 'adc_dispatch_job';

    /**
     * AS group shared by all plugin jobs.
     */
    public const GROUP = 'adc-marketing';

    /**
     * Default retry ladder in seconds (filter `adc_outbox_retry_schedule`).
     *
     * @var int[]
     */
    private const RETRY_SCHEDULE = array(60, 300, 1800, 7200, 43200);

    /**
     * Max event age per platform before the send is dead without HTTP (D4).
     * Platforms not listed: conservative 7 days.
     *
     * @var array<string, int>
     */
    private const MAX_EVENT_AGE = array(
        'meta'   => 7 * DAY_IN_SECONDS,
        'tiktok' => 7 * DAY_IN_SECONDS,
        'ga4'    => 72 * HOUR_IN_SECONDS,
        'sgtm'   => 72 * HOUR_IN_SECONDS,
    );

    /**
     * Boot the module: entry points + withdrawal listener.
     */
    public static function boot(): void
    {
        // C4 (Event_Hooks) and ADC_Tracking_Rest fire this action.
        add_action('adc_track_event', array(self::class, 'enqueue'));
        add_action(self::JOB_HOOK, array(self::class, 'process_job'), 10, 1);

        // Withdrawal cancels queued jobs for the revoked categories (§3.5 step 6).
        add_action('adc_consent_withdrawn', array(self::class, 'on_consent_withdrawn'), 10, 2);
    }

    /**
     * Bridge for the `adc_consent_withdrawn` action (cid + revoked categories).
     *
     * @param ADC_Consent_State $new     New state.
     * @param string[]          $revoked Categories that went true→false.
     */
    public static function on_consent_withdrawn($new, array $revoked = array()): void
    {
        $cid = '';
        if ($new instanceof ADC_Consent_State) {
            $cid = $new->cid;
        }

        self::cancel_pending($cid, $revoked);
    }

    /**
     * Entry point; also bound to action `adc_track_event`.
     *
     * @param ADC_Event $event Canonical event (consent snapshot embedded).
     * @return array<string, string> platform => 'queued'|'skipped_no_consent'|'skipped_disabled'|'skipped_filtered'|'duplicate'.
     */
    public static function enqueue(ADC_Event $event): array
    {
        if (defined('ADC_MARKETING_DISABLE_TRACKING') && ADC_MARKETING_DISABLE_TRACKING) {
            return array(); // Kill-switch: no server sends at all.
        }

        $results = array();
        $consent = self::consent_from_snapshot($event->consent, $event->cid);

        foreach (self::integrations() as $id => $integration) {
            if (!$integration->supports($event->name)) {
                continue;
            }

            if (!self::allows($consent, $integration->required_category())) {
                $results[$id] = 'skipped_no_consent'; // No outbox row (nothing ever stored).
                continue;
            }

            if (!apply_filters('adc_event_should_send', true, $event, $id)) {
                $results[$id] = 'skipped_filtered';
                continue;
            }

            $scheduled = time() + self::delay_for($event->name);
            $job_id    = ADC_Outbox::insert(self::payload_for_platform($event, $id), $id, $scheduled);

            if ($job_id <= 0) {
                $results[$id] = 'duplicate';
                continue;
            }

            self::schedule_job($job_id, $scheduled);
            $results[$id] = 'queued';
        }

        return $results;
    }

    /**
     * Strip hashed user_data for platforms that are not in matching.send_to
     * (§4: identity only leaves for explicitly allowed platforms).
     *
     * @param ADC_Event $event    Canonical event.
     * @param string    $platform Platform id.
     * @return ADC_Event
     */
    private static function payload_for_platform(ADC_Event $event, string $platform): ADC_Event
    {
        $send_to = ADC_Settings::get('matching', 'send_to', array('meta', 'tiktok', 'ga4'));
        $send_to = is_array($send_to) ? $send_to : array();

        if (null === $event->user || in_array($platform, array_map('strval', $send_to), true)) {
            return $event;
        }

        return ADC_Event::from_array(array_merge($event->to_array(), array('user' => null)));
    }

    /**
     * Server-side delay: purchase waits for gateway status settling (§4 events.server_delay_seconds).
     *
     * @param string $event_name Event name.
     * @return int Seconds.
     */
    private static function delay_for(string $event_name): int
    {
        if ('purchase' !== $event_name) {
            return 0;
        }

        $delay = ADC_Settings::get('events', 'server_delay_seconds', 60);

        return max(0, (int) $delay);
    }

    /**
     * Schedule one Action Scheduler job (WP-Cron fallback per §2.5).
     *
     * @param int $job_id     Outbox row id.
     * @param int $timestamp  Unix seconds.
     */
    private static function schedule_job(int $job_id, int $timestamp): void
    {
        if (class_exists('ActionScheduler')) {
            as_schedule_single_action($timestamp, self::JOB_HOOK, array('job_id' => $job_id), self::GROUP);

            return;
        }

        ADC_Logger::log('warning', 'Action Scheduler missing; falling back to WP-Cron.');
        wp_schedule_single_event($timestamp, self::JOB_HOOK, array('job_id' => $job_id));
    }

    /**
     * Action Scheduler callback for hook `adc_dispatch_job`.
     *
     * @param int $job_id Outbox row id.
     */
    public static function process_job(int $job_id): void
    {
        if (!self::is_runner_context()) {
            return; // Never send HTTP from a normal frontend request (§2.5).
        }

        $job = ADC_Outbox::get($job_id);
        if (null === $job || 'pending' !== $job->status) {
            return; // Already handled or purged.
        }

        if (null === $job->payload) {
            ADC_Outbox::mark($job_id, 'dead', 0, 'payload_missing');

            return;
        }

        $event = ADC_Event::from_array($job->payload);
        if ('' === $event->event_id || '' === $event->name) {
            ADC_Outbox::mark($job_id, 'dead', 0, 'payload_invalid');

            return;
        }

        $platform    = $job->platform;
        $integration = self::integrations()[$platform] ?? null;

        // D20: credentials/URL may have been removed since enqueue.
        if (null === $integration) {
            ADC_Outbox::mark($job_id, 'skipped_disabled', 0, 'integration_disabled_or_gone');

            return;
        }

        // ---- Consent re-check at SEND time (D13: withdrawal always wins). ----
        $snapshot = self::consent_from_snapshot($event->consent, $job->cid);
        $consent  = $snapshot;
        if (class_exists('ADC_Consent') && '' !== $job->cid) {
            $consent = ADC_Consent::effective($job->cid, $snapshot);
        }

        if (!self::allows($consent, $integration->required_category())) {
            ADC_Outbox::mark($job_id, 'skipped_no_consent', 0, 'consent_revoked_at_send_time');

            return;
        }

        // ---- D4: too-old events are dead without an HTTP attempt. ----
        $max_age = self::MAX_EVENT_AGE[$platform] ?? 7 * DAY_IN_SECONDS;
        if ($event->time > 0 && (time() - $event->time) > $max_age) {
            ADC_Outbox::mark($job_id, 'dead', 0, 'event_too_old');

            return;
        }

        $result = $integration->send($event, $consent);
        if (!$result instanceof ADC_Send_Result) {
            ADC_Outbox::mark($job_id, 'dead', 0, 'invalid_send_result');

            return;
        }

        switch ($result->status) {
            case 'sent':
                ADC_Outbox::mark($job_id, 'sent', $result->http_code, '', true);

                return;

            case 'retry':
                self::handle_retry($job, $result);

                return;

            case 'dead':
                ADC_Outbox::mark($job_id, 'dead', $result->http_code, $result->message);

                return;

            default: // skipped.
                ADC_Outbox::mark($job_id, 'skipped', 0, $result->message);
        }
    }

    /**
     * Reschedule per the `adc_outbox_retry_schedule` filter; dead after the
     * ladder is exhausted (default 5 attempts).
     *
     * @param ADC_Outbox_Job  $job    Job.
     * @param ADC_Send_Result $result Retry result.
     */
    private static function handle_retry(ADC_Outbox_Job $job, ADC_Send_Result $result): void
    {
        $schedule = apply_filters('adc_outbox_retry_schedule', self::RETRY_SCHEDULE);
        $schedule = array_values(array_filter(is_array($schedule) ? $schedule : array(), static fn ($s): bool => is_int($s) || is_numeric($s)));
        $schedule = array_map('intval', $schedule);

        if (array() === $schedule) {
            $schedule = self::RETRY_SCHEDULE;
        }

        $next_index = $job->attempts; // attempts so far; this failure consumes slot [attempts].
        if ($next_index >= count($schedule)) {
            ADC_Outbox::mark($job->id, 'dead', $result->http_code, $result->message);

            return;
        }

        $when = time() + max(1, $schedule[$next_index]);
        ADC_Outbox::schedule_retry($job->id, $when, $result->message, $result->http_code);
        self::schedule_job($job->id, $when);
    }

    /**
     * Marks pending rows 'skipped_no_consent' for platforms whose required
     * category was revoked (§3.5 step 6).
     *
     * @param string   $cid               Consent id.
     * @param string[] $revoked_categories Revoked categories.
     * @return int Cancelled rows.
     */
    public static function cancel_pending(string $cid, array $revoked_categories): int
    {
        if ('' === $cid || array() === $revoked_categories) {
            return 0;
        }

        $required = self::required_categories_by_platform();
        $pending  = ADC_Outbox::pending_for_cid($cid);

        $cancelled = 0;
        foreach ($pending as $job) {
            $category = $required[$job->platform] ?? '';
            if ('' !== $category && in_array($category, $revoked_categories, true)) {
                ADC_Outbox::mark($job->id, 'skipped_no_consent', 0, 'consent_withdrawn');
                $cancelled++;
            }
        }

        if ($cancelled > 0) {
            ADC_Logger::log('info', 'Cancelled pending jobs after consent withdrawal.', array('count' => $cancelled));
        }

        return $cancelled;
    }

    /**
     * Required consent category per platform (ALL registered integrations,
     * including disabled ones — rows exist for platforms enabled at enqueue).
     *
     * @return array<string, string>
     */
    private static function required_categories_by_platform(): array
    {
        $map = array();
        foreach (self::integration_classes() as $class) {
            try {
                $instance        = new $class();
                $map[$instance->id()] = $instance->required_category();
            } catch (Throwable $e) {
                continue; // A broken integration must never break cancellation.
            }
        }

        return $map;
    }

    /**
     * Enabled integrations keyed by id, supersedes applied (§2.5):
     * if an enabled integration supersedes another enabled one, the
     * superseded platform is dropped.
     *
     * @return array<string, ADC_Integration_Interface>
     */
    public static function integrations(): array
    {
        $instances = array();
        foreach (self::integration_classes() as $class) {
            try {
                $instance = new $class();
            } catch (Throwable $e) {
                continue;
            }

            if (!$instance instanceof ADC_Integration_Interface || !$instance->is_enabled()) {
                continue;
            }

            $instances[$instance->id()] = $instance;
        }

        $superseded = array();
        foreach ($instances as $integration) {
            foreach ($integration->supersedes() as $id) {
                $superseded[$id] = true;
            }
        }

        return array_filter(
            $instances,
            static fn (string $id) => !isset($superseded[$id]),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * All integration class names from the `adc_integrations` filter,
     * validated against the interface.
     *
     * @return class-string<ADC_Integration_Interface>[]
     */
    private static function integration_classes(): array
    {
        $classes = apply_filters('adc_integrations', array());
        $classes = is_array($classes) ? $classes : array();

        $valid = array();
        foreach ($classes as $class) {
            if (is_string($class) && class_exists($class) && is_subclass_of($class, ADC_Integration_Interface::class)) {
                $valid[] = $class;
            }
        }

        return array_values(array_unique($valid));
    }

    /**
     * Rebuild an ADC_Consent_State from an event snapshot. Null means "no
     * consent engine available" and is treated as denied everywhere —
     * nothing is ever sent without a real consent engine.
     *
     * @param array  $snapshot Consent array embedded in the event.
     * @param string $cid      Fallback cid for the state.
     * @return ADC_Consent_State|null
     */
    private static function consent_from_snapshot(array $snapshot, string $cid): ?ADC_Consent_State
    {
        if (array() !== $snapshot && class_exists('ADC_Consent_State')) {
            return new ADC_Consent_State(
                !empty($snapshot['preferences']),
                !empty($snapshot['statistics']),
                !empty($snapshot['marketing']),
                !empty($snapshot['decided']),
                (int) ($snapshot['ts'] ?? 0),
                (int) ($snapshot['policy'] ?? 0),
                (string) ($snapshot['cid'] ?? $cid),
                (string) ($snapshot['source'] ?? '')
            );
        }

        if (class_exists('ADC_Consent')) {
            return ADC_Consent::current();
        }

        return null; // Undecided: denied everywhere.
    }

    /**
     * Category check tolerating a missing consent engine (null ⇒ denied).
     *
     * @param ADC_Consent_State|null $consent  State (or null).
     * @param string                 $category Required category.
     * @return bool
     */
    private static function allows(?ADC_Consent_State $consent, string $category): bool
    {
        return null !== $consent && $consent->allows($category);
    }

    /**
     * Whether the current request may perform platform HTTP: cron, AJAX
     * (Action Scheduler runner / admin-ajax), WP-CLI or REST. Normal page
     * loads never send.
     */
    private static function is_runner_context(): bool
    {
        if (wp_doing_cron() || wp_doing_ajax()) {
            return true;
        }

        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        return false;
    }
}
