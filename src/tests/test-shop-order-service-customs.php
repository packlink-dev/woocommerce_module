<?php
/**
 * Tests that captured customs data (product HS code / country, customer tax id) flows into the
 * core Order/Item objects at synchronization time (WC-T6).
 *
 * @package Packlink_Pro_Shipping
 */

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\Order\Interfaces\ShopOrderService;
use Packlink\WooCommerce\Components\Customs\Customs_Handler;
use Packlink\WooCommerce\Components\Services\Customs_Mapping_Service;
use Packlink\WooCommerce\Components\Utility\Database;

/**
 * Class ShopOrderServiceCustomsTest
 *
 * @package Packlink_Pro_Shipping
 */
class ShopOrderServiceCustomsTest extends WP_UnitTestCase {

	/**
	 * Install the entity table and seed the default customs mapping (maps to the dedicated meta keys).
	 */
	public function setUp() {
		parent::setUp();
		global $wpdb;
		$database = new Database( $wpdb );
		$database->install();
		Customs_Handler::seed_default_customs_mapping();
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
	 * Shop order service.
	 *
	 * @return ShopOrderService
	 */
	private function shop_order_service() {
		return ServiceRegister::getService( ShopOrderService::CLASS_NAME );
	}

	/**
	 * Creates a simple (non-virtual) product, optionally with customs meta.
	 *
	 * @param string $hs_code HS code meta value.
	 * @param string $country Country-of-origin meta value.
	 *
	 * @return WC_Product_Simple
	 */
	private function create_product( $hs_code = '', $country = '' ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Customs Test Product' );
		$product->set_regular_price( 20 );
		$product->set_weight( 2 );
		$product->save();

		if ( '' !== $hs_code ) {
			$product->update_meta_data( Customs_Mapping_Service::PRODUCT_HS_CODE_META, $hs_code );
		}
		if ( '' !== $country ) {
			$product->update_meta_data( Customs_Mapping_Service::PRODUCT_COUNTRY_OF_ORIGIN_META, $country );
		}
		$product->save();

		return $product;
	}

	/**
	 * Creates an order containing the product, with a shipping address and optional customer tax id.
	 *
	 * @param WC_Product_Simple $product Product to add.
	 * @param string            $tax_id  Customer tax id meta value.
	 *
	 * @return int Order id.
	 */
	private function create_order( $product, $tax_id = '' ) {
		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_shipping_first_name( 'Jane' );
		$order->set_shipping_last_name( 'Doe' );
		$order->set_shipping_address_1( 'Calle de Atocha 52' );
		$order->set_shipping_city( 'Bern' );
		$order->set_shipping_postcode( '3011' );
		$order->set_shipping_country( 'CH' );

		if ( '' !== $tax_id ) {
			$order->update_meta_data( Customs_Mapping_Service::BILLING_TAX_ID_META, $tax_id );
		}

		$order->calculate_totals();
		$order->save();

		return $order->get_id();
	}

	/**
	 * A mapped product HS code / country and a customer tax id flow into the core Item/Order.
	 */
	public function test_maps_product_customs_data_and_customer_tax_id() {
		$product  = $this->create_product( '12345678', 'DE' );
		$order_id = $this->create_order( $product, 'TAX-123' );

		$order = $this->shop_order_service()->getOrderAndShippingData( (string) $order_id );

		$items = $order->getItems();
		$this->assertNotEmpty( $items, 'Expected the order to contain at least one item.' );
		$this->assertSame( '12345678', $items[0]->getTariffNumber() );
		$this->assertSame( 'DE', $items[0]->getCountryOfOrigin() );
		$this->assertSame( 'TAX-123', $order->getTaxId() );
	}

	/**
	 * A product without customs meta leaves the item tariff/country empty (core applies defaults later).
	 */
	public function test_unmapped_product_leaves_item_customs_empty() {
		$product  = $this->create_product();
		$order_id = $this->create_order( $product );

		$order = $this->shop_order_service()->getOrderAndShippingData( (string) $order_id );

		$items = $order->getItems();
		$this->assertNotEmpty( $items );
		$this->assertEmpty( $items[0]->getTariffNumber(), 'Unmapped product should leave item tariff empty.' );
		$this->assertEmpty( $items[0]->getCountryOfOrigin() );
	}
}
