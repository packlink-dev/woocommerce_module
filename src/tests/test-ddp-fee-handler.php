<?php
/**
 * Tests that duties are charged as their own cart fee only when a duties-paid option is chosen, taxed
 * like the transport line it accompanies, and recorded on the order afterwards (WC-T8).
 *
 * @package Packlink_Pro_Shipping
 */

use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\WooCommerce\Components\Checkout\Ddp_Checkout;
use Packlink\WooCommerce\Components\Checkout\Ddp_Fee_Handler;
use Packlink\WooCommerce\Components\ShippingMethod\Packlink_Shipping_Method;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Map;
use Packlink\WooCommerce\Components\Utility\Database;

/**
 * Class DdpFeeHandlerTest
 *
 * @package Packlink_Pro_Shipping
 */
class DdpFeeHandlerTest extends WP_UnitTestCase {

	/**
	 * WooCommerce shipping method instance id used throughout.
	 */
	const INSTANCE_ID = 12;

	/**
	 * Base rate id.
	 */
	const BASE_ID = 'packlink_shipping_method:12';

	/**
	 * DDP rate id.
	 */
	const DDP_ID = 'packlink_shipping_method:12:ddp';

	/**
	 * Installs the entity table and maps a Packlink method onto the WooCommerce instance.
	 */
	public function setUp() {
		parent::setUp();
		global $wpdb;
		$database = new Database( $wpdb );
		$database->install();

		$this->map_method();

		if ( null === WC()->customer ) {
			// WooCommerce only builds the customer on a frontend request.
			WC()->customer = new \WC_Customer();
		}
	}

	/**
	 * Drops the entity table.
	 */
	public function tearDown() {
		WC()->session->set( 'chosen_shipping_methods', array() );
		WC()->session->set( 'shipping_for_package_0', null );
		global $wpdb;
		$database = new Database( $wpdb );
		$database->uninstall();
		parent::tearDown();
	}

	/**
	 * The plain Packlink option charges no duties.
	 */
	public function test_no_fee_for_the_plain_rate() {
		$this->choose( self::BASE_ID, 24.51 );

		$this->handler()->add_fee( WC()->cart );

		$this->assertSame( array(), WC()->cart->get_fees() );
	}

	/**
	 * Another shipping plugin's rate is none of our business, even if it ends in the same suffix.
	 */
	public function test_no_fee_for_a_foreign_rate() {
		$this->choose( 'flat_rate:5:ddp', 24.51 );

		$this->handler()->add_fee( WC()->cart );

		$this->assertSame( array(), WC()->cart->get_fees() );
	}

	/**
	 * The duties-paid option charges the amount quoted for that rate, as its own line.
	 */
	public function test_the_ddp_rate_is_charged_as_its_own_fee() {
		$this->choose( self::DDP_ID, 24.51 );

		$this->handler()->add_fee( WC()->cart );

		$fees = WC()->cart->get_fees();
		$this->assertCount( 1, $fees );

		$fee = reset( $fees );
		$this->assertSame( 'Delivery Duty Paid', $fee->name );
		$this->assertEquals( 24.51, $fee->amount );
	}

	/**
	 * Totals can be recalculated several times in a request; the duties must not stack.
	 */
	public function test_the_fee_is_not_added_twice() {
		$this->choose( self::DDP_ID, 24.51 );

		$handler = $this->handler();
		$handler->add_fee( WC()->cart );
		$handler->add_fee( WC()->cart );

		$this->assertCount( 1, WC()->cart->get_fees() );
	}

	/**
	 * With no amount on the rate and nothing cached, no duties are invented.
	 */
	public function test_no_fee_when_no_amount_is_known() {
		$this->choose( self::DDP_ID, null );

		$this->handler()->add_fee( WC()->cart );

		$this->assertSame( array(), WC()->cart->get_fees() );
	}

	/**
	 * The duties line follows the Tax status of the shipping method instance that carries it.
	 *
	 * @dataProvider tax_statuses
	 *
	 * @param string $status Instance Tax status setting.
	 * @param bool   $expected Whether the fee must be taxable.
	 */
	public function test_the_fee_follows_the_instance_tax_status( $status, $expected ) {
		update_option(
			'woocommerce_packlink_shipping_method_' . self::INSTANCE_ID . '_settings',
			array( 'tax_status' => $status )
		);
		$this->choose( self::DDP_ID, 24.51 );

		$this->handler()->add_fee( WC()->cart );

		$fees = WC()->cart->get_fees();
		$fee  = reset( $fees );
		$this->assertSame( $expected, (bool) $fee->taxable );
	}

