<?php
/**
 * The one ADC Marketing admin page + tab router.
 *
 * Menu slug stays `adc-marketing` (admin.php?page=adc-marketing, screen id
 * toplevel_page_adc-marketing). Tabs are collected from the `adc_admin_tabs`
 * filter (array of class names implementing ADC_Admin_Tab_Interface).
 * Save: admin-post.php?action=adc_save_tab&tab=<slug>, nonce action
 * `adc_save_tab_<slug>` (field `_adc_nonce`), notices in a 60 s per-user
 * transient `adc_notices_{user_id}`.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin shell: menu, tab navigation, save handler, assets.
 */
final class ADC_Admin_Shell implements ADC_Module_Interface
{
    /**
     * Admin menu / page slug (URL: admin.php?page=adc-marketing).
     */
    public const PAGE_SLUG = 'adc-marketing';

    /**
     * Notices transient time-to-live (seconds).
     */
    private const NOTICES_TTL = 60;

    /**
     * Known tab classes (chunks create them; class_exists keeps C1 safe alone).
     *
     * @var string[]
     */
    private const DEFAULT_TABS = array(
        'ADC_Admin_Tab_Banner',
        'ADC_Admin_Tab_Events',
        'ADC_Admin_Tab_Meta',
        'ADC_Admin_Tab_Ga4',
        'ADC_Admin_Tab_Sgtm',
        'ADC_Admin_Tab_Tiktok',
        'ADC_Admin_Tab_Matching',
        'ADC_Admin_Tab_Marketing',
        'ADC_Admin_Tab_Logs',
    );

    /**
     * Cached tab instances (slug => instance).
     *
     * @var array<string, ADC_Admin_Tab_Interface>|null
     */
    private static ?array $tabs = null;

    /**
     * Boot the module.
     */
    public static function boot(): void
    {
        add_action('admin_menu', array(self::class, 'register_menu'), 5);
        add_action('admin_post_adc_save_tab', array(self::class, 'handle_save'));
        add_action('admin_enqueue_scripts', array(self::class, 'enqueue_assets'));
    }

    /**
     * Register the top-level menu (below WooCommerce, cap manage_options).
     */
    public static function register_menu(): void
    {
        add_menu_page(
            __('ADC Cookies & Commerce Hub', 'adc-marketing'),
            __('ADC Cookies & Commerce Hub', 'adc-marketing'),
            'manage_options',
            self::PAGE_SLUG,
            array(self::class, 'render_page'),
            'dashicons-email',
            56
        );
    }

