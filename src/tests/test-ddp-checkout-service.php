<?php
/**
 * Tests the checkout duty lookup: when it refuses to call Packlink at all, what it caches, and that a
 * failure is never allowed to break a shipping-rate calculation (WC-T5).
 *
 * Every lookup is two Packlink requests and the first permanently creates a customs invoice, so the
 * assertions here are mostly about calls NOT being made.
 *
 * @package Packlink_Pro_Shipping
 */

use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\DDP\DdpBehavior;
use Packlink\BusinessLogic\DDP\Interfaces\DdpCostServiceInterface;
use Packlink\BusinessLogic\DDP\Models\DdpCostResponse;
use Packlink\BusinessLogic\Http\DTO\DDP\DdpProductCost;
use Packlink\BusinessLogic\Order\Objects\Order;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingService;
use Packlink\WooCommerce\Components\Checkout\Ddp_Checkout_Service;
use Packlink\WooCommerce\Components\Utility\Database;

/**
 * Counting stand-in for the core duty service.
 */
class Ddp_Cost_Service_Spy implements DdpCostServiceInterface {

	/**
	 * Number of lookups performed.
	 *
	 * @var int
	 */
	public $calls = 0;

	/**
	 * Response to return, or null.
	 *
	 * @var DdpCostResponse|null
	 */
	public $response;

	/**
	 * Exception to throw instead of answering.
	 *
	 * @var \Exception|null
	 */
	public $throw;

	/**
	 * @inheritDoc
	 */
	public function getDdpCosts( Order $order, $serviceId ) {
		$this->calls++;

		if ( null !== $this->throw ) {
			throw $this->throw;
		}

		return $this->response;
	}
}

/**
 * Class DdpCheckoutServiceTest
 *
 * @package Packlink_Pro_Shipping
 */
class DdpCheckoutServiceTest extends WP_UnitTestCase {

	/**
	 * Counting stand-in registered in place of the core service.
	 *
	 * @var Ddp_Cost_Service_Spy
	 */
	private $spy;

	/**
	 * Installs the entity table, seeds a warehouse and swaps in the counting core service.
	 */
	public function setUp() {
		parent::setUp();
		global $wpdb;
		$database = new Database( $wpdb );
		$database->install();

		$this->spy           = new Ddp_Cost_Service_Spy();
		$this->spy->response = $this->response();

		$spy = $this->spy;
		ServiceRegister::registerService(
			DdpCostServiceInterface::CLASS_NAME,
			function () use ( $spy ) {
				return $spy;
			}
		);

		Ddp_Checkout_Service::resetInstance();
		$this->seed_warehouse();

		// WooCommerce only builds the customer on a frontend request, so in a suite run it can be
		// absent. The duty lookup reads the delivery address off it, so give the test one either way.
		if ( null === WC()->customer ) {
			WC()->customer = new \WC_Customer();
		}

		if ( WC()->session ) {
			WC()->session->set( Ddp_Checkout_Service::SESSION_KEY, null );
		}
	}

	/**
	 * Drops the entity table.
	 */
	public function tearDown() {
		Ddp_Checkout_Service::resetInstance();
		global $wpdb;
		$database = new Database( $wpdb );
		$database->uninstall();
		parent::tearDown();
	}

	/**
	 * A domestic cart owes no duty, so no request may be made.
	 */
	public function test_no_lookup_for_a_domestic_route() {
		$this->seed_method( DdpBehavior::OPTIONAL, DdpBehavior::LEVEL_SUPPORTED );

		$amount = $this->service()->amount_for_method( $this->method(), $this->package( 'DE' ) );

		$this->assertNull( $amount );
		$this->assertSame( 0, $this->spy->calls, 'A domestic cart must not trigger a duty lookup.' );
	}

