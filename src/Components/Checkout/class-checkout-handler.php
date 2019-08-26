<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Checkout;

use Logeecom\Infrastructure\Logger\Logger;
use Logeecom\Infrastructure\ORM\Interfaces\RepositoryInterface;
use Logeecom\Infrastructure\ORM\QueryFilter\QueryFilter;
use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\Location\LocationService;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\BusinessLogic\ShippingMethod\ShippingMethodService;
use Packlink\WooCommerce\Components\Order\Order_Details_Helper;
use Packlink\WooCommerce\Components\Order\Order_Meta_Keys;
use Packlink\WooCommerce\Components\Services\Config_Service;
use Packlink\WooCommerce\Components\ShippingMethod\Packlink_Shipping_Method;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Helper;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Map;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;
use WC_Order_Factory;
use WC_Shipping_Rate;

/**
 * Class Checkout_Handler
 *
 * @package Packlink\WooCommerce\Components\Checkout
 */
class Checkout_Handler {

	/**
	 * Drop-off id hidden input name
	 */
	const PACKLINK_DROP_OFF_ID = 'packlink_drop_off_id';
	/**
	 * Drop-off address hidden input name
	 */
	const PACKLINK_DROP_OFF_EXTRA = 'packlink_drop_off_extra';
	/**
	 * Default Packlink shipping title
	 */
	const DEFAULT_SHIPPING = 'shipping cost';
	/**
	 * Base repository.
	 *
	 * @var RepositoryInterface
	 */
	private $repository;
	/**
	 * Shipping method service.
	 *
	 * @var ShippingMethodService
	 */
	private $shipping_method_service;

	/**
	 * Checkout_Handler constructor.
	 */
	public function __construct() {
		/** @noinspection PhpUnhandledExceptionInspection */
		$this->repository              = RepositoryRegistry::getRepository( Shipping_Method_Map::CLASS_NAME );
		$this->shipping_method_service = ServiceRegister::getService( ShippingMethodService::CLASS_NAME );
	}

	/**
	 * This hook is triggered after shipping method label, and it will insert hidden input values.
	 *
	 * @param WC_Shipping_Rate $rate Shipping rate.
	 * @param int              $index Shipping method index.
	 */
	public function after_shipping_rate( WC_Shipping_Rate $rate, $index ) {
		$rate_data       = $this->get_rate_data( $rate );
		$shipping_method = $this->get_packlink_shipping_method( $rate_data['instance_id'] );
		if ( null === $shipping_method ) {
			return;
		}

		$fields = array(
			'packlink_image_url'   => Shipping_Method_Helper::get_carrier_logo( $shipping_method->getCarrierName() ),
			'packlink_show_image'  => $shipping_method->isDisplayLogo() ? 'yes' : 'no',
			'packlink_is_drop_off' => $shipping_method->isDestinationDropOff() ? 'yes' : 'no',
		);
		foreach ( $fields as $field => $value ) {
			$this->print_hidden_input( $field, $value );
		}

		$chosen_method = wc()->session->chosen_shipping_methods[ $index ];
		if ( wc()->session->get( Order_Meta_Keys::SHIPPING_ID, '' ) !== $chosen_method ) {
			wc()->session->set( Order_Meta_Keys::DROP_OFF_ID, '' );
			wc()->session->set( Order_Meta_Keys::DROP_OFF_EXTRA, '' );
			wc()->session->set( Order_Meta_Keys::SHIPPING_ID, '' );
		}

		if ( $rate_data['id'] === $chosen_method && $shipping_method->isDestinationDropOff() ) {
			include dirname( __DIR__ ) . '/../resources/views/shipping-method-drop-off.php';
		}
	}

	/**
	 * Initializes script on cart page.
	 */
	public function after_shipping_calculator() {
		echo '<script>Packlink.checkout.init();</script>';
	}

	/**
	 * Sets hidden field for drop-off data and initializes script.
	 */
	public function after_shipping() {
		$this->print_hidden_input( static::PACKLINK_DROP_OFF_ID );
		$this->print_hidden_input( static::PACKLINK_DROP_OFF_EXTRA );
		echo '<script>Packlink.checkout.init();</script>';
	}

