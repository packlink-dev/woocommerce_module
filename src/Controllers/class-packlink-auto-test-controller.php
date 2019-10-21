<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

use Logeecom\Infrastructure\AutoTest\AutoTestLogger;
use Logeecom\Infrastructure\AutoTest\AutoTestService;
use Logeecom\Infrastructure\Exceptions\StorageNotAccessibleException;
use Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException;
use Logeecom\Infrastructure\ORM\Exceptions\RepositoryClassException;
use Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException;
use Logeecom\Infrastructure\TaskExecution\Exceptions\QueueStorageUnavailableException;
use Packlink\WooCommerce\Components\Services\Logger_Service;
use Packlink\WooCommerce\Components\Utility\Script_Loader;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Packlink_Auto_Test_Controller
 *
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Auto_Test_Controller extends Packlink_Base_Controller {
	/**
	 * Renders appropriate view.
	 */
	public function render() {
		Script_Loader::load_css(
			array(
				'css/packlink.css',
				'css/packlink-auto-test.css',
				'css/packlink-wp-override.css',
			)
		);
		Script_Loader::load_js(
			array(
				'js/core/packlink-ajax-service.js',
				'js/core/packlink-template-service.js',
				'js/core/packlink-utility-service.js',
				'js/core/packlink-auto-test-controller.js',
			)
		);

		include dirname( __DIR__ ) . '/resources/views/auto-test.php';
	}

	/**
	 * Runs the auto-test and returns the queue item ID.
	 *
	 * @throws QueueStorageUnavailableException When database is not accessible.
	 */
	protected function start() {
		$service = new AutoTestService();
		try {
			$this->return_json(
				array(
					'success' => true,
					'itemId'  => $service->startAutoTest(),
				)
			);
		} catch ( StorageNotAccessibleException $e ) {
			$this->return_json(
				array(
					'success' => false,
					'error'   => __( 'Database not accessible.' ),
				)
			);
		}
	}

	/**
	 * Checks the status of the auto-test task.
	 *
	 * @throws QueryFilterInvalidParamException When queue filter is wrong.
	 * @throws RepositoryClassException When repository class is not available.
	 * @throws RepositoryNotRegisteredException When repository is not registered in bootstrap.
	 */
	protected function checkStatus() {
		$service = new AutoTestService();
		$status  = $service->getAutoTestTaskStatus( $this->get_param( 'queueItemId' ) );

		if ( $status->finished ) {
			$service->stopAutoTestMode(
				static function () {
					return Logger_Service::getInstance();
				}
			);
		}

		$this->return_json(
			array(
				'finished' => $status->finished,
				'error'    => __( $status->error, 'packlink-pro-shipping' ), // phpcs:ignore
				'logs'     => AutoTestLogger::getInstance()->getLogsArray(),
			)
		);
	}

	/**
	 * Exports all logs as a JSON file.
	 *
	 * @throws RepositoryNotRegisteredException When repository is not registered in bootstrap.
	 */
	protected function exportLogs() {
		if ( ! defined( 'JSON_PRETTY_PRINT' ) ) {
			define( 'JSON_PRETTY_PRINT', 128 );
		}

		$data = wp_json_encode( AutoTestLogger::getInstance()->getLogsArray(), JSON_PRETTY_PRINT );
		self::dieFileFromString( $data, 'auto-test-logs.json' );
	}

	/**
	 * Sets string specified by $content as a file response.
	 *
	 * @param string $content Content to output as file.
	 * @param string $file_name The name of the file.
	 */
	private static function dieFileFromString( $content, $file_name ) {
		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename=' . $file_name );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate' );
		header( 'Pragma: public' );
		header( 'Content-Length: ' . strlen( $content ) );

		echo $content; //phpcs:ignore

		die( 200 );
	}

	/**
	 * Resolves dashboard view arguments.
	 *
	 * @return array Dashboard view arguments.
	 */
	protected function resolve_view_arguments() {
		return array(
			'dashboard_logo'   => Shop_Helper::get_plugin_base_url() . 'resources/images/logo-pl.svg',
			'download_log_url' => Shop_Helper::get_controller_url( 'Auto_Test', 'exportLogs' ),
			'debug_url'        => Shop_Helper::get_controller_url( 'Debug', 'download' ),
			'module_url'       => menu_page_url( 'packlink-pro-shipping', false ),
			'start_test_url'   => Shop_Helper::get_controller_url( 'Auto_Test', 'start' ),
			'check_status_url' => Shop_Helper::get_controller_url( 'Auto_Test', 'checkStatus' ),
		);
	}
}
