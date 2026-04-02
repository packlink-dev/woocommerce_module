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
	private $configService;

	/**
	 * Integration_Registration_Data_Provider constructor.
	 */
	public function __construct($configService) {
		$this->configService = $configService;
	}

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

		$guid = $this->configService->getIntegrationGuid();
		if (!$guid) {
			$guid = \Logeecom\Infrastructure\Utility\GuidProvider::getInstance()->generateGuid();
			$this->configService->setIntegrationGuid($guid);
		}

		return $guid;
	}

	/**
	 * Returns the persisted webhook secret.
	 *
	 * @return string Webhook secret used for authentication.
	 */
	public function getWebhookSecret() {

		$secret = $this->configService->getWebhookSecret();
		if (!$secret) {
			$cryptoStrong = false;
			$bytes32 = openssl_random_pseudo_bytes(32, $cryptoStrong);

			if ($bytes32 === false || $cryptoStrong === false) {
				throw new \RuntimeException('Unable to generate a secure webhook secret.');
			}

			$secret = rtrim(strtr(base64_encode($bytes32), '+/', '-_'), '=');
			$this->configService->setWebhookSecret($secret);
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

		$result = $this->configService->getIntegrationId();

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
		$this->configService->setIntegrationId($integrationId);
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
		return $this->configService->getStatusUpdateUrl();
	}

	/**
	 * Removes integration registration data from the database.
	 *
	 * @return void
	 */
	public function deleteIntegrationData() {
		$this->integrationId = null;
		$this->configService->deleteIntegrationData();
	}

	/**
	 * Reset AuthorizationCredentials.
	 *
	 * @return void
	 */
	public function deleteToken()
	{
		$this->configService->resetAuthorizationCredentials();
	}
}
