<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Services;

use Packlink\BusinessLogic\Configuration;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;

/**
 * Class Config_Service
 * @package Packlink\WooCommerce\Components\Services
 */
class Config_Service extends Configuration {
	/**
	 * Threshold between two runs of scheduler.
	 */
	const SCHEDULER_TIME_THRESHOLD = 86400;

	/**
	 * Singleton instance of this class.
	 *
	 * @var static
	 */
	protected static $instance;

	/**
	 * Retrieves integration name.
	 *
	 * @return string Integration name.
	 */
	public function getIntegrationName() {
		return 'WooCommerce';
	}

	/**
	 * Returns order draft source.
	 *
	 * @return string
	 */
	public function getDraftSource() {
		return 'module_woocommerce';
	}

	/**
	 * Returns current system identifier.
	 *
	 * @return string Current system identifier.
	 */
	public function getCurrentSystemId() {
		return (string) get_current_blog_id();
	}

	/**
	 * Returns async process starter url, always in http.
	 *
	 * @param string $guid Process identifier.
	 *
	 * @return string Formatted URL of async process starter endpoint.
	 */
	public function getAsyncProcessUrl( $guid ) {
		return Shop_Helper::get_controller_url( 'Async_Process', 'run', array(
			'guid' => $guid
		) );
	}

	/**
	 * Returns web-hook callback URL for current system.
	 *
	 * @return string Web-hook callback URL.
	 */
	public function getWebHookUrl() {
		return Shop_Helper::get_controller_url( 'Web_Hook', 'index' );
	}

	/**
	 * Sets database version for migration scripts
	 *
	 * @param string $database_version Database version.
	 */
	public function set_database_version( $database_version ) {
		$this->saveConfigValue( 'PACKLINK_DATABASE_VERSION', $database_version );
	}

	/**
	 * Returns database version
	 *
	 * @return string
	 */
	public function get_database_version() {
		return $this->getConfigValue( 'PACKLINK_DATABASE_VERSION' );
	}
}
