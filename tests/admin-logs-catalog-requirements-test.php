<?php
/** Offline test for the Catalog requirements panel in the Logs tab. */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
interface ADC_Admin_Tab_Interface {}
interface ADC_Module_Interface {}
trait ADC_Admin_Help_Content {}
function __(string $text, string $domain = 'default'): string { return $text; }
function esc_html__(string $text, string $domain = 'default'): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_attr(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }

final class ADC_Feed_Module
{
    public static function requirements(): array
    {
        return array(
            array('code' => 'woocommerce_active', 'label' => 'WooCommerce activ', 'ok' => true),
            array('code' => 'xmlreader', 'label' => 'XMLReader disponibil', 'ok' => true),
            array('code' => 'xmlwriter', 'label' => 'XMLWriter <test>', 'ok' => false),
            array('code' => 'storage_writable', 'label' => 'Director scriibil', 'ok' => true),
            array('code' => 'storage_autotest', 'status' => 'storage_guard_executed', 'label' => 'Sondă HTTP informativă', 'ok' => true, 'warning' => true),
        );
    }
}

require dirname(__DIR__) . '/includes/admin/class-adc-admin-tab-logs.php';
ob_start();
ADC_Admin_Tab_Logs::render_catalog_requirements();
$html = (string) ob_get_clean();
$checks = array(
    str_contains($html, 'Cerințe Catalog'),
    str_contains($html, 'WooCommerce activ') && str_contains($html, 'XMLReader disponibil'),
    str_contains($html, 'XMLWriter &lt;test&gt;') && !str_contains($html, 'XMLWriter <test>'),
    str_contains($html, 'Director scriibil') && str_contains($html, 'Sondă HTTP informativă'),
    str_contains($html, 'adc-status--error') && str_contains($html, 'adc-status--warning'),
    str_contains($html, 'Neverificat'),
);
if (in_array(false, $checks, true)) {
    fwrite(STDERR, "FAIL Logs Catalog requirements section\n");
    exit(1);
}
echo "Logs Catalog requirements section passed.\n";
