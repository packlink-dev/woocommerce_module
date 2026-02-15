<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\Interfaces\TaskExecutorInterface;
use Packlink\BusinessLogic\Controllers\AutoConfigurationController;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Packlink_Auto_Configure_Controller
 *
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Auto_Configure_Controller extends Packlink_Base_Controller {
	/**
	 * Starts the auto-configuration.
	 */
	protected function start() {
		$controller = new AutoConfigurationController(
			ServiceRegister::getService( TaskExecutorInterface::CLASS_NAME ),
            ServiceRegister::getService( UpdateShippingServiceTaskStatusServiceInterface::CLASS_NAME)
		);

		$this->return_json( array( 'success' => $controller->start( true ) ) );
	}
}
