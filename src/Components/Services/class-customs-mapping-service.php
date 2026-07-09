<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Services;

use Packlink\BusinessLogic\Customs\CustomsMappingService as Base_Customs_Mapping_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Customs_Mapping_Service
 *
 * WooCommerce implementation of the core abstract customs mapping service. It supplies the
 * platform-driven field definitions rendered by the core customs settings page: for each mapping
 * select, the label and the list of WooCommerce fields that can hold that customs value.
 *
 * The returned option `value`s are the meta keys resolved back into the core order objects at
 * order-synchronization time (see Shop_Order_Service).
 *
 * @package Packlink\WooCommerce\Components\Services
 */
class Customs_Mapping_Service extends Base_Customs_Mapping_Service {

	/**
	 * Product meta key holding the Packlink HS (tariff) code.
	 */
	const PRODUCT_HS_CODE_META = '_packlink_hs_code';

	/**
	 * Product meta key holding the country of origin (ISO-3166-1 alpha-2).
	 */
	const PRODUCT_COUNTRY_OF_ORIGIN_META = '_packlink_country_of_origin';

	/**
	 * Order/billing meta key holding the customer tax id.
	 */
	const BILLING_TAX_ID_META = '_billing_packlink_tax_id';

	/**
	 * Order/billing meta key holding the company VAT number.
	 */
	const BILLING_VAT_META = '_billing_packlink_vat';

	/**
	 * Returns the data-mapping field definitions rendered by the core customs settings page.
	 *
	 * One entry per mapping select, in the raw array shape the core CustomsController.js consumes:
	 * `{ field, label, options: [ { value, name } ] }`. The `field` values match the CustomsMapping
	 * DTO keys.
	 *
	 * @return array
	 */
	public function getMappingFieldsOptions() {
		return array(
			array(
				'field'   => 'mapping_receiver_tax_id',
				'label'   => __( 'Customer tax ID field', 'packlink-pro-shipping' ),
				'options' => array(
					array(
						'value' => self::BILLING_TAX_ID_META,
						'name'  => __( 'Packlink customer tax ID', 'packlink-pro-shipping' ),
					),
				),
			),
			array(
				'field'   => 'mapping_tariff_number',
				'label'   => __( 'Product HS code field', 'packlink-pro-shipping' ),
				'options' => $this->get_tariff_number_options(),
			),
			array(
				'field'   => 'mapping_company_vat',
				'label'   => __( 'Company VAT field', 'packlink-pro-shipping' ),
				'options' => array(
					array(
						'value' => self::BILLING_VAT_META,
						'name'  => __( 'Packlink company VAT', 'packlink-pro-shipping' ),
					),
					array(
						'value' => '_billing_company',
						'name'  => __( 'Billing company', 'packlink-pro-shipping' ),
					),
				),
			),
		);
	}

	/**
	 * Builds the Product HS code mapping options: the dedicated Packlink product meta plus any
	 * global WooCommerce product attributes the merchant may already use to store the HS code.
	 *
	 * @return array
	 */
	private function get_tariff_number_options() {
		$options = array(
			array(
				'value' => self::PRODUCT_HS_CODE_META,
				'name'  => __( 'Packlink product HS code', 'packlink-pro-shipping' ),
			),
		);

		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return $options;
		}

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$options[] = array(
				'value' => 'pa_' . $attribute->attribute_name,
				'name'  => sprintf(
				/* translators: %s: product attribute label. */
					__( 'Product attribute: %s', 'packlink-pro-shipping' ),
					$attribute->attribute_label
				),
			);
		}

		return $options;
	}
}
