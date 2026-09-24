<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Order;

use Logeecom\Infrastructure\Logger\Logger;
use Packlink\BusinessLogic\Order\Objects\Address;
use Packlink\BusinessLogic\Order\Objects\Item;
use Packlink\BusinessLogic\Order\Objects\Order;
use Throwable;
use WC_Customer;
use WC_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Cart_Order_Factory
 *
 * Builds a core Order out of a WooCommerce shipping package, which is what the duty-cost call needs
 * at the shipping step: the core DDP cost service creates a customs invoice from an Order, and until
 * the order is placed there is none. Shop_Order_Service::getOrderAndShippingData() does the
 * equivalent job for a placed order, and the customs-relevant values here are resolved through the
 * same Customs_Data_Resolver so the checkout estimate and the shipment's customs invoice cannot
 * disagree.
 *
 * Nothing in this class may throw. A duty estimate is optional at checkout while an exception raised
 * here would take the whole shipping-rate calculation down with it, and a missing phone, tax id or HS
 * code is entirely normal at the shipping step. Failures are logged as warnings and degrade into a
 * partial order, or into `null` when there is no destination to price at all.
 *
 * @package Packlink\WooCommerce\Components\Order
 */
class Cart_Order_Factory {

	/**
	 * Builds a core Order from a WooCommerce shipping package.
	 *
	 * @param array      $package       WooCommerce shipping package (`contents`, `destination`). The
	 *                                  package's own `cart_subtotal` is deliberately not read — see
	 *                                  declared_value().
	 * @param float|null $shipping_cost Transport price of the service being priced, in the store
	 *                                  currency. When given it is carried on the order as the
	 *                                  shipment cost, because the customs value Packlink computes is
	 *                                  goods plus freight: the shipment cost of the customs invoice
	 *                                  has to be the freight, never the goods value. Left unset when
	 *                                  the caller does not know it yet.
	 *
	 * @return Order|null The assembled order, or null when the destination is unknown.
	 */
	public static function from_package( array $package, $shipping_cost = null ) {
		try {
			$address = self::get_shipping_address( $package );
			if ( empty( $address->getCountry() ) || empty( $address->getZipCode() ) ) {
				// No route, nothing to price: the shopper has not told us where this is going.
				return null;
			}

			$resolver    = new Customs_Data_Resolver();
			$customer_id = get_current_user_id();

			$order = new Order();
			$order->setShippingAddress( $address );
			$order->setCustomerId( $customer_id );
			$order->setCurrency( get_woocommerce_currency() );

			$items = self::get_items( $package, $resolver );

			// Declared value of the goods: the sum of the very lines this invoice itemises, so the
			// declared total can never contradict them. Shipping is deliberately excluded here and
			// carried separately in $shipping_cost, since Packlink adds the freight to the goods
			// value itself.
			//
			// Not $package['cart_subtotal']. WooCommerce fills that from get_displayed_subtotal(),
			// which is tax-INCLUDED whenever the shop displays gross prices, and it covers the whole
			// cart including the virtual lines get_item() deliberately drops as unshippable. Either
			// one makes the declared value disagree with the itemised lines and inflates the customs
			// value Packlink prices the duty from - and a customs value must never swing on a display
			// setting. The lines themselves carry `line_total`, which is net and post-discount: the
			// transaction value customs actually wants.
			$subtotal = self::declared_value( $items );
			$order->setBasePrice( $subtotal );
			$order->setNetCartPrice( $subtotal );
			$order->setCartPrice( $subtotal );
			$order->setTotalPrice( $subtotal );

			$order->setItems( $items );
			$order->setTotalWeight( Customs_Data_Resolver::total_weight( $items ) );

			// One resolved value feeds both customs attributes: the core sends it as the receiver
			// tax id (private person) or VAT number (company) based on the receiver user type.
			$tax_id = $resolver->resolve_tax_id_from_customer( $customer_id );
			if ( '' !== $tax_id ) {
				$order->setTaxId( $tax_id );
				$order->setVatNumber( $tax_id );
			}

			if ( null !== $shipping_cost ) {
				$order->setShippingCost( (float) $shipping_cost );
			}

			return $order;
		} catch ( Throwable $e ) {
			Logger::logWarning(
				'Failed to build the cart order for the duty estimate: ' . $e->getMessage(),
				'Integration'
			);

			return null;
		}
	}

