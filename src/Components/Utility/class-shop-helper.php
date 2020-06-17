<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Utility;

use Packlink\WooCommerce\Components\Services\Logger_Service;

/**
 * Class Shop_Helper
 *
 * @package Packlink\WooCommerce\Components\Utility
 */
class Shop_Helper {
	/**
	 * The version of the plugin.
	 *
	 * @var string
	 */
	private static $plugin_version;

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
		if ( ! is_multisite() ) {
			return false;
		}

		$plugins = get_site_option( 'active_sitewide_plugins' );

		return isset( $plugins[ self::get_plugin_name() ] );
	}

	/**
	 * Returns the name of the plugin
	 *
	 * @return string
	 */
	public static function get_plugin_name() {
		return plugin_basename( dirname( dirname( __DIR__ ) ) . '/packlink-pro-shipping.php' );
	}

	/**
	 * Returns if Packlink PRO Shipping plugin is active for current site
	 *
	 * @return bool
	 */
	public static function is_plugin_active_for_current_site() {
		return in_array(
			self::get_plugin_name(),
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
		return self::is_plugin_active( 'woocommerce.php' );
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
		if ( ! self::$plugin_version ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . self::get_plugin_name() );

			self::$plugin_version = $plugin_data['Version'];
		}

		return self::$plugin_version;
	}

	/**
	 * Gets URL for Packlink controller.
	 *
	 * @param string $name Name of the controller without "Packlink" and "Controller".
	 * @param string $action Name of the action.
	 * @param array  $params Associative array of parameters.
	 *
	 * @return string
	 */
	public static function get_controller_url( $name, $action = '', array $params = array() ) {
		$query = array( 'packlink_pro_controller' => $name );
		if ( ! empty( $action ) ) {
			$query['action'] = $action;
		}

		$query = array_merge( $query, $params );

		return get_home_url() . '/?' . http_build_query( $query );
	}

	/**
	 * Gets URL to Packlink PRO Shipping plugin root folder.
	 *
	 * @return string
	 */
	public static function get_plugin_base_url() {
		return plugins_url( '/', dirname( __DIR__ ) );
	}

	/**
	 * Gets URL to Packlink PRO Shipping plugin configuration page.
	 *
	 * @return string
	 */
	public static function get_plugin_page_url() {
		return admin_url( 'admin.php?page=packlink-pro-shipping' );
	}

	/**
	 * Creates log directory with protection files.
	 */
	public static function create_log_directory() {
		$dir = dirname( Logger_Service::get_log_file() );

		if ( ! is_dir( $dir ) ) {
			if ( ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
				return;
			}

			$dir = rtrim( $dir ) . '/';
			file_put_contents( $dir . '.htaccess', 'deny from all' );
			file_put_contents( $dir . 'index.html', '' );
		}
	}

	/**
	 * Checks if plugin is active.
	 *
	 * @param string $plugin_name The name of the plugin main entry point file. For example "packlink-pro-shipping.php".
	 *
	 * @return bool
	 */
	private static function is_plugin_active( $plugin_name ) {
		$all_plugins = get_option( 'active_plugins' );

		if ( is_multisite() ) {
			$all_plugins = array_merge( $all_plugins, array_keys( get_site_option( 'active_sitewide_plugins' ) ) );
		}

		foreach ( $all_plugins as $plugin ) {
			if ( false !== strpos( $plugin, '/' . $plugin_name ) ) {
				return true;
			}
		}

		return false;
	}
}
