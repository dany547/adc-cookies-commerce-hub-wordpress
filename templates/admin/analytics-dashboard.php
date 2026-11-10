<?php
/**
 * Analytics operațional — dashboard + settings (Romanian, admin-only).
 *
 * Rendered by ADC_Admin_Tab_Dashboard and ADC_Admin_Tab_Analytics through
 * ADC_Template, inside the admin shell's own <form method="post">. Therefore this
 * file prints NO <form> tag and NO nonce: report filters are plain GET links
 * (plus JS-built URLs) and carry no `name` attribute, so they cannot pollute the
 * settings POST.
 *
 * Composition follows DESIGN.md §6 — three tiers of boxes, not 28 hairline
 * panels in one card:
 *  - tier 1: one row of 4 KPI tiles + a supporting-figures box ("Cifre cheie");
 *  - tier 2: symmetric instrument rows, one box-dark (chart) beside one
 *    box-light (the same story as a table);
 *  - tier 3: ranked tables, verification, status and notes, with long lists
 *    behind a <details> disclosure;
 *  - the report filters live in ONE compact toolbar box at the top.
 * The `<hr class="adc-an-rule" />` break below separates the settings half (one
 * delimited box per settings section), which render_sections() sends to the
 * Analytics tab; no settings control ever sits between report boxes.
 *
 * Every value comes from the view array; nothing here invents data. When the
 * reporting façade is absent, the panels show an explicit unavailable state.
 *
 * @package ADC_Marketing
 *
 * @var array<string,mixed> $view
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$v = is_array( $view ?? null ) ? $view : array();

$e = static fn ( mixed $value ): string => esc_html( (string) $value );
$k = static fn ( mixed $value ): string => wp_kses_post( (string) $value );
$u = static fn ( mixed $value ): string => esc_url( (string) $value );

$get  = static fn ( array $source, string $key, mixed $default = null ): mixed => isset( $source[ $key ] ) ? $source[ $key ] : $default;

$filters       = (array) $get( $v, 'filters', array() );
$range         = (array) $get( $v, 'range', array( 'from' => '', 'to' => '', 'prev_from' => '', 'prev_to' => '' ) );
$nav           = (array) $get( $v, 'nav', array() );
$gates         = (array) $get( $v, 'gates', array() );
$figures       = (array) $get( $v, 'figures', array() );
$series        = (array) $get( $v, 'series', array() );
$traffic_chart = (array) $get( $v, 'traffic_chart', array() );
$channels      = (array) $get( $v, 'channels', array( 'rows' => array() ) );
$pages         = (array) $get( $v, 'pages', array( 'rows' => array() ) );
$products      = (array) $get( $v, 'products', array( 'rows' => array() ) );
$statuses      = (array) $get( $v, 'statuses', array() );
$recon         = (array) $get( $v, 'reconciliation', array() );
$warnings      = (array) $get( $v, 'warnings', array() );
$channel_statuses = (array) $get( $v, 'channel_statuses', array() );
$unavailable   = (array) $get( $v, 'unavailable', array() );
$compare       = (array) $get( $v, 'compare', array( 'available' => false, 'rows' => array() ) );
$exports       = (array) $get( $v, 'export_links', array() );
$settings      = (array) $get( $v, 'settings', array() );
$product_stats = (array) $get( $v, 'product_stats', array() );
$paid          = (array) $get( $v, 'paid_statuses', array( 'selected' => array(), 'options' => array(), 'diverges' => false ) );
$threshold     = (int) $get( $v, 'threshold', 5 );
$currency      = (string) $get( $v, 'currency', '' );
$commerce_on   = (bool) $get( $v, 'commerce', false );
$traffic_on    = (bool) $get( $v, 'traffic', false );
$has_reports   = (bool) $get( $v, 'reports_ready', false );

/**
 * Row-bound reconciliation warning (design review F2).
 *
 * A discrepancy is shown next to the metric it describes, inside the
 * reconciliation table, instead of only in the collapsed general-warnings
 * disclosure. The disclosure keeps the genuinely general caveats:
 * reconciliation-derived rows are matched out of it by their 'recon'
 * marker (ADC_Admin_Tab_Dashboard::warning_rows()), not by text.
 */
$recon_warning_text = static function ( mixed $label ): string {
	return sprintf(
		/* translators: %s: metric label. */
		__( 'Diferență de recalculare pentru „%s”: agregatul nu corespunde cu totalul direct din comenzi. Verificați manual.', 'adc-marketing' ),
		(string) $label
	);
};

$recon_warning_texts = array();
foreach ( (array) $get( $recon, 'rows', array() ) as $recon_row ) {
	if ( ! empty( $recon_row['notify'] ) ) {
		$recon_warning_texts[] = $recon_warning_text( $recon_row['label'] ?? '' );
	}
}

$general_warnings = array_values(
	array_filter(
		(array) $warnings,
		static function ( $warning ): bool {
			if ( ! is_array( $warning ) ) {
				return true;
			}

			// Reconciliation-derived warnings are rendered next to their
			// metric row (design review F2); drop them from this disclosure
			// by the 'recon' marker (added by ADC_Admin_Tab_Dashboard::
			// warning_rows) instead of matching the rendered text.
			return empty( $warning['recon'] );
		}
	)
);

$gate_state = static function ( string $feature ) use ( $gates ): array {
	foreach ( $gates as $gate ) {
		if ( (string) ( $gate['feature'] ?? '' ) === $feature ) {
			return (array) $gate;
		}
	}

	return array(
		'feature' => $feature,
		'label'   => $feature,
		'state'   => 'off',
		'text'    => __( 'Oprit', 'adc-marketing' ),
		'reason'  => '',
	);
};

/* --------------------------------------------------------------------- *
 * Small presentation helpers (markup only — no data is produced here)
 * --------------------------------------------------------------------- */

/**
 * Provenance pill: which register a figure comes from (DESIGN.md §5 badge).
 *
 * @param string $unit 'est' (estimated traffic) or 'fin' (WooCommerce orders).
 * @return string Escaped markup.
 */
$prov = static function ( string $unit ): string {
	$label = 'fin' === $unit
		? __( 'Din comenzi WooCommerce', 'adc-marketing' )
		: __( 'Estimativ', 'adc-marketing' );

	return '<span class="adc-an-prov adc-an-prov--' . esc_attr( $unit ) . '">' . esc_html( $label ) . '</span>';
};

/**
 * Delta chip for one KPI label: arrow + unsigned number + words.
 *
 * Colour is reinforcement only: the direction is carried by the arrow glyph and
 * by a screen-reader word, never by the colour alone (DESIGN.md §5).
 *
 * @param array<string,array<string,mixed>> $deltas Comparison rows keyed by label.
 * @param string                            $label  KPI label to look up.
 */
$render_delta = static function ( array $deltas, string $label ) use ( $e ): void {
	if ( ! isset( $deltas[ $label ] ) ) {
		return;
	}

	$delta = (array) $deltas[ $label ];
	$tone  = (string) ( $delta['tone'] ?? '' );
	$up    = 'up' === $tone;

	// The view model returns a signed text ("+8,2 %"); the arrow carries the sign.
	$value = preg_replace( '/^[+−\s]+/u', '', (string) ( $delta['text'] ?? '' ) );

	if ( '' === (string) $value ) {
		return;
	}
	?>
	<span class="adc-an-chip adc-an-chip--<?php echo esc_attr( $tone ); ?>">
		<span aria-hidden="true"><?php echo $up ? '↑' : '↓'; ?></span>
		<?php echo $e( $value ); ?>
		<span class="screen-reader-text">
			<?php echo $up ? esc_html__( 'creștere', 'adc-marketing' ) : esc_html__( 'scădere', 'adc-marketing' ); ?>
			<?php esc_html_e( 'față de intervalul anterior', 'adc-marketing' ); ?>
		</span>
	</span>
	<?php
};

/**
 * Legend of the CSS marker dots that name a chart series (DESIGN.md §4.6).
 *
 * @param array<int,string> $items Series labels in fixed series order.
 */
$render_legend = static function ( array $items ) use ( $e ): void {
	if ( $items === array() ) {
		return;
	}
	?>
	<ul class="adc-an-legend">
		<?php foreach ( $items as $item ) : ?>
			<li class="adc-an-legend__item"><?php echo $e( $item ); ?></li>
		<?php endforeach; ?>
	</ul>
	<?php
};

/**
 * Inline chart: gridlines, area, bars, line and points, plus the axis labels in
 * real HTML text (so they stay readable at 390 px). The equivalent table lives in
 * the symmetric light box next to it, never inside the dark box.
 *
 * @param array<string,mixed> $chart   Chart payload.
 * @param string              $id      DOM id prefix.
 * @param string              $caption Figure caption (also the SVG label).
 */
