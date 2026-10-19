<?php
/**
 * Analytics operațional — dashboard + settings (Romanian, admin-only).
 *
 * Rendered by ADC_Admin_Tab_Analytics through ADC_Template, inside the admin
 * shell's own <form method="post">. Therefore this file prints NO <form> tag
 * and NO nonce: report filters are plain GET links (plus JS-built URLs) and
 * carry no `name` attribute, so they cannot pollute the settings POST.
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
$unavailable   = (array) $get( $v, 'unavailable', array() );
$compare       = (array) $get( $v, 'compare', array( 'available' => false, 'rows' => array() ) );
$exports       = (array) $get( $v, 'export_links', array() );
$settings      = (array) $get( $v, 'settings', array() );
$paid          = (array) $get( $v, 'paid_statuses', array( 'selected' => array(), 'options' => array(), 'diverges' => false ) );
$threshold     = (int) $get( $v, 'threshold', 5 );
$currency      = (string) $get( $v, 'currency', '' );
$commerce_on   = (bool) $get( $v, 'commerce', false );
$traffic_on    = (bool) $get( $v, 'traffic', false );
$has_reports   = (bool) $get( $v, 'reports_ready', false );

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

/**
 * Inline chart: gridlines, area, bars, line and points, plus the axis labels in
 * real HTML text (so they stay readable at 390 px) and the equivalent table.
 *
 * @param array<string,mixed> $chart   Chart payload.
 * @param string              $id      DOM id prefix.
 * @param string              $caption Figure caption.
 */
$render_chart = static function ( array $chart, string $id, string $caption ) use ( $e, $k, $get ): void {
	if ( $chart === array() || empty( $chart['has_data'] ) ) {
		return;
	}

	$plot_id = $id . '-plot';
	$grid    = array_reverse( (array) $get( $chart, 'grid', array() ) );
	$rows    = (array) $get( $chart, 'rows', array() );
	$bars    = (array) $get( $chart, 'bars', array() );
	$marks   = (array) $get( $chart, 'marks', array() );
	$xlabels = (array) $get( $chart, 'xlabels', array() );
	?>
	<figure class="adc-an-fig" id="<?php echo esc_attr( $id ); ?>">
		<figcaption class="adc-an-fig-title">
			<?php echo $e( $caption ); ?>
			<?php if ( '' !== (string) $get( $chart, 'summary', '' ) ) : ?>
				<span class="adc-an-fig-summary"><?php echo $k( $chart['summary'] ); ?></span>
			<?php endif; ?>
		</figcaption>

		<div class="adc-an-plot">
			<div class="adc-an-yaxis" id="<?php echo esc_attr( $plot_id ); ?>-y" aria-hidden="true">
				<?php foreach ( $grid as $line ) : ?>
					<span class="adc-an-ylabel" style="top:<?php echo esc_attr( (string) round( ( (float) ( $line['y'] ?? 0 ) / 220 ) * 100, 2 ) ); ?>%;"><?php echo $e( $line['label'] ?? '' ); ?></span>
				<?php endforeach; ?>
			</div>

			<svg class="adc-an-svg" viewBox="<?php echo esc_attr( (string) $get( $chart, 'view_box', '0 0 760 220' ) ); ?>"
				preserveAspectRatio="none"
				role="img" aria-labelledby="<?php echo esc_attr( $id ); ?>" focusable="false">
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

		<details class="adc-an-data">
			<summary>
				<?php esc_html_e( 'Datele graficului (tabel)', 'adc-marketing' ); ?>
				<span class="adc-an-data-count"><?php echo $e( sprintf( /* translators: %d: number of rows. */ _n( '%d zi', '%d zile', count( $rows ), 'adc-marketing' ), count( $rows ) ) ); ?></span>
			</summary>
			<table class="widefat striped adc-an-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Data', 'adc-marketing' ); ?></th>
						<?php if ( 'combo' === (string) $get( $chart, 'kind', '' ) ) : ?>
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
							<?php if ( 'combo' === (string) $get( $chart, 'kind', '' ) ) : ?>
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
		</details>
	</figure>
	<?php
};

/**
 * Blocked / empty state panel with a reason and an in-page pointer.
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
 * @param string $report Report slug.
 * @param string $label  Distinct link text (several exports can share a panel).
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
 * Ranked list (label + measure + CSS bar) with an equivalent table.
 *
 * @param array<string,mixed> $list      Ranked payload.
 * @param string              $id        DOM id.
 * @param string              $title     Panel title.
 * @param string              $col_label Measure column heading.
 * @param bool                $money     Show the money column.
 * @param string              $unit      Provenance tag tone ('est' or 'fin').
 * @param string              $export_label Distinct CSV link text.
 */
