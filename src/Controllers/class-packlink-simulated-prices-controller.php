<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

use Packlink\BusinessLogic\Controllers\SimulatedPricesController;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Packlink_Simulated_Prices_Controller
 *
 * "Discover our rates" panel (CR-SET-72-02). Everything is core's - who may ask, which payload keys are
 * read, the shop origin (Shop_Origin_Provider) and the HTTP status of each error code; this controller
 * only exposes it to the admin SPA.
 *
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Simulated_Prices_Controller extends Packlink_Base_Controller {
	/**
	 * Core simulated prices controller.
	 *
	 * @var SimulatedPricesController
	 */
	private $controller;

	/**
	 * Packlink_Simulated_Prices_Controller constructor.
	 */
	public function __construct() {
		$this->controller = new SimulatedPricesController();
	}

	/**
	 * Returns what the panel needs before the first quote.
	 */
	public function get_data() {
		$this->return_json( $this->controller->getData() );
	}

	/**
	 * Quotes the sample parcel. Body: market, toCountry, toPostalCode.
	 */
	public function search() {
		$this->validate( 'yes', true );
		$payload = json_decode( $this->get_raw_input(), true );
		$result  = $this->controller->search( is_array( $payload ) ? $payload : array() );

		$this->return_json( $result, SimulatedPricesController::getHttpStatus( $result['error'] ) );
	}
}