    /**
     * Render the whole admin page: nav + notices + form wrapping the active tab.
     */
    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nu aveți permisiunea necesară.', 'adc-marketing'), '', array('response' => 403));
        }

        $tabs  = self::tabs();
        $slug  = self::requested_tab($tabs);
        $tab   = $tabs[$slug] ?? null;
        $notices = self::pull_notices();

        ?>
        <div class="wrap adc-admin-shell">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if (empty($tabs)) : ?>
                <div class="notice notice-info"><p>
                    <?php esc_html_e('Niciun tab de administrare nu este disponibil momentan.', 'adc-marketing'); ?>
                </p></div>
            <?php else : ?>
                <nav class="adc-admin-nav" aria-label="<?php esc_attr_e('Navigare setări ADC Cookies & Commerce Hub', 'adc-marketing'); ?>">
                    <?php self::render_tab_list($tabs, $slug, ''); ?>
                    <?php self::render_tab_list($tabs, $slug, 'integrations'); ?>
                </nav>
            <?php endif; ?>

            <?php foreach ($notices as $notice) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                    <p><?php echo wp_kses_post($notice['message']); ?></p>
                </div>
            <?php endforeach; ?>

            <?php if (null !== $tab) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="adc_save_tab" />
                    <input type="hidden" name="tab" value="<?php echo esc_attr($tab->slug()); ?>" />
                    <?php wp_nonce_field('adc_save_tab_' . $tab->slug(), '_adc_nonce'); ?>

                    <div class="adc-admin-tab-content">
                        <?php $tab->render(); ?>
                    </div>

                    <?php submit_button(__('Salvează setările', 'adc-marketing')); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Save handler for every tab (admin-post.php?action=adc_save_tab).
     */
    public static function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nu aveți permisiunea necesară.', 'adc-marketing'), '', array('response' => 403));
        }

        $post = isset($_POST) && is_array($_POST) ? wp_unslash($_POST) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $slug = isset($post['tab']) ? sanitize_key((string) $post['tab']) : '';

        $nonce = isset($post['_adc_nonce']) ? sanitize_text_field((string) $post['_adc_nonce']) : '';
        if ('' === $slug || !wp_verify_nonce($nonce, 'adc_save_tab_' . $slug)) {
            wp_die(esc_html__('Securitatea formularului nu a putut fi verificată. Reîncărcați pagina și încercați din nou.', 'adc-marketing'), '', array('response' => 403));
        }

        $tabs = self::tabs();
        if (!isset($tabs[$slug])) {
            wp_die(esc_html__('Tab necunoscut.', 'adc-marketing'), '', array('response' => 400));
        }

        $notices = $tabs[$slug]->save($post);
        if (!is_array($notices)) {
            $notices = array();
        }

        self::store_notices($notices);

        wp_safe_redirect(add_query_arg(
            array(
                'page' => self::PAGE_SLUG,
                'tab'  => $slug,
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Enqueue shell assets on the plugin page only.
     *
     * @param string $hook_suffix Current admin page hook.
     */
    public static function enqueue_assets(string $hook_suffix): void
    {
        if ('toplevel_page_' . self::PAGE_SLUG !== $hook_suffix || !current_user_can('manage_options')) {
            return;
        }

        $version = defined('ADC_MARKETING_VERSION') ? (string) ADC_MARKETING_VERSION : null;

        wp_enqueue_style(
            'adc-admin-shell',
            self::asset_url('css/admin-shell.css'),
            array(),
            $version
        );

        wp_enqueue_script(
            'adc-admin-shell',
            self::asset_url('js/admin-shell.js'),
            array(),
            $version,
            array(
                'in_footer' => true,
                'strategy'  => 'defer',
            )
        );
    }

    /**
     * All available tabs, keyed by slug, sorted by order().
     *
     * @return array<string, ADC_Admin_Tab_Interface>
     */
    public static function tabs(): array
    {
        if (null !== self::$tabs) {
            return self::$tabs;
        }

        $classes = apply_filters('adc_admin_tabs', self::DEFAULT_TABS);
        $classes = is_array($classes) ? $classes : self::DEFAULT_TABS;

        $instances = array();
        foreach (array_unique($classes) as $class) {
            if (!is_string($class) || !class_exists($class)) {
                continue; // Tab class not implemented yet (parallel chunks) — skip quietly.
            }
            if (!is_subclass_of($class, ADC_Admin_Tab_Interface::class)) {
                continue;
            }

            $instance = new $class();
            if (!$instance instanceof ADC_Admin_Tab_Interface) {
                continue;
            }

            $slug          = sanitize_key($instance->slug());
            $instances[$slug] = $instance;
        }

        uasort(
            $instances,
            static function (ADC_Admin_Tab_Interface $a, ADC_Admin_Tab_Interface $b): int {
                return [$a->order(), $a->label()] <=> [$b->order(), $b->label()];
            }
        );

        self::$tabs = $instances;

        return self::$tabs;
    }

    /**
     * Render one nav list (top-level or a sub-nav group).
     *
     * @param array<string, ADC_Admin_Tab_Interface> $tabs       All tabs.
     * @param string                                 $activeSlug Currently open tab.
     * @param string                                 $group      '' for top-level, 'integrations' for sub-nav.
     */
    private static function render_tab_list(array $tabs, string $activeSlug, string $group): void
    {
        $items = array_filter(
            $tabs,
            static fn (ADC_Admin_Tab_Interface $tab): bool => $tab->group() === $group
        );

        if (empty($items)) {
            return;
        }

        $class = '' === $group ? 'adc-admin-tabs' : 'adc-admin-tabs adc-admin-tabs--sub';
        ?>
        <ul class="<?php echo esc_attr($class); ?>">
            <?php foreach ($items as $tab_item) : ?>
                <li class="adc-admin-tab<?php echo $tab_item->slug() === $activeSlug ? ' adc-admin-tab--active' : ''; ?>">
                    <a href="<?php echo esc_url(add_query_arg(array('tab' => $tab_item->slug()), admin_url('admin.php?page=' . self::PAGE_SLUG))); ?>"
                       <?php echo $tab_item->slug() === $activeSlug ? 'aria-current="page"' : ''; ?>>
                        <?php echo esc_html($tab_item->label()); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    /**
     * Validate the requested ?tab= against known tabs; fall back to the
     * lowest-order tab (default after upgrade: 'banner', order 10).
     *
     * @param array<string, ADC_Admin_Tab_Interface> $tabs Known tabs.
     * @return string
     */
    private static function requested_tab(array $tabs): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation state.
        $requested = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ('' !== $requested && isset($tabs[$requested])) {
            return $requested;
        }

        $first = reset($tabs);
        return $first instanceof ADC_Admin_Tab_Interface ? $first->slug() : '';
    }

    /**
     * Store tab notices in a 60 s per-user transient.
     *
     * @param array $notices Notices from ADC_Admin_Tab_Interface::save().
     */
    private static function store_notices(array $notices): void
    {
        $clean = array();
        foreach ($notices as $notice) {
            if (!is_array($notice) || !isset($notice['message'])) {
                continue;
            }

            $type = isset($notice['type']) ? (string) $notice['type'] : 'success';
            if (!in_array($type, array('success', 'error', 'warning', 'info'), true)) {
                $type = 'success';
            }

            $clean[] = array(
                'type'    => $type,
                'message' => (string) $notice['message'],
            );
        }

        set_transient('adc_notices_' . get_current_user_id(), $clean, self::NOTICES_TTL);
    }

    /**
     * Fetch and clear pending notices.
     *
     * @return array<int, array{type: string, message: string}>
     */
    private static function pull_notices(): array
    {
        $key      = 'adc_notices_' . get_current_user_id();
        $notices  = get_transient($key);
        delete_transient($key);

        return is_array($notices) ? $notices : array();
    }

    /**
     * Plugin asset URL helper (constant-free for uninstall/CLI contexts).
     *
     * @param string $relative Path relative to the plugin root.
     * @return string
     */
    private static function asset_url(string $relative): string
    {
        if (defined('ADC_MARKETING_PLUGIN_URL')) {
            return rtrim(ADC_MARKETING_PLUGIN_URL, '/') . '/assets/' . $relative;
        }

        return plugin_dir_url(dirname(__DIR__, 2) . '/adc-marketing.php') . 'assets/' . $relative;
    }
}
