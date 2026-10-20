<?php
/**
 * Commerce pipeline integration checks (disposable WordPress + WooCommerce only).
 *
 * Run: wp --path=<disposable-wp> eval-file tests/analytics/integration-commerce.php
 *
 * Exercises the real WC CRUD path: create order -> pay -> refund, then asserts
 * ledger dedupe, aggregate correctness, multi-currency separation, the absence
 * of order ids in aggregates and a zero-diff reconciliation.
 *
 * Backend dependence: ADC_Analytics_Store::lock_day() uses MySQL named locks
 * (GET_LOCK). On non-MySQL stacks (e.g. SQLite) the rebuild sub-check is SKIPPED
 * with an explicit notice; the production locking is never weakened for that.
 */

declare(strict_types=1);

if (!defined('ABSPATH') || !class_exists('ADC_Analytics_Store') || !class_exists('WC_Order')) {
    throw new RuntimeException('Load WordPress, WooCommerce and the ADC Marketing plugin first.');
}

$failures = array();
$check    = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
        echo "FAIL: $message\n";
    }
};
$skip = static function (string $message): void {
    echo "SKIP: $message\n";
};

// Isolated settings state: commerce on, everything else fail-closed.
$previous = get_option('adc_settings_analytics', array());
ADC_Analytics_Schema::install();
update_option('adc_settings_analytics', array_merge(is_array($previous) ? $previous : array(), array(
    'enabled_commerce' => true,
    'commerce_ack'     => true,
    'paid_statuses'    => array('processing', 'completed'),
)));

