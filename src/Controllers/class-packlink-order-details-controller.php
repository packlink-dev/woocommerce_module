<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\OrderShipmentDetails\OrderShipmentDetailsService;
use Packlink\BusinessLogic\ShipmentDraft\ShipmentDraftService;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Helper;
use Packlink\WooCommerce\Components\Utility\Script_Loader;
use WP_Post;

/**
 * Class Packlink_Order_Detail
 *
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Order_Details_Controller extends Packlink_Base_Controller {

	/**
	 * Renders Packlink PRO Shipping post box content.
	 *
	 * @param WP_Post $wp_post WordPress post object.
	 *
	 * @throws \Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException
	 * @throws \Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException
	 *
	 * @noinspection PhpUnusedLocalVariableInspection
	 */
	public function render( WP_Post $wp_post ) {
		Script_Loader::load_css( array( 'css/packlink-order-details.css' ) );
		Script_Loader::load_js( array( 'js/core/packlink-ajax-service.js', 'js/packlink-order-details.js' ) );

		$wc_order = \WC_Order_Factory::get_order( $wp_post->ID );

		/** @var OrderShipmentDetailsService $shipment_details_service */
		$shipment_details_service = ServiceRegister::getService( OrderShipmentDetailsService::CLASS_NAME );
		/** @var ShipmentDraftService $draft_service */
		$draft_service = ServiceRegister::getService( ShipmentDraftService::CLASS_NAME );
		$order_details      = $shipment_details_service->getDetailsByOrderId( (string) $wp_post->ID );
		$last_status_update = '';
		if ( $order_details && $order_details->getLastStatusUpdateTime() ) {
			$update_timestamp   = $order_details->getLastStatusUpdateTime()->getTimestamp();
			$last_status_update = date( get_option( 'links_updated_date_format' ), $update_timestamp );
		}

		$shipment_deleted = $order_details ? $shipment_details_service->isShipmentDeleted( $order_details->getReference() ) : true;
		$draft_status = $draft_service->getDraftStatus( (string) $wp_post->ID );
		$shipping_method = Shipping_Method_Helper::get_packlink_shipping_method_from_order( $wc_order );

		include dirname( __DIR__ ) . '/resources/views/meta-post-box.php';
	}

	/**
	 * Forces create of shipment draft for order.
	 *
	 * @throws \Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException
	 * @throws \Logeecom\Infrastructure\TaskExecution\Exceptions\QueueStorageUnavailableException
	 * @throws \Packlink\BusinessLogic\ShipmentDraft\Exceptions\DraftTaskMapExists
	 * @throws \Packlink\BusinessLogic\ShipmentDraft\Exceptions\DraftTaskMapNotFound
	 */
	public function create_draft() {
		$this->validate( 'yes' );
		$raw     = $this->get_raw_input();
		$payload = json_decode( $raw, true );
		if ( ! array_key_exists( 'id', $payload ) ) {
			$this->return_json( array( 'success' => false ), 400 );
		}

		/** @var ShipmentDraftService $draft_service */
		$draft_service = ServiceRegister::getService( ShipmentDraftService::CLASS_NAME );
		$draft_service->enqueueCreateShipmentDraftTask( (string) $payload['id'] );

		$this->return_json( array( 'success' => true ) );
	}
}
