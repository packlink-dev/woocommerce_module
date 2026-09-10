<?php
/**
 * Tests the data the classic checkout hands to the shipping-option row for a duties-paid rate (WC-T9).
 *
 * Only the server side is asserted here: `after_shipping_rate()` echoes the hidden inputs, and
 * `packlink-checkout.js` turns them into the visible suffix and the combined price. The DOM half is
 * verified manually on the storefront - see the browser checks in the task - rather than faked here.
 *
 * @package Packlink_Pro_Shipping
 */

use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\WooCommerce\Components\Checkout\Checkout_Handler;
use Packlink\WooCommerce\Components\Checkout\Ddp_Checkout;
use Packlink\WooCommerce\Components\ShippingMethod\Packlink_Shipping_Method;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Map;
use Packlink\WooCommerce\Components\Utility\Database;

/**
 * Class DdpOptionRowTest
 *
 * @package Packlink_Pro_Shipping
 */
class DdpOptionRowTest extends WP_UnitTestCase {

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
	 * Transport cost of the rate under test.
	 */
	const COST = 31.84;

	/**
	 * Duty amount quoted for the rate under test.
	 */
	const DUTY = 24.51;

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
		global $wpdb;
		$database = new Database( $wpdb );
		$database->uninstall();
		parent::tearDown();
	}

	/**
	 * The duties-paid row is flagged, and carries the suffix and one combined price.
	 */
	public function test_the_ddp_row_carries_the_flag_the_suffix_and_the_combined_price() {
		$markup = $this->render( self::DDP_ID, self::DUTY );

		$this->assertSame( 'yes', $this->hidden_value( $markup, 'packlink_is_ddp' ) );
		$this->assertSame( '- Delivery Duty Paid', $this->hidden_value( $markup, 'packlink_ddp_suffix' ) );
		$this->assertSame(
			$this->formatted( self::COST + self::DUTY ),
			$this->price_shown( $markup ),
			'The row shows transport plus duties as a single figure.'
		);
	}

	/**
	 * The combined figure is the sum, not either component on its own.
	 */
	public function test_the_combined_price_is_neither_component_alone() {
		$total = $this->price_shown( $this->render( self::DDP_ID, self::DUTY ) );

		$this->assertNotSame( $this->formatted( self::COST ), $total );
		$this->assertNotSame( $this->formatted( self::DUTY ), $total );
		$this->assertContains( '56.35', $total );
	}

	/**
	 * The plain row is left undecorated, so it keeps showing the transport price alone.
	 */
	public function test_the_plain_row_is_not_decorated() {
		$markup = $this->render( self::BASE_ID, self::DUTY );

		$this->assertSame( 'no', $this->hidden_value( $markup, 'packlink_is_ddp' ) );
		$this->assertSame( '', $this->hidden_value( $markup, 'packlink_ddp_total' ) );
	}

	/**
	 * A duties-paid rate id is not enough to decorate the row: with no amount on the rate, nothing is
	 * charged, so the row must keep WooCommerce's own title and price rather than be labelled with
	 * duties nobody pays. The block checkout decides this the same way.
	 */
	public function test_a_ddp_rate_without_an_amount_is_not_decorated() {
		$markup = $this->render( self::DDP_ID, null );

		$this->assertSame( 'no', $this->hidden_value( $markup, 'packlink_is_ddp' ) );
		$this->assertSame( '', $this->hidden_value( $markup, 'packlink_ddp_total' ) );
	}

	/**
	 * A duty absorbed down to zero is an amount, not a missing one: the row is labelled duties-paid and
	 * shows the transport price. The block checkout decides this the same way.
	 */
	public function test_a_ddp_rate_with_an_absorbed_duty_is_decorated() {
		$markup = $this->render( self::DDP_ID, 0.0 );

		$this->assertSame( 'yes', $this->hidden_value( $markup, 'packlink_is_ddp' ) );
		$this->assertSame( $this->formatted( self::COST ), $this->price_shown( $markup ) );
	}

	/**
	 * The decoration rides alongside the carrier logo and drop-off inputs, not instead of them.
	 */
	public function test_the_existing_row_inputs_are_still_emitted() {
		$markup = $this->render( self::DDP_ID, self::DUTY );

		$this->assertNotNull( $this->hidden_value( $markup, 'packlink_show_image' ) );
		$this->assertNotNull( $this->hidden_value( $markup, 'packlink_is_drop_off' ) );
		$this->assertNotNull( $this->hidden_value( $markup, 'packlink_cash_on_delivery' ) );
	}

	/**
	 * Captures the markup `after_shipping_rate()` echoes for a rate.
	 *
	 * @param string     $rate_id Rate id to render.
	 * @param float|null $amount Duty amount to attach to the rate, or null for none.
	 *
	 * @return string Echoed markup.
	 */
	private function render( $rate_id, $amount ) {
		WC()->session->set( 'chosen_shipping_methods', array( $rate_id ) );

		$rate = new \WC_Shipping_Rate(
			$rate_id,
			'UPS - 3 DAYS delivery',
			self::COST,
			array(),
			Packlink_Shipping_Method::PACKLINK_SHIPPING_METHOD,
			self::INSTANCE_ID
		);

		if ( null !== $amount ) {
			$rate->add_meta_data( Ddp_Checkout::RATE_META_AMOUNT, $amount );
		}

		ob_start();
		( new Checkout_Handler() )->after_shipping_rate( $rate, 0 );

		return ob_get_clean();
	}

	/**
	 * Reads a hidden input value out of the echoed markup.
	 *
	 * @param string $markup Echoed markup.
	 * @param string $name Input name.
	 *
	 * @return string|null Value, or null when the input was not emitted at all.
	 */
	private function hidden_value( $markup, $name ) {
		$pattern = '/name="' . preg_quote( $name, '/' ) . '" value="([^"]*)"/';

		return preg_match( $pattern, $markup, $matches ) ? $matches[1] : null;
	}

	/**
	 * The price the row ends up showing: the hidden input value as the browser reads it.
	 *
	 * `print_hidden_input()`'s `wp_kses` re-encodes the currency entity (`&#36;` becomes `&#036;`), which
	 * the browser decodes back to the same character, so the comparison is made on decoded text.
	 *
	 * @param string $markup Echoed markup.
	 *
	 * @return string
	 */
	private function price_shown( $markup ) {
		return html_entity_decode( (string) $this->hidden_value( $markup, 'packlink_ddp_total' ) );
	}

	/**
	 * The shop's own formatting of an amount, de-tagged and decoded as the row shows it.
	 *
	 * @param float $amount Amount to format.
	 *
	 * @return string
	 */
	private function formatted( $amount ) {
		return html_entity_decode(
			wp_strip_all_tags( wc_price( $amount, array( 'currency' => get_woocommerce_currency() ) ) )
		);
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
