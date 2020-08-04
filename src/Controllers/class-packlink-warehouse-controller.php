<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

use Exception;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\Exceptions\QueueStorageUnavailableException;
use Packlink\BusinessLogic\Country\CountryService;
use Packlink\BusinessLogic\DTO\Exceptions\FrontDtoNotRegisteredException;
use Packlink\BusinessLogic\DTO\Exceptions\FrontDtoValidationException;
use Packlink\BusinessLogic\Location\LocationService;
use Packlink\BusinessLogic\Warehouse\WarehouseService;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Packlink_Warehouse_Controller
 *
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Warehouse_Controller extends Packlink_Base_Controller {

	/**
	 * Warehouse service.
	 *
	 * @var WarehouseService
	 */
	private $service;

	/**
	 * Country service.
	 *
	 * @var CountryService
	 */
	private $countries_service;

	/**
	 * Locations service.
	 *
	 * @var LocationService
	 */
	private $locations_service;

	/**
	 * Packlink_Warehouse_Controller constructor.
	 */
	public function __construct() {
		$this->service           = ServiceRegister::getService( WarehouseService::CLASS_NAME );
		$this->countries_service = ServiceRegister::getService( CountryService::CLASS_NAME );
		$this->locations_service = ServiceRegister::getService( LocationService::CLASS_NAME );
	}

	/**
	 * Retrieves senders warehouse.
	 */
	public function get() {
		$warehouse = $this->service->getWarehouse();

		$this->return_json( $warehouse ? $warehouse->toArray() : array() );
	}

	/**
	 * Updates warehouse data.
	 *
	 * @throws QueueStorageUnavailableException When queue storage is unavailable.
	 * @throws FrontDtoNotRegisteredException When front dto is not registered.
	 * @throws FrontDtoValidationException When warehouse data is not valid.
	 */
	public function submit() {
		$this->validate( 'yes', true );
		$raw     = $this->get_raw_input();
		$payload = json_decode( $raw, true );
		$this->service->updateWarehouseData( $payload );

		$this->get();
	}

	/**
	 * Retrieves supported countries.
	 */
	public function get_countries() {
		$countries = $this->countries_service->getSupportedCountries( false );

		$this->return_dto_entities_response( $countries );
	}

	/**
	 * Searches postal coded with given country and query.
	 */
	public function search_postal_codes() {
		$this->validate( 'yes', true );
		$raw     = $this->get_raw_input();
		$payload = json_decode( $raw, true );

		if ( empty( $payload['query'] ) || empty( $payload['country'] ) ) {
			$this->return_json( array() );
		}

		$result = array();

		try {
			$result = $this->locations_service->searchLocations( $payload['country'], $payload['query'] );
		} catch ( Exception $e ) {
			$this->return_json( $result );
		}

		$this->return_dto_entities_response( $result );
	}
}
