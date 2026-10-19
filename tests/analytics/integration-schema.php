<?php
/** Run only inside a disposable WordPress installation with the plugin loaded. */
declare(strict_types=1);

if (!defined('ABSPATH') || !class_exists('ADC_Analytics_Schema') || !class_exists('ADC_Analytics_Store')) {
    throw new RuntimeException('Load WordPress and the ADC Marketing plugin first.');
}
ADC_Analytics_Schema::install();
global $wpdb;
foreach (ADC_Analytics_Schema::tables() as $key) {
    $table = ADC_Analytics_Schema::table($key);
    $row = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $table), ARRAY_A);
    if (!$row || strtoupper((string) $row['Engine']) !== 'INNODB') { throw new RuntimeException('Analytics table missing or not InnoDB: ' . $key); }
}
if ((string) get_option(ADC_Analytics_Schema::OPTION_DB_VERSION) !== ADC_Analytics_Schema::DB_VERSION) { throw new RuntimeException('Schema version mismatch.'); }
if (!ADC_Analytics_Store::transaction(static fn(): bool => false)) { throw new RuntimeException('Rollback-on-false contract failed.'); }
echo "integration-schema: PASS\n";
