<?php
/**
 * Cookie-policy shortcode and policy-page presence check.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Renders the known cookie inventory for a site's cookie policy page. */
final class ADC_Cookie_Policy implements ADC_Module_Interface
{
    /** Register the public cookie-policy shortcode. */
    public static function boot(): void
    {
        add_shortcode('adc_cookie_table', array(self::class, 'shortcode'));
    }

    /**
     * Render the cookie table shortcode.
     *
     * @param array<string, string> $attributes Shortcode attributes.
     * @return string
     */
    public static function shortcode(array $attributes = array()): string
    {
        $attributes = shortcode_atts(array('category' => '', 'show_empty' => 'no'), $attributes, 'adc_cookie_table');
        $inventory = ADC_Consent_Banner::inventory();
        $category = sanitize_key((string) $attributes['category']);
        if ('' !== $category) {
            $inventory = array_values(array_filter($inventory, static fn (array $row): bool => (string) ($row['category'] ?? '') === $category));
        }

        $labels = (array) ADC_Settings::get('banner', 'category_labels', array());
        $html = self::render_html($inventory, $labels);
        if ('' !== $html || 'yes' !== strtolower((string) $attributes['show_empty'])) {
            return $html;
        }

        return '<p class="adc-cookie-table-empty">' . esc_html__('Nu există cookie-uri înregistrate.', 'adc-marketing') . '</p>';
    }

    /**
     * Render grouped cookie inventory markup.
     *
     * @param array<int, array{name?: string, category?: string, provider?: string, purpose?: string, duration?: string, wildcard?: bool}> $inventory Known cookies.
     * @param array<string, string> $labels Category labels keyed by slug.
     * @return string
     */
    public static function render_html(array $inventory, array $labels): string
    {
        $categories = array();
        foreach ($inventory as $row) {
            $category = (string) ($row['category'] ?? '');
            $categories[$category][] = $row;
        }
        if (!$categories) {
            return '';
        }

        $groups = array();
        foreach (ADC_Consent_Banner::CATEGORIES as $slug) {
            if (!empty($categories[$slug])) {
                $groups[] = array(
                    'label' => (string) ($labels[$slug] ?? $slug),
                    'rows' => $categories[$slug],
                );
                unset($categories[$slug]);
            }
        }
        foreach ($categories as $slug => $rows) {
            $groups[] = array('label' => (string) ($labels[$slug] ?? $slug), 'rows' => $rows);
        }

        $intro = __('Mai jos găsiți cookie-urile cunoscute, grupate pe categorii.', 'adc-marketing');
        return ADC_Template::render('consent/cookie-policy-table.php', array('groups' => $groups, 'intro' => $intro), false);
    }

    /**
     * Report whether the configured cookie-policy page includes this shortcode.
     *
     * @return array{state: 'no_page'|'missing'|'present', page_id: int, edit_url: string}
     */
    public static function page_has_table(): array
    {
        $page_id = absint(ADC_Settings::get('banner', 'cookie_page_id', 0));
        if (!$page_id || 'publish' !== get_post_status($page_id)) {
            return array('state' => 'no_page', 'page_id' => $page_id, 'edit_url' => '');
        }
        $content = (string) get_post_field('post_content', $page_id);
        $present = has_shortcode($content, 'adc_cookie_table');
        return array(
            'state' => $present ? 'present' : 'missing',
            'page_id' => $page_id,
            'edit_url' => (string) get_edit_post_link($page_id, 'raw'),
        );
    }
}