	/**
	 * Tax status settings and the taxability they imply.
	 *
	 * @return array
	 */
	public function tax_statuses() {
		return array(
			array( 'taxable', true ),
			array( 'none', false ),
		);
	}

	/**
	 * What was charged is recorded on the order, read back from the fee line rather than recomputed.
	 */
	public function test_the_charged_amount_is_recorded_on_the_order() {
		$this->choose( self::DDP_ID, 24.51 );

		$order = $this->order_with_shipping_and_fee( 24.51 );
		$this->handler()->persist_on_order( $order );

		$saved = \WC_Order_Factory::get_order( $order->get_id() );
		$this->assertSame( 'yes', $saved->get_meta( Ddp_Checkout::META_SELECTED ) );
		$this->assertEquals( 24.51, $saved->get_meta( Ddp_Checkout::META_COST ) );
		$this->assertSame( $saved->get_currency(), $saved->get_meta( Ddp_Checkout::META_CURRENCY ) );
	}

	/**
	 * A non-DDP order records nothing, so its draft never claims duties were paid.
	 */
	public function test_a_plain_order_records_nothing() {
		$this->choose( self::BASE_ID, 24.51 );

		$order = $this->order_with_shipping_and_fee( null );
		$this->handler()->persist_on_order( $order );

		$saved = \WC_Order_Factory::get_order( $order->get_id() );
		$this->assertSame( '', (string) $saved->get_meta( Ddp_Checkout::META_SELECTED ) );
		$this->assertSame( '', (string) $saved->get_meta( Ddp_Checkout::META_COST ) );
	}

	/**
	 * Handler under test.
	 *
	 * @return Ddp_Fee_Handler
	 */
	private function handler() {
		return new Ddp_Fee_Handler();
	}

	/**
	 * Chooses a shipping rate and seeds WooCommerce's cached rate set for it.
	 *
	 * @param string     $rate_id Rate id to choose.
	 * @param float|null $amount Duty amount to attach to the rate, or null for none.
	 */
	private function choose( $rate_id, $amount ) {
		WC()->cart->empty_cart();
		WC()->session->set( 'chosen_shipping_methods', array( $rate_id ) );

		$rate = new \WC_Shipping_Rate( $rate_id, 'UPS - 3 DAYS delivery', 31.84 );
		if ( null !== $amount ) {
			$rate->add_meta_data( Ddp_Checkout::RATE_META_AMOUNT, $amount );
		}

		WC()->session->set(
			'shipping_for_package_0',
			array(
				'package_hash' => 'test',
				'rates'        => array( $rate_id => $rate ),
			)
		);
	}

	/**
	 * Builds an order carrying the chosen Packlink shipping line and, optionally, a duties fee line.
	 *
	 * @param float|null $fee_total Duties fee total, or null for no fee line.
	 *
	 * @return \WC_Order
	 */
	private function order_with_shipping_and_fee( $fee_total ) {
		$order = wc_create_order();

		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_id( Packlink_Shipping_Method::PACKLINK_SHIPPING_METHOD );
		$shipping->set_instance_id( self::INSTANCE_ID );
		$shipping->set_method_title( 'UPS - 3 DAYS delivery' );
		$shipping->set_total( 31.84 );
		$order->add_item( $shipping );

		if ( null !== $fee_total ) {
			$fee = new \WC_Order_Item_Fee();
			$fee->set_name( 'Delivery Duty Paid' );
			$fee->set_total( $fee_total );
			$order->add_item( $fee );
		}

		$order->save();

		return $order;
	}

	/**
	 * Persists a Packlink shipping method and maps it onto the WooCommerce instance id.
	 */
	private function map_method() {
		$method = new ShippingMethod();
		$method->setActivated( true );
		$method->setEnabled( true );
		$method->setTitle( 'UPS - 3 DAYS delivery' );
		$method->setCarrierName( 'UPS' );
		RepositoryRegistry::getRepository( ShippingMethod::CLASS_NAME )->save( $method );

		$map = new Shipping_Method_Map();
		$map->setPacklinkShippingMethodId( $method->getId() );
		$map->setWoocommerceShippingMethodId( self::INSTANCE_ID );
		RepositoryRegistry::getRepository( Shipping_Method_Map::CLASS_NAME )->save( $map );
	}
}
