<?php
/**
 * Asserts the feature is dormant when the account has no DDP capability (WC-T15).
 *
 * This is the state every existing installation upgrades into: the services response carries no
 * `ddp_support_level`, so nothing about duties may be visible, chargeable or requested. It is verified
 * rather than inferred, because a regression here would change checkout for every store that never
 * asked for DDP.
 *
 * @package Packlink_Pro_Shipping
 */

use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\DDP\DdpBehavior;
use Packlink\BusinessLogic\DDP\Interfaces\DdpCostServiceInterface;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingService;
use Packlink\WooCommerce\Components\Checkout\Ddp_Checkout;
use Packlink\WooCommerce\Components\Checkout\Ddp_Checkout_Service;
use Packlink\WooCommerce\Components\Checkout\Ddp_Fee_Handler;
use Packlink\WooCommerce\Components\ShippingMethod\Packlink_Shipping_Method;
use Packlink\WooCommerce\Components\Utility\Database;

/**
 * Counts duty lookups so "no request was made" can be asserted rather than assumed.
 */
class Ddp_Dormancy_Spy implements DdpCostServiceInterface {

	/**
	 * Number of lookups performed.
	 *
	 * @var int
	 */
	public $calls = 0;

	/**
	 * @inheritDoc
	 */
	public function getDdpCosts( \Packlink\BusinessLogic\Order\Objects\Order $order, $serviceId ) {
		$this->calls++;

		return null;
	}
}

/**
 * Class DdpDormancyTest
 *
 * @package Packlink_Pro_Shipping
 */
class DdpDormancyTest extends WP_UnitTestCase {

	/**
	 * Lookup counter registered in place of the core service.
	 *
	 * @var Ddp_Dormancy_Spy
	 */
	private $spy;

	/**
	 * Installs the entity table and counts any duty lookup.
	 */
	public function setUp() {
		parent::setUp();
		global $wpdb;
		$database = new Database( $wpdb );
		$database->install();

		$this->spy = new Ddp_Dormancy_Spy();
		$spy       = $this->spy;
		ServiceRegister::registerService(
			DdpCostServiceInterface::CLASS_NAME,
			function () use ( $spy ) {
				return $spy;
			}
		);

		Ddp_Checkout_Service::resetInstance();

		if ( null === WC()->customer ) {
			WC()->customer = new \WC_Customer();
		}
	}

	/**
	 * Drops the entity table.
	 */
	public function tearDown() {
		Ddp_Checkout_Service::resetInstance();
		WC()->session->set( 'chosen_shipping_methods', array() );
		global $wpdb;
		$database = new Database( $wpdb );
		$database->uninstall();
		parent::tearDown();
	}

	/**
	 * With no support level on any service, the method's effective behaviour is none whatever the
	 * merchant setting says - so a stale configuration from a downgraded account cannot resurrect DDP.
	 *
	 * @dataProvider merchant_behaviours
	 *
	 * @param string $behavior Merchant-configured behaviour.
	 */
	public function test_effective_behaviour_is_none_without_a_support_level( $behavior ) {
		$method = $this->seed_method( $behavior, null );

		$this->assertSame( DdpBehavior::NONE, $method->getEffectiveDdpBehavior() );
	}

	/**
	 * Every merchant behaviour, including ones that would charge duty on a capable account.
	 *
	 * @return array
	 */
	public function merchant_behaviours() {
		return array(
			array( DdpBehavior::NONE ),
			array( DdpBehavior::OPTIONAL ),
			array( DdpBehavior::ENFORCED ),
		);
	}

	/**
	 * The literal string "none" is a legal support level, not only null, and must be treated the same.
	 */
	public function test_the_literal_none_support_level_is_also_dormant() {
		$method = $this->seed_method( DdpBehavior::OPTIONAL, 'none' );

		$this->assertSame( DdpBehavior::NONE, $method->getEffectiveDdpBehavior() );
	}

