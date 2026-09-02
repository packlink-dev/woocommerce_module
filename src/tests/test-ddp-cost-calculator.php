<?php
/**
 * Tests the composition of the duty amount presented to the shopper (WC-T1). The core deliberately
 * keeps the two duty components separate and leaves the merchant adjustment unapplied, so composing
 * the single charged figure is the module's job. Two rules are locked down here: only enabled
 * components contribute to the raw base, and each method's adjustment is applied to that base before
 * the result is floored at zero and rounded exactly once (INV-3).
 *
 * The two halves are tested apart because they run at different times: the base is composed once per
 * lookup and cached, the adjustment on every read.
 *
 * The currency the quote is priced in is checked here too: the core hands the component amounts over
 * unconverted, so a duty quoted in another currency than the cart's must be refused rather than added
 * to a total as if it were the shop's own money.
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
	 * Both enabled components compose to their raw sum: unadjusted and unrounded, because this is the
	 * figure the checkout caches for the whole cart and prices every method from.
	 */
	public function test_composed_base_sums_the_enabled_components() {
		$this->assertEquals(
			24.51,
			Ddp_Cost_Calculator::composed_base( $this->get_response( 5.76, 18.75 ) ),
			'Both enabled components should compose to their sum.',
			0.0001
		);
	}

	/**
	 * A disabled component does not apply on this route, so it contributes nothing to the base.
	 */
	public function test_a_disabled_component_does_not_contribute_to_the_base() {
		$response                   = $this->get_response( 5.76, 18.75 );
		$response->customsAndDuties = $this->get_component( 18.75, false );

		$this->assertEquals(
			5.76,
			Ddp_Cost_Calculator::composed_base( $response ),
			'Only the enabled component should contribute.',
			0.0001
		);
	}

	/**
	 * The merchant adjustment moves the base without ever pushing the charged amount below zero.
	 */
	public function test_the_adjustment_moves_the_base_and_floors_at_zero() {
		$base = 24.51;

		$this->assertEquals(
			24.51,
			Ddp_Cost_Calculator::charged_amount( $base, $this->get_method( null, 0.0 ) ),
			'Without an adjustment the base is charged as quoted.',
			0.0001
		);

		$this->assertEquals(
			29.51,
			Ddp_Cost_Calculator::charged_amount(
				$base,
				$this->get_method( DdpBehavior::ADJUSTMENT_FIXED, 5.00 )
			),
			'A fixed adjustment should be added to the base.',
			0.0001
		);

		$this->assertEquals(
			26.96,
			Ddp_Cost_Calculator::charged_amount(
				$base,
				$this->get_method( DdpBehavior::ADJUSTMENT_PERCENTAGE, 10 )
			),
			'A percentage adjustment should be taken off the base and rounded to two decimals.',
			0.0001
		);

		$this->assertEquals(
			0.00,
			Ddp_Cost_Calculator::charged_amount(
				$base,
				$this->get_method( DdpBehavior::ADJUSTMENT_FIXED, -30.00 )
			),
			'A negative adjustment larger than the base should floor the amount at zero.',
			0.0001
		);
	}

	/**
	 * INV-3: the charged amount is derived from the cached base on every read and must come out
	 * identical each time, so the figure shown on the option row, the cart fee and the order meta can
	 * never drift apart.
	 */
	public function test_charged_amount_is_not_recomputed_at_render() {
		// A base of 10.01 at +33.33% is a repeating decimal before rounding, the case where an
		// unrounded intermediate would surface as a different value on a second read.
		$base   = 10.01;
		$method = $this->get_method( DdpBehavior::ADJUSTMENT_PERCENTAGE, 33.33 );

		$first  = Ddp_Cost_Calculator::charged_amount( $base, $method );
		$second = Ddp_Cost_Calculator::charged_amount( $base, $method );
		$third  = Ddp_Cost_Calculator::charged_amount( $base, $method );

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
			Ddp_Cost_Calculator::composed_base( $response ),
			'A response with only the DDP fee set should compose to that fee.',
			0.0001
		);
	}

	/**
	 * No enabled component means the route carries no duty for this service, and the calculator says so
	 * with null rather than 0.00. The two are different answers: null is "there is no duty here", while
	 * 0.00 is a duty that exists and was adjusted down to nothing, which callers still offer.
	 */
	public function test_all_components_disabled_return_null() {
		$response                    = new DdpCostResponse();
		$response->serviceId         = 20339;
		$response->effectiveBehavior = DdpBehavior::OPTIONAL;
		$response->ddpFee            = $this->get_component( 5.76, false );
		$response->customsAndDuties  = $this->get_component( 18.75, false );

		$this->assertNull(
			Ddp_Cost_Calculator::composed_base( $response ),
			'A response with no enabled component must compose to null, never to 0.0.'
		);
	}

	/**
	 * An adjustment that cancels the duty out charges 0.00 off a base that is not null: the merchant
	 * absorbed a duty that does exist, and callers offer that as a duties-paid option priced at the
	 * transport cost. Only a null base means there was no duty to absorb.
	 */
	public function test_an_adjustment_down_to_zero_charges_zero_off_a_real_base() {
		$base = Ddp_Cost_Calculator::composed_base( $this->get_response( 5.76, 18.75 ) );

		$this->assertNotNull( $base, 'An absorbed duty is a quoted duty, not a missing one.' );
		$this->assertEquals(
			0.00,
			Ddp_Cost_Calculator::charged_amount(
				$base,
				$this->get_method( DdpBehavior::ADJUSTMENT_PERCENTAGE, -100.0 )
			),
			'',
			0.0001
		);
	}

	/**
	 * The adjustment is read off the method at the moment the amount is wanted, so the same cached base
	 * prices differently the instant the merchant edits it - which is why the cache holds no amount.
	 */
	public function test_the_same_base_reprices_when_the_adjustment_changes() {
		$base   = 24.51;
		$method = $this->get_method( DdpBehavior::ADJUSTMENT_FIXED, 5.00 );

		$this->assertEquals( 29.51, Ddp_Cost_Calculator::charged_amount( $base, $method ), '', 0.0001 );

		$method->setDdpAdjustmentAmount( -1.00 );

		$this->assertEquals(
			23.51,
			Ddp_Cost_Calculator::charged_amount( $base, $method ),
			'An edited adjustment must take effect without the base being quoted again.',
			0.0001
		);
	}

	/**
	 * A quote priced in the cart's own currency is usable, whatever the letter case.
	 */
	public function test_a_quote_in_the_cart_currency_is_not_foreign() {
		$this->assertNull( Ddp_Cost_Calculator::foreign_currency( $this->get_response( 5.76, 18.75 ), 'EUR' ) );
		$this->assertNull( Ddp_Cost_Calculator::foreign_currency( $this->get_response( 5.76, 18.75 ), 'eur' ) );
	}

	/**
	 * The core hands the amounts over unconverted, so a duty quoted in another currency is refused: it
	 * would otherwise be added to the total as if it were the shop's own money, and recorded on the
	 * order under the shop's currency.
	 */
	public function test_a_quote_in_another_currency_is_reported_as_foreign() {
		$response                   = $this->get_response( 5.76, 18.75 );
		$response->ddpFee           = $this->get_component( 5.76, true, 'USD' );
		$response->customsAndDuties = $this->get_component( 18.75, true, 'USD' );

		$this->assertSame( 'USD', Ddp_Cost_Calculator::foreign_currency( $response, 'EUR' ) );
	}

	/**
	 * One mispriced component is enough to refuse the quote: the two are summed into a single figure,
	 * so a foreign half cannot be dropped and the rest still charged.
	 */
	public function test_a_single_component_in_another_currency_is_reported_as_foreign() {
		$response                   = $this->get_response( 5.76, 18.75 );
		$response->customsAndDuties = $this->get_component( 18.75, true, 'USD' );

		$this->assertSame( 'USD', Ddp_Cost_Calculator::foreign_currency( $response, 'EUR' ) );
	}

	/**
	 * A component that does not apply on this route contributes nothing to the sum, so its currency is
	 * not a reason to refuse the quote.
	 */
	public function test_a_disabled_component_in_another_currency_is_ignored() {
		$response                   = $this->get_response( 5.76, 18.75 );
		$response->customsAndDuties = $this->get_component( 18.75, false, 'USD' );

		$this->assertNull( Ddp_Cost_Calculator::foreign_currency( $response, 'EUR' ) );
	}

	/**
	 * A missing currency on either side is not a mismatch: there is nothing to compare, and refusing
	 * would drop duties for a response that simply did not carry the field.
	 */
	public function test_a_missing_currency_is_not_foreign() {
		$response                   = $this->get_response( 5.76, 18.75 );
		$response->ddpFee           = $this->get_component( 5.76, true, '' );
		$response->customsAndDuties = $this->get_component( 18.75, true, '' );

		$this->assertNull( Ddp_Cost_Calculator::foreign_currency( $response, 'EUR' ) );
		$this->assertNull( Ddp_Cost_Calculator::foreign_currency( $this->get_response( 5.76, 18.75 ), '' ) );
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
	 * @param float  $total_price Total price of the component.
	 * @param bool   $is_enabled  Whether the component applies on this route.
	 * @param string $currency    Currency the component is priced in.
	 *
	 * @return DdpProductCost
	 */
	private function get_component( $total_price, $is_enabled, $currency = 'EUR' ) {
		return DdpProductCost::fromArray(
			array(
				'total_price' => $total_price,
				'base_price'  => $total_price,
				'tax_price'   => 0,
				'currency'    => $currency,
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
