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

use Logeecom\Infrastructure\Logger\Logger;
use Packlink\BusinessLogic\WebHook\WebHookEventHandler;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;

/**
 * Class Packlink_Web_Hook_Controller
 *
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Web_Hook_Controller extends Packlink_Base_Controller {
	/**
	 * Packlink_Web_Hook_Controller constructor.
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

		$input  = $this->get_raw_input();
		$result = WebHookEventHandler::getInstance()->handle( $input );

		if ( ! $result ) {
			// A rejected payload is discarded before anything above debug level is written, so a
			// production install with debug mode off keeps no record of what was refused. Log the
			// event name and reference so recurring rejections are diagnosable without having to
			// run full debug logging in production.
			$payload = json_decode( $input, false );

			Logger::logWarning(
				'Webhook from Packlink was rejected.',
				'Core',
				array(
					'event'     => isset( $payload->event ) ? $payload->event : '(missing)',
					'reference' => isset( $payload->data->shipment_reference )
						? $payload->data->shipment_reference
						: '(missing)',
				)
			);
		}

		$this->return_json( array( 'success' => $result ), $result ? 200 : 400 );
	}
}
