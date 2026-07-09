<?php
/**
 * Tests for the customer tax ID / company VAT capture fields (WC-T5).
 *
 * @package Packlink_Pro_Shipping
 */

use Packlink\WooCommerce\Components\Customs\Customs_Handler;
use Packlink\WooCommerce\Components\Services\Customs_Mapping_Service;

/**
 * Class CustomsCustomerFieldsTest
 *
 * @package Packlink_Pro_Shipping
 */
class CustomsCustomerFieldsTest extends WP_UnitTestCase {

	/**
	 * The storefront billing fields are registered under the keys that map to the dedicated meta.
	 */
	public function test_storefront_billing_fields_use_expected_keys() {
		$fields = ( new Customs_Handler() )->add_billing_fields( array() );

		$this->assertArrayHasKey( ltrim( Customs_Mapping_Service::BILLING_TAX_ID_META, '_' ), $fields );
		$this->assertArrayHasKey( ltrim( Customs_Mapping_Service::BILLING_VAT_META, '_' ), $fields );
	}

	/**
	 * The admin order billing panel exposes the tax id + company VAT fields.
	 */
	public function test_admin_billing_fields_present() {
		$fields = ( new Customs_Handler() )->add_admin_billing_fields( array() );

		$this->assertArrayHasKey( 'packlink_tax_id', $fields );
		$this->assertArrayHasKey( 'packlink_vat', $fields );
	}

	/**
	 * The customer tax id / company VAT meta round-trips through order save/read.
	 */
	public function test_order_customs_meta_round_trips() {
		$order = wc_create_order();
		$order->update_meta_data( Customs_Mapping_Service::BILLING_TAX_ID_META, 'TAX-9' );
		$order->update_meta_data( Customs_Mapping_Service::BILLING_VAT_META, 'VAT-9' );
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( 'TAX-9', $reloaded->get_meta( Customs_Mapping_Service::BILLING_TAX_ID_META ) );
		$this->assertSame( 'VAT-9', $reloaded->get_meta( Customs_Mapping_Service::BILLING_VAT_META ) );
	}
}
