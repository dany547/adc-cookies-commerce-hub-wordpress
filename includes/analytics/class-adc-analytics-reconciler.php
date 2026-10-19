<?php
/**
 * Bounded day reconciliation for commerce aggregates.
 *
 * The aggregates are a derived cache of the WooCommerce orders (source of
 * truth). A job crash between ledger and aggregate (or a dropped retry) leaves
 * a stale day; the reconciler recomputes one day from WC CRUD orders and
 * replaces the aggregates for that day.
 *
 * Safety protocol (identical locking as live jobs):
 * - Writes run inside one ADC_Analytics_Store::transaction (atomic replace:
 *   readers see the old or the new day, never a mix of dates).
 * - The transaction body first acquires the per-day MySQL lock
 *   (ADC_Analytics_Store::lock_day) that every live job also holds until after
 *   its commit, so a rebuild can never interleave with a live job into a
 *   double count. Lock refusal aborts the rebuild without deleting anything.
 * - `status_reached` rows are event facts (§8.8) and are NOT rebuilt from
 *   current order state; they are re-read under the lock and preserved verbatim.
 * - Every materialized event also claims its dedupe ledger row inside the same
 *   transaction, fencing late job replays against double application.
 * - Only `commerce_daily` and `products_daily` rows are replaced
 *   (ADC_Analytics_Store::delete_day); traffic and interactions are untouched.
 * - Every WC query is bounded (MAX_ORDERS per query); overflow refuses the
 *   rebuild instead of silently replacing a day with partial values.
 *
 * `rebuild_*` never runs when commerce is disabled, for future dates, or for
 * days older than the ledger retention (their ledger rows may already be
 * purged, so replay fencing could not be materialized).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Day-level rebuild and read-only comparison of commerce aggregates.
 */
final class ADC_Analytics_Reconciler
{
	/** Maximum orders/refunds fetched per bounded query. */
	private const MAX_ORDERS = 2000;

	/** Maximum days per rebuild_range call (mirrors the dashboard limit). */
	public const MAX_RANGE_DAYS = 31;

	/** Commerce metrics rebuilt from orders (order_status column always ''). */
	private const FINANCIAL_METRICS = array(
		'order_total',
		'tax_total',
		'shipping_total',
		'shipping_tax',
		'discount_total',
		'discount_tax',
		'items_net',
		'items_tax',
	);

