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
 * Turns a core DdpCostResponse into the single duty amount charged to the shopper. The core keeps the
 * duty components separate and leaves the merchant adjustment unapplied on purpose, so composing the
 * presented figure belongs to the module.
 *
 * Deliberately free of any WooCommerce or WordPress dependency: it is pure arithmetic, unit testable
 * in isolation, and it keeps the two rules it encodes reversible in one place.
 *
 * @package Packlink\WooCommerce\Components\Checkout
 */
class Ddp_Cost_Calculator {

	/**
	 * Composes the duty amount charged to the shopper for one shipping method.
	 *
	 * Only components flagged as enabled contribute. When no component is enabled the route carries no
	 * duty for this service, and that is not a zero duty: a 0.00 amount is indistinguishable from
	 * "no duty on this route", and presenting it would put a second, identically priced option in front
	 * of the shopper. Callers therefore read null as "no DDP option here" and must never substitute 0.0.
	 *
	 * The merchant adjustment is read from the shipping method, never from the response, whose own
	 * adjustment fields describe only the queried service (WC-DDP-18).
	 *
	 * The result is floored at zero and rounded exactly once, here. Downstream surfaces — the option
	 * row, the cart fee, the order meta — reuse this value and never recompute or re-round it (INV-3).
	 *
	 * @param DdpCostResponse $response Core duty cost response for the method's service.
	 * @param ShippingMethod  $method   Shipping method carrying the merchant's adjustment configuration.
	 *
	 * @return float|null Charged duty amount, or null when the response carries no enabled component.
	 */
	public static function charged_amount( DdpCostResponse $response, ShippingMethod $method ) {
		$amount        = 0.0;
		$has_component = false;

		if ( null !== $response->ddpFee && $response->ddpFee->isEnabled ) {
			$amount       += (float) $response->ddpFee->totalPrice;
			$has_component = true;
		}

		if ( null !== $response->customsAndDuties && $response->customsAndDuties->isEnabled ) {
			$amount       += (float) $response->customsAndDuties->totalPrice;
			$has_component = true;
		}

		if ( ! $has_component ) {
			return null;
		}

		$amount = self::apply_adjustment(
			$amount,
			$method->getDdpAdjustmentType(),
			(float) $method->getDdpAdjustmentAmount()
		);

		// An adjustment may legitimately be negative, but a negative duty amount would reduce the
		// transport price of the option it is added to.
		return round( max( 0.0, $amount ), 2 );
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
