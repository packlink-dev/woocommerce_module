<?php
/**
 * Tests the payload the block checkout hands to `packlink-block-checkout.js` (WC-T10): it is keyed by
 * shipping rate id rather than by shipping-method instance id, and a duties-paid rate carries the
 * suffix and the combined transport + duties price alongside the fields the carrier logo, the drop-off
 * picker and the cash-on-delivery message have always used.
 *
 * Only the server side is asserted here. The DOM half - appending the suffix to the row label and
 * replacing the rendered price, both idempotently - lives in `packlink-block-checkout.js` and is
 * verified manually on the block checkout rather than faked here.
 *
 * @package Packlink_Pro_Shipping
 */

use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\WooCommerce\Components\Checkout\Block_Checkout_Handler;
use Packlink\WooCommerce\Components\Checkout\Ddp_Checkout;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Helper;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Map;
use Packlink\WooCommerce\Components\Utility\Database;

/**
 * Class BlockCheckoutDdpTest
 *
 * @package Packlink_Pro_Shipping
 */
class BlockCheckoutDdpTest extends WP_UnitTestCase {

	/**
	 * WooCommerce shipping method instance id used throughout.
	 */
	const INSTANCE_ID = 12;

	/**
	 * Base rate id.
	 */
	const BASE_ID = 'packlink_shipping_method:12';

	/**
	 * DDP rate id - the same instance, a third segment.
	 */
	const DDP_ID = 'packlink_shipping_method:12:ddp';

	/**
	 * Transport cost of the rates under test.
	 */
	const COST = 31.84;

	/**
	 * Duty amount quoted for the duties-paid rate.
	 */
	const DUTY = 24.51;

	/**
	 * Packlink shipping method persisted for this test.
	 *
	 * @var ShippingMethod
	 */
	private $method;

	/**
	 * Installs the entity table and maps a Packlink method onto the WooCommerce instance.
	 */
	public function setUp() {
		parent::setUp();
		global $wpdb;
		$database = new Database( $wpdb );
		$database->install();

		$this->method = $this->map_method();

		if ( null === WC()->customer ) {
			// WooCommerce only builds the customer on a frontend request. Never save() it - a guest
			// customer written to the database poisons later tests in a full-suite run.
			WC()->customer = new \WC_Customer();
		}
	}

	/**
	 * Drops the entity table.
	 */
	public function tearDown() {
		WC()->session->set( 'chosen_shipping_methods', array() );
		WC()->session->set( 'shipping_for_package_0', null );
		WC()->session->set( Shipping_Method_Helper::DROP_OFF_ID, '' );
		WC()->session->set( Shipping_Method_Helper::DROP_OFF_EXTRA, array() );
		global $wpdb;
		$database = new Database( $wpdb );
		$database->uninstall();
		parent::tearDown();
	}

	/**
	 * One instance renders two rows, so the payload is keyed by rate id: the key of every entry is
	 * exactly the `value` of that row's radio input, which is what the JavaScript selects on.
	 */
	public function test_method_details_are_keyed_by_rate_id() {
		$this->seed_rates( self::DUTY );

		$details = $this->details( array( self::BASE_ID, self::DDP_ID ) );

		$this->assertSame( array( self::BASE_ID, self::DDP_ID ), array_keys( $details ) );
	}

	/**
	 * A duties-paid entry is flagged, carries the suffix, and carries one combined price.
	 */
	public function test_the_ddp_entry_carries_the_flag_the_suffix_and_the_combined_price() {
		$this->seed_rates( self::DUTY );

		$entry = $this->details( array( self::BASE_ID, self::DDP_ID ) )[ self::DDP_ID ];

		$this->assertTrue( $entry['packlink_is_ddp'] );
		$this->assertSame( '- Delivery Duty Paid', $entry['packlink_ddp_suffix'] );
		$this->assertSame(
			$this->formatted( self::COST + self::DUTY ),
			$entry['packlink_ddp_total'],
			'The row shows transport plus duties as a single figure.'
		);
	}