	/**
	 * Recompute one day from WooCommerce orders and atomically replace its
	 * commerce/product aggregates.
	 *
	 * @param  string $stat_date Y-m-d site-local date.
	 * @return array{date:string,orders:int,products:int,diff:array<string,float>,ok:bool,truncated:bool,reason:string}
	 */
	public static function rebuild_day(string $stat_date): array
	{
		$result = array(
			'date'      => $stat_date,
			'orders'    => 0,
			'products'  => 0,
			'diff'      => array(),
			'ok'        => false,
			'truncated' => false,
			'reason'    => '',
		);

		if (!ADC_Analytics_Settings::commerce_enabled()) {
			$result['reason'] = 'commerce-disabled';
			return $result;
		}
		if (!self::valid_date($stat_date) || $stat_date > self::today()) {
			$result['reason'] = 'invalid-date';
			return $result;
		}
		if ($stat_date < self::oldest_rebuildable_date()) {
			$result['reason'] = 'beyond-retention';
			return $result;
		}

		try {
			$facts = self::day_facts($stat_date);
		} catch (Throwable $error) {
			$result['reason'] = 'orders-query-failed';
			return $result;
		}

		if ($facts['truncated']) {
			$result['reason']    = 'too-many-orders';
			$result['truncated'] = true;
			return $result; // Refuse instead of silently writing partial values.
		}

		$computed = self::compute_buckets($facts);

		$lock     = null;
		$previous = array();
		$ok       = ADC_Analytics_Store::transaction(
			static function () use ($stat_date, $computed, &$previous, &$lock): bool {
				if (!ADC_Analytics_Store::lock_day($stat_date)) {
					return false; // A live job holds the day: abort, nothing deleted.
				}
				$lock = $stat_date;

				// Event facts must survive the replace; re-read under the lock.
				$previous = ADC_Analytics_Store::day_rows($stat_date);

				// Commerce + products only; traffic/interactions never touched.
				ADC_Analytics_Store::delete_day($stat_date);

				foreach ($previous as $row) {
					if (('status_reached' === (string) ($row['metric'] ?? '')) && !ADC_Analytics_Store::apply_commerce_metric(
						$stat_date,
						(string) ($row['currency'] ?? ''),
						(string) ($row['order_status'] ?? ''),
						'status_reached',
						(float) ($row['amount'] ?? 0),
						(int) ($row['count'] ?? 0)
					)) {
						return false;
					}
				}

				foreach ($computed['commerce'] as $currency => $by_status) {
					foreach ($by_status as $status => $metrics) {
						foreach ($metrics as $metric => $value) {
							if (!ADC_Analytics_Store::apply_commerce_metric($stat_date, $currency, $status, $metric, (float) $value['amount'], (int) $value['count'])) {
								return false;
							}
						}
					}
				}
				foreach ($computed['products'] as $currency => $by_product) {
					foreach ($by_product as $product_id => $metrics) {
						if (!ADC_Analytics_Store::apply_product_metric($stat_date, $currency, (int) $product_id, $metrics)) {
							return false;
						}
					}
				}

				// Fence every materialized event in the dedupe ledger so a late
				// job replay cannot apply it a second time. Duplicate claims
				// (job already applied) are expected and ignored; the computed
				// values came from the same orders the job read.
				foreach ($computed['events'] as $event) {
					ADC_Analytics_Store::claim_ledger(
						(int) $event['order_id'],
						(string) $event['event_key'],
						(string) $event['status'],
						(string) $event['currency'],
						(float) $event['amount'],
						$stat_date,
						(string) $event['occurred_at']
					);
				}

				return true;
			},
			static function () use (&$lock): void {
				if (null !== $lock) {
					ADC_Analytics_Store::unlock_day($lock);
					$lock = null;
				}
			}
		);

		if (null !== $lock) { // Rollback/exception path: release what we hold.
			ADC_Analytics_Store::unlock_day($lock);
			$lock = null;
		}

		if (!$ok) {
			$result['reason'] = 'transaction-failed';
			return $result;
		}

		$result['ok']       = true;
		$result['orders']   = count($facts['created']) + count($facts['paid']) + count($facts['refunds']);
		$result['products'] = count($computed['product_ids']);
		$result['diff']     = self::diff_against_previous($previous, $computed['commerce']);

		return $result;
	}

	/**
	 * Rebuild a bounded range, one day at a time (max 31 days).
	 *
	 * @param  string $from Y-m-d.
	 * @param  string $to   Y-m-d.
	 * @return array{from:string,to:string,ok:bool,days:array<int,array<string,mixed>>,reason:string}
	 */
	public static function rebuild_range(string $from, string $to): array
	{
		$result = array('from' => $from, 'to' => $to, 'ok' => false, 'days' => array(), 'reason' => '');

		if (!self::valid_date($from) || !self::valid_date($to) || $from > $to) {
			$result['reason'] = 'invalid-range';
			return $result;
		}

		$days = array();
		try {
			$cursor = new DateTimeImmutable($from);
			$end    = new DateTimeImmutable($to);
		} catch (Throwable) {
			$result['reason'] = 'invalid-range';
			return $result;
		}
		while ($cursor <= $end) {
			$days[] = $cursor->format('Y-m-d');
			$cursor = $cursor->modify('+1 day');
		}

		if (count($days) > self::MAX_RANGE_DAYS) {
			$result['reason'] = 'range-too-long';
			return $result;
		}

		$ok = true;
		foreach ($days as $day) {
			$day_result       = self::rebuild_day($day);
			$result['days'][] = $day_result;
			$ok               = $ok && (bool) $day_result['ok'];
		}

		$result['ok'] = $ok;
		return $result;
	}

