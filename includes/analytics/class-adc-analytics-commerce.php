<?php
/**
 * WooCommerce commerce event ingestion for operational analytics.
 *
 * Pipeline: official WC hooks (Classic + Blocks — Blocks goes through the same
 * status/payment/refund CRUD events) enqueue ONE async job; a later process
 * (Action Scheduler group `adc-analytics`, WP-Cron fallback) claims a dedupe
 * ledger row and applies aggregates inside a single InnoDB transaction.
 *
 * Guarantees:
 * - Nothing writes during the checkout/payment request; hook handlers only enqueue.
 * - Paid once: the `order_paid` ledger key is unique per order, so asynchronous
 *   payments, repeated `payment_complete` calls and repeated status cycles
 *   (processing → completed → …) count exactly one paid event.
 * - One transaction per event: ledger claim + every aggregate upsert commit or
 *   roll back together. A failed/retried job never loses or double-applies a
 *   claimed ledger row (the claim rolls back with the aggregates).
 * - Writers serialize per stat_date with the same MySQL day-lock protocol the
 *   reconciler uses (ADC_Analytics_Store::lock_day/unlock_day, released only
 *   after COMMIT), so a day rebuild can never race a live job into a double count.
 * - No order IDs in aggregates (only the dedicated ledger keeps order_id);
 *   no personal data is read: currency, dates, statuses, totals and product IDs only.
 *
 * Financial definitions (identical to the Reports façade, WC CRUD getters, no
 * double subtraction; components are stored once and combined only at display):
 *   order_total    = $order->get_total()          (gross, incl. tax/shipping, after discounts)
 *   tax_total      = $order->get_total_tax()
 *   shipping_total = $order->get_shipping_total() (excl. tax)
 *   shipping_tax   = $order->get_shipping_tax()   (component only, never re-added)
 *   discount_total = $order->get_discount_total() (excl. tax)
 *   discount_tax   = $order->get_discount_tax()   (component only)
 *   items_net/tax  = Σ line get_total() / get_total_tax()
 *   units          = Σ line get_quantity()
 *   refund_*       = abs() of refund amount / refund line totals (partial refunds accumulate)
 * Display formulas live in ADC_Analytics_Reports (net = gross − refunds, AOV = gross / paid).
 * Amounts are rounded to 6 decimals (DECIMAL(18,6)); sums are per currency, never mixed.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Commerce hooks, async jobs and metric application.
 */
final class ADC_Analytics_Commerce implements ADC_Module_Interface
{
	/** Action Scheduler / WP-Cron job hook. */
	public const JOB_HOOK = 'adc_analytics_record';

	/** Action Scheduler group (unscheduled by ADC_Analytics_Schema::uninstall()). */
	public const GROUP = 'adc-analytics';

	/** Retry backoff in seconds for a job whose transaction failed. */
	private const RETRY_SCHEDULE = array(120, 300, 900, 3600, 21600);

	/** Ledger event keys produced by this class. */
	public const EVT_CREATED = 'order_created';
	public const EVT_PAID    = 'order_paid';

	/**
	 * Hook registration. WC fires these events for Classic checkout, Blocks
	 * checkout, admin edits, REST order updates and payment gateways alike,
	 * because every path goes through WC CRUD status/payment/refund operations.
	 */
	public static function boot(): void
	{
		add_action('woocommerce_new_order', array(self::class, 'on_new_order'), 20, 1);
		add_action('woocommerce_payment_complete', array(self::class, 'on_payment_complete'), 20, 1);
		add_action('woocommerce_order_status_changed', array(self::class, 'on_status_changed'), 20, 3);
		add_action('woocommerce_order_refunded', array(self::class, 'on_refunded'), 20, 2);
		add_action(self::JOB_HOOK, array(self::class, 'process'), 10, 5);
	}

