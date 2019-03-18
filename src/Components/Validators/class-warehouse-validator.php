<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Validators;

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\Http\Proxy;

/**
 * Class Warehouse_Validator
 *
 * @package Packlink\WooCommerce\Components\Validators
 */
class Warehouse_Validator {

	/**
	 * Required fields.
	 *
	 * @var string[]
	 */
	private static $required_fields = array(
		'alias',
		'name',
		'surname',
		'country',
		'postal_code',
		'address',
		'phone',
		'email',
	);

	/**
	 * Proxy instance.
	 *
	 * @var Proxy
	 */
	private $proxy;

	/**
	 * Warehouse_Validator constructor.
	 */
	public function __construct() {
		$this->proxy = ServiceRegister::getService( Proxy::CLASS_NAME );
	}

	/**
	 * Validates warehouse data.
	 *
	 * @param array $data Payload data.
	 *
	 * @return array Validation errors.
	 */
	public function validate( array $data ) {
		$result = array();

		foreach ( static::$required_fields as $field ) {
			if ( empty( $data[ $field ] ) ) {
				$result[ $field ] = __( 'Field is required.', 'packlink-pro-shipping' );
			}
		}

		if ( ! empty( $data['country'] ) && ! empty( $data['postal_code'] ) ) {
			try {
				$postal_codes = $this->proxy->getPostalCodes( $data['country'], $data['postal_code'] );
				if ( empty( $postal_codes ) ) {
					$result['postal_code'] = __( 'Postal code is not correct.', 'packlink-pro-shipping' );
				}
			} catch ( \Exception $e ) {
				$result['postal_code'] = __( 'Postal code is not correct.', 'packlink-pro-shipping' );
			}
		}

		if ( ! empty( $data['email'] ) && ! filter_var( $data['email'], FILTER_VALIDATE_EMAIL ) ) {
			$result['email'] = __( 'Field must be valid email.', 'packlink-pro-shipping' );
		}

		if ( ! empty( $data['phone'] ) ) {
			$regex       = '/^(\+|\/|\.|-|\(|\)|\d)+$/m';
			$phone_error = ! preg_match( $regex, $data['phone'] );

			$digits       = '/\d/m';
			$match        = preg_match_all( $digits, $data['phone'] );
			$phone_error |= false === $match || $match < 3;

			if ( $phone_error ) {
				$result['phone'] = __( 'Field mus be valid phone number.', 'packlink-pro-shipping' );
			}
		}

		return $result;
	}
}