	/**
	 * Read-only comparison for one day and currency: actual aggregates minus
	 * values recomputed from orders (no writes). Compares against the CURRENT
	 * paid-status setting — a diverging diff after a setting change is expected
	 * and reported, not hidden.
	 *
	 * @param  string $stat_date Y-m-d.
	 * @param  string $currency  Uppercase ISO code.
	 * @return array<string,float> metric => aggregate_minus_orders
	 * @throws RuntimeException When the gate is closed, arguments are invalid, the
	 *                          queries fail, or the day is truncated (never fabricates).
	 */
	public static function compare_day(string $stat_date, string $currency): array
	{
		if (!ADC_Analytics_Settings::commerce_enabled()) {
			throw new RuntimeException('Commerce reporting is disabled.');
		}
		if (!self::valid_date($stat_date) || !preg_match('/^[A-Z]{3}\z/', strtoupper($currency))) {
			throw new RuntimeException('Invalid reconciliation arguments.');
		}

		$currency = strtoupper($currency);

		try {
			$facts = self::day_facts($stat_date);
		} catch (Throwable $error) {
			throw new RuntimeException('Order queries failed: ' . $error->getMessage(), 0, $error);
		}
		if ($facts['truncated']) {
			throw new RuntimeException('Day has too many orders to compare safely.');
		}

		$expected = array_fill_keys(array('orders_created', 'orders_paid', 'refund_total', 'units'), 0.0);
		$expected = array_merge($expected, array_fill_keys(self::FINANCIAL_METRICS, 0.0));

		foreach ($facts['created'] as $order) {
			if ($currency === self::order_currency($order)) {
				$expected['orders_created'] += 1;
			}
		}
		foreach ($facts['paid'] as $order) {
			if ($currency !== self::order_currency($order)) {
				continue;
			}
			$expected['orders_paid'] += 1;
			foreach (ADC_Analytics_Commerce::order_metrics($order) as $metric => $value) {
				if (array_key_exists($metric, $expected)) {
					$expected[$metric] += (float) $value;
				}
			}
		}
		foreach ($facts['refunds'] as $refund) {
			$parent_id = (int) $refund->get_parent_id();
			if ($parent_id < 1) {
				continue;
			}
			$parent = wc_get_order($parent_id);
			if (!$parent instanceof WC_Order || $currency !== self::order_currency($parent)) {
				continue;
			}
			$refund_metrics = ADC_Analytics_Commerce::refund_metrics($parent, $refund);
			$expected['refund_total'] += (float) $refund_metrics['order']['refund_total'];
		}

		// Actual aggregates (metric names carry the semantics; status_reached
		// rows are facts and never compared here).
		$actual = array();
		foreach (ADC_Analytics_Store::commerce_rows($stat_date, $stat_date, $currency) as $row) {
			$metric = (string) ($row['metric'] ?? '');
			if ('' === $metric || 'status_reached' === $metric) {
				continue;
			}
			$actual[$metric] ??= array('amount' => 0.0, 'count' => 0);
			$actual[$metric]['amount'] += (float) ($row['amount'] ?? 0);
			$actual[$metric]['count']  += (int) ($row['count'] ?? 0);
		}

		$diff = array();
		foreach ($expected as $metric => $want) {
			$is_count = in_array($metric, array('orders_created', 'orders_paid', 'units'), true);
			$have     = $is_count
				? (float) ($actual[$metric]['count'] ?? 0)
				: (float) ($actual[$metric]['amount'] ?? 0);
			$diff[$metric] = round($have - (float) $want, 6);
		}

		return $diff;
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------- */

	/**
	 * Bounded WC CRUD facts for one day (HPOS-safe; no direct order SQL):
	 * orders created that day, orders paid that day, refunds created that day.
	 *
	 * Date filtering uses the officially documented range form
	 * `'min_timestamp...max_timestamp'` (wc_get_orders docs: "Orders created
	 * between two unix timestamps"). Bounds are the site-local day edges —
	 * inclusive of the day's last second, DST-safe by construction — because a
	 * bare `Y-m-d` string would be interpreted against the server timezone.
	 * Draft/auto-draft/checkout-draft/trash orders never count as created
	 * (Blocks abandoned carts are drafts at heart).
	 *
	 * @return array{created:WC_Order[],paid:WC_Order[],refunds:WC_Order_Refund[],truncated:bool}
	 * @throws Throwable When wc_get_orders fails.
	 */
	private static function day_facts(string $stat_date): array
	{
		$window = self::day_window($stat_date);
		$range  = $window[0] . '...' . $window[1]; // Inclusive [first second, last second] of the site-local day.

		$created = self::query_orders(array('type' => 'shop_order', 'status' => self::placed_statuses(), 'date_created' => $range));
		$paid    = self::query_orders(array('type' => 'shop_order', 'status' => ADC_Analytics_Settings::paid_statuses(), 'date_paid' => $range));
		$refunds = self::query_orders(array('type' => 'shop_order_refund', 'status' => 'any', 'date_created' => $range));

		$truncated = count($created) > self::MAX_ORDERS
			|| count($paid) > self::MAX_ORDERS
			|| count($refunds) > self::MAX_ORDERS;

		return array(
			'created'   => array_slice($created, 0, self::MAX_ORDERS),
			'paid'      => array_slice($paid, 0, self::MAX_ORDERS),
			'refunds'   => array_slice($refunds, 0, self::MAX_ORDERS),
			'truncated' => $truncated,
		);
	}

	/**
	 * Site-local day window as UTC epoch bounds: [first second, last second].
	 * Derived from the site timezone, so DST shifts move the window with it.
	 *
	 * @return array{0:int,1:int}
	 * @throws RuntimeException When the date is unusable.
	 */
	private static function day_window(string $stat_date): array
	{
		$start = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $stat_date . ' 00:00:00', wp_timezone());
		if (false === $start) {
			throw new RuntimeException('Unusable stat_date for order window.');
		}

		$next = $start->modify('+1 day');

		return array($start->getTimestamp(), $next->getTimestamp() - 1);
	}

