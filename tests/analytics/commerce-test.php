<?php
/**
 * Standalone, deterministic commerce pipeline test.
 *
 * Backs ADC_Analytics_Store with a miniature InnoDB-faithful SQL engine
 * (rows + unique buckets + snapshot transactions + named day locks + fault
 * injection) so the tests below exercise REAL transactional semantics:
 * rolled-back ledger claims disappear, duplicate claims skip aggregates,
 * and a failed job re-enqueues and later applies exactly once.
 *
 * Run: php tests/analytics/commerce-test.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 1);

interface ADC_Module_Interface { public static function boot(): void; }

/* ---------------------------------------------------------------------------
 * WordPress stubs (site timezone fixed to Europe/Bucharest)
 * ------------------------------------------------------------------------- */

final class ADC_Settings
{
	/** @var array<string,mixed> */
	public static array $values = array();

	public static function get(string $group, string $key, mixed $default = null): mixed
	{
		unset($group);
		return self::$values[$key] ?? $default;
	}
}

final class ADC_Logger
{
	/** @var string[] */
	public static array $lines = array();

	public static function log(string $level, string $message, array $context = array()): void
	{
		self::$lines[] = $level . ': ' . $message . (isset($context['source']) ? ' [' . $context['source'] . ']' : '');
	}
}

/** @var array<int,array<string,mixed>> */
$GLOBALS['__adc_actions'] = array();
/** @var array<int,array<string,mixed>> */
$GLOBALS['__adc_cron'] = array();
/** @var array<int,array<string,mixed>> */
$GLOBALS['__adc_as'] = array();

function add_action(string $hook, callable|string|array $cb, int $prio = 10, int $args = 1): void
{
	$GLOBALS['__adc_actions'][] = array('hook' => $hook, 'args' => $args);
}
function wp_date(string $format, ?int $ts = null, ?DateTimeZone $tz = null): string
{
	// Deterministic clock: 2024-04-01 12:00 UTC (site tz Europe/Bucharest).
	$date = new DateTimeImmutable('@' . ($ts ?? ($GLOBALS['__adc_now'] ?? 1711972800)));
	return $date->setTimezone($tz ?? new DateTimeZone('Europe/Bucharest'))->format($format);
}
function wp_timezone(): DateTimeZone
{
	return new DateTimeZone('Europe/Bucharest');
}
function current_time(string $format): string
{
	return wp_date($format);
}
function __(string $text, string $domain = 'default'): string
{
	unset($domain);
	return $text;
}
function sanitize_text_field(mixed $value): string
{
	return trim(strip_tags((string) $value));
}
function wp_schedule_single_event(int $ts, string $hook, array $args = array()): bool
{
	$GLOBALS['__adc_cron'][] = array('ts' => $ts, 'hook' => $hook, 'args' => $args);
	return true;
}
// Action Scheduler is intentionally NOT defined here: without it, Commerce
// must fall back to WP-Cron. The AS path is activated at the end of this file
// via eval (function definitions would otherwise be hoisted to compile time).

/* ---------------------------------------------------------------------------
 * Fake WooCommerce objects + repository (site-local date windows supported)
 * ------------------------------------------------------------------------- */

final class FakeWCItem
{
	public function __construct(
		private int $product_id,
		private int $variation_id,
		private int $quantity,
		private float $total,
		private float $total_tax
	) {
	}
	public function get_product_id(): int { return $this->product_id; }
	public function get_variation_id(): int { return $this->variation_id; }
	public function get_quantity(): int { return $this->quantity; }
	public function get_total(): float { return $this->total; }
	public function get_total_tax(): float { return $this->total_tax; }
}

class WC_Order
{
	/** @var FakeWCItem[] */
	public array $items = array();

	public function __construct(
		public int $id,
		public string $currency = 'RON',
		public string $status = 'pending',
		public float $total = 0.0,
		public float $tax = 0.0,
		public float $shipping = 0.0,
		public float $shipping_tax = 0.0,
		public float $discount = 0.0,
		public float $discount_tax = 0.0,
		public ?DateTimeImmutable $created = null,
		public ?DateTimeImmutable $paid = null,
		public ?DateTimeImmutable $modified = null
	) {
	}
	public function get_id(): int { return $this->id; }
	public function get_currency(): string { return $this->currency; }
	public function get_status(): string { return $this->status; }
	public function get_total(): float { return $this->total; }
	public function get_total_tax(): float { return $this->tax; }
	public function get_shipping_total(): float { return $this->shipping; }
	public function get_shipping_tax(): float { return $this->shipping_tax; }
	public function get_discount_total(): float { return $this->discount; }
	public function get_discount_tax(): float { return $this->discount_tax; }
	public function get_date_created(): ?DateTimeImmutable { return $this->created; }
	public function get_date_paid(): ?DateTimeImmutable { return $this->paid; }
	public function get_date_modified(): ?DateTimeImmutable { return $this->modified; }

	/** @return FakeWCItem[] */
	public function get_items(array $types = array('line_item')): array
	{
		unset($types);
		return $this->items;
	}
}

class WC_Order_Refund
{
	/** @var FakeWCItem[] */
	public array $items = array();

	public function __construct(
		public int $id,
		public int $parent_id,
		public float $amount,
		public ?DateTimeImmutable $created = null
	) {
	}
	public function get_id(): int { return $this->id; }
	public function get_parent_id(): int { return $this->parent_id; }
	public function get_amount(): float { return $this->amount; }
	public function get_status(): string { return 'completed'; }
	public function get_date_created(): ?DateTimeImmutable { return $this->created; }

	/** @return FakeWCItem[] */
	public function get_items(array $types = array('line_item')): array
	{
		unset($types);
		return $this->items;
	}
}

final class FakeOrderRepo
{
	/** @var array<int,WC_Order> */
	public static array $orders = array();
	/** @var array<int,WC_Order_Refund> */
	public static array $refunds = array();

	public static function reset(): void
	{
		self::$orders = array();
		self::$refunds = array();
	}
}

function wc_get_order(int|object $id): WC_Order|WC_Order_Refund|false
{
	if (is_object($id)) {
		return $id;
	}
	return FakeOrderRepo::$orders[$id] ?? FakeOrderRepo::$refunds[$id] ?? false;
}

/**
 * @return array<int,WC_Order|WC_Order_Refund>
 */
