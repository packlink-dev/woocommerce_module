<?php
/**
 * Tests that the cart-side order factory builds a core Order from a WooCommerce shipping package
 * with the same customs resolution the shipment draft uses (WC-T2). The DDP cost call happens at the
 * shipping step, where no WC_Order exists yet, so the estimate has to be assembled from the cart -
 * and it has to agree with the customs invoice created later from the placed order.
 *
 * @package Packlink_Pro_Shipping
 */

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\WooCommerce\Components\Customs\Customs_Handler;
use Packlink\WooCommerce\Components\Order\Cart_Order_Factory;
use Packlink\WooCommerce\Components\Services\Config_Service;
use Packlink\WooCommerce\Components\Services\Customs_Mapping_Service;
use Packlink\WooCommerce\Components\Utility\Database;

/**
 * Class CartOrderFactoryTest
 *
 * @package Packlink_Pro_Shipping
 */
class CartOrderFactoryTest extends WP_UnitTestCase {

	/**
	 * Install the entity table and seed the default customs mapping (maps to the dedicated fields).
	 */
	public function setUp() {
		parent::setUp();
		global $wpdb;
		$database = new Database( $wpdb );
		$database->install();
		Customs_Handler::seed_default_customs_mapping();
		WC()->customer = new WC_Customer();
	}

	/**
	 * Drop the entity table.
	 */
	public function tearDown() {
		WC()->customer = null;
		global $wpdb;
		$database = new Database( $wpdb );
		$database->uninstall();
		parent::tearDown();
	}

	/**
	 * Overrides fields of the saved customs mapping.
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
	}

	/**
	 * Creates a product, optionally virtual and optionally with the dedicated customs metas.
	 *
	 * @param array $args Overrides: virtual, weight, hs_code, country, price.
	 *
	 * @return WC_Product_Simple
	 */
	private function create_product( array $args = array() ) {
		$args = array_merge(
			array(
				'virtual' => false,
				'weight'  => 2,
				'price'   => 20,
				'hs_code' => '',
				'country' => '',
				'name'    => 'Cart Test Product',
				'sku'     => '',
			),
			$args
		);

		$product = new WC_Product_Simple();
		$product->set_name( $args['name'] );
		$product->set_regular_price( $args['price'] );
		$product->set_weight( $args['weight'] );
		$product->set_height( 3 );
		$product->set_width( 4 );
		$product->set_length( 5 );
		$product->set_description( 'A shippable thing.' );
		$product->set_virtual( (bool) $args['virtual'] );
		if ( '' !== $args['sku'] ) {
			$product->set_sku( $args['sku'] );
		}
		$product->save();

		if ( '' !== $args['hs_code'] ) {
			$product->update_meta_data( Customs_Mapping_Service::PRODUCT_HS_CODE_META, $args['hs_code'] );
		}
		if ( '' !== $args['country'] ) {
			$product->update_meta_data( Customs_Mapping_Service::PRODUCT_COUNTRY_OF_ORIGIN_META, $args['country'] );
		}
		$product->save();

		return $product;
	}

	/**
	 * Builds one shipping-package content row, shaped as WooCommerce shapes cart lines.
	 *
	 * @param WC_Product $product  Line product.
	 * @param int        $quantity Line quantity.
	 * @param float      $total    Line total.
	 *
	 * @return array
	 */
	private function content_row( $product, $quantity = 1, $total = 20.0 ) {
		return array(
			'product_id'    => $product->get_id(),
			'variation_id'  => 0,
			'quantity'      => $quantity,
			'data'          => $product,
			'line_total'    => $total,
			'line_subtotal' => $total,
		);
	}

	/**
	 * Builds a shipping package with the given content rows and destination.
	 *
	 * @param array $contents    Content rows.
	 * @param array $destination Destination overrides.
	 *
	 * @return array
	 */
	private function package( array $contents, array $destination = array() ) {
		return array(
			'contents'      => $contents,
			'cart_subtotal' => 40.0,
			'destination'   => array_merge(
				array(
					'country'   => 'CH',
					'postcode'  => '3011',
					'city'      => 'Bern',
					'address_1' => 'Kramgasse 49',
					'address_2' => '',
				),
				$destination
			),
		);
	}

	/**
	 * Virtual cart lines are not shipped, so they carry no customs value and must not appear as
	 * items - the same rule get_order_items() applies on the order side.
	 */
	public function test_virtual_lines_are_skipped() {
		$shippable = $this->create_product( array( 'sku' => 'SHIP-1' ) );
		$virtual   = $this->create_product( array( 'virtual' => true, 'name' => 'Downloadable' ) );

		$order = Cart_Order_Factory::from_package(
			$this->package(
				array(
					'a' => $this->content_row( $shippable ),
					'b' => $this->content_row( $virtual ),
				)
			)
		);

		$this->assertNotNull( $order );
		$items = $order->getItems();
		$this->assertCount( 1, $items, 'Only the shippable line may become an item.' );
		$this->assertSame( 'SHIP-1', $items[0]->getSku() );
	}

