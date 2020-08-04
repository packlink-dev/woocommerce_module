<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\Country\CountryService;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Packlink_Regions_Controller
 *
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Regions_Controller extends Packlink_Base_Controller {

	/**
	 * Retrieves available registration regions.
	 */
	public function get_regions() {
		/** @var CountryService $country_service */ // phpcs:ignore
		$country_service     = ServiceRegister::getService( CountryService::CLASS_NAME );
		$supported_countries = $country_service->getSupportedCountries( false );

		$this->return_dto_entities_response( $supported_countries );
	}
}