function wc_get_orders(array $args): array
{
	$rows = ('shop_order_refund' === ($args['type'] ?? 'shop_order'))
		? FakeOrderRepo::$refunds
		: FakeOrderRepo::$orders;

	$status = $args['status'] ?? 'any';

	// Strict date-filter emulation of the REAL wc_get_orders API: only the
	// documented forms are accepted. The array {after,before} form is the exact
	// bug that crashed real WC (posts: preg_match TypeError; HPOS: strtotime
	// TypeError) — rejected here so production code can never regress to it.
	$date_prop = null;
	$window = null;
	foreach (array('date_created', 'date_paid') as $prop) {
		if (isset($args[$prop])) {
			$window = $args[$prop];
			$date_prop = $prop;
		}
	}

	$min_ts = null;
	$max_ts = null;
	if (null !== $date_prop) {
		if (is_array($window)) {
			throw new TypeError('wc_get_orders: date props do not accept arrays (use the documented "min...max" timestamp range)');
		}
		if (is_string($window) && str_contains($window, '...')) {
			[$min_raw, $max_raw] = explode('...', $window, 2);
			if (!is_numeric($min_raw) || !is_numeric($max_raw)) {
				throw new InvalidArgumentException('wc_get_orders: range bounds must be unix timestamps');
			}
			$min_ts = (int) $min_raw;
			$max_ts = (int) $max_raw;
		} elseif (is_string($window) && preg_match('/^(>=|<=|>|<|=)(\d+)$/', $window, $m)) {
			$value = (int) $m[2];
			$min_ts = match ($m[1]) { '>' => $value + 1, '>=' => $value, default => PHP_INT_MIN };
			$max_ts = match ($m[1]) { '<' => $value - 1, '<=' => $value, '=' => $value, default => PHP_INT_MAX };
			if ('=' === $m[1]) { $min_ts = $value; }
		} else {
			throw new InvalidArgumentException('wc_get_orders: unsupported date filter form');
		}
	}

	$getter = array('date_created' => 'get_date_created', 'date_paid' => 'get_date_paid')[$date_prop ?? 'date_created'];
	$out = array();
	foreach ($rows as $row) {
		if ('any' !== $status && is_array($status) && !in_array($row->get_status(), $status, true) && !in_array('wc-' . $row->get_status(), $status, true)) {
			continue;
		}
		if (null !== $date_prop) {
			$date = $row->{$getter}();
			if (null === $date) {
				continue;
			}
			$ts = $date->getTimestamp(); // Absolute epoch: site-day bounds are computed by the caller.
			if ($ts < $min_ts || $ts > $max_ts) {
				continue;
			}
		}
		$out[] = $row;
	}

	$limit = $args['limit'] ?? -1;
	return ($limit > 0) ? array_slice($out, 0, $limit) : $out;
}

/* ---------------------------------------------------------------------------
 * Mini SQL engine: rows + unique buckets + snapshot transactions + day locks
 * ------------------------------------------------------------------------- */

final class FakeAnalyticsDb
{
	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = array();
	/** @var array<string,string[]> unique bucket columns per table */
	public array $unique = array(
		'wp_adc_analytics_commerce_daily' => array('stat_date', 'currency', 'order_status', 'metric'),
		'wp_adc_analytics_products_daily' => array('stat_date', 'currency', 'product_id'),
		'wp_adc_analytics_order_ledger'   => array('order_id', 'event_key'),
		'wp_adc_analytics_interactions'   => array('stat_date', 'event_name'),
		'wp_adc_analytics_traffic'        => array('stat_date', 'stat_hour', 'channel', 'page_type', 'object_id'),
	);
	/** @var array<string,array<string,bool>> numeric (int|decimal) columns per table */
	public array $numeric = array(
		'wp_adc_analytics_commerce_daily' => array('amount' => 'dec', 'count' => 'int', 'id' => 'int'),
		'wp_adc_analytics_products_daily' => array('units' => 'int', 'items_net' => 'dec', 'items_tax' => 'dec', 'refund_units' => 'int', 'refund_net' => 'dec', 'refund_tax' => 'dec', 'product_id' => 'int', 'id' => 'int'),
		'wp_adc_analytics_order_ledger'   => array('order_id' => 'int', 'amount' => 'dec', 'id' => 'int'),
		'wp_adc_analytics_interactions'   => array('count' => 'int', 'id' => 'int'),
		'wp_adc_analytics_traffic'        => array('views' => 'int', 'stat_hour' => 'int', 'object_id' => 'int', 'id' => 'int'),
	);
	/** @var array<string,bool> held named locks */
	public array $locks = array();
	public string $last = '';
	public string $prefix = 'wp_';
	public int $transactions = 0;
	public int $rollbacks = 0;
	public int $commits = 0;
	/** @var null|callable(string):bool fault injector */
	public $fail_when = null;
	/** @var array<string,array<string,array<int,array<string,mixed>>>> snapshot stack */
	private array $snapshots = array();

	public function __construct()
	{
		foreach (array_keys($this->unique) as $table) {
			$this->tables[$table] = array();
		}
	}

	public function prepare(string $sql, mixed ...$args): string
	{
		$out = '';
		$pos = 0;
		$i = 0;
		while (($pos = strpos($sql, '%', $pos)) !== false) {
			$spec = $sql[$pos + 1];
			$out .= substr($sql, 0, $pos);
			$value = $args[$i++] ?? null;
			$out .= match ($spec) {
				'i' => (string) $value,
				'd' => (string) (int) $value,
				'f' => sprintf('%F', (float) $value),
				default => "'" . addslashes((string) $value) . "'",
			};
			$sql = substr($sql, $pos + 2);
			$pos = 0;
		}
		return $out . $sql;
	}

	private function fault(string $sql): bool
	{
		return null !== $this->fail_when && (bool) ($this->fail_when)($sql);
	}

	public function query(string $sql): int|false
	{
		$this->last = $sql;
		if ($this->fault($sql)) {
			return false;
		}

		if ('START TRANSACTION' === $sql) { $this->snapshots[] = $this->snapshot(); $this->transactions++; return 0; }
		if ('COMMIT' === $sql) { array_pop($this->snapshots); $this->commits++; return 0; }
		if ('ROLLBACK' === $sql) { $this->restore(array_pop($this->snapshots) ?? array()); $this->rollbacks++; return 0; }

		if (preg_match("/^INSERT INTO (\w+) \(([^)]*)\) VALUES \((.*)\) ON DUPLICATE KEY UPDATE (.+)$/s", $sql, $m)) {
			return $this->upsert($m[1], $m[2], $m[3], $m[4]);
		}
		// RELEASE_LOCK is sent via query() in production (SELECT through wpdb->query).
		if (preg_match("/^SELECT RELEASE_LOCK\('([^']*)'\)$/", $sql, $m)) {
			unset($this->locks[$m[1]]);
			return 0;
		}
		if (preg_match("/^DELETE FROM (\w+) WHERE stat_date\\s*=\\s*'([^']*)'$/", $sql, $m)) {
			return $this->delete_exact($m[1], $m[2]);
		}
		if (preg_match("/^DELETE FROM (\w+) WHERE stat_date < '([^']*)'$/", $sql, $m)) {
			return $this->delete_before($m[1], $m[2]);
		}

		return 0;
	}

