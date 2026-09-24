<?php
/**
 * Tests that a DDP selection recorded on a WooCommerce order reaches the core Order object, so the
 * shipment draft carries `selected_products.ddp.is_selected` and the charged amount is persisted
 * against the shipment (WC-T6).
 *
 * Nothing writes this order meta yet - the cart-fee task does that - so these tests set it directly.
 *
 * @package Packlink_Pro_Shipping
 */

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\Order\Interfaces\ShopOrderService;
use Packlink\WooCommerce\Components\Checkout\Ddp_Checkout;
use Packlink\WooCommerce\Components\Customs\Customs_Handler;
use Packlink\WooCommerce\Components\Order\Shop_Order_Service;
use Packlink\WooCommerce\Components\Utility\Database;

/**
 * Class ShopOrderServiceDdpTest
 *
 * @package Packlink_Pro_Shipping
 */
class ShopOrderServiceDdpTest extends WP_UnitTestCase {

	/**
	 * Install the entity table and reset the singleton so each test starts clean.
	 */
	public function setUp() {
		parent::setUp();
		global $wpdb;
		$database = new Database( $wpdb );
		$database->install();
		Customs_Handler::seed_default_customs_mapping();
		Shop_Order_Service::resetInstance();
	}

	/**
	 * Drop the entity table.
	 */
	public function tearDown() {
		Shop_Order_Service::resetInstance();
		global $wpdb;
		$database = new Database( $wpdb );
		$database->uninstall();
		parent::tearDown();
	}

	/**
	 * Shop order service.
	 *
	 * @return ShopOrderService
	 */
	private function shop_order_service() {
		return ServiceRegister::getService( ShopOrderService::CLASS_NAME );
	}

	/**
	 * Creates a WooCommerce order carrying the given Packlink DDP meta.
	 *
	 * @param array $meta Meta key/value pairs to set on the order.
	 *
	 * @return int Order identifier.
	 */
	private function create_order_with_meta( array $meta ) {
		$order = wc_create_order();
		$order->set_currency( 'EUR' );

		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		$order->save();

		return $order->get_id();
	}

	/**
	 * A recorded DDP selection reaches the core Order, which is what makes the draft carry
	 * `selected_products.ddp.is_selected`. Without it a mandatory-DDP service is rejected at
	 * purchase with 400 mandatory_ddp_not_selected, so this is correctness, not presentation.
	 */
	public function test_recorded_ddp_selection_reaches_the_core_order() {
		$order_id = $this->create_order_with_meta(
			array(
				Ddp_Checkout::META_SELECTED => 'yes',
				Ddp_Checkout::META_COST     => '24.51',
			)
		);

		$order = $this->shop_order_service()->getOrderAndShippingData( (string) $order_id );

		$this->assertTrue( $order->isDdpSelected(), 'A recorded DDP selection must reach the core Order.' );
		$this->assertSame( 24.51, $order->getDdpCost(), 'The charged DDP amount must be carried verbatim.' );
	}

	/**
	 * An order without the DDP meta leaves both core fields at their defaults, so a non-DDP draft
	 * never emits the selected-products key.
	 */
	public function test_order_without_ddp_meta_leaves_the_defaults() {
		$order_id = $this->create_order_with_meta( array() );

		$order = $this->shop_order_service()->getOrderAndShippingData( (string) $order_id );

		$this->assertFalse( $order->isDdpSelected(), 'An order without DDP meta must not be marked as DDP.' );
		$this->assertNull( $order->getDdpCost(), 'An order without DDP meta must carry no DDP cost.' );
	}

	/**
	 * Only the literal 'yes' counts as a selection. Anything else - an explicit 'no', an empty
	 * string, a stale value from a cancelled selection - is not DDP.
	 *
	 * @dataProvider not_selected_values
	 *
	 * @param string $value Meta value that must not be read as a selection.
	 */
	public function test_a_non_yes_value_is_not_a_selection( $value ) {
		$order_id = $this->create_order_with_meta(
			array(
				Ddp_Checkout::META_SELECTED => $value,
				Ddp_Checkout::META_COST     => '24.51',
			)
		);

		$order = $this->shop_order_service()->getOrderAndShippingData( (string) $order_id );

		$this->assertFalse( $order->isDdpSelected(), 'Only "yes" may be read as a DDP selection.' );
		$this->assertNull( $order->getDdpCost(), 'A non-selection must carry no DDP cost.' );
	}

	/**
	 * Meta values that must not be treated as a DDP selection.
	 *
	 * @return array
	 */
	public function not_selected_values() {
		return array(
			array( 'no' ),
			array( '' ),
			array( '0' ),
			array( '1' ),
		);
	}
}
