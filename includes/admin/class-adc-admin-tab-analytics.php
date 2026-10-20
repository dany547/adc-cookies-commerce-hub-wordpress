<?php
/**
 * "Analytics (setări)" tab of the ADC Marketing admin shell.
 *
 * Operational settings only — collection gates, paid statuses, retention,
 * privacy threshold and the inert legal draft. The statistics (KPI figures,
 * charts, ranked tables, report filters and CSV export links) live in the
 * dedicated Dashboard tab (ADC_Admin_Tab_Dashboard); both tabs share one
 * presentation layer, the `ADC_Admin_Analytics_Stats` trait, so no adapter is
 * duplicated.
 *
 * The trait lives in class-adc-admin-tab-dashboard.php because the plugin
 * autoloader only resolves `ADC_*` classes and interfaces, never traits, so the
 * file is required explicitly here.
 *
 * Gate policy: commerce reporting and the anonymous traffic counter are real
 * switches, each needing the feature toggle plus its confirmation box; UTM,
 * add-to-cart and begin-checkout stay closed by the backend
 * (`ADC_Analytics_Settings`), so the settings form shows them as one compact
 * "indisponibile" card with the reason and no inputs.
 *
 * The shell wraps render() in its own `<form>` + nonce, therefore this tab never
 * prints a form tag and never names a control that could pollute the report
 * filters (which are GET links on the Dashboard).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-adc-admin-tab-dashboard.php';

/**
 * Operational analytics settings tab.
 */
final class ADC_Admin_Tab_Analytics implements ADC_Admin_Tab_Interface {

	use ADC_Admin_Analytics_Stats;

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
		return __( 'Analytics (setări)', 'adc-marketing' );
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
	 * Render the settings form half of the analytics template (inner HTML only,
	 * no form tag, no nonce — the shell provides both).
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

		$parts = $this->render_sections( $this->build_settings_view() );

		printf(
			'<p class="adc-an-jump"><a class="button button-secondary" href="%1$s">%2$s</a></p>',
			esc_url( $this->reports_url() ),
			esc_html__( 'Vezi rapoartele în Dashboard', 'adc-marketing' )
		);

