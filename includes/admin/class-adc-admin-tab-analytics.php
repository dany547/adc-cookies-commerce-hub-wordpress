<?php
/**
 * "Analytics operațional" tab of the ADC Marketing admin shell.
 *
 * Owns the presentation layer only (docs/research/operational-analytics-discovery.md
 * §10.1, §11.3, chunk F3): it reads through the `ADC_Analytics_Reports` façade,
 * writes settings through `ADC_Settings::update('analytics', …)` and links to the
 * `ADC_Analytics_Csv` admin-post export. It contains no SQL, no order identifiers
 * and no collection code of its own.
 *
 * Gate policy for this release: traffic, UTM, add-to-cart and begin-checkout are
 * closed by the backend (`ADC_Analytics_Settings`), so this tab renders them as
 * disabled controls with the legal reason — there is deliberately no functional
 * enable switch for those features.
 *
 * The shell wraps render() in its own `<form>` + nonce, therefore this tab never
 * prints a form tag and never names a control that could pollute the save POST
 * (dashboard filters are GET links / JS-built URLs and carry no `name` attribute).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Operational analytics dashboard + settings tab.
 */
final class ADC_Admin_Tab_Analytics implements ADC_Admin_Tab_Interface {

	/** Style handle (assets/css/admin-analytics.css). */
	private const STYLE_HANDLE = 'adc-admin-analytics';

	/** Script handle (assets/js/admin-analytics.js). */
	private const SCRIPT_HANDLE = 'adc-admin-analytics';

	/** admin-post action of the CSV export (ADC_Analytics_Csv). */
	private const CSV_ACTION = 'adc_analytics_export';

	/** Nonce action + query arg used by the CSV export. */
	private const CSV_NONCE = 'adc_analytics_export_nonce';

	/** Panel export slots mapped to the CSV allowlist (ADC_Analytics_Csv::REPORTS). */
	private const CSV_REPORTS = array(
		'traffic'  => 'traffic_series',
		'channels' => 'channel_distribution',
		'pages'    => 'top_pages',
		'products' => 'top_products',
		'orders'   => 'commerce_series',
		'revenue'  => 'kpis',
	);

	/** Channel taxonomy of ADC_Analytics_Traffic::channel_from_referer(). */
	private const CHANNELS = array( 'direct', 'search', 'social', 'referral', 'internal', 'unknown' );

	/** Page taxonomy of ADC_Analytics_Traffic::page_dimension(). */
	private const PAGE_TYPES = array( 'home', 'blog', 'post', 'page', 'product', 'product_cat', 'product_tag', 'category', 'tag', 'shop', 'archive', 'other' );

	/** Range presets offered as plain links. */
	private const RANGES = array( '7' => 7, '30' => 30, '90' => 90 );

	/** Statuses selectable as "paid" (mirrors ADC_Analytics_Settings allowlist). */
	private const PAID_STATUSES = array( 'processing', 'completed' );

	/** Chart canvas geometry (CSS pixels; scaled responsively by the stylesheet). */
	private const CHART_W = 760;
	private const CHART_H = 220;
	private const CHART_PAD_L = 46;
	private const CHART_PAD_R = 46;
	private const CHART_PAD_T = 12;
	private const CHART_PAD_B = 26;

