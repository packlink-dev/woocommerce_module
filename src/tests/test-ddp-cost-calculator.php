<?php
/**
 * Tests the composition of the duty amount presented to the shopper (WC-T1). The core deliberately
 * keeps the two duty components separate and leaves the merchant adjustment unapplied, so composing
 * the single charged figure is the module's job. Two rules are locked down here: only enabled
 * components contribute, and the merchant adjustment is applied on top before the result is floored
 * at zero and rounded exactly once (INV-3).
 *
 * @package Packlink_Pro_Shipping
 */

use Packlink\BusinessLogic\DDP\DdpBehavior;
use Packlink\BusinessLogic\DDP\Models\DdpCostResponse;
use Packlink\BusinessLogic\Http\DTO\DDP\DdpProductCost;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\WooCommerce\Components\Checkout\Ddp_Cost_Calculator;

/**
 * Class DdpCostCalculatorTest
 *
 * @package Packlink_Pro_Shipping
 */
class DdpCostCalculatorTest extends WP_UnitTestCase {

	/**
	 * Both components enabled compose to their sum, and the merchant adjustment moves that sum
	 * without ever pushing the charged amount below zero.
	 */
	public function test_composes_both_components_and_floors_at_zero() {
		$response = $this->get_response( 5.76, 18.75 );

		$this->assertEquals(
			24.51,
			Ddp_Cost_Calculator::charged_amount( $response, $this->get_method( null, 0.0 ) ),
			'Both enabled components should compose to their sum when there is no adjustment.',
			0.0001
		);

		$this->assertEquals(
			29.51,
			Ddp_Cost_Calculator::charged_amount(
				$response,
				$this->get_method( DdpBehavior::ADJUSTMENT_FIXED, 5.00 )
			),
			'A fixed adjustment should be added to the composed sum.',
			0.0001
		);

		$this->assertEquals(
			26.96,
			Ddp_Cost_Calculator::charged_amount(
				$response,
				$this->get_method( DdpBehavior::ADJUSTMENT_PERCENTAGE, 10 )
			),
			'A percentage adjustment should be taken off the composed sum and rounded to two decimals.',
			0.0001
		);

		$this->assertEquals(
			0.00,
			Ddp_Cost_Calculator::charged_amount(
				$response,
				$this->get_method( DdpBehavior::ADJUSTMENT_FIXED, -30.00 )
			),
			'A negative adjustment larger than the composed sum should floor the amount at zero.',
			0.0001
		);
	}

	/**
	 * INV-3: the charged amount is composed once and stays byte-identical on every later read, so the
	 * figure shown on the option row, the cart fee and the order meta can never drift apart.
	 */
	public function test_charged_amount_is_not_recomputed_at_render() {
		// 10.00 + 0.01 at +33.33% is a repeating decimal before rounding, the case where an
		// unrounded intermediate would surface as a different value on a second read.
		$response = $this->get_response( 10.00, 0.01 );
		$method   = $this->get_method( DdpBehavior::ADJUSTMENT_PERCENTAGE, 33.33 );

		$first  = Ddp_Cost_Calculator::charged_amount( $response, $method );
		$second = Ddp_Cost_Calculator::charged_amount( $response, $method );
		$third  = Ddp_Cost_Calculator::charged_amount( $response, $method );

		$this->assertSame( $first, $second, 'The composed amount must not change between reads.' );
		$this->assertSame( $second, $third, 'The composed amount must not change between reads.' );
		$this->assertSame(
			round( $first, 2 ),
			$first,
			'The composed amount must already be rounded to two decimals, never rounded again downstream.'
		);
	}

	/**
	 * A response carrying only one of the two components still composes: the missing component is
	 * simply absent from the sum rather than aborting the composition.
	 */
	public function test_null_components_are_treated_as_zero() {
		$response                    = new DdpCostResponse();
		$response->serviceId         = 20339;
		$response->effectiveBehavior = DdpBehavior::OPTIONAL;
		$response->ddpFee            = $this->get_component( 5.76, true );
		$response->customsAndDuties  = null;

		$this->assertEquals(
			5.76,
			Ddp_Cost_Calculator::charged_amount( $response, $this->get_method( null, 0.0 ) ),
			'A response with only the DDP fee set should compose to that fee.',
			0.0001
		);
	}

	/**
	 * No enabled component means the route carries no duty for this service. That is not a zero duty:
	 * a 0.00 amount is indistinguishable from "no duty here" and would put a second, identically
	 * priced option in front of the shopper, so the calculator returns null instead.
	 */
	public function test_all_components_disabled_return_null() {
		$response                    = new DdpCostResponse();
		$response->serviceId         = 20339;
		$response->effectiveBehavior = DdpBehavior::OPTIONAL;
		$response->ddpFee            = $this->get_component( 5.76, false );
		$response->customsAndDuties  = $this->get_component( 18.75, false );

		$this->assertNull(
			Ddp_Cost_Calculator::charged_amount( $response, $this->get_method( null, 0.0 ) ),
			'A response with no enabled component must compose to null, never to 0.0.'
		);
	}

	/**
	 * Builds a response with both components enabled.
	 *
	 * @param float $ddp_fee Total price of the DDP fee component.
	 * @param float $customs Total price of the customs and duties component.
	 *
	 * @return DdpCostResponse
	 */
	private function get_response( $ddp_fee, $customs ) {
		$response                    = new DdpCostResponse();
		$response->serviceId         = 20339;
		$response->effectiveBehavior = DdpBehavior::OPTIONAL;
		$response->ddpFee            = $this->get_component( $ddp_fee, true );
		$response->customsAndDuties  = $this->get_component( $customs, true );

		// Deliberately set on the response as well: the calculator must read the adjustment off the
		// shipping method, because the response describes only the queried service (WC-DDP-18).
		$response->ddpAdjustmentType   = DdpBehavior::ADJUSTMENT_FIXED;
		$response->ddpAdjustmentAmount = 999.0;

		return $response;
	}

	/**
	 * Builds a single duty component.
	 *
	 * @param float $total_price Total price of the component.
	 * @param bool  $is_enabled  Whether the component applies on this route.
	 *
	 * @return DdpProductCost
	 */
	private function get_component( $total_price, $is_enabled ) {
		return DdpProductCost::fromArray(
			array(
				'total_price' => $total_price,
				'base_price'  => $total_price,
				'tax_price'   => 0,
				'currency'    => 'EUR',
				'is_enabled'  => $is_enabled,
			)
		);
	}

	/**
	 * Builds a shipping method carrying the merchant's DDP adjustment configuration.
	 *
	 * @param string|null $type   Adjustment type, one of DdpBehavior::ADJUSTMENT_*.
	 * @param float       $amount Signed adjustment value.
	 *
	 * @return ShippingMethod
	 */
	private function get_method( $type, $amount ) {
		$method = new ShippingMethod();
		$method->setDdpBehavior( DdpBehavior::OPTIONAL );
		$method->setDdpAdjustmentType( $type );
		$method->setDdpAdjustmentAmount( $amount );

		return $method;
	}
}
