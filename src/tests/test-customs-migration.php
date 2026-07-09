<?php
/**
 * Tests for the customs default-mapping install/migration seeding (WC-T7).
 *
 * @package Packlink_Pro_Shipping
 */

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\Customs\Models\CustomsMapping;
use Packlink\WooCommerce\Components\Customs\Customs_Handler;
use Packlink\WooCommerce\Components\Services\Config_Service;
use Packlink\WooCommerce\Components\Services\Customs_Mapping_Service;
use Packlink\WooCommerce\Components\Utility\Database;

/**
 * Class CustomsMigrationTest
 *
 * @package Packlink_Pro_Shipping
 */
class CustomsMigrationTest extends WP_UnitTestCase {

	/**
	 * Install the plugin entity table (via the real installer) so Configuration reads/writes work.
	 */
	public function setUp() {
		parent::setUp();
		global $wpdb;
		$database = new Database( $wpdb );
		$database->install();
	}

	/**
	 * Drop the plugin entity table.
	 */
	public function tearDown() {
		global $wpdb;
		$database = new Database( $wpdb );
		$database->uninstall();
		parent::tearDown();
	}

	/**
	 * Config service.
	 *
	 * @return Config_Service
	 */
	private function config() {
		return ServiceRegister::getService( Config_Service::CLASS_NAME );
	}

	/**
	 * Seeding creates a default mapping when none exists, pointing at the dedicated fields.
	 */
	public function test_seeds_defaults_when_none_exists() {
		$this->assertEmpty( $this->config()->getCustomsMappings(), 'Expected no mapping on a fresh install.' );

		Customs_Handler::seed_default_customs_mapping();

		$mapping = $this->config()->getCustomsMappings();
		$this->assertInstanceOf( CustomsMapping::class, $mapping );
		$this->assertSame( Customs_Mapping_Service::BILLING_TAX_ID_META, $mapping->mappingReceiverTaxId );
		$this->assertSame( Customs_Mapping_Service::PRODUCT_HS_CODE_META, $mapping->mappingTariffNumber );
		$this->assertSame( Customs_Mapping_Service::BILLING_VAT_META, $mapping->mappingCompanyVat );
	}

	/**
	 * Re-running the seed must not overwrite a merchant's saved mapping (idempotent).
	 */
	public function test_does_not_overwrite_existing_mapping() {
		$existing                          = new CustomsMapping();
		$existing->defaultReason           = 'sale_of_goods';
		$existing->defaultSenderTaxId      = '';
		$existing->defaultReceiverUserType = 'private_person';
		$existing->defaultReceiverTaxId    = '';
		$existing->defaultTariffNumber     = '99999999';
		$existing->defaultCountry          = 'DE';
		$existing->mappingReceiverTaxId    = Customs_Mapping_Service::BILLING_TAX_ID_META;
		$existing->mappingTariffNumber     = Customs_Mapping_Service::PRODUCT_HS_CODE_META;
		$existing->mappingCompanyVat       = Customs_Mapping_Service::BILLING_VAT_META;
		$this->config()->setCustomsMappings( $existing );

		Customs_Handler::seed_default_customs_mapping();

		$mapping = $this->config()->getCustomsMappings();
		$this->assertInstanceOf( CustomsMapping::class, $mapping );
		$this->assertSame( '99999999', $mapping->defaultTariffNumber, 'Seed must not overwrite an existing mapping.' );
		$this->assertSame( 'DE', $mapping->defaultCountry );
	}
}