	/**
	 * New order (any status): count `orders_created` — but only when the order
	 * is actually placed. WooCommerce Blocks creates `checkout-draft` orders for
	 * abandoned carts before any checkout happens; drafts never count. The
	 * placement transition (draft → pending/…) enqueues the created event later.
	 */
	public static function on_new_order(int $order_id): void
	{
		$order = wc_get_order($order_id);
		if ($order instanceof WC_Order && self::is_unplaced_status((string) $order->get_status())) {
			return; // Draft / abandoned-cart shell: not a placed order yet.
		}

		self::safe_enqueue(self::EVT_CREATED, $order_id, 0);
	}

	/**
	 * Payment complete (asynchronous gateways included): try to count the paid
	 * event; the ledger makes this idempotent against the status transition.
	 */
	public static function on_payment_complete(int $order_id): void
	{
		self::safe_enqueue(self::EVT_PAID, $order_id, 0);
	}

	/**
	 * Status transition: record the first reach of the target status, claim the
	 * paid event when the target is a paid status, and count the creation when
	 * the order is placed for the first time (Blocks draft → placed status;
	 * the ledger keeps it at exactly one `orders_created`).
	 *
	 * Draft-only transitions (auto-draft ↔ checkout-draft) are never facts.
	 */
	public static function on_status_changed(int $order_id, string $from, string $to): void
	{
		$to_unplaced = self::is_unplaced_status($to);
		if ($to_unplaced) {
			return; // No event fact for draft-side moves.
		}

		if (self::is_unplaced_status($from)) {
			// Actual placement happens now: creation + first reach of the placed status.
			self::safe_enqueue(self::EVT_CREATED, $order_id, 0);
		}

		self::safe_enqueue(self::event_key_for_status($to), $order_id, 0);
		if (self::is_paid_status($to)) {
			self::safe_enqueue(self::EVT_PAID, $order_id, 0);
		}
	}

	/**
	 * Refund created (partial or full, once per refund id).
	 */
	public static function on_refunded(int $order_id, int $refund_id): void
	{
		self::safe_enqueue('refund:' . $refund_id, $order_id, $refund_id);
	}

	/**
	 * Async job entry point. Re-checks the gate at process time; never throws
	 * into the scheduler; retries transient failures on a bounded backoff.
	 *
	 * @param string $event_key Ledger event key.
	 * @param int    $order_id  Order (or refund parent) id.
	 * @param int    $refund_id Refund id for refund events, 0 otherwise.
	 * @param string $stat_date Optional site-local date captured at enqueue time
	 *                           (status facts); otherwise derived from the order.
	 * @param int    $attempt   Retry attempt number (0-based).
	 */
	public static function process(string $event_key, int $order_id, int $refund_id = 0, string $stat_date = '', int $attempt = 0): void
	{
		if (!ADC_Analytics_Settings::commerce_enabled()) {
			return; // Re-checked at process time; a disabled gate simply leaves the event unapplied.
		}

		try {
			if (self::apply($event_key, $order_id, $refund_id, $stat_date)) {
				return;
			}
		} catch (Throwable $error) {
			// No order id / personal data in logs; scheduler errors are operator diagnostics.
			ADC_Logger::log('error', 'Analytics commerce job exception: ' . $error->getMessage());
		}

		self::requeue($event_key, $order_id, $refund_id, $stat_date, $attempt);
	}

	/**
	 * True when the status (with or without the `wc-` prefix) counts as paid.
	 */
	public static function is_paid_status(string $status): bool
	{
		return in_array(self::normalize_status($status), ADC_Analytics_Settings::paid_statuses(), true);
	}

	/**
	 * True for non-placed order states: WP/admin drafts, WooCommerce Blocks
	 * abandoned-cart shells and trashed orders. Only placed orders count.
	 */
	public static function is_unplaced_status(string $status): bool
	{
		return in_array(self::normalize_status($status), array('draft', 'auto-draft', 'checkout-draft', 'trash'), true);
	}

