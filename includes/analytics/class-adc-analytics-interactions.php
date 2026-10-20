<?php
/**
 * Interaction counting (add_to_cart / begin_checkout) — legally gated OFF.
 *
 * Coordinator decision (Art. 21 GDPR objection analysis + ePrivacy review,
 * docs/privacy/operational-analytics-review.md): interaction events are NOT
 * exempt from consent merely because they are collected server-side. The gates
 * in ADC_Analytics_Settings are hard `false` — no option, constant or filter
 * can open them in this release — so every method below is inert by code and
 * cannot write a row even if called directly. Commerce reporting works
 * completely independently of this class.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Inert interaction collector: hook registration only, writes impossible.
 */
final class ADC_Analytics_Interactions implements ADC_Module_Interface
{
	/**
	 * Register the classic add-to-cart hook. The handler checks the closed
	 * gate first, so the registration costs nothing and writes nothing.
	 */
	public static function boot(): void
	{
		add_action('woocommerce_add_to_cart', array(self::class, 'on_add_to_cart'), 20, 6);
	}

	/**
	 * Classic add-to-cart counter. Inert: `add_to_cart_enabled()` is hard false
	 * for this release, so this never reaches the store and never links a
	 * product to a source, session or order.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $product_id    Product id.
	 * @param int|float $quantity   Quantity added (WC stock amount).
	 * @param int    $variation_id  Variation id.
	 * @param array  $variation     Variation attributes.
	 * @param array  $cart_item_data Other cart item data.
	 */
	public static function on_add_to_cart(string $cart_item_key, int $product_id, int|float $quantity, int $variation_id = 0, array $variation = array(), array $cart_item_data = array()): void
	{
		unset($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data);

		if (!ADC_Analytics_Settings::add_to_cart_enabled()) {
			return; // Hard false in this release: no count, no write, ever.
		}

		// Unreachable in this release. If a future legal review opens the gate,
		// the write is a per-day count only (no product/cart/session linkage):
		ADC_Analytics_Store::increment_interaction(
			(string) wp_date('Y-m-d'),
			'add_to_cart',
			1
		);
	}

	/**
	 * Store API / Blocks add-to-cart hook. NOT registered in this release:
	 * the exact Store API source signature must be verified against the
	 * installed WC version first (contract §11.3). Present so the extension
	 * point is explicit; the closed gate keeps it inert even if hooked.
	 *
	 * @param mixed  $request       Store API request object.
	 * @param string $cart_item_key Cart item key.
	 */
	public static function on_store_api_add_to_cart(mixed $request, string $cart_item_key): void
	{
		unset($request, $cart_item_key);

		if (!ADC_Analytics_Settings::add_to_cart_enabled()) {
			return; // Hard false in this release.
		}

		ADC_Analytics_Store::increment_interaction((string) wp_date('Y-m-d'), 'add_to_cart', 1);
	}

	/**
	 * begin_checkout cannot be derived reliably from a server-side order hook;
	 * always false until a verified hook exists AND legal approval is granted.
	 */
	public static function begin_checkout_supported(): bool
	{
		return false;
	}
}
