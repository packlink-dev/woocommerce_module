<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Order;

use Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException;
use Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException;
use Logeecom\Infrastructure\ORM\QueryFilter\Operators;
use Logeecom\Infrastructure\ORM\QueryFilter\QueryFilter;
use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\Singleton;
use Packlink\BusinessLogic\Http\DTO\Shipment;
use Packlink\BusinessLogic\Order\Exceptions\OrderNotFound;
use Packlink\BusinessLogic\Order\Interfaces\ShopOrderService as BaseShopOrderService;
use Packlink\BusinessLogic\Order\Objects\Address;
use Packlink\BusinessLogic\Order\Objects\Item;
use Packlink\BusinessLogic\Order\Objects\Order;
use Packlink\WooCommerce\Components\Checkout\Ddp_Checkout;
use Packlink\WooCommerce\Components\Services\Config_Service;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Helper;
use WC_Order;

/**
 * Class Shop_Order_Service
 *
 * @package Packlink\WooCommerce\Components\Repositories
 */
class Shop_Order_Service extends Singleton implements BaseShopOrderService {
	/**
	 * Singleton instance of this class.
	 *
	 * @var static
	 */
	protected static $instance;

	/**
	 * Configuration service.
	 *
	 * @var Config_Service
	 */
	protected $configuration;

	/**
	 * Customs data resolver, shared with the checkout-time duty estimate.
	 *
	 * @var Customs_Data_Resolver
	 */
	private $customs_resolver;

	/**
	 * Order_Repository constructor.
	 */
	protected function __construct() {
		parent::__construct();

		$this->configuration    = ServiceRegister::getService( Config_Service::CLASS_NAME );
		$this->customs_resolver = new Customs_Data_Resolver();
	}

	/**
	 * Fetches and returns system order by its unique identifier.
	 *
	 * @param string $order_id $orderId Unique order id.
	 *
	 * @return Order Order object.
	 *
	 * @throws OrderNotFound When order with provided id is not found.
	 * @throws QueryFilterInvalidParamException
	 * @throws RepositoryNotRegisteredException
	 */
	public function getOrderAndShippingData( $order_id ) {
		$wc_order = $this->get_order_by_id( $order_id );

		/**
		 * Reference this order is known by on the Packlink side: the draft's shipment reference and the
		 * customs invoice number both come from it.
		 *
		 * Packlink matches a draft against the order number it arrives with, per account - so a bare
		 * sequential number collides the moment one account serves more than one shop. Two shops both
		 * reach order 254 and the second draft resolves to the first shop's shipment, inheriting its
		 * carrier, its status and its customs invoice while no draft of its own is ever created.
		 *
		 * PrestaShop avoids this by sending its own random per-order reference rather than the id.
		 * WooCommerce has no equivalent field, so the order number carries a short digest of the order
		 * key, which is random per order - the digest and not the key itself, because the key authorises
		 * access to the order-received page while this value is shown in the Packlink panel and printed
		 * on the customs invoice.
		 */
		$reference = $this->get_order_reference( $wc_order );

		$order = new Order();
		$order->setId( $reference );
		$order->setOrderNumber( $reference );
		$order->setStatus( $wc_order->get_status() );
		$order->setBasePrice( $wc_order->get_subtotal() );
		$order->setCartPrice( $wc_order->get_total() - $wc_order->get_shipping_total() );
		$order->setCurrency( $wc_order->get_currency() );
		$order->setCustomerId( $wc_order->get_customer_id() );
		$order->setNetCartPrice( $order->getCartPrice() - $wc_order->get_cart_tax() );
		$order->setTotalPrice( $wc_order->get_total() );
		$order->setShippingPrice( $wc_order->get_shipping_total() );

		// The customs invoice declares the freight, and the core falls back to the order total when the
		// platform leaves this unset - which double-counts the goods that are already itemised on the
		// invoice and inflates every duty computed from it (C8). Duties ride on their own fee line here,
		// so the shipping total is the freight alone and needs nothing subtracted from it.
		$order->setShippingCost( (float) $wc_order->get_shipping_total() );

		$items = $this->get_order_items( $wc_order );
		$order->setItems( $items );
		$order->setTotalWeight( Customs_Data_Resolver::total_weight( $items ) );

		$order->setBillingAddress( $this->get_billing_address( $wc_order ) );
		$order->setShippingAddress( $this->get_shipping_address( $wc_order ) );

		// One resolved value feeds both customs attributes: the core sends it as the receiver
		// tax id (private person) or VAT number (company) based on the receiver user type.
		$tax_id = $this->customs_resolver->resolve_tax_id_from_order( $wc_order );
		if ( '' !== $tax_id ) {
			$order->setTaxId( $tax_id );
			$order->setVatNumber( $tax_id );
		}

        $order->setPaymentId($wc_order->get_payment_method());

		// Core turns this flag into `selected_products.ddp.is_selected` on the shipment draft, and a
		// service whose DDP support level is mandatory rejects the purchase with
		// `400 mandatory_ddp_not_selected` when the draft omits it. So carrying the selection is a
		// correctness requirement, not a display one. The charged amount is read back rather than
		// recomputed, so what the shipment records is exactly what the customer paid.
		if ( 'yes' === $wc_order->get_meta( Ddp_Checkout::META_SELECTED ) ) {
			$order->setDdpSelected( true );
			$order->setDdpCost( (float) $wc_order->get_meta( Ddp_Checkout::META_COST ) );
		}

		$shipping_method = Shipping_Method_Helper::get_packlink_shipping_method_from_order( $wc_order );
		if ( null !== $shipping_method ) {
			$order->setShippingMethodId( $shipping_method->getId() );
		}

		$drop_off_point_id = $this->get_drop_off_point_id( (int) $order_id );
		if ( $drop_off_point_id ) {
			$order->setShippingDropOffId( $drop_off_point_id );
		}

		return $order;
	}