	/**
	 * Every registered WooCommerce order status except non-placed drafts —
	 * the rebuild half of "drafts never count as orders_created" (B4).
	 *
	 * @return string[] wc-prefixed status keys accepted by wc_get_orders.
	 */
	private static function placed_statuses(): array
	{
		$statuses = function_exists('wc_get_order_statuses') ? array_keys((array) wc_get_order_statuses()) : array();
		$statuses = array_map('strval', $statuses);
		$statuses = array_diff($statuses, array('wc-draft', 'wc-auto-draft', 'wc-checkout-draft', 'wc-trash'));

		if (array() === $statuses) {
			// Defensive fallback when the status registry is unavailable.
			$statuses = array('wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed');
		}

		return array_values($statuses);
	}

	/**
	 * One bounded wc_get_orders call (limit MAX_ORDERS + 1 detects overflow).
	 *
	 * @param  array<string,mixed> $args Query args.
	 * @return WC_Order[]|WC_Order_Refund[]
	 */
	private static function query_orders(array $args): array
	{
		$args['limit'] = self::MAX_ORDERS + 1;

		$rows = wc_get_orders($args);

		return is_array($rows) ? $rows : array();
	}

	/**
	 * Aggregate buckets recomputed from the day's facts.
	 *
	 * @param  array{created:WC_Order[],paid:WC_Order[],refunds:WC_Order_Refund[]} $facts Day facts.
	 * @return array{commerce:array<string,array<string,array<string,array{amount:float,count:int}>>>,products:array<string,array<int,array<string,float|int>>>,events:array<int,array<string,mixed>>,product_ids:array<int,int>}
	 */
	private static function compute_buckets(array $facts): array
	{
		$commerce    = array(); // [currency][status][metric] => {amount, count}
		$products    = array(); // [currency][product_id] => product metrics
		$events      = array(); // ledger fencing rows
		$product_ids = array();

		$bump = static function (string $currency, string $status, string $metric, float $amount, int $count) use (&$commerce): void {
			$commerce[$currency][$status][$metric] ??= array('amount' => 0.0, 'count' => 0);
			$commerce[$currency][$status][$metric]['amount'] += $amount;
			$commerce[$currency][$status][$metric]['count']  += $count;
		};
		$bump_product = static function (string $currency, int $product_id, array $metrics) use (&$products, &$product_ids): void {
			$products[$currency][$product_id] ??= array(
				'units'        => 0,
				'items_net'    => 0.0,
				'items_tax'    => 0.0,
				'refund_units' => 0,
				'refund_net'   => 0.0,
				'refund_tax'   => 0.0,
			);
			foreach ($metrics as $key => $value) {
				$products[$currency][$product_id][$key] += $value;
			}
			$product_ids[$product_id] = $product_id;
		};

		foreach ($facts['created'] as $order) {
			$currency = self::order_currency($order);
			if ('' === $currency) {
				continue;
			}
			$bump($currency, '', 'orders_created', 0.0, 1);
			$events[] = self::ledger_event($order, ADC_Analytics_Commerce::EVT_CREATED, $currency, 0.0);
		}

		foreach ($facts['paid'] as $order) {
			$currency = self::order_currency($order);
			if ('' === $currency) {
				continue;
			}
			$metrics = ADC_Analytics_Commerce::order_metrics($order);
			$bump($currency, '', 'orders_paid', 0.0, 1);
			foreach (self::FINANCIAL_METRICS as $key) {
				$bump($currency, '', $key, (float) $metrics[$key], 0);
			}
			$bump($currency, '', 'units', 0.0, (int) $metrics['units']);
			foreach (ADC_Analytics_Commerce::product_metrics($order) as $product_id => $product) {
				$bump_product($currency, (int) $product_id, $product);
			}
			$events[] = self::ledger_event($order, ADC_Analytics_Commerce::EVT_PAID, $currency, (float) $metrics['order_total']);
		}

		foreach ($facts['refunds'] as $refund) {
			$parent_id = (int) $refund->get_parent_id();
			if ($parent_id < 1) {
				continue;
			}
			$parent = wc_get_order($parent_id);
			if (!$parent instanceof WC_Order) {
				continue;
			}
			$currency = self::order_currency($parent);
			if ('' === $currency) {
				continue;
			}
			$refund_metrics = ADC_Analytics_Commerce::refund_metrics($parent, $refund);
			$bump($currency, '', 'refund_total', (float) $refund_metrics['order']['refund_total'], 0);
			foreach ($refund_metrics['products'] as $product_id => $product) {
				$bump_product($currency, (int) $product_id, $product);
			}
			$events[] = self::ledger_event(
				$parent,
				'refund:' . (int) $refund->get_id(),
				$currency,
				(float) $refund_metrics['order']['refund_total'],
				$refund
			);
		}

		return array(
			'commerce'    => $commerce,
			'products'    => $products,
			'events'      => $events,
			'product_ids' => $product_ids,
		);
	}

