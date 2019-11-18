<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Order;

use Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException;
use Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException;
use Logeecom\Infrastructure\ORM\Interfaces\RepositoryInterface;
use Logeecom\Infrastructure\ORM\QueryFilter\Operators;
use Logeecom\Infrastructure\ORM\QueryFilter\QueryFilter;
use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\Singleton;
use Packlink\BusinessLogic\Http\DTO\Shipment as Shipment_DTO;
use Packlink\BusinessLogic\Http\DTO\Tracking;
use Packlink\BusinessLogic\Order\Exceptions\OrderNotFound;
use Packlink\BusinessLogic\Order\Interfaces\OrderRepository;
use Packlink\BusinessLogic\Order\Models\OrderShipmentDetails;
use Packlink\BusinessLogic\Order\Objects\Address;
use Packlink\BusinessLogic\Order\Objects\Item;
use Packlink\BusinessLogic\Order\Objects\Order;
use Packlink\BusinessLogic\Order\Objects\Shipment;
use Packlink\BusinessLogic\Order\Objects\TrackingHistory;
use Packlink\BusinessLogic\ShippingMethod\Utility\ShipmentStatus;
use Packlink\WooCommerce\Components\Repositories\Base_Repository;
use Packlink\WooCommerce\Components\Services\Config_Service;
use WC_Order;
use WP_Term;

// @codingStandardsIgnoreStart
/**
 * Class Order_Repository
 *
 * @package Packlink\WooCommerce\Components\Repositories
 */
class Order_Repository extends Singleton implements OrderRepository {

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
	 * @var Base_Repository
	 */
	private $order_shipment_entity_repository;

	/**
	 * Order_Repository constructor.
	 */
	protected function __construct() {
		parent::__construct();

		$this->configuration = ServiceRegister::getService( Config_Service::CLASS_NAME );
	}

	/**
	 * Fetches and returns system order by its unique identifier.
	 *
	 * @param string $order_id $orderId Unique order id.
	 *
	 * @return Order Order object.
	 * @throws OrderNotFound When order with provided id is not found.
	 */
	public function getOrderAndShippingData( $order_id ) {
		$wc_order = $this->load_order_by_id( $order_id );

		$order = new Order();
		$order->setId( $order_id );
		$order->setStatus( $wc_order->get_status() );
		$order->setBasePrice( $wc_order->get_subtotal() );
		$order->setCartPrice( $wc_order->get_total() - $wc_order->get_shipping_total() );
		$order->setCurrency( $wc_order->get_currency() );
		$order->setCustomerId( $wc_order->get_customer_id() );
		$order->setNetCartPrice( $order->getCartPrice() - $wc_order->get_cart_tax() );
		$order->setOrderNumber( $wc_order->get_order_number() );
		$order->setTotalPrice( $wc_order->get_total() );
		$order->setShippingPrice( $wc_order->get_shipping_total() );
		$order->setItems( $this->get_order_items( $wc_order ) );
		$order->setShipment( $this->get_order_shipment( $wc_order ) );
		$order->setPacklinkShipmentLabels( $wc_order->get_meta( Order_Meta_Keys::LABELS ) ?: array() );

		$order->setBillingAddress( $this->get_billing_address( $wc_order ) );
		$order->setShippingAddress( $this->get_shipping_address( $wc_order ) );
		$order->setShippingMethodId( $this->get_shipping_method_id( $wc_order ) );

		if ( $wc_order->meta_exists( Order_Meta_Keys::DROP_OFF_ID ) ) {
			$order->setShippingDropOffId( $wc_order->get_meta( Order_Meta_Keys::DROP_OFF_ID ) );
		}

		return $order;
	}

