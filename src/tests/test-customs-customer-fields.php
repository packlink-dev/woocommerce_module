<?php
/**
 * Tests for the customer tax ID / VAT number capture field (WC-T5).
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
	 * The admin customer profile gains a "Packlink customs" section with exactly one tax ID / VAT
	 * number field keyed by the dedicated user-meta key. One value serves both customs attributes:
	 * the core routes it to tax id or VAT number based on the configured receiver user type.
	 */
	public function test_customer_meta_fields_add_packlink_customs_section() {
		$fields = ( new Customs_Handler() )->add_customer_meta_fields( array() );

		$this->assertArrayHasKey( 'packlink_customs', $fields );
		$this->assertArrayHasKey( 'title', $fields['packlink_customs'] );
		$this->assertArrayHasKey( 'fields', $fields['packlink_customs'] );

		$section_fields = $fields['packlink_customs']['fields'];
		$this->assertCount( 1, $section_fields, 'The Packlink customs section must declare exactly one field.' );
		$this->assertArrayHasKey( Customs_Mapping_Service::USER_TAX_ID_META, $section_fields );

		$field = $section_fields[ Customs_Mapping_Service::USER_TAX_ID_META ];
		$this->assertArrayHasKey( 'label', $field );
		$this->assertArrayHasKey( 'description', $field );
	}

	/**
	 * The customer meta fields filter must not clobber sections declared by WooCommerce or other
	 * plugins.
	 */
	public function test_customer_meta_fields_preserve_existing_sections() {
		$existing = array( 'billing' => array( 'title' => 'Billing', 'fields' => array() ) );

		$fields = ( new Customs_Handler() )->add_customer_meta_fields( $existing );

		$this->assertArrayHasKey( 'billing', $fields );
		$this->assertArrayHasKey( 'packlink_customs', $fields );
	}

	/**
	 * The storefront checkout and admin order billing fields are removed: the handler no longer
	 * exposes the billing-field callbacks.
	 */
	public function test_billing_field_callbacks_removed() {
		$handler = new Customs_Handler();

		$this->assertFalse( method_exists( $handler, 'add_billing_fields' ) );
		$this->assertFalse( method_exists( $handler, 'add_admin_billing_fields' ) );
		$this->assertFalse( has_filter( 'woocommerce_billing_fields', array( $handler, 'add_billing_fields' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_admin_billing_fields', array( $handler, 'add_admin_billing_fields' ) ) );
	}

	/**
	 * The customer tax ID / VAT number meta round-trips through user meta save/read.
	 */
	public function test_customer_customs_meta_round_trips() {
		$customer_id = $this->factory->user->create( array( 'role' => 'customer' ) );

		update_user_meta( $customer_id, Customs_Mapping_Service::USER_TAX_ID_META, 'TAX-9' );

		$this->assertSame( 'TAX-9', get_user_meta( $customer_id, Customs_Mapping_Service::USER_TAX_ID_META, true ) );
	}
}
