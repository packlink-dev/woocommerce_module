<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Validators;

/**
 * Class Parcel_Validator
 * @package Packlink\WooCommerce\Components\Validators
 */
class Parcel_Validator {

	private static $fields = array(
		'weight',
		'width',
		'height',
		'length',
	);

	private static $int_fields = array(
		'width',
		'height',
		'length',
	);

	/**
	 * Validates parcel data.
	 *
	 * @param array $data Payload data.
	 *
	 * @return array Validation errors.
	 */
	public function validate( array $data ) {
		$result = array();

		foreach ( static::$fields as $field ) {
			if ( empty( $data[ $field ] ) ) {
				$result[ $field ] = __( 'Field is required.', 'packlink-pro-shipping' );
				continue;
			}

			$value        = $data[ $field ];
			$is_int_field = in_array( $field, static::$int_fields, true );

			$options = array( 'options' => array( 'min_range' => 0 ) );
			if ( $is_int_field && filter_var( $value, FILTER_VALIDATE_INT, $options ) === false ) {
				$result[ $field ] = __( 'Field must be valid whole number.', 'packlink-pro-shipping' );
				continue;
			}

			if ( ! $is_int_field && filter_var( $value, FILTER_VALIDATE_FLOAT, $options ) === false ) {
				$result[ $field ] = __( 'Field must be valid decimal number.', 'packlink-pro-shipping' );
			}
		}

		return $result;
	}
}