$render_chart_plot = static function ( array $chart, string $id, string $caption ) use ( $e, $k, $get ): void {
	if ( $chart === array() || empty( $chart['has_data'] ) ) {
		return;
	}

	$grid    = array_reverse( (array) $get( $chart, 'grid', array() ) );
	$bars    = (array) $get( $chart, 'bars', array() );
	$marks   = (array) $get( $chart, 'marks', array() );
	$xlabels = (array) $get( $chart, 'xlabels', array() );
	$cap_id  = $id . '-cap';
	?>
	<figure class="adc-an-fig" id="<?php echo esc_attr( $id ); ?>">
		<figcaption class="adc-an-fig-title" id="<?php echo esc_attr( $cap_id ); ?>">
			<?php echo $e( $caption ); ?>
			<?php if ( '' !== (string) $get( $chart, 'summary', '' ) ) : ?>
				<span class="adc-an-fig-summary"><?php echo $k( $chart['summary'] ); ?></span>
			<?php endif; ?>
		</figcaption>

		<div class="adc-chart adc-an-plot">
			<div class="adc-an-yaxis" id="<?php echo esc_attr( $id ); ?>-plot-y" aria-hidden="true">
				<?php foreach ( $grid as $line ) : ?>
					<span class="adc-an-ylabel" style="top:<?php echo esc_attr( (string) round( ( (float) ( $line['y'] ?? 0 ) / 220 ) * 100, 2 ) ); ?>%;"><?php echo $e( $line['label'] ?? '' ); ?></span>
				<?php endforeach; ?>
			</div>

			<svg class="adc-an-svg" viewBox="<?php echo esc_attr( (string) $get( $chart, 'view_box', '0 0 760 220' ) ); ?>"
				preserveAspectRatio="none"
				role="img" aria-labelledby="<?php echo esc_attr( $cap_id ); ?>" focusable="false">
				<?php foreach ( $grid as $line ) : ?>
					<line class="adc-an-gridline" x1="0" x2="100%" y1="<?php echo esc_attr( (string) $line['y'] ); ?>" y2="<?php echo esc_attr( (string) $line['y'] ); ?>" />
				<?php endforeach; ?>

				<?php if ( '' !== (string) $get( $chart, 'area', '' ) ) : ?>
					<path class="adc-an-area" d="<?php echo esc_attr( (string) $chart['area'] ); ?>" />
				<?php endif; ?>

				<?php foreach ( $bars as $bar ) : ?>
					<rect class="adc-an-bar<?php echo ! empty( $bar['small'] ) ? ' is-small' : ''; ?>"
						x="<?php echo esc_attr( (string) $bar['x'] ); ?>" y="<?php echo esc_attr( (string) $bar['y'] ); ?>"
						width="<?php echo esc_attr( (string) $bar['w'] ); ?>" height="<?php echo esc_attr( (string) $bar['h'] ); ?>" rx="1.5" />
				<?php endforeach; ?>

				<?php if ( '' !== (string) $get( $chart, 'path', '' ) ) : ?>
					<path class="adc-an-line" d="<?php echo esc_attr( (string) $chart['path'] ); ?>" />
				<?php endif; ?>

				<?php foreach ( $marks as $mark ) : ?>
					<?php if ( isset( $mark['value'] ) && is_string( $mark['value'] ) ) : ?>
						<circle class="adc-an-dot" cx="<?php echo esc_attr( (string) $mark['cx'] ); ?>" cy="<?php echo esc_attr( (string) $mark['cy'] ); ?>" r="2.4" />
					<?php else : ?>
						<circle class="adc-an-dot<?php echo ! empty( $mark['suppressed'] ) ? ' is-suppressed' : ''; ?>"
							cx="<?php echo esc_attr( (string) $mark['cx'] ); ?>" cy="<?php echo esc_attr( (string) $mark['cy'] ); ?>" r="2.6" />
					<?php endif; ?>
				<?php endforeach; ?>
			</svg>
		</div>

		<div class="adc-an-xaxis" aria-hidden="true">
			<?php foreach ( $xlabels as $label ) : ?>
				<span class="adc-an-xlabel<?php echo 'start' === (string) ( $label['anchor'] ?? '' ) ? ' is-start' : ( 'end' === (string) ( $label['anchor'] ?? '' ) ? ' is-end' : '' ); ?>"
					style="left:<?php echo esc_attr( (string) ( $label['left'] ?? 0 ) ); ?>%;"><?php echo $e( $label['label'] ?? '' ); ?></span>
			<?php endforeach; ?>
		</div>
	</figure>
	<?php
};

/**
 * The chart's equivalent table (DESIGN.md §9: every chart ships with its data).
 *
 * It is the light half of the instrument pair, so it stays open inside a bounded
 * scroll region and can still be collapsed by the reader.
 *
 * @param array<string,mixed> $chart Chart payload.
 * @param string              $id    DOM id prefix.
 */
