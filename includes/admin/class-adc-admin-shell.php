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
 * Admin shell: menu, two-level tab navigation, save handler, assets.
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
        // Landing tab: operational statistics only (added by the analytics chunk).
        'ADC_Admin_Tab_Dashboard',
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
     * Two-level navigation: nav order => tab slugs owned by the group.
     *
     * Grouping is by slug (the ADC_Admin_Tab_Interface::group() value is kept for
     * backwards compatibility only, it no longer drives the nav). A slug that is
     * not listed here — or a tab class that has not been built yet — is skipped or
     * collected in the "Altele" group, so the nav never renders an empty pill.
     *
     * @var array<string, string[]>
     */
    private const NAV_GROUPS = array(
        'dashboard'    => array('dashboard'),
        'consent'      => array('banner', 'marketing'),
        'measurement'  => array('analytics', 'events', 'matching'),
        'integrations' => array('meta', 'ga4', 'sgtm', 'tiktok'),
        'logs'         => array('logs'),
    );

    /**
     * Catch-all group for tabs that no NAV_GROUPS entry claims.
     */
    private const OTHERS_KEY = 'others';

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
        add_action('admin_head', array(self::class, 'render_menu_icon_styles'));
        add_action('admin_post_adc_save_tab', array(self::class, 'handle_save'));
        add_action('admin_enqueue_scripts', array(self::class, 'enqueue_assets'));
        add_action('in_footer', array(self::class, 'render_help_modal'), 99);
    }

    /**
     * Print the single shared help <dialog> in the admin footer (every tab's
     * info-icon buttons target it; assets/js/admin-help.js fills its content).
     */
    public static function render_help_modal(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (null === $screen || 'toplevel_page_' . self::PAGE_SLUG !== $screen->id) {
            return;
        }

        ADC_Admin_Help::render_shared_dialog();
    }

    /**
     * Register the top-level menu (below WooCommerce, cap manage_options).
     *
     * The icon is the plugin SVG (assets/svg/icon.svg); render_menu_icon_styles()
     * gives it the box WordPress only computes for dashicon fonts.
     */
    public static function register_menu(): void
    {
        add_menu_page(
            __('ADC Cookies & Commerce Hub', 'adc-marketing'),
            __('ADC Cookies & Commerce Hub', 'adc-marketing'),
            'manage_options',
            self::PAGE_SLUG,
            array(self::class, 'render_page'),
            self::asset_url('svg/icon.svg'),
            56
        );
    }

    /**
     * Size the SVG menu icon on every admin screen (static CSS, no interpolation).
     *
     * It cannot live in admin-shell.css because that stylesheet is enqueued on the
     * plugin page only, while the admin menu is rendered on every screen.
     */
    public static function render_menu_icon_styles(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $selector = '#toplevel_page_' . self::PAGE_SLUG . ' .wp-menu-image';
        $css      = $selector . '{float:left;width:36px;height:34px;margin:0;text-align:center;display:flex;align-items:center;justify-content:center;}'
            . $selector . ' img{width:20px;height:auto;padding:0;}';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from class constants, no dynamic data.
        echo '<style id="adc-admin-menu-icon">' . $css . '</style>';
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
            <?php self::render_header(); ?>

            <?php if (empty($tabs)) : ?>
                <div class="notice notice-info"><p>
                    <?php esc_html_e('Niciun tab de administrare nu este disponibil momentan.', 'adc-marketing'); ?>
                </p></div>
            <?php else : ?>
                <?php self::render_nav(self::navigation($tabs), $slug); ?>
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

                    <?php
                    // Read-only tabs opt out with shows_save_button() (the Dashboard has no settings).
                    if (self::shows_save_button($tab)) {
                        ?>
                        <div class="adc-admin-save-bar">
                            <?php submit_button(__('Salvează setările', 'adc-marketing')); ?>
                        </div>
                        <?php
                    }
                    ?>
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

        // Info-icon + modal help component (ADC_Admin_Help) — all tabs depend on it.
        wp_enqueue_style(
            'adc-admin-help',
            self::asset_url('css/admin-help.css'),
            array('adc-admin-shell'),
            $version
        );

        wp_enqueue_script(
            'adc-admin-help',
            self::asset_url('js/admin-help.js'),
            array(),
            $version,
            array(
                'in_footer' => true,
                'strategy'  => 'defer',
            )
        );

        $help_config = ADC_Admin_Help::script_config();
        if ('' !== $help_config) {
            wp_add_inline_script('adc-admin-help', 'window.adcAdminHelp = ' . $help_config . ';', 'before');
        }

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
     * Page header: plugin logo, page title and the installed plugin version.
     */
    private static function render_header(): void
    {
        $version = defined('ADC_MARKETING_VERSION') ? (string) ADC_MARKETING_VERSION : '';
        ?>
        <header class="adc-admin-header">
            <img class="adc-admin-logo" src="<?php echo esc_url(self::asset_url('svg/icon-menu.svg')); ?>"
                 alt="" width="40" height="40">
            <h1 class="adc-admin-title"><?php echo esc_html(get_admin_page_title()); ?></h1>
            <?php if ('' !== $version) : ?>
                <p class="adc-admin-version"><?php echo esc_html($version); ?></p>
            <?php endif; ?>
        </header>
        <?php
    }

    /**
     * Build the navigation groups from the tabs that actually exist.
     *
     * @param array<string, ADC_Admin_Tab_Interface> $tabs All tabs (already ordered).
     * @return array<string, array{tabs: ADC_Admin_Tab_Interface[]}>
     */
    private static function navigation(array $tabs): array
    {
        $groups = array();
        $placed = array();

        foreach (self::NAV_GROUPS as $key => $slugs) {
            $items = array();
            foreach ($slugs as $slug) {
                if (!isset($tabs[$slug])) {
                    continue; // Tab class not built yet — the group simply loses that pill.
                }
                $items[] = $tabs[$slug];
                $placed[$slug] = true;
            }

            if (!empty($items)) {
                $groups[$key] = array('tabs' => $items);
            }
        }

        $others = array_values(array_filter(
            $tabs,
            static fn (ADC_Admin_Tab_Interface $tab): bool => !isset($placed[$tab->slug()])
        ));

        if (!empty($others)) {
            $groups[self::OTHERS_KEY] = array('tabs' => $others);
        }

        return $groups;
    }

    /**
     * Romanian group label (literals keep the translation extractor working).
     *
     * @param string $key Group key from NAV_GROUPS.
     * @return string
     */
    private static function group_label(string $key): string
    {
        return match ($key) {
            'dashboard'    => __('Dashboard', 'adc-marketing'),
            'consent'      => __('Consimțământ', 'adc-marketing'),
            'measurement'  => __('Măsurare', 'adc-marketing'),
            'integrations' => __('Integrări', 'adc-marketing'),
            'logs'         => __('Jurnale & Debug', 'adc-marketing'),
            default        => __('Altele', 'adc-marketing'),
        };
    }

    /**
     * Render the two-level nav: group pills, then the tabs of the active group.
     *
     * @param array<string, array{tabs: ADC_Admin_Tab_Interface[]}> $groups   Navigation groups.
     * @param string                                                 $activeSlug Open tab slug.
     */
    private static function render_nav(array $groups, string $activeSlug): void
    {
        $activeKey = '';
        foreach ($groups as $key => $group) {
            foreach ($group['tabs'] as $group_tab) {
                if ($group_tab->slug() === $activeSlug) {
                    $activeKey = (string) $key;
                    break 2;
                }
            }
        }
        ?>
        <nav class="adc-admin-nav" aria-label="<?php esc_attr_e('Navigare setări ADC Cookies & Commerce Hub', 'adc-marketing'); ?>">
            <ul class="adc-admin-tabs adc-admin-tabs--groups" role="list">
                <?php foreach ($groups as $key => $group) : ?>
                    <?php $entry = $group['tabs'][0]; ?>
                    <li class="adc-admin-group<?php echo $key === $activeKey ? ' adc-admin-group--active' : ''; ?>">
                        <a href="<?php echo esc_url(self::tab_url($entry->slug())); ?>"
                           <?php echo $key === $activeKey ? 'aria-current="true"' : ''; ?>>
                            <?php echo esc_html(self::group_label((string) $key)); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ('' !== $activeKey && count($groups[$activeKey]['tabs']) > 1) : ?>
                <ul class="adc-admin-tabs adc-admin-tabs--sub" role="list"
                    aria-label="<?php echo esc_attr(sprintf(/* translators: %s: navigation group name. */ __('Tab-uri din grupul %s', 'adc-marketing'), self::group_label($activeKey))); ?>">
                    <?php foreach ($groups[$activeKey]['tabs'] as $group_tab) : ?>
                        <li class="adc-admin-tab<?php echo $group_tab->slug() === $activeSlug ? ' adc-admin-tab--active' : ''; ?>">
                            <a href="<?php echo esc_url(self::tab_url($group_tab->slug())); ?>"
                               <?php echo $group_tab->slug() === $activeSlug ? 'aria-current="page"' : ''; ?>>
                                <?php echo esc_html($group_tab->label()); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </nav>
        <?php
    }

    /**
     * URL of one tab (admin.php?page=adc-marketing&tab=<slug>).
     *
     * @param string $slug Tab slug.
     * @return string
     */
    private static function tab_url(string $slug): string
    {
        return add_query_arg(array('tab' => $slug), admin_url('admin.php?page=' . self::PAGE_SLUG));
    }

    /**
     * Whether the shell should print the sticky save bar for one tab.
     *
     * @param ADC_Admin_Tab_Interface $tab Current tab.
     * @return bool
     */
    private static function shows_save_button(ADC_Admin_Tab_Interface $tab): bool
    {
        return !method_exists($tab, 'shows_save_button') || $tab->shows_save_button();
    }

    /**
     * Validate the requested ?tab= against known tabs; fall back to the
     * lowest-order tab (the 'dashboard' statistics tab, order 5).
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