	/**
	 * Every item field the draft path populates is populated here too, from the same sources.
	 */
	public function test_item_is_populated_like_the_order_path() {
		$product = $this->create_product( array( 'sku' => 'SKU-9', 'hs_code' => '12345678', 'country' => 'DE' ) );

		$order = Cart_Order_Factory::from_package(
			$this->package( array( 'a' => $this->content_row( $product, 2, 40.0 ) ) )
		);

		$items = $order->getItems();
		$this->assertCount( 1, $items );
		$item = $items[0];
		$this->assertSame( 2, $item->getQuantity() );
		$this->assertSame( $product->get_id(), $item->getId() );
		$this->assertSame( 40.0, $item->getTotalPrice() );
		$this->assertSame( 40.0, $item->getPrice() );
		$this->assertSame( 'SKU-9', $item->getSku() );
		$this->assertSame( 'Cart Test Product', $item->getTitle() );
		$this->assertSame( 'A shippable thing.', $item->getConcept() );
		$this->assertSame( 2.0, $item->getWeight() );
		$this->assertSame( 3.0, $item->getHeight() );
		$this->assertSame( 4.0, $item->getWidth() );
		$this->assertSame( 5.0, $item->getLength() );
		$this->assertSame( '12345678', $item->getTariffNumber() );
		$this->assertSame( 'DE', $item->getCountryOfOrigin() );
	}

	/**
	 * Packlink rejects a customs invoice whose parcels_weight is 0, so the order-level total weight
	 * has to be the sum of unit weight times quantity over every line.
	 */
	public function test_total_weight_is_summed_by_quantity() {
		$first  = $this->create_product( array( 'weight' => 2 ) );
		$second = $this->create_product( array( 'weight' => 0.5 ) );

		$order = Cart_Order_Factory::from_package(
			$this->package(
				array(
					'a' => $this->content_row( $first, 3 ),
					'b' => $this->content_row( $second, 2 ),
				)
			)
		);

		$this->assertSame( 7.0, $order->getTotalWeight() );
	}

	/**
	 * A tariff mapping pointing at an arbitrary product meta resolves through the shared customs
	 * resolver, exactly as it does when the shipment draft is built.
	 */
	public function test_tariff_number_resolved_through_the_mapping() {
		$product = $this->create_product( array( 'hs_code' => '99999999' ) );
		$product->update_meta_data( '_custom_hs_field', '392620' );
		$product->save();
		$this->set_mapping( array( 'mappingTariffNumber' => Customs_Mapping_Service::PREFIX_PRODUCT_META . '_custom_hs_field' ) );

		$order = Cart_Order_Factory::from_package(
			$this->package( array( 'a' => $this->content_row( $product ) ) )
		);

		$items = $order->getItems();
		$this->assertSame( '392620', $items[0]->getTariffNumber() );
	}

	/**
	 * The shipping fields of the customer win when they carry a street.
	 */
	public function test_shipping_address_is_taken_from_the_customer_shipping_fields() {
		$product  = $this->create_product();
		$customer = WC()->customer;
		$customer->set_shipping_first_name( 'Jane' );
		$customer->set_shipping_last_name( 'Doe' );
		$customer->set_shipping_address_1( 'Kramgasse 49' );
		$customer->set_shipping_city( 'Bern' );
		$customer->set_shipping_postcode( '3011' );
		$customer->set_shipping_country( 'CH' );
		$customer->set_billing_first_name( 'Billing' );
		$customer->set_billing_phone( '+41 31 000 00 00' );
		$customer->set_billing_email( 'jane@example.com' );

		$order = Cart_Order_Factory::from_package(
			$this->package( array( 'a' => $this->content_row( $product ) ) )
		);

		$address = $order->getShippingAddress();
		$this->assertSame( 'Jane', $address->getName() );
		$this->assertSame( 'Doe', $address->getSurname() );
		$this->assertSame( 'Kramgasse 49', $address->getStreet1() );
		$this->assertSame( 'CH', $address->getCountry() );
		$this->assertSame( '3011', $address->getZipCode() );
		$this->assertSame( '+41 31 000 00 00', $address->getPhone(), 'The phone falls back to billing, as on the order side.' );
		$this->assertSame( 'jane@example.com', $address->getEmail() );
	}

