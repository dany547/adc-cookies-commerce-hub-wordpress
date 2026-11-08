<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-zA-Z0-9_\-]/', '', $value)); }
function wc_get_price_to_display(object $product, array $args): float { return (float) $args['price'] * (float) ($GLOBALS['tax_multiplier'] ?? 1); }
function wc_get_price_decimals(): int { return 2; }
function taxonomy_exists(string $taxonomy): bool { return true; }
function get_term(int $id, string $taxonomy): object|false { return $GLOBALS['catalog_terms'][$taxonomy . ':' . $id] ?? false; }
function get_term_by(string $field, string $value, string $taxonomy): object|false { return $GLOBALS['catalog_term_slugs'][$taxonomy . ':' . $value] ?? false; }
function get_woocommerce_currency(): string { return 'RON'; }
function wc_get_product(int $id): mixed
{
    ++$GLOBALS['catalog_parent_calls'];
    return $GLOBALS['catalog_parents'][$id] ?? null;
}
final class ADC_Settings
{
    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        return 'events' === $group && 'item_id_source' === $key ? 'sku' : $default;
    }
}
class CatalogTestProduct
{
    public static bool $on_sale = false;
    public function get_id(): int { return 43; }
    public function get_parent_id(): int { return 0; }
    public function get_category_ids(): array { return array(8, 9); }
    public function get_status(): string { return 'publish'; }
    public function get_catalog_visibility(): string { return 'visible'; }
    public function is_type(mixed $type): bool { return false; }
    public function get_price(): string { return self::$on_sale ? '20.00' : '25.50'; }
    public function get_name(): string { return '<strong>Ceai & cafea</strong>'; }
    public function get_sku(): string { return str_repeat('SKU', 20); }
    public function get_meta(string $key, bool $single = true): string { return (string) ($GLOBALS['catalog_meta'][$key] ?? ''); }
    public function get_short_description(): string { return '<p>Ceai <em>natural</em> &amp; aromat</p>'; }
    public function get_description(): string { return 'Long description'; }
    public function get_permalink(): string { return 'https://shop.example/product/43'; }
    public function get_image_id(): int { return 0; }
    public function get_gallery_image_ids(): array { return array(); }
    public function get_regular_price(): string { return '25.50'; }
    public function get_sale_price(): string { return '20.00'; }
    public function is_on_sale(): bool { return self::$on_sale; }
    public function get_date_on_sale_from(): null { return null; }
    public function get_date_on_sale_to(): null { return null; }
    public function get_stock_status(): string { return 'instock'; }
    public function is_on_backorder(int $quantity = 1): bool { return false; }
    public function get_weight(): string { return '0.5'; }
    public function get_attributes(): array { return (array) ($GLOBALS['catalog_attributes'] ?? array()); }
    public function get_variation_attributes(): array { return (array) ($GLOBALS['catalog_variation_attributes'] ?? array()); }
}
final class CatalogVariationTestProduct extends CatalogTestProduct
{
    public function __construct(private int $product_id, private int $parent_id) {}
    public function get_id(): int { return $this->product_id; }
    public function get_parent_id(): int { return $this->parent_id; }
}
final class CatalogHiddenParentTestProduct extends CatalogTestProduct
{
    public function get_catalog_visibility(): string { return 'hidden'; }
}
$GLOBALS['catalog_query'] = array();
$GLOBALS['catalog_calls'] = 0;
$GLOBALS['catalog_parent_calls'] = 0;
$GLOBALS['catalog_parents'] = array();
$GLOBALS['catalog_products'] = null;
$GLOBALS['catalog_meta'] = array();
$GLOBALS['catalog_attributes'] = array();
$GLOBALS['catalog_variation_attributes'] = array();
$GLOBALS['catalog_terms'] = array();
$GLOBALS['catalog_term_slugs'] = array();
$GLOBALS['tax_multiplier'] = 1.0;
function wc_get_products(array $query): object
{
    $GLOBALS['catalog_query'] = $query;
    ++$GLOBALS['catalog_calls'];
    $products = is_array($GLOBALS['catalog_products']) ? $GLOBALS['catalog_products'] : array(new CatalogTestProduct());
    return (object) array('products' => $products, 'total' => count($products));
}
require_once __DIR__ . '/../includes/feeds/class-adc-feed-catalog.php';
function catalog_check(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

$items = ADC_Feed_Catalog::items(array('scope' => 'filtered', 'categories' => array('tea'), 'in_stock_only' => true), 0, 100);
catalog_check(count($items) === 1, 'published eligible WooCommerce item is normalized');
catalog_check(strlen($items[0]['feed_item_id']) === 60 && strlen($items[0]['tracking_item_id']) === 60
    && $items[0]['sku_id'] === str_repeat('SKU', 20), 'raw feed ID, tracking ID, and channel-specific TikTok SKU ID are retained for channel validation');
catalog_check($items[0]['title'] === 'Ceai & cafea', 'product title is plain text');
catalog_check($items[0]['description'] === 'Ceai natural & aromat', 'short description is stripped of markup');
catalog_check($items[0]['price'] === 25.5 && $items[0]['currency'] === 'RON', 'price and store currency are normalized');
catalog_check($items[0]['availability'] === 'in_stock', 'WooCommerce stock state is mapped');
catalog_check($GLOBALS['catalog_query']['category'] === array('tea') && $GLOBALS['catalog_query']['stock_status'] === 'instock', 'wizard scope filters are passed to the WooCommerce CRUD query');
catalog_check(ADC_Feed_Catalog::last_examined() === 1 && !ADC_Feed_Catalog::has_more(), 'pagination metadata is retained for chunked generation');

CatalogTestProduct::$on_sale = false;
$GLOBALS['tax_multiplier'] = 1.2;
$regular = ADC_Feed_Catalog::items(array('scope' => 'all', 'categories' => array()), 0, 100)[0];
catalog_check($regular['sale_price'] === null && $regular['price'] === 30.6, 'inactive/scheduled sale is omitted and regular price uses display tax context');
CatalogTestProduct::$on_sale = true;
$active_sale = ADC_Feed_Catalog::items(array('scope' => 'all', 'categories' => array()), 0, 100)[0];
catalog_check($active_sale['price'] === 30.6 && $active_sale['sale_price'] === 24.0, 'active sale uses regular and sale prices in the same display/tax context');
$before_filtered_empty = $GLOBALS['catalog_calls'];
$empty_filtered = ADC_Feed_Catalog::items(array('scope' => 'filtered', 'categories' => array()), 0, 100);
catalog_check($empty_filtered === array() && $GLOBALS['catalog_calls'] === $before_filtered_empty, 'filtered scope without categories never queries all products');
$GLOBALS['catalog_meta'] = array();
$default_category = ADC_Feed_Catalog::items(array('scope' => 'all', 'default_category' => '2271'), 0, 100)[0];
catalog_check($default_category['google_product_category'] === '2271', 'default category is used without artificial product metadata');
$mapped_category = ADC_Feed_Catalog::items(array('scope' => 'all', 'category_map' => array('8' => '1234', '9' => '5678'), 'default_category' => '2271'), 0, 100)[0];
catalog_check($mapped_category['google_product_category'] === '1234', 'category map is used before default category in product term order');
$GLOBALS['catalog_meta'] = array('_adc_feed_google_product_category' => '9876');
$meta_category = ADC_Feed_Catalog::items(array('scope' => 'all', 'category_map' => array('8' => '1234'), 'default_category' => '2271'), 0, 100)[0];
catalog_check($meta_category['google_product_category'] === '9876', 'product metadata category takes precedence over configured mappings');
$parent = new CatalogTestProduct();
$GLOBALS['catalog_parents'][70] = $parent;
$GLOBALS['catalog_parent_calls'] = 0;
ADC_Feed_Catalog::reset_parent_cache();
$GLOBALS['catalog_products'] = array(new CatalogVariationTestProduct(71, 70));
$first_variation = ADC_Feed_Catalog::items(array('scope' => 'all'), 0, 100);
$GLOBALS['catalog_products'] = array(new CatalogVariationTestProduct(72, 70));
$second_variation = ADC_Feed_Catalog::items(array('scope' => 'all'), 1, 100);
catalog_check(count($first_variation) === 1 && count($second_variation) === 1 && $GLOBALS['catalog_parent_calls'] === 1,
    'variation parent is fetched once across catalog pages within a generation run');
$GLOBALS['catalog_products'] = array(new CatalogVariationTestProduct(73, 70));
ADC_Feed_Catalog::reset_parent_cache();
$GLOBALS['catalog_parents'][70] = new CatalogHiddenParentTestProduct();
$GLOBALS['catalog_parent_calls'] = 0;
$hidden_parent_items = ADC_Feed_Catalog::items(array('scope' => 'all'), 0, 100);
catalog_check($hidden_parent_items === array() && $GLOBALS['catalog_parent_calls'] === 1,
    'memoized hidden parent still excludes its variations');
$GLOBALS['catalog_parents'] = array();
$GLOBALS['catalog_parent_calls'] = 0;
ADC_Feed_Catalog::reset_parent_cache();
$GLOBALS['catalog_meta'] = array(
    '_adc_feed_certification_required' => 'yes',
    '_adc_feed_unit_pricing_required' => 'yes',
);
$conditional = ADC_Feed_Catalog::items(array('scope' => 'all', 'categories' => array()), 0, 100)[0];
require_once __DIR__ . '/../includes/feeds/class-adc-feed-profile-google.php';
$conditional_warnings = ADC_Feed_Profile_Google::diagnostics($conditional);
catalog_check(in_array('certification_required_but_missing', $conditional_warnings, true)
    && in_array('unit_pricing_required_but_missing', $conditional_warnings, true), 'conditional EU product requirements produce diagnostics without making unrelated items invalid');
$GLOBALS['catalog_terms']['pa_color:41'] = (object) array('name' => 'Verde');
$GLOBALS['catalog_term_slugs']['pa_color:albastru'] = (object) array('name' => 'Albastru');
$GLOBALS['catalog_attributes'] = array('pa_color' => new class {
    public function get_name(): string { return 'pa_color'; }
    public function get_options(): array { return array(41); }
});
$GLOBALS['catalog_variation_attributes'] = array('attribute_pa_color' => 'albastru');
$term_attributes = ADC_Feed_Catalog::items(array('scope' => 'all'), 0, 100)[0]['attributes'];
catalog_check($term_attributes['pa_color'] === 'Albastru', 'taxonomy attribute slugs resolve to term names rather than exposing term IDs or slugs');
$GLOBALS['catalog_attributes'] = array();
$GLOBALS['catalog_variation_attributes'] = array();
$GLOBALS['catalog_meta'] = array(
    '_adc_feed_certification_required' => 'yes',
    '_adc_feed_eprel_code' => 'EC:EPREL:123456',
    '_adc_feed_unit_measure' => '500 ml',
    '_adc_feed_unit_base_measure' => '1 l',
    '_adc_feed_unit_pricing_required' => 'yes',
);
$european = ADC_Feed_Catalog::items(array('scope' => 'all', 'categories' => array()), 0, 100)[0];
$mapped_european = ADC_Feed_Profile_Google::map_item($european);
catalog_check(($mapped_european['certification']['certification_authority'] ?? '') === 'EC'
    && ($mapped_european['certification']['certification_name'] ?? '') === 'EPREL'
    && ($mapped_european['certification']['certification_code'] ?? '') === 'EC:EPREL:123456'
    && ($mapped_european['unit_pricing_measure'] ?? '') === '500 ml'
    && ($mapped_european['unit_pricing_base_measure'] ?? '') === '1 l'
    && ADC_Feed_Profile_Google::diagnostics($european) === array(), 'EPREL and unit-price meta map to Google fields only for products carrying those values');

echo "feed-catalog-test: PASS\n";