	/**
	 * Site-local stat date (Y-m-d) of one event. The refund argument supplies
	 * the refund creation date; paid falls back to the modification date only
	 * when the order already sits in a paid status without a paid date.
	 */
	public static function event_date(WC_Order $order, string $event_key, ?WC_Order_Refund $refund = null): string
	{
		if (str_starts_with($event_key, 'refund:')) {
			return self::local_date_from_object($refund, 'get_date_created');
		}

		if (self::EVT_CREATED === $event_key) {
			return self::local_date_from_object($order, 'get_date_created');
		}

		// order_paid: the paid date is the source of truth (async gateways can pay
		// days later); a paid-status order without one falls back to its last edit.
		$paid = self::local_date_from_object($order, 'get_date_paid');
		if ('' !== $paid) {
			return $paid;
		}

		return self::is_paid_status($order->get_status())
			? self::local_date_from_object($order, 'get_date_modified')
			: '';
	}

	/**
	 * Financial components of one order (see class docblock for definitions).
	 *
	 * @return array<string,float|int>
	 */
	public static function order_metrics(WC_Order $order): array
	{
		$items_net  = 0.0;
		$items_tax  = 0.0;
		$units      = 0;

		foreach (self::order_lines($order) as $item) {
			$items_net += (float) $item['net'];
			$items_tax += (float) $item['tax'];
			$units     += (int) $item['quantity'];
		}

		return array(
			'order_total'    => self::dec6((float) $order->get_total()),
			'tax_total'      => self::dec6((float) $order->get_total_tax()),
			'shipping_total' => self::dec6((float) $order->get_shipping_total()),
			'shipping_tax'   => self::dec6((float) $order->get_shipping_tax()),
			'discount_total' => self::dec6((float) $order->get_discount_total()),
			'discount_tax'   => self::dec6((float) $order->get_discount_tax()),
			'items_net'      => self::dec6($items_net),
			'items_tax'      => self::dec6($items_tax),
			'units'          => $units,
		);
	}

	/**
	 * Per-parent-product metrics (variations roll up by parent product id).
	 *
	 * @return array<int,array<string,float|int>> product_id => metrics
	 */
	public static function product_metrics(WC_Order $order): array
	{
		return self::accumulate_lines(self::order_lines($order));
	}