	/**
	 * With no shipping street the billing fields are used wholesale - the same fallback rule
	 * Shop_Order_Service::get_shipping_address() applies.
	 */
	public function test_billing_address_is_used_when_the_shipping_street_is_empty() {
		$product  = $this->create_product();
		$customer = WC()->customer;
		$customer->set_billing_first_name( 'Bill' );
		$customer->set_billing_last_name( 'Payer' );
		$customer->set_billing_address_1( 'Calle de Atocha 52' );
		$customer->set_billing_city( 'Madrid' );
		$customer->set_billing_postcode( '28012' );
		$customer->set_billing_country( 'ES' );
		$customer->set_billing_phone( '+34 900 000 000' );

		$order = Cart_Order_Factory::from_package(
			$this->package( array( 'a' => $this->content_row( $product ) ) )
		);

		$address = $order->getShippingAddress();
		$this->assertSame( 'Bill', $address->getName() );
		$this->assertSame( 'Calle de Atocha 52', $address->getStreet1() );
		$this->assertSame( '+34 900 000 000', $address->getPhone() );
		$this->assertSame( 'CH', $address->getCountry(), 'The package destination is what the rates were priced on and wins over the billing country.' );
		$this->assertSame( '3011', $address->getZipCode() );
	}

	/**
	 * Without a destination country there is no route to price, so there is nothing to ask Packlink
	 * about.
	 */
	public function test_returns_null_when_the_destination_country_is_missing() {
		$product = $this->create_product();

		$order = Cart_Order_Factory::from_package(
			$this->package( array( 'a' => $this->content_row( $product ) ), array( 'country' => '' ) )
		);

		$this->assertNull( $order );
	}

	/**
	 * The same holds for a missing postcode.
	 */
	public function test_returns_null_when_the_destination_postcode_is_missing() {
		$product = $this->create_product();

		$order = Cart_Order_Factory::from_package(
			$this->package( array( 'a' => $this->content_row( $product ) ), array( 'postcode' => '' ) )
		);

		$this->assertNull( $order );
	}

	/**
	 * The customs value is goods plus freight, so the shipment cost carried on the order must be the
	 * transport price when the caller knows it, and stay unset when it does not.
	 */
	public function test_shipping_cost_is_set_only_when_provided() {
		$product = $this->create_product();
		$package = $this->package( array( 'a' => $this->content_row( $product ) ) );

		$without = Cart_Order_Factory::from_package( $package );
		$with    = Cart_Order_Factory::from_package( $package, 7.45 );

		$this->assertNull( $without->getShippingCost() );
		$this->assertSame( 7.45, $with->getShippingCost() );
	}

	/**
	 * The dedicated tax-ID user meta of the logged-in customer feeds both customs attributes, as on
	 * the order side.
	 */
	public function test_tax_id_resolved_from_the_logged_in_customer() {
		$user_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		update_user_meta( $user_id, Customs_Mapping_Service::USER_TAX_ID_META, 'TAX-123' );
		wp_set_current_user( $user_id );

		$product = $this->create_product();

		$order = Cart_Order_Factory::from_package(
			$this->package( array( 'a' => $this->content_row( $product ) ) )
		);

		$this->assertSame( $user_id, $order->getCustomerId() );
		$this->assertSame( 'TAX-123', $order->getTaxId() );
		$this->assertSame( 'TAX-123', $order->getVatNumber() );

		wp_set_current_user( 0 );
	}

	/**
	 * A guest has no profile to read a tax id from, and that is not an error at the shipping step.
	 */
	public function test_guest_resolves_an_empty_tax_id() {
		$product = $this->create_product();

		$order = Cart_Order_Factory::from_package(
			$this->package( array( 'a' => $this->content_row( $product ) ) )
		);

		$this->assertSame( 0, $order->getCustomerId() );
		$this->assertEmpty( $order->getTaxId() );
		$this->assertEmpty( $order->getVatNumber() );
	}

	/**
	 * Currency and the declared cart value come from the store and from the package the rates were
	 * priced on.
	 */
	public function test_currency_and_declared_value() {
		$product = $this->create_product();

		$order = Cart_Order_Factory::from_package(
			$this->package( array( 'a' => $this->content_row( $product ) ) )
		);

		$this->assertSame( get_woocommerce_currency(), $order->getCurrency() );
		$this->assertSame( 40.0, $order->getTotalPrice() );
		$this->assertSame( 40.0, $order->getBasePrice() );
	}

	/**
	 * A duty estimate is optional; an exception raised while assembling it would take the whole
	 * shipping-rate calculation down with it. A malformed package must degrade, never throw.
	 */
	public function test_a_malformed_package_does_not_throw() {
		$order = Cart_Order_Factory::from_package(
			array(
				'contents'    => array( 'a' => array( 'quantity' => 1 ) ),
				'destination' => array( 'country' => 'CH', 'postcode' => '3011' ),
			)
		);

		$this->assertNotNull( $order, 'A package with an unusable line still yields an order.' );
		$this->assertCount( 0, $order->getItems() );
	}

	/**
	 * An empty package is not a failure either - it simply has nothing to declare.
	 */
	public function test_an_empty_package_yields_an_order_without_items() {
		$order = Cart_Order_Factory::from_package( $this->package( array() ) );

		$this->assertNotNull( $order );
		$this->assertCount( 0, $order->getItems() );
		$this->assertSame( 0.0, $order->getTotalWeight() );
	}
}