	/**
	 * Sets order packlink reference number.
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * @param string $order_id Unique order id.
	 * @param string $shipment_reference Packlink shipment reference.
	 *
	 * @throws OrderNotFound When order with provided id is not found.
	 */
	public function setReference( $order_id, $shipment_reference ) {
		$wc_order = $this->load_order_by_id( $order_id );

		$order_shipment = new Order_Shipment_Entity();
		$order_shipment->setStatus( ShipmentStatus::STATUS_PENDING );
		$order_shipment->setPacklinkShipmentReference( $shipment_reference );
		$order_shipment->setWoocommerceOrderId( $order_id );

		$this->get_order_shipment_entity_repository()->save( $order_shipment );

		$wc_order->update_meta_data( Order_Meta_Keys::SHIPMENT_REFERENCE, $shipment_reference );
		$wc_order->update_meta_data( Order_Meta_Keys::SHIPMENT_STATUS, ShipmentStatus::STATUS_PENDING );
		$wc_order->update_meta_data( Order_Meta_Keys::SHIPMENT_STATUS_TIME, time() );

		$wc_order->save();
	}

	/**
	 * Sets order packlink shipment tracking history to an order for given shipment.
	 *
	 * @param Shipment_DTO $shipment Packlink shipment details.
	 * @param Tracking[]   $tracking_history Shipment tracking history.
	 *
	 * @throws OrderNotFound When order with provided reference is not found.
	 */
	public function updateTrackingInfo(Shipment_DTO $shipment, array $tracking_history) {
		$order = $this->load_order_by_reference( $shipment->reference );

		if (!empty($tracking_history)) {
			usort(
				$tracking_history,
				static function ( Tracking $a, Tracking $b ) {
					return $b->timestamp - $a->timestamp;
				}
			);

			$tracking = array();
			foreach ( $tracking_history as $item ) {
				$tracking[] = $item->toArray();
			}

			$order->update_meta_data( Order_Meta_Keys::TRACKING_HISTORY, wp_json_encode( $tracking ) );
		}

		$order->update_meta_data( Order_Meta_Keys::SHIPMENT_PRICE, $shipment->price );
		$order->update_meta_data( Order_Meta_Keys::CARRIER_TRACKING_URL, $shipment->carrierTrackingUrl );
		if ( ! empty( $shipment->trackingCodes ) ) {
			$order->update_meta_data( Order_Meta_Keys::CARRIER_TRACKING_CODES, $shipment->trackingCodes );
		}

		$order->save();
	}

	/**
	 * Sets order packlink shipping status to an order by shipment reference.
	 *
	 * @param string $shipment_reference Packlink shipment reference.
	 * @param string $shipping_status Packlink shipping status.
	 *
	 * @throws OrderNotFound When order with provided reference is not found.
	 */
	public function setShippingStatusByReference( $shipment_reference, $shipping_status ) {
		$order = $this->load_order_by_reference( $shipment_reference );

		$order->update_meta_data( Order_Meta_Keys::SHIPMENT_STATUS, $shipping_status );
		$order->update_meta_data( Order_Meta_Keys::SHIPMENT_STATUS_TIME, time() );

		$status_map = $this->configuration->getOrderStatusMappings();
		if ( isset( $status_map[ $shipping_status ] ) ) {
			$order->set_status( $status_map[ $shipping_status ], __( 'Status set by Packlink PRO.', 'packlink-pro-shipping' ) );
		}

		$order->save();
		$this->update_order_shipment_status( $shipment_reference, $shipping_status );
	}

	/**
	 * Returns shipment references of the orders that have not yet been completed.
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * @return array Array of shipment references.
	 * @throws QueryFilterInvalidParamException
	 */
	public function getIncompleteOrderReferences() {
		$filter     = new QueryFilter();
		$references = array();

		/** @noinspection PhpUnhandledExceptionInspection */
		$filter->where( 'status', Operators::NOT_EQUALS, ShipmentStatus::STATUS_DELIVERED );
		/**
		 * Order shipment entity.
		 *
		 * @var Order_Shipment_Entity $order_details
		 */
		$orders = $this->get_order_shipment_entity_repository()->select( $filter );

		foreach ( $orders as $order_details ) {
			if ( null !== $order_details->getPacklinkShipmentReference() ) {
				$references[] = $order_details->getPacklinkShipmentReference();
			}
		}

		return $references;
	}

