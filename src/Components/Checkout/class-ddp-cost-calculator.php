<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Checkout;

use Packlink\BusinessLogic\DDP\DdpBehavior;
use Packlink\BusinessLogic\DDP\Models\DdpCostResponse;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;

/**
 * Class Ddp_Cost_Calculator
 *
 * Turns a core DdpCostResponse into the duty amount charged to the shopper. The core keeps the duty
 * components separate and leaves the merchant adjustment unapplied on purpose, so composing the
 * presented figure belongs to the module.
 *
 * Split in two on purpose. `composed_base()` sums the route's duty once, which is what the checkout
 * caches; `charged_amount()` applies one method's own adjustment to that base every time an amount is
 * wanted. Keeping the adjustment out of the cached figure is what lets a merchant's edit take effect
 * on the next render instead of when the quote expires.
 *
 * Deliberately free of any WooCommerce or WordPress dependency: it is pure arithmetic, unit testable
 * in isolation, and it keeps the rules it encodes reversible in one place.
 *
 * @package Packlink\WooCommerce\Components\Checkout
 */
class Ddp_Cost_Calculator {

	/**
	 * Sums the duty components that apply on this route into the raw base every method is priced from.
	 *
	 * Only components flagged as enabled contribute. When no component is enabled the route carries no
	 * duty for this service, and that is not a zero duty: a 0.00 amount is indistinguishable from
	 * "no duty on this route", and presenting it would put a second, identically priced option in front
	 * of the shopper. Callers therefore read null as "no DDP option here" and must never substitute 0.0.
	 *
	 * Deliberately unadjusted and unrounded. Duty is a function of the goods and the route rather than
	 * the carrier service, so this one base answers for every DDP-capable method of the cart and is what
	 * gets cached; each method's own adjustment is applied afterwards, on read, by charged_amount().
	 *
	 * The base is only money if the response is priced in the currency the cart charges in, which
	 * `foreign_currency()` answers separately - the caller gates on it once per lookup rather than once
	 * per method, so the merchant gets one actionable log line instead of one per shipping service.
	 *
	 * @param DdpCostResponse $response Core duty cost response for the quoted service.
	 *
	 * @return float|null Raw duty base, or null when the response carries no enabled component.
	 */
	public static function composed_base( DdpCostResponse $response ) {
		$base          = 0.0;
		$has_component = false;

		if ( null !== $response->ddpFee && $response->ddpFee->isEnabled ) {
			$base         += (float) $response->ddpFee->totalPrice;
			$has_component = true;
		}

		if ( null !== $response->customsAndDuties && $response->customsAndDuties->isEnabled ) {
			$base         += (float) $response->customsAndDuties->totalPrice;
			$has_component = true;
		}

		return $has_component ? $base : null;
	}

	/**
	 * Applies one method's merchant adjustment to the raw base, giving the amount charged to the shopper.
	 *
	 * The adjustment is read from the shipping method, never from the response, whose own adjustment
	 * fields describe only the queried service (WC-DDP-18). It is read here, at the moment the amount is
	 * wanted, rather than when the base was quoted: a merchant editing an adjustment then changes the
	 * next render, with no cached quote to invalidate.
	 *
	 * The result is floored at zero and rounded exactly once, here. Downstream surfaces — the option
	 * row, the cart fee, the order meta — reuse this value and never recompute or re-round it (INV-3).
	 *
	 * An amount of exactly 0.00 is a real, quoted duty the merchant chose to absorb with an adjustment,
	 * not a missing one: callers offer it like any other amount. Only a null base means "no duty here".
	 *
	 * @param float          $base   Raw duty base from composed_base().
	 * @param ShippingMethod $method Shipping method carrying the merchant's adjustment configuration.
	 *
	 * @return float Charged duty amount.
	 */
	public static function charged_amount( $base, ShippingMethod $method ) {
		$amount = self::apply_adjustment(
			(float) $base,
			$method->getDdpAdjustmentType(),
			(float) $method->getDdpAdjustmentAmount()
		);

		// An adjustment may legitimately be negative, but a negative duty amount would reduce the
		// transport price of the option it is added to.
		return round( max( 0.0, $amount ), 2 );
	}

	/**
	 * The currency an enabled component was quoted in when that is not the currency the cart charges.
	 *
	 * The core hands the component amounts over unconverted, so a duty quoted in another currency is
	 * numerically wrong money the moment it is added to a total - and the order would then record the
	 * shop's currency against a figure that was never in it. Better no duties option than a silently
	 * mispriced one, so callers refuse the whole quote rather than convert a rate the module does not
	 * have.
	 *
	 * An empty currency on either side is not a mismatch: there is nothing to compare, and refusing
	 * would drop duties for a response that simply did not carry the field.
	 *
	 * @param DdpCostResponse $response          Core duty cost response.
	 * @param string          $expected_currency ISO code the cart charges in; empty when unresolvable.
	 *
	 * @return string|null Offending currency code, or null when every enabled component is usable.
	 */
	public static function foreign_currency( DdpCostResponse $response, $expected_currency ) {
		$expected = strtoupper( (string) $expected_currency );
		if ( '' === $expected ) {
			return null;
		}

		foreach ( array( $response->ddpFee, $response->customsAndDuties ) as $component ) {
			if ( null === $component || ! $component->isEnabled ) {
				continue;
			}

			$currency = strtoupper( (string) $component->currency );
			if ( '' !== $currency && $currency !== $expected ) {
				return $currency;
			}
		}

		return null;
	}

	/**
	 * Applies the merchant's signed adjustment to the composed amount.
	 *
	 * @param float       $amount Composed duty amount before adjustment.
	 * @param string|null $type   Adjustment type, one of DdpBehavior::ADJUSTMENT_*.
	 * @param float       $value  Signed adjustment value.
	 *
	 * @return float Adjusted amount, not yet floored or rounded.
	 */
	private static function apply_adjustment( $amount, $type, $value ) {
		if ( 0.0 === $value ) {
			return $amount;
		}

		if ( DdpBehavior::ADJUSTMENT_PERCENTAGE === $type ) {
			return $amount + ( $amount * $value / 100 );
		}

		if ( DdpBehavior::ADJUSTMENT_FIXED === $type ) {
			return $amount + $value;
		}

		return $amount;
	}
}
