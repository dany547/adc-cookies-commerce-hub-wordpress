<?php
/**
 * Standalone dashboard adapter test.
 *
 * Drives ADC_Admin_Tab_Analytics's private view adapters with fixtures shaped
 * EXACTLY like the real ADC_Analytics_Reports envelopes (available/reason/rows,
 * counts, currencies, reconciliation days, suppressed rows with null values)
 * through a fake Reports class, then asserts:
 *  - envelopes are unwrapped (rows + availability + reason), never misread as rows;
 *  - null / suppressed cells are never plotted or reported as zero;
 *  - Reports' suppression flag (incl. complementary suppression) is authoritative:
 *    flagged cells stay hidden, unflagged cells are never re-suppressed locally;
 *  - filters propagate to Reports (channel/page_type to traffic-side reports,
 *    product_id only to products; never channel to commerce) and CSV export URLs
 *    use the backend parameter names (preset/from/to/currency/product_id).
 *
 * Run: php tests/analytics/dashboard-test.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 1);

interface ADC_Module_Interface { public static function boot(): void; }
interface ADC_Admin_Tab_Interface {
	public function slug(): string;
	public function label(): string;
	public function group(): string;
	public function order(): int;
	public function render(): void;
	public function save( array $post ): array;
}

/* ---------------------------------------------------------------------------
 * WordPress stubs
 * ------------------------------------------------------------------------- */

final class ADC_Admin_Shell { public const PAGE_SLUG = 'adc-marketing'; }

final class ADC_Settings
{
	/** @var array<string,mixed> */
	public static array $values = array();

	public static function get( string $group, string $key, mixed $default = null ): mixed {
		unset( $group );
		return self::$values[ $key ] ?? $default;
	}

	public static function all( string $group ): array {
		unset( $group );
		return self::$values;
	}
}

function __( string $text, string $domain = 'default' ): string { unset( $domain ); return $text; }
function esc_html__( string $t, string $d = 'default' ): string { unset( $d ); return $t; }
function esc_html_e( string $t, string $d = 'default' ): void { unset( $t, $d ); }
function esc_attr_e( string $t, string $d = 'default' ): void { unset( $t, $d ); }
function number_format_i18n( float $v, int $decimals = 0 ): string { return number_format( $v, $decimals, '.', '' ); }
function current_time( string $format ): string { return '2024-05-10' === $format ? '2024-05-10' : gmdate( $format, 1715294400 ); } // 2024-05-10
function wp_date( string $format, ?int $ts = null, ?DateTimeZone $tz = null ): string {
	$date = new DateTimeImmutable( '@' . ( $ts ?? 1715294400 ) );
	return $date->setTimezone( $tz ?? new DateTimeZone( 'Europe/Bucharest' ) )->format( $format );
}
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function admin_url( string $path = '' ): string { return 'http://example.test/wp-admin/' . $path; }
function add_query_arg( array $args, string $url ): string {
	$sep = str_contains( $url, '?' ) ? '&' : '?';
	return $url . $sep . http_build_query( $args );
}
function wp_nonce_url( string $url, string $action ): string { unset( $action ); return $url . '&_wpnonce=testnonce'; }
function get_woocommerce_currency(): string { return 'RON'; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }

// Real analytics settings (fail-closed gates, paid-status allowlist, threshold).
require_once __DIR__ . '/../../includes/analytics/class-adc-analytics-settings.php';

/* ---------------------------------------------------------------------------
 * Fake Reports façade: exact envelope shapes, argument recording
 * ------------------------------------------------------------------------- */

final class FakeReportsLog
{
	/** @var array<string,array<int,mixed>> */
	public static array $calls = array();

	public static function record( string $method, array $args ): void {
		self::$calls[ $method ][] = $args;
	}

	/** @return array<int,mixed>|null */
	public static function last( string $method ): ?array {
		return self::$calls[ $method ][ count( self::$calls[ $method ] ) - 1 ] ?? null;
	}
}

