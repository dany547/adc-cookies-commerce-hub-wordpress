<?php
/**
 * Overridable template renderer.
 *
 * Theme override path: {theme}/adc-marketing/{name}.php
 * (child theme first, then parent theme, then plugin templates/).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders plugin templates with theme-override support.
 */
final class ADC_Template
{
    /**
     * Render a template.
     *
     * @param string $name  Template path relative to adc-marketing/, e.g. 'consent/banner.php'.
     * @param array  $args  Variables extracted into the template scope.
     * @param bool   $echo  Print the output (true) or only return it (false).
     * @return string Rendered HTML ('' when no template file exists).
     */
    public static function render(string $name, array $args = array(), bool $echo = true): string
    {
        $file = self::locate($name);
        if ('' === $file) {
            return '';
        }

        $args = array_filter(
            $args,
            static fn ($key): bool => !in_array((string) $key, array('args', 'file', 'html'), true),
            ARRAY_FILTER_USE_KEY
        );
        extract($args, EXTR_SKIP); // phpcs:ignore WordPress.PHP.DontExtract

        ob_start();
        include $file;
        $html = (string) ob_get_clean();

        if ($echo) {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template renders/escapes its own output.
        }

        return $html;
    }

    /**
     * Resolve a template file: child theme, parent theme, then plugin.
     *
     * @param string $name Template path relative to adc-marketing/.
     * @return string Absolute path or '' when not found.
     */
    private static function locate(string $name): string
    {
        $relative = 'adc-marketing/' . ltrim($name, '/');

        $candidates = array(
            get_stylesheet_directory() . '/' . $relative,
            get_template_directory() . '/' . $relative,
        );

        $plugin_root = defined('ADC_MARKETING_PLUGIN_DIR')
            ? rtrim(ADC_MARKETING_PLUGIN_DIR, '/')
            : dirname(__DIR__, 2);

        $candidates[] = $plugin_root . '/templates/' . ltrim($name, '/');

        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}
