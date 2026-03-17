<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\IntegrationRegistration;


use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\Configuration;
use Packlink\BusinessLogic\IntegrationRegistration\Interfaces\IntegrationRegistrationDataProviderInterface;

/**
 * Class Config_Service
 *
 * @package Packlink\WooCommerce\Components\Services
 */
class Integration_Registration_Data_Provider implements IntegrationRegistrationDataProviderInterface {

	const INTEGRATION_TYPE = 'woocommerce_module';
	/**
	 * @var string|null integration identifier
	 */
	private $integrationId = null;
	/**
	 * @var Configuration $configService
	 */
	private $configService = null;

	/**
	 * @return array Payload.
	 */
	public function getRegistrationPayload() {
		return array(
			'integration_type' => $this->getIntegrationType(),
			'integration' => array(
				'guid' => $this->getIntegrationGuid(),
				'name' => $this->getIntegrationName(),
			),
			'webhooks' => array(
				'http_header_name' => 'X-Packlink-Webhook-Secret',
				'http_header_value' => $this->getWebhookSecret(),
				'status_update_url' => $this->getIntegrationWebhookStatusUpdateUrl(),
			),
		);
	}

	/**
	 * Returns the persisted integration GUID.
	 *
	 * @return string Integration GUID.
	 */
	public function getIntegrationGuid() {
		$config = $this->getConfigService();

		$guid = $config->getIntegrationGuid();
		if (!$guid) {
			$guid = \Logeecom\Infrastructure\Utility\GuidProvider::getInstance()->generateGuid();
			$config->setIntegrationGuid($guid);
		}

		return $guid;
	}

	/**
	 * Returns the persisted webhook secret.
	 *
	 * @return string Webhook secret used for authentication.
	 */
	public function getWebhookSecret() {
		$config = $this->getConfigService();

		$secret = $config->getWebhookSecret();
		if (!$secret) {
			$bytes32 = openssl_random_pseudo_bytes(32);
			$secret = rtrim(strtr(base64_encode($bytes32), '+/', '-_'), '=');
			$config->setWebhookSecret($secret);
		}

		return $secret;
	}

	/**
	 * Returns the persisted integration ID if present as class variable,
	 * otherwise, if returns the ID from database if present.
	 *
	 * @return string|null Integration ID.
	 */
	public function getIntegrationId() {
		if ($this->integrationId) {
			return $this->integrationId;
		}

		$result = $this->getConfigService()->getIntegrationId();

		if ($result) {
			$this->integrationId = $result;
			return $this->integrationId;
		}

		return null;
	}

	/**
	 * Saves Integration Identifier to database
	 *
	 * @param string $integrationId
	 *
	 * @return void
	 */
	public function setIntegrationId( $integrationId ) {
		$this->integrationId = $integrationId;
		$this->getConfigService()->setIntegrationId($integrationId);
	}

	/**
	 * Returns the integration type (e.g. Prestashop, WooCommerce...).
	 *
	 * @return string Integration type.
	 */
	public function getIntegrationType() {
		return self::INTEGRATION_TYPE;
	}

	/**
	 * Returns the name of the integration.
	 *
	 * @return string Integration name.
	 */
	public function getIntegrationName() {
		return get_option('blogname', '');
	}

	/**
	 * Returns the WebhookStatusUpdateUrl.
	 *
	 * @return string webhook URL.
	 */
	public function getIntegrationWebhookStatusUpdateUrl() {
		//return $this->getConfigService()->getStatusUpdateUrl(); //TODO: not valid-> fix when u start working on webhooks
		return 'https://packlink.io/integration-status-update';
	}

	/**
	 * Removes integration registration data from the database.
	 *
	 * @return void
	 */
	public function deleteIntegrationData() {
		$this->integrationId = null;
		$this->getConfigService()->deleteIntegrationData();
	}

	/**
	 * @return object|Configuration
	 */
	private function getConfigService() {
		if (null === $this->configService) {
			$this->configService = ServiceRegister::getService(Configuration::CLASS_NAME);
		}

		return $this->configService;
	}
}