final class ADC_Analytics_Reports
{
	public const TRAFFIC_ENVELOPE = array(
		'available' => true,
		'reason'    => '',
		'rows'      => array(
			array( 'date' => '2024-05-08', 'views' => 120, 'suppressed' => false ),
			// Suppressed day: Reports nulls the value keys and flags the row.
			array( 'date' => '2024-05-09', 'views' => null, 'suppressed' => true ),
			array( 'date' => '2024-05-10', 'views' => 250, 'suppressed' => false ),
		),
		'suppressed_count' => 1,
		'complementary'    => false,
		'views_total'      => 370,
	);

	public const COMMERCE_ENVELOPE = array(
		'available'      => true,
		'reason'         => '',
		'currency'       => 'RON',
		'currency_valid' => true,
		'rows'           => array(
			array(
				'date' => '2024-05-08', 'orders_created' => 9, 'orders_paid' => 6,
				'gross_revenue' => 600.5, 'refunds' => 10.0, 'net_revenue' => 590.5, 'suppressed' => false,
			),
			// Complementary-suppressed day: nulls across the board.
			array(
				'date' => '2024-05-09', 'orders_created' => null, 'orders_paid' => null,
				'gross_revenue' => null, 'refunds' => null, 'net_revenue' => null, 'suppressed' => true,
			),
			array(
				'date' => '2024-05-10', 'orders_created' => 4, 'orders_paid' => 3,
				'gross_revenue' => 300.25, 'refunds' => 0.0, 'net_revenue' => 300.25, 'suppressed' => false,
			),
		),
		'totals' => array(),
	);

	public const CHANNELS_ENVELOPE = array(
		'available' => true,
		'reason'    => '',
		'rows'      => array(
			// Value 2 WITHOUT the flag: the backend decided it is publishable —
			// the UI must never re-suppress it with its own local threshold.
			array( 'channel' => 'referral', 'label' => 'Trimis de alt site', 'views' => 2, 'share' => 0.005, 'suppressed' => false ),
			// Complementary-suppressed row: flagged, values nulled.
			array( 'channel' => 'internal', 'label' => 'Intern', 'views' => null, 'share' => null, 'suppressed' => true ),
			array( 'channel' => 'search', 'label' => 'Căutare', 'views' => 368, 'share' => 0.995, 'suppressed' => false ),
		),
	);

	public const PRODUCTS_ENVELOPE = array(
		'available' => true,
		'reason'    => '',
		'rows'      => array(
			array(
				'product_id' => 77, 'label' => 'Produs existent', 'units' => 14, 'net' => 420.0,
				'tax' => 88.2, 'refund_units' => 1, 'refund_net' => 30.0, 'suppressed' => false,
			),
			array(
				'product_id' => 91, 'label' => '', 'units' => null, 'net' => null,
				'tax' => null, 'refund_units' => null, 'refund_net' => null, 'suppressed' => true,
			),
		),
	);

	public static function range( string $preset, string $from = '', string $to = '' ): array {
		FakeReportsLog::record( 'range', array( $preset, $from, $to ) );
		return array( 'from' => '2024-04-11', 'to' => '2024-05-10', 'prev_from' => '2024-03-12', 'prev_to' => '2024-04-10', 'days' => 30 );
	}

	public static function kpis( array $range, string $currency, array $filters = array() ): array {
		FakeReportsLog::record( 'kpis', array( $range, $currency, $filters ) );
		return array(
			'orders_paid' => 9, 'orders_created' => 13, 'gross_revenue' => 900.75,
			'net_revenue' => 890.75, 'refunds' => 10.0, 'tax' => 100.0, 'shipping' => 5.0,
			'discounts' => 2.0, 'aov' => 100.08, 'traffic_views' => null, 'small_sample' => false,
		);
	}

	public static function traffic_series( array $range, array $filters = array() ): array {
		FakeReportsLog::record( 'traffic_series', array( $range, $filters ) );
		return self::TRAFFIC_ENVELOPE;
	}

