<?php
/**
 * Shop origin of the simulated prices panel (CR-SET-72-02) for a merchant without a Packlink account.
 *
 * @package Packlink_Pro_Shipping
 */

use Packlink\WooCommerce\Components\Services\Shop_Origin_Provider;

/**
 * Class Shop_Origin_Provider_Test
 *
 * @package Packlink_Pro_Shipping
 */
class Shop_Origin_Provider_Test extends WP_UnitTestCase {

	/**
	 * Store address options touched by the tests, with their values before the test.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * @inheritDoc
	 */
	public function setUp() {
		parent::setUp();

		foreach ( array( 'woocommerce_default_country', 'woocommerce_store_postcode' ) as $name ) {
			$this->options[ $name ] = get_option( $name );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function tearDown() {
		foreach ( $this->options as $name => $value ) {
			update_option( $name, $value );
		}

		parent::tearDown();
	}

	/**
	 * The store address is the origin; a state suffix ("ES:M") is not part of the country.
	 */
	public function test_origin_is_the_store_address() {
		update_option( 'woocommerce_default_country', 'ES:M' );
		update_option( 'woocommerce_store_postcode', ' 28009 ' );

		$this->assertSame(
			array(
				'country'    => 'ES',
				'postalCode' => '28009',
			),
			( new Shop_Origin_Provider() )->getOrigin()
		);
	}

	/**
	 * Without a postcode there is no usable address: core then uses the capital city of the market.
	 */
	public function test_no_postcode_leaves_the_origin_to_core() {
		update_option( 'woocommerce_default_country', 'FR' );
		update_option( 'woocommerce_store_postcode', '' );

		$this->assertNull( ( new Shop_Origin_Provider() )->getOrigin() );
	}
}
