<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Checkout;

use Logeecom\Infrastructure\Logger\Logger;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\Singleton;
use Packlink\BusinessLogic\DDP\DdpBehavior;
use Packlink\BusinessLogic\DDP\Interfaces\DdpCostServiceInterface;
use Packlink\BusinessLogic\DDP\Models\DdpCostResponse;
use Packlink\BusinessLogic\Order\Objects\Order;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\WooCommerce\Components\Order\Cart_Order_Factory;
use Packlink\WooCommerce\Components\Services\Config_Service;
use Throwable;

/**
 * Class Ddp_Checkout_Service
 *
 * Owns the duty lookup at checkout. A single lookup is two Packlink requests, the first of which
 * permanently creates a customs invoice, so it must happen once per cart and address - not once per
 * shipping method instance, and not once per render.
 *
 * Duty is a function of the goods and the route, not of the carrier service, so one call answers for
 * every DDP-capable method of a cart. What is cached is therefore the raw duty base plus the ids of the
 * methods it applies to - never a charged amount. Each method's own merchant adjustment is applied on
 * read, so an adjustment the merchant edits mid-session takes effect on the next render instead of when
 * the quote expires, and the cache key does not have to fingerprint every method's configuration.
 *
 * Two accessors, deliberately. WooCommerce caches per-package shipping rates in the session, so
 * `calculate_shipping()` does not run on every checkout render - meaning the cart-fee and
 * presentation paths cannot assume the rate path just populated anything. They use the non-fetching
 * accessor and treat a miss as "no DDP", while the rate path uses the fetching one.
 *
 * A singleton, because the request memo below is only worth having if every caller in a request shares
 * it: `ServiceRegister::getService()` invokes its delegate on every call, so a closure handing out a
 * fresh instance would give the rate path and the fee path separate memos.
 *
 * @package Packlink\WooCommerce\Components\Checkout
 */
class Ddp_Checkout_Service extends Singleton {

	/**
	 * Fully qualified name of this class.
	 */
	const CLASS_NAME = __CLASS__;

	/**
	 * Singleton instance of this class.
	 *
	 * @var static
	 */
	protected static $instance;

	/**
	 * Maximum inventory lines Packlink documents for one customs invoice.
	 *
	 * The API does not enforce this - a 13-line invoice is accepted with 201 - so a cart over the cap
	 * would produce a silently incomplete customs invoice with no error to react to. Refusing the
	 * lookup keeps that out of the shopper's total. The sibling PrestaShop module takes the opposite
	 * view and sends every line, on the grounds that capping makes the checkout estimate disagree with
	 * the invoice the real shipment carries; if that view wins, this constant is the only thing to
	 * change.
	 */
	const MAX_INVOICE_ITEMS = 12;

	/**
	 * Seconds a successful quote stays valid.
	 */
	const OK_TTL = 900;

	/**
	 * Seconds a failed lookup is remembered, so a broken upstream is not retried on every keystroke.
	 */
	const FAIL_TTL = 120;

	/**
	 * WooCommerce session key holding the cached quote.
	 */
	const SESSION_KEY = '_packlink_ddp_quote';

	/**
	 * Request-scoped memo: signature of the cart the quote was made for.
	 *
	 * @var string|null
	 */
	private $memo_signature;

	/**
	 * Request-scoped memo: the resolved quote, or null when DDP is unavailable for this cart.
	 *
	 * @var array|null
	 */
	private $memo_quote;

	/**
	 * Configuration service.
	 *
	 * @var Config_Service
	 */
	private $configuration;

	/**
	 * Returns the charged duty amount for one shipping method, performing the lookup when needed.
	 *
	 * For the shipping-rate path only. Null covers every "no usable amount" case - domestic route, no
	 * eligible service, cart the customs invoice cannot describe, API failure - because a missing duty
	 * must never be presented as a zero one.
	 *
	 * @param ShippingMethod $method Shipping method the amount is wanted for.
	 * @param array          $package WooCommerce shipping package.
	 * @param float|null     $transport_cost Transport price of the method, for the customs invoice.
	 *
	 * @return float|null Charged duty amount, or null when DDP is not available.
	 */
	public function amount_for_method( ShippingMethod $method, array $package, $transport_cost = null ) {
		return $this->pick( $this->quote( $package, $transport_cost, true ), $method );
	}