	public static function top_pages( array $range, array $filters = array(), int $limit = 20 ): array {
		FakeReportsLog::record( 'top_pages', array( $range, $filters, $limit ) );
		return array(
			'available' => true,
			'reason'    => '',
			'rows'      => array(
				array( 'page_type' => 'product', 'object_id' => 77, 'label' => 'product:77', 'views' => 210, 'suppressed' => false ),
				array( 'page_type' => 'home', 'object_id' => 0, 'label' => 'home:0', 'views' => 160, 'suppressed' => false ),
			),
		);
	}

	public static function channel_distribution( array $range, array $filters = array() ): array {
		FakeReportsLog::record( 'channel_distribution', array( $range, $filters ) );
		return self::CHANNELS_ENVELOPE;
	}

	public static function commerce_series( array $range, string $currency ): array {
		FakeReportsLog::record( 'commerce_series', array( $range, $currency ) );
		return self::COMMERCE_ENVELOPE;
	}

	public static function top_products( array $range, string $currency, int $limit = 20, array $filters = array() ): array {
		FakeReportsLog::record( 'top_products', array( $range, $currency, $limit, $filters ) );
		return self::PRODUCTS_ENVELOPE;
	}

	public static function current_order_counts(): array {
		FakeReportsLog::record( 'current_order_counts', array() );
		return array(
			'available' => true,
			'reason'    => '',
			'counts'    => array( 'processing' => 4, 'completed' => 5, 'pending' => 1 ),
			'total'     => 10,
			'statuses'  => array( 'processing', 'completed', 'pending' ),
		);
	}

	public static function reconciliation( array $range, string $currency ): array {
		FakeReportsLog::record( 'reconciliation', array( $range, $currency ) );
		return array(
			'available' => true,
			'reason'    => '',
			'days'      => array(
				array( 'date' => '2024-05-08', 'diff' => array( 'order_total' => 0.5, 'orders_paid' => 0.0 ), 'warn' => true ),
			),
			'max_diff'  => 0.5,
			'warn'      => true,
		);
	}

	public static function available_currencies(): array {
		FakeReportsLog::record( 'available_currencies', array() );
		return array( 'available' => true, 'reason' => '', 'base' => 'RON', 'currencies' => array( 'RON', 'EUR' ), 'partial' => true );
	}

	public static function warnings( array $range ): array {
		FakeReportsLog::record( 'warnings', array( $range ) );
		return array(
			array( 'code' => 'cache', 'severity' => 'info', 'text' => 'Avertisment din backend.' ),
		);
	}
}

final class ADC_Analytics_Csv
{
	public const NONCE = 'adc_analytics_export_nonce';
	public const ACTION = 'adc_analytics_export';

	/** @return string[] */
	public static function allowed_reports(): array {
		return array( 'kpis', 'traffic_series', 'top_pages', 'channel_distribution', 'commerce_series', 'top_products', 'current_counts', 'reconciliation' );
	}

	public static function export_url( string $report, array $args = array() ): string {
		FakeReportsLog::record( 'export_url', array( $report, $args ) );
		$query = array( 'action' => self::ACTION, 'report' => $report );
		foreach ( array( 'from', 'to', 'currency', 'page_type', 'channel', 'product_id', 'preset' ) as $key ) {
			if ( isset( $args[ $key ] ) && '' !== $args[ $key ] && 0 !== $args[ $key ] ) {
				$query[ $key ] = (string) $args[ $key ];
			}
		}
		return wp_nonce_url( add_query_arg( $query, admin_url( 'admin-post.php' ) ), self::NONCE );
	}
}

/* ---------------------------------------------------------------------------
 * Load the real tab class and drive it
 * ------------------------------------------------------------------------- */

require_once __DIR__ . '/../../includes/admin/class-adc-admin-tab-analytics.php';

function check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
}

/**
 * Invoke a private method on the tab.
 */
