<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Utility;

use DateTime;
use Logeecom\Infrastructure\Exceptions\BaseException;
use Logeecom\Infrastructure\ORM\QueryFilter\QueryFilter;
use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\QueueItem;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\WooCommerce\Components\Services\Config_Service;
use Packlink\WooCommerce\Components\Services\Logger_Service;
use WooCommerce;
use ZipArchive;

/**
 * Class Debug_Helper
 *
 * @package Packlink\WooCommerce\Components\Utility
 */
class Debug_Helper {

	const PHP_INFO_FILE_NAME         = 'phpinfo.html';
	const SYSTEM_INFO_FILE_NAME      = 'system-info.txt';
	const LOG_FILE_NAME              = 'logs.txt';
	const WC_LOG_FILE_NAME           = 'wc-logs.txt';
	const USER_INFO_FILE_NAME        = 'packlink-user-info.txt';
	const QUEUE_INFO_FILE_NAME       = 'queue.txt';
	const PARCEL_WAREHOUSE_FILE_NAME = 'parcel-warehouse.txt';
	const SERVICE_INFO_FILE_NAME     = 'services.txt';
	const DATABASE                   = 'MySQL';

	/**
	 * Returns path to zip archive that contains current system information.
	 *
	 * @return string Temporary file path.
	 */
	public static function get_system_info() {
		$file = tempnam( sys_get_temp_dir(), 'packlink_system_info' );

		$zip = new ZipArchive();
		$zip->open( $file, ZipArchive::CREATE );

		$php_info = static::get_php_info();

		if ( false !== $php_info ) {
			$zip->addFromString( static::PHP_INFO_FILE_NAME, $php_info );
		}

		$dir = dirname( Logger_Service::get_log_file() );
		$zip->addFromString( static::SYSTEM_INFO_FILE_NAME, static::get_woocommerce_shop_info() );
		$zip->addFromString( static::LOG_FILE_NAME, static::get_logs( $dir ) );
		/** @noinspection PhpUndefinedConstantInspection */
		$zip->addFromString( static::WC_LOG_FILE_NAME, static::get_logs( WC_LOG_DIR ) );
		$zip->addFromString( static::USER_INFO_FILE_NAME, static::get_user_info() );
		$zip->addFromString( static::QUEUE_INFO_FILE_NAME, static::get_queue_status() );
		$zip->addFromString( static::PARCEL_WAREHOUSE_FILE_NAME, static::get_parcel_and_warehouse_info() );
		$zip->addFromString( static::SERVICE_INFO_FILE_NAME, static::get_services_info() );

		$zip->close();

		return $file;
	}

	/**
	 * Retrieves php info.
	 *
	 * @return false | string
	 */
	protected static function get_php_info() {
		ob_start();
		phpinfo();

		return ob_get_clean();
	}

	/**
	 * Returns information about WooCommerce and plugin.
	 *
	 * @return string
	 */
	protected static function get_woocommerce_shop_info() {
		global $wpdb, $wp_version;

		$result  = 'WooCommerce version: ' . WooCommerce::instance()->version;
		$result .= "\nWordPress version: " . $wp_version;
		$result .= "\ntheme: " . wp_get_theme()->get( 'Name' );
		$result .= "\nbase admin url: " . get_admin_url();
		// WooCommerce only supports MySQL database.
		$result .= "\ndatabase: " . static::DATABASE;
		$result .= "\ndatabase version: " . $wpdb->db_version();
		$result .= "\nplugin version: " . Shop_Helper::get_plugin_version();

		return $result;
	}

	/**
	 * Retrieves logs from WooCommerce.
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * @param string $dir Logs directory path.
	 *
	 * @return string Log file contents.
	 */
	protected static function get_logs( $dir ) {
		$ignore      = array( '.', '..', 'index.html', '.htaccess' );
		$dir_content = scandir( $dir, SCANDIR_SORT_NONE );

		$dir   = rtrim( $dir, '/' ) . '/';
		$start = new DateTime( '-7 days' );
		$start->setTime( 0, 0 );
		$files = array();
		foreach ( $dir_content as $file ) {
			if ( in_array( $file, $ignore, true ) ) {
				continue;
			}

			// only logs from past 7 days.
			$file_time = filemtime( $dir . '/' . $file );
			if ( $file_time >= $start->getTimestamp() ) {
				$files[ $file ] = $file_time;
			}
		}

		asort( $files );
		$result = '';
		foreach ( array_keys( $files ) as $file ) {
			$result .= file_get_contents( $dir . $file ) . "\n";
		}

		return $result;
	}

	/**
	 * Retrieves user info.
	 *
	 * @return string User info.
	 */
	protected static function get_user_info() {
		/**
		 * Configuration service.
		 *
		 * @var Config_Service $config
		 */
		$config = ServiceRegister::getService( Config_Service::CLASS_NAME );

		$result  = 'user info :' . wp_json_encode( $config->getUserInfo() );
		$result .= "\n\napi key: " . $config->getAuthorizationToken();

		return $result;
	}

	/**
	 * Retrieves current queue status.
	 *
	 * @return string Queue status.
	 */
	protected static function get_queue_status() {
		$result = "[\n";
		$items  = array();

		try {
			$repository = RepositoryRegistry::getQueueItemRepository();

			$query = new QueryFilter();
			$query->orWhere( 'status', '=', QueueItem::QUEUED );
			$query->orWhere( 'status', '=', QueueItem::CREATED );
			$query->orWhere( 'status', '=', QueueItem::IN_PROGRESS );
			$query->orWhere( 'status', '=', QueueItem::FAILED );

			$items = $repository->select( $query );
		} catch ( BaseException $e ) {
			/* Just continue with empty result. */
		}

		foreach ( $items as $item ) {
			$result .= wp_json_encode( $item->toArray() ) . ",\n\n";
		}

		return rtrim( $result, ",\n" ) . "\n]";
	}

	/**
	 * Retrieves parcel and warehouse information.
	 *
	 * @return string Parcel and warehouse info.
	 */
	protected static function get_parcel_and_warehouse_info() {
		/**
		 * Configuration service.
		 *
		 * @var Config_Service $config_service
		 */
		$config_service = ServiceRegister::getService( Config_Service::CLASS_NAME );

		$result  = 'default parcel: ' . wp_json_encode( $config_service->getDefaultParcel() ?: array() );
		$result .= "\n\ndefault warehouse: " . wp_json_encode( $config_service->getDefaultWarehouse() ?: array() );

		return $result;
	}


	/**
	 * Retrieves service info.
	 *
	 * @return string Service info.
	 */
	protected static function get_services_info() {
		$result = "[\n";

		try {
			$repository = RepositoryRegistry::getRepository( ShippingMethod::CLASS_NAME );

			foreach ( $repository->select() as $item ) {
				$result .= wp_json_encode( $item->toArray() ) . ",\n\n";
			}
		} catch ( BaseException $e ) {
			/* Just continue with empty result. */
		}

		return rtrim( $result, ",\n" ) . "\n]";
	}
}
