<?php
/**
 * PSR-4-ish autoloader for all ADC_* symbols.
 *
 * Rule (docs/ARCHITECTURE.md §1):
 * - class ADC_Foo_Bar        -> class-adc-foo-bar.php
 * - interface ADC_Foo_Bar_Interface -> interface-adc-foo-bar.php
 *
 * Files are searched in: includes/, includes/core/, includes/consent/,
 * includes/tracking/, includes/integrations/, includes/admin/, includes/analytics/.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Autoloader for the ADC Marketing plugin.
 */
final class ADC_Autoloader
{
    /**
     * Whether the autoloader has been registered already.
     *
     * @var bool
     */
    private static bool $registered = false;

    /**
     * Plugin root directory (works even when called from uninstall.php).
     *
     * @var string|null
     */
    private static ?string $root = null;

    /**
     * Register the autoloader (idempotent).
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;
        spl_autoload_register(array(self::class, 'load'));
    }

    /**
     * Resolve a class/interface name to a plugin file and load it.
     *
     * @param string $class Class name being autoloaded (e.g. ADC_Admin_Tab_Banner).
     */
    public static function load(string $class): void
    {
        if (!preg_match('/^ADC_/i', $class)) {
            return;
        }

        $snake = strtolower(substr($class, 4)); // strip the ADC_ prefix.
        if ('' === $snake) {
            return;
        }

        if (str_ends_with($snake, '_interface')) {
            // ADC_Foo_Bar_Interface -> interface-adc-foo-bar.php
            $file = 'interface-adc-' . str_replace('_', '-', substr($snake, 0, -strlen('_interface')));
        } else {
            // ADC_Foo_Bar -> class-adc-foo-bar.php (the 'adc' comes from the ADC_ prefix)
            $file = 'class-adc-' . str_replace('_', '-', $snake);
        }

        foreach (self::search_dirs() as $dir) {
            $path = $dir . '/' . $file . '.php';
            if (is_readable($path)) {
                require_once $path;
                return;
            }
        }
    }

    /**
     * Directories searched for class files.
     *
     * @return string[]
     */
    private static function search_dirs(): array
    {
        if (null === self::$root) {
            self::$root = defined('ADC_MARKETING_PLUGIN_DIR')
                ? rtrim(ADC_MARKETING_PLUGIN_DIR, '/')
                : dirname(__DIR__, 2); // includes/core -> plugin root.
        }

        return array(
            self::$root . '/includes',
            self::$root . '/includes/core',
            self::$root . '/includes/consent',
            self::$root . '/includes/tracking',
            self::$root . '/includes/integrations',
            self::$root . '/includes/admin',
            self::$root . '/includes/analytics',
        );
    }
}