	/**
	 * Returns the charged duty amount for one shipping method from the cache only, never performing a
	 * lookup.
	 *
	 * For the cart-fee and presentation paths. WooCommerce serves later renders from its cached
	 * package rates without calling `calculate_shipping()`, so these callers have no guarantee the rate
	 * path ran this request - but they must not spend two Packlink requests and create a customs
	 * invoice from a fee callback either. A miss simply means no DDP line.
	 *
	 * @param ShippingMethod $method Shipping method the amount is wanted for.
	 * @param array          $package WooCommerce shipping package.
	 *
	 * @return float|null Charged duty amount, or null when nothing is cached for this cart.
	 */
	public function cached_amount_for_method( ShippingMethod $method, array $package ) {
		return $this->pick( $this->quote( $package, null, false ), $method );
	}

	/**
	 * Prices one method off a quote by applying that method's own merchant adjustment to the raw base.
	 *
	 * The quote carries the ids of the methods the base was obtained for. A method outside that set is
	 * not simply unadjusted, it is ineligible: `eligible_methods()` only admits methods whose service on
	 * this route supports duties, and duty must not be offered on a service Packlink cannot ship it with.
	 *
	 * @param array|null     $quote Resolved quote, or null when DDP is unavailable.
	 * @param ShippingMethod $method Shipping method the amount is wanted for.
	 *
	 * @return float|null Charged duty amount, or null when this method has none.
	 */
	private function pick( $quote, ShippingMethod $method ) {
		if ( ! is_array( $quote ) || ! in_array( (string) $method->getId(), $quote['methods'], true ) ) {
			return null;
		}

		return Ddp_Cost_Calculator::charged_amount( $quote['base'], $method );
	}

	/**
	 * Resolves the quote for this cart: request memo, then session cache, then - only when fetching is
	 * allowed - a live lookup.
	 *
	 * @param array      $package WooCommerce shipping package.
	 * @param float|null $transport_cost Transport price, for the customs invoice.
	 * @param bool       $may_fetch Whether a live lookup is permitted.
	 *
	 * @return array|null Quote as `array( 'base' => float, 'methods' => string[] )`, or null when DDP is
	 *                    unavailable for this cart.
	 */
	private function quote( array $package, $transport_cost, $may_fetch ) {
		$signature = $this->signature( $package );

		if ( $this->memo_signature === $signature ) {
			return $this->memo_quote;
		}

		$cached = $this->read_cache( $signature );
		if ( false !== $cached ) {
			// A cached null is a real answer: DDP was unavailable for this cart and must not be
			// re-attempted until the entry expires.
			return $this->memoize( $signature, $cached );
		}

		if ( ! $may_fetch ) {
			return null;
		}

		return $this->memoize( $signature, $this->fetch( $package, $transport_cost, $signature ) );
	}