	/**
	 * Retrieves list of order references where order is in one of the provided statuses.
	 *
	 * @param array $statuses List of order statuses.
	 *
	 * @return string[] Array of shipment references.
	 *
	 * @throws QueryFilterInvalidParamException
	 */
	public function getOrderReferencesWithStatus( array $statuses )
	{
		$filter = new QueryFilter();

		foreach ($statuses as $status) {
			$filter->orWhere( 'status', Operators::EQUALS, $status );
		}

		$orders = $this->get_order_shipment_entity_repository()->select( $filter );

		$result = array( );
		/** @var OrderShipmentDetails $order */
		foreach ( $orders as $order ) {
			$result[] = $order->getReference();
		}

		return $result;
	}

	/**
	 * Sets shipping price to an order by shipment reference.
	 *
	 * @param string $shipment_reference Packlink shipment reference.
	 * @param float  $price Shipment price.
	 *
	 * @throws OrderNotFound When order with provided reference is not found.
	 */
	public function setShippingPriceByReference( $shipment_reference, $price ) {
		$order = $this->load_order_by_reference( $shipment_reference );
		$order->update_meta_data( Order_Meta_Keys::SHIPMENT_PRICE, $price );

		$order->save();
	}

	/**
	 * Marks shipment identified by provided reference as deleted on Packlink.
	 *
	 * @param string $shipmentReference Packlink shipment reference.
	 *
	 * @throws OrderNotFound
	 */
	public function markShipmentDeleted( $shipmentReference ) {
		$order_shipment_entity = $this->get_order_shipment_entity( $shipmentReference );

		$this->get_order_shipment_entity_repository()->delete( $order_shipment_entity );
	}

	/**
	 * Returns whether shipment identified by provided reference is deleted on Packlink or not.
	 *
	 * @param string $shipmentReference Packlink shipment reference.
	 *
	 * @return bool Returns TRUE if shipment has been deleted; otherwise returns FALSE.
	 */
	public function isShipmentDeleted( $shipmentReference ) {
		try {
			$this->get_order_shipment_entity( $shipmentReference );

			return false;
		} catch ( OrderNotFound $e ) {
			return true;
		}
	}

	/**
	 * Fetches and returns order instance.
	 *
	 * @param string $order_id $orderId Unique order id.
	 *
	 * @return WC_Order WooCommerce order object.
	 * @throws OrderNotFound When order with provided id is not found.
	 */
	private function load_order_by_id( $order_id ) {
		$wc_order = \WC_Order_Factory::get_order( $order_id );
		if ( false === $wc_order ) {
			/* translators: %s: order identifier */
			throw new OrderNotFound( sprintf( __( 'Order with id(%s) not found!', 'packlink-pro-shipping' ), $order_id ) );
		}

		return $wc_order;
	}

	/**
	 * Fetches and returns order instance.
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * @param string $shipment_reference Packlink shipment reference.
	 *
	 * @return WC_Order WooCommerce order object.
	 * @throws OrderNotFound When order with provided id is not found.
	 */
	private function load_order_by_reference( $shipment_reference ) {
		$order_shipment = $this->get_order_shipment_entity( $shipment_reference );

		return $this->load_order_by_id( $order_shipment->getWoocommerceOrderId() );
	}

	/**
	 * Updates order shipment status.
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * @param string $shipment_reference Packlink shipment reference.
	 * @param string $status Shipment status.
	 *
	 * @throws OrderNotFound When order with provided id is not found.
	 */
	private function update_order_shipment_status( $shipment_reference, $status ) {
		$order_shipment = $this->get_order_shipment_entity( $shipment_reference );
		$order_shipment->setStatus( $status );

		$this->get_order_shipment_entity_repository()->save( $order_shipment );
	}

