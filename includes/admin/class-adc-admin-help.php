<?php
/**
 * Reusable "info icon + modal" helper for admin option explanations.
 *
 * ADC_Admin_Help::icon() returns an accessible 20px "i" button plus the long
 * help text. The text travels in two redundant copies:
 * - a hidden <details> block right under the option — the no-JS fallback,
 *   always present in the DOM (assets/css/admin-help.css hides it only when
 *   the browser supports <dialog>, i.e. when the modal works);
 * - a <template> element — assets/js/admin-help.js moves its content into
 *   the single shared <dialog> rendered by ADC_Admin_Shell at click time.
 *
 * All strings are gettext literals in Romanian; no text lives in JS.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static helper: help icon, shared dialog markup, section/option layout.
 */
final class ADC_Admin_Help
{
    /**
     * Whether the shared modal has already been printed on this page.
     *
     * @var bool
     */
    private static bool $dialog_printed = false;

    /**
     * Render an info button + modal content (and its <details> fallback).
     *
     * Output is fully escaped here: $id is sanitized, $title is an html
     * attribute, $body_html goes through wp_kses_post(). Safe inline markup
     * for $body_html: p, strong, em, b, i, ul, ol, li, br, code, a[href].
     *
     * @param string $id       Unique content id on the page (used for aria-controls / data-adc-help).
     * @param string $title    Modal title (plain text, already translated by the caller).
     * @param string $body_html Long explanation HTML (escaped with wp_kses_post).
     * @return string Escaped HTML: <span class="adc-help">button + template + details</span>.
     */
    public static function icon(string $id, string $title, string $body_html): string
    {
        $id = sanitize_key($id);
        if ('' === $id) {
            return '';
        }

        $aria = sprintf(
            /* translators: %s: option or section name the help button describes. */
            __('Mai multe informații despre: %s', 'adc-marketing'),
            $title
        );

        $svg = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false">'
            . '<circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.5"/>'
            . '<circle cx="8" cy="4.7" r="1" fill="currentColor"/>'
            . '<path d="M8 7.1v4.3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>'
            . '</svg>';

        $html = '<span class="adc-help">';
        $html .= '<button type="button" class="adc-help-btn"'
            . ' data-adc-help="' . esc_attr($id) . '"'
            . ' aria-haspopup="dialog"'
            . ' aria-controls="adc-help-dialog"'
            . ' aria-label="' . esc_attr($aria) . '"'
            . ' title="' . esc_attr($aria) . '">' . $svg . '</button>';
        $html .= '<template id="tmpl-adc-help-' . esc_attr($id) . '"'
            . ' data-adc-help-title="' . esc_attr($title) . '">'
            . wp_kses_post($body_html)
            . '</template>';
        // No-JS / no-<dialog> fallback: the same text as a disclosure under the option.
        $html .= '<details class="adc-help-details">'
            . '<summary>' . esc_html__('Mai multe informații', 'adc-marketing') . '</summary>'
            . wp_kses_post($body_html)
            . '</details>';
        $html .= '</span>';

        return $html;
    }

    /**
     * Section heading with a one-line introduction ("Ce face această secțiune").
     *
     * Call inside a tab's render(). The heading + intro travel together in one
     * echo of pre-escaped HTML.
     *
     * @param string      $title Section title (plain text, translated by the caller).
     * @param string      $intro One-sentence introduction (plain text).
     * @param string|null $help_id Optional id for a section-level help icon.
     * @param string|null $help_body Optional long help HTML (with wp_kses_post-safe tags).
     */
    public static function section(string $title, string $intro, ?string $help_id = null, ?string $help_body = null): void
    {
        echo '<div class="adc-admin-section-head"><h2>' . esc_html($title); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
        if (null !== $help_id && null !== $help_body) {
            echo self::icon($help_id, $title, $help_body); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self::icon() escapes its own output.
        }
        echo '</h2><p class="adc-admin-section-intro">' . esc_html($intro) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
    }

    /**
     * One option as a stacked block: label (top) + control (below) + short
     * one-sentence description (muted, below the control).
     *
     * Keeps 16px between options (admin-help.css) and preserves the control's
     * original name=/id= — the caller passes the control HTML untouched.
     *
     * @param string      $label   Option label (plain text, translated by the caller).
     * @param string      $control Control HTML (input/select/textarea markup).
     * @param string      $desc    Short description, one sentence (plain text).
     * @param string|null $for     input id the label points to (null for checkbox groups).
     * @param string|null $help_id Optional id for the "i" modal next to the label.
     * @param string|null $help_body Optional long help HTML.
     */
    public static function row(string $label, string $control, string $desc = '', ?string $for = null, ?string $help_id = null, ?string $help_body = null): void
    {
        echo '<div class="adc-help-row">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static class name.
        echo '<span class="adc-help-row__label">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static class name.
        if ('' !== $label) {
            if ('' !== (string) $for) {
                echo '<label for="' . esc_attr($for) . '">' . esc_html($label) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
            } else {
                echo '<span class="adc-help-row__group-label">' . esc_html($label) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
            }
        }
        if (null !== $help_id && null !== $help_body) {
            echo self::icon($help_id, $label, $help_body); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self::icon() escapes its own output.
        }
        echo '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- closing tag.
        echo '<div class="adc-help-row__control">' . $control . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-supplied, pre-escaped control markup.
        if ('' !== $desc) {
            echo '<p class="adc-help-row__desc">' . esc_html($desc) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
        }
        echo '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- closing tag.
    }

    /**
     * The single shared <dialog> (empty shell; admin-help.js fills it from the
     * clicked option's <template>). Rendered once, in the admin footer.
     *
     * The close button label lives here in PHP gettext — never in JS.
     */
    public static function render_shared_dialog(): void
    {
        self::$dialog_printed = true;
        ?>
        <dialog id="adc-help-dialog" class="adc-help-dialog" aria-labelledby="adc-help-dialog-title">
            <div class="adc-help-dialog__inner">
                <div class="adc-help-dialog__head">
                    <h2 id="adc-help-dialog-title" class="adc-help-dialog__title"></h2>
                    <button type="button" class="adc-help-dialog__dismiss" data-adc-help-close aria-label="<?php esc_attr_e('Închide', 'adc-marketing'); ?>">&times;</button>
                </div>
                <div class="adc-help-dialog__body"></div>
                <p class="adc-help-dialog__actions">
                    <button type="button" class="button" data-adc-help-close><?php esc_html_e('Închide', 'adc-marketing'); ?></button>
                </p>
            </div>
        </dialog>
        <?php
    }

    /**
     * Inline i18n config consumed by assets/js/admin-help.js.
     *
     * @return string JSON, or empty string on encode failure.
     */
    public static function script_config(): string
    {
        $config = array(
            'closeLabel' => __('Închide', 'adc-marketing'),
            'closeAria'  => __('Închide această fereastră', 'adc-marketing'),
        );

        $json = wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return is_string($json) ? $json : '';
    }

    /**
     * Whether render_shared_dialog() already ran (defensive for re-renders).
     */
    public static function dialog_printed(): bool
    {
        return self::$dialog_printed;
    }
}
