<?php
/**
 * Proves the DDP rate-id vocabulary of `Ddp_Checkout` (WC-T3) and invariant INV-2: a `:ddp` rate id
 * resolves to the same Packlink shipping method as its base rate id. WooCommerce builds the DDP rate
 * id with `WC_Shipping_Method::get_rate_id( 'ddp' )`, which appends a third segment to
 * `packlink_shipping_method:<instance_id>`. Every existing parser in the plugin reads the instance id
 * from segment `[1]`, so it must stay blind to that suffix.
 *
 * @package Packlink_Pro_Shipping
 */

use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\WooCommerce\Components\Checkout\Checkout_Handler;
use Packlink\WooCommerce\Components\Checkout\Ddp_Checkout;
use Packlink\WooCommerce\Components\ShippingMethod\Packlink_Shipping_Method;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Helper;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Map;
use Packlink\WooCommerce\Components\Utility\Database;

/**
 * Class Ddp_Rate_Test
 *
 * @package Packlink_Pro_Shipping
 */
class Ddp_Rate_Test extends WP_UnitTestCase {

	/**
	 * Install the entity table so shipping-method maps can be persisted.
	 */
	public function setUp() {
		parent::setUp();
		global $wpdb;
		$database = new Database( $wpdb );
		$database->install();
	}

	/**
	 * Drop the entity table.
	 */
	public function tearDown() {
		global $wpdb;
		$database = new Database( $wpdb );
		$database->uninstall();
		parent::tearDown();
	}

	/**
	 * Persists a Packlink shipping method and maps it to a WooCommerce shipping-method instance,
	 * which is what `Shipping_Method_Helper::get_packlink_shipping_method()` resolves through.
	 *
	 * @param int $wc_instance_id WooCommerce shipping method instance identifier.
	 *
	 * @return int Identifier of the persisted Packlink shipping method.
	 *
	 * @throws \Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException When repository not registered.
	 */
	private function map_instance_to_packlink_method( $wc_instance_id ) {
		$method = new ShippingMethod();
		$method->setCarrierName( 'Test carrier' );
		$method->setTitle( 'Test service' );
		$method->setCurrency( 'EUR' );
		RepositoryRegistry::getRepository( ShippingMethod::CLASS_NAME )->save( $method );

		$map = new Shipping_Method_Map();
		$map->setWoocommerceShippingMethodId( $wc_instance_id );
		$map->setPacklinkShippingMethodId( $method->getId() );
		$map->setZoneId( 1 );
		RepositoryRegistry::getRepository( Shipping_Method_Map::CLASS_NAME )->save( $map );

		return $method->getId();
	}

	/**
	 * Builds the base rate id WooCommerce produces for a Packlink shipping-method instance.
	 *
	 * @param int $instance_id Instance identifier.
	 *
	 * @return string
	 */
	private function base_rate_id( $instance_id ) {
		return Packlink_Shipping_Method::PACKLINK_SHIPPING_METHOD . ':' . $instance_id;
	}

	/**
	 * INV-2: the `:ddp` rate id and its base rate id resolve to the very same Packlink shipping
	 * method, because both carry the instance id in segment `[1]`.
	 *
	 * @throws \Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException When query filter invalid.
	 * @throws \Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException When repository not registered.
	 */
	public function test_ddp_rate_id_resolves_to_same_instance() {
		$expected_method_id = $this->map_instance_to_packlink_method( 12 );

		$base = $this->base_rate_id( 12 );
		$ddp  = $base . ':' . Ddp_Checkout::RATE_SUFFIX;

		$this->assertSame( 12, Ddp_Checkout::instance_id( $base ) );
		$this->assertSame( 12, Ddp_Checkout::instance_id( $ddp ) );

		$from_base = Shipping_Method_Helper::get_packlink_shipping_method( Ddp_Checkout::instance_id( $base ) );
		$from_ddp  = Shipping_Method_Helper::get_packlink_shipping_method( Ddp_Checkout::instance_id( $ddp ) );

		$this->assertNotNull( $from_base, 'The base rate id must resolve to a Packlink shipping method.' );
		$this->assertNotNull( $from_ddp, 'The DDP rate id must resolve to a Packlink shipping method.' );
		// The repository returns identifiers as strings, hence the casts.
		$this->assertSame( (int) $expected_method_id, (int) $from_base->getId() );
		$this->assertSame(
			(int) $from_base->getId(),
			(int) $from_ddp->getId(),
			'INV-2: the DDP rate must resolve to the same Packlink shipping method as its base rate.'
		);
	}

