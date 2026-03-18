<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Packlink\BusinessLogic\WebHook\IntegrationRegistrationWebhookEventHandler;

/**
 * Class Packlink_Web_Hook_Controller
 *
 * Handles Packlink-initiated integration lifecycle webhooks
 * (ENABLED, DISABLED, DELETED statuses).
 *
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Integration_Registration_Webhook_Controller extends Packlink_Base_Controller {

	/**
	 * Packlink_Integration_Registration_Webhook_Controller constructor.
	 */
	public function __construct() {
		$this->is_internal = false;
	}

	/**
	 * Web-hook action handler
	 */
	public function index()
	{
		if ( ! $this->is_post() ) {
			$this->redirect404();
		}

		$result = IntegrationRegistrationWebhookEventHandler::getInstance()->handle( $this->get_raw_input() );

		$this->return_json( array( 'success' => $result ), $result ? 200 : 400 );
	}
}