	public function get_var(string $sql): mixed
	{
		$this->last = $sql;
		if ($this->fault($sql)) {
			return null;
		}
		if (preg_match("/^SELECT GET_LOCK\('([^']*)', (\d+)\)$/", $sql, $m)) {
			if (!empty($this->locks[$m[1]])) { return '0'; }
			$this->locks[$m[1]] = true;
			return '1';
		}
		if (preg_match("/^SELECT RELEASE_LOCK\('([^']*)'\)$/", $sql, $m)) {
			unset($this->locks[$m[1]]);
			return '1';
		}
		if (preg_match("/^SELECT 1 FROM (\w+) WHERE order_id=(\d+) AND event_key='([^']*)' LIMIT 1$/", $sql, $m)) {
			foreach ($this->tables[$m[1]] as $row) {
				if ((int) $row['order_id'] === (int) $m[2] && $row['event_key'] === $m[3]) { return '1'; }
			}
			return null;
		}
		return null;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results(string $sql, int|string $mode = 'OBJECT'): array
	{
		unset($mode);
		$this->last = $sql;
		if ($this->fault($sql)) {
			return array();
		}
		if (preg_match("/^SELECT \* FROM (\w+) WHERE stat_date = '([^']*)'$/", $sql, $m)) {
			return $this->select($m[1], $m[2], $m[2]);
		}
		if (preg_match("/^SELECT \* FROM (\w+) WHERE stat_date BETWEEN '([^']*)' AND '([^']*)'(?: AND (\w+) = '([^']*)')?$/", $sql, $m)) {
			$rows = $this->select($m[1], $m[2], $m[3]);
			if (isset($m[4])) {
				$rows = array_values(array_filter($rows, static fn($row) => (string) $row[$m[4]] === $m[5]));
			}
			return $rows;
		}
		return array();
	}

	private function select(string $table, string $from, string $to): array
	{
		$rows = array();
		foreach ($this->tables[$table] as $row) {
			if ((string) $row['stat_date'] >= $from && (string) $row['stat_date'] <= $to) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	private function upsert(string $table, string $columns, string $values, string $updates): int|false
	{
		$cols = array_map('trim', explode(',', $columns));
		$vals = $this->split_values($values);
		if (count($cols) !== count($vals)) {
			return false;
		}
		$row = array();
		foreach ($cols as $i => $col) {
			$row[$col] = $this->literal($vals[$i]);
		}

		$index = null;
		foreach ($this->tables[$table] as $i => $existing) {
			$match = true;
			foreach ($this->unique[$table] as $key) {
				if ((string) $existing[$key] !== (string) $row[$key]) { $match = false; break; }
			}
			if ($match) { $index = $i; break; }
		}

		if (null === $index) {
			$row['id'] = count($this->tables[$table]) + 1;
			$this->tables[$table][] = $this->typed($table, $row);
			return 1;
		}

		foreach ($this->split_values($updates) as $assignment) {
			if (!preg_match('/^(\w+) = (\w+) \+ VALUES\(\1\)$/', trim($assignment), $m)) {
				continue; // `col = col` no-op (ledger) and plain overwrites are not increments.
			}
			if (preg_match("/^(\w+) = VALUES\((\w+)\)$/", trim($assignment), $ov)) {
				$this->tables[$table][$index][$ov[1]] = $row[$ov[2]];
				continue;
			}
			$col = $m[1];
			$kind = $this->numeric[$table][$col] ?? null;
			if (null === $kind) {
				$this->tables[$table][$index][$col] = $row[$col];
				continue;
			}
			$current = (float) $this->tables[$table][$index][$col];
			$delta   = (float) $row[$col];
			$this->tables[$table][$index][$col] = ('int' === $kind) ? (int) ($current + $delta) : $current + $delta;
		}

		return 2;
	}

	/** @return string[] */
	private function split_values(string $list): array
	{
		$out = array();
		$buf = '';
		$depth = 0;
		$in_string = false;
		$len = strlen($list);
		for ($i = 0; $i < $len; $i++) {
			$char = $list[$i];
			$buf .= $char;
			if ($in_string) {
				if ('\\' === $char) { $buf .= $list[++$i] ?? ''; continue; }
				if ("'" === $char) { $in_string = false; }
				continue;
			}
			if ("'" === $char) { $in_string = true; continue; }
			if ('(' === $char) { $depth++; continue; }
			if (')' === $char) { $depth--; continue; }
			if (',' === $char && 0 === $depth) {
				$out[] = substr($buf, 0, -1);
				$buf = '';
			}
		}
		$out[] = $buf;
		return array_map('trim', $out);
	}

	private function literal(string $value): mixed
	{
		if (preg_match("/^'(.*)'$/s", $value, $m)) {
			return stripslashes($m[1]);
		}
		return is_numeric($value) ? $value + 0 : $value;
	}

	private function typed(string $table, array $row): array
	{
		foreach ($this->numeric[$table] as $col => $kind) {
			if (isset($row[$col])) {
				$row[$col] = ('int' === $kind) ? (int) $row[$col] : (float) $row[$col];
			}
		}
		return $row;
	}

	private function delete_exact(string $table, string $date): int
	{
		$kept = array();
		$removed = 0;
		foreach ($this->tables[$table] as $row) {
			if ((string) $row['stat_date'] === $date) { $removed++; continue; }
			$kept[] = $row;
		}
		$this->tables[$table] = $kept;
		return $removed;
	}

	private function delete_before(string $table, string $date): int
	{
		$kept = array();
		$removed = 0;
		foreach ($this->tables[$table] as $row) {
			if ((string) $row['stat_date'] < $date) { $removed++; continue; }
			$kept[] = $row;
		}
		$this->tables[$table] = $kept;
		return $removed;
	}

	/** @return array<string,array<string,array<int,array<string,mixed>>>> */
	private function snapshot(): array
	{
		return unserialize(serialize($this->tables));
	}

	/** @param array<string,array<string,array<int,array<string,mixed>>>> $snapshot */
	private function restore(array $snapshot): void
	{
		if (array() !== $snapshot) {
			$this->tables = $snapshot;
		}
	}
}

$GLOBALS['wpdb'] = new FakeAnalyticsDb();

/* ---------------------------------------------------------------------------
 * Load the real classes under test
 * ------------------------------------------------------------------------- */

require_once __DIR__ . '/../../includes/analytics/class-adc-analytics-schema.php';
require_once __DIR__ . '/../../includes/analytics/class-adc-analytics-store.php';
require_once __DIR__ . '/../../includes/analytics/class-adc-analytics-settings.php';
require_once __DIR__ . '/../../includes/analytics/class-adc-analytics-commerce.php';
require_once __DIR__ . '/../../includes/analytics/class-adc-analytics-interactions.php';
require_once __DIR__ . '/../../includes/analytics/class-adc-analytics-reconciler.php';

/* ---------------------------------------------------------------------------
 * Harness
 * ------------------------------------------------------------------------- */

function check(bool $condition, string $message): void
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: $message\n");
		exit(1);
	}
}

/** @return array<string,mixed>|null */
function bucket(string $date, string $currency, string $status, string $metric): ?array
{
	foreach ($GLOBALS['wpdb']->tables['wp_adc_analytics_commerce_daily'] as $row) {
		if ($row['stat_date'] === $date && $row['currency'] === $currency && $row['order_status'] === $status && $row['metric'] === $metric) {
			return $row;
		}
	}
	return null;
}

/** @return array<string,mixed>|null */
function product_row(string $date, string $currency, int $product_id): ?array
{
	foreach ($GLOBALS['wpdb']->tables['wp_adc_analytics_products_daily'] as $row) {
		if ($row['stat_date'] === $date && $row['currency'] === $currency && (int) $row['product_id'] === $product_id) {
			return $row;
		}
	}
	return null;
}

function ledger_count(int $order_id, string $event_key): int
{
	foreach ($GLOBALS['wpdb']->tables['wp_adc_analytics_order_ledger'] as $row) {
		if ((int) $row['order_id'] === $order_id && $row['event_key'] === $event_key) {
			return 1;
		}
	}
	return 0;
}

function dec(float $value): float
{
	return round($value, 6);
}

const D1 = '2024-03-30'; // Saturday, before the DST switch.
const D2 = '2024-03-31';

// Fixed "now" so enqueue timestamps and today-checks are deterministic.
// wp_date is stubbed above; today() must equal D2 for rebuild tests.
ADC_Settings::$values = array(
	'enabled_commerce' => true,
	'commerce_ack'     => true,
	'paid_statuses'    => array('processing', 'completed'),
);

/* ---------------------------------------------------------------------------
 * 0. Hard-failed legal gates (Art. 21 / ePrivacy override)
 * ------------------------------------------------------------------------- */

check(!ADC_Analytics_Settings::traffic_enabled(), 'traffic gate hard false');
check(!ADC_Analytics_Settings::utm_enabled(), 'utm gate hard false');
check(!ADC_Analytics_Settings::add_to_cart_enabled(), 'add_to_cart gate hard false');
check(!ADC_Analytics_Settings::begin_checkout_enabled(), 'begin_checkout gate hard false');
check(ADC_Analytics_Interactions::begin_checkout_supported() === false, 'begin_checkout unsupported');
ADC_Settings::$values['enabled_traffic'] = true; // Stored values can never open the gate.
ADC_Settings::$values['enabled_add_to_cart'] = true;
check(!ADC_Analytics_Settings::traffic_enabled(), 'stored traffic toggle cannot open gate');
check(!ADC_Analytics_Settings::add_to_cart_enabled(), 'stored add-to-cart toggle cannot open gate');
unset(ADC_Settings::$values['enabled_traffic'], ADC_Settings::$values['enabled_add_to_cart']);

/* ---------------------------------------------------------------------------
 * 1. Gate closed -> hooks enqueue nothing; open gate -> WP-Cron fallback job
 * ------------------------------------------------------------------------- */

ADC_Settings::$values['enabled_commerce'] = false;
ADC_Analytics_Commerce::on_new_order(1);
check(array() === $GLOBALS['__adc_cron'] && array() === $GLOBALS['__adc_as'], 'disabled gate enqueues nothing');

ADC_Settings::$values['enabled_commerce'] = true; // + commerce_ack above.
FakeOrderRepo::$orders[1] = new WC_Order(1, 'RON', 'pending', 105.0, 10.0, 5.0, 1.0, 2.0, 0.0, new DateTimeImmutable('2024-03-30 08:00:00', new DateTimeZone('UTC')));
ADC_Analytics_Commerce::on_new_order(1);
check(count($GLOBALS['__adc_cron']) === 1, 'WP-Cron fallback job scheduled (no ActionScheduler in this process)');
$job = $GLOBALS['__adc_cron'][0];
check('adc_analytics_record' === $job['hook'], 'job hook name');
check(array('order_created', 1, 0, '', 0) === $job['args'], 'job args carry event/order/refund/date/attempt');

/* ---------------------------------------------------------------------------
 * 2. order_created applied once; duplicate replay is an idempotent no-op
 * ------------------------------------------------------------------------- */

ADC_Analytics_Commerce::process('order_created', 1);
check(1 === ledger_count(1, 'order_created'), 'ledger row claimed');
$row = bucket(D1, 'RON', '', 'orders_created');
check(null !== $row && 1 === (int) $row['count'], 'orders_created counted on site-local date');

ADC_Analytics_Commerce::process('order_created', 1);
$row = bucket(D1, 'RON', '', 'orders_created');
check(null !== $row && 1 === (int) $row['count'], 'duplicate job does not double-count');
check($GLOBALS['wpdb']->rollbacks === 0, 'duplicate path commits (no rollback churn) [last=' . $GLOBALS['wpdb']->last . ']');

/* ---------------------------------------------------------------------------
 * 3. payment_complete before the status transition (D14): soft skip, then
 *    the transition applies the paid event exactly once
 * ------------------------------------------------------------------------- */

FakeOrderRepo::$orders[2] = new WC_Order(2, 'RON', 'pending', 50.0, 8.0, 0.0, 0.0, 0.0, 0.0, new DateTimeImmutable('2024-03-30 09:00:00', new DateTimeZone('UTC')));
FakeOrderRepo::$orders[2]->items = array(new FakeWCItem(8, 0, 1, 42.0, 8.0));
ADC_Analytics_Commerce::process('order_paid', 2);
check(0 === ledger_count(2, 'order_paid'), 'unpaid order soft-skips without claiming');
check(null === bucket(D1, 'RON', '', 'orders_paid'), 'unpaid order writes no aggregate');

// Gateway succeeds: date paid set, status still pending (async payment), then
// the transition lands. Both hook paths enqueue; the ledger dedupes.
FakeOrderRepo::$orders[2]->paid = new DateTimeImmutable('2024-03-30 21:30:00', new DateTimeZone('UTC')); // 23:30 RO
FakeOrderRepo::$orders[2]->status = 'processing';
FakeOrderRepo::$orders[2]->modified = new DateTimeImmutable('2024-03-30 21:31:00', new DateTimeZone('UTC'));
ADC_Analytics_Commerce::on_payment_complete(2);
ADC_Analytics_Commerce::on_status_changed(2, 'pending', 'processing');
ADC_Analytics_Commerce::process('order_paid', 2);
ADC_Analytics_Commerce::process('order_paid', 2); // Hook replay.

check(1 === ledger_count(2, 'order_paid'), 'order_paid claimed exactly once');
$row = bucket(D1, 'RON', '', 'orders_paid');
check(null !== $row && 1 === (int) $row['count'], 'orders_paid counted once');
$row = bucket(D1, 'RON', '', 'order_total');
check(null !== $row && dec(50.0) === dec((float) $row['amount']), 'gross order_total stored');
$row = bucket(D1, 'RON', '', 'tax_total');
check(null !== $row && dec(8.0) === dec((float) $row['amount']), 'tax_total stored');
$row = bucket(D1, 'RON', '', 'items_net');
check(null !== $row && dec(42.0) === dec((float) $row['amount']), 'items_net from lines');
$row = bucket(D1, 'RON', '', 'units');
check(null !== $row && 1 === (int) $row['count'], 'units counted');
$prod = product_row(D1, 'RON', 8);
check(null !== $prod && 1 === (int) $prod['units'] && dec(42.0) === dec((float) $prod['items_net']), 'product row from order lines');

// Re-entry into a paid status never counts again.
FakeOrderRepo::$orders[2]->status = 'completed';
ADC_Analytics_Commerce::on_status_changed(2, 'processing', 'completed');
ADC_Analytics_Commerce::process('order_paid', 2);
$row = bucket(D1, 'RON', '', 'orders_paid');
check(1 === (int) $row['count'], 're-entry into paid status does not double-count');

/* ---------------------------------------------------------------------------
 * 4. status_reached: first reach per status, own bucket, no double count
 * ------------------------------------------------------------------------- */

ADC_Analytics_Commerce::process('status_reached:processing', 2, 0, D1);
ADC_Analytics_Commerce::process('status_reached:processing', 2, 0, D1);
$row = bucket(D1, 'RON', 'processing', 'status_reached');
check(null !== $row && 1 === (int) $row['count'], 'status_reached counted once (event fact)');
ADC_Analytics_Commerce::process('status_reached:completed', 2, 0, D1);
check(null !== bucket(D1, 'RON', 'completed', 'status_reached'), 'next status is its own bucket');
check(0 === ledger_count(2, 'status_reached:on-hold'), 'unreached status has no ledger row');

/* ---------------------------------------------------------------------------
 * 5. Refunds: partial accumulates, replay idempotent, full refund composition
 * ------------------------------------------------------------------------- */

FakeOrderRepo::$refunds[10] = new WC_Order_Refund(10, 1, -50.5, new DateTimeImmutable('2024-03-31 10:00:00', new DateTimeZone('UTC')));
FakeOrderRepo::$refunds[10]->items = array(new FakeWCItem(7, 0, -1, -30.0, -5.0));
ADC_Analytics_Commerce::on_refunded(1, 10);
ADC_Analytics_Commerce::process('refund:10', 1, 10);
check(1 === ledger_count(1, 'refund:10'), 'refund ledger key claimed');
$row = bucket(D2, 'RON', '', 'refund_total');
check(null !== $row && dec(50.5) === dec((float) $row['amount']), 'refund_total absolute on refund date');
$prod = product_row(D2, 'RON', 7);
check(null !== $prod, 'refund product row exists');
check(1 === (int) $prod['refund_units'], 'refund units absolute (got ' . $prod['refund_units'] . ')');
check(dec(30.0) === dec((float) $prod['refund_net']), 'refund net absolute (got ' . $prod['refund_net'] . ')');
check(dec(5.0) === dec((float) $prod['refund_tax']), 'refund tax absolute (got ' . $prod['refund_tax'] . ')');
$paid_row = bucket(D1, 'RON', '', 'orders_paid');
check(1 === (int) $paid_row['count'], 'refund does not decrement paid count');

ADC_Analytics_Commerce::process('refund:10', 1, 10);
$row = bucket(D2, 'RON', '', 'refund_total');
check(dec(50.5) === dec((float) $row['amount']), 'refund replay does not double-count');

FakeOrderRepo::$refunds[11] = new WC_Order_Refund(11, 1, -10.25, new DateTimeImmutable('2024-03-31 11:00:00', new DateTimeZone('UTC')));
ADC_Analytics_Commerce::process('refund:11', 1, 11);
$row = bucket(D2, 'RON', '', 'refund_total');
check(dec(60.75) === dec((float) $row['amount']), 'partial refunds accumulate (got ' . $row['amount'] . ')');

/* ---------------------------------------------------------------------------
 * 6. Transactional failure: rollback reverts the claim; retry applies once
 * ------------------------------------------------------------------------- */

$GLOBALS['wpdb']->fail_when = static fn(string $sql): bool => str_contains($sql, 'adc_analytics_commerce_daily');
$GLOBALS['__adc_cron'] = array();
ADC_Analytics_Commerce::process('order_created', 2); // ledger claim succeeds, aggregate write fails.
check($GLOBALS['wpdb']->rollbacks >= 1, 'aggregate failure rolled the transaction back');
check(0 === ledger_count(2, 'order_created'), 'rolled-back claim did not persist (retry cannot lose or orphan it)');
check(count($GLOBALS['__adc_cron']) === 1 && 1 === (int) $GLOBALS['__adc_cron'][0]['args'][4], 'failed job re-enqueued with attempt+1');
$GLOBALS['wpdb']->fail_when = null;

// Replay the exact requeued job: applies exactly once.
$retry = $GLOBALS['__adc_cron'][0]['args'];
ADC_Analytics_Commerce::process($retry[0], (int) $retry[1], (int) $retry[2], (string) $retry[3], (int) $retry[4]);
$row = bucket(D1, 'RON', '', 'orders_created');
check(null !== $row && 2 === (int) $row['count'], 'retry after failure applied once (order 2 created counted)');
check(1 === ledger_count(2, 'order_created'), 'retry claimed the ledger');

/* ---------------------------------------------------------------------------
 * 7. Multi-currency: buckets stay separate, never summed
 * ------------------------------------------------------------------------- */

FakeOrderRepo::$orders[3] = new WC_Order(3, 'EUR', 'processing', 200.0, 33.3, 0.0, 0.0, 0.0, 0.0, new DateTimeImmutable('2024-03-30 12:00:00', new DateTimeZone('UTC')), new DateTimeImmutable('2024-03-30 12:05:00', new DateTimeZone('UTC')));
ADC_Analytics_Commerce::process('order_created', 3);
ADC_Analytics_Commerce::process('order_paid', 3);
$row = bucket(D1, 'EUR', '', 'orders_paid');
check(null !== $row && 1 === (int) $row['count'], 'EUR paid bucket exists');
check(dec(200.0) === dec((float) bucket(D1, 'EUR', '', 'order_total')['amount']), 'EUR total stored in its own bucket');
check(dec(50.0) === dec((float) bucket(D1, 'RON', '', 'order_total')['amount']), 'RON total unchanged by EUR order');

/* ---------------------------------------------------------------------------
 * 8. Timezone: UTC-before-midnight lands on the site-local day (DST window)
 * ------------------------------------------------------------------------- */

FakeOrderRepo::$orders[4] = new WC_Order(4, 'RON', 'processing', 70.0, 0.0, 0.0, 0.0, 0.0, 0.0, new DateTimeImmutable('2024-03-30 22:30:00', new DateTimeZone('UTC'))); // 2024-03-31 00:30 in Bucharest (UTC+2)
ADC_Analytics_Commerce::process('order_created', 4);
check(null !== bucket(D2, 'RON', '', 'orders_created'), 'order created at 22:30 UTC is counted on 2024-03-31 local');

/* ---------------------------------------------------------------------------
 * 8b. Blocks drafts (B4): abandoned carts never count; placement counts once
 * ------------------------------------------------------------------------- */

$draft = new WC_Order(6, 'RON', 'checkout-draft', 88.0, 0.0, 0.0, 0.0, 0.0, 0.0, new DateTimeImmutable('2024-03-31 09:00:00', new DateTimeZone('UTC')));
FakeOrderRepo::$orders[6] = $draft;
$GLOBALS['__adc_cron'] = array();
ADC_Analytics_Commerce::on_new_order(6);
check(array() === $GLOBALS['__adc_cron'], 'checkout-draft on_new_order enqueues nothing (B4: no abandoned-cart orders_created)');
ADC_Analytics_Commerce::on_status_changed(6, 'auto-draft', 'checkout-draft');
check(array() === $GLOBALS['__adc_cron'], 'draft-side transitions are not facts');

// The customer finishes checkout on the SAME draft order (Blocks lifecycle).
$draft->status = 'pending';
$draft->modified = new DateTimeImmutable('2024-03-31 09:05:00', new DateTimeZone('UTC'));
ADC_Analytics_Commerce::on_status_changed(6, 'checkout-draft', 'pending');
check(2 === count($GLOBALS['__adc_cron']), 'placement enqueues order_created + status_reached (got ' . count($GLOBALS['__adc_cron']) . ')');
ADC_Analytics_Commerce::process('order_created', 6);
$row = bucket(D2, 'RON', '', 'orders_created');
check(null !== $row && 2 === (int) $row['count'], 'eventual purchase counted as created exactly once (order 4 + order 6)');
ADC_Analytics_Commerce::process('status_reached:pending', 6, 0, D2);
$row = bucket(D2, 'RON', 'pending', 'status_reached');
check(null !== $row && 1 === (int) $row['count'], 'first placed status is a fact');

// B1 regression: the invalid {after,before} array form must be rejected by a
// strict wc_get_orders emulation (real WC: TypeError in both stores).
$rejected = false;
try {
	wc_get_orders(array('type' => 'shop_order', 'date_created' => array('after' => '2024-03-30 00:00:00', 'before' => '2024-03-30 23:59:59')));
} catch (TypeError) {
	$rejected = true;
}
check($rejected, 'array date filter is rejected (cannot regress to the crashing form)');

// B1 regression: the documented timestamp-range form filters by absolute epoch.
$window_probe = wc_get_orders(array('type' => 'shop_order', 'status' => 'any', 'date_created' => '1711788000...1711874399', 'limit' => 100));
check(array() !== $window_probe, 'documented range form returns orders (day window in absolute timestamps)');

/* ---------------------------------------------------------------------------
 * 9. Decimal precision: no float drift in the stored bucket
 * ------------------------------------------------------------------------- */

FakeOrderRepo::$orders[5] = new WC_Order(5, 'RON', 'processing', 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, new DateTimeImmutable('2024-03-30 13:00:00', new DateTimeZone('UTC')), new DateTimeImmutable('2024-03-30 13:01:00', new DateTimeZone('UTC')));
FakeOrderRepo::$orders[5]->items = array(new FakeWCItem(9, 0, 1, 0.1, 0.2), new FakeWCItem(9, 0, 1, 0.2, 0.1));
FakeOrderRepo::$orders[5]->total = 0.6;
ADC_Analytics_Commerce::process('order_paid', 5);
$prod = product_row(D1, 'RON', 9);
check(dec(0.3) === dec((float) $prod['items_net']) && dec(0.3) === dec((float) $prod['items_tax']), '0.1 + 0.2 stored as exact 0.3 (6-decimal rounding)');

/* ---------------------------------------------------------------------------
 * 10. Reconciler: compare detects loss, rebuild repairs atomically and
 *     preserves status facts + traffic; late replays stay deduped
 * ------------------------------------------------------------------------- */

// Existing day state: orders 1,2 (RON), 3 (EUR), refund rows, status facts.
// Simulate a job crash: aggregates for D1 vanish (ledger rows remain).
$GLOBALS['wpdb']->tables['wp_adc_analytics_commerce_daily'] = array_values(array_filter(
	$GLOBALS['wpdb']->tables['wp_adc_analytics_commerce_daily'],
	static fn(array $row): bool => $row['stat_date'] !== D1
));
$GLOBALS['wpdb']->tables['wp_adc_analytics_products_daily'] = array_values(array_filter(
	$GLOBALS['wpdb']->tables['wp_adc_analytics_products_daily'],
	static fn(array $row): bool => $row['stat_date'] !== D1
));

// Traffic and interactions for D1 must survive a rebuild untouched.
$GLOBALS['wpdb']->tables['wp_adc_analytics_traffic'][] = array('id' => 1, 'stat_date' => D1, 'stat_hour' => 9, 'channel' => 'search', 'page_type' => 'product', 'object_id' => 7, 'views' => 12, 'updated_at' => '2024-03-30 09:00:00');
$GLOBALS['wpdb']->tables['wp_adc_analytics_interactions'][] = array('id' => 1, 'stat_date' => D1, 'event_name' => 'add_to_cart', 'count' => 3, 'updated_at' => '2024-03-30 09:00:00');

$diff = ADC_Analytics_Reconciler::compare_day(D1, 'RON');
check(abs($diff['orders_paid'] - (-2.0)) < 1e-9, 'compare: paid aggregates missing (actual - expected < 0), got ' . $diff['orders_paid']);
check(abs($diff['order_total'] - (-50.6)) < 1e-9, 'compare: gross missing (50 + 0.6), got ' . $diff['order_total']);
check(abs($diff['refund_total']) < 1e-9, 'compare: refund bucket still present on D2, D1 untouched by refunds');

$report = ADC_Analytics_Reconciler::rebuild_day(D1);
check(true === $report['ok'], 'rebuild succeeded: ' . $report['reason']);
check(abs(($report['diff']['RON/orders_paid'] ?? 99) - (-2.0)) < 1e-9 && abs(($report['diff']['EUR/orders_paid'] ?? 99) - (-1.0)) < 1e-9,
	'rebuild diff report = previous (lost) minus recomputed, got ' . json_encode($report['diff']));
$row = bucket(D1, 'RON', '', 'orders_paid');
check(null !== $row && 2 === (int) $row['count'], 'rebuild restored orders_paid (orders 2 and 5)');
$row = bucket(D1, 'RON', '', 'order_total');
check(dec(50.6) === dec((float) $row['amount']), 'rebuild restored gross (orders 2 + 5 paid on D1)');
$row = bucket(D1, 'EUR', '', 'orders_paid');
check(null !== $row && 1 === (int) $row['count'], 'rebuild restored EUR bucket');
$row = bucket(D1, 'RON', '', 'refund_total');
check(null === $row, 'rebuild did not fabricate refund rows on D1 (refunds are D2 facts)');
$prod = product_row(D1, 'RON', 8);
check(null !== $prod && 1 === (int) $prod['units'], 'rebuild restored product rows');
check(12 === (int) $GLOBALS['wpdb']->tables['wp_adc_analytics_traffic'][0]['views'], 'traffic row untouched by rebuild');
check(3 === (int) $GLOBALS['wpdb']->tables['wp_adc_analytics_interactions'][0]['count'], 'interaction row untouched by rebuild');

$diff = ADC_Analytics_Reconciler::compare_day(D1, 'RON');
foreach ($diff as $metric => $value) {
	check(abs($value) < 1e-9, "compare after rebuild: $metric diff is zero (got $value)");
}
$diff = ADC_Analytics_Reconciler::compare_day(D1, 'EUR');
check(abs($diff['orders_paid']) < 1e-9 && abs($diff['order_total']) < 1e-9, 'compare after rebuild (EUR): zero diff');

// Late job replay after rebuild: ledger fencing keeps it deduped.
ADC_Analytics_Commerce::process('order_created', 1);
$row = bucket(D1, 'RON', '', 'orders_created');
check(null !== $row && 3 === (int) $row['count'], 'rebuild counted created once; replay still deduped (not 4)');

// Rebuild preserves status facts without recomputing them from order state.
$GLOBALS['wpdb']->tables['wp_adc_analytics_commerce_daily'][] = array('id' => 99, 'stat_date' => D1, 'currency' => 'RON', 'order_status' => 'cancelled', 'metric' => 'status_reached', 'amount' => 0.0, 'count' => 2, 'updated_at' => '2024-03-30 10:00:00');
$report = ADC_Analytics_Reconciler::rebuild_day(D1);
$row = bucket(D1, 'RON', 'cancelled', 'status_reached');
check(null !== $row && 2 === (int) $row['count'] && true === $report['ok'], 'status_reached rows preserved verbatim across rebuild');

// Refund day rebuild: D2 refund rows recomputed from refund objects.
$report = ADC_Analytics_Reconciler::rebuild_day(D2);
check(true === $report['ok'], 'D2 rebuild ok');
$row = bucket(D2, 'RON', '', 'refund_total');
check(null !== $row && dec(60.75) === dec((float) $row['amount']), 'D2 refunds recomputed identically');
$prod = product_row(D2, 'RON', 7);
check(null !== $prod && 1 === (int) $prod['refund_units'], 'D2 refund product rows recomputed');

// B4 rebuild side: an abandoned checkout-draft on the same day is never materialized.
$abandoned = new WC_Order(7, 'RON', 'checkout-draft', 55.0, 0.0, 0.0, 0.0, 0.0, 0.0, new DateTimeImmutable('2024-03-31 10:00:00', new DateTimeZone('UTC')));
FakeOrderRepo::$orders[7] = $abandoned;
$report = ADC_Analytics_Reconciler::rebuild_day(D2);
check(true === $report['ok'], 'D2 rebuild with a draft present still ok');
$row = bucket(D2, 'RON', '', 'orders_created');
check(null !== $row && 2 === (int) $row['count'], 'rebuild materializes only placed orders (draft excluded, got ' . (string) ($row['count'] ?? 'null') . ')');
check(0 === ledger_count(2, 'order_paid') - 1, 'ledger still holds exactly one paid row for order 2');

/* ---------------------------------------------------------------------------
 * 11. Day lock serializes job vs rebuild; refusal aborts without deleting
 * ------------------------------------------------------------------------- */

$GLOBALS['wpdb']->locks['wp_adc_analytics_day_' . D1] = true; // Simulate a live job holding the day.
$report = ADC_Analytics_Reconciler::rebuild_day(D1);
check(false === $report['ok'] && 'transaction-failed' === $report['reason'], 'rebuild aborts while a live job holds the day lock');
$row = bucket(D1, 'RON', '', 'orders_paid');
check(null !== $row && 2 === (int) $row['count'], 'aborted rebuild deleted nothing');
unset($GLOBALS['wpdb']->locks['wp_adc_analytics_day_' . D1]);

// A job under contention also fails safely and requeues.
$GLOBALS['wpdb']->locks['wp_adc_analytics_day_' . D2] = true;
$GLOBALS['__adc_cron'] = array();
FakeOrderRepo::$refunds[12] = new WC_Order_Refund(12, 1, -1.0, new DateTimeImmutable('2024-03-31 12:00:00', new DateTimeZone('UTC')));
ADC_Analytics_Commerce::process('refund:12', 1, 12);
check(0 === ledger_count(1, 'refund:12'), 'job under lock refusal did not claim');
check(count($GLOBALS['__adc_cron']) === 1, 'job under lock refusal requeued');
unset($GLOBALS['wpdb']->locks['wp_adc_analytics_day_' . D2]);
$retry = $GLOBALS['__adc_cron'][0]['args'];
ADC_Analytics_Commerce::process($retry[0], (int) $retry[1], (int) $retry[2], (string) $retry[3], (int) $retry[4]);
$row = bucket(D2, 'RON', '', 'refund_total');
check(dec(61.75) === dec((float) $row['amount']), 'requeued job applied once after the lock cleared');

/* ---------------------------------------------------------------------------
 * 12. Rebuild guards: truncated day, future date, beyond retention, closed gate
 * ------------------------------------------------------------------------- */

// Overflow: 2001 created orders must refuse the rebuild without deleting.
$overflow = array();
for ($i = 100; $i <= 2100; $i++) {
	$order = new WC_Order($i, 'RON', 'pending', 1.0, 0.0, 0.0, 0.0, 0.0, 0.0, new DateTimeImmutable('2024-03-30 12:00:00', new DateTimeZone('UTC'))); // 14:00 local
	$overflow[$i] = $order;
}
FakeOrderRepo::$orders += $overflow;
$report = ADC_Analytics_Reconciler::rebuild_day(D1);
check(false === $report['ok'] && true === $report['truncated'] && 'too-many-orders' === $report['reason'], 'overflow refuses the rebuild');
check(2 === (int) bucket(D1, 'RON', '', 'orders_paid')['count'], 'refused rebuild deleted nothing');
foreach (array_keys($overflow) as $id) {
	unset(FakeOrderRepo::$orders[$id]);
}

$report = ADC_Analytics_Reconciler::rebuild_day('2099-01-01');
check(false === $report['ok'] && 'invalid-date' === $report['reason'], 'future date refused');
$report = ADC_Analytics_Reconciler::rebuild_day('2000-01-01');
check(false === $report['ok'] && 'beyond-retention' === $report['reason'], 'beyond-retention date refused');
ADC_Settings::$values['enabled_commerce'] = false;
$report = ADC_Analytics_Reconciler::rebuild_day(D1);
check(false === $report['ok'] && 'commerce-disabled' === $report['reason'], 'rebuild refused when commerce is off');
ADC_Settings::$values['enabled_commerce'] = true;

$report = ADC_Analytics_Reconciler::rebuild_range(D1, D2);
check(true === $report['ok'] && 2 === count($report['days']), 'bounded range rebuild ok');
$report = ADC_Analytics_Reconciler::rebuild_range('2024-01-01', '2024-06-01');
check(false === $report['ok'] && 'range-too-long' === $report['reason'], 'range capped at 31 days');

/* ---------------------------------------------------------------------------
 * 13. No order_id ever lands in aggregates; currency/date gates hold
 * ------------------------------------------------------------------------- */

foreach ($GLOBALS['wpdb']->tables['wp_adc_analytics_commerce_daily'] as $row) {
	check(!array_key_exists('order_id', $row), 'no order_id column in commerce aggregates');
}
foreach ($GLOBALS['wpdb']->tables['wp_adc_analytics_products_daily'] as $row) {
	check(!array_key_exists('order_id', $row), 'no order_id column in product aggregates');
}

ADC_Analytics_Commerce::process('order_paid', 999); // Missing order: soft skip, no throw.
check(0 === ledger_count(999, 'order_paid'), 'missing order never claims');

check(!ADC_Analytics_Commerce::is_paid_status('pending'), 'pending is not paid');
check(ADC_Analytics_Commerce::is_paid_status('wc-processing'), 'wc-prefixed paid status normalized');

/* ---------------------------------------------------------------------------
 * 14. After-commit hook: runs only on commit, never on rollback
 * ------------------------------------------------------------------------- */

$committed = false;
$rolled = false;
check(true === ADC_Analytics_Store::transaction(static function (): bool { return true; }, static function () use (&$committed): void { $committed = true; }), 'outer transaction commits');
check(true === $committed, 'after-commit ran after COMMIT');
check(false === ADC_Analytics_Store::transaction(static function (): bool { return false; }, static function () use (&$rolled): void { $rolled = true; }), 'callback false rolls back');
check(false === $rolled, 'after-commit skipped on rollback');

/* ---------------------------------------------------------------------------
 * 15. Action Scheduler path: unique job in the adc-analytics group
 * ------------------------------------------------------------------------- */

// Runtime definitions (eval) so the earlier tests exercised the WP-Cron fallback.
eval(<<<'PHP'
class ActionScheduler {}
function as_schedule_unique_action(int $ts, string $hook, array $args, string $group): int {
	$GLOBALS['__adc_as'][] = array('ts' => $ts, 'hook' => $hook, 'args' => $args, 'group' => $group, 'unique' => true);
	return 42;
}
function as_schedule_single_action(int $ts, string $hook, array $args, string $group): int {
	$GLOBALS['__adc_as'][] = array('ts' => $ts, 'hook' => $hook, 'args' => $args, 'group' => $group, 'unique' => false);
	return 43;
}
PHP);
$GLOBALS['__adc_as'] = array();
FakeOrderRepo::$refunds[13] = new WC_Order_Refund(13, 1, -2.0, new DateTimeImmutable('2024-03-31 13:00:00', new DateTimeZone('UTC')));
ADC_Analytics_Commerce::on_refunded(1, 13);
check(1 === count($GLOBALS['__adc_as']) && 'adc-analytics' === $GLOBALS['__adc_as'][0]['group'] && true === $GLOBALS['__adc_as'][0]['unique'], 'AS unique action in adc-analytics group');
check(array('refund:13', 1, 13, '', 0) === $GLOBALS['__adc_as'][0]['args'], 'AS job args');
ADC_Analytics_Commerce::process('refund:13', 1, 13);
$row = bucket(D2, 'RON', '', 'refund_total');
check(dec(63.75) === dec((float) $row['amount']), 'AS-path refund applied once');

/* ---------------------------------------------------------------------------
 * 16. Store gates: invalid data never writes; interactions still inert
 * ------------------------------------------------------------------------- */

check(!ADC_Analytics_Store::apply_commerce_metric(D1, 'USD', "'; drop table x", 'status_reached', 0.0, 1), 'order_status charset enforced (no injection)');
check(!ADC_Analytics_Store::claim_ledger(1, 'drop table x', '', 'RON', 0.0, D1, '2024-03-30 00:00:00'), 'event key charset enforced');
check(!ADC_Analytics_Store::claim_ledger(1, 'refund:0', '', 'RON', 0.0, D1, '2024-03-30 00:00:00'), 'refund:0 rejected');
check(!ADC_Analytics_Store::increment_interaction(D1, 'purchase', 1), 'interaction allowlist enforced');
ADC_Analytics_Interactions::on_add_to_cart('k1', 7, 1);
ADC_Analytics_Interactions::on_store_api_add_to_cart(null, 'k1');
check(3 === (int) $GLOBALS['wpdb']->tables['wp_adc_analytics_interactions'][0]['count'] && 1 === count($GLOBALS['wpdb']->tables['wp_adc_analytics_interactions']), 'interaction hooks stay inert with gates closed (only the seeded D1 row remains)');

echo "commerce-test: PASS (" . $GLOBALS['wpdb']->transactions . " transactions, " . $GLOBALS['wpdb']->rollbacks . " rollbacks)\n";