	/**
	 * With no method configured to charge duty there is nothing to quote.
	 */
	public function test_no_lookup_when_no_method_charges_duty() {
		$this->seed_method( DdpBehavior::NONE, DdpBehavior::LEVEL_SUPPORTED );

		$amount = $this->service()->amount_for_method( $this->method(), $this->package() );

		$this->assertNull( $amount );
		$this->assertSame( 0, $this->spy->calls, 'A cart with no duty-charging method must not be quoted.' );
	}

	/**
	 * A cart over the documented invoice line cap is refused before any request is spent, because the
	 * API accepts an over-cap invoice with 201 and leaves nothing to fail soft on.
	 */
	public function test_no_lookup_when_the_cart_exceeds_the_invoice_item_cap() {
		$this->seed_method( DdpBehavior::OPTIONAL, DdpBehavior::LEVEL_SUPPORTED );

		$package = $this->package( 'CH', Ddp_Checkout_Service::MAX_INVOICE_ITEMS + 1 );
		$amount  = $this->service()->amount_for_method( $this->method(), $package );

		$this->assertNull( $amount );
		$this->assertSame( 0, $this->spy->calls, 'An over-cap cart must not be quoted.' );
	}

	/**
	 * A delivery address without a phone number cannot produce a valid customs invoice.
	 */
	public function test_no_lookup_without_a_delivery_phone_number() {
		$this->seed_method( DdpBehavior::OPTIONAL, DdpBehavior::LEVEL_SUPPORTED );

		$amount = $this->service()->amount_for_method( $this->method(), $this->package( 'CH', 1, '8001', '' ) );

		$this->assertNull( $amount );
		$this->assertSame( 0, $this->spy->calls, 'A phone-less address must not be quoted.' );
	}

	/**
	 * One lookup serves every call in a request, however many methods ask.
	 */
	public function test_one_lookup_serves_the_whole_request() {
		$this->seed_method( DdpBehavior::OPTIONAL, DdpBehavior::LEVEL_SUPPORTED );
		$service = $this->service();
		$package = $this->package();

		$service->amount_for_method( $this->method(), $package );
		$service->amount_for_method( $this->method(), $package );
		$service->amount_for_method( $this->method(), $package );

		$this->assertSame( 1, $this->spy->calls, 'Repeated asks in one request must reuse the lookup.' );
	}

	/**
	 * A changed destination is a different quote and must be looked up afresh.
	 */
	public function test_a_changed_destination_is_quoted_again() {
		$this->seed_method( DdpBehavior::OPTIONAL, DdpBehavior::LEVEL_SUPPORTED );
		$service = $this->service();

		$service->amount_for_method( $this->method(), $this->package( 'CH', 1, '8001' ) );
		$service->amount_for_method( $this->method(), $this->package( 'CH', 1, '3000' ) );

		$this->assertSame( 2, $this->spy->calls, 'A new destination must be quoted again.' );
	}

	/**
	 * The non-fetching accessor never spends a request, and answers from the cache the rate path left.
	 */
	public function test_the_cached_accessor_never_performs_a_lookup() {
		$this->seed_method( DdpBehavior::OPTIONAL, DdpBehavior::LEVEL_SUPPORTED );
		$package = $this->package();

		$cold = $this->service()->cached_amount_for_method( $this->method(), $package );
		$this->assertNull( $cold, 'With nothing cached the fee path must see no DDP.' );
		$this->assertSame( 0, $this->spy->calls, 'The fee path must never trigger a lookup.' );

		$this->service()->amount_for_method( $this->method(), $package );
		$calls = $this->spy->calls;

		$warm = $this->service()->cached_amount_for_method( $this->method(), $package );
		$this->assertSame( 24.51, $warm, 'The fee path must read what the rate path quoted.' );
		$this->assertSame( $calls, $this->spy->calls, 'Reading the cache must not add a lookup.' );
	}

