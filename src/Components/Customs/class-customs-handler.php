<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Customs;

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\Country\CountryCodes;
use Packlink\BusinessLogic\Customs\CustomsService;
use Packlink\BusinessLogic\Customs\Models\CustomsMapping;
use Packlink\WooCommerce\Components\Services\Config_Service;
use Packlink\WooCommerce\Components\Services\Customs_Mapping_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Customs_Handler
 *
 * Adds the customs data-capture fields WooCommerce lacks natively: HS code and country of origin on
 * products, and customer tax ID and company VAT on billing/orders. The captured meta is later mapped
 * into the core order objects at synchronization time (Shop_Order_Service).
 *
 * @package Packlink\WooCommerce\Components\Customs
 */
class Customs_Handler {

	/**
	 * Renders the HS code and country-of-origin fields in the product Shipping tab.
	 *
	 * Hook: woocommerce_product_options_shipping.
	 *
	 * @return void
	 */
	public function render_product_fields() {
		\woocommerce_wp_text_input(
			array(
				'id'          => Customs_Mapping_Service::PRODUCT_HS_CODE_META,
				'label'       => __( 'HS code', 'packlink-pro-shipping' ),
				'description' => __( 'Harmonized System code used on customs invoices (6 to 8 digits).', 'packlink-pro-shipping' ),
				'desc_tip'    => true,
				'type'        => 'text',
				'custom_attributes' => array(
					'pattern'   => '[0-9]{6,8}',
					'maxlength' => '8',
				),
			)
		);

		\woocommerce_wp_select(
			array(
				'id'      => Customs_Mapping_Service::PRODUCT_COUNTRY_OF_ORIGIN_META,
				'label'   => __( 'Country of origin', 'packlink-pro-shipping' ),
				'desc_tip' => true,
				'description' => __( 'Country where the product was manufactured, used on customs invoices.', 'packlink-pro-shipping' ),
				'options' => $this->get_country_options(),
			)
		);
	}

	/**
	 * Sanitizes and persists the product customs meta.
	 *
	 * Hook: woocommerce_process_product_meta.
	 *
	 * @param int $post_id Product post id.
	 *
	 * @return void
	 */
	public function save_product_fields( $post_id ) {
		$product = \wc_get_product( $post_id );

		if ( ! $product ) {
			return;
		}

		$hs_code = isset( $_POST[ Customs_Mapping_Service::PRODUCT_HS_CODE_META ] ) //phpcs:ignore WordPress.Security.NonceVerification.Missing
			? \sanitize_text_field( \wp_unslash( $_POST[ Customs_Mapping_Service::PRODUCT_HS_CODE_META ] ) ) //phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';
		$hs_code = \preg_replace( '/[^0-9]/', '', $hs_code );

		$country = isset( $_POST[ Customs_Mapping_Service::PRODUCT_COUNTRY_OF_ORIGIN_META ] ) //phpcs:ignore WordPress.Security.NonceVerification.Missing
			? \sanitize_text_field( \wp_unslash( $_POST[ Customs_Mapping_Service::PRODUCT_COUNTRY_OF_ORIGIN_META ] ) ) //phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';

		if ( '' !== $country && ! in_array( $country, CountryCodes::$countryCodes, true ) ) {
			$country = '';
		}

		$product->update_meta_data( Customs_Mapping_Service::PRODUCT_HS_CODE_META, $hs_code );
		$product->update_meta_data( Customs_Mapping_Service::PRODUCT_COUNTRY_OF_ORIGIN_META, $country );
		$product->save();
	}

	/**
	 * Adds tax ID and company VAT to the storefront billing fields.
	 *
	 * Hook: woocommerce_billing_fields.
	 *
	 * @param array $fields Billing fields.
	 *
	 * @return array
	 */
	public function add_billing_fields( $fields ) {
		// WooCommerce billing-field keys are the meta key without the leading underscore; WC persists
		// each `billing_*` field to the `_billing_*` order meta the mapping service reads.
		$fields[ ltrim( Customs_Mapping_Service::BILLING_TAX_ID_META, '_' ) ] = array(
			'label'    => __( 'Tax ID', 'packlink-pro-shipping' ),
			'required' => false,
			'class'    => array( 'form-row-wide' ),
			'priority' => 120,
		);

		$fields[ ltrim( Customs_Mapping_Service::BILLING_VAT_META, '_' ) ] = array(
			'label'    => __( 'Company VAT', 'packlink-pro-shipping' ),
			'required' => false,
			'class'    => array( 'form-row-wide' ),
			'priority' => 130,
		);

		return $fields;
	}

	/**
	 * Adds tax ID and company VAT as editable fields on the admin order billing panel.
	 *
	 * Hook: woocommerce_admin_billing_fields.
	 *
	 * @param array $fields Admin billing fields.
	 *
	 * @return array
	 */
	public function add_admin_billing_fields( $fields ) {
		// Admin billing field keys are meta keys without the leading "_billing_" prefix.
		$fields['packlink_tax_id'] = array(
			'label' => __( 'Tax ID', 'packlink-pro-shipping' ),
			'show'  => true,
		);

		$fields['packlink_vat'] = array(
			'label' => __( 'Company VAT', 'packlink-pro-shipping' ),
			'show'  => true,
		);

		return $fields;
	}

	/**
	 * Seeds a default customs mapping the first time the plugin is installed/upgraded, pointing the
	 * mapping selects at the dedicated Packlink fields. Idempotent: never overwrites a merchant's
	 * saved mapping.
	 *
	 * @return void
	 */
	public static function seed_default_customs_mapping() {
		/**
		 * Configuration service.
		 *
		 * @var Config_Service $config
		 */
		$config = ServiceRegister::getService( Config_Service::CLASS_NAME );

		if ( $config->getCustomsMappings() ) {
			return;
		}

		$mapping = new CustomsMapping();

		$mapping->defaultReason           = 'sale_of_goods';
		$mapping->defaultSenderTaxId      = '';
		$mapping->defaultReceiverUserType = CustomsService::PRIVATE_PERSON;
		$mapping->defaultReceiverTaxId    = '';
		// Core CustomsMapping validation requires a 6-8 digit default tariff number; an empty value
		// produces a mapping that fails validation on read. Seed a valid placeholder HS code that the
		// merchant can override; per-product HS codes still take precedence at order-mapping time.
		$mapping->defaultTariffNumber     = '61091000';
		$mapping->defaultCountry          = '';
		$mapping->mappingReceiverTaxId    = Customs_Mapping_Service::BILLING_TAX_ID_META;
		$mapping->mappingTariffNumber     = Customs_Mapping_Service::PRODUCT_HS_CODE_META;
		$mapping->mappingCompanyVat       = Customs_Mapping_Service::BILLING_VAT_META;

		$config->setCustomsMappings( $mapping );
	}

	/**
	 * Builds the country-of-origin select options from the core supported-country list.
	 *
	 * @return array
	 */
	private function get_country_options() {
		$options = array( '' => __( '— Select —', 'packlink-pro-shipping' ) );

		foreach ( CountryCodes::$countryCodes as $code ) {
			$options[ $code ] = $code;
		}

		return $options;
	}
}
