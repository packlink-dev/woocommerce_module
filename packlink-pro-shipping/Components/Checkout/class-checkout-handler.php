<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Checkout;

use Logeecom\Infrastructure\Http\Exceptions\HttpBaseException;
use Logeecom\Infrastructure\Logger\Logger;
use Logeecom\Infrastructure\ORM\Interfaces\RepositoryInterface;
use Logeecom\Infrastructure\ORM\QueryFilter\QueryFilter;
use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\Http\Proxy;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\BusinessLogic\ShippingMethod\ShippingMethodService;
use Packlink\WooCommerce\Components\Order\Order_Details_Helper;
use Packlink\WooCommerce\Components\Order\Order_Meta_Keys;
use Packlink\WooCommerce\Components\ShippingMethod\Packlink_Shipping_Method;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Helper;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Map;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;
use WC_Order_Factory;
use WC_Shipping_Rate;

/**
 * Class Checkout_Handler
 * @package Packlink\WooCommerce\Components\Checkout
 */
class Checkout_Handler {

	/**
	 * Hidden field HTML template.
	 */
	const HIDDEN_FIELD = '<input type="hidden" name="%s" value="%s" />';
	/**
	 * Drop-off id hidden input name
	 */
	const PACKLINK_DROP_OFF_ID = 'packlink_drop_off_id';
	/**
	 * Drop-off address hidden input name
	 */
	const PACKLINK_DROP_OFF_EXTRA = 'packlink_drop_off_extra';
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
	 * @param int $index Shipping method index.
	 */
	public function after_shipping_rate( WC_Shipping_Rate $rate, $index ) {
		$shipping_method = $this->get_packlink_shipping_method( $rate->get_instance_id() );
		if ( null === $shipping_method ) {
			return;
		}

		$fields = array(
			'packlink_image_url'   => Shipping_Method_Helper::get_carrier_logo( $shipping_method->getCarrierName() ),
			'packlink_show_image'  => $shipping_method->isDisplayLogo() ? 'yes' : 'no',
			'packlink_is_drop_off' => $shipping_method->isDestinationDropOff() ? 'yes' : 'no',
		);
		foreach ( $fields as $field => $value ) {
			echo sprintf( static::HIDDEN_FIELD, $field, $value );
		}

		echo '<div class="pl-wide-shipping"></div>';
		$chosen_method = wc()->session->chosen_shipping_methods[ $index ];
		if ( $chosen_method !== wc()->session->get( Order_Meta_Keys::SHIPPING_ID, '' ) ) {
			wc()->session->set( Order_Meta_Keys::DROP_OFF_ID, '' );
			wc()->session->set( Order_Meta_Keys::DROP_OFF_EXTRA, '' );
			wc()->session->set( Order_Meta_Keys::SHIPPING_ID, '' );
		}

		if ( $rate->get_id() === $chosen_method && $shipping_method->isDestinationDropOff() ) {
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
		echo sprintf( static::HIDDEN_FIELD, static::PACKLINK_DROP_OFF_ID, '' );
		echo sprintf( static::HIDDEN_FIELD, static::PACKLINK_DROP_OFF_EXTRA, '' );
		echo '<script>Packlink.checkout.init();</script>';
	}

	/**
	 * This hook is used to validate drop-off point.
	 */
	public function checkout_process() {
		if ( ! isset( $_POST['shipping_method'][0] ) ) {
			return;
		}

		$parts = explode( ':', $_POST['shipping_method'][0] );
		$code  = $parts[0];

		if ( $code !== Packlink_Shipping_Method::PACKLINK_SHIPPING_METHOD ) {
			return;
		}

		$shipping_method = $this->get_packlink_shipping_method( (int) $parts[1] );
		$is_drop_off     = $shipping_method->isDestinationDropOff();
		if ( $is_drop_off && empty( $_POST[ static::PACKLINK_DROP_OFF_ID ] ) ) {
			wc_add_notice( __( 'Please choose a drop-off location.', 'packlink-pro-shipping' ), 'error' );
		}
	}

	/**
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * This hook is used to update drop-off point value.
	 *
	 * @param int $order_id WooCommerce order identifier.
	 * @param array $data WooCommerce order meta data.
	 */
	public function checkout_update_order_meta( $order_id, array $data ) {
		if ( empty( $data ) || ! isset( $data['shipping_method'][0] ) ) {
			return;
		}

		$parts       = explode( ':', $data['shipping_method'][0] );
		$code        = $parts[0];
		$instance_id = (int) $parts[1];

		if ( $code !== Packlink_Shipping_Method::PACKLINK_SHIPPING_METHOD ) {
			return;
		}

		$wc_order        = WC_Order_Factory::get_order( $order_id );
		$shipping_method = $this->get_packlink_shipping_method( $instance_id );
		$is_drop_off     = $shipping_method->isDestinationDropOff();

		$wc_order->update_meta_data( Order_Meta_Keys::IS_PACKLINK, 'yes' );
		$wc_order->update_meta_data( Order_Meta_Keys::LABEL_PRINTED, 'no' );
		$wc_order->update_meta_data( Order_Meta_Keys::SHIPPING_ID, $shipping_method->getId() );

		if ( $is_drop_off ) {
			$wc_order->update_meta_data( Order_Meta_Keys::DROP_OFF_ID, (int) $_POST[ static::PACKLINK_DROP_OFF_ID ] );
			$wc_order->update_meta_data( Order_Meta_Keys::DROP_OFF_EXTRA, $_POST[ static::PACKLINK_DROP_OFF_EXTRA ] );
		}

		$wc_order->save();
		Order_Details_Helper::queue_draft( $order_id, $wc_order );
	}

	/**
	 * This hook is used to print drop-off point information to customer on order view page.
	 *
	 * @param int $order_id WooCommerce order identifier.
	 */
	public function after_customer_details( $order_id ) {
		$wc_order = WC_Order_Factory::get_order( $order_id );
		if ( ! $wc_order->meta_exists( Order_Meta_Keys::IS_PACKLINK ) ) {
			return;
		}

		$shipping_method = Order_Details_Helper::get_packlink_shipping_method( $wc_order );
		if ( $shipping_method && $shipping_method->isDestinationDropOff() ) {
			$json = $wc_order->get_meta( Order_Meta_Keys::DROP_OFF_EXTRA );
			/** @noinspection PhpUnusedLocalVariableInspection */
			$location = json_decode( \stripslashes( $json ), true );

			include dirname( __DIR__ ) . '/../resources/views/order-view-drop-off.php';
		}
	}

	/**
	 * Loads javascript and css resources
	 */
	public function load_scripts() {
		$base_url = Shop_Helper::get_plugin_base_url() . 'resources/';
		wp_enqueue_script(
			'packlink_ajax',
			esc_url( $base_url . 'js/core/packlink-ajax-service.js' ),
			array(),
			1
		);
		wp_enqueue_script(
			'packlink_checkout',
			esc_url( $base_url . 'js/packlink-checkout.js' ),
			array(),
			1
		);
		wp_enqueue_style(
			'packlink_checkout_css',
			esc_url( $base_url . 'css/packlink-checkout.css' ),
			array(),
			1
		);
	}

	/**
	 * Returns array of locations for this shipping service.
	 *
	 * @param int $service_id Service identifier.
	 *
	 * @return array Locations.
	 */
	public function get_drop_off_locations( $service_id ) {
		$result = array();
		/** @var Proxy $proxy */
		$proxy    = ServiceRegister::getService( Proxy::CLASS_NAME );
		$customer = wc()->session->customer;
		try {
			$locations = $proxy->getLocations( $service_id, $customer['shipping_country'], $customer['shipping_postcode'] );
			foreach ( $locations as $location ) {
				$result[] = $location->toArray();
			}
		} catch ( HttpBaseException $e ) {
			Logger::logError( $e->getMessage(), 'Integration', array(
				'service_id'   => $service_id,
				'country_code' => $customer['shipping_country'],
				'postal_code'  => $customer['shipping_postcode'],
			) );
		}

		return $result;
	}

	/**
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * Returns Packlink shipping method that is assigned to this WooCommerce shipping method.
	 *
	 * @param int $instance_id Shipping method identifier.
	 *
	 * @return ShippingMethod Shipping method.
	 */
	private function get_packlink_shipping_method( $instance_id ) {
		$filter = new QueryFilter();
		/** @noinspection PhpUnhandledExceptionInspection */
		$filter->where( 'woocommerceShippingMethodId', '=', $instance_id );

		/** @var Shipping_Method_Map $map_entry */
		$map_entry = $this->repository->selectOne( $filter );
		if ( null === $map_entry ) {
			return null;
		}

		return $this->shipping_method_service->getShippingMethod( $map_entry->getPacklinkShippingMethodId() );
	}
}