	/**
	 * This hook is used to validate drop-off point.
	 */
	public function checkout_process() {
		$shipping_param = $this->get_param( 'shipping_method', false );
		if ( ! $shipping_param ) {
			return;
		}

		$parts = explode( ':', $shipping_param );
		$code  = $parts[0];

		if ( Packlink_Shipping_Method::PACKLINK_SHIPPING_METHOD !== $code ) {
			return;
		}

		$shipping_method = $this->get_packlink_shipping_method( (int) $parts[1] );
		$is_drop_off     = $shipping_method->isDestinationDropOff();
		$drop_off_id     = $this->get_param( static::PACKLINK_DROP_OFF_ID );
		if ( $is_drop_off && empty( $drop_off_id ) ) {
			wc_add_notice( __( 'Please choose a drop-off location.', 'packlink-pro-shipping' ), 'error' );
		}
	}

	/**
	 * Substitutes order shipping address with drop-off address.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @param array     $data Order data.
	 */
	public function checkout_update_shipping_address( \WC_Order $order, array $data ) {
		$shipping_method = $this->get_shipping_method( $data );
		if ( ! $shipping_method ) {
			return;
		}

		$is_drop_off = $shipping_method->isDestinationDropOff();
		if ( $is_drop_off ) {
			try {
				$drop_off_address = json_decode( $this->get_param( static::PACKLINK_DROP_OFF_EXTRA ), true );
				$order->set_shipping_company( $drop_off_address['name'] );
				$order->set_shipping_city( $drop_off_address['city'] );
				$order->set_shipping_postcode( $drop_off_address['zip'] );
				$order->set_shipping_state( $drop_off_address['state'] );
				$order->set_shipping_address_1( $drop_off_address['address'] );
			} catch ( \WC_Data_Exception $e ) {
				Logger::logError( 'Unable to substitute delivery address with drop-off location.', 'Integration', $data );
			}
		}
	}

	/**
	 * This hook is used to update drop-off point value.
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * @param int   $order_id WooCommerce order identifier.
	 * @param array $data WooCommerce order meta data.
	 */
	public function checkout_update_order_meta( $order_id, array $data ) {
		$shipping_method = $this->get_shipping_method( $data );
		if ( ! $shipping_method ) {
			return;
		}

		$wc_order    = WC_Order_Factory::get_order( $order_id );
		$is_drop_off = $shipping_method->isDestinationDropOff();

		$wc_order->update_meta_data( Order_Meta_Keys::IS_PACKLINK, 'yes' );
		$wc_order->update_meta_data( Order_Meta_Keys::LABEL_PRINTED, 'no' );
		$wc_order->update_meta_data( Order_Meta_Keys::SHIPPING_ID, $shipping_method->getId() );

		if ( $is_drop_off ) {
			$wc_order->update_meta_data( Order_Meta_Keys::DROP_OFF_ID, $this->get_param( static::PACKLINK_DROP_OFF_ID ) );
			$wc_order->update_meta_data( Order_Meta_Keys::DROP_OFF_EXTRA, $this->get_param( static::PACKLINK_DROP_OFF_EXTRA ) );
		}

		$wc_order->save();
		Order_Details_Helper::queue_draft( $order_id, $wc_order );
	}

	/**
	 * Checks if default Packlink shipping method should be removed.
	 *
	 * @param array $rates Shipping rates.
	 *
	 * @return array Filtered shipping rates.
	 */
	public function check_additional_packlink_rate( $rates ) {
		if ( count( $rates ) === 1 ) {
			return $rates;
		}

		/**
		 * Map with key as shipping method id and rate as its value.
		 *
		 * @var string $key
		 * @var WC_Shipping_Rate $rate
		 */
		foreach ( $rates as $key => $rate ) {
			$rate_data = $this->get_rate_data( $rate );
			if ( Packlink_Shipping_Method::PACKLINK_SHIPPING_METHOD === $rate_data['method_id'] && self::DEFAULT_SHIPPING === $rate_data['label'] ) {
				unset( $rates[ $key ] );
				break;
			}
		}

		return $rates;
	}

	/**
	 * Loads javascript and css resources
	 */
	public function load_scripts() {
		$base_url = Shop_Helper::get_plugin_base_url() . 'resources/';
		wp_enqueue_script(
			'packlink_ajax',
			$base_url . 'js/core/packlink-ajax-service.js',
			array(),
			1
		);
		wp_enqueue_script(
			'packlink_translations',
			$base_url . 'js/location-picker/packlink-translations.js',
			array(),
			1
		);
		wp_enqueue_script(
			'packlink_location_picker',
			$base_url . 'js/location-picker/packlink-location-picker.js',
			array(),
			1
		);
		wp_enqueue_script(
			'packlink_checkout',
			$base_url . 'js/packlink-checkout.js',
			array(),
			1
		);
		wp_enqueue_style(
			'packlink_checkout_css',
			$base_url . 'css/packlink-checkout.css',
			array(),
			1
		);
		wp_enqueue_style(
			'packlink_location_picker_css',
			$base_url . 'css/packlink-location-picker.css',
			array(),
			1
		);
	}