	/**
	 * Returns category name.
	 *
	 * @param \WC_Product $product WooCommerce product.
	 *
	 * @return string|null Category name.
	 */
	private function get_product_category_name( \WC_Product $product ) {
		$category_ids = $product->get_category_ids();
		if ( empty( $category_ids ) ) {
			return null;
		}

		$category = WP_Term::get_instance( $category_ids[0] );

		return $category instanceof WP_Term ? $category->name : null;
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
			if ( $product->is_downloadable() || $product->is_virtual() ) {
				continue;
			}

			$item = new Item();
			$item->setQuantity( $wc_item->get_quantity() );
			$item->setId( $wc_item->get_product_id() );
			$item->setTotalPrice( (float) $wc_item->get_total() );
			$item->setSku( $product->get_sku() );
			$item->setHeight( (float) $product->get_height() );
			$item->setLength( (float) $product->get_length() );
			$item->setWidth( (float) $product->get_width() );
			$item->setWeight( (float) $product->get_weight() );
			$item->setTitle( $product->get_title() );
			$item->setCategoryName( $this->get_product_category_name( $product ) );
			$item->setPrice( $wc_item->get_subtotal() );
			$item->setConcept( $product->get_description() );

			$picture = wp_get_attachment_image_src( $product->get_image_id(), 'single' );
			if ( $picture ) {
				$item->setPictureUrl( $picture[0] );
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Builds Shipment object for provided order.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 *
	 * @return Shipment Shipment.
	 */
	private function get_order_shipment( WC_Order $wc_order ) {
		$shipment = new Shipment();
		$shipment->setReferenceNumber( $wc_order->get_meta( Order_Meta_Keys::SHIPMENT_REFERENCE ) );
		$shipment->setTrackingNumber( $wc_order->get_meta( Order_Meta_Keys::SHIPMENT_REFERENCE ) );
		$shipment->setStatus( $wc_order->get_meta( Order_Meta_Keys::SHIPMENT_STATUS ) );

		$tracking_json    = $wc_order->get_meta( Order_Meta_Keys::TRACKING_HISTORY );
		$tracking_history = array();
		if ( $tracking_json ) {
			$entries = json_decode( $tracking_json, true );
			if ( is_array( $entries ) ) {
				foreach ( $entries as $item ) {
					$tracking = new TrackingHistory();
					$tracking->setDescription( $item['description'] );
					$tracking->setCity( $item['city'] );
					$tracking->setTimestamp( $item['timestamp'] );
					$tracking_history[] = $tracking;
				}
			}
		}

		$shipment->setTrackingHistory( $tracking_history );

		return $shipment;
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
	 * Returns Packlink shipping method id.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 *
	 * @return int|null Returns shipping method id.
	 */
	private function get_shipping_method_id( WC_Order $wc_order ) {
		$method = Order_Details_Helper::get_packlink_shipping_method( $wc_order );

		return $method ? $method->getId() : null;
	}

	/**
	 * Fetches and returns order shipment entity.
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * @param string $shipment_reference Shipment reference number.
	 *
	 * @return Order_Shipment_Entity Order shipment entity.
	 * @throws OrderNotFound When order with provided id is not found.
	 */
	private function get_order_shipment_entity( $shipment_reference ) {
		$order_shipment = null;
		$query_filter = new QueryFilter();
		try {
			$query_filter->where( 'packlinkShipmentReference', '=', $shipment_reference );
			/**
			 * Order shipment entity.
			 *
			 * @var Order_Shipment_Entity $order_shipment
			 */
			$order_shipment = $this->get_order_shipment_entity_repository()->selectOne( $query_filter );
		} catch ( QueryFilterInvalidParamException $e ) {
		}

		if ( null === $order_shipment ) {
			/* translators: %s: order identifier */
			throw new OrderNotFound( sprintf( __( 'Order with shipment reference(%s) not found!', 'packlink-pro-shipping' ), $shipment_reference ) );
		}

		return $order_shipment;
	}

	/**
	 * Returns an instance of order shipment entity repository.
	 *
	 * @return RepositoryInterface|Base_Repository
	 */
	private function get_order_shipment_entity_repository()
	{
		if ( null === $this->order_shipment_entity_repository ) {
			try {
				$this->order_shipment_entity_repository = RepositoryRegistry::getRepository( Order_Shipment_Entity::CLASS_NAME );
			} catch ( RepositoryNotRegisteredException $e ) {
			}
		}

		return $this->order_shipment_entity_repository;
	}
}