	/**
	 * The combined figure is the sum, not either component on its own.
	 */
	public function test_the_combined_price_is_neither_component_alone() {
		$this->seed_rates( self::DUTY );

		$total = $this->details( array( self::DDP_ID ) )[ self::DDP_ID ]['packlink_ddp_total'];

		$this->assertNotSame( $this->formatted( self::COST ), $total );
		$this->assertNotSame( $this->formatted( self::DUTY ), $total );
		$this->assertContains( '56.35', $total );
	}

	/**
	 * The plain sibling of a duties-paid rate is left undecorated, so it keeps showing the transport
	 * price alone.
	 */
	public function test_the_plain_entry_carries_no_ddp_data() {
		$this->seed_rates( self::DUTY );

		$entry = $this->details( array( self::BASE_ID, self::DDP_ID ) )[ self::BASE_ID ];

		$this->assertFalse( $entry['packlink_is_ddp'] );
		$this->assertSame( '', $entry['packlink_ddp_total'] );
	}

	/**
	 * With no amount on the cached rate and nothing cached for the cart, no duties are presented -
	 * the row would otherwise promise a duties-paid service while the cart fee charges nothing.
	 */
	public function test_no_ddp_data_when_the_cached_rate_carries_no_amount() {
		$this->seed_rates( null );

		$entry = $this->details( array( self::DDP_ID ) )[ self::DDP_ID ];

		$this->assertFalse( $entry['packlink_is_ddp'] );
		$this->assertSame( '', $entry['packlink_ddp_total'] );
	}

	/**
	 * Both rows of one instance resolve to the same Packlink method, so the carrier logo, the drop-off
	 * flag and locations, and the cash-on-delivery message and fee come out identical on both. This is
	 * the regression guard for the three features that share this payload with DDP.
	 */
	public function test_both_rows_carry_the_same_logo_drop_off_and_cash_on_delivery_data() {
		$this->seed_rates( self::DUTY );

		$details = $this->details( array( self::BASE_ID, self::DDP_ID ) );
		$shared  = array(
			'packlink_image_url',
			'packlink_show_image',
			'packlink_is_drop_off',
			'packlink_drop_off_locations',
			'packlink_cash_on_delivery',
			'packlink_cash_on_delivery_fee',
		);

		foreach ( $shared as $field ) {
			$this->assertArrayHasKey( $field, $details[ self::BASE_ID ] );
			$this->assertArrayHasKey( $field, $details[ self::DDP_ID ] );
			$this->assertSame(
				$details[ self::BASE_ID ][ $field ],
				$details[ self::DDP_ID ][ $field ],
				'"' . $field . '" must not depend on the DDP suffix.'
			);
		}

		$this->assertContains( 'box.svg', $details[ self::DDP_ID ]['packlink_image_url'] );
	}

	/**
	 * A rate id of an instance no Packlink method is mapped to yields an empty entry, exactly as an
	 * unmapped instance id did before the rekeying.
	 */
	public function test_an_unmapped_rate_yields_an_empty_entry() {
		$this->seed_rates( self::DUTY );

		$details = $this->details( array( 'packlink_shipping_method:99:ddp' ) );

		$this->assertSame( array(), $details['packlink_shipping_method:99:ddp'] );
	}

	/**
	 * With no payload the handler falls back to the chosen rate, and keys that entry by its rate id
	 * too - the single-shipping-method render, where the JavaScript sends no ids at all.
	 */
	public function test_the_chosen_rate_is_used_when_the_payload_is_empty() {
		$this->seed_rates( self::DUTY );
		WC()->session->set( 'chosen_shipping_methods', array( self::DDP_ID ) );

		$response = ( new Block_Checkout_Handler() )->initialize( array() );

		$this->assertSame( array( self::DDP_ID ), array_keys( $response['method_details'] ) );
		$this->assertTrue( $response['method_details'][ self::DDP_ID ]['packlink_is_ddp'] );
		$this->assertSame( self::DDP_ID, $response['selected_shipping_method'] );
	}