	/**
	 * Refund contributions: order-level `refund_total` plus per-product
	 * refund_units/refund_net/refund_tax, all absolute. A shipping-only refund
	 * (no line items) touches only `refund_total`. Paid counts never change.
	 *
	 * @return array{order:array<string,float|int>,products:array<int,array<string,float|int>>}
	 */
	public static function refund_metrics(WC_Order $order, WC_Order_Refund $refund): array
	{
		unset($order); // Kept for contract symmetry; refund lines carry the product data.

		$lines = array();
		if (method_exists($refund, 'get_items')) {
			foreach ($refund->get_items(array('line_item')) as $item) {
				if (!is_object($item) || !method_exists($item, 'get_quantity')) {
					continue;
				}
				$product_id = self::line_product_id($item);
				if ($product_id < 1) {
					continue;
				}
				$lines[] = array(
					'product_id' => $product_id,
					'quantity'   => abs((int) $item->get_quantity()),
					'net'        => abs((float) (method_exists($item, 'get_total') ? $item->get_total() : 0)),
					'tax'        => abs((float) (method_exists($item, 'get_total_tax') ? $item->get_total_tax() : 0)),
				);
			}
		}

		return array(
			'order'    => array(
				'refund_total' => self::dec6(abs((float) (method_exists($refund, 'get_amount') ? $refund->get_amount() : 0))),
			),
			'products' => self::accumulate_refund_lines($lines),
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------- */

	/**
	 * Enqueue from a WC hook: gate check, argument validation, never throws.
	 * The hook handlers must never interrupt checkout/payment.
	 */
	private static function safe_enqueue(string $event_key, int $order_id, int $refund_id): void
	{
		try {
			if ($order_id < 1 || !ADC_Analytics_Settings::commerce_enabled()) {
				return;
			}
			// Status facts freeze their site-local date at enqueue time so a job
			// that runs after midnight still lands on the day of the transition.
			self::enqueue($event_key, $order_id, $refund_id, self::stat_date_for_enqueue($event_key), 0);
		} catch (Throwable $error) {
			ADC_Logger::log('error', 'Analytics commerce enqueue failed: ' . $error->getMessage());
		}
	}

	/**
	 * Schedule one job. Action Scheduler unique actions collapse hook replays
	 * while a job is pending; the WP-Cron fallback relies on the transactional
	 * ledger for correctness (scheduling duplication is harmless there).
	 */
	private static function enqueue(string $event_key, int $order_id, int $refund_id, string $stat_date, int $attempt): void
	{
		$args = array($event_key, $order_id, $refund_id, $stat_date, $attempt);
		$when = time() + 60 + $attempt * 30;

		if (class_exists('ActionScheduler') && function_exists('as_schedule_unique_action')) {
			as_schedule_unique_action($when, self::JOB_HOOK, $args, self::GROUP);
			return;
		}

		if (function_exists('as_schedule_single_action')) {
			as_schedule_single_action($when, self::JOB_HOOK, $args, self::GROUP);
			return;
		}

		wp_schedule_single_event($when, self::JOB_HOOK, $args);
	}

	/**
	 * Bounded retry after a transient failure (DB error, lock refusal).
	 * The claim rolled back with the aggregates, so the replay re-applies
	 * exactly once; after the ladder is exhausted the event is dropped with an
	 * error log and remains repairable by ADC_Analytics_Reconciler::rebuild_day().
	 */
	private static function requeue(string $event_key, int $order_id, int $refund_id, string $stat_date, int $attempt): void
	{
		if ($attempt >= count(self::RETRY_SCHEDULE) - 1) {
			ADC_Logger::log('error', sprintf('Analytics commerce job dropped after %d attempts (event %s).', $attempt + 1, $event_key));
			return;
		}

		try {
			self::enqueue($event_key, $order_id, $refund_id, $stat_date, $attempt + 1);
		} catch (Throwable $error) {
			ADC_Logger::log('error', 'Analytics commerce retry scheduling failed: ' . $error->getMessage());
		}
	}

	/**
	 * Resolve the event into concrete facts, then claim + apply in one transaction.
	 *
	 * @return bool True when applied (or already applied / not applicable yet);
	 *              false only on transient failure (caller retries).
	 */
	private static function apply(string $event_key, int $order_id, int $refund_id, string $stat_date): bool
	{
		$context = self::resolve($event_key, $order_id, $refund_id, $stat_date);
		if (null === $context) {
			return true; // Nothing to do (unknown order/refund, unpaid-yet, bad currency): soft skip.
		}

		$lock = null;
		$ok   = ADC_Analytics_Store::transaction(
			static function () use ($context, &$lock): bool {
				// Same day-lock protocol as the reconciler: writers serialize per
				// stat_date so a day rebuild can never interleave with this job.
				if (!ADC_Analytics_Store::lock_day($context['stat_date'])) {
					return false;
				}
				$lock = $context['stat_date'];

				// Duplicate claim => the event was applied before; commit the empty
				// transaction and skip the aggregates (idempotent no-op).
				if (!ADC_Analytics_Store::claim_ledger(
					$context['order_id'],
					$context['event_key'],
					$context['status'],
					$context['currency'],
					$context['amount'],
					$context['stat_date'],
					$context['occurred_at']
				)) {
					return true;
				}

				return self::write_aggregates($context);
			},
			static function () use (&$lock): void {
				// Released only after COMMIT (after-commit hook), never mid-transaction.
				if (null !== $lock) {
					ADC_Analytics_Store::unlock_day($lock);
					$lock = null;
				}
			}
		);

		if (null !== $lock) { // Rollback or exception path: release what we still hold.
			ADC_Analytics_Store::unlock_day($lock);
			$lock = null;
		}

		return $ok;
	}

	/**
	 * Load the order/refund via WC CRUD (HPOS-safe) and build every fact the
	 * transaction needs. Returns null when the event is a soft skip.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function resolve(string $event_key, int $order_id, int $refund_id, string $stat_date)
	{
		if ($order_id < 1) {
			return null;
		}

		$refund = null;
		$target = wc_get_order($order_id);

		if (str_starts_with($event_key, 'refund:')) {
			$refund_id = (int) substr($event_key, 7);
			if ($refund_id < 1) {
				return null;
			}
			$refund = wc_get_order($refund_id);
			if (!$refund instanceof WC_Order_Refund) {
				return null;
			}
			$parent = (int) $refund->get_parent_id();
			if ($parent < 1 || $parent !== $order_id) {
				return null; // Mismatched hook args: never attribute a refund to the wrong order.
			}
			if (!$target instanceof WC_Order) {
				$target = wc_get_order($parent);
			}
		}

		if (!$target instanceof WC_Order) {
			return null; // Refund objects are never aggregated as orders.
		}

		$currency = strtoupper(trim((string) $target->get_currency()));
		if (!preg_match('/^[A-Z]{3}\z/', $currency)) {
			return null; // Currency must be a clean ISO code; sums are never mixed.
		}

		$context = array(
			'event_key'  => null,
			'order_id'   => (int) $target->get_id(),
			'refund'     => $refund,
			'order'      => $target,
			'status'     => self::normalize_status((string) $target->get_status()),
			'currency'   => $currency,
			'stat_date'  => '',
			'occurred_at' => '',
			'amount'     => 0.0,
			'type'       => '',
		);

		switch (true) {
			case self::EVT_CREATED === $event_key:
				$context['type']      = 'created';
				$context['event_key'] = self::EVT_CREATED;
				$context['stat_date'] = self::event_date($target, self::EVT_CREATED);
				$context['occurred_at'] = self::utc_from_object($target, 'get_date_created');
				break;

			case self::EVT_PAID === $event_key:
				// payment_complete may fire before the status transition (D14):
				// without a paid date AND a paid status the event is not payable
				// yet; soft-skip and let the later transition enqueue again.
				if ('' === self::local_date_from_object($target, 'get_date_paid') && !self::is_paid_status($context['status'])) {
					return null;
				}
				$context['type']        = 'paid';
				$context['event_key']   = self::EVT_PAID;
				$context['stat_date']   = self::event_date($target, self::EVT_PAID);
				$context['occurred_at'] = self::utc_from_object($target, 'get_date_paid')
					?: self::utc_from_object($target, 'get_date_modified');
				$context['amount'] = self::dec6((float) $target->get_total());
				break;

			case str_starts_with($event_key, 'status_reached:'):
				$status = self::normalize_status(substr($event_key, strlen('status_reached:')));
				if ('' === $status) {
					return null;
				}
				$context['type']        = 'status';
				$context['event_key']   = 'status_reached:' . $status;
				$context['status']      = $status;
				$context['stat_date']   = self::valid_date($stat_date) ? $stat_date : self::local_date_from_object($target, 'get_date_modified');
				$context['occurred_at'] = self::utc_from_object($target, 'get_date_modified');
				break;

			case str_starts_with($event_key, 'refund:'):
				if (null === $refund) {
					return null;
				}
				$context['type']        = 'refund';
				$context['event_key']   = 'refund:' . $refund_id;
				$context['stat_date']   = self::event_date($target, $event_key, $refund);
				$context['occurred_at'] = self::utc_from_object($refund, 'get_date_created');
				break;

			default:
				return null; // Unknown event key: never claim an arbitrary ledger row.
		}

		if ('' === $context['stat_date'] || '' === $context['occurred_at']) {
			return null; // No trustworthy event time: do not fabricate one.
		}

		return $context;
	}

	/**
	 * Write every aggregate for one claimed event. Any Store failure returns
	 * false and the whole transaction (claim included) rolls back.
	 *
	 * @param array<string,mixed> $context Resolved event.
	 */
	private static function write_aggregates(array $context): bool
	{
		$date     = (string) $context['stat_date'];
		$currency = (string) $context['currency'];
		$order    = $context['order'];

		switch ($context['type']) {
			case 'created':
				return ADC_Analytics_Store::apply_commerce_metric($date, $currency, '', 'orders_created', 0.0, 1);

			case 'paid':
				if (!ADC_Analytics_Store::apply_commerce_metric($date, $currency, '', 'orders_paid', 0.0, 1)) {
					return false;
				}
				$metrics = self::order_metrics($order);
				foreach (array('order_total', 'tax_total', 'shipping_total', 'shipping_tax', 'discount_total', 'discount_tax', 'items_net', 'items_tax') as $key) {
					if (!ADC_Analytics_Store::apply_commerce_metric($date, $currency, '', $key, (float) $metrics[$key], 0)) {
						return false;
					}
				}
				if (!ADC_Analytics_Store::apply_commerce_metric($date, $currency, '', 'units', 0.0, (int) $metrics['units'])) {
					return false;
				}
				foreach (self::product_metrics($order) as $product_id => $product) {
					if (!ADC_Analytics_Store::apply_product_metric($date, $currency, (int) $product_id, $product)) {
						return false;
					}
				}
				return true;

			case 'status':
				return ADC_Analytics_Store::apply_commerce_metric(
					$date,
					$currency,
					(string) $context['status'],
					'status_reached',
					0.0,
					1
				);

			case 'refund':
				$refund_metrics = self::refund_metrics($order, $context['refund']);
				if (!ADC_Analytics_Store::apply_commerce_metric($date, $currency, '', 'refund_total', (float) $refund_metrics['order']['refund_total'], 0)) {
					return false;
				}
				foreach ($refund_metrics['products'] as $product_id => $product) {
					if (!ADC_Analytics_Store::apply_product_metric($date, $currency, (int) $product_id, $product)) {
						return false;
					}
				}
				return true;
		}

		return false;
	}

	/**
	 * Normalized line items of an order: parent product id, quantity, net, tax.
	 *
	 * @return array<int,array<string,float|int>>
	 */
	private static function order_lines(WC_Order $order): array
	{
		$lines = array();
		if (!method_exists($order, 'get_items')) {
			return $lines;
		}

		foreach ($order->get_items(array('line_item')) as $item) {
			if (!is_object($item) || !method_exists($item, 'get_quantity')) {
				continue;
			}
			$product_id = self::line_product_id($item);
			if ($product_id < 1) {
				continue;
			}
			$lines[] = array(
				'product_id' => $product_id,
				'quantity'   => (int) $item->get_quantity(),
				'net'        => (float) (method_exists($item, 'get_total') ? $item->get_total() : 0),
				'tax'        => (float) (method_exists($item, 'get_total_tax') ? $item->get_total_tax() : 0),
			);
		}

		return $lines;
	}

	/**
	 * Parent product id for a line (falls back to the variation id), so
	 * variations roll up into the same catalogue row (§9.6).
	 */
	private static function line_product_id(object $item): int
	{
		$product_id = method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0;
		if ($product_id < 1 && method_exists($item, 'get_variation_id')) {
			$product_id = (int) $item->get_variation_id();
		}

		return $product_id;
	}

	/**
	 * Sum line metrics per product id.
	 *
	 * @param  array<int,array<string,float|int>> $lines Normalized lines.
	 * @return array<int,array<string,float|int>>
	 */
	private static function accumulate_lines(array $lines): array
	{
		$products = array();
		foreach ($lines as $line) {
			$id = (int) $line['product_id'];
			$products[$id] ??= array(
				'units'        => 0,
				'items_net'    => 0.0,
				'items_tax'    => 0.0,
				'refund_units' => 0,
				'refund_net'   => 0.0,
				'refund_tax'   => 0.0,
			);
			$products[$id]['units']     += (int) $line['quantity'];
			$products[$id]['items_net'] += (float) $line['net'];
			$products[$id]['items_tax'] += (float) $line['tax'];
		}

		foreach ($products as $id => $row) {
			$products[$id]['items_net'] = self::dec6((float) $row['items_net']);
			$products[$id]['items_tax'] = self::dec6((float) $row['items_tax']);
			$products[$id]['units']     = max(0, (int) $row['units']);
		}

		ksort($products);
		return $products;
	}

	/**
	 * Sum refund line metrics per product id into the refund_* component keys
	 * (already absolute; never mixed with sale units/net).
	 *
	 * @param  array<int,array<string,float|int>> $lines Normalized refund lines.
	 * @return array<int,array<string,float|int>>
	 */
	private static function accumulate_refund_lines(array $lines): array
	{
		$products = array();
		foreach ($lines as $line) {
			$id = (int) $line['product_id'];
			$products[$id] ??= array(
				'units'        => 0,
				'items_net'    => 0.0,
				'items_tax'    => 0.0,
				'refund_units' => 0,
				'refund_net'   => 0.0,
				'refund_tax'   => 0.0,
			);
			$products[$id]['refund_units'] += (int) $line['quantity'];
			$products[$id]['refund_net']   += (float) $line['net'];
			$products[$id]['refund_tax']   += (float) $line['tax'];
		}

		foreach ($products as $id => $row) {
			$products[$id]['refund_net'] = self::dec6((float) $row['refund_net']);
			$products[$id]['refund_tax'] = self::dec6((float) $row['refund_tax']);
			$products[$id]['refund_units'] = max(0, (int) $row['refund_units']);
		}

		ksort($products);
		return $products;
	}

	/**
	 * Ledger key for a status transition (first reach per status is counted).
	 */
	private static function event_key_for_status(string $status): string
	{
		return 'status_reached:' . self::normalize_status($status);
	}

	/**
	 * WooCommerce statuses arrive with or without the `wc-` prefix; ledger keys
	 * and the `order_status` column use the bare lowercase form.
	 */
	private static function normalize_status(string $status): string
	{
		$status = strtolower(trim($status));
		if (str_starts_with($status, 'wc-')) {
			$status = substr($status, 3);
		}

		return preg_match('/^[a-z0-9_-]{1,20}\z/', $status) ? $status : '';
	}

	/**
	 * Site-local Y-m-d captured when a status hook fires (async-safe midnight).
	 */
	private static function stat_date_for_enqueue(string $event_key): string
	{
		if (!str_starts_with($event_key, 'status_reached:')) {
			return ''; // Financial events derive their date from the order itself.
		}

		$date = (string) wp_date('Y-m-d');

		return self::valid_date($date) ? $date : '';
	}

	/**
	 * Site-local Y-m-d from a WC date getter ('' when unset/unparsable).
	 */
	private static function local_date_from_object(object $object, string $getter): string
	{
		$timestamp = self::timestamp_from_object($object, $getter);
		if (null === $timestamp) {
			return '';
		}

		$date = (string) wp_date('Y-m-d', $timestamp);

		return self::valid_date($date) ? $date : '';
	}

	/**
	 * UTC Y-m-d H:i:s from a WC date getter ('' when unset/unparsable).
	 */
	private static function utc_from_object(object $object, string $getter): string
	{
		$timestamp = self::timestamp_from_object($object, $getter);

		return null === $timestamp ? '' : gmdate('Y-m-d H:i:s', $timestamp);
	}

	/**
	 * Absolute UTC epoch from a WC date getter, tolerating WC_DateTime,
	 * DateTimeImmutable, numeric strings and nulls across WC versions.
	 */
	private static function timestamp_from_object(object $object, string $getter): ?int
	{
		if (!method_exists($object, $getter)) {
			return null;
		}

		$value = $object->{$getter}();
		if (null === $value) {
			return null;
		}
		if ($value instanceof DateTimeInterface) {
			return $value->getTimestamp();
		}
		if (is_numeric($value)) {
			return (int) $value;
		}
		if (is_string($value) && '' !== $value) {
			$parsed = strtotime($value);
			return false === $parsed ? null : $parsed;
		}

		return null;
	}

	/**
	 * Round to 6 decimals and force plain decimal magnitude so no scientific
	 * notation can reach the SQL string binding (float → string safety).
	 */
	private static function dec6(float $value): float
	{
		if (!is_finite($value)) {
			return 0.0;
		}

		return (float) number_format(round($value, 6), 6, '.', '');
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