	/**
	 * Builds the receiver address: the destination the rates were priced on, completed with the
	 * personal fields of the current customer.
	 *
	 * @param array $package WooCommerce shipping package.
	 *
	 * @return Address
	 */
	private static function get_shipping_address( array $package ) {
		$customer = self::get_customer();
		$address  = new Address();

		if ( null !== $customer ) {
			$address = self::get_customer_shipping_fields( $customer );

			if ( empty( $address->getStreet1() ) && empty( $address->getStreet2() ) ) {
				// Same fallback rule Shop_Order_Service::get_shipping_address() applies: with no
				// shipping street the billing address is the one to ship to.
				$address = self::get_customer_billing_fields( $customer );
			}
		}

		// Country, postcode and city come from the package destination, exactly as
		// Packlink_Shipping_Method::load_shipping_costs() reads them, so the estimate is asked for
		// the very route the rates were priced on.
		$destination = isset( $package['destination'] ) && is_array( $package['destination'] )
			? $package['destination']
			: array();

		$address->setCountry( self::value( $destination, 'country' ) );
		$address->setZipCode( self::value( $destination, 'postcode' ) );

		$city = self::value( $destination, 'city' );
		if ( '' !== $city ) {
			$address->setCity( $city );
		}

		return $address;
	}

	/**
	 * Builds an address out of the shipping fields of the customer. WooCommerce keeps no shipping
	 * email, and a shipping phone only since WooCommerce 5.6, so both are read from the billing
	 * fields - which is what the shipment draft does with a placed order too.
	 *
	 * @param WC_Customer $customer WooCommerce customer.
	 *
	 * @return Address
	 */
	private static function get_customer_shipping_fields( WC_Customer $customer ) {
		$address = new Address();
		$address->setEmail( $customer->get_billing_email() );
		$address->setPhone( $customer->get_billing_phone() );
		$address->setName( $customer->get_shipping_first_name() );
		$address->setSurname( $customer->get_shipping_last_name() );
		$address->setCompany( $customer->get_shipping_company() );
		$address->setCity( $customer->get_shipping_city() );
		$address->setStreet1( $customer->get_shipping_address_1() );
		$address->setStreet2( $customer->get_shipping_address_2() );

		return $address;
	}

	/**
	 * Builds an address out of the billing fields of the customer.
	 *
	 * Unlike Shop_Order_Service::get_billing_address() this does not require a street: at the
	 * shipping step the address is partial by nature, and discarding the receiver phone because the
	 * street has not been typed yet would fail the duty estimate for a cart the shipment draft would
	 * later accept.
	 *
	 * @param WC_Customer $customer WooCommerce customer.
	 *
	 * @return Address
	 */
	private static function get_customer_billing_fields( WC_Customer $customer ) {
		$address = new Address();
		$address->setEmail( $customer->get_billing_email() );
		$address->setPhone( $customer->get_billing_phone() );
		$address->setName( $customer->get_billing_first_name() );
		$address->setSurname( $customer->get_billing_last_name() );
		$address->setCompany( $customer->get_billing_company() );
		$address->setCity( $customer->get_billing_city() );
		$address->setStreet1( $customer->get_billing_address_1() );
		$address->setStreet2( $customer->get_billing_address_2() );

		return $address;
	}

	/**
	 * Builds one item per non-virtual line of the package. A line that cannot be read is skipped and
	 * logged: a single unusable product must not cost the whole estimate.
	 *
	 * @param array                 $package  WooCommerce shipping package.
	 * @param Customs_Data_Resolver $resolver Customs data resolver.
	 *
	 * @return Item[]
	 */
	private static function get_items( array $package, Customs_Data_Resolver $resolver ) {
		$items    = array();
		$contents = isset( $package['contents'] ) && is_array( $package['contents'] )
			? $package['contents']
			: array();

		foreach ( $contents as $line ) {
			try {
				$item = is_array( $line ) ? self::get_item( $line, $resolver ) : null;
				if ( null !== $item ) {
					$items[] = $item;
				}
			} catch ( Throwable $e ) {
				Logger::logWarning(
					'Skipping a cart line while building the duty estimate: ' . $e->getMessage(),
					'Integration'
				);
			}
		}

		return $items;
	}

