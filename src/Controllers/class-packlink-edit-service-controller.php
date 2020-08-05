<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

use Packlink\BusinessLogic\Controllers\DTO\ShippingMethodConfiguration;
use Packlink\BusinessLogic\Controllers\ShippingMethodController;
use Packlink\BusinessLogic\DTO\Exceptions\FrontDtoValidationException;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Packlink_Edit_Service_Controller
 *
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Edit_Service_Controller extends Packlink_Base_Controller {


	/**
	 * Shipping method controller.
	 *
	 * @var ShippingMethodController
	 */
	private $controller;

	/**
	 * Packlink_Edit_Service_Controller constructor.
	 */
	public function __construct() {
		$this->controller = new ShippingMethodController();
	}

	/**
	 * Retrieves shipping service.
	 */
	public function get_service() {
		$id = get_query_var( 'id' );
		if ( empty( $id ) ) {
			$this->return_error( 'Not found!', 404 );

			return;
		}

		$method = $this->controller->getShippingMethod( $id );
		if ( null === $method ) {
			$this->return_error( 'Not found!', 404 );

			return;
		}

		$this->return_json( $method->toArray() );
	}

	/**
	 * Updates shipping service.
	 */
	public function update_service() {
		$this->validate( 'yes', true );
		$raw     = $this->get_raw_input();
		$payload = json_decode( $raw, true );
		try {
			$configuration = ShippingMethodConfiguration::fromArray( $payload );
		} catch ( FrontDtoValidationException $e ) {
			$this->return_dto_entities_response( $e->getValidationErrors(), 400 );

			return;
		}

		$response = $this->controller->save( $configuration );
		$response = $response ? $response->toArray() : array();

		$this->return_json( $response );
	}
}