	/**
	 * One ledger fencing row for a materialized event.
	 *
	 * @return array<string,mixed>
	 */
	private static function ledger_event(WC_Order $order, string $event_key, string $currency, float $amount, ?WC_Order_Refund $refund = null): array
	{
		return array(
			'order_id'    => (int) $order->get_id(),
			'event_key'   => $event_key,
			'status'      => self::bare_status((string) $order->get_status()),
			'currency'    => $currency,
			'amount'      => $amount,
			'occurred_at' => self::utc_of($refund ?? $order, str_starts_with($event_key, 'refund:') ? 'get_date_created' : (ADC_Analytics_Commerce::EVT_PAID === $event_key ? 'get_date_paid' : 'get_date_created')),
		);
	}

	/**
	 * Per-metric difference previous aggregates minus recomputed values
	 * ("<CURRENCY>/<metric>" => previous - computed), for the rebuild report.
	 *
	 * @param  array<int,array<string,mixed>> $previous Rows read before the replace.
	 * @param  array<string,array<string,array<string,array{amount:float,count:int}>>> $computed New buckets.
	 * @return array<string,float>
	 */
	private static function diff_against_previous(array $previous, array $computed): array
	{
		$previous_totals = array();
		foreach ($previous as $row) {
			$metric = (string) ($row['metric'] ?? '');
			if ('' === $metric || 'status_reached' === $metric) {
				continue;
			}
			$key   = strtoupper((string) ($row['currency'] ?? '')) . '/' . $metric;
			$previous_totals[$key] ??= 0.0;
			$previous_totals[$key] += (float) ($row['amount'] ?? 0) + (float) ($row['count'] ?? 0);
		}

		$computed_totals = array();
		foreach ($computed as $currency => $by_status) {
			foreach ($by_status as $metrics) {
				foreach ($metrics as $metric => $value) {
					$key = $currency . '/' . $metric;
					$computed_totals[$key] ??= 0.0;
					$computed_totals[$key] += (float) $value['amount'] + (float) $value['count'];
				}
			}
		}

		$diff = array();
		foreach (array_unique(array_merge(array_keys($previous_totals), array_keys($computed_totals))) as $key) {
			$value = round(($previous_totals[$key] ?? 0.0) - ($computed_totals[$key] ?? 0.0), 6);
			if (0.0 !== $value) {
				$diff[$key] = $value;
			}
		}

		return $diff;
	}

