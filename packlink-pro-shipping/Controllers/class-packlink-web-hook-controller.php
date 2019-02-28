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

namespace Packlink\WooCommerce\Controllers;

use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\Utility\Events\EventBus;
use Packlink\BusinessLogic\ShippingMethod\Utility\ShipmentStatus;
use Packlink\BusinessLogic\WebHook\Events\ShipmentLabelEvent;
use Packlink\BusinessLogic\WebHook\Events\ShipmentStatusChangedEvent;
use Packlink\BusinessLogic\WebHook\Events\TrackingInfoEvent;
use Packlink\WooCommerce\Components\Services\Config_Service;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;

/**
 * Class Packlink_Web_Hook_Controller
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Web_Hook_Controller extends Packlink_Base_Controller {
	/**
	 * Packlink_Async_Process_Controller constructor.
	 */
	public function __construct() {
		$this->is_internal = false;
	}

	/**
	 * Web-hook action handler
	 */
	public function index() {
		if ( ! Shop_Helper::is_plugin_enabled() ) {
			exit();
		}

		if ( ! $this->is_post() ) {
			$this->redirect404();
		}

		/** @var EventBus $event_bus */
		$event_bus = ServiceRegister::getService( EventBus::CLASS_NAME );

		$json_raw = $this->get_raw_input();
		$payload  = json_decode( $json_raw, true );

		$this->validate_payload( $payload );
		$this->check_auth_token();

		$reference_id = $payload['data']['shipment_reference'];
		switch ( $payload['event'] ) {
			case 'shipment.carrier.success':
				$event_bus->fire( new ShipmentStatusChangedEvent( $reference_id, ShipmentStatus::STATUS_ACCEPTED ) );
				break;
			case 'shipment.delivered':
				$event_bus->fire( new ShipmentStatusChangedEvent( $reference_id, ShipmentStatus::STATUS_DELIVERED ) );
				break;
			case 'shipment.label.ready':
				$event_bus->fire( new ShipmentLabelEvent( $reference_id ) );
				break;
			case 'shipment.tracking.update':
				$event_bus->fire( new TrackingInfoEvent( $reference_id ) );
				break;
		}

		exit();
	}

	/**
	 * Validates request payload and returns bad request response in case of invalid payload.
	 *
	 * @param array $payload Request data.
	 */
	private function validate_payload( $payload ) {
		if ( empty( $payload )
		     || ! $payload['datetime']
		     || ! $payload['data']
		     || ! in_array( $payload['event'], array(
				'shipment.carrier.success',
				'shipment.carrier.fail',
				'shipment.label.ready',
				'shipment.label.fail',
				'shipment.tracking.update',
				'shipment.delivered',
			), true )
		) {
			$this->return_json( array( 'success' => false, 'message' => 'Invalid payload' ), 400 );
		}
	}

	/**
	 * Validates if authorization token is set.
	 */
	private function check_auth_token() {
		/** @var Config_Service $config_service */
		$config_service = ServiceRegister::getService( Config_Service::CLASS_NAME );
		$token          = $config_service->getAuthorizationToken();

		if ( $token === null ) {
			$this->return_json( array( 'success' => false, 'message' => 'Authorization token not found' ), 400 );
		}
	}
}