function call_tab( object $tab, string $method, mixed ...$args ): mixed {
	$reflection = new ReflectionMethod( $tab, $method );
	$reflection->setAccessible( true );
	return $reflection->invoke( $tab, ...$args );
}

$tab = new ADC_Admin_Tab_Analytics();

/* 1. Currencies: envelope unwrapped (currencies list, not envelope keys). */
$currencies = call_tab( $tab, 'currencies', true );
check( array( 'RON', 'EUR' ) === $currencies, 'currencies() unwraps the envelope list (got ' . json_encode( $currencies ) . ')' );

$unavailable = call_tab( $tab, 'currencies', false );
check( array() === $unavailable, 'currencies() empty when reports unavailable' );

/* 2. Traffic chart: suppressed day keeps its slot, never plotted as zero. */
$filters = array( 'range' => '30', 'from' => '', 'to' => '', 'currency' => 'RON', 'channel' => '', 'page_type' => '', 'product' => 0, 'top' => 10 );
$traffic = call_tab( $tab, 'traffic_chart', ADC_Analytics_Reports::range( '30' ), $filters );
check( $traffic !== array() && true === (bool) $traffic['has_data'], 'traffic chart renders from the envelope' );
check( 1 === (int) $traffic['suppressed'], 'traffic chart reports exactly one suppressed day' );
$traffic_dates = array_column( $traffic['marks'], 'date' );
check( ! in_array( '2024-05-09', $traffic_dates, true ), 'suppressed day has NO plotted point (no zero fabrication)' );
check( in_array( '2024-05-08', $traffic_dates, true ) && in_array( '2024-05-10', $traffic_dates, true ), 'visible days still plotted around the gap' );
$traffic_args = FakeReportsLog::last( 'traffic_series' );
check( array_key_exists( 1, $traffic_args ) && is_array( $traffic_args[1] ), 'traffic_series receives the filters argument' );
call_tab( $tab, 'traffic_chart', ADC_Analytics_Reports::range( '30' ), array_merge( $filters, array( 'channel' => 'search', 'page_type' => 'home' ) ) );
$traffic_args = FakeReportsLog::last( 'traffic_series' );
check( isset( $traffic_args[1]['channel'], $traffic_args[1]['page_type'] ) && 'search' === $traffic_args[1]['channel'] && 'home' === $traffic_args[1]['page_type'], 'traffic_series receives channel + page_type filters' );
check( str_contains( $traffic['path'], 'M' ) || str_starts_with( $traffic['path'], 'L' ) === false, 'traffic path starts at a real point' );

/* 3. Commerce chart: complementary-suppressed day shows the threshold marker, not zeros. */
$series = call_tab( $tab, 'commerce_chart', ADC_Analytics_Reports::range( '30' ), 'RON' );
check( $series !== array() && true === (bool) $series['has_data'], 'commerce chart renders' );
check( 1 === (int) $series['suppressed'], 'commerce chart reports the complementary suppressed day' );
check( 2 === count( $series['bars'] ), 'only the two visible days get bars (got ' . count( $series['bars'] ) . ')' );
$hidden_rows = array_values( array_filter( $series['rows'], static fn ( array $row ): bool => ! empty( $row['small'] ) ) );
check( 1 === count( $hidden_rows ), 'exactly one table row is marked as below threshold' );
check( '' === $hidden_rows[0]['orders'] && '' === $hidden_rows[0]['money'], 'suppressed day table cells are empty (never 0)' );
check( array_key_exists( 1, $series['rows'] ) && '2024-05-09' === $series['rows'][1]['date'], 'suppressed day keeps its calendar slot' );