		echo $parts['settings']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the analytics template escapes its own output.
	}

	/* --------------------------------------------------------------------- *
	 * View building (settings half)
	 * --------------------------------------------------------------------- */

	/**
	 * Assemble what the settings form renders: stored values, the legal gate
	 * states shown next to the disabled controls, the paid-status choices and the
	 * privacy notice draft.
	 *
	 * @return array<string,mixed>
	 */
	private function build_settings_view(): array {
		return array(
			'settings'      => ADC_Settings::all( ADC_Analytics_Settings::GROUP ),
			'gates'         => $this->gate_rows(
				ADC_Analytics_Settings::commerce_enabled(),
				ADC_Analytics_Settings::traffic_enabled()
			),
			'paid_statuses' => array(
				'selected' => ADC_Analytics_Settings::paid_statuses(),
				'options'  => self::status_options(),
				'diverges' => $this->purchase_statuses_diverge(),
			),
			'notice_draft'  => $this->privacy_notice_draft(),
			// Long help texts for the info-icon modals of this form (PHP-side so they
			// stay translatable); the template renders them through ADC_Admin_Help.
			'help'          => $this->settings_help_bodies(),
			// The template only checks whether this list is empty: an empty list
			// means the CSV export engine is missing from this installation.
			'export_links'  => $this->csv_reports(),
		);
	}

	/**
	 * Modal copy for every section and option of the analytics settings form.
	 *
	 * @return array<string,string>
	 */
	private function settings_help_bodies(): array {
		return array(
			'section_commerce'      => $this->help_analytics_commerce(),
			'opt_commerce_enabled'  => $this->help_analytics_commerce(),
			'opt_commerce_ack'      => $this->help_analytics_commerce(),
			'opt_paid_statuses'     => $this->help_analytics_paid_statuses(),
			'opt_retention_finance' => $this->help_analytics_retention_finance(),
			'section_traffic'       => $this->help_analytics_traffic(),
			'opt_traffic_enabled'   => $this->help_analytics_traffic(),
			'opt_traffic_ack'       => $this->help_analytics_traffic(),
			'opt_retention_traffic' => $this->help_analytics_retention_traffic(),
			'opt_exclusions'        => $this->help_analytics_exclusions(),
			'opt_min_cell'          => $this->help_analytics_min_cell(),
			'internal_notes'        => $this->help_analytics_internal_notes(),
			'section_unavailable'   => $this->help_analytics_unavailable(),
			'blocked_utm'           => $this->help_analytics_utm(),
			'blocked_add_to_cart'   => $this->help_analytics_add_to_cart(),
			'blocked_begin_checkout' => $this->help_analytics_begin_checkout(),
			'section_retention'     => $this->help_analytics_retention_section(),
		);
	}

	/**
	 * Persist the analytics settings group. The shell already checked the
	 * capability and the `adc_save_tab_analytics` nonce.
	 *
	 * Only keys of the `analytics` group are read. Commerce and traffic keep their
	 * real switch + confirmation pairs; the UTM, add-to-cart and begin-checkout
	 * gates stay forced closed by ADC_Analytics_Settings::sanitize(), and their
	 * draft references are kept from storage because the form no longer exposes
	 * inputs for them.
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
			// Anonymous traffic counter: switch + privacy confirmation, both required.
			'enabled_traffic'     => ! empty( $raw['enabled_traffic'] ),
			'traffic_ack'         => ! empty( $raw['traffic_ack'] ),
			'retention_traffic_days' => isset( $raw['retention_traffic_days'] ) ? (int) $raw['retention_traffic_days'] : (int) ( $stored['retention_traffic_days'] ?? 90 ),
			'exclude_logged_in_admins' => ! empty( $raw['exclude_logged_in_admins'] ),
			'bot_filter_enabled'      => ! empty( $raw['bot_filter_enabled'] ),
			'min_cell_count'          => isset( $raw['min_cell_count'] ) ? (int) $raw['min_cell_count'] : 0,
			// Draft legal metadata: stored for the future review, inert by design.
			'traffic_legal_basis'     => isset( $raw['traffic_legal_basis'] ) ? (string) $raw['traffic_legal_basis'] : '',
			'traffic_legal_reference' => isset( $raw['traffic_legal_reference'] ) ? (string) $raw['traffic_legal_reference'] : '',
			'traffic_approved_at'     => isset( $raw['traffic_approved_at'] ) ? (string) $raw['traffic_approved_at'] : '',
			// No inputs anymore (the gates stay closed): keep whatever was stored.
			'traffic_utm_legal_reference' => isset( $stored['traffic_utm_legal_reference'] ) ? (string) $stored['traffic_utm_legal_reference'] : '',
			'traffic_utm_approved_at'     => isset( $stored['traffic_utm_approved_at'] ) ? (string) $stored['traffic_utm_approved_at'] : '',
			'add_to_cart_legal_reference' => isset( $stored['add_to_cart_legal_reference'] ) ? (string) $stored['add_to_cart_legal_reference'] : '',
			'add_to_cart_approved_at'     => isset( $stored['add_to_cart_approved_at'] ) ? (string) $stored['add_to_cart_approved_at'] : '',
		);

		$saved = ADC_Settings::update( ADC_Analytics_Settings::GROUP, $group );

		$notices = array(
			array(
				'type'    => 'success',
				'message' => __( 'Setările de analiză operațională au fost salvate.', 'adc-marketing' ),
			),
		);

		foreach ( array( 'commerce', 'traffic' ) as $feature ) {
			$status = ADC_Analytics_Settings::legal_gate_status( $feature );
			if ( ! $status['enabled'] ) {
				$notices[] = array(
					'type'    => 'warning',
					'message' => $status['reason'],
				);
			}
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
		return __( 'Draft de notificare (nezvălit, nepublicat): operator [nume firmă, date de contact]; scop: contoare operaționale agregate pentru paginații și comenzi; categorii de date: număr de cereri de pagină pe zi, canal și tip de pagină (agregat, fără identificatori), agregate de comandă pe monedă; temeiuri propuse: interes legitim evaluat (trafic) și executarea contractului (raportare financiară din comenzile proprii); referință LIA: [id document / data]; retenție: trafic [zile], financiar [zile], registru de deduplicare [zile]; destinatari: niciunul extern — datele rămân în baza de date WordPress; drepturi: acces, rectificare, ștergere, obiecție, plângere la ANSPDCP; contact/DPO: [date]. Contorul de trafic este anonim-agregat: nu instaurează cookie-uri, nu citește sesiuni, ID-uri de utilizator, adrese IP, user agent sau referer, nu folosește JavaScript de urmărire și nu amprentează dispozitivele; cererile care trimit Sec-GPC: 1 sau DNT: 1 nu sunt numărate, iar vizitele administratorilor autentificați pot fi excluse. Textul nu declară conformitatea; necesită validare juridică înainte de publicare.', 'adc-marketing');
	}
}
