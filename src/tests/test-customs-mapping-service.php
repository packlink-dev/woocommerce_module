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
	 * Drop the cached custom-attribute-name scan so every test starts from a fresh catalog state.
	 */
	public function setUp() {
		parent::setUp();
		delete_transient( Customs_Mapping_Service::ATTRIBUTE_NAMES_TRANSIENT );
	}

	/**
	 * getMappingFieldsOptions() returns the three expected mapping selects, in order, each with
	 * value/name options. The former company-VAT select is gone: one field serves both customs
	 * attributes and the core routes the value based on the configured receiver user type.
	 */
	public function test_returns_the_three_mapping_field_definitions_in_order() {
		$service = new Customs_Mapping_Service();

		$fields = $service->getMappingFieldsOptions();

		$this->assertCount( 3, $fields, 'Expected exactly three mapping-field definitions.' );

		$field_keys = array();
		foreach ( $fields as $field ) {
			$this->assertArrayHasKey( 'field', $field );
			$this->assertArrayHasKey( 'label', $field );
			$this->assertArrayHasKey( 'options', $field );
			$this->assertNotEmpty( $field['options'], 'Each mapping field must expose at least one option.' );

			foreach ( $field['options'] as $option ) {
				$this->assertArrayHasKey( 'value', $option );
				$this->assertArrayHasKey( 'name', $option );
			}

			$field_keys[] = $field['field'];
		}

		$this->assertSame(
			array(
				'mapping_receiver_tax_id',
				'mapping_tariff_number',
				'mapping_country_of_origin',
			),
			$field_keys,
			'Mapping-field definitions are not in the expected order.'
		);
		$this->assertNotContains( 'mapping_company_vat', $field_keys, 'The company-VAT mapping select must be gone.' );
		$this->assertSame( 'Customer tax ID / VAT number field', $fields[0]['label'] );
	}

	/**
	 * Every select lists its dedicated Packlink field first, with the namespaced option value.
	 */
	public function test_dedicated_options_come_first_with_namespaced_values() {
		$first_options = array();
		foreach ( $this->get_mapping_fields() as $field ) {
			$first_options[ $field['field'] ] = $field['options'][0];
		}

		$this->assertSame(
			array(
				'value' => 'user:packlink_tax_id',
				'name'  => 'Packlink customer tax ID / VAT number',
			),
			$first_options['mapping_receiver_tax_id']
		);
		$this->assertSame(
			array(
				'value' => 'meta:_packlink_hs_code',
				'name'  => 'Packlink product HS code',
			),
			$first_options['mapping_tariff_number']
		);
		$this->assertSame(
			array(
				'value' => 'meta:_packlink_country_of_origin',
				'name'  => 'Packlink product country of origin',
			),
			$first_options['mapping_country_of_origin']
		);
	}

	/**
	 * The customer tax ID / VAT select offers the curated order meta keys as `order:` values, and
	 * the old `_billing_company` option (a company name, not a VAT number) is gone.
	 */
	public function test_customer_select_offers_curated_order_meta_keys() {
		$values_by_field = $this->get_option_values_by_field();

		$curated = array( '_vat_number', '_billing_vat_number', '_billing_eu_vat_number', '_billing_nif' );

		foreach ( $curated as $key ) {
			$this->assertContains(
				Customs_Mapping_Service::PREFIX_ORDER_META . $key,
				$values_by_field['mapping_receiver_tax_id'],
				"Expected curated order meta '$key' on the mapping_receiver_tax_id select."
			);
		}

		foreach ( $values_by_field['mapping_receiver_tax_id'] as $value ) {
			$this->assertFalse(
				strpos( $value, '_billing_company' ),
				'The _billing_company option must not be offered on the mapping_receiver_tax_id select.'
			);
		}
	}

	/**
	 * Global attribute taxonomies are offered as `attr:pa_*` options on both product selects.
	 */
	public function test_global_attribute_taxonomies_listed_on_both_product_selects() {
		$attribute_id = wc_create_attribute(
			array(
				'name' => 'HS Region',
				'slug' => 'hs_region',
			)
		);
		$this->assertNotWPError( $attribute_id, 'Failed to create the global product attribute.' );

		$values_by_field = $this->get_option_values_by_field();

		foreach ( array( 'mapping_tariff_number', 'mapping_country_of_origin' ) as $field ) {
			$this->assertContains(
				Customs_Mapping_Service::PREFIX_ATTRIBUTE . 'pa_hs_region',
				$values_by_field[ $field ],
				"Expected the global attribute taxonomy on the $field select."
			);
		}

		wc_delete_attribute( $attribute_id );
	}

	/**
	 * Per-product custom (non-taxonomy) attribute names scanned from `_product_attributes` are
	 * offered as `attr:{name}` options on both product selects; taxonomy-backed entries of that
	 * meta are ignored by the scan.
	 */
	public function test_per_product_custom_attributes_listed_on_both_product_selects() {
		$product_id = $this->factory->post->create( array( 'post_type' => 'product' ) );
		update_post_meta(
			$product_id,
			'_product_attributes',
			array(
				'hs-code'  => array(
					'name'        => 'hs-code',
					'value'       => '620342',
					'is_taxonomy' => 0,
				),
				'pa_color' => array(
					'name'        => 'pa_color',
					'value'       => '',
					'is_taxonomy' => 1,
				),
			)
		);

		delete_transient( Customs_Mapping_Service::ATTRIBUTE_NAMES_TRANSIENT );

		$values_by_field = $this->get_option_values_by_field();

		foreach ( array( 'mapping_tariff_number', 'mapping_country_of_origin' ) as $field ) {
			$this->assertContains(
				Customs_Mapping_Service::PREFIX_ATTRIBUTE . 'hs-code',
				$values_by_field[ $field ],
				"Expected the per-product custom attribute on the $field select."
			);
			$this->assertNotContains(
				Customs_Mapping_Service::PREFIX_ATTRIBUTE . 'pa_color',
				$values_by_field[ $field ],
				'Taxonomy-backed entries of _product_attributes must not be collected by the scan.'
			);
		}
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

	/**
	 * Fetches the mapping field definitions from a fresh service instance.
	 *
	 * @return array
	 */
	private function get_mapping_fields() {
		$service = new Customs_Mapping_Service();

		return $service->getMappingFieldsOptions();
	}

	/**
	 * Returns a map of mapping-field key to the list of its option values.
	 *
	 * @return array
	 */
	private function get_option_values_by_field() {
		$values_by_field = array();
		foreach ( $this->get_mapping_fields() as $field ) {
			$values = array();
			foreach ( $field['options'] as $option ) {
				$values[] = $option['value'];
			}
			$values_by_field[ $field['field'] ] = $values;
		}

		return $values_by_field;
	}
}
