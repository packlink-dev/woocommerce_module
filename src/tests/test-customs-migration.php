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
	 * Seeding creates a default mapping when none exists, pointing at the dedicated fields via
	 * namespaced mapping values, with a valid default reason and an empty default tariff number.
	 */
	public function test_seeds_defaults_when_none_exists() {
		$this->assertEmpty( $this->config()->getCustomsMappings(), 'Expected no mapping on a fresh install.' );

		Customs_Handler::seed_default_customs_mapping();

		$mapping = $this->config()->getCustomsMappings();
		$this->assertInstanceOf( CustomsMapping::class, $mapping );
		// The core normalises the customs enums to the upper-case tokens Packlink's schema defines, so
		// that is the spelling a stored mapping comes back with.
		$this->assertSame( 'PURCHASE_OR_SALE', $mapping->defaultReason );
		$this->assertSame( '', $mapping->defaultTariffNumber, 'Default tariff number must be seeded empty; the core skips invoices with a warning when nothing resolves.' );
		$this->assertSame( '', $mapping->defaultCountry );
		$this->assertSame(
			Customs_Mapping_Service::PREFIX_USER_META . Customs_Mapping_Service::USER_TAX_ID_META,
			$mapping->mappingReceiverTaxId
		);
		$this->assertSame(
			Customs_Mapping_Service::PREFIX_PRODUCT_META . Customs_Mapping_Service::PRODUCT_HS_CODE_META,
			$mapping->mappingTariffNumber
		);
		$this->assertSame(
			Customs_Mapping_Service::PREFIX_PRODUCT_META . Customs_Mapping_Service::PRODUCT_COUNTRY_OF_ORIGIN_META,
			$mapping->mappingCountryOfOrigin
		);
		$this->assertEmpty( $mapping->mappingCompanyVat, 'The company-VAT mapping must not be seeded: one field serves both customs attributes.' );
	}

	/**
	 * Re-running the seed must not overwrite a merchant's saved mapping (idempotent).
	 */
	public function test_does_not_overwrite_existing_mapping() {
		$existing                          = new CustomsMapping();
		// A reason other than the seeded default, so the assertion below can only pass if the merchant's
		// own value survived. It has to be one of Packlink's tokens: the core rejects anything else.
		$existing->defaultReason           = 'SAMPLE';
		$existing->defaultSenderTaxId      = '';
		$existing->defaultReceiverUserType = CustomsMapping::USER_TYPE_PRIVATE_PERSON;
		$existing->defaultReceiverTaxId    = '';
		$existing->defaultTariffNumber     = '99999999';
		$existing->defaultCountry          = 'DE';
		$existing->mappingReceiverTaxId    = Customs_Mapping_Service::PREFIX_USER_META . Customs_Mapping_Service::USER_TAX_ID_META;
		$existing->mappingTariffNumber     = Customs_Mapping_Service::PREFIX_PRODUCT_META . Customs_Mapping_Service::PRODUCT_HS_CODE_META;
		$existing->mappingCountryOfOrigin  = Customs_Mapping_Service::PREFIX_PRODUCT_META . Customs_Mapping_Service::PRODUCT_COUNTRY_OF_ORIGIN_META;
		$this->config()->setCustomsMappings( $existing );

		Customs_Handler::seed_default_customs_mapping();

		$mapping = $this->config()->getCustomsMappings();
		$this->assertInstanceOf( CustomsMapping::class, $mapping );
		$this->assertSame( '99999999', $mapping->defaultTariffNumber, 'Seed must not overwrite an existing mapping.' );
		$this->assertSame( 'DE', $mapping->defaultCountry );
		$this->assertSame( 'SAMPLE', $mapping->defaultReason );
	}

	/**
	 * The seed runs from the 4.3.0 upgrade script: Version_File_Reader only executes scripts with
	 * a version greater than the stored one, and released stores are on 4.2.3.
	 */
	public function test_upgrade_script_targets_4_3_0() {
		$upgrade_dir = dirname( __DIR__ ) . '/upgrade';

		$this->assertFileExists( $upgrade_dir . '/upgrade-4.3.0.php' );
		$this->assertFileNotExists( $upgrade_dir . '/upgrade-4.2.0.php', 'The 4.2.0 upgrade script must be renamed to 4.3.0 so the seed runs for stores upgrading from 4.2.3.' );
	}
}