	/**
	 * Performs the lookup and composes the raw duty base the cart's eligible methods are priced from.
	 *
	 * @param array      $package WooCommerce shipping package.
	 * @param float|null $transport_cost Transport price, for the customs invoice.
	 * @param string     $signature Cart signature the result is cached under.
	 *
	 * @return array|null Quote as `array( 'base' => float, 'methods' => string[] )`, or null when DDP is
	 *                    unavailable.
	 */
	private function fetch( array $package, $transport_cost, $signature ) {
		try {
			if ( ! $this->is_international( $package ) ) {
				// Domestic carts owe no duty. Not a failure, and not worth caching a negative for.
				return null;
			}

			$to_country = isset( $package['destination']['country'] ) ? $package['destination']['country'] : '';

			$eligible = $this->eligible_methods( $to_country );
			if ( empty( $eligible ) ) {
				return null;
			}

			$service_id = $this->quotable_service_id( $eligible, $to_country );
			if ( null === $service_id ) {
				return null;
			}

			$order = Cart_Order_Factory::from_package( $package, $transport_cost );
			if ( null === $order ) {
				return $this->fail( $signature, 'the cart could not be described to Packlink' );
			}

			$reason = $this->validate( $order );
			if ( null !== $reason ) {
				return $this->fail( $signature, $reason );
			}

			$response = $this->ddp_cost_service()->getDdpCosts( $order, $service_id );
			if ( ! $response instanceof DdpCostResponse ) {
				return $this->fail( $signature, 'Packlink returned no duty cost' );
			}

			$foreign = Ddp_Cost_Calculator::foreign_currency( $response, $this->shop_currency() );
			if ( null !== $foreign ) {
				return $this->fail(
					$signature,
					'Packlink quoted the duty in ' . $foreign . ' but the cart charges in '
					. $this->shop_currency() . ', and the amount cannot be converted here'
				);
			}

			$base = Ddp_Cost_Calculator::composed_base( $response );
			if ( null === $base ) {
				// Every component came back disabled: the route carries no duty for this service. Not a
				// failure of the lookup, but worth remembering so the two requests are not spent again.
				return $this->fail( $signature, 'Packlink reported no applicable duty for this route' );
			}

			$quote = array(
				'base'    => $base,
				'methods' => array_map( 'strval', array_keys( $eligible ) ),
			);

			$this->write_cache( $signature, $quote, self::OK_TTL );

			return $quote;
		} catch ( Throwable $e ) {
			// A duty estimate is optional; a shipping-rate calculation is not. Nothing may escape.
			return $this->fail( $signature, 'the lookup failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Checks the rules Packlink documents for a customs invoice but does not enforce - an invalid
	 * invoice comes back 201, so there is no error to fail soft on and a shopper would be charged
	 * against a document that cannot be honoured.
	 *
	 * @param Order $order Assembled checkout order.
	 *
	 * @return string|null Reason the cart cannot be quoted, or null when it can.
	 */
	private function validate( Order $order ) {
		$items = $order->getItems();

		if ( count( $items ) > self::MAX_INVOICE_ITEMS ) {
			return 'the cart has ' . count( $items ) . ' lines and a customs invoice takes at most '
				. self::MAX_INVOICE_ITEMS;
		}

		$address = $order->getShippingAddress();
		if ( null === $address || '' === (string) $address->getPhone() ) {
			return 'the delivery address has no phone number, which a customs invoice requires';
		}

		// The cart carries only what the products themselves resolved; the core fills the gaps from
		// the customs settings when it builds the invoice. Judging the raw item value would refuse
		// carts the invoice can in fact describe - a shop that sets one default HS code instead of
		// tagging every product would never be offered duties.
		$default_tariff_number = $this->default_tariff_number();

		foreach ( $items as $item ) {
			if ( '' === (string) ( $item->getTariffNumber() ?: $default_tariff_number ) ) {
				return 'no customs tariff number (HS code) is set for "' . $item->getTitle()
					. '" and no default tariff number is configured';
			}
		}

		if ( (float) $order->getTotalPrice() <= 0.0 ) {
			// Packlink would answer zero duty, which is indistinguishable from a free DDP option.
			return 'the cart has no declared value, so no duty can be quoted';
		}

		return null;
	}

	/**
	 * Default HS code from the customs settings, applied to every item the products left blank.
	 * A shop that never opened the customs page has no mapping at all.
	 *
	 * @return string Configured default tariff number, or an empty string when there is none.
	 */
	private function default_tariff_number() {
		$mapping = $this->config()->getCustomsMappings();

		return null !== $mapping ? (string) $mapping->defaultTariffNumber : '';
	}

	/**
	 * Whether the shipment crosses a customs border.
	 *
	 * @param array $package WooCommerce shipping package.
	 *
	 * @return bool
	 */
	private function is_international( array $package ) {
		$warehouse = $this->config()->getDefaultWarehouse();
		if ( null === $warehouse ) {
			return false;
		}

		$destination = isset( $package['destination']['country'] ) ? $package['destination']['country'] : '';
		if ( '' === $destination ) {
			return false;
		}

		return strtoupper( $destination ) !== strtoupper( $warehouse->country );
	}

	/**
	 * Returns the shipping methods that can charge duty on this route.
	 *
	 * Both conditions are needed. `getEffectiveDdpBehavior()` answers for the method as a whole - it
	 * reports "supported" when any one of the method's services supports DDP - but a method carries one
	 * service per destination, and only some of those routes support it. A UPS method that offers DDP
	 * to Germany and not to Canada must not put a duties option in front of a shopper in Canada, nor
	 * spend two Packlink requests and create a customs invoice discovering that.
	 *
	 * @param string $to_country Destination country code.
	 *
	 * @return ShippingMethod[] Keyed by method id.
	 */
	private function eligible_methods( $to_country ) {
		$result = array();
		$from   = $this->warehouse_country();

		/** @var \Packlink\BusinessLogic\ShippingMethod\ShippingMethodService $service */ // phpcs:ignore
		$service = ServiceRegister::getService(
			\Packlink\BusinessLogic\ShippingMethod\ShippingMethodService::CLASS_NAME
		);

		foreach ( $service->getActiveMethods() as $method ) {
			if ( DdpBehavior::NONE === $method->getEffectiveDdpBehavior() ) {
				continue;
			}

			if ( null === $this->route_service_id( $method, $from, $to_country ) ) {
				continue;
			}

			$result[ (string) $method->getId() ] = $method;
		}

		return $result;
	}

	/**
	 * Picks any one service id that Packlink can quote duty for on this route. Duty does not vary by
	 * service, so one is enough - and the request is never batched, because batched responses cannot be
	 * attributed back to the service that priced them.
	 *
	 * @param ShippingMethod[] $eligible Eligible methods.
	 * @param string           $to_country Destination country code.
	 *
	 * @return string|int|null Service id, or null when none can be quoted.
	 */
	private function quotable_service_id( array $eligible, $to_country ) {
		$from = $this->warehouse_country();

		foreach ( $eligible as $method ) {
			$service_id = $this->route_service_id( $method, $from, $to_country );
			if ( null !== $service_id ) {
				return $service_id;
			}
		}

		return null;
	}

	/**
	 * The id of the method's DDP-capable service for one route, if it has one.
	 *
	 * The literal string "none" is a legal support level, not only null, so neither counts as support.
	 *
	 * @param ShippingMethod $method Shipping method.
	 * @param string         $from Departure country code.
	 * @param string         $to Destination country code.
	 *
	 * @return string|int|null Service id, or null when this route carries no DDP-capable service.
	 */
	private function route_service_id( ShippingMethod $method, $from, $to ) {
		$from = strtoupper( (string) $from );
		$to   = strtoupper( (string) $to );

		foreach ( $method->getShippingServices() as $service ) {
			if ( strtoupper( (string) $service->departureCountry ) !== $from
				|| strtoupper( (string) $service->destinationCountry ) !== $to ) {
				continue;
			}

			$level = $service->getDdpSupportLevel();
			if ( DdpBehavior::LEVEL_SUPPORTED === $level || DdpBehavior::LEVEL_MANDATORY === $level ) {
				return $service->serviceId;
			}
		}

		return null;
	}

	/**
	 * Country the default warehouse ships from.
	 *
	 * @return string
	 */
	private function warehouse_country() {
		$warehouse = $this->config()->getDefaultWarehouse();

		return null !== $warehouse ? (string) $warehouse->country : '';
	}

	/**
	 * Logs why DDP is unavailable and remembers the failure briefly.
	 *
	 * The shopper sees nothing - the option is simply absent - so the log is the only place a merchant
	 * can find out why, and the reason must be specific enough to act on.
	 *
	 * @param string $signature Cart signature.
	 * @param string $reason Why the lookup did not produce an amount.
	 *
	 * @return null
	 */
	private function fail( $signature, $reason ) {
		Logger::logWarning( 'Duties cannot be offered at checkout: ' . $reason . '.', 'Integration' );
		$this->write_cache( $signature, null, self::FAIL_TTL );

		return null;
	}

	/**
	 * Currency the cart charges in, which both the quote's validity and its cache key depend on.
	 *
	 * @return string ISO code, or an empty string outside a WooCommerce request.
	 */
	private function shop_currency() {
		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
	}

	/**
	 * Fingerprint of everything the duty amount depends on: destination, declared value and contents.
	 *
	 * Mirrors the normalisation the shipping-cost memo already uses, so the two invalidate together.
	 *
	 * @param array $package WooCommerce shipping package.
	 *
	 * @return string
	 */
	private function signature( array $package ) {
		$parts = array(
			isset( $package['destination']['country'] ) ? $package['destination']['country'] : '',
			isset( $package['destination']['postcode'] ) ? $package['destination']['postcode'] : '',
			isset( $package['cart_subtotal'] ) ? (float) $package['cart_subtotal'] : 0.0,
			$this->shop_currency(),
		);

		$contents = isset( $package['contents'] ) ? $package['contents'] : array();
		foreach ( $contents as $item ) {
			$parts[] = ( isset( $item['product_id'] ) ? (int) $item['product_id'] : 0 )
				. '_' . ( isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0 )
				. '_' . ( isset( $item['quantity'] ) ? (int) $item['quantity'] : 0 );
		}

		return md5( implode( '|', $parts ) );
	}

	/**
	 * Reads the cached quote for this signature.
	 *
	 * Kept in the WooCommerce session rather than a transient: a duty amount belongs to one shopper's
	 * cart and address, and a shared cache would hand it to somebody else.
	 *
	 * @param string $signature Cart signature.
	 *
	 * @return array|null|false Quote, null for a cached failure, or false when nothing usable is cached.
	 */
	private function read_cache( $signature ) {
		$session = $this->session();
		if ( null === $session ) {
			return false;
		}

		$entry = $session->get( self::SESSION_KEY );
		if ( ! is_array( $entry ) || ! isset( $entry['signature'], $entry['expires'] ) ) {
			return false;
		}

		if ( $entry['signature'] !== $signature || $entry['expires'] < time() ) {
			return false;
		}

		if ( ! isset( $entry['quote'] ) ) {
			return null;
		}

		// A session can outlive a plugin update, so an entry written in another shape is a miss rather
		// than a quote to trust: reading a stale shape would price the cart off a figure of unknown
		// meaning.
		$quote = $entry['quote'];

		return ( is_array( $quote ) && isset( $quote['base'] ) && isset( $quote['methods'] )
			&& is_array( $quote['methods'] ) ) ? $quote : false;
	}

	/**
	 * Stores the quote for this signature.
	 *
	 * @param string     $signature Cart signature.
	 * @param array|null $quote Quote to store, or null for a failure.
	 * @param int        $ttl Seconds the entry stays valid.
	 *
	 * @return void
	 */
	private function write_cache( $signature, $quote, $ttl ) {
		$session = $this->session();
		if ( null === $session ) {
			return;
		}

		$session->set(
			self::SESSION_KEY,
			array(
				'signature' => $signature,
				'quote'     => $quote,
				'expires'   => time() + (int) $ttl,
			)
		);
	}

	/**
	 * Records the resolved quote for the rest of this request.
	 *
	 * @param string     $signature Cart signature.
	 * @param array|null $quote Resolved quote, or null.
	 *
	 * @return array|null The quote, unchanged.
	 */
	private function memoize( $signature, $quote ) {
		$this->memo_signature = $signature;
		$this->memo_quote     = $quote;

		return $quote;
	}

	/**
	 * WooCommerce session, when there is one.
	 *
	 * @return \WC_Session|null
	 */
	private function session() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$wc = WC();

		return ( $wc && isset( $wc->session ) && $wc->session ) ? $wc->session : null;
	}

	/**
	 * Core duty cost service. Every DDP request goes through it; this module never calls a Packlink
	 * DDP endpoint itself.
	 *
	 * @return DdpCostServiceInterface
	 */
	private function ddp_cost_service() {
		return ServiceRegister::getService( DdpCostServiceInterface::CLASS_NAME );
	}

	/**
	 * Configuration service.
	 *
	 * @return Config_Service
	 */
	private function config() {
		if ( null === $this->configuration ) {
			$this->configuration = ServiceRegister::getService( Config_Service::CLASS_NAME );
		}

		return $this->configuration;
	}
}
