<?php
/**
 * Tests that captured customs data (product HS code / country, customer tax ID / VAT number) flows
 * into the core Order/Item objects at synchronization time, honoring the namespaced mapping values
 * (`attr:`, `meta:`, `order:`, `user:`) and the dedicated-field fallbacks (WC-T6). One resolved
 * customer value feeds both Order::setTaxId() and Order::setVatNumber(): the core routes it to the
 * `tax_id` or `vat_number` customs attribute based on the configured receiver user type.
 *
 * @package Packlink_Pro_Shipping
 */

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\Order\Interfaces\ShopOrderService;
use Packlink\WooCommerce\Components\Customs\Customs_Handler;
use Packlink\WooCommerce\Components\Order\Shop_Order_Service;
use Packlink\WooCommerce\Components\Services\Config_Service;
use Packlink\WooCommerce\Components\Services\Customs_Mapping_Service;
use Packlink\WooCommerce\Components\Utility\Database;

/**
 * Class ShopOrderServiceCustomsTest
 *
 * @package Packlink_Pro_Shipping
 */
class ShopOrderServiceCustomsTest extends WP_UnitTestCase {

	/**
	 * Install the entity table and seed the default customs mapping (maps to the dedicated fields).
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
	 * Overrides fields of the saved customs mapping and drops the service instance so the change
	 * is picked up (the service caches the loaded mapping per request).
	 *
	 * @param array $fields Mapping property => value pairs.
	 */
	private function set_mapping( array $fields ) {
		/**
		 * Configuration service.
		 *
		 * @var Config_Service $config
		 */
		$config  = ServiceRegister::getService( Config_Service::CLASS_NAME );
		$mapping = $config->getCustomsMappings();

		foreach ( $fields as $property => $value ) {
			$mapping->$property = $value;
		}

		$config->setCustomsMappings( $mapping );
		Shop_Order_Service::resetInstance();
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
	 * Attaches a global (taxonomy-backed) attribute with one term to the product.
	 *
	 * @param WC_Product $product Product to attach the attribute to.
	 * @param string     $slug    Attribute slug (taxonomy becomes `pa_{slug}`).
	 * @param string     $value   Attribute term name.
	 *
	 * @return string The attribute taxonomy name (`pa_*`).
	 */
	private function attach_global_attribute( $product, $slug, $value ) {
		$attribute_id = wc_create_attribute(
			array(
				'name' => ucwords( str_replace( '_', ' ', $slug ) ),
				'slug' => $slug,
			)
		);
		$this->assertNotWPError( $attribute_id, 'Failed to create the global product attribute.' );

		$taxonomy = wc_attribute_taxonomy_name( $slug );
		register_taxonomy( $taxonomy, array( 'product' ), array( 'hierarchical' => false ) );

		$term = wp_insert_term( $value, $taxonomy );
		$this->assertNotWPError( $term, 'Failed to create the attribute term.' );

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( $attribute_id );
		$attribute->set_name( $taxonomy );
		$attribute->set_options( array( (int) $term['term_id'] ) );
		$attribute->set_visible( true );
		$product->set_attributes( array( $attribute ) );
		$product->save();

		return $taxonomy;
	}

	/**
	 * Attaches a per-product custom (non-taxonomy) attribute to the product.
	 *
	 * @param WC_Product $product Product to attach the attribute to.
	 * @param string     $name    Custom attribute name.
	 * @param string     $value   Attribute value.
	 */
	private function attach_custom_attribute( $product, $name, $value ) {
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( $name );
		$attribute->set_options( array( $value ) );
		$attribute->set_visible( true );
		$product->set_attributes( array( $attribute ) );
		$product->save();
	}

	/**
	 * Creates a registered customer, optionally with the dedicated customs user meta.
	 *
	 * @param string $tax_id Dedicated tax ID / VAT number user meta value.
	 *
	 * @return int User id.
	 */
	private function create_customer( $tax_id = '' ) {
		$user_id = $this->factory->user->create( array( 'role' => 'customer' ) );

		if ( '' !== $tax_id ) {
			update_user_meta( $user_id, Customs_Mapping_Service::USER_TAX_ID_META, $tax_id );
		}

		return $user_id;
	}

	/**
	 * Creates an order containing the product, with a shipping address and optional customer.
	 *
	 * @param WC_Product_Simple $product     Product to add.
	 * @param int               $customer_id Customer user id (0 keeps the order a guest order).
	 *
	 * @return WC_Order
	 */
	private function create_order( $product, $customer_id = 0 ) {
		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_shipping_first_name( 'Jane' );
		$order->set_shipping_last_name( 'Doe' );
		$order->set_shipping_address_1( 'Calle de Atocha 52' );
		$order->set_shipping_city( 'Bern' );
		$order->set_shipping_postcode( '3011' );
		$order->set_shipping_country( 'CH' );

		if ( 0 !== $customer_id ) {
			$order->set_customer_id( $customer_id );
		}

		$order->calculate_totals();
		$order->save();

		return $order;
	}

	/**
	 * Synchronizes the order and returns the core Order object.
	 *
	 * @param WC_Order $order WooCommerce order.
	 *
	 * @return \Packlink\BusinessLogic\Order\Objects\Order
	 */
	private function sync_order( $order ) {
		return $this->shop_order_service()->getOrderAndShippingData( (string) $order->get_id() );
	}

	/**
	 * With the seeded mapping, the dedicated product metas and the dedicated customer user meta
	 * flow into the core Item/Order. The single resolved customer value is set as both the tax id
	 * and the VAT number.
	 */
	public function test_maps_product_customs_data_and_customer_profile_fields() {
		$product     = $this->create_product( '12345678', 'DE' );
		$customer_id = $this->create_customer( 'TAX-123' );
		$order       = $this->create_order( $product, $customer_id );

		$core_order = $this->sync_order( $order );

		$items = $core_order->getItems();
		$this->assertNotEmpty( $items, 'Expected the order to contain at least one item.' );
		$this->assertSame( '12345678', $items[0]->getTariffNumber() );
		$this->assertSame( 'DE', $items[0]->getCountryOfOrigin() );
		$this->assertSame( 'TAX-123', $core_order->getTaxId() );
		$this->assertSame( $core_order->getTaxId(), $core_order->getVatNumber(), 'The VAT number must carry the same single resolved value as the tax id.' );
	}

	/**
	 * The customs invoice declares a per-unit value next to the quantity, so a multi-unit line has to
	 * report the unit price. Sending the line subtotal makes Packlink apply the quantity twice and
	 * refuse the shipment: the declared goods then exceed the package value.
	 */
	public function test_item_price_is_the_unit_value_not_the_line_subtotal() {
		$product = $this->create_product( '12345678', 'DE' );
		$order   = wc_create_order();
		$order->add_product( $product, 3 );
		$order->set_shipping_country( 'CH' );
		$order->calculate_totals();
		$order->save();

		$items = $this->sync_order( $order )->getItems();

		$this->assertNotEmpty( $items, 'Expected the order to contain an item.' );
		$this->assertEquals( 3, $items[0]->getQuantity() );
		$this->assertEquals(
			20.0,
			$items[0]->getPrice(),
			'The unit price belongs here, not the 60.00 line subtotal.',
			0.0001
		);
	}

	/**
	 * The customs invoice declares the freight, so the order must carry the shipping total as its
	 * shipping cost. Left unset, the core falls back to the order total - which double-counts the goods
	 * already itemised on the invoice and inflates every duty computed from it (C8).
	 */
	public function test_the_order_carries_the_freight_as_its_shipping_cost() {
		$product = $this->create_product( '12345678', 'DE' );
		$order   = $this->create_order( $product );

		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_title( 'UPS - 3 DAYS delivery' );
		$shipping->set_total( 7.90 );
		$order->add_item( $shipping );
		$order->calculate_totals();
		$order->save();

		$core_order = $this->sync_order( $order );

		$this->assertEquals(
			7.90,
			$core_order->getShippingCost(),
			'The freight, not the order value, is what the customs invoice declares.',
			0.0001
		);
		$this->assertNotEquals(
			(float) $core_order->getTotalPrice(),
			(float) $core_order->getShippingCost(),
			'A shipping cost equal to the order total is the C8 double-count the fallback causes.'
		);
	}

	/**
	 * A product without customs meta leaves the item tariff/country empty (core applies defaults later).
	 */
	public function test_unmapped_product_leaves_item_customs_empty() {
		$product = $this->create_product();
		$order   = $this->create_order( $product );

		$core_order = $this->sync_order( $order );

		$items = $core_order->getItems();
		$this->assertNotEmpty( $items );
		$this->assertEmpty( $items[0]->getTariffNumber(), 'Unmapped product should leave item tariff empty.' );
		$this->assertEmpty( $items[0]->getCountryOfOrigin() );
	}

	/**
	 * A tariff mapping pointing at a global attribute taxonomy (`attr:pa_*`) resolves through
	 * get_attribute and wins over the dedicated HS-code meta.
	 */
	public function test_tariff_resolved_from_mapped_global_attribute() {
		$product  = $this->create_product( '99999999' );
		$taxonomy = $this->attach_global_attribute( $product, 'hs_class', '620342' );
		$this->set_mapping( array( 'mappingTariffNumber' => Customs_Mapping_Service::PREFIX_ATTRIBUTE . $taxonomy ) );

		$core_order = $this->sync_order( $this->create_order( $product ) );

		$items = $core_order->getItems();
		$this->assertSame( '620342', $items[0]->getTariffNumber() );
	}

	/**
	 * A tariff mapping pointing at a per-product custom attribute name (`attr:{name}`) resolves
	 * through get_attribute and is not misread as a meta key.
	 */
	public function test_tariff_resolved_from_mapped_custom_attribute_name() {
		$product = $this->create_product();
		$this->attach_custom_attribute( $product, 'HS Code', '840733' );
		$this->set_mapping( array( 'mappingTariffNumber' => Customs_Mapping_Service::PREFIX_ATTRIBUTE . 'HS Code' ) );

		$core_order = $this->sync_order( $this->create_order( $product ) );

		$items = $core_order->getItems();
		$this->assertSame( '840733', $items[0]->getTariffNumber() );
	}

	/**
	 * A tariff mapping pointing at an arbitrary product meta (`meta:{key}`) resolves through
	 * product meta.
	 */
	public function test_tariff_resolved_from_mapped_product_meta() {
		$product = $this->create_product();
		$product->update_meta_data( '_custom_hs_field', '392620' );
		$product->save();
		$this->set_mapping( array( 'mappingTariffNumber' => Customs_Mapping_Service::PREFIX_PRODUCT_META . '_custom_hs_field' ) );

		$core_order = $this->sync_order( $this->create_order( $product ) );

		$items = $core_order->getItems();
		$this->assertSame( '392620', $items[0]->getTariffNumber() );
	}

	/**
	 * A legacy un-namespaced `pa_*` mapping value (saved before the namespace change) still
	 * resolves as a global attribute.
	 */
	public function test_legacy_unprefixed_attribute_mapping_still_resolves() {
		$product  = $this->create_product();
		$taxonomy = $this->attach_global_attribute( $product, 'hs_legacy', '610910' );
		$this->set_mapping( array( 'mappingTariffNumber' => $taxonomy ) );

		$core_order = $this->sync_order( $this->create_order( $product ) );

		$items = $core_order->getItems();
		$this->assertSame( '610910', $items[0]->getTariffNumber() );
	}

	/**
	 * A country-of-origin mapping resolves the mapped product field before the dedicated meta.
	 */
	public function test_country_of_origin_prefers_mapped_field_over_dedicated_meta() {
		$product = $this->create_product( '', 'DE' );
		$product->update_meta_data( '_custom_origin_field', 'FR' );
		$product->save();
		$this->set_mapping( array( 'mappingCountryOfOrigin' => Customs_Mapping_Service::PREFIX_PRODUCT_META . '_custom_origin_field' ) );

		$core_order = $this->sync_order( $this->create_order( $product ) );

		$items = $core_order->getItems();
		$this->assertSame( 'FR', $items[0]->getCountryOfOrigin() );
	}

	/**
	 * When the mapped country-of-origin field is empty on the product, the dedicated meta is used.
	 */
	public function test_country_of_origin_falls_back_to_dedicated_meta() {
		$product = $this->create_product( '', 'DE' );
		$this->set_mapping( array( 'mappingCountryOfOrigin' => Customs_Mapping_Service::PREFIX_PRODUCT_META . '_custom_origin_field' ) );

		$core_order = $this->sync_order( $this->create_order( $product ) );

		$items = $core_order->getItems();
		$this->assertSame( 'DE', $items[0]->getCountryOfOrigin() );
	}

	/**
	 * A tax-id mapping pointing at an order meta key (`order:{key}`) resolves from the order, and
	 * the resolved value is set as both the tax id and the VAT number.
	 */
	public function test_tax_id_resolved_from_mapped_order_meta() {
		$product = $this->create_product();
		$order   = $this->create_order( $product );
		$order->update_meta_data( '_vat_number', 'ORD-TAX-1' );
		$order->save();
		$this->set_mapping( array( 'mappingReceiverTaxId' => Customs_Mapping_Service::PREFIX_ORDER_META . '_vat_number' ) );

		$core_order = $this->sync_order( $order );

		$this->assertSame( 'ORD-TAX-1', $core_order->getTaxId() );
		$this->assertSame( $core_order->getTaxId(), $core_order->getVatNumber(), 'The VAT number must carry the same single resolved value as the tax id.' );
	}

	/**
	 * A tax-id mapping pointing at a user meta key (`user:{key}`) resolves from the customer
	 * profile of the order customer, and the resolved value is set as both the tax id and the
	 * VAT number.
	 */
	public function test_tax_id_resolved_from_mapped_user_meta() {
		$product     = $this->create_product();
		$customer_id = $this->create_customer();
		update_user_meta( $customer_id, 'custom_tax_field', 'USR-TAX-1' );
		$order = $this->create_order( $product, $customer_id );
		$this->set_mapping( array( 'mappingReceiverTaxId' => Customs_Mapping_Service::PREFIX_USER_META . 'custom_tax_field' ) );

		$core_order = $this->sync_order( $order );

		$this->assertSame( 'USR-TAX-1', $core_order->getTaxId() );
		$this->assertSame( $core_order->getTaxId(), $core_order->getVatNumber(), 'The VAT number must carry the same single resolved value as the tax id.' );
	}

	/**
	 * With an empty mapping, the dedicated `packlink_tax_id` user meta of the order customer is
	 * used for both the tax id and the VAT number.
	 */
	public function test_dedicated_user_meta_used_when_mapping_empty() {
		$product     = $this->create_product();
		$customer_id = $this->create_customer( 'TAX-789' );
		$order       = $this->create_order( $product, $customer_id );
		$this->set_mapping( array( 'mappingReceiverTaxId' => '' ) );

		$core_order = $this->sync_order( $order );

		$this->assertSame( 'TAX-789', $core_order->getTaxId() );
		$this->assertSame( 'TAX-789', $core_order->getVatNumber() );
	}

	/**
	 * A guest order (customer id 0) has no profile: the `user:` mapped step and the dedicated
	 * user-meta fallback both resolve to empty without notices.
	 */
	public function test_guest_order_resolves_empty_tax_id_and_vat() {
		$product = $this->create_product();
		$order   = $this->create_order( $product );

		$core_order = $this->sync_order( $order );

		$this->assertSame( 0, $order->get_customer_id(), 'Expected a guest order.' );
		$this->assertEmpty( $core_order->getTaxId(), 'Guest order must resolve an empty tax id.' );
		$this->assertEmpty( $core_order->getVatNumber(), 'Guest order must resolve an empty VAT number.' );
	}
}