	/**
	 * Uppercase ISO currency of an order ('' when unusable).
	 */
	private static function order_currency(WC_Order $order): string
	{
		$currency = strtoupper(trim((string) $order->get_currency()));

		return preg_match('/^[A-Z]{3}\z/', $currency) ? $currency : '';
	}

	/**
	 * Bare lowercase status (ledger/`order_status` form).
	 */
	private static function bare_status(string $status): string
	{
		$status = strtolower(trim($status));
		if (str_starts_with($status, 'wc-')) {
			$status = substr($status, 3);
		}

		return preg_match('/^[a-z0-9_-]{0,20}\z/', $status) ? $status : '';
	}

	/**
	 * UTC datetime string from a WC date getter ('' when unusable).
	 */
	private static function utc_of(object $object, string $getter): string
	{
		if (!method_exists($object, $getter)) {
			return '';
		}
		$value = $object->{$getter}();
		if ($value instanceof DateTimeInterface) {
			return gmdate('Y-m-d H:i:s', $value->getTimestamp());
		}
		if (is_numeric($value)) {
			return gmdate('Y-m-d H:i:s', (int) $value);
		}
		if (is_string($value) && '' !== $value) {
			$parsed = strtotime($value);
			return false === $parsed ? '' : gmdate('Y-m-d H:i:s', $parsed);
		}

		return '';
	}

	/**
	 * Oldest date a rebuild may touch: limited by the ledger retention, because
	 * older ledger rows may already be purged and replay fencing must exist.
	 */
	private static function oldest_rebuildable_date(): string
	{
		$days = max(1, ADC_Analytics_Settings::retention_days('ledger'));

		try {
			return (new DateTimeImmutable(self::today(), wp_timezone()))
				->modify('-' . $days . ' days')
				->format('Y-m-d');
		} catch (Throwable) {
			return self::today();
		}
	}

	/**
	 * Today in the site timezone.
	 */
	private static function today(): string
	{
		return (string) wp_date('Y-m-d');
	}

	/**
	 * Strict Y-m-d validation.
	 */
	private static function valid_date(string $date): bool
	{
		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

		return false !== $parsed && $parsed->format('Y-m-d') === $date;
	}
}
