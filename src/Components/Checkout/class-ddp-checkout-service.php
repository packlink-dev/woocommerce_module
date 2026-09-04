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
use Packlink\BusinessLogic\DDP\DdpCostComposer;
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
 * Duty is a function of the goods, the route AND the carrier service. Packlink prices it from goods
 * plus freight, and it does not use the freight a platform declares - it re-prices at its own
 * `porterage` for the chosen service. Measured on FR->CH with one identical declared freight across
 * four services: duties 87.12 / 86.71 / 87.04 / 88.58; and ten different declared freights against ONE
 * service: all 87.65. So one lookup per SERVICE, and the price a method happens to show changes
 * nothing. This class previously quoted one service for the whole cart, which charged every other
 * carrier a duty computed for a shipment it was not - up to 1.87 out on those figures.
 *
 * What is cached is therefore a raw duty base per service, plus the ids of the methods each one prices
 * and the carrier price it was quoted against - never a charged amount. Each method's own merchant
 * adjustment is applied on read, so an adjustment the merchant edits mid-session takes effect on the
 * next render instead of when the quote expires, and the cache key does not have to fingerprint every
 * method's configuration.
 *
 * The lookups are dispatched together rather than one after another: core runs the invoices as one
 * concurrent wave and the products calls as a second, so the render waits for the slowest lookup
 * instead of the sum of them (four real carriers measured 3840 ms against 11382 ms sequential).
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
	 * WooCommerce session key holding the checkout customs invoice ids, keyed by service.
	 *
	 * Deliberately NOT part of the quote entry. That entry is replaced wholesale on every write - a
	 * failed lookup replaces it with a failure marker - so invoice ids kept inside it would be discarded
	 * by the very failure that makes reuse matter most, and the retry would create fresh permanent
	 * invoices. Packlink offers neither a delete nor a list endpoint, so every abandoned one is forever.
	 *
	 * Outlives the quote's signature and TTL on purpose: an invoice exists at Packlink whatever the cart
	 * has since become, and re-pointing it with PUT is the only way not to orphan it.
	 */
	const SESSION_INVOICES_KEY = '_packlink_ddp_invoices';

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
	 * Packlink's own carrier price for the service that prices one method, from the cache only.
	 *
	 * The freight the draft must declare on its customs invoice. The shipping total WooCommerce records
	 * is the SHOPPER-facing carrier price - porterage plus Packlink's platform fee, plus any
	 * pricing-policy markup - and only porterage is carrier freight. Measured on the identical
	 * PrestaShop defect: a shopper-facing 44.99 against a real carrier price of 44.00, which had the
	 * draft screen quote 26.49 where the shopper was charged, and Packlink billed, 26.40.
	 *
	 * Knowable nowhere else: `porterage` appears only inside a products response, and the draft is
	 * assembled in a later request that makes no such call. So it is carried on the rate's meta and
	 * from there onto the order - see Ddp_Checkout::RATE_META_PORTERAGE.
	 *
	 * Never fetches, for the same reason cached_amount_for_method() does not.
	 *
	 * @param ShippingMethod $method Shipping method the carrier price is wanted for.
	 * @param array          $package WooCommerce shipping package.
	 *
	 * @return float|null Carrier price, or null when this cart's quote did not record one.
	 */
	public function porterage_for_method( ShippingMethod $method, array $package ) {
		$entry = $this->service_entry( $this->quote( $package, null, false ), $method );

		if ( null === $entry || empty( $entry['porterage'] ) ) {
			return null;
		}

		$porterage = (float) $entry['porterage'];

		return $porterage > 0.0 ? $porterage : null;
	}

	/**
	 * Prices one method off a quote by applying that method's own merchant adjustment to the raw base.
	 *
	 * The quote carries one entry per service, and a map from method id to the service that prices it.
	 * A method outside that map is not simply unadjusted, it is ineligible: `eligible_methods()` only
	 * admits methods whose service on this route supports duties, and duty must not be offered on a
	 * service Packlink cannot ship it with. A method whose own service failed to quote is equally
	 * absent - it must not fall back to another service's base, which is exactly the defect this
	 * structure replaced.
	 *
	 * @param array|null     $quote Resolved quote, or null when DDP is unavailable.
	 * @param ShippingMethod $method Shipping method the amount is wanted for.
	 *
	 * @return float|null Charged duty amount, or null when this method has none.
	 */
	private function pick( $quote, ShippingMethod $method ) {
		$entry = $this->service_entry( $quote, $method );

		if ( null === $entry ) {
			return null;
		}

		return DdpCostComposer::chargedFromBase( $entry['base'], $method );
	}

	/**
	 * The quote entry for the service that prices one method, or null when there is none.
	 *
	 * @param array|null     $quote Resolved quote.
	 * @param ShippingMethod $method Shipping method.
	 *
	 * @return array|null
	 */
	private function service_entry( $quote, ShippingMethod $method ) {
		if ( ! is_array( $quote ) || empty( $quote['byMethod'] ) || empty( $quote['services'] ) ) {
			return null;
		}

		$method_id = (string) $method->getId();

		if ( ! isset( $quote['byMethod'][ $method_id ] ) ) {
			return null;
		}

		$service_id = (string) $quote['byMethod'][ $method_id ];

		return isset( $quote['services'][ $service_id ]['base'] )
			? $quote['services'][ $service_id ]
			: null;
	}

	/**
	 * Why a quote cannot be charged in the cart's currency, or null when it can.
	 *
	 * The core hands the component amounts over unconverted and answers this question as a code, so
	 * that each integration can word it in its own voice; mapping the code to the line WooCommerce
	 * logs is this method's only job.
	 *
	 * Two of these refuse quotes the module used to charge. An enabled component naming no currency,
	 * and a shop currency that will not resolve, were both previously read as "nothing to compare, so
	 * assume the shop's own money". An amount whose unit cannot be established is not money that can
	 * be added to a total, so nothing is assumed now.
	 *
	 * @param DdpCostResponse $response Core duty cost response.
	 *
	 * @return string|null Reason to record against the failed quote, or null when it is usable.
	 */
	private function currency_refusal( DdpCostResponse $response ) {
		switch ( DdpCostComposer::checkCurrency( $response, $this->shop_currency() ) ) {
			case DdpCostComposer::CURRENCY_USABLE:
				return null;
			case DdpCostComposer::CURRENCY_FOREIGN:
				return 'Packlink quoted the duty in ' . DdpCostComposer::quotedCurrency( $response )
					. ' but the cart charges in ' . $this->shop_currency()
					. ', and the amount cannot be converted here';
			case DdpCostComposer::CURRENCY_UNQUOTED:
				return 'Packlink quoted a duty amount without naming a currency, so its unit is unknown'
					. ' and it cannot be charged';
			default:
				return 'the shop currency could not be resolved, so the quoted duty cannot be verified';
		}
	}

	/**
	 * Resolves the quote for this cart: request memo, then session cache, then - only when fetching is
	 * allowed - a live lookup.
	 *
	 * @param array      $package WooCommerce shipping package.
	 * @param float|null $transport_cost Transport price, for the customs invoice.
	 * @param bool       $may_fetch Whether a live lookup is permitted.
	 *
	 * @return array|null Quote as `array( 'services' => array, 'byMethod' => array )`, or null when DDP
	 *                    is unavailable for this cart.
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
	 * Performs the lookups - one per service the cart can ship on - and composes a raw duty base for
	 * each.
	 *
	 * @param array      $package WooCommerce shipping package.
	 * @param float|null $transport_cost First-guess transport price for the customs invoice. Only a
	 *                                  guess: core re-quotes at each service's own porterage and
	 *                                  discards whatever is sent, which is why one value serves every
	 *                                  service here.
	 * @param string     $signature Cart signature the result is cached under.
	 *
	 * @return array|null Quote as `array( 'services' => array, 'byMethod' => array )`, or null when DDP
	 *                    is unavailable.
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

			$groups = $this->group_by_service( $eligible, $to_country );
			if ( empty( $groups ) ) {
				return null;
			}

			// Validated once, on a throwaway order: the checks are properties of the CART - line count,
			// tariff numbers, declared value - so they cannot differ between services, and running them
			// per service would multiply the log noise by the number of carriers.
			$probe = Cart_Order_Factory::from_package( $package, $transport_cost );
			if ( null === $probe ) {
				return $this->fail( $signature, 'the cart could not be described to Packlink' );
			}

			$reason = $this->validate( $probe );
			if ( null !== $reason ) {
				return $this->fail( $signature, $reason );
			}

			$reused = $this->cached_invoice_ids();
			$items  = array();

			foreach ( $groups as $service_id => $method_ids ) {
				// A FRESH order per lookup. Core sets the shipping cost of the order it is handed to that
				// service's porterage, so a shared object would end up carrying whichever correction ran
				// last and every service would read the same carrier price.
				$order = Cart_Order_Factory::from_package( $package, $transport_cost );
				if ( null === $order ) {
					continue;
				}

				$items[ (string) $service_id ] = array(
					'order'     => $order,
					'serviceId' => $service_id,
					// An invoice already made for this cart and service is re-pointed with PUT instead
					// of another being created. Packlink offers no way to delete or even list checkout
					// invoices, so every one abandoned here is permanent and invisible.
					'invoiceId' => isset( $reused[ (string) $service_id ] ) ? $reused[ (string) $service_id ] : null,
				);
			}

			if ( empty( $items ) ) {
				return $this->fail( $signature, 'the cart could not be described to Packlink' );
			}

			$results = $this->lookup_many( $items );

			// Recorded first, and for EVERY service that produced one - including those whose quote then
			// turned out unusable. The invoice exists at Packlink either way, so the id is worth keeping
			// so the next attempt re-points it rather than leaving it orphaned.
			$invoice_ids = array();
			foreach ( $results as $service_id => $result ) {
				if ( ! empty( $result['invoiceId'] ) ) {
					$invoice_ids[ (string) $service_id ] = (string) $result['invoiceId'];
				}
			}
			$this->remember_invoice_ids( $invoice_ids );

			$services  = array();
			$by_method = array();
			$errors    = array();

			foreach ( $items as $service_id => $item ) {
				$result   = isset( $results[ $service_id ] ) ? $results[ $service_id ] : array();
				$response = isset( $result['costs'] ) ? $result['costs'] : null;

				if ( ! $response instanceof DdpCostResponse ) {
					$errors[] = $service_id . ': ' . ( empty( $result['error'] )
						? 'Packlink returned no duty cost'
						: $result['error'] );
					continue;
				}

				// Composed before the currency is judged, and in that order deliberately. A response
				// with no enabled component names no currency either, so asking about the currency
				// first answers "the duty has no currency" for a route that simply carries no duty -
				// two different outcomes reported as one, and only one worth a merchant's attention.
				$base = DdpCostComposer::composeBase( $response );
				if ( null === $base ) {
					$errors[] = $service_id . ': Packlink reported no applicable duty for this route';
					continue;
				}

				$refusal = $this->currency_refusal( $response );
				if ( null !== $refusal ) {
					$errors[] = $service_id . ': ' . $refusal;
					continue;
				}

				// Mutated by core's correction, so after the call this IS Packlink's carrier price for
				// the service. The only moment it is knowable - the draft makes no products call.
				$porterage = (float) $item['order']->getShippingCost();

				$services[ (string) $service_id ] = array(
					'base'      => $base,
					'porterage' => $porterage > 0.0 ? $porterage : null,
					'invoiceId' => empty( $result['invoiceId'] ) ? null : (string) $result['invoiceId'],
					'methods'   => array_map( 'strval', $groups[ $service_id ] ),
				);

				foreach ( $groups[ $service_id ] as $method_id ) {
					$by_method[ (string) $method_id ] = (string) $service_id;
				}
			}

			if ( empty( $services ) ) {
				return $this->fail( $signature, 'no service could be quoted - ' . implode( '; ', $errors ) );
			}

			if ( ! empty( $errors ) ) {
				// A partial answer is the normal shape here: each service is an independent lookup, so
				// the carriers that did quote keep their duty and only the rest go without. Recorded
				// because otherwise a carrier silently losing its option looks like configuration.
				Logger::logWarning(
					'Duties unavailable for some services: ' . implode( '; ', $errors ) . '.',
					'Integration'
				);
			}

			$quote = array(
				'services' => $services,
				'byMethod' => $by_method,
			);

			$this->write_cache( $signature, $quote, self::OK_TTL );

			return $quote;
		} catch ( Throwable $e ) {
			// A duty estimate is optional; a shipping-rate calculation is not. Nothing may escape.
			return $this->fail( $signature, 'the lookup failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Hands every prepared lookup to core at once, or prices them one at a time on a core that cannot.
	 *
	 * The plugin ships with its own vendored core, so the batched path may simply not be there - and a
	 * fatal on a missing method would take out the shipping-rate calculation rather than just the duty
	 * option. The fallback prices each service separately, which is slower and skips core's
	 * carrier-price correction, but keeps every carrier priced from its OWN service; degrading to one
	 * shared lookup would be faster and wrong.
	 *
	 * @param array $items Lookups keyed by service id.
	 *
	 * @return array Same keys, each with 'invoiceId', 'costs' and 'error'.
	 */
	private function lookup_many( array $items ) {
		$service = $this->ddp_cost_service();

		if ( method_exists( $service, 'getDdpCostsMany' ) ) {
			return $service->getDdpCostsMany( $items );
		}

		Logger::logWarning(
			'The bundled Packlink core has no batched duty lookup, so duties are priced one service at a'
			. ' time and without the carrier-price correction. Update the core dependency.',
			'Integration'
		);

		$results = array();

		foreach ( $items as $key => $item ) {
			$results[ $key ] = array(
				'invoiceId' => null,
				'costs'     => $service->getDdpCosts( $item['order'], $item['serviceId'] ),
				'error'     => null,
			);
		}

		return $results;
	}

	/**
	 * Groups the eligible methods by the service that will carry them on this route.
	 *
	 * @param ShippingMethod[] $eligible Eligible methods keyed by method id.
	 * @param string           $to_country Destination country code.
	 *
	 * @return array Service id => method ids.
	 */
	private function group_by_service( array $eligible, $to_country ) {
		$from   = $this->warehouse_country();
		$groups = array();

		foreach ( $eligible as $method_id => $method ) {
			$service_id = $this->route_service_id( $method, $from, $to_country );

			if ( null === $service_id ) {
				continue;
			}

			$groups[ (string) $service_id ][] = (string) $method_id;
		}

		return $groups;
	}

	/**
	 * Checkout customs invoice ids this session already holds, keyed by service.
	 *
	 * Read without regard to the signature or the TTL, both deliberately. An invoice exists at Packlink
	 * whatever the cart has since become, and re-pointing it with PUT is the only way to avoid leaving
	 * it orphaned - there is no delete and no list endpoint. A stale signature means the AMOUNT must be
	 * re-quoted, not that the invoice must be a new one, and that is exactly when reuse saves the most.
	 *
	 * @return array Service id => invoice id.
	 */
	private function cached_invoice_ids() {
		$session = $this->session();

		if ( null === $session ) {
			return array();
		}

		$stored = $session->get( self::SESSION_INVOICES_KEY );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$ids = array();

		foreach ( $stored as $service_id => $invoice_id ) {
			if ( ! empty( $invoice_id ) ) {
				$ids[ (string) $service_id ] = (string) $invoice_id;
			}
		}

		return $ids;
	}

	/**
	 * Remembers the invoice ids this lookup used, merged over whatever the session already held.
	 *
	 * Merged rather than replaced: a lookup that quoted two of a cart's four services must not drop the
	 * other two's ids, or the next render orphans them.
	 *
	 * @param array $ids Service id => invoice id, from this lookup.
	 *
	 * @return void
	 */
	private function remember_invoice_ids( array $ids ) {
		if ( empty( $ids ) ) {
			return;
		}

		$session = $this->session();

		if ( null === $session ) {
			return;
		}

		$existing = $session->get( self::SESSION_INVOICES_KEY );
		$session->set(
			self::SESSION_INVOICES_KEY,
			array_merge( is_array( $existing ) ? $existing : array(), $ids )
		);
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

		return ( is_array( $quote ) && ! empty( $quote['services'] ) && is_array( $quote['services'] )
			&& isset( $quote['byMethod'] ) && is_array( $quote['byMethod'] ) ) ? $quote : false;
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
