<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Utility;

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\WooCommerce\Components\Services\Config_Service;

/**
 * Class Shop_Helper
 *
 * @package Packlink\WooCommerce\Components\Utility
 */
class Shop_Helper {
	/**
	 * Plugin identifier.
	 */
	const PLUGIN_ID = 'packlink-pro-shipping/packlink-pro-shipping.php';

	/**
	 * Country code.
	 *
	 * @var string
	 */
	private static $country_code;

	/**
	 * Returns whether Packlink PRO Shipping plugin is enabled.
	 *
	 * @return bool
	 */
	public static function is_plugin_enabled() {
		if ( self::is_plugin_active_for_network() ) {
			return true;
		}

		return self::is_plugin_active_for_current_site();
	}

	/**
	 * Returns if Packlink PRO Shipping plugin is active through network
	 *
	 * @return bool
	 */
	public static function is_plugin_active_for_network() {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . '/wp-admin/includes/plugin.php';
		}

		return is_plugin_active_for_network( self::PLUGIN_ID );
	}

	/**
	 * Returns if Packlink PRO Shipping plugin is active for current site
	 *
	 * @return bool
	 */
	public static function is_plugin_active_for_current_site() {
		return in_array(
			self::PLUGIN_ID,
			apply_filters( 'active_plugins', get_option( 'active_plugins' ) ),
			true
		);
	}

	/**
	 * Checks if WooCommerce is active in the shop.
	 *
	 * @return bool
	 */
	public static function is_woocommerce_active() {
		return is_plugin_active( 'woocommerce/woocommerce.php' );
	}

	/**
	 * Checks if cURL library is installed and enabled on the system.
	 *
	 * @return bool
	 */
	public static function is_curl_enabled() {
		return function_exists( 'curl_version' );
	}

	/**
	 * Returns plugin current version.
	 *
	 * @return string
	 */
	public static function get_plugin_version() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . self::PLUGIN_ID );

		return $plugin_data['Version'];
	}

	/**
	 * Gets URL for Packlink controller.
	 *
	 * @param string $name Name of the controller without "Packlink" and "Controller".
	 * @param string $action Name of the action.
	 * @param array $params Associative array of parameters.
	 *
	 * @return string
	 */
	public static function get_controller_url( $name, $action = '', array $params = array() ) {
		$query = array( 'controller' => $name );
		if ( ! empty( $action ) ) {
			$query['action'] = $action;
		}

		$query = array_merge( $query, $params );
		$url   = get_site_url() . '/wp-content/plugins/packlink-pro-shipping/Controllers/class-packlink-index.php?'
		         . http_build_query( $query );

		return $url;
	}

	/**
	 * Gets URL to Packlink PRO Shipping plugin root folder.
	 *
	 * @return string
	 */
	public static function get_plugin_base_url() {
		return plugins_url() . '/packlink-pro-shipping/';
	}

	/**
	 * Returns country code.
	 *
	 * @return string Country code.
	 */
	public static function get_country_code() {
		if ( ! static::$country_code ) {
			/** @var Config_Service $config */
			$config = ServiceRegister::getService( Config_Service::CLASS_NAME );
			$user   = $config->getUserInfo();

			static::$country_code = 'es';
			if ( $user && $user->country ) {
				static::$country_code = strtolower( $user->country );
			}
		}

		return static::$country_code;
	}
}
