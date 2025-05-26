<?php

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
	 * @var Configuration
	 */
	private $config_service;

	/**
	 * Retrieves configs.
	 */
	public function get() {
		$this->return_json( [
			'TASK_RUNNER_WAKEUP_DELAY' => $this->get_config_service()->getTaskRunnerWakeupDelay(),
			'FOOTER_HEIGHT'         => $this->get_config_service()->get_footer_height(),
		] );
	}

	/**
	 * Sets configs.
	 */
	public function set() {
		$body = json_decode( $this->get_raw_input(), true );

		if ( isset( $body['taskRunnerWakeupDelay'] ) ) {
			$this->set_timeout( $body['taskRunnerWakeupDelay'] );
		}

		if ( isset( $body['footerHeight'] ) ) {
			$this->set_footer_height( $body['footerHeight'] );
		}

		$this->return_json( [ 'success' => true ] );
	}

	private function set_footer_height( $height ) {
		if ( ! is_int( $height ) ) {
			return;
		}

		$this->get_config_service()->set_footer_height( $height );
	}

	private function set_timeout( $timeout ) {
		if ( ! is_int( $timeout ) ) {
			return;
		}

		$this->get_config_service()->setTaskRunnerWakeupDelay( $timeout );
	}

	/**
	 * @return Config_Service
	 */
	private function get_config_service() {
		if ( $this->config_service === null ) {
			$this->config_service = ServiceRegister::getService( Configuration::CLASS_NAME );
		}

		/** @noinspection PhpIncompatibleReturnTypeInspection */
		return $this->config_service;
	}
}