/* 4. Channels: backend suppression flag is authoritative in both directions. */
$channels = call_tab( $tab, 'channel_rows', ADC_Analytics_Reports::range( '30' ), $filters );
check( 1 === (int) $channels['hidden'], 'complementary-suppressed channel counted as hidden' );
$labels = array_column( $channels['rows'], 'label' );
check( ! in_array( 'Intern', $labels, true ), 'suppressed channel not listed' );
check( in_array( 'Trimis de alt site', $labels, true ), 'UNflagged small channel stays visible (no local re-suppression)' );
$channel_labels = call_tab( $tab, 'channel_rows', ADC_Analytics_Reports::range( '30' ), array_merge( $filters, array( 'channel' => 'search' ) ) );
$channel_args = FakeReportsLog::last( 'channel_distribution' );
check( isset( $channel_args[1]['channel'] ) && 'search' === $channel_args[1]['channel'], 'channel filter propagates to channel_distribution' );

/* 5. Pages: label + channel/page_type filters propagate. */
$filtered = array_merge( $filters, array( 'channel' => 'search', 'page_type' => 'product' ) );
$pages = call_tab( $tab, 'page_rows', ADC_Analytics_Reports::range( '30' ), $filtered, 5 );
$pages_args = FakeReportsLog::last( 'top_pages' );
check( isset( $pages_args[1]['channel'], $pages_args[1]['page_type'] ) && 'search' === $pages_args[1]['channel'] && 'product' === $pages_args[1]['page_type'], 'top_pages receives channel + page_type filters' );
check( 2 === count( $pages['rows'] ), 'page rows rendered from envelope' );

/* 6. Products: product_id filter goes to the backend (CSV parity), money from `net`. */
$product_filters = array_merge( $filters, array( 'product' => 77 ) );
$products = call_tab( $tab, 'product_rows', ADC_Analytics_Reports::range( '30' ), 'RON', $product_filters, 5 );
$products_args = FakeReportsLog::last( 'top_products' );
check( isset( $products_args[3]['product_id'] ) && 77 === $products_args[3]['product_id'], 'top_products receives product_id filter (backend-side, like the CSV)' );
check( 1 === count( $products['rows'] ) && 'Produs existent' === $products['rows'][0]['label'], 'filtered product rendered' );
check( str_contains( (string) $products['rows'][0]['money'], '420' ), 'money column from the exact `net` field' );
$all_products = call_tab( $tab, 'product_rows', ADC_Analytics_Reports::range( '30' ), 'RON', $filters, 5 );
check( 1 === count( $all_products['rows'] ) && 1 === $all_products['hidden'], 'suppressed product hidden, visible one rendered' );

/* 7. Statuses: counts map unwrapped from the envelope. */
$statuses = call_tab( $tab, 'status_rows' );
check( 3 === count( $statuses ), 'status rows from counts map' );
check( 5.0 === $statuses[0]['count'] || 5 === (int) $statuses[0]['count'], 'counts preserved (sorted desc: completed first)' );
check( true === $statuses[0]['paid'] && true === $statuses[1]['paid'], 'paid statuses flagged' );

/* 8. Reconciliation: days flattened per metric + date, unavailable reason surfaced. */
$recon = call_tab( $tab, 'reconciliation_rows', ADC_Analytics_Reports::range( '30' ), 'RON' );
check( true === $recon['available'] && 2 === count( $recon['rows'] ), 'reconciliation rows flattened from days.diff' );
check( 'order_total' === $recon['rows'][0]['metric'] && '2024-05-08' === $recon['rows'][0]['date'], 'reconciliation row carries metric + date' );
check( 0.5 === $recon['rows'][0]['diff'], 'diff value preserved exactly' );
check( true === $recon['rows'][0]['notify'] && false === $recon['rows'][1]['notify'], 'notify threshold on |diff| > 0.01 only' );

$blocked_range = ADC_Analytics_Reports::range( '30' );
unset( $blocked_range );
// Unavailable-envelope shape handling (gate closed / errors): reason stays surfaced.
$unavailable_recon = array( 'rows' => array(), 'available' => false, 'reason' => 'Reconcilierea este limitată.' );
check( '' !== $unavailable_recon['reason'], 'unavailable reconciliation carries a reason for the UI' );

