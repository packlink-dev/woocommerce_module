<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Services;

use Packlink\BusinessLogic\SimulatedPrices\Interfaces\ShopOriginProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Shop_Origin_Provider
 *
 * Shop address of a merchant without a Packlink account, for core's simulated prices (CR-SET-72-02):
 * the store address from WooCommerce > Settings > General.
 *
 * @package Packlink\WooCommerce\Components\Services
 */
class Shop_Origin_Provider implements ShopOriginProvider {
	/**
	 * Returns the store address, or null when the store has no country or postcode set, so core falls back
	 * to the capital city of the market.
	 *
	 * @return array|null Keys: country, postalCode.
	 */
	public function getOrigin() {
		if ( ! function_exists( 'WC' ) || null === WC()->countries ) {
			return null;
		}

		$country     = strtoupper( trim( (string) WC()->countries->get_base_country() ) );
		$postal_code = trim( (string) WC()->countries->get_base_postcode() );

		if ( '' === $country || '' === $postal_code ) {
			return null;
		}

		return array(
			'country'    => $country,
			'postalCode' => $postal_code,
		);
	}
}