$render_ranking = static function ( array $list, string $id, string $title, string $col_label, bool $money, string $unit, string $export_label ) use ( $e, $k, $render_export, $get ): void {
	$rows = (array) $get( $list, 'rows', array() );
	?>
	<section class="adc-an-panel" id="<?php echo esc_attr( $id ); ?>" aria-labelledby="<?php echo esc_attr( $id ); ?>-h">
		<div class="adc-an-panel-head">
			<h3 id="<?php echo esc_attr( $id ); ?>-h"><?php echo $e( $title ); ?></h3>
			<p class="adc-an-prov adc-an-prov--<?php echo esc_attr( $unit ); ?>">
				<?php
				if ( 'fin' === $unit ) {
					esc_html_e( 'Din comenzi WooCommerce', 'adc-marketing' );
				} else {
					esc_html_e( 'Estimativ', 'adc-marketing' );
				}
				?>
			</p>
			<?php $render_export( $id, $export_label ); ?>
		</div>

		<?php if ( $rows === array() ) : ?>
			<p class="adc-an-none"><?php esc_html_e( 'Fără celule deasupra pragului de confidențialitate pentru acest interval.', 'adc-marketing' ); ?></p>
		<?php else : ?>
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
								<span class="adc-an-bar" style="width:<?php echo esc_attr( (string) (int) ( $row['pct'] ?? 0 ) ); ?>%;" aria-hidden="true"></span>
							</td>
							<?php if ( $money ) : ?>
								<td class="adc-an-num"><?php echo $k( $row['money'] ?? '' ); ?></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
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
		<?php endif; ?>
	</section>
	<?php
};
?>
<div class="adc-an" data-adc-analytics>

	<section class="adc-an-head">
		<h2 class="adc-an-title"><?php esc_html_e( 'Analytics operațional', 'adc-marketing' ); ?></h2>
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
		<p class="adc-an-lede">
			<?php esc_html_e( 'Două registre separate: cererile de pagină sunt stimate din logările serverului, iar cifrele financiare provin din comenzile WooCommerce. Ele nu se împart una la alta: acest modul nu calculează rată de conversie, vizitatori unici sau atribuire.', 'adc-marketing' ); ?>
		</p>
	</section>

	<section class="adc-an-panel adc-an-gates" aria-labelledby="adc-an-gates-h">
		<div class="adc-an-panel-head">
			<h3 id="adc-an-gates-h"><?php esc_html_e( 'Stare colectare', 'adc-marketing' ); ?></h3>
		</div>
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
	</section>

	<section class="adc-an-panel adc-an-filters" aria-labelledby="adc-an-filters-h">
		<div class="adc-an-panel-head">
			<h3 id="adc-an-filters-h"><?php esc_html_e( 'Filtre raport', 'adc-marketing' ); ?></h3>
			<a class="adc-an-reset" href="<?php echo $u( (string) $get( $nav, 'reset', '' ) ); ?>"><?php esc_html_e( 'Resetați filtrele', 'adc-marketing' ); ?></a>
		</div>

		<div class="adc-an-frow">
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

		<div class="adc-an-frow">
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
			<div class="adc-an-frow">
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

		<div class="adc-an-frow adc-an-frow--js">
			<span class="adc-an-fkey"><?php esc_html_e( 'Personalizat', 'adc-marketing' ); ?></span>
			<div class="adc-an-controls">
				<label class="adc-an-field" for="adc-an-page-type"><?php esc_html_e( 'Tip pagină', 'adc-marketing' ); ?></label>
				<select id="adc-an-page-type" data-adc-an-filter="page_type">
					<?php foreach ( (array) $get( $nav, 'page_types', array() ) as $type ) : ?>
						<option value="<?php echo esc_attr( (string) $type['value'] ); ?>" <?php selected( ! empty( $type['active'] ) ); ?>>
							<?php echo $e( $type['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label class="adc-an-field" for="adc-an-top"><?php esc_html_e( 'Lista', 'adc-marketing' ); ?></label>
				<select id="adc-an-top" data-adc-an-filter="top">
					<?php foreach ( (array) $get( $nav, 'tops', array() ) as $top ) : ?>
						<option value="<?php echo esc_attr( (string) $top['value'] ); ?>" <?php selected( ! empty( $top['active'] ) ); ?>>
							<?php echo $e( $top['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label class="adc-an-field" for="adc-an-from"><?php esc_html_e( 'Data început', 'adc-marketing' ); ?></label>
				<input type="date" id="adc-an-from" data-adc-an-filter="from" value="<?php echo esc_attr( (string) ( $filters['from'] ?? '' ) ); ?>" />

				<label class="adc-an-field" for="adc-an-to"><?php esc_html_e( 'Data sfârșit', 'adc-marketing' ); ?></label>
				<input type="date" id="adc-an-to" data-adc-an-filter="to" value="<?php echo esc_attr( (string) ( $filters['to'] ?? '' ) ); ?>" />

				<label class="adc-an-field" for="adc-an-product"><?php esc_html_e( 'Produs (ID)', 'adc-marketing' ); ?></label>
				<input type="number" id="adc-an-product" class="small-text" min="0" step="1"
					data-adc-an-filter="product" value="<?php echo esc_attr( (string) ( (int) ( $filters['product'] ?? 0 ) ?: '' ) ); ?>" />

				<a class="button button-primary adc-an-apply" id="adc-an-apply" href="<?php echo $u( (string) $get( $nav, 'apply', '' ) ); ?>">
					<?php esc_html_e( 'Aplică filtrele', 'adc-marketing' ); ?>
					<span class="screen-reader-text adc-an-dirty-hint" aria-live="polite"></span>
				</a>
			</div>
			<p class="description"><?php esc_html_e( 'Intervalul personalizat și filtrele de tip pagină, listă sau produs se aplică prin linkul de mai sus (navigare prin URL). Fără JavaScript, folosiți intervalele prestabilite și editați parametrii din URL: range, from, to, currency, channel, page_type, top, product.', 'adc-marketing' ); ?></p>
			<noscript>
				<p class="adc-an-none"><?php esc_html_e( 'JavaScript este dezactivat: filtrele de tip pagină, listă, produs și intervalul personalizat nu pot fi aplicate din formularele de mai sus; folosiți linkurile de interval și parametrii din URL.', 'adc-marketing' ); ?></p>
			</noscript>
		</div>

		<?php if ( 'custom' === (string) ( $filters['range'] ?? '' ) ) : ?>
			<p class="adc-an-active">
				<?php echo $e( sprintf( /* translators: 1: date, 2: date. */ __( 'Interval activ: %1$s → %2$s', 'adc-marketing' ), (string) $range['from'], (string) $range['to'] ) ); ?>
			</p>
		<?php endif; ?>
	</section>

	<?php if ( ! $has_reports ) : ?>
		<div class="adc-an-blocked">
			<p class="adc-an-empty-title"><?php esc_html_e( 'Rapoartele nu sunt disponibile în această instalare', 'adc-marketing' ); ?></p>
			<p><?php esc_html_e( 'Motorul de agregare (ADC_Analytics_Reports) nu este încă încărcat. Setările de mai jos se pot salva; cifrele nu sunt simulate nicăieri în pagină.', 'adc-marketing' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $figures !== array() ) : ?>
		<section class="adc-an-panel adc-an-figures-panel" aria-labelledby="adc-an-figures-h">
			<div class="adc-an-panel-head">
				<h3 id="adc-an-figures-h"><?php esc_html_e( 'Cifre cheie', 'adc-marketing' ); ?></h3>
				<span class="adc-an-prov adc-an-prov--fin"><?php esc_html_e( 'Din comenzi WooCommerce', 'adc-marketing' ); ?></span>
			</div>

			<dl class="adc-an-figures">
				<?php foreach ( $figures as $figure ) : ?>
					<div class="adc-an-figure<?php echo ! empty( $figure['is_money'] ) ? ' is-money' : ''; ?><?php echo ! empty( $figure['tone'] ) ? ' is-' . esc_attr( (string) $figure['tone'] ) : ''; ?>">
						<dt><?php echo $e( $figure['label'] ?? '' ); ?></dt>
						<dd>
							<span class="adc-an-figure-value"><?php echo isset( $figure['display'] ) ? $k( $figure['display'] ) : $e( '—' ); ?></span>
							<span class="adc-an-figure-note"><?php echo $e( $figure['note'] ?? '' ); ?></span>
						</dd>
					</div>
				<?php endforeach; ?>
			</dl>

			<?php if ( ! empty( $compare['available'] ) ) : ?>
				<p class="adc-an-compare">
					<strong><?php esc_html_e( 'Față de intervalul anterior:', 'adc-marketing' ); ?></strong>
					<?php foreach ( (array) $get( $compare, 'rows', array() ) as $delta ) : ?>
						<span class="adc-an-delta adc-an-delta--<?php echo esc_attr( (string) $delta['tone'] ); ?>">
							<?php echo $e( $delta['label'] ); ?> <?php echo $e( $delta['text'] ); ?>
						</span>
					<?php endforeach; ?>
					<span class="adc-an-compare-range"><?php echo $e( (string) $get( $compare, 'range', '' ) ); ?></span>
				</p>
			<?php elseif ( '' !== (string) $get( $compare, 'text', '' ) ) : ?>
				<p class="adc-an-compare is-incomplete"><?php echo $e( (string) $compare['text'] ); ?></p>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<?php
	$traffic_gate = $gate_state( 'traffic' );
	if ( $traffic_on ) :
		?>
		<section class="adc-an-panel" id="traffic" aria-labelledby="traffic-h">
			<div class="adc-an-panel-head">
				<h3 id="traffic-h"><?php esc_html_e( 'Cereri de pagină pe zi', 'adc-marketing' ); ?></h3>
				<span class="adc-an-prov adc-an-prov--est"><?php esc_html_e( 'Estimativ', 'adc-marketing' ); ?></span>
				<?php $render_export( 'traffic', __( 'Export CSV — trafic', 'adc-marketing' ) ); ?>
			</div>
			<?php
			if ( $traffic_chart === array() ) {
				$render_empty( __( 'Fără date de trafic pentru acest interval', 'adc-marketing' ), __( 'Colectarea este activă, dar nu a fost înregistrată nicio celulă de trafic între datele selectate.', 'adc-marketing') );
			} else {
				$render_chart( $traffic_chart, 'adc-an-traffic-chart', __( 'Cereri de pagină estimate pe zi', 'adc-marketing' ) );
			}
			?>
		</section>
	<?php else : ?>
		<section class="adc-an-panel" id="traffic" aria-labelledby="traffic-h">
			<div class="adc-an-panel-head">
				<h3 id="traffic-h"><?php esc_html_e( 'Trafic (estimativ)', 'adc-marketing' ); ?></h3>
				<span class="adc-an-pill"><?php echo $e( $traffic_gate['text'] ); ?></span>
			</div>
			<?php
			$render_empty(
				__( 'Numărătoarea traficului este oprită', 'adc-marketing' ),
				(string) $traffic_gate['reason'],
				'#adc-an-settings-traffic',
				__( 'Vezi secțiunea de trafic', 'adc-marketing' )
			);
			?>
		</section>
	<?php endif; ?>

	<?php if ( $commerce_on && $has_reports ) : ?>
		<section class="adc-an-panel" id="orders" aria-labelledby="orders-h">
			<div class="adc-an-panel-head">
				<h3 id="orders-h"><?php esc_html_e( 'Comenzi și venit pe zi', 'adc-marketing' ); ?></h3>
				<span class="adc-an-prov adc-an-prov--fin"><?php esc_html_e( 'Din comenzi WooCommerce', 'adc-marketing' ); ?></span>
				<?php $render_export( 'orders', __( 'Export CSV — comenzi', 'adc-marketing' ) ); ?>
				<?php $render_export( 'revenue', __( 'Export CSV — venit', 'adc-marketing' ) ); ?>
			</div>
			<?php
			if ( $series === array() ) {
				$render_empty(
					__( 'Fără comenzi înregistrate în acest interval', 'adc-marketing' ),
					__( 'Agregatele financiare sunt goale pentru datele selectate. Comenzile existente în alte monede nu sunt incluse — alegeți moneda corectă de mai sus.', 'adc-marketing' )
				);
			} else {
				$render_chart( $series, 'adc-an-commerce-chart', __( 'Comenzi plătite (bare) și venit brut (linie), pe zi', 'adc-marketing' ) );
			}
			?>
		</section>
	<?php elseif ( $has_reports ) : ?>
		<section class="adc-an-panel" id="orders" aria-labelledby="orders-h">
			<div class="adc-an-panel-head">
				<h3 id="orders-h"><?php esc_html_e( 'Comenzi și venit', 'adc-marketing' ); ?></h3>
				<span class="adc-an-pill"><?php echo $e( $gate_state( 'commerce' )['text'] ); ?></span>
			</div>
			<?php
			$render_empty(
				__( 'Raportarea financiară nu este activă', 'adc-marketing' ),
				(string) $gate_state( 'commerce' )['reason'],
				'#adc-an-settings-commerce',
				__( 'Deschideți setările financiare', 'adc-marketing' )
			);
			?>
		</section>
	<?php endif; ?>

	<?php
	if ( $has_reports ) :
		$cart_gate = $gate_state( 'add_to_cart' );
		$utm_gate  = $gate_state( 'utm' );
		?>
		<?php if ( $traffic_on ) : ?>
			<?php $render_ranking( $channels, 'channels', __( 'Canale de proveniență (orientativ)', 'adc-marketing' ), __( 'Cereri estimate', 'adc-marketing' ), false, 'est', __( 'Export CSV — canale', 'adc-marketing' ) ); ?>
			<?php $render_ranking( $pages, 'pages', __( 'Top pagini', 'adc-marketing' ), __( 'Cereri estimate', 'adc-marketing' ), false, 'est', __( 'Export CSV — pagini', 'adc-marketing' ) ); ?>
		<?php endif; ?>

		<?php if ( $commerce_on ) : ?>
			<?php $render_ranking( $products, 'products', __( 'Top produse', 'adc-marketing' ), __( 'Bucăți', 'adc-marketing' ), true, 'fin', __( 'Export CSV — produse', 'adc-marketing' ) ); ?>
		<?php endif; ?>

		<section class="adc-an-panel" id="interactions" aria-labelledby="interactions-h">
			<div class="adc-an-panel-head">
				<h3 id="interactions-h"><?php esc_html_e( 'Interacțiuni', 'adc-marketing' ); ?></h3>
				<span class="adc-an-pill"><?php echo $e( $cart_gate['text'] ); ?></span>
			</div>
			<p class="adc-an-empty-body"><?php echo $e( $cart_gate['reason'] ); ?></p>
			<p class="adc-an-foot"><?php echo $e( $utm_gate['reason'] ); ?></p>
		</section>

		<?php if ( $statuses !== array() ) : ?>
			<section class="adc-an-panel" id="statuses" aria-labelledby="statuses-h">
				<div class="adc-an-panel-head">
					<h3 id="statuses-h"><?php esc_html_e( 'Comenzi pe stare (instantaneu)', 'adc-marketing' ); ?></h3>
					<p class="adc-an-foot"><?php esc_html_e( 'Numărul curent pe stare se citește direct din comenzi și nu face parte din agregatele pe zile.', 'adc-marketing' ); ?></p>
				</div>
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
			</section>
		<?php endif; ?>

		<?php $recon_rows = (array) $get( $recon, 'rows', array() ); ?>
		<?php if ( $recon_rows !== array() ) : ?>
			<section class="adc-an-panel" id="reconciliation" aria-labelledby="recon-h">
				<div class="adc-an-panel-head">
					<h3 id="recon-h"><?php esc_html_e( 'Recalculare (agregate vs. comenzi)', 'adc-marketing' ); ?></h3>
					<p class="adc-an-foot"><?php esc_html_e( 'Diferențele se raportează, nu se corectează automat.', 'adc-marketing' ); ?></p>
				</div>
				<details class="adc-an-data">
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
			</section>
		<?php elseif ( ! empty( $recon['reason'] ) ) : ?>
			<section class="adc-an-panel" id="reconciliation" aria-labelledby="recon-h">
				<div class="adc-an-panel-head">
					<h3 id="recon-h"><?php esc_html_e( 'Recalculare (agregate vs. comenzi)', 'adc-marketing' ); ?></h3>
					<p class="adc-an-foot"><?php esc_html_e( 'Diferențele se raportează, nu se corectează automat.', 'adc-marketing' ); ?></p>
				</div>
				<p class="adc-an-none"><?php echo $e( (string) $recon['reason'] ); ?></p>
			</section>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $warnings !== array() ) : ?>
		<section class="adc-an-panel adc-an-notes" aria-labelledby="adc-an-notes-h">
			<div class="adc-an-panel-head">
				<h3 id="adc-an-notes-h"><?php esc_html_e( 'Atenționări și precizii', 'adc-marketing' ); ?></h3>
			</div>
			<ul class="adc-an-note-list">
				<?php foreach ( $warnings as $warning ) : ?>
					<li class="adc-an-note adc-an-note--<?php echo esc_attr( (string) ( $warning['tone'] ?? 'info' ) ); ?>">
						<?php echo $e( $warning['text'] ?? '' ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<?php if ( $unavailable !== array() ) : ?>
		<section class="adc-an-panel adc-an-unavailable" aria-labelledby="adc-an-unavailable-h">
			<div class="adc-an-panel-head">
				<h3 id="adc-an-unavailable-h"><?php esc_html_e( 'Ce nu poate determina acest modul', 'adc-marketing' ); ?></h3>
			</div>
			<dl class="adc-an-unavail-list">
				<?php foreach ( $unavailable as $item ) : ?>
					<div class="adc-an-unavail">
						<dt><?php echo $e( $item['label'] ); ?> <span class="adc-an-pill"><?php esc_html_e( 'indisponibil', 'adc-marketing' ); ?></span></dt>
						<dd><?php echo $e( $item['text'] ); ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
		</section>
	<?php endif; ?>

	<hr class="adc-an-rule" />

	<h2 class="adc-an-title" id="adc-an-settings"><?php esc_html_e( 'Setări analiză operațională', 'adc-marketing' ); ?></h2>

	<h3 class="title" id="adc-an-settings-commerce"><?php esc_html_e( '1. Rapoarte financiare din comenzi', 'adc-marketing' ); ?></h3>
	<p class="description"><?php esc_html_e( 'Functionează independent de bannerul de consimțământ: datele provin din comenzile proprii ale magazinului și nu se transmit nicăieri.', 'adc-marketing' ); ?></p>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Activare', 'adc-marketing' ); ?></th>
			<td>
				<label for="adc-an-enabled-commerce">
					<input type="checkbox" id="adc-an-enabled-commerce" name="analytics[enabled_commerce]" value="1"
						<?php checked( ! empty( $settings['enabled_commerce'] ) ); ?> />
					<?php esc_html_e( 'Înregistrez agregate financiare pe zile, din comenzile WooCommerce', 'adc-marketing' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Este nevoie și de confirmarea de mai jos; fără ea, raportarea rămâne oprită.', 'adc-marketing' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Confirmare definiții', 'adc-marketing' ); ?></th>
			<td>
				<label for="adc-an-commerce-ack">
					<input type="checkbox" id="adc-an-commerce-ack" name="analytics[commerce_ack]" value="1"
						<?php checked( ! empty( $settings['commerce_ack'] ) ); ?> />
					<?php esc_html_e( 'Confirm că venit brut = suma valorilor comenzilor plătite, venit net = brut minus rambursări, iar retenția de mai jos corespunde politicii magazinului.', 'adc-marketing' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Stări considerate plătite', 'adc-marketing' ); ?></th>
			<td>
				<fieldset>
					<?php foreach ( (array) $paid['options'] as $slug => $label ) : ?>
						<label class="adc-an-check">
							<input type="checkbox" name="analytics[paid_statuses][]" value="<?php echo esc_attr( (string) $slug ); ?>"
								<?php checked( in_array( (string) $slug, (array) $paid['selected'], true ) ); ?> />
							<?php echo $e( $label ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description">
						<?php esc_html_e( 'O comandă se numără o singură dată la prima tranziție într-una dintre aceste stări. Rambursările nu scad numărul de comenzi plătite.', 'adc-marketing' ); ?>
					</p>
					<?php if ( ! empty( $paid['diverges'] ) ) : ?>
						<p class="adc-an-alert-text">
							<?php esc_html_e( 'Atenție: aceste stări diferă de stările de purchase folosite la evenimentele de marketing; cifrele din cele două module nu vor coincidea.', 'adc-marketing' ); ?>
						</p>
					<?php endif; ?>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="adc-an-retention-commerce"><?php esc_html_e( 'Retenție agregate financiare (zile)', 'adc-marketing' ); ?></label></th>
			<td>
				<input type="number" id="adc-an-retention-commerce" name="analytics[retention_commerce_days]" class="small-text"
					min="90" max="3650" step="1" value="<?php echo esc_attr( (string) ( $settings['retention_commerce_days'] ?? 730 ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Între 90 și 3650 de zile.', 'adc-marketing' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="adc-an-retention-ledger"><?php esc_html_e( 'Retenție registru de deduplicare (zile)', 'adc-marketing' ); ?></label></th>
			<td>
				<input type="number" id="adc-an-retention-ledger" name="analytics[retention_ledger_days]" class="small-text"
					min="30" max="1095" step="1" value="<?php echo esc_attr( (string) ( $settings['retention_ledger_days'] ?? 365 ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Regitrul conține ID-uri interne de comandă pentru a preveni dublarea; nu poate fi reținut mai puțin decât agregatele financiare.', 'adc-marketing' ); ?></p>
			</td>
		</tr>
	</table>

	<h3 class="title" id="adc-an-settings-traffic"><?php esc_html_e( '2. Contorizare trafic fără identificatori', 'adc-marketing' ); ?></h3>
	<p class="adc-an-gate-warning">
		<?php echo $e( $gate_state( 'traffic' )['reason'] ); ?>
	</p>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Activare', 'adc-marketing' ); ?></th>
			<td>
				<label class="adc-an-check is-disabled" for="adc-an-enabled-traffic">
					<input type="checkbox" id="adc-an-enabled-traffic" disabled="disabled" />
					<?php esc_html_e( 'Numărătoare trafic (indisponibilă în această versiune)', 'adc-marketing' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Comutatorul este prezentat doar ca referință de design: nu există o cale de activare. Câmpurile juridice de mai jos se salvează ca notițe interne.', 'adc-marketing' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="adc-an-traffic-basis"><?php esc_html_e( 'Temei juridic propus', 'adc-marketing' ); ?></label></th>
			<td>
				<select id="adc-an-traffic-basis" name="analytics[traffic_legal_basis]">
					<option value="" <?php selected( '' === (string) ( $settings['traffic_legal_basis'] ?? '' ) ); ?>><?php esc_html_e( '— fără temei stabilit —', 'adc-marketing' ); ?></option>
					<option value="lia" <?php selected( 'lia' === (string) ( $settings['traffic_legal_basis'] ?? '' ) ); ?>><?php esc_html_e( 'Interes legitim (evaluare LIA)', 'adc-marketing' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Numai „interes legitim” este acceptat de acest modul; selecția nu activează nimic de una singură.', 'adc-marketing' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="adc-an-traffic-ref"><?php esc_html_e( 'Referință LIA (notiță)', 'adc-marketing' ); ?></label></th>
			<td>
				<input type="text" id="adc-an-traffic-ref" name="analytics[traffic_legal_reference]" class="regular-text"
					value="<?php echo esc_attr( (string) ( $settings['traffic_legal_reference'] ?? '' ) ); ?>"
					placeholder="<?php esc_attr_e( 'ID document / URL / data evaluării', 'adc-marketing' ); ?>" />
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="adc-an-traffic-date"><?php esc_html_e( 'Data aprobării (notiță)', 'adc-marketing' ); ?></label></th>
			<td>
				<input type="date" id="adc-an-traffic-date" name="analytics[traffic_approved_at]"
					value="<?php echo esc_attr( (string) ( $settings['traffic_approved_at'] ?? '' ) ); ?>" />
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="adc-an-traffic-retention"><?php esc_html_e( 'Retenție trafic (zile)', 'adc-marketing' ); ?></label></th>
			<td>
				<input type="number" id="adc-an-traffic-retention" name="analytics[retention_traffic_days]" class="small-text"
					min="30" max="180" step="1" value="<?php echo esc_attr( (string) ( $settings['retention_traffic_days'] ?? 90 ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Se va aplica doar dacă numărătoarea traficului va fi activată vreodată.', 'adc-marketing' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Parametri UTM', 'adc-marketing' ); ?></th>
			<td>
				<label class="adc-an-check is-disabled" for="adc-an-utm">
					<input type="checkbox" id="adc-an-utm" disabled="disabled" />
					<?php esc_html_e( 'Corespundență UTM (indisponibilă)', 'adc-marketing' ); ?>
				</label>
				<p class="description"><?php echo $e( $gate_state( 'utm' )['reason'] ); ?></p>
				<label class="adc-an-field" for="adc-an-utm-ref"><?php esc_html_e( 'Referință juridică UTM (notiță)', 'adc-marketing' ); ?></label>
				<input type="text" id="adc-an-utm-ref" name="analytics[traffic_utm_legal_reference]" class="regular-text"
					value="<?php echo esc_attr( (string) ( $settings['traffic_utm_legal_reference'] ?? '' ) ); ?>" />
				<label class="adc-an-field" for="adc-an-utm-date"><?php esc_html_e( 'Data aprobării UTM (notiță)', 'adc-marketing' ); ?></label>
				<input type="date" id="adc-an-utm-date" name="analytics[traffic_utm_approved_at]"
					value="<?php echo esc_attr( (string) ( $settings['traffic_utm_approved_at'] ?? '' ) ); ?>" />
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Excluderi și filtrare roboți', 'adc-marketing' ); ?></th>
			<td>
				<label class="adc-an-check is-disabled" for="adc-an-exclude-admins">
					<input type="checkbox" id="adc-an-exclude-admins" disabled="disabled" />
					<?php esc_html_e( 'Exclude vizitele administratorilor autentificați (inactiv: dependen de trafic)', 'adc-marketing' ); ?>
				</label>
				<label class="adc-an-check is-disabled" for="adc-an-bot-filter">
					<input type="checkbox" id="adc-an-bot-filter" disabled="disabled" />
					<?php esc_html_e( 'Filtrare roboți conservatoare (inactiv: dependen de trafic)', 'adc-marketing' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="adc-an-min-cell"><?php esc_html_e( 'Prag minim pentru celule (valori)', 'adc-marketing' ); ?></label></th>
			<td>
				<input type="number" id="adc-an-min-cell" name="analytics[min_cell_count]" class="small-text"
					min="3" max="50" step="1" value="<?php echo esc_attr( (string) ( $settings['min_cell_count'] ?? 5 ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Celulele mai mici decât această valoare nu se afișează și nu se exportă; se aplică și listelor de produse, care există independent de trafic.', 'adc-marketing' ); ?></p>
			</td>
		</tr>
	</table>

	<h3 class="title"><?php esc_html_e( '3. Evenimente de interacțiune', 'adc-marketing' ); ?></h3>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Adăugare în coș', 'adc-marketing' ); ?></th>
			<td>
				<label class="adc-an-check is-disabled" for="adc-an-add-to-cart">
					<input type="checkbox" id="adc-an-add-to-cart" disabled="disabled" />
					<?php esc_html_e( 'Numărare evenimente add_to_cart (indisponibilă în această versiune)', 'adc-marketing' ); ?>
				</label>
				<p class="description"><?php echo $e( $gate_state( 'add_to_cart' )['reason'] ); ?></p>
				<label class="adc-an-field" for="adc-an-atc-ref"><?php esc_html_e( 'Referință juridică (notiță)', 'adc-marketing' ); ?></label>
				<input type="text" id="adc-an-atc-ref" name="analytics[add_to_cart_legal_reference]" class="regular-text"
					value="<?php echo esc_attr( (string) ( $settings['add_to_cart_legal_reference'] ?? '' ) ); ?>" />
				<label class="adc-an-field" for="adc-an-atc-date"><?php esc_html_e( 'Data aprobării (notiță)', 'adc-marketing' ); ?></label>
				<input type="date" id="adc-an-atc-date" name="analytics[add_to_cart_approved_at]"
					value="<?php echo esc_attr( (string) ( $settings['add_to_cart_approved_at'] ?? '' ) ); ?>" />
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Începere finalizare', 'adc-marketing' ); ?></th>
			<td>
				<span class="adc-an-pill"><?php echo $e( $gate_state( 'begin_checkout' )['text'] ); ?></span>
				<p class="description"><?php echo $e( $gate_state( 'begin_checkout' )['reason'] ); ?></p>
			</td>
		</tr>
	</table>

	<h3 class="title"><?php esc_html_e( '4. Retenție și dezinstalare', 'adc-marketing' ); ?></h3>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Retenții active', 'adc-marketing' ); ?></th>
			<td>
				<ul class="adc-an-retention">
					<li>
						<?php
						printf(
							/* translators: %d: days. */
							esc_html__( 'Agregate financiare: %d zile', 'adc-marketing' ),
							(int) ( $settings['retention_commerce_days'] ?? 730 )
						);
						?>
					</li>
					<li>
						<?php
						printf(
							/* translators: %d: days. */
							esc_html__( 'Registru de deduplicare: %d zile', 'adc-marketing' ),
							(int) ( $settings['retention_ledger_days'] ?? 365 )
						);
						?>
					</li>
					<li>
						<?php
						printf(
							/* translators: %d: days. */
							esc_html__( 'Trafic (inactiv): %d zile', 'adc-marketing' ),
							(int) ( $settings['retention_traffic_days'] ?? 90 )
						);
						?>
					</li>
				</ul>
				<p class="description"><?php esc_html_e( 'Ștergerea la dezinstalare urmează opțiunea existentă „Șterge datele la dezinstalare” din setările de consimțământ; acest modul nu adaugă un al doilea comutator.', 'adc-marketing' ); ?></p>
			</td>
		</tr>
	</table>

	<details class="adc-an-draft">
		<summary><?php esc_html_e( 'Draft de notificare de confidențialitate (pentru validare juridică)', 'adc-marketing' ); ?></summary>
		<p class="adc-an-draft-body"><?php echo $e( (string) $get( $v, 'notice_draft', '' ) ); ?></p>
		<p class="adc-an-alert-text"><?php esc_html_e( 'Nu publicați acest text ca afirmație de conformitate; trebuie validat de jurid și adaptat magazinului.', 'adc-marketing' ); ?></p>
	</details>

	<?php if ( $exports === array() ) : ?>
		<p class="description adc-an-noexport"><?php esc_html_e( 'Exportul CSV nu este disponibil în această instalare (motorul de export lipsește).', 'adc-marketing' ); ?></p>
	<?php endif; ?>
</div>