/* 9. Export parity: panel slots mapped to backend report slugs with backend param names. */
$exports = call_tab( $tab, 'export_links', $product_filters, ADC_Analytics_Reports::range( '30' ) );
check( isset( $exports['traffic'], $exports['channels'], $exports['pages'], $exports['products'], $exports['orders'], $exports['revenue'] ), 'all panel export slots present' );
$export_args = FakeReportsLog::last( 'export_url' );
check( 'kpis' === $export_args[0], 'revenue slot maps to the kpis report' );
$parsed_args = $export_args[1];
check( '30' === $parsed_args['preset'], 'CSV receives preset (not range)' );
check( 77 === (int) $parsed_args['product_id'], 'CSV receives product_id (not product)' );
check( '2024-04-11' === $parsed_args['from'] && '2024-05-10' === $parsed_args['to'], 'CSV receives the resolved range' );
check( 'RON' === $parsed_args['currency'], 'CSV receives the currency filter' );
foreach ( $exports as $panel => $url ) {
	check( ! str_contains( $url, 'range=' ) && ! str_contains( $url, '&product=' ), "export URL for $panel uses backend param names only" );
	check( str_contains( $url, '_wpnonce=testnonce' ), "export URL for $panel carries the nonce" );
}
$orders_url = $exports['orders'];
check( str_contains( $orders_url, 'report=commerce_series' ), 'orders slot exports commerce_series' );
check( str_contains( $exports['traffic'], 'report=traffic_series' ), 'traffic slot exports traffic_series' );

/* 9b. Default currency parity (B1): the RESOLVED effective currency is always
 * sent, matching what the page displays — never a raw empty filter. */
$default_filters = array_merge( $filters, array( 'currency' => '' ) );
check( 'RON' === call_tab( $tab, 'currency_for', $default_filters, true ), 'currency_for resolves the shop currency when the filter is empty' );
call_tab( $tab, 'export_links', $default_filters, ADC_Analytics_Reports::range( '30' ), 'RON' );
$default_args = FakeReportsLog::last( 'export_url' );
check( 'RON' === $default_args[1]['currency'], 'export links carry the resolved currency (no blank currency context)' );
check( 'RON' === strtoupper( (string) $default_args[1]['currency'] ), 'currency sent uppercase' );
call_tab( $tab, 'export_links', array_merge( $filters, array( 'currency' => 'EUR' ) ), ADC_Analytics_Reports::range( '30' ), 'EUR' );
$eur_args = FakeReportsLog::last( 'export_url' );
check( 'EUR' === $eur_args[1]['currency'], 'explicit EUR export matches the explicit EUR view' );

/* 10. Commerce never receives channel filters (no attribution). */
$commerce_args = FakeReportsLog::last( 'commerce_series' );
check( 2 === count( $commerce_args ) && ! isset( $commerce_args[2] ), 'commerce_series takes no filters (range + currency only)' );

/* 11. Envelope reason surfaces: unavailable envelope yields no fabricated chart. */
$helper = call_tab( $tab, 'envelope_rows', array( 'available' => false, 'reason' => 'Colectarea este oprită.', 'rows' => array() ) );
check( false === $helper['available'] && 'Colectarea este oprită.' === $helper['reason'] && array() === $helper['rows'], 'envelope_rows keeps availability + reason' );
$plain = call_tab( $tab, 'envelope_rows', array( array( 'code' => 'x', 'text' => 'y' ) ) );
check( 1 === count( $plain['rows'] ) && true === $plain['available'], 'plain row lists pass through envelope_rows' );
$reason_envelope = call_tab( $tab, 'envelope_rows', array( 'available' => true, 'reason' => '', 'rows' => array( array( 'date' => '2024-05-08', 'views' => 10, 'suppressed' => false ) ) ) );
check( 1 === count( $reason_envelope['rows'] ), 'available envelope rows unwrapped' );

echo "dashboard-test: PASS\n";