	/**
	 * A failure is remembered, so a broken upstream is not retried on every rate refresh - and it never
	 * escapes as an exception, because a rate calculation must not die over an optional estimate.
	 */
	public function test_a_failure_is_cached_and_never_thrown() {
		$this->seed_method( DdpBehavior::OPTIONAL, DdpBehavior::LEVEL_SUPPORTED );
		$this->spy->throw = new \RuntimeException( 'upstream is down' );
		$package          = $this->package();

		$this->assertNull( $this->service()->amount_for_method( $this->method(), $package ) );
		$this->assertSame( 1, $this->spy->calls );

		$this->assertNull( $this->service()->amount_for_method( $this->method(), $package ) );
		$this->assertSame( 1, $this->spy->calls, 'A cached failure must suppress the retry.' );
	}

	/**
	 * A response whose components are all disabled is "no duty here", not a zero-priced option.
	 */
	public function test_a_disabled_response_yields_no_amount() {
		$this->seed_method( DdpBehavior::OPTIONAL, DdpBehavior::LEVEL_SUPPORTED );
		$this->spy->response = $this->response( false );

		$this->assertNull( $this->service()->amount_for_method( $this->method(), $this->package() ) );
	}

	/**
	 * A duty quoted in a currency the cart does not charge in is refused outright: the core hands the
	 * amount over unconverted, so adding it to the total would charge the shopper wrong money. The
	 * refusal is cached like any other failure, so a misconfigured account is not re-quoted per render.
	 */
	public function test_a_quote_in_another_currency_yields_no_amount() {
		$this->seed_method( DdpBehavior::OPTIONAL, DdpBehavior::LEVEL_SUPPORTED );
		$foreign             = 'EUR' === get_woocommerce_currency() ? 'USD' : 'EUR';
		$this->spy->response = $this->response( true, $foreign );
		$package             = $this->package();

		$this->assertNull( $this->service()->amount_for_method( $this->method(), $package ) );
		$this->assertSame( 1, $this->spy->calls );

		$this->assertNull( $this->service()->amount_for_method( $this->method(), $package ) );
		$this->assertSame( 1, $this->spy->calls, 'A refused currency must be cached, not re-quoted.' );
	}

	/**
	 * A quote priced in the shop's own currency passes the check that refuses a foreign one.
	 */
	public function test_a_quote_in_the_shop_currency_is_charged() {
		$this->seed_method( DdpBehavior::OPTIONAL, DdpBehavior::LEVEL_SUPPORTED );
		$this->spy->response = $this->response( true, get_woocommerce_currency() );

		$this->assertEquals(
			24.51,
			$this->service()->amount_for_method( $this->method(), $this->package() ),
			'',
			0.0001
		);
	}

	/**
	 * An enabled component that names no currency is refused, not assumed to be in the shop's money.
	 *
	 * The unit of an amount cannot be inferred from the fact that somebody sent it. Charging it anyway
	 * is how a quote in another currency reaches a shopper's total unconverted, so a nameless unit is
	 * treated exactly like a foreign one: no duties option, and a line in the log saying why.
	 */
	public function test_a_quote_naming_no_currency_yields_no_amount() {
		$this->seed_method( DdpBehavior::OPTIONAL, DdpBehavior::LEVEL_SUPPORTED );
		$this->spy->response = $this->response( true, '' );

		$this->assertNull(
			$this->service()->amount_for_method( $this->method(), $this->package() ),
			'An amount whose currency is unknown must not be charged.'
		);
	}

