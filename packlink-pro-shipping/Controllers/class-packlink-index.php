<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	/** Set up WordPress environment */
	require_once '../../../../wp-load.php';
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

if ( ! is_plugin_active( 'packlink-pro-shipping/packlink-pro-shipping.php' ) ) {
	require get_404_template();

	exit();
}

/**
 * Class Packlink_Index
 *
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Index extends Packlink_Base_Controller {
	/**
	 * Controller index action.
	 */
	public function index() {
		$controller_name = $this->get_param( 'controller' );

		if ( ! $this->validate_controller_name( $controller_name ) ) {
			status_header( 404 );
			nocache_headers();

			require get_404_template();

			exit();
		}

		$class_name = '\Packlink\WooCommerce\Controllers\Packlink_' . $controller_name . '_Controller';
		/**
		 * Controller instance.
		 *
		 * @var Packlink_Base_Controller $controller
		 */
		$controller = new $class_name();
		$controller->process();
	}

	/**
	 * Validates controller name by checking whether it exists in the list of known controller names.
	 *
	 * @param string $controller_name Controller name from request input.
	 *
	 * @return bool
	 */
	private function validate_controller_name( $controller_name ) {
		$allowed_controllers = array(
			'Async_Process',
			'Web_Hook',
			'Frontend',
			'Order_Overview',
			'Checkout',
			'Order_Details',
			'Debug',
		);

		return in_array( $controller_name, $allowed_controllers, true );
	}
}

$controller = new Packlink_Index();
$controller->index();
