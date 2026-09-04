<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Checkout;

use Packlink\WooCommerce\Components\ShippingMethod\Packlink_Shipping_Method;

/**
 * Class Ddp_Checkout
 *
 * The single vocabulary for Delivery Duty Paid (DDP) at checkout: the shipping-rate id suffix that
 * marks the DDP variant of a Packlink rate, and the order-meta keys under which the customer's DDP
 * choice and the amount charged for it are persisted. Every call site uses these members instead of
 * spelling the strings out, so no two of them can disagree.
 *
 * A Packlink rate id is built by `WC_Shipping_Method::get_rate_id()` and has the shape
 * `packlink_shipping_method:<instance_id>`. The DDP variant is `get_rate_id( self::RATE_SUFFIX )`,
 * which appends a third segment: `packlink_shipping_method:<instance_id>:ddp`. The instance id
 * therefore stays in segment `[1]` in both forms, which is what every parser already shipped in this
 * plugin reads - so a DDP rate resolves to the same Packlink shipping method as its base rate.
 *
 * @package Packlink\WooCommerce\Components\Checkout
 */
class Ddp_Checkout {

	/**
	 * Rate-id suffix that marks the DDP variant of a Packlink shipping rate.
	 */
	const RATE_SUFFIX = 'ddp';
	/**
	 * Shipping-rate meta key holding the duty amount quoted for that rate.
	 *
	 * The amount travels on the rate itself because WooCommerce caches calculated rates in the session
	 * and serves later renders from that cache without calling `calculate_shipping()`. Anything that
	 * needs the amount afterwards - the cart fee above all - would otherwise find nothing and silently
	 * charge no duty on a rate the shopper chose precisely because it included duty.
	 */
	const RATE_META_AMOUNT = 'packlink_ddp_amount';

	/**
	 * Rate meta key holding Packlink's own carrier price for the service behind a duties-paid rate.
	 *
	 * Carried on the rate because the draft is built in a later request that cannot ask Packlink for it
	 * again - `porterage` appears only inside a products response. From here it reaches the order as
	 * META_PORTERAGE, which is what the draft declares as the shipment's transport cost.
	 */
	const RATE_META_PORTERAGE = 'packlink_ddp_porterage';

	/**
	 * Order meta key holding whether the customer chose the DDP variant.
	 */
	const META_SELECTED = '_packlink_ddp_selected';
	/**
	 * Order meta key holding the DDP amount charged to the customer.
	 */
	const META_COST = '_packlink_ddp_cost';
	/**
	 * Order meta key holding the currency of the charged DDP amount.
	 */
	const META_CURRENCY = '_packlink_ddp_currency';

	/**
	 * Order meta key holding Packlink's own carrier price for the chosen service.
	 *
	 * The freight the shipment draft declares. The order's shipping total is the SHOPPER-facing carrier
	 * price - porterage plus Packlink's platform fee, plus any pricing-policy markup - and only
	 * porterage is carrier freight, so declaring the total over-states the customs value. Absent on
	 * orders placed before this was recorded, and the draft then falls back to the shipping total.
	 */
	const META_PORTERAGE = '_packlink_ddp_porterage';

	/**
	 * Checks whether the given rate id is the DDP variant of a Packlink shipping rate. A base
	 * Packlink rate and any rate of another shipping method are not DDP rates.
	 *
	 * @param string $rate_id WooCommerce shipping rate id.
	 *
	 * @return bool Whether the rate id is a Packlink DDP rate id.
	 */
	public static function is_ddp_rate_id( $rate_id ) {
		$parts = explode( ':', (string) $rate_id );

		return count( $parts ) > 2
			&& Packlink_Shipping_Method::PACKLINK_SHIPPING_METHOD === $parts[0]
			&& self::RATE_SUFFIX === $parts[2];
	}

	/**
	 * Returns the DDP variant of the given Packlink rate id. Idempotent: a rate id that already
	 * carries the suffix is returned unchanged.
	 *
	 * @param string $rate_id WooCommerce shipping rate id.
	 *
	 * @return string DDP rate id.
	 */
	public static function ddp_rate_id( $rate_id ) {
		$rate_id = (string) $rate_id;

		if ( self::is_ddp_rate_id( $rate_id ) ) {
			return $rate_id;
		}

		return $rate_id . ':' . self::RATE_SUFFIX;
	}

	/**
	 * Strips the DDP suffix, returning the base rate id of the same Packlink shipping-method
	 * instance. Idempotent: a rate id that does not carry the suffix is returned unchanged.
	 *
	 * @param string $rate_id WooCommerce shipping rate id.
	 *
	 * @return string Base rate id.
	 */
	public static function base_rate_id( $rate_id ) {
		$rate_id = (string) $rate_id;

		if ( ! self::is_ddp_rate_id( $rate_id ) ) {
			return $rate_id;
		}

		$parts = explode( ':', $rate_id );

		return $parts[0] . ':' . $parts[1];
	}

	/**
	 * Returns the WooCommerce shipping-method instance id carried by the rate id. Reads segment
	 * `[1]`, which makes it suffix-agnostic: the base rate id and its DDP variant yield the same
	 * instance id, and therefore the same Packlink shipping method.
	 *
	 * Mirrors the parsing in `Checkout_Handler::get_rate_data()` and
	 * `Surcharge_Handler::get_shipping_method_id()`. Returns `0` - never a valid instance id - when
	 * the rate id carries no instance segment.
	 *
	 * @param string $rate_id WooCommerce shipping rate id.
	 *
	 * @return int Shipping method instance id, or 0 when the rate id carries none.
	 */
	public static function instance_id( $rate_id ) {
		$parts = explode( ':', (string) $rate_id );

		return isset( $parts[1] ) ? (int) $parts[1] : 0;
	}
}