	/**
	 * Whether boot() already registered its hooks.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Boot the tab module: enqueue admin-only assets on the plugin page.
	 *
	 * Idempotent, same convention as ADC_Admin_Tab_Banner / ADC_Admin_Tab_Logs.
	 * When the bootstrap seam (ADC_Operational_Analytics) is not wired yet,
	 * render_assets_guard() still delivers the stylesheet.
	 */
	public static function boot(): void {
		if (self::$booted) {
			return;
		}
		self::$booted = true;

		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue the dashboard stylesheet and the admin-only enhancer script.
	 *
	 * Both are local plugin assets, admin-only, capability-gated, with no
	 * dependency, no CDN and no data passed to the page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . ADC_Admin_Shell::PAGE_SLUG !== $hook_suffix || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$base = self::asset_url( '' );
		if ( '' === $base ) {
			return;
		}

		self::register_assets( $base );

		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );
	}

	/**
	 * Register (without enqueuing) the two tab assets.
	 *
	 * @param string $base Asset base URL, trailing slash included.
	 */
	private static function register_assets( string $base ): void {
		$version = defined( 'ADC_MARKETING_VERSION' ) ? (string) ADC_MARKETING_VERSION : null;

		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			wp_register_style( self::STYLE_HANDLE, $base . 'css/admin-analytics.css', array( 'adc-admin-shell' ), $version );
		}

		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			wp_register_script(
				self::SCRIPT_HANDLE,
				$base . 'js/admin-analytics.js',
				array(),
				$version,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
	}

	/**
	 * Plugin asset URL helper (same fallback chain as ADC_Admin_Shell).
	 *
	 * @param string $relative Path relative to assets/.
	 * @return string
	 */
	private static function asset_url( string $relative ): string {
		if ( defined( 'ADC_MARKETING_PLUGIN_URL' ) ) {
			return rtrim( ADC_MARKETING_PLUGIN_URL, '/' ) . '/assets/' . ltrim( $relative, '/' );
		}

		return plugin_dir_url( dirname( __DIR__, 2 ) . '/adc-marketing.php' ) . 'assets/' . ltrim( $relative, '/' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'analytics';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Analytics operațional', 'adc-marketing' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): string {
		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function order(): int {
		return 75;
	}

	/**
	 * Render the dashboard + settings (inner HTML only, no form, no nonce).
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'Acces limitat la administratori.', 'adc-marketing' )
			);
			return;
		}

		$this->guard_assets();

		$view = $this->build_view();

		/** @var array<string,mixed> $view */
		ADC_Template::render( 'admin/analytics-dashboard.php', array( 'view' => $view ) );
	}

	/**
	 * Make sure the tab CSS/JS reach the page even when the bootstrap seam has
	 * not run yet. WordPress prints styles enqueued after wp_head in the footer,
	 * so the plain enqueue is enough; the handle keeps output idempotent (no
	 * duplicate stylesheet).
	 */
	private function guard_assets(): void {
		$base = self::asset_url( '' );
		if ( '' === $base ) {
			return;
		}

		self::register_assets( $base );

		if ( ! wp_style_is( self::STYLE_HANDLE, 'enqueued' ) && ! wp_style_is( self::STYLE_HANDLE, 'done' ) ) {
			wp_enqueue_style( self::STYLE_HANDLE ); // Late enqueue: WP prints footer styles, no raw <link> needed.
		}

		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) && ! wp_script_is( self::SCRIPT_HANDLE, 'done' ) ) {
			// Footer scripts are printed after the page callback, so late enqueue works.
			wp_enqueue_script( self::SCRIPT_HANDLE );
		}
	}

	/**
	 * Persist the analytics settings group. The shell already checked the
	 * capability and the `adc_save_tab_analytics` nonce.
	 *
	 * Only keys of the `analytics` group are read; collection gates for traffic,
	 * UTM, add-to-cart and begin-checkout are forced closed by
	 * ADC_Analytics_Settings::sanitize(), so a disabled control can never open them.
	 *
	 * @param array $post wp_unslash($_POST).
	 * @return array<int, array{type: string, message: string}>
	 */
	public function save( array $post ): array {
		$raw    = isset( $post['analytics'] ) && is_array( $post['analytics'] ) ? $post['analytics'] : array();
		$stored = ADC_Settings::all( ADC_Analytics_Settings::GROUP );

		$group = array(
			'enabled_commerce'    => ! empty( $raw['enabled_commerce'] ),
			'commerce_ack'        => ! empty( $raw['commerce_ack'] ),
			'paid_statuses'       => isset( $raw['paid_statuses'] ) && is_array( $raw['paid_statuses'] )
				? array_map( 'strval', $raw['paid_statuses'] )
				: array(),
			'retention_commerce_days' => isset( $raw['retention_commerce_days'] ) ? (int) $raw['retention_commerce_days'] : 0,
			'retention_ledger_days'   => isset( $raw['retention_ledger_days'] ) ? (int) $raw['retention_ledger_days'] : 0,
			'exclude_logged_in_admins' => ! empty( $raw['exclude_logged_in_admins'] ),
			'bot_filter_enabled'      => ! empty( $raw['bot_filter_enabled'] ),
			'min_cell_count'          => isset( $raw['min_cell_count'] ) ? (int) $raw['min_cell_count'] : 0,
			// Draft legal metadata: stored for the future review, inert by design.
			'traffic_legal_basis'     => isset( $raw['traffic_legal_basis'] ) ? (string) $raw['traffic_legal_basis'] : '',
			'traffic_legal_reference' => isset( $raw['traffic_legal_reference'] ) ? (string) $raw['traffic_legal_reference'] : '',
			'traffic_approved_at'     => isset( $raw['traffic_approved_at'] ) ? (string) $raw['traffic_approved_at'] : '',
			'traffic_utm_legal_reference' => isset( $raw['traffic_utm_legal_reference'] ) ? (string) $raw['traffic_utm_legal_reference'] : '',
			'traffic_utm_approved_at'     => isset( $raw['traffic_utm_approved_at'] ) ? (string) $raw['traffic_utm_approved_at'] : '',
			'add_to_cart_legal_reference' => isset( $raw['add_to_cart_legal_reference'] ) ? (string) $raw['add_to_cart_legal_reference'] : '',
			'add_to_cart_approved_at'     => isset( $raw['add_to_cart_approved_at'] ) ? (string) $raw['add_to_cart_approved_at'] : '',
		);

		// Never let the POST drop an unrelated stored key (retention of traffic).
		$group['retention_traffic_days'] = isset( $raw['retention_traffic_days'] )
			? (int) $raw['retention_traffic_days']
			: (int) ( $stored['retention_traffic_days'] ?? 90 );

		$saved = ADC_Settings::update( ADC_Analytics_Settings::GROUP, $group );

		$notices = array(
			array(
				'type'    => 'success',
				'message' => __( 'Setările de analiză operațională au fost salvate.', 'adc-marketing' ),
			),
		);

		$commerce = ADC_Analytics_Settings::legal_gate_status( 'commerce' );
		if ( ! $commerce['enabled'] ) {
			$notices[] = array(
				'type'    => 'warning',
				'message' => $commerce['reason'],
			);
		}

		$drafted = array_filter(
			array( 'traffic_legal_reference', 'traffic_utm_legal_reference', 'add_to_cart_legal_reference' ),
			static fn ( string $key ): bool => '' !== trim( (string) ( $saved[ $key ] ?? '' ) )
		);

		if ( $drafted !== array() ) {
			$notices[] = array(
				'type'    => 'info',
				'message' => __( 'Referințele juridice au fost salvate ca notițe interne. Nu activează nicio colectare în această versiune.', 'adc-marketing' ),
			);
		}

		return $notices;
	}

	/* --------------------------------------------------------------------- *
	 * View building
	 * --------------------------------------------------------------------- */

	/**
	 * Assemble everything the template renders: filters, gates, figures, charts,
	 * tables, export links, warnings and the settings values.
	 *
	 * @return array<string,mixed>
	 */
	private function build_view(): array {
		$filters    = $this->filters();
		$reports    = $this->reports_ready();
		$range      = $reports ? $this->range_for( $filters ) : $this->local_range( $filters );
		$currency   = $this->currency_for( $filters, $reports );
		$threshold  = ADC_Analytics_Settings::min_cell_count();
		$commerce   = ADC_Analytics_Settings::commerce_enabled();
		$traffic    = ADC_Analytics_Settings::traffic_enabled();
		$gates      = $this->gate_rows( $commerce, $traffic );

		$currencies = $this->currencies( $reports );
		$report_filters = array_filter(
			array(
				'channel'    => (string) $filters['channel'],
				'page_type'  => (string) $filters['page_type'],
				'product_id' => (int) $filters['product'],
			),
			static fn ( $value ): bool => '' !== $value && 0 !== $value
		);
		$kpis_raw   = $reports ? self::normalize_kpis( (array) $this->report_call( 'kpis', array( $range, $currency, $report_filters ), array() ) ) : array();
		$series   = $reports ? $this->commerce_chart( $range, $currency ) : array();
		$traffic_chart = $reports ? $this->traffic_chart( $range, $filters ) : array();
		$channels = $reports ? $this->channel_rows( $range, $filters ) : array();
		$pages    = $reports ? $this->page_rows( $range, $filters, $threshold ) : array();
		$products = $reports ? $this->product_rows( $range, $currency, $filters, $threshold ) : array();
		$statuses = $reports ? $this->status_rows() : array();
		$recon    = $reports ? $this->reconciliation_rows( $range, $currency ) : array( 'rows' => array(), 'available' => false, 'reason' => '' );
		$warnings = $this->warning_rows( $range, $reports, (array) $recon['rows'], $series, $traffic_chart );

		return array(
			'range_label'   => sprintf(
				/* translators: 1: start date, 2: end date. */
				__( 'Interval: %1$s → %2$s', 'adc-marketing' ),
				$range['from'],
				$range['to']
			),
			'filters'       => $filters,
			'range'         => $range,
			'currency'      => $currency,
			'currencies'    => $currencies,
			'commerce'      => $commerce,
			'traffic'       => $traffic,
			'nav'           => $this->nav_rows( $filters, $currencies ),
			'threshold'     => $threshold,
			'gates'         => $gates,
			'reports_ready' => $reports,
			'csv_reports'   => $this->csv_reports(),
			'export_links'  => $this->export_links( $filters, $range, $currency ),
			'kpis'          => $kpis_raw,
			'series'        => $series,
			'traffic_chart' => $traffic_chart,
			'channels'      => $channels,
			'pages'         => $pages,
			'products'      => $products,
			'statuses'      => $statuses,
			'reconciliation' => $recon,
			'warnings'      => $warnings,
			'compare'       => $this->compare_summary( $range, $kpis_raw, $reports, $currency ),
			'settings'      => ADC_Settings::all( ADC_Analytics_Settings::GROUP ),
			'paid_statuses' => array(
				'selected' => ADC_Analytics_Settings::paid_statuses(),
				'options'  => self::status_options(),
				'diverges' => $this->purchase_statuses_diverge(),
			),
			'figures'       => $this->kpi_figures( $kpis_raw, $currency, $traffic ),
			'unavailable'   => $this->unavailable_rows(),
			'notice_draft'  => $this->privacy_notice_draft(),
			'filter_help'   => array(
				'range'     => __( 'Interval', 'adc-marketing' ),
				'from'      => __( 'Data început', 'adc-marketing' ),
				'to'        => __( 'Data sfârșit', 'adc-marketing' ),
				'currency'  => __( 'Monedă', 'adc-marketing' ),
				'channel'   => __( 'Canal', 'adc-marketing' ),
				'page_type' => __( 'Tip pagină', 'adc-marketing' ),
				'product'   => __( 'Produs', 'adc-marketing' ),
			),
		);
	}

	/**
	 * Read and sanitize the GET filter state (read-only navigation, no nonce).
	 *
	 * @return array<string,mixed>
	 */
	private function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only report filters.
		$raw = isset( $_GET ) && is_array( $_GET ) ? wp_unslash( $_GET ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$range = isset( $raw['range'] ) ? strtolower( trim( (string) $raw['range'] ) ) : '';
		if ( ! isset( self::RANGES[ $range ] ) && 'custom' !== $range ) {
			$range = '30';
		}

		$from = self::valid_date( isset( $raw['from'] ) ? (string) $raw['from'] : '' );
		$to   = self::valid_date( isset( $raw['to'] ) ? (string) $raw['to'] : '' );

		if ( 'custom' === $range && ( '' === $from || '' === $to ) ) {
			// A custom range without both ends is not usable: fall back to the preset.
			$range = '30';
			$from  = '';
			$to    = '';
		}

		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', isset( $raw['currency'] ) ? (string) $raw['currency'] : '' ) );
		$currency = 3 === strlen( $currency ) ? $currency : '';

		$channel = isset( $raw['channel'] ) ? sanitize_key( (string) $raw['channel'] ) : '';
		$channel = in_array( $channel, self::CHANNELS, true ) ? $channel : '';

		$page_type = isset( $raw['page_type'] ) ? sanitize_key( (string) $raw['page_type'] ) : '';
		$page_type = in_array( $page_type, self::PAGE_TYPES, true ) ? $page_type : '';

		$product = isset( $raw['product'] ) ? absint( $raw['product'] ) : 0;

		$top = isset( $raw['top'] ) ? (int) $raw['top'] : 10;
		$top = in_array( $top, array( 5, 10, 20, 50 ), true ) ? $top : 10;

		return array(
			'range'     => $range,
			'from'      => $from,
			'to'        => $to,
			'currency'  => $currency,
			'channel'   => $channel,
			'page_type' => $page_type,
			'product'   => $product,
			'top'       => $top,
		);
	}

	/**
	 * Base admin URL of this tab (used to rebuild filter links).
	 *
	 * @return string
	 */
	private function tab_url(): string {
		return admin_url( 'admin.php?page=' . ADC_Admin_Shell::PAGE_SLUG . '&tab=' . $this->slug() );
	}

	/**
	 * Build a filter link preserving every active filter.
	 *
	 * @param array<string,mixed> $overrides Changed parameters.
	 * @return string
	 */
	private function filter_url( array $overrides ): string {
		$params = array_merge( $this->filters(), $overrides );
		$params = array_filter(
			$params,
			static fn ( $value ): bool => ( is_string( $value ) && '' !== $value ) || ( is_int( $value ) && $value > 0 )
		);

		return add_query_arg( array_map( 'strval', $params ), $this->tab_url() );
	}

	/**
	 * Reset link: the tab without any report filter.
	 *
	 * @return string
	 */
	private function reset_url(): string {
		return $this->tab_url();
	}

	/* --------------------------------------------------------------------- *
	 * Backend compatibility
	 * --------------------------------------------------------------------- */

	/**
	 * Whether the reporting façade is present (chunk F4).
	 */
	private function reports_ready(): bool {
		return class_exists( 'ADC_Analytics_Reports' );
	}

	/**
	 * Call a Reports method defensively; unknown or missing methods yield $fallback.
	 *
	 * @param string $method   Method name from the §11.3 contract.
	 * @param array  $args     Positional arguments.
	 * @param mixed  $fallback Value returned when the method is unavailable.
	 * @return mixed
	 */
	private function report_call( string $method, array $args, mixed $fallback ): mixed {
		if ( ! class_exists( 'ADC_Analytics_Reports' ) || ! method_exists( 'ADC_Analytics_Reports', $method ) ) {
			return $fallback;
		}

		$result = call_user_func_array( array( 'ADC_Analytics_Reports', $method ), $args );

		return null === $result ? $fallback : $result;
	}

	/**
	 * Report range resolved by the backend, with a local fallback so that the
	 * dashboard never invents dates that the CSV export would not accept.
	 *
	 * @param array<string,mixed> $filters Filter state.
	 * @return array{from:string,to:string,prev_from:string,prev_to:string}
	 */
	private function range_for( array $filters ): array {
		$range = $this->report_call(
			'range',
			array( $filters['range'], $filters['from'], $filters['to'] ),
			array()
		);

		if ( is_array( $range ) && isset( $range['from'], $range['to'] ) ) {
			return array(
				'from'      => self::valid_date( (string) $range['from'] ) ?: $this->local_range( $filters )['from'],
				'to'        => self::valid_date( (string) $range['to'] ) ?: $this->local_range( $filters )['to'],
				'prev_from' => self::valid_date( (string) ( $range['prev_from'] ?? '' ) ),
				'prev_to'   => self::valid_date( (string) ( $range['prev_to'] ?? '' ) ),
			);
		}

		return $this->local_range( $filters );
	}

	/**
	 * Local range mirror (Y-m-d, site timezone) used when Reports is absent and
	 * as a sanity fallback for malformed backend values.
	 *
	 * @param array<string,mixed> $filters Filter state.
	 * @return array{from:string,to:string,prev_from:string,prev_to:string}
	 */
	private function local_range( array $filters ): array {
		$today = (string) current_time( 'Y-m-d' );

		if ( 'custom' === $filters['range'] && '' !== $filters['from'] && '' !== $filters['to'] ) {
			$from = $filters['from'];
			$to   = $filters['to'];
			if ( $from > $to ) {
				[ $from, $to ] = array( $to, $from );
			}
		} else {
			$days = self::RANGES[ $filters['range'] ] ?? 30;
			$to   = $today;
			$from = gmdate( 'Y-m-d', strtotime( $today . ' -' . ( $days - 1 ) . ' days' ) );
		}

		$span  = max( 1, (int) ( ( strtotime( $to ) - strtotime( $from ) ) / 86400 ) + 1 );
		$prev_to   = gmdate( 'Y-m-d', strtotime( $from . ' -1 day' ) );
		$prev_from = gmdate( 'Y-m-d', strtotime( $prev_to . ' -' . ( $span - 1 ) . ' days' ) );

		return array(
			'from'      => $from,
			'to'        => $to,
			'prev_from' => $prev_from,
			'prev_to'   => $prev_to,
		);
	}

	/**
	 * Active currency: explicit filter, then shop currency, then the first
	 * currency that actually has rows. Never a cross-currency total.
	 *
	 * @param array<string,mixed> $filters Filter state.
	 * @param bool                $reports Whether the reporting façade is available.
	 */
	private function currency_for( array $filters, bool $reports ): string {
		if ( '' !== $filters['currency'] ) {
			return $filters['currency'];
		}

		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$shop = strtoupper( (string) get_woocommerce_currency() );
			if ( 3 === strlen( $shop ) ) {
				return $shop;
			}
		}

		$list = $this->currencies( $reports );

		return (string) ( $list[0] ?? '' );
	}

	/**
	 * Currencies that exist in the aggregated data.
	 *
	 * @param bool $reports Whether the reporting façade is available.
	 * @return string[]
	 */
	private function currencies( bool $reports ): array {
		if ( ! $reports ) {
			return array();
		}

		$raw = $this->report_call( 'available_currencies', array(), array() );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		// Envelope shape: {available, reason, base, currencies[], ...}. The
		// `currencies` list is authoritative; a legacy flat list still works.
		$list = isset( $raw['currencies'] ) && is_array( $raw['currencies'] ) ? $raw['currencies'] : $raw;

		$out = array();
		foreach ( $list as $value ) {
			$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', is_string( $value ) ? $value : (string) $value ) );
			if ( 3 === strlen( $code ) ) {
				$out[ $code ] = $code;
			}
		}

		return array_values( $out );
	}

	/* --------------------------------------------------------------------- *
	 * Normalization of backend payloads
	 * --------------------------------------------------------------------- */

	/**
	 * First non-null value among candidate keys (row shapes may vary).
	 *
	 * @param array<string,mixed> $row  Report row.
	 * @param string[]            $keys Candidate keys, in order.
	 */
	private static function pick( array $row, array $keys ): mixed {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && '' !== $row[ $key ] && null !== $row[ $key ] ) {
				return $row[ $key ];
			}
		}

		return null;
	}

	/**
	 * Numeric read of a candidate key.
	 *
	 * Deprecated adapter shim: business values must never be re-derived in the
	 * UI, and null (suppressed/unavailable) must never collapse to 0. Series and
	 * ranking adapters read the exact backend fields and preserve null.
	 *
	 * @param array<string,mixed> $row       Report row.
	 * @param string[]            $keys      Candidate keys.
	 * @param float               $fallback  Returned when nothing numeric is found.
	 * @return float|null Null when the value is missing/null (caller must not plot zero).
	 */
	private static function num( array $row, array $keys, float $fallback = 0.0 ): ?float {
		$value = self::pick( $row, $keys );

		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Coerce an unknown backend payload into a list of associative rows.
	 *
	 * Handles list-of-rows, map-of-rows (key => row, e.g. date => totals) and
	 * metric maps (key => scalar).
	 *
	 * @param mixed  $payload Raw Reports value.
	 * @param string $key_name Column to inject from a map key when absent.
	 * @return array<int,array<string,mixed>>
	 */
	private static function rows_of( mixed $payload, string $key_name = 'key' ): array {
		if ( ! is_array( $payload ) || $payload === array() ) {
			return array();
		}

		$rows = array();
		foreach ( $payload as $key => $value ) {
			if ( is_array( $value ) ) {
				$row  = array();
				foreach ( $value as $k => $v ) {
					$row[ is_string( $k ) ? $k : 'value' ] = $v;
				}
				if ( ! is_string( $key ) ) {
					$rows[] = $row;
					continue;
				}
				$col = in_array( $key_name, array( 'key', 'date' ), true ) ? $key_name : $key_name;
				if ( ! isset( $row[ $col ] ) ) {
					$row[ $col ] = $key;
				}
				$rows[] = $row;
				continue;
			}

			if ( is_string( $key ) ) {
				$rows[] = array(
					$key_name => $key,
					'value'   => $value,
				);
			}
		}

		return $rows;
	}

	/**
	 * Unwrap a Reports envelope ({available, reason, rows[], ...}) into its rows
	 * plus availability metadata. A plain row list passes through untouched; an
	 * unavailable envelope surfaces the backend reason instead of empty rows
	 * that would read as "no data".
	 *
	 * @param mixed  $payload  Raw Reports payload.
	 * @param string $rows_key Envelope key that carries the rows.
	 * @return array{rows:array<int,array<string,mixed>>,available:bool,reason:string}
	 */
	private static function envelope_rows( mixed $payload, string $rows_key = 'rows' ): array {
		if ( ! is_array( $payload ) || $payload === array() ) {
			return array( 'rows' => array(), 'available' => false, 'reason' => '' );
		}

		if ( array_key_exists( $rows_key, $payload ) ) {
			$rows             = is_array( $payload[ $rows_key ] ) ? array_values( $payload[ $rows_key ] ) : array();
			$available        = ! empty( $payload['available'] );
			return array(
				'rows'     => array_values( array_filter( $rows, 'is_array' ) ),
				'available' => $available,
				'reason'   => (string) ( $payload['reason'] ?? '' ),
			);
		}

		// Plain list of rows (e.g. warnings) passes through.
		$all_rows = true;
		foreach ( $payload as $value ) {
			if ( ! is_array( $value ) ) {
				$all_rows = false;
				break;
			}
		}
		if ( $all_rows && array_is_list( $payload ) ) {
			return array( 'rows' => array_values( $payload ), 'available' => true, 'reason' => '' );
		}

		return array( 'rows' => array(), 'available' => false, 'reason' => '' );
	}

	/**
	 * KPI map from whatever the backend returns (map, list of label/value, or
	 * nested ['kpis' => …] wrapper).
	 *
	 * @param mixed $payload Raw KPI payload.
	 * @return array<string,float>
	 */
	private static function normalize_kpis( mixed $payload ): array {
		if ( is_array( $payload ) && isset( $payload['kpis'] ) && is_array( $payload['kpis'] ) ) {
			$payload = $payload['kpis'];
		}

		$out = array();

		foreach ( self::rows_of( $payload, 'label' ) as $row ) {
			$label = strtolower( trim( (string) self::pick( $row, array( 'label', 'key', 'metric', 'name', 'code' ) ) ) );
			$value = self::pick( $row, array( 'value', 'total', 'count', 'amount', 'display' ) );

			if ( '' === $label || ! is_numeric( $value ) ) {
				continue;
			}

			$out[ $label ] = (float) $value;
		}

		// Flat map: metric => scalar.
		if ( is_array( $payload ) ) {
			foreach ( $payload as $key => $value ) {
				if ( is_string( $key ) && is_numeric( $value ) ) {
					$out[ strtolower( $key ) ] = (float) $value;
				}
			}
		}

		return $out;
	}

	/**
	 * Value of a KPI, tolerant to the aliases the reporting façade may expose.
	 *
	 * @param array<string,float> $kpis   Normalized KPI map.
	 * @param string[]            $aliases Candidate keys.
	 */
	private static function kpi( array $kpis, array $aliases ): ?float {
		foreach ( $aliases as $alias ) {
			if ( isset( $kpis[ $alias ] ) ) {
				return (float) $kpis[ $alias ];
			}
		}

		return null;
	}

	/* --------------------------------------------------------------------- *
	 * Sections
	 * --------------------------------------------------------------------- */

	/**
	 * Gate status rows shown at the top of the dashboard.
	 *
	 * @param bool $commerce Commerce gate state (for the label only).
	 * @param bool $traffic  Traffic gate state.
	 * @return array<int,array<string,string>>
	 */
	private function gate_rows( bool $commerce, bool $traffic ): array {
		unset( $commerce );

		$features = array(
			'commerce'       => __( 'Rapoarte financiare din comenzi', 'adc-marketing' ),
			'traffic'        => __( 'Numărare trafic (estimativă)', 'adc-marketing' ),
			'utm'            => __( 'Parametri UTM', 'adc-marketing' ),
			'add_to_cart'    => __( 'Eveniment „adăugare în coș”', 'adc-marketing' ),
			'begin_checkout' => __( 'Eveniment „începere finalizare”', 'adc-marketing' ),
		);

		$rows = array();
		foreach ( $features as $feature => $label ) {
			$status = ADC_Analytics_Settings::legal_gate_status( $feature );
			$rows[] = array(
				'feature' => $feature,
				'label'   => $label,
				'state'   => $status['enabled'] ? 'on' : 'off',
				'text'    => $status['enabled']
					? __( 'Activ', 'adc-marketing' )
					: ( 'begin_checkout' === $feature ? __( 'Indisponibil', 'adc-marketing' ) : __( 'Oprit', 'adc-marketing' ) ),
				'reason'  => $status['reason'],
			);
		}

		unset( $traffic );

		return $rows;
	}

	/**
	 * Metrics that cannot be produced by design (§7.1), shown as a fact list.
	 *
	 * @return array<int,array{label:string,text:string}>
	 */
	private function unavailable_rows(): array {
		return array(
			array(
				'label' => __( 'Vizitatori unici', 'adc-marketing' ),
				'text'  => __( 'Indisponibil: identificatorii sunt interziși în acest modul.', 'adc-marketing' ),
			),
			array(
				'label' => __( 'Rată de conversie', 'adc-marketing' ),
				'text'  => __( 'Indisponibil: cererile estimate și comenzile nu pot fi împărțite una la alta.', 'adc-marketing' ),
			),
			array(
				'label' => __( 'Attribution (primul/ultimul contact)', 'adc-marketing' ),
				'text'  => __( 'Indisponibil: canalul din referer este orientativ, nu o atribuire.', 'adc-marketing' ),
			),
			array(
				'label' => __( 'Abandon de coș', 'adc-marketing' ),
				'text'  => __( 'Indisponibil: nu există eveniment de începere a finalizării verificat pe server.', 'adc-marketing' ),
			),
			array(
				'label' => __( 'Durată vizită / sesiuni', 'adc-marketing' ),
				'text'  => __( 'Indisponibil: fără JavaScript de urmărire.', 'adc-marketing' ),
			),
		);
	}

	/**
	 * Traffic line chart (estimated). Renders only when the backend reports an
	 * available envelope; suppressed days keep their x position but are never
	 * plotted as zero.
	 *
	 * @param array<string,string> $range   Report range.
	 * @param array<string,mixed>  $filters Active filters.
	 * @return array<string,mixed>
	 */
	private function traffic_chart( array $range, array $filters ): array {
		$args = array(
			$range,
			array_filter(
				array(
					'channel'   => (string) $filters['channel'],
					'page_type' => (string) $filters['page_type'],
				),
				static fn ( string $v ): bool => '' !== $v
			),
		);

		$env = self::envelope_rows( $this->report_call( 'traffic_series', $args, array() ) );
		if ( ! $env['available'] || $env['rows'] === array() ) {
			return array();
		}

		$points    = array();
		$suppressed = 0;
		foreach ( $env['rows'] as $row ) {
			// Exact backend field: `views` is nulled for suppressed rows.
			$is_suppressed = ! empty( $row['suppressed'] ) || ! isset( $row['views'] ) || ! is_numeric( $row['views'] );
			$points[]  = array(
				'date'       => (string) ( $row['date'] ?? '' ),
				'value'      => $is_suppressed ? null : (float) $row['views'],
				'suppressed' => $is_suppressed,
			);
			$suppressed += $is_suppressed ? 1 : 0;
		}

		$chart = $this->line_chart( $points, __( 'cereri estimate', 'adc-marketing' ) );

		$chart['suppressed'] = $suppressed;

		return $chart;
	}

	/**
	 * Orders (bars) + gross revenue (line) per day.
	 *
	 * @param array<string,string> $range    Report range.
	 * @param string               $currency Active currency.
	 * @return array<string,mixed>
	 */
	private function commerce_chart( array $range, string $currency ): array {
		$env = self::envelope_rows( $this->report_call( 'commerce_series', array( $range, $currency ), array() ) );
		if ( ! $env['available'] || $env['rows'] === array() ) {
			return array();
		}

		$dates     = array();
		$orders    = array();
		$revenue   = array();
		$suppressed = 0;

		foreach ( $env['rows'] as $row ) {
			// Exact backend fields; suppressed rows carry null on these keys.
			$is_suppressed = ! empty( $row['suppressed'] );
			$orders_value  = ( ! $is_suppressed && isset( $row['orders_paid'] ) && is_numeric( $row['orders_paid'] ) ) ? (float) $row['orders_paid'] : null;
			$revenue_value = ( ! $is_suppressed && isset( $row['gross_revenue'] ) && is_numeric( $row['gross_revenue'] ) ) ? (float) $row['gross_revenue'] : null;

			if ( $is_suppressed || null === $orders_value || null === $revenue_value ) {
				$suppressed++;
			}

			$dates[]    = (string) ( $row['date'] ?? '' );
			$orders[]   = $orders_value;
			$revenue[]  = $revenue_value;
		}

		$chart = $this->combo_chart( $dates, $orders, $revenue, $currency );

		$chart['suppressed'] = $suppressed;

		return $chart;
	}

	/**
	 * Channel distribution rows with CSS bars.
	 *
	 * @param array<string,string> $range     Report range.
	 * @param array<string,mixed>  $filters   Active filters.
	 * @return array<string,mixed>
	 */
	private function channel_rows( array $range, array $filters ): array {
		$args = array_filter(
			array(
				'channel'   => (string) $filters['channel'],
				'page_type' => (string) $filters['page_type'],
			),
			static fn ( string $v ): bool => '' !== $v
		);

		$env = self::envelope_rows( $this->report_call( 'channel_distribution', array( $range, $args ), array() ) );

		return $this->ranked_rows(
			$env,
			'label',
			'views',
			null,
			''
		);
	}

	/**
	 * Top pages rows with resolved titles.
	 *
	 * @param array<string,string> $range     Report range.
	 * @param array<string,mixed>  $filters   Active filters.
	 * @param int                  $threshold Suppression floor (unused: Reports owns suppression).
	 * @return array<string,mixed>
	 */
	private function page_rows( array $range, array $filters, int $threshold ): array {
		unset( $threshold ); // Suppression flag comes from the backend only.

		$args = array_filter(
			array(
				'channel'   => (string) $filters['channel'],
				'page_type' => (string) $filters['page_type'],
			),
			static fn ( string $v ): bool => '' !== $v
		);

		$env = self::envelope_rows( $this->report_call( 'top_pages', array( $range, $args, (int) $filters['top'] ), array() ) );

		return $this->ranked_rows(
			$env,
			'label',
			'views',
			static function ( array $row, string $label ): string {
				if ( '' !== $label ) {
					return $label; // Backend safe identifier (never a raw URL).
				}

				// Titles are resolved at render time and never stored (§9.6).
				$id    = (int) ( $row['object_id'] ?? 0 );
				$title = $id > 0 && function_exists( 'get_the_title' ) ? (string) get_the_title( $id ) : '';

				if ( '' !== $title ) {
					return $title;
				}

				return $id > 0
					? sprintf(
						/* translators: %d: identifier of a deleted object. */
						__( 'Obiect șters (ID %d)', 'adc-marketing' ),
						$id
					)
					: __( 'Pagină fără identificator', 'adc-marketing' );
			},
			''
		);
	}

	/**
	 * Top products rows, narrowed by the product filter (backend-side filter,
	 * identical to the CSV export).
	 *
	 * @param array<string,string> $range     Report range.
	 * @param string               $currency  Active currency.
	 * @param array<string,mixed>  $filters   Active filters.
	 * @param int                  $threshold Suppression floor (unused: Reports owns suppression).
	 * @return array<string,mixed>
	 */
	private function product_rows( array $range, string $currency, array $filters, int $threshold ): array {
		unset( $threshold ); // Suppression flag comes from the backend only.

		$args = array();
		if ( (int) $filters['product'] > 0 ) {
			$args['product_id'] = (int) $filters['product'];
		}

		$env = self::envelope_rows( $this->report_call( 'top_products', array( $range, $currency, (int) $filters['top'], $args ), array() ) );

		return $this->ranked_rows(
			$env,
			'label',
			'units',
			static function ( array $row, string $label ): string {
				if ( '' !== $label ) {
					return $label;
				}

				$id    = (int) ( $row['product_id'] ?? 0 );
				$title = $id > 0 && function_exists( 'get_the_title' ) ? (string) get_the_title( $id ) : '';

				if ( '' !== $title ) {
					return $title;
				}

				return $id > 0
					? sprintf(
						/* translators: %d: product id. */
						__( 'Produs șters (ID %d)', 'adc-marketing' ),
						$id
					)
					: __( 'Produs nesemnalat', 'adc-marketing' );
			},
			'net',
			$currency
		);
	}

	/**
	 * Shared shaping for ranked lists (label + measure + bar).
	 *
	 * The backend (Reports::suppress_rows) owns suppression, including the
	 * complementary rule; suppressed rows carry `suppressed => true` and null
	 * values. This adapter NEVER re-judges a value against the local threshold
	 * (that would unsuppress complementary cells or invent its own floor).
	 *
	 * @param array{rows:array<int,array<string,mixed>>,available:bool,reason:string} $env       Unwrapped envelope.
	 * @param string                                                                   $label_key Exact label field.
	 * @param string                                                                   $value_key Exact measure field.
	 * @param callable|null                                                            $label_fn  fn(array $row, string $label): string — render-time label resolution.
	 * @param string                                                                   $money_key Optional exact money field rendered as a second column.
	 * @param string                                                                   $currency  Currency for the money column.
	 * @return array{rows:array<int,array<string,mixed>>,total:float,max:float,hidden:int}
	 */
	private function ranked_rows( array $env, string $label_key, string $value_key, ?callable $label_fn = null, string $money_key = '', string $currency = '' ): array {
		$items  = array();
		$max    = 0.0;
		$total  = 0.0;
		$hidden = 0;

		foreach ( (array) $env['rows'] as $row ) {
			$suppressed = ! empty( $row['suppressed'] );
			$raw_value  = $row[ $value_key ] ?? null;
			$value      = is_numeric( $raw_value ) ? (float) $raw_value : null;

			// Suppressed (small or complementary) and unavailable cells are never
			// plotted or printed — and never reported as zero.
			if ( $suppressed || null === $value ) {
				$hidden++;
				continue;
			}

			$label = trim( (string) ( $row[ $label_key ] ?? '' ) );

			if ( null !== $label_fn ) {
				$label = (string) $label_fn( $row, $label );
			}

			if ( '' === $label ) {
				$hidden++;
				continue;
			}

			$id = (int) ( $row['object_id'] ?? $row['product_id'] ?? 0 );

			$max   = max( $max, $value );
			$total += $value;

			$item = array(
				'label' => $label,
				'id'    => $id,
				'value' => $value,
				'money' => '',
			);

			if ( '' !== $money_key && isset( $row[ $money_key ] ) && is_numeric( $row[ $money_key ] ) ) {
				$item['money'] = self::money( (float) $row[ $money_key ], $currency );
			}

			$items[] = $item;
		}

		foreach ( $items as &$item ) {
			$item['pct'] = $max > 0.0 ? (int) round( ( $item['value'] / $max ) * 100 ) : 0;
		}
		unset( $item );

		usort(
			$items,
			static fn ( array $a, array $b ): int => ( $b['value'] <=> $a['value'] ) ?: strcmp( (string) $a['label'], (string) $b['label'] )
		);

		return array(
			'rows'   => $items,
			'total'  => $total,
			'max'    => $max,
			'hidden' => $hidden,
		);
	}

	/**
	 * Current orders per status (live snapshot) + status_reached facts.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function status_rows(): array {
		// Exact envelope: {available, reason, counts:{status:count}, statuses[], ...}.
		$raw    = $this->report_call( 'current_order_counts', array(), array() );
		$counts = array();

		if ( is_array( $raw ) && isset( $raw['counts'] ) && is_array( $raw['counts'] ) ) {
			foreach ( $raw['counts'] as $status => $count ) {
				$status = strtolower( trim( (string) $status ) );
				if ( '' !== $status && is_numeric( $count ) ) {
					$counts[ $status ] = (float) $count;
				}
			}
		}

		$rows = array();
		foreach ( $counts as $status => $count ) {
			$rows[] = array(
				'status' => $status,
				'label'  => function_exists( 'wc_get_order_status_name' ) ? (string) wc_get_order_status_name( $status ) : $status,
				'count'  => $count,
				'paid'   => in_array( $status, ADC_Analytics_Settings::paid_statuses(), true ),
			);
		}

		usort(
			$rows,
			static fn ( array $a, array $b ): int => ( $b['count'] <=> $a['count'] ) ?: strcmp( (string) $a['status'], (string) $b['status'] )
		);

		return $rows;
	}

	/**
	 * Aggregate-vs-orders differences (§9.4), reported not silently fixed.
	 *
	 * @param array<string,string> $range    Report range.
	 * @param string               $currency Active currency.
	 * @return array<int,array<string,mixed>>
	 */
	private function reconciliation_rows( array $range, string $currency ): array {
		// Exact envelope: {available, reason, days:[{date, diff:{metric:float}, warn}], ...}.
		$raw = $this->report_call( 'reconciliation', array( $range, $currency ), array() );

		if ( ! is_array( $raw ) ) {
			return array( 'rows' => array(), 'available' => false, 'reason' => '' );
		}

		$rows = array();
		foreach ( (array) ( $raw['days'] ?? array() ) as $day ) {
			if ( ! is_array( $day ) ) {
				continue;
			}
			$date = (string) ( $day['date'] ?? '' );
			foreach ( (array) ( $day['diff'] ?? array() ) as $metric => $diff ) {
				if ( ! is_string( $metric ) || '' === $metric || ! is_numeric( $diff ) ) {
					continue;
				}
				$rows[] = array(
					'metric' => $metric,
					'date'   => $date,
					'diff'   => (float) $diff,
					'label'  => self::metric_label( $metric ),
					'show'   => self::is_money_metric( $metric ),
					'notify' => abs( (float) $diff ) > 0.01,
				);
			}
		}

		return array(
			'rows'      => $rows,
			'available' => ! empty( $raw['available'] ),
			'reason'    => (string) ( $raw['reason'] ?? '' ),
		);
	}

	/**
	 * Warning list: backend warnings + the fixed privacy/precision caveats.
	 *
	 * @param array<string,string>  $range   Report range.
	 * @param bool                  $reports Whether the reporting façade is available.
	 * @param array<int,array>      $recon   Reconciliation rows.
	 * @param array<string,mixed>   $series  Commerce chart payload.
	 * @param array<string,mixed>   $traffic Traffic chart payload.
	 * @return array<int,array{tone:string,text:string}>
	 */
	private function warning_rows( array $range, bool $reports, array $recon, array $series, array $traffic ): array {
		$out = array();

		if ( ! $reports ) {
			$out[] = array(
				'tone' => 'info',
				'text' => __( 'Motorul de rapoarte nu este disponibil în această instalare: se afișează doar setările. Cifrele nu sunt estimate manual.', 'adc-marketing' ),
			);

			return $out;
		}

		foreach ( self::rows_of( $this->report_call( 'warnings', array( $range ), array() ), 'message' ) as $row ) {
			$text = trim( (string) ( self::pick( $row, array( 'message', 'text', 'warning', 'key', 'value' ) ) ?? '' ) );
			if ( '' !== $text && ! is_numeric( $text ) ) {
				$out[] = array(
					'tone' => 'warning',
					'text' => $text,
				);
			}
		}

		// Completeness of the interval: today is not finished yet.
		$today = (string) current_time( 'Y-m-d' );
		if ( $range['to'] >= $today ) {
			$out[] = array(
				'tone' => 'info',
				'text' => __( 'Ultima zi din interval nu este încheiată; comparația cu intervalul anterior este afișată doar când ambele intervale sunt complete.', 'adc-marketing' ),
			);
		}

		foreach ( $recon as $row ) {
			if ( ! empty( $row['notify'] ) ) {
				$out[] = array(
					'tone' => 'warning',
					/* translators: %s: metric label. */
					'text'  => sprintf( __( 'Diferență de recalculare pentru „%s”: agregatul nu corespunde cu totalul direct din comenzi. Verificați manual.', 'adc-marketing' ), $row['label'] ),
				);
			}
		}

		$suppressed = (int) ( $series['suppressed'] ?? 0 ) + (int) ( $traffic['suppressed'] ?? 0 );
		if ( $suppressed > 0 ) {
			$out[] = array(
				'tone' => 'info',
				/* translators: %d: number of suppressed cells. */
				'text'  => sprintf( __( '%d celulă (celule) sub pragul de %d valori au fost ascunse pentru a evita identificarea indirectă.', 'adc-marketing' ), $suppressed, ADC_Analytics_Settings::min_cell_count() ),
			);
		}

		$out[] = array(
			'tone' => 'info',
			'text' => __( 'Cererile de pagină sunt estimative: cache/CDN nu înregistrează toate vizitele, iar roboții pot fi filtrați parțial.', 'adc-marketing' ),
		);

		return $out;
	}

	/**
	 * Previous-period comparison, only when both ranges are complete.
	 *
	 * @param array<string,string>  $range    Report range.
	 * @param array<string,float>   $kpis     Normalized KPI map.
	 * @param bool                  $reports  Whether the reporting façade is available.
	 * @param string                $currency Active currency for the previous period.
	 * @return array<string,mixed>
	 */
	private function compare_summary( array $range, array $kpis, bool $reports, string $currency = '' ): array {
		if ( ! $reports ) {
			return array( 'available' => false, 'text' => __( 'Comparație indisponibilă: motorul de rapoarte lipsește.', 'adc-marketing' ), 'rows' => array() );
		}

		$complete = '' !== $range['prev_from'] && '' !== $range['prev_to'] && $range['prev_to'] < (string) current_time( 'Y-m-d' ) && $range['to'] < (string) current_time( 'Y-m-d' );

		if ( ! $complete ) {
			return array(
				'available' => false,
				'text'      => __( 'Interval incomplet — comparația cu perioada anterioară nu este afișată.', 'adc-marketing' ),
				'rows'      => array(),
			);
		}

		$prev = self::normalize_kpis( (array) $this->report_call( 'kpis', array( array( 'from' => $range['prev_from'], 'to' => $range['prev_to'] ), $currency ), array() ) );

		$rows = array();
		foreach ( array(
			'orders_paid'   => __( 'Comenzi plătite', 'adc-marketing' ),
			'gross_revenue' => __( 'Venit brut', 'adc-marketing' ),
			'net_revenue'   => __( 'Venit net', 'adc-marketing' ),
			'refunds'       => __( 'Rambursări', 'adc-marketing' ),
		) as $key => $label ) {
			$now  = self::kpi( $kpis, array( $key, str_replace( '_', '', $key ), $key . '_total' ) );
			$before = self::kpi( $prev, array( $key, str_replace( '_', '', $key ), $key . '_total' ) );

			if ( null === $now || null === $before || 0.0 === $before ) {
				continue;
			}

			$delta = ( ( $now - $before ) / abs( $before ) ) * 100;

			$rows[] = array(
				'label'  => $label,
				'delta'  => $delta,
				'text'   => ( $delta >= 0 ? '+' : '−' ) . number_format_i18n( abs( $delta ), 1 ) . ' %',
				'tone'   => $delta >= 0 ? 'up' : 'down',
				'before' => $before,
				'now'    => $now,
				'money'  => 0 !== strpos( $key, 'orders' ),
			);
		}

		return array(
			'available' => $rows !== array(),
			'text'      => $rows === array() ? __( 'Fără date comparabile pentru intervalul anterior.', 'adc-marketing' ) : '',
			'rows'      => $rows,
			'range'     => $range['prev_from'] . ' → ' . $range['prev_to'],
		);
	}

	/* --------------------------------------------------------------------- *
	 * Chart geometry
	 * --------------------------------------------------------------------- */

	/**
	 * Single-series line chart (traffic estimate).
	 *
	 * Suppressed points (backend flag, null value) keep their x spacing but are
	 * never plotted — no fabricated zero, no threshold re-judgement here.
	 *
	 * @param array<int,array{date:string,value:?float,suppressed:bool}> $points Series points.
	 * @param string                                                     $unit   Axis unit label.
	 * @return array<string,mixed>
	 */
	private function line_chart( array $points, string $unit ): array {
		$visible = array_filter(
			array_map( static fn ( array $point ): ?float => empty( $point['suppressed'] ) && null !== $point['value'] ? (float) $point['value'] : null, $points ),
			static fn ( ?float $value ): bool => null !== $value
		);
		$max    = $visible ? max( $visible ) : 0.0;
		$axis   = $this->nice_max( (float) $max );
		$plot   = $this->plot_box();

		$path  = '';
		$area  = '';
		$marks = array();

		$last_visible_index = -2;
		$first_mark = null;
		$last_mark  = null;

		foreach ( $points as $i => $point ) {
			if ( ! empty( $point['suppressed'] ) || null === $point['value'] ) {
				continue; // Suppressed cell: no point, no zero, no path segment.
			}

			$x = $this->x_at( (int) $i, count( $points ), $plot );
			$y = $this->y_at( (float) $point['value'], $axis, $plot );

			// A gap (suppressed neighbour) starts a new subpath instead of a
			// misleading straight line across the hidden cell.
			$path .= ( $last_visible_index + 1 === (int) $i ? 'L' : 'M' ) . round( $x, 1 ) . ' ' . round( $y, 1 ) . ' ';
			$marks[] = array(
				'cx'        => round( $x, 1 ),
				'cy'        => round( $y, 1 ),
				'date'      => (string) $point['date'],
				'label'     => $this->date_label( (string) $point['date'] ),
				'value'     => number_format_i18n( (float) $point['value'], 0 ),
				'suppressed' => false,
			);
			$last_visible_index = (int) $i;
			$first_mark ??= $marks[0];
			$last_mark  = $marks[ count( $marks ) - 1 ];
		}

		if ( null !== $first_mark && null !== $last_mark && count( $marks ) > 1 ) {
			$area = sprintf( 'M%s %s L%s %s L%s %s L%s %s Z', $first_mark['cx'], $plot['bottom'], $first_mark['cx'], $first_mark['cy'], $last_mark['cx'], $last_mark['cy'], $last_mark['cx'], $plot['bottom'] );
		}

		$summary = '';
		if ( $marks !== array() ) {
			$peak = max( $visible );
			$peak_date = '';
			foreach ( $points as $point ) {
				if ( empty( $point['suppressed'] ) && null !== $point['value'] && (float) $point['value'] === $peak ) {
					$peak_date = (string) $point['date'];
					break;
				}
			}
			$summary = sprintf(
				/* translators: 1: total, 2: peak value, 3: peak day. */
				__( '%1$s în total · maxim %2$s în ziua de %3$s', 'adc-marketing' ),
				number_format_i18n( array_sum( $visible ), 0 ),
				number_format_i18n( $peak, 0 ),
				$this->date_label( $peak_date )
			);
		}

		return array(
			'kind'      => 'line',
			'view_box'  => '0 0 ' . self::CHART_W . ' ' . self::CHART_H,
			'path'      => trim( $path ),
			'area'      => $area,
			'marks'     => $marks,
			'grid'      => $this->grid( $axis, $plot, static fn ( float $v ): string => number_format_i18n( $v, 0 ) ),
			'xlabels'   => $this->x_labels( array_map( static fn ( array $p ): array => array( 'date' => (string) $p['date'] ), $points ), $plot, $this->x_steps( count( $points ) ) ),
			'rows'      => $marks,
			'unit'      => $unit,
			'max'       => $axis,
			'summary'   => $summary,
			'has_data'  => $marks !== array(),
			'suppressed' => 0,
		);
	}

	/**
	 * Bars (orders) + line (gross revenue) on a second scale.
	 *
	 * Suppressed days (null orders/revenue, backend flag) are never plotted and
	 * never render as zero; the table shows the "under threshold" marker and
	 * the chart reports the hidden-cell count.
	 *
	 * @param string[] $dates     Day keys.
	 * @param array<int,?float> $orders   Paid orders per day (null = suppressed).
	 * @param array<int,?float> $revenue  Gross revenue per day (null = suppressed).
	 * @param string   $currency  Active currency.
	 * @return array<string,mixed>
	 */
	private function combo_chart( array $dates, array $orders, array $revenue, string $currency ): array {
		$visible_orders  = array_values( array_filter( $orders, static fn ( $v ): bool => null !== $v ) );
		$visible_revenue = array_values( array_filter( $revenue, static fn ( $v ): bool => null !== $v ) );
		$axis_money  = $this->nice_max( $visible_revenue ? (float) max( $visible_revenue ) : 0.0 );
		$axis_count  = $this->nice_max( $visible_orders ? (float) max( $visible_orders ) : 0.0 );
		$plot        = $this->plot_box();
		$steps       = $this->x_steps( count( $dates ) );

		$bars = array();
		$path = '';
		$line = array();
		$rows = array();
		$n    = count( $dates );

		$band    = $n > 0 ? ( $plot['right'] - $plot['left'] ) / $n : 0.0;
		$bar_w   = max( 2.0, min( 22.0, $band * 0.55 ) );

		$last_visible_index = -2;

		foreach ( $dates as $i => $date ) {
			$orders_v = $orders[ $i ] ?? null;
			$money_v  = $revenue[ $i ] ?? null;
			$hidden   = null === $orders_v || null === $money_v;

			if ( ! $hidden ) {
				$x       = $this->x_at( (int) $i, $n, $plot );
				$bar_top = $this->y_at( (float) $orders_v, $axis_count, $plot );
				$line_y  = $this->y_at( (float) $money_v, $axis_money, $plot );

				$bars[] = array(
					'x'      => round( $x - $bar_w / 2, 1 ),
					'y'      => round( $bar_top, 1 ),
					'w'      => round( $bar_w, 1 ),
					'h'      => round( max( 0.0, $plot['bottom'] - $bar_top ), 1 ),
					'small'  => false, // Real suppression is decided by the backend only.
				);

				$path .= ( $last_visible_index + 1 === (int) $i ? 'L' : 'M' ) . round( $x, 1 ) . ' ' . round( $line_y, 1 ) . ' ';
				$line[] = array(
					'cx'    => round( $x, 1 ),
					'cy'    => round( $line_y, 1 ),
					'value' => self::money( (float) $money_v, $currency ),
					'date'  => $this->date_label( (string) $date ),
				);

				$last_visible_index = (int) $i;
			}

			$rows[] = array(
				'date'   => (string) $date,
				'label'  => $this->date_label( (string) $date ),
				'orders' => null === $orders_v ? '' : number_format_i18n( (float) $orders_v, 0 ),
				'money'  => null === $money_v ? '' : self::money( (float) $money_v, $currency ),
				'small'  => $hidden, // Rendered as the "sub pragă" marker, not as zero.
			);
		}

		$summary = '';
		if ( $visible_orders !== array() || $visible_revenue !== array() ) {
			$orders_total = array_sum( array_map( 'floatval', $visible_orders ) );
			$money_total  = array_sum( array_map( 'floatval', $visible_revenue ) );
			$peak = $visible_revenue ? max( $visible_revenue ) : 0.0;
			$peak_index = null !== $peak ? array_search( $peak, $revenue, true ) : false;

			$summary = sprintf(
				/* translators: 1: order count, 2: revenue, 3: best day. */
				__( '%1$s comenzi plătite · %2$s venit brut · cea mai bună zi: %3$s', 'adc-marketing' ),
				number_format_i18n( $orders_total, 0 ),
				self::money( (float) $money_total, $currency ),
				$this->date_label( false !== $peak_index ? (string) $dates[ $peak_index ] : '' )
			);
		}

		return array(
			'kind'      => 'combo',
			'view_box'  => '0 0 ' . self::CHART_W . ' ' . self::CHART_H,
			'path'      => trim( $path ),
			'area'      => '',
			'bars'      => $bars,
			'marks'     => $line,
			'grid'      => $this->grid(
				$axis_money,
				$plot,
				static fn ( float $v ): string => self::compact_money( $v )
			),
			'right_axis' => $this->grid(
				$axis_count,
				$plot,
				static fn ( float $v ): string => number_format_i18n( $v, 0 ),
				'right'
			),
			'xlabels'   => $this->x_labels( array_map( static fn ( string $d ): array => array( 'date' => $d ), $dates ), $plot, $steps ),
			'rows'      => $rows,
			'unit'      => $currency,
			'max'       => $axis_money,
			'summary'   => $summary,
			'has_data'  => $visible_orders !== array() || $visible_revenue !== array(),
			'suppressed' => 0,
		);
	}

	/**
	 * Drawable area of the SVG canvas.
	 *
	 * @return array{left:float,right:float,top:float,bottom:float}
	 */
	private function plot_box(): array {
		return array(
			'left'   => (float) self::CHART_PAD_L,
			'right'  => (float) ( self::CHART_W - self::CHART_PAD_R ),
			'top'    => (float) self::CHART_PAD_T,
			'bottom' => (float) ( self::CHART_H - self::CHART_PAD_B ),
		);
	}

	/**
	 * X coordinate for point $i of $count.
	 */
	private function x_at( int $i, int $count, array $plot ): float {
		if ( $count < 1 ) {
			return $plot['left'];
		}
		if ( 1 === $count ) {
			return ( $plot['left'] + $plot['right'] ) / 2;
		}

		return $plot['left'] + ( ( $plot['right'] - $plot['left'] ) * ( $i / ( $count - 1 ) ) );
	}

	/**
	 * Y coordinate for a value against a maximum.
	 */
	private function y_at( float $value, float $max, array $plot ): float {
		if ( $max <= 0.0 ) {
			return $plot['bottom'];
		}

		$ratio = max( 0.0, min( 1.0, $value / $max ) );

		return $plot['bottom'] - ( $ratio * ( $plot['bottom'] - $plot['top'] ) );
	}

	/**
	 * Round the axis maximum up to a readable step (1/2/2.5/5 × 10ⁿ, four steps).
	 *
	 * @param float $max Largest value in the series.
	 */
	private function nice_max( float $max ): float {
		if ( $max <= 0.0 || ! is_finite( $max ) ) {
			return 1.0;
		}

		$rough     = $max / 4;
		$magnitude = (float) pow( 10, floor( log10( $rough ) ) );
		$normal    = $rough / $magnitude;

		$step = match ( true ) {
			$normal <= 1   => 1,
			$normal <= 2   => 2,
			$normal <= 2.5 => 2.5,
			$normal <= 5   => 5,
			default        => 10,
		};

		return $step * $magnitude * 4;
	}

	/**
	 * Four horizontal gridlines with axis labels.
	 *
	 * @param float    $max    Scale maximum.
	 * @param array    $plot   Plot box.
	 * @param callable $format Value formatter.
	 * @param string   $axis   'left' or 'right'.
	 * @return array<int,array<string,mixed>>
	 */
	private function grid( float $max, array $plot, callable $format, string $axis = 'left' ): array {
		$lines = array();
		for ( $step = 0; $step <= 4; $step++ ) {
			$value = $max * ( $step / 4 );
			$y     = $this->y_at( $value, $max > 0.0 ? $max : 1.0, $plot );

			$lines[] = array(
				'y'     => round( $y, 1 ),
				'label' => (string) $format( (float) $value ),
				'x'     => 'right' === $axis ? (float) ( self::CHART_W - self::CHART_PAD_R + 8 ) : (float) ( self::CHART_PAD_L - 8 ),
				'anchor' => 'right' === $axis ? 'start' : 'end',
			);
		}

		return $lines;
	}

	/**
	 * Sparse x-axis labels (first, last and every $step-th day).
	 *
	 * @param array<int,array<string,mixed>> $points Series points.
	 * @param array                          $plot   Plot box.
	 * @param int                            $steps  Label every N points.
	 * @return array<int,array<string,mixed>>
	 */
	private function x_labels( array $points, array $plot, int $steps ): array {
		$count = count( $points );
		$out   = array();

		foreach ( $points as $i => $point ) {
			if ( 0 !== $i % $steps && $i !== $count - 1 ) {
				continue;
			}

			$out[] = array(
				'x'     => round( $this->x_at( (int) $i, $count, $plot ), 1 ),
				// Percentage of the plot width: the SVG stretches horizontally, so the
				// HTML axis labels are positioned in the same normalized space.
				'left'  => round( ( $this->x_at( (int) $i, $count, $plot ) / self::CHART_W ) * 100, 2 ),
				'y'     => $plot['bottom'] + 16,
				'anchor' => 0 === $i ? 'start' : ( $i === $count - 1 ? 'end' : 'center' ),
				'label' => $this->date_label( (string) ( $point['date'] ?? '' ) ),
			);
		}

		return $out;
	}

	/**
	 * Label density for the x axis.
	 */
	private function x_steps( int $count ): int {
		return match ( true ) {
			$count <= 10 => 1,
			$count <= 31 => 3,
			$count <= 62 => 7,
			default      => 14,
		};
	}

	/* --------------------------------------------------------------------- *
	 * Formatting helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Validate a Y-m-d string.
	 */
	private static function valid_date( string $value ): string {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );

		return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $value : '';
	}

	/**
	 * Short Romanian day label (d. M).
	 */
	private function date_label( string $date ): string {
		if ( '' === $date ) {
			return '';
		}

		$ts = strtotime( $date . ' 12:00:00' );
		if ( false === $ts ) {
			return $date;
		}

		$label = function_exists( 'wp_date' ) ? wp_date( 'j M', $ts ) : gmdate( 'j M', $ts );

		return (string) $label;
	}

	/**
	 * WooCommerce money formatting with a plain fallback.
	 *
	 * @param float  $value    Amount.
	 * @param string $currency Three-letter code.
	 */
	private static function money( float $value, string $currency ): string {
		if ( $currency === '' ) {
			return number_format_i18n( $value, 2 );
		}

		if ( function_exists( 'wc_price' ) ) {
			return (string) wp_kses_post(
				wc_price(
					$value,
					array( 'currency' => $currency )
				)
			);
		}

		return number_format_i18n( $value, 2 ) . ' ' . $currency;
	}

	/**
	 * Axis money label: compact, no decimals when not needed.
	 */
	private static function compact_money( float $value ): string {
		if ( $value >= 1000000 ) {
			return number_format_i18n( $value / 1000000, 1 ) . 'M';
		}
		if ( $value >= 1000 ) {
			return number_format_i18n( $value / 1000, 1 ) . 'k';
		}

		return number_format_i18n( $value, 0 );
	}

	/**
	 * Human label for a stored metric key.
	 */
	private static function metric_label( string $metric ): string {
		return (string) match ( $metric ) {
			'orders_paid'          => __( 'Comenzi plătite', 'adc-marketing' ),
			'orders_created'       => __( 'Comenzi create', 'adc-marketing' ),
			'order_total'          => __( 'Valoarea comenzilor', 'adc-marketing' ),
			'tax_total'            => __( 'Taxe', 'adc-marketing' ),
			'shipping_total'       => __( 'Transport', 'adc-marketing' ),
			'shipping_tax'         => __( 'Taxă transport', 'adc-marketing' ),
			'discount_total'       => __( 'Reduceri', 'adc-marketing' ),
			'discount_tax'         => __( 'Taxă reduceri', 'adc-marketing' ),
			'refund_total'         => __( 'Rambursări', 'adc-marketing' ),
			'items_net'            => __( 'Produse (fără taxă)', 'adc-marketing' ),
			'items_tax'            => __( 'Taxa produselor', 'adc-marketing' ),
			'units'                => __( 'Bucăți', 'adc-marketing' ),
			'status_reached'       => __( 'Stări atinse', 'adc-marketing' ),
			'gross_revenue'        => __( 'Venit brut', 'adc-marketing' ),
			'net_revenue'          => __( 'Venit net', 'adc-marketing' ),
			'refunds'              => __( 'Rambursări', 'adc-marketing' ),
			default               => ucwords( str_replace( array( '_', '-' ), ' ', $metric ) ),
		};
	}

	/**
	 * Whether a metric should be printed as money rather than a count.
	 */
	private static function is_money_metric( string $metric ): bool {
		$money = array(
			'order_total',
			'tax_total',
			'shipping_total',
			'shipping_tax',
			'discount_total',
			'discount_tax',
			'refund_total',
			'items_net',
			'items_tax',
			'gross_revenue',
			'net_revenue',
			'refunds',
		);

		return in_array( $metric, $money, true );
	}

	/**
	 * KPI figures for the summary panel.
	 *
	 * @param array<string,float> $kpis     Normalized KPI map.
	 * @param string              $currency Active currency.
	 * @param bool                $traffic  Traffic gate (always false this release).
	 * @return array<int,array<string,mixed>>
	 */
	private function kpi_figures( array $kpis, string $currency, bool $traffic ): array {

		$gross    = self::kpi( $kpis, array( 'gross_revenue', 'gross', 'order_total', 'revenue' ) );
		$net      = self::kpi( $kpis, array( 'net_revenue', 'net' ) );
		$refunds  = self::kpi( $kpis, array( 'refunds', 'refund_total' ) );
		$orders   = self::kpi( $kpis, array( 'orders_paid', 'paid_orders', 'paid' ) );
		$created  = self::kpi( $kpis, array( 'orders_created', 'created_orders', 'created' ) );
		$aov      = self::kpi( $kpis, array( 'aov', 'avg_order_value', 'average_order_value' ) );
		$views    = self::kpi( $kpis, array( 'views', 'pageviews', 'page_views', 'requests', 'traffic_views' ) );
		$units    = self::kpi( $kpis, array( 'units', 'items' ) );

		if ( null === $aov && null !== $gross && null !== $orders && $orders > 0 ) {
			$aov = $gross / $orders;
		}

		$figures = array(
			array(
				'label'  => __( 'Cereri de pagină estimate', 'adc-marketing' ),
				'number' => $views,
				'note'   => $traffic
					? __( 'Estimativ: cache/CDN și roboții afectează precizia', 'adc-marketing' )
					: __( 'Indisponibil — colectarea oprită', 'adc-marketing' ),
				'tone'   => $traffic ? '' : 'blocked',
			),
			array(
				'label'  => __( 'Comenzi plătite', 'adc-marketing' ),
				'number' => $orders,
				'note'   => __( 'Din comenzi WooCommerce', 'adc-marketing' ),
			),
			array(
				'label'  => __( 'Comenzi create', 'adc-marketing' ),
				'number' => $created,
				'note'   => __( 'Include stări neplătite', 'adc-marketing' ),
			),
			array(
				'label'  => __( 'Venit brut', 'adc-marketing' ),
				'money'  => $gross,
				'note'   => __( 'Suma valorilor comenzilor plătite', 'adc-marketing' ),
			),
			array(
				'label'  => __( 'Venit net', 'adc-marketing' ),
				'money'  => $net,
				'note'   => __( 'Brut minus rambursări', 'adc-marketing' ),
			),
			array(
				'label'  => __( 'Valoare medie comandă', 'adc-marketing' ),
				'money'  => $aov,
				'note'   => __( 'Brut / comenzi plătite', 'adc-marketing' ),
			),
			array(
				'label'  => __( 'Rambursări', 'adc-marketing' ),
				'money'  => $refunds,
				'note'   => __( 'Nu scad numărul de comenzi plătite', 'adc-marketing' ),
			),
			array(
				'label'  => __( 'Bucăți vândute', 'adc-marketing' ),
				'number' => $units,
				'note'   => __( 'Brute, din comenzile plătite; rambursările nu scad', 'adc-marketing' ),
			),
		);

		foreach ( $figures as &$figure ) {
			if ( isset( $figure['number'] ) ) {
				$figure['display'] = null === $figure['number'] ? '—' : number_format_i18n( (float) $figure['number'], 0 );
				unset( $figure['number'] );
			}
			if ( isset( $figure['money'] ) ) {
				$figure['display'] = null === $figure['money'] ? '—' : self::money( (float) $figure['money'], $currency );
				$figure['money']   = (float) ( $figure['money'] ?? 0 );
				$figure['is_money'] = true;
			}
		}
		unset( $figure );

		return $figures;
	}

	/**
	 * Export links (only for reports the CSV backend actually allows).
	 *
	 * Currency parity: the RESOLVED effective currency is always sent — an empty
	 * UI filter means "shop/effective currency" on the page, and sending it raw
	 * made the CSV resolve a different (empty) context with zero KPIs.
	 *
	 * @param array<string,mixed>  $filters  Active filters, carried into the CSV.
	 * @param array<string,string> $range    Resolved range (exported dates win over presets).
	 * @param string               $currency Resolved effective currency of the view.
	 * @return array<string,string> panel slot => URL
	 */
	private function export_links( array $filters, array $range, string $currency = '' ): array {
		$allowed = $this->csv_reports();
		if ( $allowed === array() || ! class_exists( 'ADC_Analytics_Csv' ) || ! method_exists( 'ADC_Analytics_Csv', 'export_url' ) ) {
			return array();
		}

		// Filter parity with the CSV backend: it reads preset/from/to/currency/
		// page_type/channel/product_id — not the UI's range/product names. The
		// resolved dates win over the preset so the export matches the view.
		$args = array(
			'preset'     => 'custom' === (string) $filters['range'] ? '' : (string) $filters['range'],
			'from'       => (string) $range['from'],
			'to'         => (string) $range['to'],
			'currency'   => '' !== $currency ? $currency : (string) $filters['currency'],
			'page_type'  => (string) $filters['page_type'],
			'channel'    => (string) $filters['channel'],
			'product_id' => (int) $filters['product'],
		);

		$links = array();
		foreach ( $allowed as $panel ) {
			$report = (string) ( self::CSV_REPORTS[ $panel ] ?? '' );
			if ( '' === $report ) {
				continue;
			}
			$url = (string) ADC_Analytics_Csv::export_url( $report, $args );
			if ( '' !== $url ) {
				$links[ $panel ] = $url;
			}
		}

		return $links;
	}

	/**
	 * Report slugs accepted by the CSV export.
	 *
	 * @return string[]
	 */
	private function csv_reports(): array {
		if ( ! class_exists( 'ADC_Analytics_Csv' ) ) {
			return array();
		}

		$allowed = method_exists( 'ADC_Analytics_Csv', 'allowed_reports' )
			? array_values( array_filter( array_map( 'strval', (array) ADC_Analytics_Csv::allowed_reports() ) ) )
			: array_values( self::CSV_REPORTS );

		// Panel slots exported only when the mapped backend report is allowlisted.
		$out = array();
		foreach ( self::CSV_REPORTS as $panel => $report ) {
			if ( in_array( $report, $allowed, true ) ) {
				$out[] = (string) $panel;
			}
		}

		return $out;
	}

	/**
	 * Romanian label for a stored traffic channel.
	 *
	 * @param string $channel Channel slug.
	 */
	private static function channel_label( string $channel ): string {
		return (string) match ( $channel ) {
			'direct'   => __( 'Direct (fără referer)', 'adc-marketing' ),
			'search'   => __( 'Căutare', 'adc-marketing' ),
			'social'   => __( 'Rețele sociale', 'adc-marketing' ),
			'referral' => __( 'Trimis de alt site', 'adc-marketing' ),
			'internal' => __( 'Intern', 'adc-marketing' ),
			default    => __( 'Necunoscut', 'adc-marketing' ),
		};
	}

	/**
	 * Romanian label for a page dimension.
	 *
	 * @param string $page_type Page type slug.
	 */
	private static function page_type_label( string $page_type ): string {
		return (string) match ( $page_type ) {
			'home'        => __( 'Acasă', 'adc-marketing' ),
			'blog'        => __( 'Listă articole', 'adc-marketing' ),
			'post'        => __( 'Articol', 'adc-marketing' ),
			'page'        => __( 'Pagină', 'adc-marketing' ),
			'product'     => __( 'Produs', 'adc-marketing' ),
			'product_cat' => __( 'Categorie produse', 'adc-marketing' ),
			'product_tag' => __( 'Etichetă produse', 'adc-marketing' ),
			'category'    => __( 'Categorie articole', 'adc-marketing' ),
			'tag'         => __( 'Etichetă articole', 'adc-marketing' ),
			'shop'        => __( 'Magazin', 'adc-marketing' ),
			'archive'     => __( 'Arhivă', 'adc-marketing' ),
			default       => __( 'Altele', 'adc-marketing' ),
		};
	}

	/**
	 * Filter navigation rows rendered as plain GET links (usable without JavaScript).
	 *
	 * @param array<string,mixed> $filters    Active filters.
	 * @param string[]            $currencies Currencies found in the data.
	 * @return array<string,mixed>
	 */
	private function nav_rows( array $filters, array $currencies ): array {
		$presets = array();
		foreach ( array( '7' => 7, '30' => 30, '90' => 90 ) as $slug => $days ) {
			$presets[] = array(
				'url'    => $this->filter_url( array( 'range' => $slug, 'from' => '', 'to' => '' ) ),
				'label'  => sprintf(
					/* translators: %d: number of days. */
					__( '%d zile', 'adc-marketing' ),
					$days
				),
				'active' => $filters['range'] === $slug,
			);
		}

		$channels = array(
			array(
				'url'    => $this->filter_url( array( 'channel' => '' ) ),
				'label'  => __( 'Toate canalele', 'adc-marketing' ),
				'active' => '' === $filters['channel'],
			),
		);
		foreach ( self::CHANNELS as $channel ) {
			$channels[] = array(
				'url'    => $this->filter_url( array( 'channel' => $channel ) ),
				'label'  => self::channel_label( $channel ),
				'active' => $filters['channel'] === $channel,
			);
		}

		$currency_links = array();
		if ( count( $currencies ) > 1 ) {
			$currency_links[] = array(
				'url'    => $this->filter_url( array( 'currency' => '' ) ),
				'label'  => __( 'Moneda implicită', 'adc-marketing' ),
				'active' => '' === $filters['currency'],
			);
			foreach ( $currencies as $code ) {
				$currency_links[] = array(
					'url'    => $this->filter_url( array( 'currency' => $code ) ),
					'label'  => $code,
					'active' => $filters['currency'] === $code,
				);
			}
		}

		$page_types = array(
			array(
				'value'  => '',
				'label'  => __( 'Toate tipurile de pagină', 'adc-marketing' ),
				'active' => '' === $filters['page_type'],
			),
		);
		foreach ( self::PAGE_TYPES as $type ) {
			$page_types[] = array(
				'value'  => $type,
				'label'  => self::page_type_label( $type ),
				'active' => $filters['page_type'] === $type,
			);
		}

		$tops = array();
		foreach ( array( 5, 10, 20, 50 ) as $top ) {
			$tops[] = array(
				'value'  => (string) $top,
				'label'  => sprintf(
					/* translators: %d: number of list rows. */
					__( 'Top %d', 'adc-marketing' ),
					$top
				),
				'active' => (int) $filters['top'] === $top,
			);
		}

		return array(
			'presets'    => $presets,
			'channels'   => $channels,
			'currencies' => $currency_links,
			'page_types' => $page_types,
			'tops'       => $tops,
			'reset'      => $this->reset_url(),
			'apply'      => $this->filter_url( array() ),
			'custom'     => array(
				'label'  => __( 'Interval personalizat', 'adc-marketing' ),
				'active' => 'custom' === $filters['range'],
			),
		);
	}

	/**
	 * Status options offered in the settings form.
	 *
	 * @return array<string,string> slug => label
	 */
	private static function status_options(): array {
		$labels = array(
			'processing' => __( 'În prelucrare', 'adc-marketing' ),
			'completed'  => __( 'Finalizate', 'adc-marketing' ),
		);

		if ( function_exists( 'wc_get_order_status_name' ) ) {
			foreach ( array_keys( $labels ) as $slug ) {
				$labels[ $slug ] = (string) wc_get_order_status_name( $slug );
			}
		}

		return $labels;
	}

	/**
	 * Warning when commerce paid statuses differ from the tracking purchase statuses.
	 */
	private function purchase_statuses_diverge(): bool {
		$analytics = ADC_Analytics_Settings::paid_statuses();

		$events = ADC_Settings::get( 'events', 'purchase_statuses', null );
		if ( ! is_array( $events ) ) {
			return false;
		}

		sort( $analytics );
		$events = array_values( array_map( 'strval', $events ) );
		sort( $events );

		return $analytics !== $events;
	}

	/**
	 * Admin-facing privacy notice draft (§10.5). Not published, not a compliance claim.
	 *
	 * @return string
	 */
	private function privacy_notice_draft(): string {
		return __( 'Draft de notificare (nezvălit, nepublicat): operator [nume firmă, date de contact]; scop: contoare operaționale agregate pentru paginații și comenzi; categorii de date: număr de cereri pe zi/canal/tip de pagină, agregate de comandă pe monedă; temeiuri propuse: interes legitim evaluat (trafic) și executarea contractului (raportare financiară din comenzile proprii); referință LIA: [id document / data]; retenție: trafic [zile], financiar [zile], registru de deduplicare [zile]; destinatari: niciunul extern — datele rămân în baza de date WordPress; drepturi: acces, rectificare, ștergere, obiecție, plângere la ANSPDCP; contact/DPO: [date]. Acest modul nu instaurează cookie-uri, nu folosește JavaScript de urmărire și nu amprentează dispozitivele. Textul nu declară conformitatea; necesită validare juridică înainte de publicare.', 'adc-marketing');
	}
}
