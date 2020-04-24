<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Order;

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\ShipmentDraft\ShipmentDraftService;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Helper;
use WC_Order;

/**
 * Class Paid_Order_Handler
 *
 * @package Packlink\WooCommerce\Components\Utility
 */
class Paid_Order_Handler {
	/**
	 * Fully qualified name of this interface.
	 */
	const CLASS_NAME = __CLASS__;

	/**
	 * Creates Packlink shipment draft if the order is paid.
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * @param int      $order_id Order identifier.
	 * @param WC_Order $order WooCommerce order instance.
	 */
	public static function handle( $order_id, WC_Order $order ) {

		if ( $order->is_paid() && static::is_packlink_order( $order ) ) {
			/** @var ShipmentDraftService $draft_service */
			$draft_service = ServiceRegister::getService( ShipmentDraftService::CLASS_NAME );
			$draft_service->enqueueCreateShipmentDraftTask( (string) $order_id );
		}
	}

	/**
	 * Checks if order is Packlink order.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return bool Returns TRUE if the order is created with Packlink shipping method.
	 *
	 * @throws \Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException
	 * @throws \Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException
	 */
	protected static function is_packlink_order( WC_Order $order ) {
		$method = Shipping_Method_Helper::get_packlink_shipping_method_from_order( $order );

		return $method !== null;
	}
}