	/**
	 * Drop-off regression: the instance id is parsed out of the chosen rate id, so choosing the
	 * duties-paid variant of a drop-off service still recognises it as one and still saves the point.
	 */
	public function test_the_drop_off_point_is_saved_when_a_duties_paid_rate_is_chosen() {
		$this->make_drop_off();
		WC()->session->set( 'chosen_shipping_methods', array( self::DDP_ID ) );
		WC()->session->set( Shipping_Method_Helper::DROP_OFF_ID, '42' );
		WC()->session->set(
			Shipping_Method_Helper::DROP_OFF_EXTRA,
			array(
				'name'    => 'Kiosk',
				'city'    => 'Madrid',
				'zip'     => '28001',
				'address' => 'Gran Via 1',
			)
		);

		$order = wc_create_order();
		( new Block_Checkout_Handler() )->checkout_update_drop_off( $order );

		$map = Shipping_Method_Helper::get_drop_off_map_for_order( $order->get_id() );
		$this->assertNotNull( $map, 'A duties-paid drop-off rate must still record the chosen point.' );
		$this->assertSame( '42', (string) $map->get_drop_off_point_id() );
		$this->assertSame( 'Kiosk', $order->get_shipping_company() );
	}

	/**
	 * The plain rate of the same drop-off service behaves identically - the rekeying changed which
	 * segment is parsed, not the behaviour.
	 */
	public function test_the_drop_off_point_is_saved_when_the_plain_rate_is_chosen() {
		$this->make_drop_off();
		WC()->session->set( 'chosen_shipping_methods', array( self::BASE_ID ) );
		WC()->session->set( Shipping_Method_Helper::DROP_OFF_ID, '43' );
		WC()->session->set(
			Shipping_Method_Helper::DROP_OFF_EXTRA,
			array(
				'name'    => 'Kiosk',
				'city'    => 'Madrid',
				'zip'     => '28001',
				'address' => 'Gran Via 1',
			)
		);

		$order = wc_create_order();
		( new Block_Checkout_Handler() )->checkout_update_drop_off( $order );

		$map = Shipping_Method_Helper::get_drop_off_map_for_order( $order->get_id() );
		$this->assertNotNull( $map );
		$this->assertSame( '43', (string) $map->get_drop_off_point_id() );
	}

	/**
	 * Runs the handler and returns just the rate details.
	 *
	 * @param array $payload Rate ids as the JavaScript sends them.
	 *
	 * @return array
	 */
	private function details( array $payload ) {
		return ( new Block_Checkout_Handler() )->initialize( $payload )['method_details'];
	}

	/**
	 * Seeds WooCommerce's cached rate set with the plain rate and its duties-paid sibling, exactly as
	 * `WC_Shipping::calculate_shipping_for_package()` stores it.
	 *
	 * @param float|null $amount Duty amount to attach to the duties-paid rate, or null for none.
	 */
	private function seed_rates( $amount ) {
		WC()->cart->empty_cart();

		$base = new \WC_Shipping_Rate( self::BASE_ID, 'UPS - 3 DAYS delivery', self::COST );
		$ddp  = new \WC_Shipping_Rate( self::DDP_ID, 'UPS - 3 DAYS delivery', self::COST );

		if ( null !== $amount ) {
			$ddp->add_meta_data( Ddp_Checkout::RATE_META_AMOUNT, $amount );
		}

		WC()->session->set(
			'shipping_for_package_0',
			array(
				'package_hash' => 'test',
				'rates'        => array(
					self::BASE_ID => $base,
					self::DDP_ID  => $ddp,
				),
			)
		);
	}

	/**
	 * The shop's own formatting of an amount, exactly as the payload carries it: de-tagged and with
	 * entities decoded, because it travels as JSON and is written into the DOM as text.
	 *
	 * @param float $amount Amount to format.
	 *
	 * @return string
	 */
	private function formatted( $amount ) {
		return html_entity_decode(
			wp_strip_all_tags( wc_price( $amount, array( 'currency' => get_woocommerce_currency() ) ) ),
			ENT_QUOTES,
			'UTF-8'
		);
	}

	/**
	 * Turns the mapped method into a drop-off one, without adding a second map for the instance.
	 */
	private function make_drop_off() {
		$this->method->setDestinationDropOff( true );
		RepositoryRegistry::getRepository( ShippingMethod::CLASS_NAME )->update( $this->method );
	}

	/**
	 * Persists a Packlink shipping method and maps it onto the WooCommerce instance id.
	 *
	 * @return ShippingMethod
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

		return $method;
	}
}