	/**
	 * Builds a single item from one package content row, populated from the same sources
	 * Shop_Order_Service::get_order_items() reads for a placed order.
	 *
	 * The product picture is deliberately not read: it plays no part in duty calculation and would
	 * cost an attachment query per line on every rate calculation.
	 *
	 * @param array                 $line     Package content row.
	 * @param Customs_Data_Resolver $resolver Customs data resolver.
	 *
	 * @return Item|null The item, or null for a virtual or unreadable line.
	 */
	private static function get_item( array $line, Customs_Data_Resolver $resolver ) {
		if ( ! isset( $line['data'] ) || ! $line['data'] instanceof WC_Product ) {
			return null;
		}

		/**
		 * Line product.
		 *
		 * @var WC_Product $product
		 */
		$product = $line['data'];
		if ( $product->is_virtual() ) {
			return null;
		}

		$quantity = isset( $line['quantity'] ) ? (int) $line['quantity'] : 1;

		$item = new Item();
		$item->setQuantity( $quantity );
		$item->setId( isset( $line['product_id'] ) ? $line['product_id'] : $product->get_id() );
		$item->setTotalPrice( isset( $line['line_total'] ) ? (float) $line['line_total'] : 0.0 );
		$item->setSku( $product->get_sku() );
		$item->setHeight( (float) $product->get_height() );
		$item->setLength( (float) $product->get_length() );
		$item->setWidth( (float) $product->get_width() );
		$item->setWeight( (float) $product->get_weight() );
		$item->setTitle( $product->get_title() );
		$item->setCategoryName( Customs_Data_Resolver::product_category_name( $product ) );
		// Per-unit value: the customs invoice this order builds declares it next to the quantity, so a
		// line subtotal would be counted once per unit and inflate the declared goods by the quantity.
		$line_subtotal = isset( $line['line_subtotal'] ) ? (float) $line['line_subtotal'] : 0.0;
		$item->setPrice( $quantity > 0 ? $line_subtotal / $quantity : $line_subtotal );
		$item->setConcept( $product->get_description() );

		$tariff_number = $resolver->resolve_item_tariff_number( $product );
		if ( '' !== $tariff_number ) {
			$item->setTariffNumber( $tariff_number );
		}

		$country_of_origin = $resolver->resolve_item_country_of_origin( $product );
		if ( '' !== $country_of_origin ) {
			$item->setCountryOfOrigin( $country_of_origin );
		}

		return $item;
	}

	/**
	 * Sums the itemised lines into the goods value declared for the shipment.
	 *
	 * Derived from the items rather than read off the cart so that the declared total and the invoice
	 * lines are the same figure by construction. They are what Packlink prices the duty from, and a
	 * total assembled from a different source than the lines beneath it is the kind of disagreement
	 * nobody notices until the invoice arrives.
	 *
	 * Rounded once, here: the lines are already money and no caller re-rounds this.
	 *
	 * @param Item[] $items Itemised, shippable cart lines.
	 *
	 * @return float Net, post-discount value of the goods.
	 */
	private static function declared_value( array $items ) {
		$total = 0.0;

		foreach ( $items as $item ) {
			$total += (float) $item->getTotalPrice();
		}

		return round( $total, 2 );
	}

	/**
	 * Returns the current WooCommerce customer, or null when there is none (the shipping step can be
	 * reached from contexts without a session).
	 *
	 * @return WC_Customer|null
	 */
	private static function get_customer() {
		if ( ! function_exists( 'WC' ) || null === WC() ) {
			return null;
		}

		return WC()->customer instanceof WC_Customer ? WC()->customer : null;
	}

	/**
	 * Reads a string value out of an array, defaulting to an empty string.
	 *
	 * @param array  $source Source array.
	 * @param string $key    Key to read.
	 *
	 * @return string
	 */
	private static function value( array $source, $key ) {
		return isset( $source[ $key ] ) ? (string) $source[ $key ] : '';
	}
}
