<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\IntegrationRegistration;

use Logeecom\Infrastructure\Logger\Logger;
use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\IntegrationRegistration\Interfaces\IntegrationRegistrationDataProviderInterface;
use Packlink\BusinessLogic\IntegrationRegistration\Interfaces\ModuleResetServiceInterface;

/**
 * Class Config_Service
 *
 * @package Packlink\WooCommerce\Components\Services
 */
class Integration_Reset_Service implements ModuleResetServiceInterface {

	public function resetModule() {

		/** @var IntegrationRegistrationDataProviderInterface $dataProvider */
		$dataProvider = ServiceRegister::getService(IntegrationRegistrationDataProviderInterface::CLASS_NAME);

		try {
			$dataProvider->deleteIntegrationData();

			return true;
		} catch (\Exception $e) {
			Logger::logError(
				'Failed to reset module integration data: ' . $e->getMessage(),
				'Woocommerce_module',
				array('trace' => $e->getTraceAsString())
			);

			return false;
		}
	}
}
