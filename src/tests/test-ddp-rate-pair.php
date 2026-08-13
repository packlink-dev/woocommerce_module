<?php
/**
 * Tests how many shipping options a DDP-capable service offers, and which (WC-T7).
 *
 * The composition is asserted through the pure `compose_rates()` seam rather than a live
 * `calculate_shipping()` call, because the latter needs a Packlink account to price anything - and the
 * decision being tested is exactly the part that does not depend on pricing.
 *
 * @package Packlink_Pro_Shipping
 */

use Packlink\BusinessLogic\DDP\DdpBehavior;
use Packlink\WooCommerce\Components\Checkout\Ddp_Checkout;
use Packlink\WooCommerce\Components\ShippingMethod\Packlink_Shipping_Method;

/**
 * Class DdpRatePairTest
 *
 * @package Packlink_Pro_Shipping
 */
class DdpRatePairTest extends WP_UnitTestCase {

	/**
	 * Base rate id used throughout.
	 */
	const BASE_ID = 'packlink_shipping_method:12';

	/**
	 * DDP rate id used throughout.
	 */
	const DDP_ID = 'packlink_shipping_method:12:ddp';

	/**
	 * A merchant who charges no duty gets exactly today's single option.
	 */
	public function test_behaviour_none_offers_only_the_plain_rate() {
		$rates = $this->compose( DdpBehavior::NONE, 24.51 );

		$this->assertCount( 1, $rates );
		$this->assertSame( self::BASE_ID, $rates[0]['id'] );
	}

	/**
	 * Offering duties optionally is the only case that produces two rows, plain first.
	 */
	public function test_optional_with_duty_offers_both_rates() {
		$rates = $this->compose( DdpBehavior::OPTIONAL, 24.51 );

		$this->assertCount( 2, $rates );
		$this->assertSame( self::BASE_ID, $rates[0]['id'], 'The duty-free option comes first.' );
		$this->assertSame( self::DDP_ID, $rates[1]['id'] );
	}

	/**
	 * Enforcing duties removes the shopper's choice, so only the DDP row is offered.
	 */
	public function test_enforced_with_duty_offers_only_the_ddp_rate() {
		$rates = $this->compose( DdpBehavior::ENFORCED, 24.51 );

		$this->assertCount( 1, $rates );
		$this->assertSame( self::DDP_ID, $rates[0]['id'] );
	}

	/**
	 * A service Packlink marks mandatory has no duty-free variant at all.
	 */
	public function test_mandatory_with_duty_offers_only_the_ddp_rate() {
		$rates = $this->compose( DdpBehavior::MANDATORY, 24.51 );

		$this->assertCount( 1, $rates );
		$this->assertSame( self::DDP_ID, $rates[0]['id'] );
	}

	/**
	 * INV-5: a duty lookup that produced nothing must never cost the shopper the plain option.
	 *
	 * @dataProvider fail_soft_behaviours
	 *
	 * @param string $behavior Effective behaviour that must fall back to the plain rate.
	 */
	public function test_no_duty_leaves_the_plain_rate_intact( $behavior ) {
		$rates = $this->compose( $behavior, null );

		$this->assertCount( 1, $rates, 'Losing duty must not lose the shipping option.' );
		$this->assertSame( self::BASE_ID, $rates[0]['id'] );
		$this->assertArrayNotHasKey( 'meta_data', $rates[0], 'A plain rate carries no duty amount.' );
	}

	/**
	 * Behaviours that fall back to the transport-only rate when no duty is available.
	 *
	 * @return array
	 */
	public function fail_soft_behaviours() {
		return array(
			array( DdpBehavior::OPTIONAL ),
			array( DdpBehavior::ENFORCED ),
		);
	}

	/**
	 * A mandatory service with no duty amount cannot be offered at all - duty-free is not a legal way
	 * to ship it, so the service drops out and WooCommerce's own fallback applies.
	 */
	public function test_mandatory_without_duty_offers_nothing() {
		$this->assertSame( array(), $this->compose( DdpBehavior::MANDATORY, null ) );
	}

	/**
	 * A zero amount is not a free duty option: it means nothing was quoted.
	 */
	public function test_a_zero_amount_is_not_offered() {
		$rates = $this->compose( DdpBehavior::OPTIONAL, 0.0 );

		$this->assertCount( 1, $rates );
		$this->assertSame( self::BASE_ID, $rates[0]['id'] );
	}

	/**
	 * The DDP rate carries the amount, so the cart fee can still find it on a render served from
	 * WooCommerce's cached rates.
	 */
	public function test_the_ddp_rate_carries_the_amount_as_meta() {
		$rates = $this->compose( DdpBehavior::OPTIONAL, 24.51 );

		$this->assertArrayHasKey( 'meta_data', $rates[1] );
		$this->assertSame(
			24.51,
			$rates[1]['meta_data'][ Ddp_Checkout::RATE_META_AMOUNT ],
			'The charged amount must travel on the rate.'
		);
	}

	/**
	 * Both rows describe the same transport, so the price policy and any shipping-class cost apply
	 * identically: the duty amount is charged separately and never folded into the shipping cost.
	 */
	public function test_both_rates_share_the_transport_cost_and_label() {
		$rates = $this->compose( DdpBehavior::OPTIONAL, 24.51 );

		$this->assertSame( $rates[0]['cost'], $rates[1]['cost'], 'Duty is never folded into transport.' );
		$this->assertSame( $rates[0]['label'], $rates[1]['label'] );
		$this->assertSame( 31.84, $rates[1]['cost'] );
	}

	/**
	 * Composes the rates for a transport-only base rate.
	 *
	 * @param string     $behavior Effective DDP behaviour.
	 * @param float|null $amount Charged duty amount, or null.
	 *
	 * @return array[]
	 */
	private function compose( $behavior, $amount ) {
		$base = array(
			'id'      => self::BASE_ID,
			'label'   => 'UPS - 3 DAYS delivery',
			'cost'    => 31.84,
			'package' => array(),
		);

		return Packlink_Shipping_Method::compose_rates( $base, self::DDP_ID, $behavior, $amount );
	}
}