	/**
	 * @inheritDoc
	 */
	public function updateTrackingInfo( $order_id, Shipment $shipment, array $tracking_history ) {
	}

	/**
	 * @inheritDoc
	 */
	public function updateShipmentStatus( $order_id, $shipping_status ) {
		$order      = $this->get_order_by_id( $order_id );
		$status_map = $this->configuration->getOrderStatusMappings();
		$old_status = $order->get_status();
		if ( $old_status === 'cancelled' ) {
			// We don't want to update order status of cancelled order.
			return;
		}

		if ( ! empty( $status_map[ $shipping_status ] ) && $status_map[ $shipping_status ] !== $old_status ) {
			$order->set_status( $status_map[ $shipping_status ], __( 'Status set by Packlink PRO.', 'packlink-pro-shipping' ) );
		}

		$order->save();
	}

	/**
	 * Returns order instance, if exists.
	 *
	 * @param string $order_id $orderId Unique order id.
	 *
	 * @return WC_Order WooCommerce order object.
	 * @throws OrderNotFound When order with provided id is not found.
	 */
	public function get_order_by_id( $order_id ) {
		$wc_order = \WC_Order_Factory::get_order( $order_id );
		if ( false === $wc_order ) {
			/* translators: %s: order identifier */
			throw new OrderNotFound( sprintf( __( 'Order with id(%s) not found!', 'packlink-pro-shipping' ), $order_id ) );
		}

		return $wc_order;
	}

	/**
	 * Returns the reference Packlink knows this order by: the shop's order number with a short digest
	 * of the order key appended, so it cannot collide with the same number in another shop sharing the
	 * account. Stable for the life of the order, so re-sending resolves to the same shipment instead of
	 * creating an orphan draft.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 *
	 * @return string
	 */
	private function get_order_reference( WC_Order $wc_order ) {
		$seed = (string) $wc_order->get_order_key();

		if ( '' === $seed ) {
			// Orders created programmatically can carry no key. The shop's own address then keeps the
			// reference distinct between shops, which is what the collision is about.
			$seed = get_site_url() . '|' . $wc_order->get_id();
		}

		return $wc_order->get_order_number() . '-' . substr( md5( $seed ), 0, 6 );
	}