try {
    // 1. The closed gates stay hard-false even with stored toggles present, and
    //    the traffic gate needs its confirmation as well as the switch.
    update_option('adc_settings_analytics', array_merge((array) get_option('adc_settings_analytics', array()), array(
        'enabled_traffic'     => true,
        'enabled_add_to_cart' => true,
    )));
    $check(!ADC_Analytics_Settings::traffic_enabled(), 'traffic gate closed without traffic_ack in WP runtime');
    update_option('adc_settings_analytics', array_merge((array) get_option('adc_settings_analytics', array()), array(
        'enabled_traffic' => true,
        'traffic_ack'     => true,
    )));
    $check(ADC_Analytics_Settings::traffic_enabled(), 'traffic gate opens with switch + ack in WP runtime');
    update_option('adc_settings_analytics', array_merge((array) get_option('adc_settings_analytics', array()), array(
        'enabled_traffic' => false,
        'traffic_ack'     => false,
    )));
    $check(!ADC_Analytics_Settings::add_to_cart_enabled(), 'add_to_cart gate hard false in WP runtime');
    $check(!ADC_Analytics_Settings::begin_checkout_enabled(), 'begin_checkout gate hard false in WP runtime');
    $check(!ADC_Analytics_Settings::utm_enabled(), 'utm gate hard false in WP runtime');
    update_option('adc_settings_analytics', array_merge((array) get_option('adc_settings_analytics', array()), array(
        'enabled_commerce' => true,
        'commerce_ack'     => true,
    )));

    // 2. Real order through WC CRUD (HPOS-agnostic): created -> paid -> partial refund.
    $order = wc_create_order(array('currency' => 'RON'));
    if (is_wp_error($order) || !$order instanceof WC_Order) {
        throw new RuntimeException('wc_create_order failed; cannot run commerce integration.');
    }
    $order->update_meta_data('_adc_integration_marker', 'adc-analytics'); // order meta usable, never used by analytics
    $order->save();
    $order->set_total(123.456789);
    $order->set_status('processing');
    $order->set_date_paid(time());
    $order->save();
    $order_id = $order->get_id();

    // Both official paths fire; the ledger must keep exactly one paid event.
    ADC_Analytics_Commerce::on_payment_complete($order_id);
    ADC_Analytics_Commerce::on_status_changed($order_id, 'pending', 'processing');
    ADC_Analytics_Commerce::process('order_created', $order_id);
    ADC_Analytics_Commerce::process('order_paid', $order_id);
    ADC_Analytics_Commerce::process('order_paid', $order_id); // replay

    $today     = (string) wp_date('Y-m-d');
    $table     = ADC_Analytics_Schema::table(ADC_Analytics_Schema::COMMERCE);
    $ledger    = ADC_Analytics_Schema::table(ADC_Analytics_Schema::LEDGER);
    global $wpdb;

    $paid_rows = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE stat_date=%s AND currency=%s AND order_status=%s AND metric=%s",
        $today, 'RON', '', 'orders_paid'
    ));
    $check(1 === $paid_rows, "exactly one orders_paid bucket row for {$today}/RON (got {$paid_rows})");

    $paid_ledger = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$ledger} WHERE order_id=%d AND event_key=%s",
        $order_id, 'order_paid'
    ));
    $check(1 === $paid_ledger, 'exactly one order_paid ledger row');

    // 3. Refund through WC CRUD API, processed once + replay.
    $refund = wc_create_refund(array(
        'order_id'   => $order_id,
        'amount'     => 20.25,
        'line_items' => array(),
    ));
    if (!$refund instanceof WC_Order_Refund) {
        throw new RuntimeException('wc_create_refund failed.');
    }
    ADC_Analytics_Commerce::on_refunded($order_id, $refund->get_id());
    ADC_Analytics_Commerce::process('refund:' . $refund->get_id(), $order_id, $refund->get_id());
    ADC_Analytics_Commerce::process('refund:' . $refund->get_id(), $order_id, $refund->get_id());

    $refund_amount = (float) $wpdb->get_var($wpdb->prepare(
        "SELECT amount FROM {$table} WHERE stat_date=%s AND currency=%s AND metric=%s",
        $today, 'RON', 'refund_total'
    ));
    $check(abs($refund_amount - 20.25) < 0.000001, 'refund_total = 20.25 once (got ' . $refund_amount . ')');

    // 4. Second currency lands in its own bucket (never summed).
    $order_eur = wc_create_order(array('currency' => 'EUR'));
    if ($order_eur instanceof WC_Order) {
        $order_eur->set_status('processing');
        $order_eur->set_date_paid(time());
        $order_eur->set_total(99.5);
        $order_eur->save();
        ADC_Analytics_Commerce::process('order_paid', $order_eur->get_id());
        $eur = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT amount FROM {$table} WHERE stat_date=%s AND currency=%s AND metric=%s",
            $today, 'EUR', 'order_total'
        ));
        $check(abs($eur - 99.5) < 0.000001, 'EUR bucket separate from RON (got ' . $eur . ')');
    }

    // 5. No order_id column may exist in the aggregate tables.
    foreach (array(ADC_Analytics_Schema::COMMERCE, ADC_Analytics_Schema::PRODUCTS) as $key) {
        $aggregate  = ADC_Analytics_Schema::table($key);
        $has_column = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $aggregate, '%order%'));
        $check(null === $has_column, "no order_id-like column in {$key}");
    }

    // 6. Reconciliation: recomputed values must match aggregates within 0.01.
    // Day-lock probe: GET_LOCK is MySQL-only; on other stacks we SKIP (never weaken prod locks).
    $lock_supported = true;
    try {
        $lock_supported = ('1' === (string) $wpdb->get_var("SELECT GET_LOCK('adc_analytics_integration_probe', 0)"));
        if ($lock_supported) {
            $wpdb->query("SELECT RELEASE_LOCK('adc_analytics_integration_probe')");
        }
    } catch (Throwable) {
        $lock_supported = false;
    }

    $diff = ADC_Analytics_Reconciler::compare_day($today, 'RON');
    $max  = 0.0;
    foreach ($diff as $metric => $value) {
        if (!in_array($metric, array('orders_created'), true)) { // created count may include unrelated test orders
            $max = max($max, abs((float) $value));
        }
    }
    $check($max <= 0.01, 'compare_day diff within 0.01 (got ' . $max . ')');

    if (!$lock_supported) {
        $skip('GET_LOCK unavailable (non-MySQL, e.g. SQLite): rebuild_day atomic-replace path NOT validated here; run on MySQL.');
    } else {
        $report = ADC_Analytics_Reconciler::rebuild_day($today);
        $check(true === (bool) $report['ok'], 'rebuild_day ok (reason: ' . $report['reason'] . ')');
        $paid_after = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT amount FROM {$table} WHERE stat_date=%s AND currency=%s AND metric=%s",
            $today, 'RON', 'order_total'
        ));
        $check(abs($paid_after - 123.456789) < 0.000001, 'rebuild preserved gross (got ' . $paid_after . ')');
        $refund_after = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT amount FROM {$table} WHERE stat_date=%s AND currency=%s AND metric=%s",
            $today, 'RON', 'refund_total'
        ));
        $check(abs($refund_after - 20.25) < 0.000001, 'rebuild preserved refund_total (got ' . $refund_after . ')');
    }

    // 7. Retention contract: ledger never shorter than commerce.
    $check(
        ADC_Analytics_Settings::retention_days('ledger') >= ADC_Analytics_Settings::retention_days('commerce'),
        'ledger retention >= commerce retention'
    );
} finally {
    // Restore prior settings; leave the disposable install otherwise intact.
    update_option('adc_settings_analytics', $previous);
}

if ($failures !== array()) {
    throw new RuntimeException('integration-commerce: ' . count($failures) . ' failure(s).');
}
echo "integration-commerce: PASS\n";
