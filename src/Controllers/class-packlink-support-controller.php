<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

use Logeecom\Infrastructure\Configuration\Configuration;
use Logeecom\Infrastructure\ServiceRegister;
use Packlink\WooCommerce\Components\Services\Config_Service;

/**
 * Class Packlink_Support_Controller
 *
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Support_Controller extends Packlink_Base_Controller {
	/**
	 * Configuration service
	 *
	 * @var Configuration $config_service Configuration service.
	 */
	private $config_service;

	/**
	 * Retrieves configs.
	 */
	public function get() {
		$this->return_json(
			array(
				'ASYNC_PROCESS_TIMEOUT' => $this->get_config_service()->getAsyncRequestTimeout(),
				'FOOTER_HEIGHT'         => $this->get_config_service()->get_footer_height(),
			)
		);
	}

	/**
	 * Sets configs.
	 */
	public function set() {
		$body = json_decode( $this->get_raw_input(), true );

		if ( isset( $body['asyncProcessTimeout'] ) ) {
			$this->set_timeout( $body['asyncProcessTimeout'] );
		}

		if ( isset( $body['footerHeight'] ) ) {
			$this->set_footer_height( $body['footerHeight'] );
		}

		$this->return_json( array( 'success' => true ) );
	}

	/**
	 * Sets footer height.
	 *
	 * @param int $height Height of footer to be set.
	 *
	 * @return void
	 */
	private function set_footer_height( $height ) {
		if ( ! is_int( $height ) ) {
			return;
		}

		$this->get_config_service()->set_footer_height( $height );
	}

	/**
	 * Set async request timeout.
	 *
	 * @param int $timeout Timeout value to be set.
	 *
	 * @return void
	 */
	private function set_timeout( $timeout ) {
		if ( ! is_int( $timeout ) ) {
			return;
		}

		$this->get_config_service()->setAsyncRequestTimeout( $timeout );
	}

	/**
	 * Gets Configuration service instance.
	 *
	 * @return Config_Service
	 */
	private function get_config_service() {
		if ( null === $this->config_service ) {
			$this->config_service = ServiceRegister::getService( Configuration::CLASS_NAME );
		}

		/** No inspection needed @noinspection PhpIncompatibleReturnTypeInspection */
		return $this->config_service;
	}
}