	/**
	 * Returns array of formatted order items.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 *
	 * @return Item[] Array of formatted order items.
	 */
	private function get_order_items( WC_Order $wc_order ) {
		$items = array();
		/**
		 * WooCommerce order item.
		 *
		 * @var \WC_Order_Item_Product $wc_item
		 */
		foreach ( $wc_order->get_items() as $wc_item ) {
			$product = $wc_item->get_product();
			if ( $product->is_virtual() ) {
				continue;
			}

			$quantity = (int) $wc_item->get_quantity();

			$item = new Item();
			$item->setQuantity( $quantity );
			$item->setId( $wc_item->get_product_id() );
			$item->setTotalPrice( (float) $wc_item->get_total() );
			$item->setSku( $product->get_sku() );
			$item->setHeight( (float) $product->get_height() );
			$item->setLength( (float) $product->get_length() );
			$item->setWidth( (float) $product->get_width() );
			$item->setWeight( (float) $product->get_weight() );
			$item->setTitle( $product->get_title() );
			$item->setCategoryName( Customs_Data_Resolver::product_category_name( $product ) );
			// The customs invoice declares a per-unit value next to the quantity, so a line subtotal
			// here is multiplied by the quantity a second time: a 9 x 44.99 line goes out as 404.91 per
			// unit, and Packlink refuses the shipment because the declared goods exceed the package
			// value. Tax-excluded, like the value the invoice asks for.
			$item->setPrice( $quantity > 0 ? (float) $wc_item->get_subtotal() / $quantity : (float) $wc_item->get_subtotal() );
			$item->setConcept( $product->get_description() );

			$tariff_number = $this->customs_resolver->resolve_item_tariff_number( $product );
			if ( '' !== $tariff_number ) {
				$item->setTariffNumber( $tariff_number );
			}

			$country_of_origin = $this->customs_resolver->resolve_item_country_of_origin( $product );
			if ( '' !== $country_of_origin ) {
				$item->setCountryOfOrigin( $country_of_origin );
			}

			$picture = wp_get_attachment_image_src( $product->get_image_id(), 'single' );
			if ( $picture ) {
				$item->setPictureUrl( $picture[0] );
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Returns billing address.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 *
	 * @return Address Billing address.
	 */
	private function get_billing_address( WC_Order $wc_order ) {
		$address = new Address();
		if ( $wc_order->get_billing_address_1() || $wc_order->get_billing_address_2() ) {
			$address->setEmail( $wc_order->get_billing_email() );
			$address->setPhone( $wc_order->get_billing_phone() );
			$address->setName( $wc_order->get_billing_first_name() );
			$address->setSurname( $wc_order->get_billing_last_name() );
			$address->setCompany( $wc_order->get_billing_company() );
			$address->setCity( $wc_order->get_billing_city() );
			$address->setStreet1( $wc_order->get_billing_address_1() );
			$address->setStreet2( $wc_order->get_billing_address_2() );
			$address->setCountry( $wc_order->get_billing_country() );
			$address->setZipCode( $wc_order->get_billing_postcode() );
		}

		return $address;
	}

	/**
	 * Returns shipping address.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 *
	 * @return Address Shipping address.
	 */
	private function get_shipping_address( WC_Order $wc_order ) {
		$address = new Address();
		if ( $wc_order->get_shipping_address_1() || $wc_order->get_shipping_address_2() ) {
			$address->setEmail( $wc_order->get_billing_email() );
			$address->setPhone( $wc_order->get_billing_phone() );
			$address->setName( $wc_order->get_shipping_first_name() );
			$address->setSurname( $wc_order->get_shipping_last_name() );
			$address->setCompany( $wc_order->get_shipping_company() );
			$address->setCity( $wc_order->get_shipping_city() );
			$address->setStreet1( $wc_order->get_shipping_address_1() );
			$address->setStreet2( $wc_order->get_shipping_address_2() );
			$address->setCountry( $wc_order->get_shipping_country() );
			$address->setZipCode( $wc_order->get_shipping_postcode() );
		} else {
			$address = $this->get_billing_address( $wc_order );
		}

		return $address;
	}

	/**
	 * Returns order drop-off point ID, if exists.
	 *
	 * @param int $order_id
	 *
	 * @return int|null
	 *
	 * @throws QueryFilterInvalidParamException
	 * @throws RepositoryNotRegisteredException
	 */
	private function get_drop_off_point_id( $order_id ) {
		$order_drop_off_map_repository = RepositoryRegistry::getRepository( Order_Drop_Off_Map::CLASS_NAME );

		$filter = new QueryFilter();
		$filter->where( 'order_id', Operators::EQUALS, $order_id );

		/** @var Order_Drop_Off_Map $order_drop_off_map */
		$order_drop_off_map = $order_drop_off_map_repository->selectOne( $filter );

		return $order_drop_off_map ? $order_drop_off_map->get_drop_off_point_id() : null;
	}
}