$render_chart_table = static function ( array $chart, string $id ) use ( $e, $k, $get ): void {
	if ( $chart === array() || empty( $chart['has_data'] ) ) {
		return;
	}

	$rows  = (array) $get( $chart, 'rows', array() );
	$combo = 'combo' === (string) $get( $chart, 'kind', '' );
	?>
	<details class="adc-an-data" open>
		<summary>
			<?php esc_html_e( 'Datele graficului (tabel)', 'adc-marketing' ); ?>
			<span class="adc-an-data-count"><?php echo $e( sprintf( /* translators: %d: number of rows. */ _n( '%d zi', '%d zile', count( $rows ), 'adc-marketing' ), count( $rows ) ) ); ?></span>
		</summary>
		<div class="adc-an-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Tabel de date pe zile, derulabil', 'adc-marketing' ); ?>">
			<table class="widefat striped adc-an-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Data', 'adc-marketing' ); ?></th>
						<?php if ( $combo ) : ?>
							<th scope="col" class="adc-an-num"><?php esc_html_e( 'Comenzi plătite', 'adc-marketing' ); ?></th>
							<th scope="col" class="adc-an-num"><?php esc_html_e( 'Venit brut', 'adc-marketing' ); ?></th>
						<?php else : ?>
							<th scope="col" class="adc-an-num"><?php esc_html_e( 'Cereri estimate', 'adc-marketing' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<th scope="row"><?php echo $e( $row['label'] ?? $row['date'] ?? '' ); ?></th>
							<?php if ( $combo ) : ?>
								<td class="adc-an-num">
									<?php echo ! empty( $row['small'] ) ? '<span class="adc-an-thresh">' . esc_html__( 'sub pragă', 'adc-marketing' ) . '</span>' : $e( $row['orders'] ?? '' ); ?>
								</td>
								<td class="adc-an-num"><?php echo $k( $row['money'] ?? '' ); ?></td>
							<?php else : ?>
								<td class="adc-an-num">
									<?php echo ! empty( $row['suppressed'] ) ? '<span class="adc-an-thresh">' . esc_html__( 'sub pragă', 'adc-marketing' ) . '</span>' : $e( $row['value'] ?? '' ); ?>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</details>
	<?php
};

/**
 * Blocked / empty state with a reason and an in-page pointer.
 *
 * @param string $title Heading.
 * @param string $body  Explanation.
 * @param string $href  Optional anchor to the setting that controls it.
 * @param string $link  Optional link label.
 */
$render_empty = static function ( string $title, string $body, string $href = '', string $link = '' ) use ( $e, $u ): void {
	?>
	<div class="adc-an-empty">
		<p class="adc-an-empty-title"><?php echo $e( $title ); ?></p>
		<p class="adc-an-empty-body"><?php echo $e( $body ); ?></p>
		<?php if ( '' !== $href && '' !== $link ) : ?>
			<p class="adc-an-empty-action"><a class="button" href="<?php echo $u( $href ); ?>"><?php echo $e( $link ); ?></a></p>
		<?php endif; ?>
	</div>
	<?php
};

/**
 * Export link for one report slug (rendered only when the CSV backend allows it).
 *
 * @param string $report Report slot (key of view['export_links']).
 * @param string $label  Distinct link text (several exports can share a box).
 */
$render_export = static function ( string $report, string $label ) use ( $exports, $u, $e ): void {
	if ( ! isset( $exports[ $report ] ) ) {
		return;
	}
	?>
	<a class="adc-an-export" href="<?php echo $u( $exports[ $report ] ); ?>">
		<?php echo $e( $label ); ?>
		<span class="screen-reader-text"><?php esc_html_e( 'cu filtrele și intervalul active', 'adc-marketing' ); ?></span>
	</a>
	<?php
};

/**
 * Ranked table: label + measure + CSS bar, with its equivalent numbers.
 *
 * @param array<int,array<string,mixed>> $rows     Ranking rows.
 * @param string                         $col_label Measure column heading.
 * @param bool                           $money     Show the money column.
 * @return string Escaped table markup.
 */
$ranking_table = static function ( array $rows, string $col_label, bool $money ) use ( $e, $k ): string {
	ob_start();
	?>
	<table class="widefat striped adc-an-table adc-an-ranked">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Element', 'adc-marketing' ); ?></th>
				<th scope="col" class="adc-an-num"><?php echo $e( $col_label ); ?></th>
				<th scope="col" class="adc-an-barcol"><span class="screen-reader-text"><?php esc_html_e( 'Reprezentare grafică relativă', 'adc-marketing' ); ?></span></th>
				<?php if ( $money ) : ?>
					<th scope="col" class="adc-an-num"><?php esc_html_e( 'Valoare (fără taxă)', 'adc-marketing' ); ?></th>
				<?php endif; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<th scope="row"><?php echo $e( $row['label'] ?? '' ); ?></th>
					<td class="adc-an-num"><?php echo $e( number_format_i18n( (float) ( $row['value'] ?? 0 ), 0 ) ); ?></td>
					<td class="adc-an-barcell">
						<span class="adc-an-trk"><span class="adc-an-bar" style="width:<?php echo esc_attr( (string) (int) ( $row['pct'] ?? 0 ) ); ?>%;" aria-hidden="true"></span></span>
					</td>
					<?php if ( $money ) : ?>
						<td class="adc-an-num"><?php echo $k( $row['money'] ?? '' ); ?></td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	return (string) ob_get_clean();
};

/**
 * One ranked list as a box-light panel (tier 3).
 *
 * Lists longer than 12 rows start collapsed behind a disclosure so the page keeps
 * its hierarchy; nothing is dropped.
 *
 * @param array<string,mixed> $list         Ranked payload.
 * @param string              $id           DOM id.
 * @param string              $title        Box title.
 * @param string              $col_label    Measure column heading.
 * @param bool                $money        Show the money column.
 * @param string              $unit         Provenance tag tone ('est' or 'fin').
 * @param string              $export_label Distinct CSV link text.
 */
$render_ranking = static function ( array $list, string $id, string $title, string $col_label, bool $money, string $unit, string $export_label ) use ( $e, $get, $prov, $render_export, $ranking_table ): void {
	$rows = (array) $get( $list, 'rows', array() );
	?>
	<section class="adc-box adc-box--light adc-an-tbl" id="<?php echo esc_attr( $id ); ?>" aria-labelledby="<?php echo esc_attr( $id ); ?>-h">
		<div class="adc-box__head">
			<h3 class="adc-box__title" id="<?php echo esc_attr( $id ); ?>-h"><?php echo $e( $title ); ?></h3>
			<?php echo $prov( $unit ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $prov() escapes its own output. ?>
			<?php $render_export( $id, $export_label ); ?>
		</div>
		<div class="adc-box__body">
			<?php if ( $rows === array() ) : ?>
				<p class="adc-an-none"><?php esc_html_e( 'Fără celule deasupra pragului de confidențialitate pentru acest interval.', 'adc-marketing' ); ?></p>
			<?php elseif ( count( $rows ) > 12 ) : ?>
				<details class="adc-an-data">
					<summary>
						<?php esc_html_e( 'Clasamentul complet', 'adc-marketing' ); ?>
						<span class="adc-an-data-count"><?php echo $e( sprintf( /* translators: %d: number of rows. */ _n( '%d rând', '%d rânduri', count( $rows ), 'adc-marketing' ), count( $rows ) ) ); ?></span>
					</summary>
					<div class="adc-an-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Clasament, tabel derulabil', 'adc-marketing' ); ?>">
					<?php echo $ranking_table( $rows, $col_label, $money ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped values. ?>
					</div>
				</details>
			<?php else : ?>
				<?php echo $ranking_table( $rows, $col_label, $money ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped values. ?>
			<?php endif; ?>

			<?php if ( (int) $get( $list, 'hidden', 0 ) > 0 ) : ?>
				<p class="adc-an-foot">
					<?php
					printf(
						/* translators: %d: number of hidden cells. */
						esc_html__( '%d rânduri sub prag au fost ascunse.', 'adc-marketing' ),
						(int) $get( $list, 'hidden', 0 )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	</section>
	<?php
};

	$help = (array) $get( $v, 'help', array() );

	/**
	 * Info-icon + modal for one section or option.
	 *
	 * The long text is built in PHP (ADC_Admin_Tab_Analytics::settings_help_bodies()
	 * / ADC_Admin_Analytics_Stats::stats_help_bodies()) and travels through
	 * ADC_Admin_Help, which escapes it. Returns empty markup when either the help
	 * component or the copy is missing, so the page degrades to a plain form.
	 *
	 * @param string $slot  Key of the help body inside the view.
	 * @param string $id    Unique content id on the page.
	 * @param string $title Modal title (plain text).
	 * @return string
	 */
	$icon = static function ( string $slot, string $id, string $title ) use ( $help ): string {
		$body = trim( (string) ( $help[ $slot ] ?? '' ) );

		if ( '' === $body || ! class_exists( 'ADC_Admin_Help' ) ) {
			return '';
		}

		return ADC_Admin_Help::icon( $id, $title, $body );
	};

	/**
	 * One option rendered the same way as in the other admin tabs: label on top,
	 * control below, one-sentence description under it, optional info-icon.
	 *
	 * @param string $label      Option label (plain text).
	 * @param string $control    Pre-escaped control markup (keeps its name=/id=).
	 * @param string $desc       One sentence: what happens when this is on.
	 * @param string $for        Input id the label points to ('' for checkbox groups).
	 * @param string $help_id    Content id for the modal ('' = no modal).
	 * @param string $help_slot  Key of the modal body in $help.
	 */
	$option = static function ( string $label, string $control, string $desc = '', string $for = '', string $help_id = '', string $help_slot = '' ) use ( $help, $icon ): void {
		$has_component = class_exists( 'ADC_Admin_Help' );

		if ( ! $has_component ) {
			// Same structure, no modal: the form stays readable without the component.
			echo '<div class="adc-help-row">';
			echo '<span class="adc-help-row__label">';
			if ( '' !== $for ) {
				echo '<label for="' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label>';
			} else {
				echo '<span class="adc-help-row__group-label">' . esc_html( $label ) . '</span>';
			}
			echo '</span>';
			echo '<div class="adc-help-row__control">' . $control . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- control markup is escaped by the caller.
			if ( '' !== $desc ) {
				echo '<p class="adc-help-row__desc">' . esc_html( $desc ) . '</p>';
			}
			echo '</div>';

			return;
		}

		$body = trim( (string) ( $help[ $help_slot ] ?? '' ) );
		$show_help = ( '' !== $help_id && '' !== $body ) ? $help_id : null;

		ADC_Admin_Help::row(
			$label,
			$control,
			$desc,
			( '' === $for ) ? null : $for,
			$show_help,
			null === $show_help ? null : $body
		);
	};

	/* --------------------------------------------------------------------- *
	 * View-model slices used by the composition (no data is invented)
	 * --------------------------------------------------------------------- */

	// Comparison rows keyed by KPI label, so each tile can carry its own delta chip.
	$deltas = array();
	foreach ( (array) $get( $compare, 'rows', array() ) as $delta_row ) {
		$deltas[ (string) ( $delta_row['label'] ?? '' ) ] = (array) $delta_row;
	}

	// Tier 1: the first four figures are the above-the-fold tiles; the rest stay
	// reachable in the supporting-figures box of the same section.
	$kpi_primary = array_slice( $figures, 0, 4 );
	$kpi_support = array_slice( $figures, 4 );

	$traffic_ok = $traffic_chart !== array() && ! empty( $traffic_chart['has_data'] );
	$orders_ok  = $series !== array() && ! empty( $series['has_data'] );

	// Active filters that are not visible in a segmented control (custom range and
	// the advanced fields) are summarised as chips in the toolbar.
	$active_chips = array();
	if ( 'custom' === (string) ( $filters['range'] ?? '' ) ) {
		$active_chips[] = array(
			'label' => __( 'Interval personalizat', 'adc-marketing' ),
			'value' => trim( (string) ( $range['from'] ?? '' ) . ' → ' . (string) ( $range['to'] ?? '' ), ' →' ),
		);
	}
	if ( '' !== (string) ( $filters['page_type'] ?? '' ) ) {
		$active_page_type = __( 'Tip pagină', 'adc-marketing' );
		foreach ( (array) $get( $nav, 'page_types', array() ) as $type_row ) {
			if ( ! empty( $type_row['active'] ) ) {
				$active_page_type = (string) $type_row['label'];
				break;
			}
		}
		$active_chips[] = array( 'label' => __( 'Tip pagină', 'adc-marketing' ), 'value' => $active_page_type );
	}
	if ( 10 !== (int) ( $filters['top'] ?? 10 ) ) {
		$active_chips[] = array(
			'label' => __( 'Lista', 'adc-marketing' ),
			'value' => sprintf( /* translators: %d: number of list rows. */ __( 'Top %d', 'adc-marketing' ), (int) $filters['top'] ),
		);
	}
	if ( (int) ( $filters['product'] ?? 0 ) > 0 ) {
		$active_chips[] = array(
			'label' => __( 'Produs (ID)', 'adc-marketing' ),
			'value' => (string) (int) $filters['product'],
		);
	}

	$adv_open = $active_chips !== array();
?>
<div class="adc-an" data-adc-analytics>
<div class="adc-an-page">
	<?php if ( $channel_statuses !== array() ) : ?>
		<nav class="adc-an-channel-status" aria-label="<?php esc_attr_e( 'Starea canalelor', 'adc-marketing' ); ?>">
			<?php foreach ( $channel_statuses as $channel ) : ?>
				<a class="adc-an-channel-status__item is-<?php echo esc_attr( (string) ( $channel['state'] ?? 'off' ) ); ?>" href="<?php echo esc_url( (string) ( $channel['url'] ?? '' ) ); ?>">
					<span class="adc-an-channel-status__icon" aria-hidden="true"><?php echo 'on' === ( $channel['state'] ?? '' ) ? '✓' : ( 'off' === ( $channel['state'] ?? '' ) ? '○' : '!' ); ?></span>
					<span><?php echo $e( $channel['label'] ?? '' ); ?></span><span class="adc-an-channel-status__state"><?php echo $e( $channel['text'] ?? '' ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<header class="adc-an-hero">
		<p class="adc-an-meta">
			<?php echo $e( (string) $get( $v, 'range_label', '' ) ); ?>
			<?php if ( '' !== $currency ) : ?>
				<span class="adc-an-sep" aria-hidden="true">·</span> <?php echo $e( $currency ); ?>
			<?php endif; ?>
			<span class="adc-an-sep" aria-hidden="true">·</span>
			<?php
			printf(
				/* translators: %d: minimum cell value. */
				esc_html__( 'Prag minim: %d', 'adc-marketing' ),
				$threshold
			);
			?>
		</p>
	</header>

	<?php if ( ! $has_reports ) : ?>
		<div class="notice notice-info adc-an-blocked">
			<p class="adc-an-empty-title"><?php esc_html_e( 'Rapoartele nu sunt disponibile în această instalare', 'adc-marketing' ); ?></p>
			<p><?php esc_html_e( 'Motorul de agregare (ADC_Analytics_Reports) nu este încă încărcat. Setările de mai jos se pot salva; cifrele nu sunt simulate nicăieri în pagină.', 'adc-marketing' ); ?></p>
		</div>
	<?php endif; ?>

	<?php /* ---------------- Toolbar: filters for the whole page ---------------- */ ?>
	<section class="adc-box adc-box--light adc-an-toolbar" aria-labelledby="adc-an-filters-h">
		<div class="adc-box__head">
			<h3 class="adc-box__title" id="adc-an-filters-h"><?php $t = __( 'Filtre raport', 'adc-marketing' ); echo esc_html( $t ) . $icon( 'filters', 'adc-help-an-filters', $t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ADC_Admin_Help::icon() escapes its own output. ?></h3>
		</div>
		<div class="adc-box__body">
			<div class="adc-an-tool">
				<div class="adc-an-fblock">
					<span class="adc-an-fkey"><?php esc_html_e( 'Interval', 'adc-marketing' ); ?></span>
					<div class="adc-an-segment" role="group" aria-label="<?php esc_attr_e( 'Interval de timp', 'adc-marketing' ); ?>">
						<?php foreach ( (array) $get( $nav, 'presets', array() ) as $preset ) : ?>
							<a class="adc-an-seg<?php echo ! empty( $preset['active'] ) ? ' is-active' : ''; ?>"
								href="<?php echo $u( (string) $preset['url'] ); ?>"
								<?php echo ! empty( $preset['active'] ) ? 'aria-current="true"' : ''; ?>>
								<?php echo $e( $preset['label'] ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="adc-an-fblock">
					<span class="adc-an-fkey"><?php esc_html_e( 'Canale', 'adc-marketing' ); ?></span>
					<div class="adc-an-segment" role="group" aria-label="<?php esc_attr_e( 'Canal de proveniență', 'adc-marketing' ); ?>">
						<?php foreach ( (array) $get( $nav, 'channels', array() ) as $channel ) : ?>
							<a class="adc-an-seg<?php echo ! empty( $channel['active'] ) ? ' is-active' : ''; ?>"
								href="<?php echo $u( (string) $channel['url'] ); ?>"
								<?php echo ! empty( $channel['active'] ) ? 'aria-current="true"' : ''; ?>>
								<?php echo $e( $channel['label'] ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				</div>

				<?php $currency_links = (array) $get( $nav, 'currencies', array() ); ?>
				<?php if ( $currency_links !== array() ) : ?>
					<div class="adc-an-fblock">
						<span class="adc-an-fkey"><?php esc_html_e( 'Monedă', 'adc-marketing' ); ?></span>
						<div class="adc-an-segment" role="group" aria-label="<?php esc_attr_e( 'Monedă pentru cifre financiare', 'adc-marketing' ); ?>">
							<?php foreach ( $currency_links as $money_link ) : ?>
								<a class="adc-an-seg<?php echo ! empty( $money_link['active'] ) ? ' is-active' : ''; ?>"
									href="<?php echo $u( (string) $money_link['url'] ); ?>"
									<?php echo ! empty( $money_link['active'] ) ? 'aria-current="true"' : ''; ?>>
									<?php echo $e( $money_link['label'] ); ?>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<details class="adc-an-adv"<?php echo $adv_open ? ' open' : ''; ?>>
				<summary>
					<?php esc_html_e( 'Filtre avansate', 'adc-marketing' ); ?>
					<?php if ( $adv_open ) : ?>
						<span class="adc-an-adv-count"><?php echo $e( (string) count( $active_chips ) ); ?></span>
					<?php endif; ?>
				</summary>

				<div class="adc-an-controls">
					<span class="adc-an-cell">
						<label class="adc-an-field" for="adc-an-page-type"><?php esc_html_e( 'Tip pagină', 'adc-marketing' ); ?></label>
						<select id="adc-an-page-type" data-adc-an-filter="page_type">
							<?php foreach ( (array) $get( $nav, 'page_types', array() ) as $type ) : ?>
								<option value="<?php echo esc_attr( (string) $type['value'] ); ?>" <?php selected( ! empty( $type['active'] ) ); ?>>
									<?php echo $e( $type['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<span class="adc-an-help"><?php esc_html_e( 'Filtrează cifrele de trafic pe un singur tip de pagină.', 'adc-marketing' ); ?></span>
					</span>

					<span class="adc-an-cell">
						<label class="adc-an-field" for="adc-an-top"><?php esc_html_e( 'Lista', 'adc-marketing' ); ?></label>
						<select id="adc-an-top" data-adc-an-filter="top">
							<?php foreach ( (array) $get( $nav, 'tops', array() ) as $top ) : ?>
								<option value="<?php echo esc_attr( (string) $top['value'] ); ?>" <?php selected( ! empty( $top['active'] ) ); ?>>
									<?php echo $e( $top['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<span class="adc-an-help"><?php esc_html_e( 'Câte rânduri se afișează în clasamente.', 'adc-marketing' ); ?></span>
					</span>

					<span class="adc-an-cell">
						<label class="adc-an-field" for="adc-an-from"><?php esc_html_e( 'Data început', 'adc-marketing' ); ?></label>
						<input type="date" id="adc-an-from" data-adc-an-filter="from" value="<?php echo esc_attr( (string) ( $filters['from'] ?? '' ) ); ?>" />
						<span class="adc-an-help"><?php esc_html_e( 'Se folosește doar împreună cu data de sfârșit.', 'adc-marketing' ); ?></span>
					</span>

					<span class="adc-an-cell">
						<label class="adc-an-field" for="adc-an-to"><?php esc_html_e( 'Data sfârșit', 'adc-marketing' ); ?></label>
						<input type="date" id="adc-an-to" data-adc-an-filter="to" value="<?php echo esc_attr( (string) ( $filters['to'] ?? '' ) ); ?>" />
						<span class="adc-an-help"><?php esc_html_e( 'Capătul intervalului personalizat.', 'adc-marketing' ); ?></span>
					</span>

					<span class="adc-an-cell">
						<label class="adc-an-field" for="adc-an-product"><?php esc_html_e( 'Produs (ID)', 'adc-marketing' ); ?></label>
						<input type="number" id="adc-an-product" class="small-text" min="0" step="1"
							data-adc-an-filter="product" value="<?php echo esc_attr( (string) ( (int) ( $filters['product'] ?? 0 ) ?: '' ) ); ?>" />
						<span class="adc-an-help"><?php esc_html_e( 'Limitează produsele și listele la un singur produs.', 'adc-marketing' ); ?></span>
					</span>
				</div>

				<p class="description"><?php esc_html_e( 'Intervalul personalizat și filtrele de tip pagină, listă sau produs se aplică prin linkul de mai sus (navigare prin URL). Fără JavaScript, folosiți intervalele prestabilite și editați parametrii din URL: range, from, to, currency, channel, page_type, top, product.', 'adc-marketing' ); ?></p>

				<noscript>
					<p class="adc-an-none"><?php esc_html_e( 'JavaScript este dezactivat: filtrele de tip pagină, listă, produs și intervalul personalizat nu pot fi aplicate din formularele de mai sus; folosiți linkurile de interval și parametrii din URL.', 'adc-marketing' ); ?></p>
				</noscript>
			</details>

			<?php if ( $active_chips !== array() ) : ?>
				<ul class="adc-an-chips">
					<?php foreach ( $active_chips as $chip ) : ?>
						<li class="adc-an-chip-pill">
							<span class="adc-an-chip-pill__label"><?php echo $e( $chip['label'] ); ?></span>
							<span class="adc-an-chip-pill__value"><?php echo $e( $chip['value'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<div class="adc-an-tool-foot">
				<a class="button button-primary adc-an-apply" id="adc-an-apply" href="<?php echo $u( (string) $get( $nav, 'apply', '' ) ); ?>">
					<?php esc_html_e( 'Aplică filtrele', 'adc-marketing' ); ?>
					<span class="screen-reader-text adc-an-dirty-hint" aria-live="polite"></span>
				</a>
				<a class="adc-an-reset" href="<?php echo $u( (string) $get( $nav, 'reset', '' ) ); ?>"><?php esc_html_e( 'Resetați filtrele', 'adc-marketing' ); ?></a>
			</div>
		</div>
	</section>

	<?php /* ---------------- Tier 1: key figures ---------------- */ ?>
	<?php if ( $figures !== array() ) : ?>
		<section class="adc-an-sec" aria-labelledby="adc-an-figures-h">
			<div class="adc-an-sec__head">
				<h2 class="adc-an-sec__title" id="adc-an-figures-h"><?php $t = __( 'Cifre cheie', 'adc-marketing' ); echo esc_html( $t ) . $icon( 'figures', 'adc-help-an-figures', $t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ADC_Admin_Help::icon() escapes its own output. ?></h2>
				<p class="adc-an-sec__intro"><?php esc_html_e( 'Valorile intervalului selectat, pe cele două registre separate.', 'adc-marketing' ); ?></p>
			</div>

			<div class="adc-an-kpis">
				<?php foreach ( $kpi_primary as $figure ) : ?>
					<div class="adc-kpi<?php echo ! empty( $figure['is_money'] ) ? ' is-money' : ''; ?><?php echo ! empty( $figure['tone'] ) ? ' adc-kpi--' . esc_attr( (string) $figure['tone'] ) : ''; ?>">
						<span class="adc-kpi__label"><?php echo $e( $figure['label'] ?? '' ); ?></span>
						<span class="adc-kpi__value"><?php echo isset( $figure['display'] ) ? $k( $figure['display'] ) : $e( '—' ); ?></span>
						<span class="adc-kpi__note"><?php echo $e( $figure['note'] ?? '' ); ?></span>
						<?php $render_delta( $deltas, (string) ( $figure['label'] ?? '' ) ); ?>
					</div>
				<?php endforeach; ?>
			</div>

			<section class="adc-box adc-box--light adc-an-support" aria-labelledby="adc-an-support-h">
				<div class="adc-box__head">
					<h3 class="adc-box__title" id="adc-an-support-h"><?php esc_html_e( 'Alte cifre ale intervalului', 'adc-marketing' ); ?></h3>
					<?php echo $prov( 'fin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $prov() escapes its own output. ?>
				</div>
				<div class="adc-box__body">
					<?php if ( $kpi_support !== array() ) : ?>
						<dl class="adc-an-metrics">
							<?php foreach ( $kpi_support as $figure ) : ?>
								<div class="adc-an-metric<?php echo ! empty( $figure['is_money'] ) ? ' is-money' : ''; ?><?php echo ! empty( $figure['tone'] ) ? ' is-' . esc_attr( (string) $figure['tone'] ) : ''; ?>">
									<dt><?php echo $e( $figure['label'] ?? '' ); ?></dt>
									<dd>
										<span class="adc-an-metric__value"><?php echo isset( $figure['display'] ) ? $k( $figure['display'] ) : $e( '—' ); ?></span>
										<span class="adc-an-metric__note"><?php echo $e( $figure['note'] ?? '' ); ?></span>
										<?php $render_delta( $deltas, (string) ( $figure['label'] ?? '' ) ); ?>
									</dd>
								</div>
							<?php endforeach; ?>
						</dl>
					<?php endif; ?>

					<?php if ( ! empty( $compare['available'] ) ) : ?>
						<p class="adc-an-compare">
							<strong><?php esc_html_e( 'Față de intervalul anterior:', 'adc-marketing' ); ?></strong>
							<span class="adc-an-compare-range"><?php echo $e( (string) $get( $compare, 'range', '' ) ); ?></span>
						</p>
					<?php elseif ( '' !== (string) $get( $compare, 'text', '' ) ) : ?>
						<p class="adc-an-compare is-incomplete"><?php echo $e( (string) $compare['text'] ); ?></p>
					<?php endif; ?>
				</div>
			</section>
		</section>
	<?php endif; ?>

	<?php /* ---------------- Tier 2: instrument rows ---------------- */ ?>
	<?php $traffic_gate = $gate_state( 'traffic' ); ?>
	<section class="adc-an-sec" aria-labelledby="adc-an-trends-h">
		<div class="adc-an-sec__head">
			<h2 class="adc-an-sec__title" id="adc-an-trends-h"><?php esc_html_e( 'Tendințe pe zile', 'adc-marketing' ); ?></h2>
			<p class="adc-an-sec__intro"><?php esc_html_e( 'Graficul alături de valorile pe zile: aceeași poveste, două forme.', 'adc-marketing' ); ?></p>
		</div>

		<?php if ( $traffic_on ) : ?>
		<div class="adc-an-row">
				<section class="adc-box adc-box--dark" id="traffic" aria-labelledby="traffic-h">
					<span class="adc-corner" aria-hidden="true"></span>
					<div class="adc-box__head">
						<h3 class="adc-box__title" id="traffic-h"><?php esc_html_e( 'Cereri de pagină pe zi', 'adc-marketing' ); ?></h3>
						<?php echo $prov( 'est' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $prov() escapes its own output. ?>
					</div>
					<div class="adc-box__body">
						<?php
						if ( $traffic_ok ) {
							$render_chart_plot( $traffic_chart, 'adc-an-traffic-chart', __( 'Cereri de pagină estimate pe zi', 'adc-marketing' ) );
							$render_legend( array( __( 'Cereri estimate', 'adc-marketing' ) ) );
						} else {
							$render_empty( __( 'Fără date de trafic pentru acest interval', 'adc-marketing' ), __( 'Colectarea este activă, dar nu a fost înregistrată nicio celulă de trafic între datele selectate.', 'adc-marketing' ) );
						}
						?>
					</div>
				</section>

				<?php if ( $traffic_ok ) : ?>
					<section class="adc-box adc-box--light" aria-labelledby="traffic-data-h">
						<div class="adc-box__head">
							<h3 class="adc-box__title" id="traffic-data-h"><?php esc_html_e( 'Cereri pe zile (valori)', 'adc-marketing' ); ?></h3>
							<?php echo $prov( 'est' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $prov() escapes its own output. ?>
							<?php $render_export( 'traffic', __( 'Export CSV — trafic', 'adc-marketing' ) ); ?>
						</div>
						<div class="adc-box__body">
							<?php $render_chart_table( $traffic_chart, 'adc-an-traffic-chart' ); ?>
						</div>
					</section>
				<?php else : ?>
					<section class="adc-box adc-box--light" aria-labelledby="traffic-data-h">
						<div class="adc-box__head">
							<h3 class="adc-box__title" id="traffic-data-h"><?php esc_html_e( 'Cereri pe zile (valori)', 'adc-marketing' ); ?></h3>
							<?php echo $prov( 'est' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $prov() escapes its own output. ?>
						</div>
						<div class="adc-box__body">
							<p class="adc-an-none"><?php esc_html_e( 'Fără celule deasupra pragului de confidențialitate pentru acest interval.', 'adc-marketing' ); ?></p>
						</div>
					</section>
				<?php endif; ?>
			</div>
	<?php else : ?>
		<section class="adc-box adc-box--light" id="traffic" aria-labelledby="traffic-h">
				<div class="adc-box__head">
					<h3 class="adc-box__title" id="traffic-h"><?php esc_html_e( 'Trafic (estimativ)', 'adc-marketing' ); ?></h3>
					<span class="adc-an-pill"><?php echo $e( $traffic_gate['text'] ); ?></span>
				</div>
				<div class="adc-box__body">
					<?php
					$render_empty(
						__( 'Numărătoarea traficului este oprită', 'adc-marketing' ),
						(string) $traffic_gate['reason'],
						'#adc-an-settings-traffic',
						__( 'Vezi secțiunea de trafic', 'adc-marketing' )
					);
					?>
				</div>
		</section>
	<?php endif; ?>

	<?php if ( $commerce_on && $has_reports ) : ?>
		<div class="adc-an-row">
			<section class="adc-box adc-box--dark" id="orders" aria-labelledby="orders-h">
				<span class="adc-corner" aria-hidden="true"></span>
				<div class="adc-box__head">
					<h3 class="adc-box__title" id="orders-h"><?php esc_html_e( 'Comenzi și venit pe zi', 'adc-marketing' ); ?></h3>
					<?php echo $prov( 'fin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $prov() escapes its own output. ?>
				</div>
				<div class="adc-box__body">
					<?php
					if ( $orders_ok ) {
						$render_chart_plot( $series, 'adc-an-commerce-chart', __( 'Comenzi plătite (bare) și venit brut (linie), pe zi', 'adc-marketing' ) );
						$render_legend(
							array(
								__( 'Venit brut (linie)', 'adc-marketing' ),
								__( 'Comenzi plătite (bare)', 'adc-marketing' ),
							)
						);
					} else {
						$render_empty(
							__( 'Fără comenzi înregistrate în acest interval', 'adc-marketing' ),
							__( 'Agregatele financiare sunt goale pentru datele selectate. Comenzile existente în alte monede nu sunt incluse — alegeți moneda corectă de mai sus.', 'adc-marketing' )
						);
					}
					?>
				</div>
			</section>

			<?php if ( $orders_ok ) : ?>
				<section class="adc-box adc-box--light" aria-labelledby="orders-data-h">
					<div class="adc-box__head">
						<h3 class="adc-box__title" id="orders-data-h"><?php esc_html_e( 'Comenzi și venit pe zile (valori)', 'adc-marketing' ); ?></h3>
						<?php echo $prov( 'fin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $prov() escapes its own output. ?>
						<?php $render_export( 'orders', __( 'Export CSV — comenzi', 'adc-marketing' ) ); ?>
						<?php $render_export( 'revenue', __( 'Export CSV — venit', 'adc-marketing' ) ); ?>
					</div>
					<div class="adc-box__body">
						<?php $render_chart_table( $series, 'adc-an-commerce-chart' ); ?>
					</div>
				</section>
			<?php else : ?>
				<section class="adc-box adc-box--light" aria-labelledby="orders-data-h">
					<div class="adc-box__head">
						<h3 class="adc-box__title" id="orders-data-h"><?php esc_html_e( 'Comenzi și venit pe zile (valori)', 'adc-marketing' ); ?></h3>
						<?php echo $prov( 'fin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $prov() escapes its own output. ?>
					</div>
					<div class="adc-box__body">
						<p class="adc-an-none"><?php esc_html_e( 'Fără celule deasupra pragului de confidențialitate pentru acest interval.', 'adc-marketing' ); ?></p>
					</div>
				</section>
			<?php endif; ?>
		</div>
	<?php elseif ( $has_reports ) : ?>
		<section class="adc-box adc-box--light" id="orders" aria-labelledby="orders-h">
			<div class="adc-box__head">
				<h3 class="adc-box__title" id="orders-h"><?php esc_html_e( 'Comenzi și venit', 'adc-marketing' ); ?></h3>
				<span class="adc-an-pill"><?php echo $e( $gate_state( 'commerce' )['text'] ); ?></span>
			</div>
			<div class="adc-box__body">
				<?php
				$render_empty(
					__( 'Raportarea financiară nu este activă', 'adc-marketing' ),
					(string) $gate_state( 'commerce' )['reason'],
					'#adc-an-settings-commerce',
					__( 'Deschideți setările financiare', 'adc-marketing' )
				);
				?>
			</div>
		</section>
	<?php endif; ?>
	</section><!-- .adc-an-sec (Tendințe pe zile) -->

	<?php /* ---------------- Tier 3: breakdowns and tables ---------------- */ ?>
	<?php if ( $has_reports ) : ?>
		<?php
		$cart_gate = $gate_state( 'add_to_cart' );
		$utm_gate  = $gate_state( 'utm' );
		?>
		<?php if ( $traffic_on || $commerce_on ) : ?>
			<section class="adc-an-sec" aria-labelledby="adc-an-breakdown-h">
				<div class="adc-an-sec__head">
					<h2 class="adc-an-sec__title" id="adc-an-breakdown-h"><?php esc_html_e( 'Defalcări și topuri', 'adc-marketing' ); ?></h2>
					<p class="adc-an-sec__intro"><?php esc_html_e( 'Detaliile pe surse: liste ordonate, fiecare cu exportul ei.', 'adc-marketing' ); ?></p>
				</div>

				<?php if ( $traffic_on ) : ?>
					<div class="adc-an-row">
						<?php $render_ranking( $channels, 'channels', __( 'Canale de proveniență (orientativ)', 'adc-marketing' ), __( 'Cereri estimate', 'adc-marketing' ), false, 'est', __( 'Export CSV — canale', 'adc-marketing' ) ); ?>
						<?php $render_ranking( $pages, 'pages', __( 'Top pagini', 'adc-marketing' ), __( 'Cereri estimate', 'adc-marketing' ), false, 'est', __( 'Export CSV — pagini', 'adc-marketing' ) ); ?>
					</div>
				<?php endif; ?>

				<?php if ( $commerce_on ) : ?>
					<div class="adc-an-row">
						<?php $render_ranking( $products, 'products', __( 'Top produse', 'adc-marketing' ), __( 'Bucăți', 'adc-marketing' ), true, 'fin', __( 'Export CSV — produse', 'adc-marketing' ) ); ?>

						<?php if ( $statuses !== array() ) : ?>
							<section class="adc-box adc-box--light adc-an-tbl" id="statuses" aria-labelledby="statuses-h">
								<div class="adc-box__head">
									<h3 class="adc-box__title" id="statuses-h"><?php esc_html_e( 'Comenzi pe stare (instantaneu)', 'adc-marketing' ); ?></h3>
								</div>
								<div class="adc-box__body">
									<table class="widefat striped adc-an-table">
										<thead>
											<tr>
												<th scope="col"><?php esc_html_e( 'Stare', 'adc-marketing' ); ?></th>
												<th scope="col" class="adc-an-num"><?php esc_html_e( 'Comenzi', 'adc-marketing' ); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $statuses as $status ) : ?>
												<tr>
													<th scope="row">
														<?php echo $e( $status['label'] ?? $status['status'] ); ?>
														<?php if ( ! empty( $status['paid'] ) ) : ?>
															<span class="adc-an-pill adc-an-pill--paid"><?php esc_html_e( 'plătită', 'adc-marketing' ); ?></span>
														<?php endif; ?>
													</th>
													<td class="adc-an-num"><?php echo $e( number_format_i18n( (float) ( $status['count'] ?? 0 ), 0 ) ); ?></td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
									<p class="adc-an-foot"><?php esc_html_e( 'Numărul curent pe stare se citește direct din comenzi și nu face parte din agregatele pe zile.', 'adc-marketing' ); ?></p>
								</div>
							</section>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<section class="adc-an-sec" aria-labelledby="adc-an-check-h">
			<div class="adc-an-sec__head">
				<h2 class="adc-an-sec__title" id="adc-an-check-h"><?php $t = __( 'Verificarea datelor', 'adc-marketing' ); echo esc_html( $t ) . $icon( 'reconciliation', 'adc-help-an-reconciliation', $t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ADC_Admin_Help::icon() escapes its own output. ?></h2>
				<p class="adc-an-sec__intro"><?php esc_html_e( 'Diferențele se raportează, nu se corectează automat.', 'adc-marketing' ); ?></p>
			</div>

			<div class="adc-an-row<?php echo ( (array) $get( $recon, 'rows', array() ) === array() && empty( $recon['reason'] ) ) ? ' adc-an-row--single' : ''; ?>">
				<?php $recon_rows = (array) $get( $recon, 'rows', array() ); ?>
				<?php if ( $recon_rows !== array() ) : ?>
					<section class="adc-box adc-box--light adc-an-tbl" id="reconciliation" aria-labelledby="recon-h">
						<div class="adc-box__head">
							<h3 class="adc-box__title" id="recon-h"><?php esc_html_e( 'Recalculare (agregate vs. comenzi)', 'adc-marketing' ); ?><?php echo $icon( 'reconciliation', 'adc-help-an-reconciliation', __( 'Recalculare (agregate vs. comenzi)', 'adc-marketing' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ADC_Admin_Help::icon() escapes its own output. ?></h3>
						</div>
						<div class="adc-box__body">
							<details class="adc-an-data"<?php echo $recon_warning_texts !== array() ? ' open' : ''; ?>>
								<summary>
									<?php esc_html_e( 'Detaliile recalculării (tabel)', 'adc-marketing' ); ?>
									<span class="adc-an-data-count"><?php echo $e( sprintf( /* translators: %d: number of rows. */ _n( '%d rând', '%d rânduri', count( $recon_rows ), 'adc-marketing' ), count( $recon_rows ) ) ); ?></span>
								</summary>
								<div class="adc-an-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Tabel de recalculare, derulabil', 'adc-marketing' ); ?>">
								<table class="widefat striped adc-an-table">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Metric', 'adc-marketing' ); ?></th>
										<th scope="col" class="adc-an-num"><?php esc_html_e( 'Diferență', 'adc-marketing' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $recon_rows as $row ) : ?>
										<tr>
											<th scope="row">
												<?php echo $e( $row['label'] ); ?>
												<?php if ( '' !== (string) ( $row['date'] ?? '' ) ) : ?>
													<span class="adc-an-prov"><?php echo $e( (string) $row['date'] ); ?></span>
												<?php endif; ?>
												<?php if ( ! empty( $row['notify'] ) ) : ?>
													<span class="adc-inline-warning"><?php echo $e( $recon_warning_text( $row['label'] ?? '' ) ); ?></span>
												<?php endif; ?>
											</th>
											<td class="adc-an-num<?php echo ! empty( $row['notify'] ) ? ' adc-an-alert' : ''; ?>">
												<?php
												if ( ! empty( $row['show'] ) && '' !== $currency && function_exists( 'wc_price' ) ) {
													echo $k( wc_price( (float) $row['diff'], array( 'currency' => $currency ) ) );
												} else {
													echo $e( number_format_i18n( (float) $row['diff'], 2 ) );
												}
												?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
								</table>
								</div>
							</details>
						</div>
					</section>
				<?php elseif ( ! empty( $recon['reason'] ) ) : ?>
					<section class="adc-box adc-box--light" id="reconciliation" aria-labelledby="recon-h">
						<div class="adc-box__head">
							<h3 class="adc-box__title" id="recon-h"><?php esc_html_e( 'Recalculare (agregate vs. comenzi)', 'adc-marketing' ); ?><?php echo $icon( 'reconciliation', 'adc-help-an-reconciliation', __( 'Recalculare (agregate vs. comenzi)', 'adc-marketing' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ADC_Admin_Help::icon() escapes its own output. ?></h3>
						</div>
						<div class="adc-box__body">
							<p class="adc-an-none"><?php echo $e( (string) $recon['reason'] ); ?></p>
						</div>
					</section>
				<?php endif; ?>

				<section class="adc-box adc-box--light" id="interactions" aria-labelledby="interactions-h">
					<div class="adc-box__head">
						<h3 class="adc-box__title" id="interactions-h"><?php esc_html_e( 'Interacțiuni', 'adc-marketing' ); ?></h3>
						<span class="adc-an-pill"><?php echo $e( $cart_gate['text'] ); ?></span>
					</div>
					<div class="adc-box__body">
						<p class="adc-an-empty-body"><?php echo $e( $cart_gate['reason'] ); ?></p>
						<p class="adc-an-foot"><?php echo $e( $utm_gate['reason'] ); ?></p>
					</div>
				</section>
			</div>
		</section>

	<?php endif; // .has_reports — the reports-dependent sections end here. ?>

	<section class="adc-an-sec" aria-labelledby="adc-an-status-h">
		<div class="adc-an-sec__head">
			<h2 class="adc-an-sec__title" id="adc-an-status-h"><?php $t = __( 'Stare colectare și note', 'adc-marketing' ); echo esc_html( $t ) . $icon( 'gates', 'adc-help-an-gates', $t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ADC_Admin_Help::icon() escapes its own output. ?></h2>
			<p class="adc-an-sec__intro"><?php esc_html_e( 'Ce colectează modulul acum și ce nu poate determina din principiile lui.', 'adc-marketing' ); ?></p>
		</div>

		<div class="adc-an-row">
			<section class="adc-box adc-box--light" aria-labelledby="adc-an-gates-h">
				<div class="adc-box__head">
					<h3 class="adc-box__title" id="adc-an-gates-h"><?php esc_html_e( 'Stare colectare', 'adc-marketing' ); ?></h3>
				</div>
				<div class="adc-box__body">
					<dl class="adc-an-gate-list">
						<?php foreach ( $gates as $gate ) : ?>
							<div class="adc-an-gate adc-an-gate--<?php echo esc_attr( (string) ( $gate['state'] ?? 'off' ) ); ?>">
								<dt><span class="adc-an-gate-name"><?php echo $e( $gate['label'] ?? '' ); ?></span></dt>
								<dd>
									<span class="adc-an-pill"><?php echo $e( $gate['text'] ?? '' ); ?></span>
									<span class="adc-an-gate-reason"><?php echo $e( $gate['reason'] ?? '' ); ?></span>
								</dd>
							</div>
						<?php endforeach; ?>
					</dl>
				</div>
			</section>

			<section class="adc-box adc-box--light" aria-labelledby="adc-an-registers-h">
				<div class="adc-box__head">
					<h3 class="adc-box__title" id="adc-an-registers-h"><?php esc_html_e( 'Cele două registre', 'adc-marketing' ); ?></h3>
				</div>
				<div class="adc-box__body">
					<p class="adc-an-lede"><?php esc_html_e( 'Două registre separate: cererile de pagină sunt stimate din logările serverului, iar cifrele financiare provin din comenzile WooCommerce. Ele nu se împart una la alta: acest modul nu calculează rată de conversie, vizitatori unici sau atribuire.', 'adc-marketing' ); ?></p>
				</div>
			</section>
		</div>

		<div class="adc-an-row<?php echo ( $general_warnings === array() || $unavailable === array() ) ? ' adc-an-row--single' : ''; ?>">
			<?php if ( $general_warnings !== array() ) : ?>
				<details class="adc-an-warnings">
					<summary><?php printf( esc_html__( 'Avertismente (%d)', 'adc-marketing' ), count( $general_warnings ) ); ?></summary>
				<section class="adc-box adc-box--light" aria-labelledby="adc-an-notes-h">
					<div class="adc-box__head">
						<h3 class="adc-box__title" id="adc-an-notes-h"><?php $t = __( 'Atenționări și precizii', 'adc-marketing' ); echo esc_html( $t ) . $icon( 'warnings', 'adc-help-an-warnings', $t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ADC_Admin_Help::icon() escapes its own output. ?></h3>
					</div>
					<div class="adc-box__body">
						<ul class="adc-an-note-list">
							<?php foreach ( $general_warnings as $warning ) : ?>
								<li class="adc-an-note adc-an-note--<?php echo esc_attr( (string) ( $warning['tone'] ?? 'info' ) ); ?>">
									<?php echo $e( $warning['text'] ?? '' ); ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				</section>
				</details>
			<?php endif; ?>

			<?php if ( $unavailable !== array() ) : ?>
				<section class="adc-box adc-box--light" aria-labelledby="adc-an-unavailable-h">
					<div class="adc-box__head">
						<h3 class="adc-box__title" id="adc-an-unavailable-h"><?php esc_html_e( 'Ce nu poate determina acest modul', 'adc-marketing' ); ?></h3>
					</div>
					<div class="adc-box__body">
						<dl class="adc-an-unavail-list">
							<?php foreach ( $unavailable as $item ) : ?>
								<div class="adc-an-unavail">
									<dt><?php echo $e( $item['label'] ); ?> <span class="adc-an-pill"><?php esc_html_e( 'indisponibil', 'adc-marketing' ); ?></span></dt>
									<dd><?php echo $e( $item['text'] ); ?></dd>
								</div>
							<?php endforeach; ?>
						</dl>
					</div>
				</section>
			<?php endif; ?>
		</div>
	</section>

	<?php if ( $exports === array() ) : ?>
		<p class="description adc-an-noexport"><?php esc_html_e( 'Exportul CSV nu este disponibil în această instalare (motorul de export lipsește).', 'adc-marketing' ); ?></p>
	<?php endif; ?>

</div><!-- .adc-an-page -->
</div>

	<hr class="adc-an-rule" />

	<span id="adc-an-settings" class="adc-an-anchor"></span>

	<div class="adc-an-settings">

	<?php $an_t_commerce = __( '1. Rapoarte financiare din comenzi', 'adc-marketing' ); ?>
	<section class="adc-box adc-box--light adc-an-settings-block" id="adc-an-settings-commerce">
	<div class="adc-box__head">
		<h3 class="adc-box__title title" id="adc-an-settings-commerce-h"><?php echo esc_html( $an_t_commerce ) . $icon( 'section_commerce', 'adc-help-an-commerce', $an_t_commerce ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ADC_Admin_Help::icon() escapes its own output. ?></h3>
	</div>
	<div class="adc-box__body">
	<p class="adc-admin-section-intro">
		<?php esc_html_e( 'Adună pe zile valorile comenzilor din WooCommerce (comenzi plătite, venit, TVA, livrare, reduceri, rambursări) și le afișează în Prezentare generală. Datele provin din comenzile proprii ale magazinului, rămân în baza de date WordPress și nu se transmit nicăieri; funcționează independent de bannerul de consimțământ.', 'adc-marketing' ); ?>
	</p>

	<div class="adc-help-options">
		<?php
		$option(
			__( 'Înregistrez agregate financiare pe zile, din comenzile WooCommerce', 'adc-marketing' ),
			'<input type="checkbox" id="adc-an-enabled-commerce" name="analytics[enabled_commerce]" value="1" ' . checked( ! empty( $settings['enabled_commerce'] ), true, false ) . ' />',
			__( 'Dacă o bifați, la prima intrare a unei comenzi în stările plătite se scrie un rând agregat pe ziua respectivă; fără această bifă (și fără confirmarea de mai jos) nu se scrie nimic.', 'adc-marketing' ),
			'adc-an-enabled-commerce',
			'adc-help-an-commerce-enabled',
			'opt_commerce_enabled'
		);

		$option(
			__( 'Confirm că venit brut = suma valorilor comenzilor plătite, venit net = brut minus rambursări, iar retenția de mai jos corespunde politicii magazinului.', 'adc-marketing' ),
			'<input type="checkbox" id="adc-an-commerce-ack" name="analytics[commerce_ack]" value="1" ' . checked( ! empty( $settings['commerce_ack'] ), true, false ) . ' />',
			__( 'A doua confirmare este obligatorie: fără ea, cheltuielile și veniturile rămân oprite, chiar dacă ați activat opțiunea de mai sus.', 'adc-marketing' ),
			'adc-an-commerce-ack',
			'adc-help-an-commerce-ack',
			'opt_commerce_ack'
		);

		$paid_choices = '';
		foreach ( (array) $paid['options'] as $slug => $label ) {
			$paid_choices .= '<label class="adc-an-opt"><input type="checkbox" name="analytics[paid_statuses][]" value="' . esc_attr( (string) $slug ) . '" '
				. checked( in_array( (string) $slug, (array) $paid['selected'], true ), true, false ) . ' /> ' . esc_html( (string) $label ) . '</label>';
		}
		if ( ! empty( $paid['diverges'] ) ) {
			$paid_choices .= '<p class="adc-an-alert-text">' . esc_html__( 'Atenție: aceste stări diferă de stările de purchase folosite la evenimentele de marketing; cifrele din cele două module nu vor coincidea.', 'adc-marketing' ) . '</p>';
		}
		$option(
			__( 'Stări considerate plătite', 'adc-marketing' ),
			$paid_choices,
			__( 'O comandă se numără o singură dată, la prima tranziție într-una dintre aceste stări; rambursările nu scad numărul de comenzi plătite.', 'adc-marketing' ),
			'',
			'adc-help-an-paid-statuses',
			'opt_paid_statuses'
		);
		?>
	</div>
	</div><!-- .adc-box__body -->
	</section>

	<?php $an_t_traffic = __( '2. Contor statistic de trafic (fără identificatori)', 'adc-marketing' ); ?>
	<section class="adc-box adc-box--light adc-an-settings-block" id="adc-an-settings-traffic">
	<div class="adc-box__head">
		<h3 class="adc-box__title title" id="adc-an-settings-traffic-h"><?php echo esc_html( $an_t_traffic ) . $icon( 'section_traffic', 'adc-help-an-traffic', $an_t_traffic ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ADC_Admin_Help::icon() escapes its own output. ?></h3>
	</div>
	<div class="adc-box__body">
	<p class="adc-admin-section-intro">
		<?php esc_html_e( 'Numără accesările de pagină pe zi, grupate pe tip de pagină și pe canalul de proveniență. Numărarea se face pe server, fără cookie, fără JavaScript și fără niciun identificator de vizitator; datele rămân în baza de date WordPress.', 'adc-marketing' ); ?>
	</p>
	<ul class="adc-an-scope">
		<li class="adc-an-scope-in">
			<?php esc_html_e( 'Se numără: cereri de pagină pe zi, grupate pe tip de pagină (produs, articol, coș etc.) și pe canalul de proveniență (direct, căutare, rețea socială, trimitere de pe alt site).', 'adc-marketing' ); ?>
		</li>
		<li class="adc-an-scope-out">
			<?php esc_html_e( 'NU se colectează: adresă IP, cookie, ID de utilizator, user agent sau URL referer. Nu se poate identifica un vizitator individual și nu se creează profiluri.', 'adc-marketing' ); ?>
		</li>
		<li class="adc-an-scope-out">
			<?php esc_html_e( 'Cererile care semnalează confidențialitate (Sec-GPC: 1 sau DNT: 1) nu sunt numărate; parametrii UTM nu sunt stocați; cifrele sunt estimative și subestimate din cauza cache-ului, CDN-ului și a boților.', 'adc-marketing' ); ?>
		</li>
	</ul>
	<p class="adc-an-gate-warning">
		<?php echo $e( $gate_state( 'traffic' )['reason'] ); ?>
	</p>

	<div class="adc-help-options">
		<?php
		$option(
			__( 'Activează contorul statistic de trafic', 'adc-marketing' ),
			'<input type="checkbox" id="adc-an-enabled-traffic" name="analytics[enabled_traffic]" value="1" ' . checked( ! empty( $settings['enabled_traffic'] ), true, false ) . ' />',
			__( 'Dacă o bifați, fiecare pagină generată de WordPress pe site adaugă unu la contorul zilei respective; oprirea nu șterge ce a fost deja numărat.', 'adc-marketing' ),
			'adc-an-enabled-traffic',
			'adc-help-an-traffic-enabled',
			'opt_traffic_enabled'
		);

		$option(
			__( 'Am actualizat Politica de Confidențialitate și descriu acolo acest contor anonim-agregat, inclusiv dreptul de a obiecta.', 'adc-marketing' ),
			'<input type="checkbox" id="adc-an-traffic-ack" name="analytics[traffic_ack]" value="1" ' . checked( ! empty( $settings['traffic_ack'] ), true, false ) . ' />',
			__( 'Fără această confirmare, contorul rămâne oprit chiar dacă primul câmp este bifat — este momentul în care vă asumați activarea măsurării.', 'adc-marketing' ),
			'adc-an-traffic-ack',
			'adc-help-an-traffic-ack',
			'opt_traffic_ack'
		);

		$option(
			__( 'Excluderi din numărare', 'adc-marketing' ),
			'<label class="adc-an-opt" for="adc-an-exclude-admins"><input type="checkbox" id="adc-an-exclude-admins" name="analytics[exclude_logged_in_admins]" value="1" '
				. checked( ! empty( $settings['exclude_logged_in_admins'] ), true, false ) . ' /> '
				. esc_html__( 'Nu număra vizitele administratorilor autentificați', 'adc-marketing' ) . '</label>'
				. '<label class="adc-an-opt" for="adc-an-bot-filter"><input type="checkbox" id="adc-an-bot-filter" name="analytics[bot_filter_enabled]" value="1" '
				. checked( ! empty( $settings['bot_filter_enabled'] ), true, false ) . ' /> '
				. esc_html__( 'Filtrare roboți conservatoare (pe user agent, fără a-l stoca)', 'adc-marketing' ) . '</label>',
			__( 'Ambele se aplică doar contorului de trafic, după pornirea lui; cu una dezactivată, acea categorie ajunge în cifre.', 'adc-marketing' ),
			'',
			'adc-help-an-exclusions',
			'opt_exclusions'
		);

		$option(
			__( 'Prag minim pentru celule (valori)', 'adc-marketing' ),
			'<input type="number" id="adc-an-min-cell" name="analytics[min_cell_count]" class="small-text" min="3" max="50" step="1" value="' . esc_attr( (string) ( $settings['min_cell_count'] ?? 5 ) ) . '" />',
			__( 'Celulele mai mici decât această valoare nu se afișează și nu se exportă; se aplică și listelor de produse, care există independent de trafic.', 'adc-marketing' ),
			'adc-an-min-cell',
			'adc-help-an-min-cell',
			'opt_min_cell'
		);
		?>
	</div>

	<details class="adc-an-draft adc-an-internal-notes">
		<summary><?php esc_html_e( 'Notițe interne (nu activează nimic)', 'adc-marketing' ); ?><?php echo $icon( 'internal_notes', 'adc-help-an-internal-notes', __( 'Notițe interne (nu activează nimic)', 'adc-marketing' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ADC_Admin_Help::icon() escapes its own output. ?></summary>
		<p class="description"><?php esc_html_e( 'Câmpurile de mai jos sunt doar pentru evidența internă a evaluării juridice. Ele nu pornesc nicio colectare și nu înlocuiesc bifa de confirmare de mai sus.', 'adc-marketing' ); ?></p>
		<div class="adc-help-options">
			<?php
			$basis_control = '<select id="adc-an-traffic-basis" name="analytics[traffic_legal_basis]">'
				. '<option value="" ' . selected( '' === (string) ( $settings['traffic_legal_basis'] ?? '' ), true, false ) . '>' . esc_html__( '— fără temei stabilit —', 'adc-marketing' ) . '</option>'
				. '<option value="lia" ' . selected( 'lia' === (string) ( $settings['traffic_legal_basis'] ?? '' ), true, false ) . '>' . esc_html__( 'Interes legitim (evaluare LIA)', 'adc-marketing' ) . '</option>'
				. '</select>';
			$option(
				__( 'Temei juridic propus', 'adc-marketing' ),
				$basis_control,
				__( 'Notiță de documentație: doar „interes legitim” este acceptat de modul; selecția nu activează nimic de una singură.', 'adc-marketing' ),
				'adc-an-traffic-basis'
			);

			$option(
				__( 'Referință LIA (notiță)', 'adc-marketing' ),
				'<input type="text" id="adc-an-traffic-ref" name="analytics[traffic_legal_reference]" class="regular-text" value="' . esc_attr( (string) ( $settings['traffic_legal_reference'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'ID document / URL / data evaluării', 'adc-marketing' ) . '" />',
				__( 'Unde găsiți evaluarea (ID document, URL, dată). Rămâne în WordPress și nu se trimite nicăieri.', 'adc-marketing' ),
				'adc-an-traffic-ref'
			);

			$option(
				__( 'Data aprobării (notiță)', 'adc-marketing' ),
				'<input type="date" id="adc-an-traffic-date" name="analytics[traffic_approved_at]" value="' . esc_attr( (string) ( $settings['traffic_approved_at'] ?? '' ) ) . '" />',
				__( 'Data de la care considerați evaluarea aprobată; o dată viitoare sau invalidă este eliminată la salvare.', 'adc-marketing' ),
				'adc-an-traffic-date'
			);
			?>
		</div>
	</details>
	</div><!-- .adc-box__body -->
	</section>

	<?php $an_t_blocked = __( '3. Indisponibile în această versiune', 'adc-marketing' ); ?>
	<section class="adc-box adc-box--light adc-an-settings-block">
	<div class="adc-box__head">
		<h3 class="adc-box__title title"><?php echo esc_html( $an_t_blocked ) . $icon( 'section_unavailable', 'adc-help-an-unavailable', $an_t_blocked ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ADC_Admin_Help::icon() escapes its own output. ?></h3>
	</div>
	<div class="adc-box__body">
	<p class="adc-admin-section-intro">
		<?php esc_html_e( 'Ce înseamnă: funcțiile de mai jos au cod și tabele în modul, dar porțile lor sunt închise în cod și nu au comutator. Nicio setare nu le poate activa; interfața nu afișează câmpuri pentru ele ca să nu sugereze că ar merge.', 'adc-marketing' ); ?>
	</p>
	<ul class="adc-an-blocked-list">
		<li>
			<strong><?php esc_html_e( 'Parametri UTM și atribuirea campaniilor', 'adc-marketing' ); ?><?php echo $icon( 'blocked_utm', 'adc-help-an-utm', __( 'Parametri UTM și atribuirea campaniilor', 'adc-marketing' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ADC_Admin_Help::icon() escapes its own output. ?></strong>
			<span><?php esc_html_e( 'Ar lega o comandă de campania din linkul de reclamă (utm_source / utm_medium / utm_campaign). Nu sunt stocați și nu sunt citiți din URL.', 'adc-marketing' ); ?></span>
			<span><?php echo $e( $gate_state( 'utm' )['reason'] ); ?></span>
		</li>
		<li>
			<strong><?php esc_html_e( 'Eveniment „adăugare în coș”', 'adc-marketing' ); ?><?php echo $icon( 'blocked_add_to_cart', 'adc-help-an-add-to-cart', __( 'Eveniment „adăugare în coș”', 'adc-marketing' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ADC_Admin_Help::icon() escapes its own output. ?></strong>
			<span><?php esc_html_e( 'Ar număra de câte ori intră un produs în coș, înainte de comandă. În schimb, topul produselor vândute din comenzi plătite este disponibil.', 'adc-marketing' ); ?></span>
			<span><?php echo $e( $gate_state( 'add_to_cart' )['reason'] ); ?></span>
		</li>
		<li>
			<strong><?php esc_html_e( 'Eveniment „începere finalizare”', 'adc-marketing' ); ?><?php echo $icon( 'blocked_begin_checkout', 'adc-help-an-begin-checkout', __( 'Eveniment „începere finalizare”', 'adc-marketing' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ADC_Admin_Help::icon() escapes its own output. ?></strong>
			<span><?php esc_html_e( 'Ar sta la baza calcului abandonului de coș. WooCommerce nu are un punct verificat pe server pentru „a început finalizarea”, deci abandonul nu poate fi calculat corect.', 'adc-marketing' ); ?></span>
			<span><?php echo $e( $gate_state( 'begin_checkout' )['reason'] ); ?></span>
		</li>
	</ul>
	</div><!-- .adc-box__body -->
	</section>

	<?php $an_t_retention = __( '4. Retenție și dezinstalare', 'adc-marketing' ); ?>
	<section class="adc-box adc-box--light adc-an-settings-block">
	<div class="adc-box__head">
		<h3 class="adc-box__title title"><?php echo esc_html( $an_t_retention ) . $icon( 'section_retention', 'adc-help-an-retention-section', $an_t_retention ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ADC_Admin_Help::icon() escapes its own output. ?></h3>
	</div>
	<div class="adc-box__body">
	<?php
	$an_retention_hint = sprintf(
		/* translators: 1: financial days, 2: ledger days, 3: traffic days. */
		__( 'Acum: financiar %1$d zile · registru %2$d zile · trafic %3$d zile', 'adc-marketing' ),
		(int) ( $settings['retention_commerce_days'] ?? 730 ),
		(int) ( $settings['retention_ledger_days'] ?? 365 ),
		(int) ( $settings['retention_traffic_days'] ?? 90 )
	);
	ADC_Admin_Help::advanced_start( __( 'Retenție date', 'adc-marketing' ), $an_retention_hint );
	?>
	<p class="adc-admin-section-intro"><?php esc_html_e( 'Cât timp se păstrează datele colectate; ce e mai vechi se șterge automat.', 'adc-marketing' ); ?></p>
	<div class="adc-help-options">
		<?php
		$option(
			__( 'Retenție agregate financiare (zile)', 'adc-marketing' ),
			'<input type="number" id="adc-an-retention-commerce" name="analytics[retention_commerce_days]" class="small-text" min="90" max="3650" step="1" value="' . esc_attr( (string) ( $settings['retention_commerce_days'] ?? 730 ) ) . '" />',
			__( 'Între 90 și 3650 de zile; agregatele mai vechi decât această perioadă se șterg automat și dispar din rapoarte și din CSV.', 'adc-marketing' ),
			'adc-an-retention-commerce',
			'adc-help-an-retention-commerce',
			'opt_retention_finance'
		);

		$option(
			__( 'Retenție registru de deduplicare (zile)', 'adc-marketing' ),
			'<input type="number" id="adc-an-retention-ledger" name="analytics[retention_ledger_days]" class="small-text" min="30" max="1095" step="1" value="' . esc_attr( (string) ( $settings['retention_ledger_days'] ?? 365 ) ) . '" />',
			__( 'Registrul conține ID-uri interne de comandă pentru a preveni dublarea; nu poate fi reținut mai puțin decât agregatele financiare.', 'adc-marketing' ),
			'adc-an-retention-ledger',
			'adc-help-an-retention-ledger',
			'opt_retention_finance'
		);

		$option(
			__( 'Retenție agregate trafic (zile)', 'adc-marketing' ),
			'<input type="number" id="adc-an-traffic-retention" name="analytics[retention_traffic_days]" class="small-text" min="30" max="180" step="1" value="' . esc_attr( (string) ( $settings['retention_traffic_days'] ?? 90 ) ) . '" />',
			__( 'Între 30 și 180 de zile. Contoarele mai vechi decât această perioadă se șterg automat; o perioadă mai scurtă înseamnă și o bază de date mai mică.', 'adc-marketing' ),
			'adc-an-traffic-retention',
			'adc-help-an-traffic-retention',
			'opt_retention_traffic'
		);
		?>
	</div>
	<?php ADC_Admin_Help::advanced_end(); ?>
	<p class="description"><?php esc_html_e( 'Ștergerea la dezinstalare urmează opțiunea existentă „Șterge datele la dezinstalare” din setările de consimțământ; acest modul nu adaugă un al doilea comutator.', 'adc-marketing' ); ?></p>

	<details class="adc-an-draft">
		<summary><?php esc_html_e( 'Draft de notificare de confidențialitate (pentru validare juridică)', 'adc-marketing' ); ?></summary>
		<p class="adc-an-draft-body"><?php echo $e( (string) $get( $v, 'notice_draft', '' ) ); ?></p>
		<p class="adc-an-alert-text"><?php esc_html_e( 'Nu publicați acest text ca afirmație de conformitate; trebuie validat de jurid și adaptat magazinului.', 'adc-marketing' ); ?></p>
	</details>
	</div><!-- .adc-box__body -->
	</section>

	<?php $product_stats_title = __( '5. Statistici per produs', 'adc-marketing' ); ?>
	<section class="adc-box adc-box--light adc-an-settings-block" id="adc-an-product-stats">
	<div class="adc-box__head">
		<h3 class="adc-box__title title"><?php echo esc_html( $product_stats_title ) . $icon( 'product_stats', 'adc-help-an-product-stats', $product_stats_title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ADC_Admin_Help::icon() escapes its own output. ?></h3>
	</div>
	<div class="adc-box__body">
		<p class="adc-admin-section-intro"><?php esc_html_e( 'Colectează statistici zilnice pentru fiecare produs.', 'adc-marketing' ); ?></p>
		<div class="adc-help-options">
			<?php
			$option(
				__( 'Activează colectarea statisticilor per produs', 'adc-marketing' ),
				'<input type="checkbox" id="adc-product-stats-enabled" name="product_stats[enabled]" value="1" ' . checked( ! empty( $product_stats['enabled'] ), true, false ) . ' />'
					. '<p class="adc-inline-warning">' . esc_html__( 'Activarea nu obține consimțământul vizitatorului.', 'adc-marketing' ) . '</p>',
				__( 'Debifat implicit. Chiar și activată, colectarea rămâne condiționată de consimțământul statistics al vizitatorului.', 'adc-marketing' ),
				'adc-product-stats-enabled'
			);
			$option(
				__( 'Păstrează agregatele (zile)', 'adc-marketing' ),
				'<input type="number" class="small-text" id="adc-product-stats-retention" name="product_stats[retention_days]" min="30" max="730" step="1" value="' . esc_attr( (string) ( $product_stats['retention_days'] ?? 180 ) ) . '" />',
				__( 'Între 30 și 730 de zile; valoarea implicită este 180. Agregatele mai vechi se șterg automat.', 'adc-marketing' ),
				'adc-product-stats-retention'
			);
			?>
		</div>
		<h4><?php esc_html_e( 'Text propus pentru Politica de confidențialitate', 'adc-marketing' ); ?></h4>
		<p class="description"><?php esc_html_e( 'Șablon informativ: verificați-l și validați-l cu operatorul/DPO înainte de publicare. Nu este o evaluare juridică și nu afirmă conformitatea.', 'adc-marketing' ); ?></p>
		<textarea id="adc-product-stats-privacy-copy" class="large-text" rows="10" readonly><?php echo esc_textarea( (string) $get( $v, 'product_stats_privacy_copy', '' ) ); ?></textarea>
		<p><button type="button" class="button adc-copy-btn" data-adc-copy="#adc-product-stats-privacy-copy"><?php esc_html_e( 'Copiază', 'adc-marketing' ); ?></button></p>
	</div><!-- .adc-box__body -->
	</section>

	</div><!-- .adc-an-settings -->