	/**
	 * The cache holds the raw duty base, never a charged amount, so an adjustment the merchant edits
	 * mid-session takes effect on the very next read - and repricing costs no second lookup, which is
	 * the whole reason the adjustment is not baked into the cached figure.
	 */
	public function test_an_edited_adjustment_reprices_the_cached_quote() {
		$this->seed_method( DdpBehavior::OPTIONAL, DdpBehavior::LEVEL_SUPPORTED );
		$package = $this->package();

		$this->assertEquals(
			24.51,
			$this->service()->amount_for_method( $this->method(), $package ),
			'',
			0.0001
		);
		$this->assertSame( 1, $this->spy->calls );

		$method = $this->method();
		$method->setDdpAdjustmentType( DdpBehavior::ADJUSTMENT_FIXED );
		$method->setDdpAdjustmentAmount( 5.00 );
		RepositoryRegistry::getRepository( ShippingMethod::CLASS_NAME )->update( $method );

		$this->assertEquals(
			29.51,
			$this->service()->amount_for_method( $this->method(), $package ),
			'An edited adjustment must reprice the quote already cached for this cart.',
			0.0001
		);
		$this->assertSame( 1, $this->spy->calls, 'Repricing must not spend another lookup.' );
	}

	/**
	 * A method that is not one of the quote's own eligible methods gets no amount. Duty must not be
	 * offered on a service whose route Packlink cannot ship duties-paid, even though the cart has a base.
	 */
	public function test_a_method_outside_the_quote_gets_no_amount() {
		$this->seed_method( DdpBehavior::OPTIONAL, DdpBehavior::LEVEL_SUPPORTED );
		$package = $this->package();

		$this->assertNotNull( $this->service()->amount_for_method( $this->method(), $package ) );

		$foreign = new ShippingMethod();
		$foreign->setId( 999999 );
		$foreign->setDdpBehavior( DdpBehavior::OPTIONAL );

		$this->assertNull( $this->service()->amount_for_method( $foreign, $package ) );
	}

	/**
	 * A merchant who absorbs the duty with a -100% adjustment still gets a quote: the base is kept and
	 * priced to 0.00, because refusing it would take the duties-paid option - and a mandatory service
	 * with it - off checkout.
	 */
	public function test_an_absorbed_duty_is_quoted_as_zero() {
		$this->seed_method( DdpBehavior::OPTIONAL, DdpBehavior::LEVEL_SUPPORTED );

		$method = $this->method();
		$method->setDdpAdjustmentType( DdpBehavior::ADJUSTMENT_PERCENTAGE );
		$method->setDdpAdjustmentAmount( -100.0 );
		RepositoryRegistry::getRepository( ShippingMethod::CLASS_NAME )->update( $method );

		$amount = $this->service()->amount_for_method( $this->method(), $this->package() );

		$this->assertNotNull( $amount, 'An absorbed duty is a quoted duty, not a missing one.' );
		$this->assertEquals( 0.0, $amount, '', 0.0001 );
	}

	/**
	 * A method carries one service per destination and only some routes support duties. Shipping to a
	 * route whose service has no DDP must not be quoted, even though the method as a whole reports
	 * support because another destination has it.
	 */
	public function test_no_lookup_for_a_route_whose_service_has_no_ddp() {
		$this->seed_method( DdpBehavior::OPTIONAL, DdpBehavior::LEVEL_SUPPORTED, 'CH' );

		// The cart ships to Canada; the only DDP-capable service on this method serves Switzerland.
		$amount = $this->service()->amount_for_method( $this->method(), $this->package( 'CA', 1, 'M5H2N2' ) );

		$this->assertNull( $amount );
		$this->assertSame( 0, $this->spy->calls, 'A route without a DDP service must not be quoted.' );
	}

	/**
	 * A product without its own HS code is covered by the default tariff number on the customs
	 * settings page - the core applies it when it builds the invoice, so the checkout gate must
	 * judge the cart the same way instead of refusing it.
	 */
	public function test_the_default_tariff_number_covers_a_product_without_one() {
		$this->seed_method( DdpBehavior::OPTIONAL, DdpBehavior::LEVEL_SUPPORTED );
		$this->seed_customs_mapping( '851713' );

		$amount = $this->service()->amount_for_method( $this->method(), $this->package( 'CH', 1, '8001', '123456789', '' ) );

		$this->assertSame( 24.51, $amount );
		$this->assertSame( 1, $this->spy->calls, 'A cart covered by the default tariff number must be quoted.' );
	}

