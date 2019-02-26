<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\ShippingMethod;

use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\ServiceRegister;
use Packlink\WooCommerce\Components\Services\Config_Service;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;

/**
 * Class Shipping_Method_Helper
 * @package Packlink\WooCommerce\Components\ShippingMethod
 */
class Shipping_Method_Helper {

	/**
	 * Returns path to carrier logo or empty string if that logo file doesn't exist.
	 *
	 * @param string $carrier_name Name of the carrier.
	 *
	 * @return string Carrier image url.
	 */
	public static function get_carrier_logo( $carrier_name ) {
		$base_path = Shop_Helper::get_plugin_base_url() . 'resources/images/carriers/';
		$default   = $base_path . 'carrier.jpg';

		/** @var Config_Service $configService */
		$configService = ServiceRegister::getService( Config_Service::CLASS_NAME );
		$user_info     = $configService->getUserInfo();
		if ( null === $user_info ) {
			return $default;
		}

		$file_name  = \strtolower( str_replace( ' ', '-', $carrier_name ) );
		$image_path = $base_path . \strtolower( $user_info->country ) . '/' . $file_name . '.png';

		return $image_path ?: $default;
	}

	/**
	 * Disable Packlink added shipping methods.
	 */
	public static function disable_packlink_shipping_methods() {
		static::change_shipping_methods_status( 0 );
	}

	/**
	 * Enable Packlink added shipping methods.
	 */
	public static function enable_packlink_shipping_methods() {
		static::change_shipping_methods_status();
	}

	/**
	 * Returns count of active shop shipping methods.
	 *
	 * @return int Count of shop active shipping methods.
	 */
	public static function get_shop_shipping_method_count() {
		$count = 0;

		foreach ( self::get_all_shipping_zone_ids() as $zone_id ) {
			$zone = \WC_Shipping_Zones::get_zone( $zone_id );
			if ( ! $zone ) {
				continue;
			}

			foreach ( $zone->get_shipping_methods( true ) as $item ) {
				if ( $item->id !== Packlink_Shipping_Method::PACKLINK_SHIPPING_METHOD ) {
					$count ++;
				}
			}
		}

		return $count;
	}

	/**
	 * Disables all active shop shipping methods.
	 */
	public static function disable_shop_shipping_methods() {
		global $wpdb;

		foreach ( self::get_all_shipping_zone_ids() as $zone_id ) {
			$zone = \WC_Shipping_Zones::get_zone( $zone_id );
			if ( ! $zone ) {
				continue;
			}

			/** @var \WC_Shipping_Method $item */
			foreach ( $zone->get_shipping_methods( true ) as $item ) {
				if ( ( $item->id !== Packlink_Shipping_Method::PACKLINK_SHIPPING_METHOD )
				     && $wpdb->update( "{$wpdb->prefix}woocommerce_shipping_zone_methods", array( 'is_enabled' => 0 ), array( 'instance_id' => absint( $item->instance_id ) ) )
				) {
					do_action( 'woocommerce_shipping_zone_method_status_toggled', $item->instance_id, $item->id, $zone_id, 0 );
				}
			}
		}
	}

	/**
	 * Fully remove Packlink added shipping methods.
	 */
	public static function remove_packlink_shipping_methods() {
		global $wpdb;

		foreach ( static::get_shipping_method_map() as $item ) {
			$instance_id = $item->getWoocommerceShippingMethodId();
			$method      = new Packlink_Shipping_Method( $instance_id );
			$option_key  = $method->get_instance_option_key();
			if ( $wpdb->delete( "{$wpdb->prefix}woocommerce_shipping_zone_methods", array( 'instance_id' => $instance_id ) ) ) {
				delete_option( $option_key );
			}
		}
	}

	/**
	 * Return array of all zone ids.
	 *
	 * @return int[] Zone ids.
	 */
	public static function get_all_shipping_zone_ids() {
		$all_zones = \WC_Shipping_Zones::get_zones();
		$zone_ids  = array_column( $all_zones, 'zone_id' );
		// Locations not covered by other zones
		if ( ! in_array( 0, $zone_ids, true ) ) {
			$zone_ids[] = 0;
		}

		return $zone_ids;
	}

	/**
	 * Loads all packlink added shipping methods and changes their status to enabled or disabled.
	 *
	 * @param int $status
	 */
	private static function change_shipping_methods_status( $status = 1 ) {
		global $wpdb;

		foreach ( static::get_shipping_method_map() as $item ) {
			$instance_id = $item->getWoocommerceShippingMethodId();
			$method      = new Packlink_Shipping_Method( $instance_id );

			if ( $wpdb->update( "{$wpdb->prefix}woocommerce_shipping_zone_methods", array( 'is_enabled' => $status ), array( 'instance_id' => absint( $instance_id ) ) ) ) {
				do_action( 'woocommerce_shipping_zone_method_status_toggled', $instance_id, $method->id, $item->getZoneId(), $status );
			}
		}
	}

	/**
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * Returns map of Packlink shipping services and WooCommerce shipping methods.
	 *
	 * @return Shipping_Method_Map[]
	 */
	private static function get_shipping_method_map() {
		/** @noinspection PhpUnhandledExceptionInspection */
		$repository = RepositoryRegistry::getRepository( Shipping_Method_Map::CLASS_NAME );
		/** @var Shipping_Method_Map[] $entities */
		$entities = $repository->select();

		return $entities;
	}
}