	/**
	 * One rate, the base id, and no second option - byte-identical to the pre-DDP behaviour.
	 */
	public function test_only_the_plain_rate_is_offered() {
		$base = array(
			'id'      => 'packlink_shipping_method:12',
			'label'   => 'UPS - 3 DAYS delivery',
			'cost'    => 31.84,
			'package' => array(),
		);

		$rates = Packlink_Shipping_Method::compose_rates(
			$base,
			'packlink_shipping_method:12:ddp',
			DdpBehavior::NONE,
			null
		);

		$this->assertCount( 1, $rates );
		$this->assertSame( 'packlink_shipping_method:12', $rates[0]['id'] );
		$this->assertArrayNotHasKey( 'meta_data', $rates[0], 'A dormant rate carries no duty amount.' );
	}

	/**
	 * No duty endpoint is called. Asserted against a counter, not inferred from the absence of output.
	 */
	public function test_no_duty_request_is_made() {
		$this->seed_method( DdpBehavior::OPTIONAL, null );

		$amount = Ddp_Checkout_Service::getInstance()->amount_for_method(
			$this->stored_method(),
			$this->package()
		);

		$this->assertNull( $amount );
		$this->assertSame( 0, $this->spy->calls, 'A dormant account must not reach Packlink for duties.' );
	}

	/**
	 * No duties fee reaches the cart, even if a duties-paid rate id somehow ends up chosen.
	 */
	public function test_no_duties_fee_is_added() {
		$this->seed_method( DdpBehavior::OPTIONAL, null );
		WC()->cart->empty_cart();
		WC()->session->set( 'chosen_shipping_methods', array( 'packlink_shipping_method:12:ddp' ) );
		WC()->session->set( 'shipping_for_package_0', null );

		$handler = new Ddp_Fee_Handler();
		$handler->add_fee( WC()->cart );

		$this->assertSame( array(), WC()->cart->get_fees() );
		$this->assertSame( 0, $this->spy->calls, 'The fee path must never reach Packlink.' );
	}

	/**
	 * Persists a shipping method with the given behaviour and support level.
	 *
	 * @param string      $behavior Merchant-configured behaviour.
	 * @param string|null $level API support level.
	 *
	 * @return ShippingMethod
	 */
	private function seed_method( $behavior, $level ) {
		$service                     = new ShippingService();
		$service->serviceId          = '10059';
		$service->serviceName        = 'Express Saver';
		$service->carrierName        = 'UPS';
		$service->departureCountry   = 'FR';
		$service->destinationCountry = 'US';
		$service->ddpSupportLevel    = $level;

		$method = new ShippingMethod();
		$method->setActivated( true );
		$method->setEnabled( true );
		$method->setTitle( 'UPS - 3 DAYS delivery' );
		$method->setCarrierName( 'UPS' );
		$method->setDdpBehavior( $behavior );
		$method->addShippingService( $service );

		RepositoryRegistry::getRepository( ShippingMethod::CLASS_NAME )->save( $method );

		return $method;
	}

	/**
	 * The persisted method.
	 *
	 * @return ShippingMethod
	 */
	private function stored_method() {
		$methods = RepositoryRegistry::getRepository( ShippingMethod::CLASS_NAME )->select();

		return reset( $methods );
	}

	/**
	 * An international shipping package - so the route is never the reason nothing happens.
	 *
	 * @return array
	 */
	private function package() {
		WC()->customer->set_shipping_country( 'US' );
		WC()->customer->set_shipping_postcode( '10001' );
		WC()->customer->set_shipping_city( 'New York' );
		WC()->customer->set_shipping_address_1( 'Street 1' );
		WC()->customer->set_billing_phone( '123456789' );

		return array(
			'contents'      => array(),
			'cart_subtotal' => 25.0,
			'destination'   => array(
				'country'  => 'US',
				'postcode' => '10001',
				'city'     => 'New York',
			),
		);
	}
}