	/**
	 * With no HS code on the product and no default configured, nothing can describe the goods.
	 */
	public function test_no_lookup_without_any_tariff_number() {
		$this->seed_method( DdpBehavior::OPTIONAL, DdpBehavior::LEVEL_SUPPORTED );
		$this->seed_customs_mapping( '' );

		$amount = $this->service()->amount_for_method( $this->method(), $this->package( 'CH', 1, '8001', '123456789', '' ) );

		$this->assertNull( $amount );
		$this->assertSame( 0, $this->spy->calls, 'A cart with no tariff number anywhere must not be quoted.' );
	}

	/**
	 * The customs page is optional; a shop that never opened it has no mapping at all, and the
	 * default lookup must not fatal on that.
	 */
	public function test_no_lookup_without_a_customs_mapping() {
		$this->seed_method( DdpBehavior::OPTIONAL, DdpBehavior::LEVEL_SUPPORTED );

		$amount = $this->service()->amount_for_method( $this->method(), $this->package( 'CH', 1, '8001', '123456789', '' ) );

		$this->assertNull( $amount );
		$this->assertSame( 0, $this->spy->calls, 'A cart with no mapping and no product HS code must not be quoted.' );
	}

	/**
	 * Service under test.
	 *
	 * @return Ddp_Checkout_Service
	 */
	private function service() {
		return Ddp_Checkout_Service::getInstance();
	}

	/**
	 * Stores a customs mapping carrying the given default tariff number.
	 *
	 * @param string $default_tariff_number Default HS code, or empty for none.
	 */
	private function seed_customs_mapping( $default_tariff_number ) {
		$mapping = new \Packlink\BusinessLogic\Customs\Models\CustomsMapping();

		$mapping->defaultReason           = 'purchase_or_sale';
		$mapping->defaultSenderTaxId      = 'DE123456789';
		$mapping->defaultReceiverUserType = 'private_person';
		$mapping->defaultReceiverTaxId    = '';
		$mapping->defaultTariffNumber     = $default_tariff_number;
		$mapping->defaultCountry          = 'DE';
		$mapping->mappingReceiverTaxId    = '';
		$mapping->mappingTariffNumber     = '';
		$mapping->mappingCountryOfOrigin  = '';

		ServiceRegister::getService(
			\Packlink\WooCommerce\Components\Services\Config_Service::CLASS_NAME
		)->setCustomsMappings( $mapping );
	}

	/**
	 * Duty response with both components enabled by default (5.76 + 18.75 = 24.51).
	 *
	 * @param bool   $enabled Whether the components are enabled.
	 * @param string $currency Currency the components are priced in; empty to omit the field.
	 *
	 * @return DdpCostResponse
	 */
	private function response( $enabled = true, $currency = null ) {
		// Defaults to the SHOP currency. It used to default to '', which the module's own calculator
		// read as "nothing to compare, so assume the shop's own money" - a quote whose unit was unknown
		// was charged anyway. Composition now lives in the core, which refuses an amount it was given no
		// unit for, so a stub naming no currency exercises the refusal rather than the happy path. Pass
		// '' explicitly to test that refusal.
		if ( null === $currency ) {
			$currency = get_woocommerce_currency();
		}

		$fee                = new DdpProductCost();
		$fee->currency      = $currency;
		$fee->totalPrice    = 5.76;
		$fee->isEnabled     = $enabled;
		$duties             = new DdpProductCost();
		$duties->currency   = $currency;
		$duties->totalPrice = 18.75;
		$duties->isEnabled  = $enabled;

		$response                   = new DdpCostResponse();
		$response->serviceId        = '10074';
		$response->ddpFee           = $fee;
		$response->customsAndDuties = $duties;
		$response->effectiveBehavior = DdpBehavior::OPTIONAL;

		return $response;
	}

