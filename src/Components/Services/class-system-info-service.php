<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Services;

use Packlink\BusinessLogic\Http\DTO\SystemInfo;
use Packlink\BusinessLogic\SystemInformation\SystemInfoService;

/**
 * Class System_Info_Service
 *
 * @package Packlink\WooCommerce\Components\Services
 */
class System_Info_Service implements SystemInfoService {
	/**
	 * System ID.
	 */
	const SYSTEM_ID = 'woocommerce';

	/**
	 * Returns system information.
	 *
	 * @return SystemInfo[]
	 */
	public function getSystemDetails() {
		$currency = get_woocommerce_currency();

		return array(SystemInfo::fromArray(array(
			'system_id' => self::SYSTEM_ID,
			'system_name' => get_bloginfo( 'name' ),
			'currencies' => array($currency),
		)));
	}

	/**
	 * Returns system information for a particular system, identified by the system ID.
	 *
	 * @param string $systemId
	 *
	 * @return SystemInfo|null
	 */
	public function getSystemInfo( $systemId ) {
		return $this->getSystemDetails()[0];
	}
}
