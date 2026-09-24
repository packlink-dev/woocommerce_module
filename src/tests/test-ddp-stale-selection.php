<?php
/**
 * Tests that a duties-paid selection which is no longer offered is silently moved to a rate that is
 * (WC-T11).
 *
 * A DDP rate disappears from the set the moment the shopper edits the address into a route that quotes
 * no duties. WooCommerce keeps the chosen rate id in the session regardless, so without this the
 * checkout is left pointing at a rate nobody offers any more.
 *
 * @package Packlink_Pro_Shipping
 */

use Packlink\WooCommerce\Components\Checkout\Checkout_Handler;

/**
 * Class DdpStaleSelectionTest
 *
 * @package Packlink_Pro_Shipping
 */
class DdpStaleSelectionTest extends WP_UnitTestCase {

	/**
	 * Base rate id of the chosen service.
	 */
	const BASE_ID = 'packlink_shipping_method:12';

	/**
	 * DDP rate id of the chosen service.
	 */
	const DDP_ID = 'packlink_shipping_method:12:ddp';

	/**
	 * Rate id of another, unrelated service.
	 */
	const OTHER_ID = 'packlink_shipping_method:7';

	/**
	 * Clears the session selection so no test inherits another's choice.
	 */
	public function tearDown() {
		WC()->session->set( 'chosen_shipping_methods', array() );
		parent::tearDown();
	}

	/**
	 * The shopper keeps the carrier and transit time they picked and loses only the duties option.
	 */
	public function test_a_vanished_ddp_rate_falls_back_to_the_same_service_without_duties() {
		WC()->session->set( 'chosen_shipping_methods', array( self::DDP_ID ) );

		$rates    = $this->rates( array( self::OTHER_ID, self::BASE_ID ) );
		$returned = $this->handler()->reset_stale_ddp_selection( $rates );

		$this->assertSame( array( self::BASE_ID ), WC()->session->get( 'chosen_shipping_methods' ) );
		$this->assertSame( array_keys( $rates ), array_keys( $returned ), 'The rate set itself is never altered.' );
	}

	/**
	 * Nothing is said about it: an unexplained warning on an ordinary address edit costs more
	 * conversions than it saves (spec D10).
	 */
	public function test_the_fallback_is_silent() {
		WC()->session->set( 'chosen_shipping_methods', array( self::DDP_ID ) );

		$this->handler()->reset_stale_ddp_selection( $this->rates( array( self::BASE_ID ) ) );

		$this->assertSame( 0, wc_notice_count() );
	}

	/**
	 * A mandatory-DDP service has no duty-free variant, so there is no same-service rate to fall back
	 * to and the first rate on offer is taken instead.
	 */
	public function test_a_mandatory_service_falls_back_to_the_first_available_rate() {
		WC()->session->set( 'chosen_shipping_methods', array( self::DDP_ID ) );

		$this->handler()->reset_stale_ddp_selection( $this->rates( array( self::OTHER_ID, 'flat_rate:3' ) ) );

		$this->assertSame( array( self::OTHER_ID ), WC()->session->get( 'chosen_shipping_methods' ) );
	}

	/**
	 * A selection that is still on offer is left exactly as the shopper made it.
	 */
	public function test_an_offered_ddp_rate_keeps_the_selection() {
		WC()->session->set( 'chosen_shipping_methods', array( self::DDP_ID ) );

		$this->handler()->reset_stale_ddp_selection( $this->rates( array( self::BASE_ID, self::DDP_ID ) ) );

		$this->assertSame( array( self::DDP_ID ), WC()->session->get( 'chosen_shipping_methods' ) );
	}

	/**
	 * A shopper who never chose duties-paid delivery is none of this filter's business, even when the
	 * rate they chose has gone - WooCommerce's own fallback handles that case as it always has.
	 */
	public function test_a_plain_selection_is_never_touched() {
		WC()->session->set( 'chosen_shipping_methods', array( self::BASE_ID ) );

		$this->handler()->reset_stale_ddp_selection( $this->rates( array( self::OTHER_ID ) ) );

		$this->assertSame( array( self::BASE_ID ), WC()->session->get( 'chosen_shipping_methods' ) );
	}

	/**
	 * Another plugin's rate that happens to end in the same suffix is not a Packlink DDP rate.
	 */
	public function test_a_foreign_rate_is_never_touched() {
		WC()->session->set( 'chosen_shipping_methods', array( 'flat_rate:5:ddp' ) );

		$this->handler()->reset_stale_ddp_selection( $this->rates( array( self::BASE_ID ) ) );

		$this->assertSame( array( 'flat_rate:5:ddp' ), WC()->session->get( 'chosen_shipping_methods' ) );
	}

	/**
	 * With nothing to fall back to, the selection is left alone rather than emptied.
	 */
	public function test_an_empty_rate_set_leaves_the_selection_alone() {
		WC()->session->set( 'chosen_shipping_methods', array( self::DDP_ID ) );

		$this->handler()->reset_stale_ddp_selection( array() );

		$this->assertSame( array( self::DDP_ID ), WC()->session->get( 'chosen_shipping_methods' ) );
	}

	/**
	 * Handler under test.
	 *
	 * @return Checkout_Handler
	 */
	private function handler() {
		return new Checkout_Handler();
	}

	/**
	 * Builds a rate set keyed by rate id, in the given order.
	 *
	 * @param string[] $rate_ids Rate ids to offer.
	 *
	 * @return \WC_Shipping_Rate[]
	 */
	private function rates( array $rate_ids ) {
		$rates = array();

		foreach ( $rate_ids as $rate_id ) {
			$parts = explode( ':', $rate_id );

			$rates[ $rate_id ] = new \WC_Shipping_Rate(
				$rate_id,
				'UPS - 3 DAYS delivery',
				31.84,
				array(),
				$parts[0],
				isset( $parts[1] ) ? $parts[1] : 0
			);
		}

		return $rates;
	}
}