	/**
	 * Seeds a warehouse in Germany so anything else is international.
	 */
	private function seed_warehouse() {
		$config = ServiceRegister::getService(
			\Packlink\WooCommerce\Components\Services\Config_Service::CLASS_NAME
		);
		$config->setDefaultWarehouse(
			\Packlink\BusinessLogic\Warehouse\Warehouse::fromArray(
				array(
					'country'     => 'DE',
					'postal_code' => '10115',
					'city'        => 'Berlin',
					'alias'       => 'test',
					'name'        => 'Test',
					'surname'     => 'Tester',
					'company'     => '',
					'address'     => 'Street 1',
					'phone'       => '123456789',
					'email'       => 'test@example.com',
					'default'     => true,
				)
			)
		);
	}

	/**
	 * Persists one shipping method with the given merchant behaviour and API support level.
	 *
	 * @param string      $behavior Merchant-configured behaviour.
	 * @param string|null $level API support level.
	 */
	private function seed_method( $behavior, $level, $destination = 'CH' ) {
		$service                     = new ShippingService();
		$service->serviceId          = '10074';
		$service->serviceName        = 'UPS Standard';
		$service->carrierName        = 'UPS';
		$service->departureCountry   = 'DE';
		$service->destinationCountry = $destination;
		$service->ddpSupportLevel    = $level;

		$method = new ShippingMethod();
		$method->setActivated( true );
		$method->setEnabled( true );
		$method->setTitle( 'UPS Standard' );
		$method->setCarrierName( 'UPS' );
		$method->setDdpBehavior( $behavior );
		$method->addShippingService( $service );

		RepositoryRegistry::getRepository( ShippingMethod::CLASS_NAME )->save( $method );
	}

	/**
	 * The persisted shipping method.
	 *
	 * @return ShippingMethod
	 */
	private function method() {
		$methods = RepositoryRegistry::getRepository( ShippingMethod::CLASS_NAME )->select();

		return reset( $methods );
	}

	/**
	 * Builds a shipping package with the given destination and line count.
	 *
	 * @param string $country Destination country.
	 * @param int    $lines Number of distinct cart lines.
	 * @param string $postcode Destination postcode.
	 * @param string $phone Contact phone number; empty to model the phone-less case.
	 * @param string $hs_code HS code set on every product; empty to model a product without one.
	 *
	 * @return array
	 */
	private function package( $country = 'CH', $lines = 1, $postcode = '8001', $phone = '123456789', $hs_code = '85171300' ) {
		WC()->customer->set_shipping_country( $country );
		WC()->customer->set_shipping_postcode( $postcode );
		WC()->customer->set_shipping_city( 'Zurich' );
		WC()->customer->set_shipping_address_1( 'Street 1' );
		WC()->customer->set_billing_phone( $phone );
		if ( method_exists( WC()->customer, 'set_shipping_phone' ) ) {
			WC()->customer->set_shipping_phone( $phone );
		}
		// Deliberately not saved: the lookup reads the in-memory customer, and persisting a guest
		// customer (id 0) means creating a WordPress user, which the data store rejects without an
		// account email.
		WC()->customer->set_billing_email( 'shopper@example.com' );

		$contents = array();
		for ( $i = 0; $i < $lines; $i++ ) {
			$product = new WC_Product_Simple();
			$product->set_name( 'Item ' . $i );
			$product->set_regular_price( '25' );
			$product->set_weight( '1' );
			if ( '' !== $hs_code ) {
				$product->update_meta_data( '_packlink_hs_code', $hs_code );
			}
			$product->save();

			$contents[] = array(
				'product_id'   => $product->get_id(),
				'variation_id' => 0,
				'quantity'     => 1,
				'line_total'   => 25.0,
				'data'         => $product,
			);
		}

		return array(
			'contents'      => $contents,
			'cart_subtotal' => 25.0 * $lines,
			'destination'   => array(
				'country'  => $country,
				'postcode' => $postcode,
				'city'     => 'Zurich',
			),
		);
	}
}
