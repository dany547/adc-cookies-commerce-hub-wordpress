<?php
/** Offline tests for recurring feed refresh scheduling and active-state guards. */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
$GLOBALS['scheduler_hooks'] = array();
$GLOBALS['scheduled_recurring'] = array();
$GLOBALS['unscheduled_recurring'] = array();
$GLOBALS['generator_runs'] = array();
function add_action(string $hook, callable|array|string $callback, int $priority = 10, int $accepted_args = 1): bool
{
    $GLOBALS['scheduler_hooks'][$hook] = array($callback, $accepted_args);
    return true;
}
function as_unschedule_all_actions(string $hook, ?array $args = array(), string $group = ''): int
{
    $GLOBALS['unscheduled_recurring'][] = array($hook, $args, $group);
    return 1;
}
function as_schedule_recurring_action(int $timestamp, int $interval, string $hook, array $args = array(), string $group = '', bool $unique = false): int
{
    $GLOBALS['scheduled_recurring'][] = compact('timestamp', 'interval', 'hook', 'args', 'group', 'unique');
    return count($GLOBALS['scheduled_recurring']);
}
final class ADC_Feed_Module { public const AS_GROUP = 'adc-feeds'; }
final class ADC_Feed_Store
{
    public static array $feed = array('status' => 'active');
    public static function get(int $id): ?array { return $id === 8 ? self::$feed : null; }
    public static function all(): array { return array(array('id' => 8, 'status' => 'active', 'config' => array('refresh' => 'daily'))); }
}
final class ADC_Feed_Generator
{
    public static function run(int $id): array { $GLOBALS['generator_runs'][] = $id; return array('ok' => true); }
}
require __DIR__ . '/../includes/feeds/class-adc-feed-scheduler.php';
function scheduler_check(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

ADC_Feed_Scheduler::boot();
scheduler_check(isset($GLOBALS['scheduler_hooks'][ADC_Feed_Scheduler::REFRESH_HOOK]), 'recurring refresh callback is registered');
scheduler_check(ADC_Feed_Scheduler::sync_refresh(8, 'active', 'daily'), 'daily refresh schedules');
$daily = end($GLOBALS['scheduled_recurring']);
scheduler_check($daily['interval'] === 86400 && $daily['group'] === 'adc-feeds' && $daily['unique'], 'daily job has the expected interval, group, and uniqueness');
scheduler_check(end($GLOBALS['unscheduled_recurring']) === array(ADC_Feed_Scheduler::REFRESH_HOOK, array(8), 'adc-feeds'), 'prior recurrence is removed before rescheduling');
$before = count($GLOBALS['scheduled_recurring']);
scheduler_check(ADC_Feed_Scheduler::sync_refresh(8, 'disabled', 'weekly') && count($GLOBALS['scheduled_recurring']) === $before, 'disabled feeds keep no recurring refresh');
scheduler_check(ADC_Feed_Scheduler::sync_refresh(8, 'active', 'manual') && count($GLOBALS['scheduled_recurring']) === $before, 'manual cadence removes recurrence without replacing it');
ADC_Feed_Scheduler::rebuild_active_refreshes();
scheduler_check(count($GLOBALS['scheduled_recurring']) === $before + 1, 'activation rebuilds recurring jobs for active non-manual feeds');
ADC_Feed_Scheduler::cancel_all_refreshes();
scheduler_check(end($GLOBALS['unscheduled_recurring']) === array(ADC_Feed_Scheduler::REFRESH_HOOK, null, 'adc-feeds'), 'deactivation cancels all feed refresh recurrences in the feed group');
ADC_Feed_Scheduler::refresh(8);
scheduler_check($GLOBALS['generator_runs'] === array(8), 'recurring handler regenerates an active feed');
ADC_Feed_Store::$feed = array('status' => 'disabled');
ADC_Feed_Scheduler::refresh(8);
scheduler_check($GLOBALS['generator_runs'] === array(8), 'recurring handler skips a feed disabled after scheduling');
echo "feed-scheduler-test: PASS\n";
