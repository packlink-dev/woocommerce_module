<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Order;

use Logeecom\Infrastructure\ORM\QueryFilter\QueryFilter;
use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\QueueItem;
use Logeecom\Infrastructure\TaskExecution\QueueService;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\BusinessLogic\ShippingMethod\Utility\ShipmentStatus;
use Packlink\BusinessLogic\Tasks\SendDraftTask;
use Packlink\WooCommerce\Components\Services\Config_Service;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Helper;
use Packlink\WooCommerce\Components\Utility\Task_Queue;
use WC_Order;
use WC_Order_Factory;
use WP_Post;

/**
 * Class Order_Details_Helper
 *
 * @package Packlink\WooCommerce\Components\Utility
 */
class Order_Details_Helper {
	/**
	 * Fully qualified name of this interface.
	 */
	const CLASS_NAME = __CLASS__;

	/**
	 * Checks if order is made using one of Packlink shipping methods.
	 *
	 * @param WP_Post $wp_post Order post.
	 *
	 * @return bool True if Packlink shipping is used for order.
	 */
	public static function is_packlink_order( WP_Post $wp_post ) {
		$order = WC_Order_Factory::get_order( $wp_post->ID );
		if ( false === $order ) {
			return false;
		}

		return $order->meta_exists( Order_Meta_Keys::IS_PACKLINK );
	}

	/**
	 * Returns label status.
	 *
	 * @param WP_Post $wp_post Order post.
	 *
	 * @return array Returns flags if label is available and if labels is already printed.
	 */
	public static function get_label_status( WP_Post $wp_post ) {
		$order = WC_Order_Factory::get_order( $wp_post->ID );
		if ( false === $order ) {
			return array();
		}

		$labels = $order->get_meta( Order_Meta_Keys::LABELS );

		return array(
			'available' => ! empty( $labels ),
			'printed'   => 'yes' === $order->get_meta( Order_Meta_Keys::LABEL_PRINTED ),
			'labels'    => ! empty( $labels ) ? $labels : array(),
		);
	}

	/**
	 * Returns order detail for provided order post.
	 *
	 * @param WP_Post $wp_post WordPress post.
	 *
	 * @return Order_Details Order details.
	 */
	public static function get_order_details( WP_Post $wp_post ) {
		$order     = WC_Order_Factory::get_order( $wp_post->ID );
		$reference = $order->get_meta( Order_Meta_Keys::SHIPMENT_REFERENCE );

		$order_details = new Order_Details();
		$order_details->set_id( $wp_post->ID );
		$order_details->set_packlink_order( $order->meta_exists( Order_Meta_Keys::IS_PACKLINK ) );
		$order_details->set_packlink_price( $order->get_meta( Order_Meta_Keys::SHIPMENT_PRICE ) ?: 0.0 );
		$order_details->set_reference( $reference ?: '' );
		$labels = $order->get_meta( Order_Meta_Keys::LABELS );
		$order_details->set_label( ! empty( $labels ) ? $labels[0] : null );

		static::set_carrier_data( $order, $order_details );
		static::set_details_status( $order, $order_details );

		return $order_details;
	}

	/**
	 * Returns Packlink shipping method used in provided WooCommerce order.
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * @param WC_Order $order WooCommerce order.
	 *
	 * @return ShippingMethod Shipping method.
	 */
	public static function get_packlink_shipping_method( WC_Order $order ) {
		$packlink_shipping_method_id = (int) $order->get_meta( Order_Meta_Keys::SHIPPING_ID );
		if ( -1 === $packlink_shipping_method_id ) {
			/**
			 * Configuration service.
			 *
			 * @var Config_Service $configuration
			 */
			$configuration = ServiceRegister::getService( Config_Service::CLASS_NAME );
			return $configuration->get_default_shipping_method();
		}

		$query_filter = new QueryFilter();
		/** @noinspection PhpUnhandledExceptionInspection */
		$query_filter->where( 'id', '=', $packlink_shipping_method_id );

		/** @noinspection PhpUnhandledExceptionInspection */
		$repository = RepositoryRegistry::getRepository( ShippingMethod::CLASS_NAME );
		/**
		 * Shipping method service.
		 *
		 * @var ShippingMethod $shipping_method
		 */
		$shipping_method = $repository->selectOne( $query_filter );

		return $shipping_method;
	}

	/**
	 * Creates and queues shipment drafts for paid orders.
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * @param int      $order_id Order identifier.
	 * @param WC_Order $order WooCommerce order instance.
	 */
	public static function queue_draft( $order_id, WC_Order $order ) {
		if (
			$order->meta_exists( Order_Meta_Keys::IS_PACKLINK )
			&& $order->is_paid()
			&& ! $order->meta_exists( Order_Meta_Keys::SHIPMENT_REFERENCE )
		) {
			/** @noinspection PhpUnhandledExceptionInspection */
			$task_id = Task_Queue::enqueue( new SendDraftTask( $order_id ) );
			$order->update_meta_data( Order_Meta_Keys::SEND_DRAFT_TASK_ID, $task_id );
			$order->save();
		}
	}

	/**
	 * Resolves order details status.
	 *
	 * @param WC_Order      $order Shop order.
	 * @param Order_Details $order_details Order details.
	 */
	private static function set_details_status( WC_Order $order, Order_Details $order_details ) {
		$status      = $order->get_meta( Order_Meta_Keys::SHIPMENT_STATUS );
		$status_time = $order->get_meta( Order_Meta_Keys::SHIPMENT_STATUS_TIME );

		$task_id = $order->get_meta( Order_Meta_Keys::SEND_DRAFT_TASK_ID );
		if ( $task_id && ! $order_details->get_reference() && $order_details->is_packlink_order() ) {
			$order_details->set_creating( true );
			/**
			 * Queue service.
			 *
			 * @var QueueService $service
			 */
			$service    = ServiceRegister::getService( QueueService::CLASS_NAME );
			$queue_item = $service->find( $task_id );
			if ( $queue_item && QueueItem::FAILED === $queue_item->getStatus() ) {
				$status      = $queue_item->getStatus();
				$status_time = $queue_item->getLastUpdateTimestamp();
				$order_details->set_message( $queue_item->getFailureDescription() );
				$order_details->set_creating( false );
			}
		}

		$order_details->set_status( $status ?: ShipmentStatus::STATUS_PENDING );
		$order_details->set_status_time( $status_time ? date( get_option( 'links_updated_date_format' ), $status_time ) : '' );
	}

	/**
	 * Resolves order carrier data.
	 *
	 * @param WC_Order      $order Shop order.
	 * @param Order_Details $order_details Order details.
	 */
	private static function set_carrier_data( WC_Order $order, Order_Details $order_details ) {
		$shipping_method = static::get_packlink_shipping_method( $order );

		$carrier_name = '';
		$carrier_logo = '';
		if ( $shipping_method ) {
			$carrier_name = $shipping_method->getTitle();
			$carrier_logo = Shipping_Method_Helper::get_carrier_logo( $shipping_method->getCarrierName() );
		}

		$order_details->set_carrier_image( $carrier_logo );
		$order_details->set_carrier_name( $carrier_name );
		$order_details->set_carrier_codes( $order->get_meta( Order_Meta_Keys::CARRIER_TRACKING_CODES ) ?: array() );
		$order_details->set_carrier_url( $order->get_meta( Order_Meta_Keys::CARRIER_TRACKING_URL ) ?: '' );
	}
}