	/**
	 * `Checkout_Handler::get_rate_data()` must report the same instance id for a DDP rate as
	 * `Ddp_Checkout::instance_id()` does, so the checkout front-end keeps working with a suffix present.
	 *
	 * @throws ReflectionException When the method cannot be reflected.
	 */
	public function test_checkout_handler_reads_same_instance_id_from_ddp_rate() {
		$ddp_rate_id = $this->base_rate_id( 12 ) . ':' . Ddp_Checkout::RATE_SUFFIX;
		$rate        = new WC_Shipping_Rate(
			$ddp_rate_id,
			'Test service',
			10.0,
			array(),
			Packlink_Shipping_Method::PACKLINK_SHIPPING_METHOD,
			12
		);

		$method = new ReflectionMethod( 'Packlink\WooCommerce\Components\Checkout\Checkout_Handler', 'get_rate_data' );
		$method->setAccessible( true );
		$rate_data = $method->invoke( new Checkout_Handler(), $rate );

		$this->assertSame( $ddp_rate_id, $rate_data['rate_id'] );
		$this->assertSame( Ddp_Checkout::instance_id( $ddp_rate_id ), $rate_data['instance_id'] );
	}

	/**
	 * `instance_id()` must agree with the `explode( ':' )` parsing already shipped in
	 * `Checkout_Handler::get_rate_data()` and `Surcharge_Handler::get_shipping_method_id()` for both
	 * rate-id forms, single- and multi-digit instance ids alike.
	 */
	public function test_instance_id_agrees_with_shipped_parsers() {
		foreach ( array( 1, 7, 12, 1234 ) as $instance_id ) {
			$base = $this->base_rate_id( $instance_id );
			$ddp  = $base . ':' . Ddp_Checkout::RATE_SUFFIX;

			foreach ( array( $base, $ddp ) as $rate_id ) {
				$parts = explode( ':', $rate_id );

				$this->assertSame(
					(int) $parts[1],
					Ddp_Checkout::instance_id( $rate_id ),
					'instance_id() must read segment [1], as the shipped parsers do: ' . $rate_id
				);
				$this->assertSame( $instance_id, Ddp_Checkout::instance_id( $rate_id ) );
			}
		}
	}

	/**
	 * A rate id without an instance segment yields no instance id.
	 */
	public function test_instance_id_of_rate_id_without_instance_segment_is_zero() {
		$this->assertSame( 0, Ddp_Checkout::instance_id( Packlink_Shipping_Method::PACKLINK_SHIPPING_METHOD ) );
		$this->assertSame( 0, Ddp_Checkout::instance_id( '' ) );
	}

	/**
	 * `base_rate_id()` strips the suffix and round-trips with `ddp_rate_id()`; it leaves a base rate
	 * id untouched.
	 */
	public function test_base_rate_id_round_trip() {
		$base = $this->base_rate_id( 12 );
		$ddp  = Ddp_Checkout::ddp_rate_id( $base );

		$this->assertSame( $base . ':' . Ddp_Checkout::RATE_SUFFIX, $ddp );
		$this->assertSame( $base, Ddp_Checkout::base_rate_id( $ddp ) );
		$this->assertSame( $base, Ddp_Checkout::base_rate_id( $base ) );
		$this->assertSame( $ddp, Ddp_Checkout::ddp_rate_id( $ddp ) );
	}

	/**
	 * Only a Packlink rate carrying the suffix is a DDP rate: neither the base rate nor a foreign
	 * shipping method qualifies.
	 */
	public function test_ddp_rate_id_recognition() {
		$base = $this->base_rate_id( 12 );

		$this->assertTrue( Ddp_Checkout::is_ddp_rate_id( $base . ':' . Ddp_Checkout::RATE_SUFFIX ) );
		$this->assertFalse( Ddp_Checkout::is_ddp_rate_id( $base ) );
		$this->assertFalse( Ddp_Checkout::is_ddp_rate_id( 'flat_rate:5' ) );
		$this->assertFalse( Ddp_Checkout::is_ddp_rate_id( 'flat_rate:5:' . Ddp_Checkout::RATE_SUFFIX ) );
		$this->assertFalse( Ddp_Checkout::is_ddp_rate_id( $base . ':drop_off' ) );
		$this->assertFalse( Ddp_Checkout::is_ddp_rate_id( '' ) );
	}

	/**
	 * The order-meta keys are the ones every later call site must use.
	 */
	public function test_order_meta_keys() {
		$this->assertSame( 'ddp', Ddp_Checkout::RATE_SUFFIX );
		$this->assertSame( '_packlink_ddp_selected', Ddp_Checkout::META_SELECTED );
		$this->assertSame( '_packlink_ddp_cost', Ddp_Checkout::META_COST );
		$this->assertSame( '_packlink_ddp_currency', Ddp_Checkout::META_CURRENCY );
	}
}
