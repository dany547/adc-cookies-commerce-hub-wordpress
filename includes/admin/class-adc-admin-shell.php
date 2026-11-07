<?php
/**
 * The one Adcelerum Commerce Hub admin page + tab router.
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
        'ADC_Admin_Tab_Cookies',
        // Unified Google Tag Manager tab (web container + optional server).
        'ADC_Admin_Tab_Gtm',
        'ADC_Admin_Tab_Events',
        'ADC_Admin_Tab_Meta',
        'ADC_Admin_Tab_Ga4',
        // 'ADC_Admin_Tab_Sgtm' merged into ADC_Admin_Tab_Gtm; the class stays
        // booted as a legacy delegate (test-button wiring + delegation).
        'ADC_Admin_Tab_Tiktok',
        // Read-only overview matrix of the integrations (Meta / GA4 / TikTok).
        'ADC_Admin_Tab_Platforms',
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
        'consent'      => array('banner', 'cookies', 'marketing'),
        'measurement'  => array('analytics', 'gtm', 'events', 'matching'),
        'integrations' => array('platforms', 'meta', 'ga4', 'tiktok'),
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
        add_filter('parent_file', array(self::class, 'highlight_parent'));
        add_filter('submenu_file', array(self::class, 'highlight_submenu'));
        add_action('admin_head', array(self::class, 'render_menu_icon_styles'));
        add_action('admin_post_adc_save_tab', array(self::class, 'handle_save'));
        add_action('admin_enqueue_scripts', array(self::class, 'enqueue_assets'));
        add_action('in_footer', array(self::class, 'render_help_modal'), 99);
        ADC_Admin_Promo::boot();
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
     * Register the top-level menu (below WooCommerce, cap manage_options) and
     * one submenu per navigation group.
     *
     * The first submenu reuses the page slug, so WordPress does not add its
     * automatic duplicate entry and get_admin_page_title() returns the plugin
     * name on every tab. The other submenus are plain links to the first tab of
     * their group (admin.php?page=adc-marketing&tab=<slug>, no callback, so no
     * extra page is registered); highlight_submenu() marks the right one.
     *
     * The icon is the plugin SVG (assets/svg/icon.svg); render_menu_icon_styles()
     * gives it the box WordPress only computes for dashicon fonts.
     */
    public static function register_menu(): void
    {
        $title = self::plugin_title();

        add_menu_page(
            $title,
            $title,
            'manage_options',
            self::PAGE_SLUG,
            array(self::class, 'render_page'),
            self::asset_url('svg/icon.svg'),
            56
        );

        foreach (self::menu_entries() as $entry) {
            add_submenu_page(
                self::PAGE_SLUG,
                $title,
                $entry['label'],
                'manage_options',
                $entry['slug'],
                self::PAGE_SLUG === $entry['slug'] ? array(self::class, 'render_page') : ''
            );
        }
    }

    /**
     * Plugin display name used in the WP menu, the page title and the header.
     *
     * @return string
     */
    public static function plugin_title(): string
    {
        return __('Adcelerum Commerce Hub', 'adc-marketing');
    }

    /**
     * Left-menu submenu entries, one per navigation group, in nav order.
     *
     * The group that owns the landing tab (the first one) links to the page slug
     * itself; every other group links to its first available tab.
     *
     * @return array<int, array{key: string, label: string, slug: string, tabs: string[]}>
     */
    public static function menu_entries(): array
    {
        $entries = array();
        $first   = true;

        foreach (self::navigation(self::tabs()) as $key => $group) {
            $slugs = array_map(
                static fn (ADC_Admin_Tab_Interface $tab): string => $tab->slug(),
                $group['tabs']
            );

            $entries[] = array(
                'key'   => (string) $key,
                'label' => self::group_label((string) $key),
                'slug'  => $first ? self::PAGE_SLUG : self::submenu_slug($slugs[0]),
                'tabs'  => $slugs,
            );
            $first = false;
        }

        return $entries;
    }

    /**
     * Submenu slug (= link) for a group whose first tab is $tab_slug.
     *
     * @param string $tab_slug First tab of the group.
     * @return string
     */
    private static function submenu_slug(string $tab_slug): string
    {
        return 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $tab_slug;
    }

    /**
     * Keep the plugin menu open on every tab (`parent_file` filter).
     *
     * @param mixed $parent_file Current parent file.
     * @return mixed
     */
    public static function highlight_parent($parent_file)
    {
        return self::is_plugin_page() ? self::PAGE_SLUG : $parent_file;
    }

    /**
     * Mark the submenu of the open tab's group as current (`submenu_file` filter).
     *
     * @param mixed $submenu_file Current submenu file.
     * @return mixed
     */
    public static function highlight_submenu($submenu_file)
    {
        if (!self::is_plugin_page()) {
            return $submenu_file;
        }

        return self::submenu_for_tab(self::requested_tab(self::tabs()));
    }

    /**
     * Submenu slug that owns one tab (falls back to the page slug).
     *
     * @param string $tab_slug Tab slug.
     * @return string
     */
    public static function submenu_for_tab(string $tab_slug): string
    {
        foreach (self::menu_entries() as $entry) {
            if (in_array($tab_slug, $entry['tabs'], true)) {
                return $entry['slug'];
            }
        }

        return self::PAGE_SLUG;
    }

    /**
     * Whether the current admin request is the plugin page.
     *
     * @return bool
     */
    private static function is_plugin_page(): bool
    {
        global $plugin_page;

        return is_string($plugin_page) && self::PAGE_SLUG === $plugin_page;
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
            <div class="adc-admin-shell-inner">
                <?php self::render_header(); ?>
                <?php ADC_Admin_Promo::render(); ?>

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
            </div>

            <?php if (null !== $tab) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="adc_save_tab" />
                    <input type="hidden" name="tab" value="<?php echo esc_attr($tab->slug()); ?>" />
                    <?php wp_nonce_field('adc_save_tab_' . $tab->slug(), '_adc_nonce'); ?>

                    <div class="adc-admin-shell-inner">
                        <div class="adc-admin-tab-content">
                            <?php self::render_page_head($tab); ?>
                            <?php $tab->render(); ?>
                        </div>
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

        // Unified GTM tab (3-step schema + two settings cards).
        wp_enqueue_style(
            'adc-admin-gtm',
            self::asset_url('css/admin-gtm.css'),
            array('adc-admin-shell'),
            $version
        );

        // Platforms overview matrix (read-only).
        wp_enqueue_style(
            'adc-admin-platforms',
            self::asset_url('css/admin-platforms.css'),
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
     * Slugs of the registered tabs, in display order (sorted by order(), label).
     *
     * Consumers (e.g. the Dashboard status panel) can link to configuration
     * tabs without hard-coding slugs: the list always includes whatever the
     * `adc_admin_tabs` filter registered, so it stays correct when tabs are
     * added, merged or removed (e.g. `gtm` replaced `sgtm` as the unified GTM
     * tab slug).
     *
     * @return array<int, string>
     */
    public static function registered_tab_slugs(): array
    {
        return array_values(array_keys(self::tabs()));
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
            <img class="adc-admin-logo" src="<?php echo esc_url(self::asset_url('svg/adcelerum-commerce-hub-dark.svg')); ?>"
                 alt="<?php echo esc_attr__('Adcelerum Commerce Hub', 'adc-marketing'); ?>" width="163" height="40">
            <h1 class="adc-admin-title"><?php echo esc_html(get_admin_page_title()); ?></h1>
            <?php if ('' !== $version) : ?>
                <p class="adc-admin-version"><?php echo esc_html($version); ?></p>
            <?php endif; ?>
        </header>
        <?php
    }

    /**
     * Tab page head: title (the tab label), optional active/inactive status and
     * one direct sentence saying what the page is for.
     *
     * A tab can expose `page_status(): ?bool` (null = no status shown) and
     * `page_status_labels(): array{0: string, 1: string}` (on, off).
     *
     * @param ADC_Admin_Tab_Interface $tab Current tab.
     */
    public static function render_page_head(ADC_Admin_Tab_Interface $tab): void
    {
        $status = method_exists($tab, 'page_status') ? $tab->page_status() : null;
        $labels = method_exists($tab, 'page_status_labels') ? (array) $tab->page_status_labels() : array();
        $intro  = self::page_intro($tab->slug());
        ?>
        <div class="adc-page-head">
            <h2 class="adc-page-head__title">
                <?php echo esc_html($tab->label()); ?>
                <?php
                if (is_bool($status)) {
                    echo ADC_Admin_Help::status($status, $labels[0] ?? null, $labels[1] ?? null); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- status() escapes.
                }
                ?>
            </h2>
            <?php if ('' !== $intro) : ?>
                <p class="adc-page-head__intro"><?php echo esc_html($intro); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * One-sentence page intro per tab slug (literals keep the extractor working).
     *
     * @param string $slug Tab slug.
     * @return string
     */
    public static function page_intro(string $slug): string
    {
        return match ($slug) {
            'dashboard' => __('Cifrele magazinului și starea colectării datelor, pe intervalul ales.', 'adc-marketing'),
            'banner'    => __('Ce vede vizitatorul la prima vizită: textele bannerului, aspectul și categoriile de cookie-uri.', 'adc-marketing'),
            'cookies'   => __('Găsește cookie-urile folosite pe site, apoi confirmă categoria și scopul fiecăruia.', 'adc-marketing'),
            'marketing' => __('Textul bifei de marketing din checkout, exportul contactelor și verificarea condițiilor GDPR.', 'adc-marketing'),
            'analytics' => __('Ce date colectează modulul de analiză, cât timp le păstrează și ce rămâne blocat.', 'adc-marketing'),
            'gtm'       => __('Containerul web Google Tag Manager și, opțional, containerul server, configurate din același loc.', 'adc-marketing'),
            'events'    => __('Alege ce acțiuni ale vizitatorilor se măsoară și cum se calculează datele trimise platformelor.', 'adc-marketing'),
            'matching'  => __('Trimite platformelor e-mailul și telefonul clienților doar ca hash, ca să atribuie mai multe comenzi campaniilor.', 'adc-marketing'),
            'platforms' => __('Starea fiecărei platforme dintr-o privire: ce se încarcă în browser, ce pleacă de pe server și ce trebuie verificat.', 'adc-marketing'),
            'meta'      => __('Trimite către Meta (Facebook și Instagram) evenimentele magazinului, pentru optimizarea reclamelor și publicuri.', 'adc-marketing'),
            'ga4'       => __('Trimite evenimentele de pe server către Google Analytics 4, mai fiabil decât o etichetă din browser.', 'adc-marketing'),
            'tiktok'    => __('Trimite către TikTok evenimentele magazinului, pentru optimizarea reclamelor și publicuri.', 'adc-marketing'),
            'logs'      => __('Ce a trimis pluginul către platforme, ce a eșuat și ce consimțământ a stat la baza fiecărei trimiteri.', 'adc-marketing'),
            default     => '',
        };
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
            'dashboard'    => __('Prezentare generală', 'adc-marketing'),
            'consent'      => __('Consimțământ', 'adc-marketing'),
            'measurement'  => __('Măsurare', 'adc-marketing'),
            'integrations' => __('Platforme', 'adc-marketing'),
            'logs'         => __('Jurnale', 'adc-marketing'),
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
        <nav class="adc-admin-nav" aria-label="<?php esc_attr_e('Navigare Adcelerum Commerce Hub', 'adc-marketing'); ?>">
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
