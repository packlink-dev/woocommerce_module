<?php
/**
 * Tests for the WooCommerce customs mapping service (WC-T3).
 *
 * @package Packlink_Pro_Shipping
 */

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\Controllers\CustomsController as CoreCustomsController;
use Packlink\BusinessLogic\Customs\CustomsMappingService;
use Packlink\WooCommerce\Components\Services\Customs_Mapping_Service;

/**
 * Class CustomsMappingServiceTest
 *
 * @package Packlink_Pro_Shipping
 */
class CustomsMappingServiceTest extends WP_UnitTestCase {

	/**
	 * getMappingFieldsOptions() returns the three expected mapping selects, each with value/name options.
	 */
	public function test_returns_the_three_mapping_field_definitions() {
		$service = new Customs_Mapping_Service();

		$fields = $service->getMappingFieldsOptions();

		$this->assertCount( 3, $fields, 'Expected exactly three mapping-field definitions.' );

		$by_field = array();
		foreach ( $fields as $field ) {
			$this->assertArrayHasKey( 'field', $field );
			$this->assertArrayHasKey( 'label', $field );
			$this->assertArrayHasKey( 'options', $field );
			$this->assertNotEmpty( $field['options'], 'Each mapping field must expose at least one option.' );

			foreach ( $field['options'] as $option ) {
				$this->assertArrayHasKey( 'value', $option );
				$this->assertArrayHasKey( 'name', $option );
			}

			$by_field[ $field['field'] ] = $field;
		}

		$this->assertArrayHasKey( 'mapping_receiver_tax_id', $by_field );
		$this->assertArrayHasKey( 'mapping_tariff_number', $by_field );
		$this->assertArrayHasKey( 'mapping_company_vat', $by_field );
	}

	/**
	 * The receiver-tax-id and HS-code selects point at the plugin's dedicated meta keys.
	 */
	public function test_default_option_values_use_dedicated_meta_keys() {
		$service = new Customs_Mapping_Service();

		$options_by_field = array();
		foreach ( $service->getMappingFieldsOptions() as $field ) {
			$values = array();
			foreach ( $field['options'] as $option ) {
				$values[] = $option['value'];
			}
			$options_by_field[ $field['field'] ] = $values;
		}

		$this->assertContains( Customs_Mapping_Service::BILLING_TAX_ID_META, $options_by_field['mapping_receiver_tax_id'] );
		$this->assertContains( Customs_Mapping_Service::PRODUCT_HS_CODE_META, $options_by_field['mapping_tariff_number'] );
		$this->assertContains( Customs_Mapping_Service::BILLING_VAT_META, $options_by_field['mapping_company_vat'] );
	}

	/**
	 * The service is registered in the ServiceRegister and the core CustomsController is constructable with it.
	 */
	public function test_service_registered_and_core_controller_constructable() {
		$service = ServiceRegister::getService( CustomsMappingService::CLASS_NAME );

		$this->assertInstanceOf( Customs_Mapping_Service::class, $service );

		$controller = new CoreCustomsController( $service );
		$this->assertInstanceOf( CoreCustomsController::class, $controller );
	}
}