	/**
	 * Returns array of locations for this shipping service.
	 *
	 * @param int $method_id Service identifier.
	 *
	 * @return array Locations.
	 */
	public function get_drop_off_locations( $method_id ) {
		$customer = wc()->session->customer;

		/**
		 * Location service.
		 *
		 * @var LocationService $location_service
		 */
		$location_service = ServiceRegister::getService( LocationService::CLASS_NAME );

		return $location_service->getLocations( $method_id, $customer['shipping_country'], $customer['shipping_postcode'] );
	}

	/**
	 * Returns Packlink shipping method that is assigned to this WooCommerce shipping method.
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * @param int $instance_id Shipping method identifier.
	 *
	 * @return ShippingMethod Shipping method.
	 */
	private function get_packlink_shipping_method( $instance_id ) {
		$filter = new QueryFilter();
		/** @noinspection PhpUnhandledExceptionInspection */
		$filter->where( 'woocommerceShippingMethodId', '=', $instance_id );

		/**
		 * Shipping method map entity.
		 *
		 * @var Shipping_Method_Map $map_entry
		 */
		$map_entry = $this->repository->selectOne( $filter );
		if ( null === $map_entry ) {
			return null;
		}

		$id = $map_entry->getPacklinkShippingMethodId();
		if ( - 1 === $id ) {
			/**
			 * Configuration service.
			 *
			 * @var Config_Service $configuration
			 */
			$configuration = ServiceRegister::getService( Config_Service::CLASS_NAME );

			return $configuration->get_default_shipping_method();
		}

		return $this->shipping_method_service->getShippingMethod( $map_entry->getPacklinkShippingMethodId() );
	}

	/**
	 * Returns Packlink shipping method.
	 *
	 * @param array $data Order data.
	 *
	 * @return ShippingMethod|null Shipping method.
	 */
	private function get_shipping_method( array $data = array() ) {
		if ( empty( $data ) || ! isset( $data['shipping_method'][0] ) ) {
			return null;
		}

		$parts       = explode( ':', $data['shipping_method'][0] );
		$code        = $parts[0];
		$instance_id = (int) $parts[1];

		if ( Packlink_Shipping_Method::PACKLINK_SHIPPING_METHOD !== $code ) {
			return null;
		}

		return $this->get_packlink_shipping_method( $instance_id );
	}

	/**
	 * Echoes sanitized input field.
	 *
	 * @param string $field Input field name.
	 * @param string $value Input field value.
	 */
	private function print_hidden_input( $field, $value = '' ) {
		$allowed_html = array(
			'input' => array(
				'type'  => array(),
				'name'  => array(),
				'value' => array(),
			),
		);

		echo wp_kses( sprintf( '<input type="hidden" name="%s" value="%s" />', $field, $value ), $allowed_html );
	}

	/**
	 * Gets request parameter if exists. Otherwise, returns null.
	 *
	 * @param string $key Request parameter key.
	 * @param bool   $is_text Is text value.
	 *
	 * @return mixed
	 */
	private function get_param( $key, $is_text = true ) {
		$nonce = 'woocommerce-process-checkout-nonce';
		if ( isset( $_REQUEST[ $key ], $_REQUEST[ $nonce ] ) && wp_verify_nonce( sanitize_key( $_REQUEST[ $nonce ] ), 'woocommerce-process_checkout' ) ) {
			return sanitize_text_field( wp_unslash( $is_text ? $_REQUEST[ $key ] : $_REQUEST[ $key ][0] ) );
		}

		return null;
	}

	/**
	 * Gets the data from shipping rate keeping the backward compatibility.
	 *
	 * @param WC_Shipping_Rate $rate Shipping method.
	 *
	 * @return array
	 */
	private function get_rate_data( WC_Shipping_Rate $rate ) {
		return array(
			'rate_id'     => method_exists( $rate, 'get_id' ) ? $rate->get_id() : $rate->id,
			'instance_id' => method_exists( $rate, 'get_instance_id' ) ? $rate->get_instance_id() : $rate->id,
			'method_id'   => method_exists( $rate, 'get_method_id' ) ? $rate->get_method_id() : $rate->method_id,
			'label'       => $rate->get_label(),
		);
	}
}
