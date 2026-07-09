<?php
/**
 * Tests for the product HS code / country-of-origin capture fields (WC-T4).
 *
 * @package Packlink_Pro_Shipping
 */

use Packlink\WooCommerce\Components\Customs\Customs_Handler;
use Packlink\WooCommerce\Components\Services\Customs_Mapping_Service;

/**
 * Class CustomsProductFieldsTest
 *
 * @package Packlink_Pro_Shipping
 */
class CustomsProductFieldsTest extends WP_UnitTestCase {

	/**
	 * Clean up the request superglobal used by save_product_fields().
	 */
	public function tearDown() {
		unset(
			$_POST[ Customs_Mapping_Service::PRODUCT_HS_CODE_META ],
			$_POST[ Customs_Mapping_Service::PRODUCT_COUNTRY_OF_ORIGIN_META ]
		);
		parent::tearDown();
	}

	/**
	 * Creates a simple product and returns its id.
	 *
	 * @return int
	 */
	private function create_product() {
		$product = new WC_Product_Simple();
		$product->set_name( 'HS Field Product' );
		$product->save();

		return $product->get_id();
	}

	/**
	 * A valid HS code + country are persisted to the dedicated product meta.
	 */
	public function test_persists_hs_code_and_country() {
		$product_id = $this->create_product();

		$_POST[ Customs_Mapping_Service::PRODUCT_HS_CODE_META ]           = '61091000';
		$_POST[ Customs_Mapping_Service::PRODUCT_COUNTRY_OF_ORIGIN_META ] = 'DE';

		( new Customs_Handler() )->save_product_fields( $product_id );

		$saved = wc_get_product( $product_id );
		$this->assertSame( '61091000', $saved->get_meta( Customs_Mapping_Service::PRODUCT_HS_CODE_META ) );
		$this->assertSame( 'DE', $saved->get_meta( Customs_Mapping_Service::PRODUCT_COUNTRY_OF_ORIGIN_META ) );
	}

	/**
	 * Non-digit characters in the HS code are stripped on save.
	 */
	public function test_sanitizes_hs_code_to_digits_only() {
		$product_id = $this->create_product();

		$_POST[ Customs_Mapping_Service::PRODUCT_HS_CODE_META ] = 'ab12.34-cd56';

		( new Customs_Handler() )->save_product_fields( $product_id );

		$saved = wc_get_product( $product_id );
		$this->assertSame( '123456', $saved->get_meta( Customs_Mapping_Service::PRODUCT_HS_CODE_META ) );
	}

	/**
	 * An unsupported country code is rejected and stored as empty.
	 */
	public function test_rejects_unsupported_country() {
		$product_id = $this->create_product();

		$_POST[ Customs_Mapping_Service::PRODUCT_COUNTRY_OF_ORIGIN_META ] = 'ZZ';

		( new Customs_Handler() )->save_product_fields( $product_id );

		$saved = wc_get_product( $product_id );
		$this->assertSame( '', $saved->get_meta( Customs_Mapping_Service::PRODUCT_COUNTRY_OF_ORIGIN_META ) );
	}
}
