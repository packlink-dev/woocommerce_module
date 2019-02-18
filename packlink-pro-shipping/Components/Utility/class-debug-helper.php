<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Utility;


use Logeecom\Infrastructure\Exceptions\BaseException;
use Logeecom\Infrastructure\ORM\QueryFilter\QueryFilter;
use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\QueueItem;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\WooCommerce\Components\Services\Config_Service;

/**
 * Class Debug_Helper
 * @package Packlink\WooCommerce\Components\Utility
 */
class Debug_Helper {

	const PHP_INFO_FILE_NAME = 'phpinfo.html';
	const SYSTEM_INFO_FILE_NAME = 'system-info.txt';
	const LOG_FILE_NAME = 'logs.txt';
	const USER_INFO_FILE_NAME = 'packlink-user-info.txt';
	const QUEUE_INFO_FILE_NAME = 'queue.txt';
	const PARCEL_WAREHOUSE_FILE_NAME = 'parcel-warehouse.txt';
	const SERVICE_INFO_FILE_NAME = 'services.txt';
	const DATABASE = 'MySQL';

	/**
	 * Returns path to zip archive that contains current system information.
	 *
	 * @return string Temporary file path.
	 */
	public static function get_system_info() {
		$file = tempnam( sys_get_temp_dir(), 'packlink_system_info' );

		$zip = new \ZipArchive();
		$zip->open( $file, \ZipArchive::CREATE );

		$php_info = static::get_php_info();

		if ( $php_info !== false ) {
			$zip->addFromString( static::PHP_INFO_FILE_NAME, $php_info );
		}

		$zip->addFromString( static::SYSTEM_INFO_FILE_NAME, static::get_woocommerce_shop_info() );
		$zip->addFromString( static::LOG_FILE_NAME, static::get_logs() );
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
		global $wpdb;

		$result = 'WooCommerce version: ' . \WooCommerce::instance()->version;
		$result .= "\ntheme: " . \wp_get_theme()->get( 'Name' );
		$result .= "\nbase admin url: " . \get_admin_url();
		// WooCommerce only supports MySQL database.
		$result .= "\ndatabase: " . static::DATABASE;
		$result .= "\ndatabase version: " . $wpdb->db_version();
		$result .= "\nplugin version: " . Shop_Helper::get_plugin_version();

		return $result;
	}

	/**
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * Retrieves logs from WooCommerce.
	 *
	 * @return string
	 */
	protected static function get_logs() {
		$ignore = array( '.', '..', 'index.html', '.htaccess' );
		/** @noinspection PhpUndefinedConstantInspection */
		$dir         = WC_LOG_DIR;
		$dir_content = scandir( $dir, SCANDIR_SORT_NONE );

		/** @noinspection PhpUnhandledExceptionInspection */
		$start = new \DateTime( '-7 days' );
		$start->setTime( 0, 0 );
		$files = array();
		foreach ( $dir_content as $file ) {
			if ( in_array( $file, $ignore, true ) ) {
				continue;
			}

			// only logs from past 7 days
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
	 * @return string
	 */
	protected static function get_user_info() {
		/** @var Config_Service $config */
		$config = ServiceRegister::getService( Config_Service::CLASS_NAME );

		$result = 'user info :' . json_encode( $config->getUserInfo() );
		$result .= "\n\napi key: " . $config->getAuthorizationToken();

		return $result;
	}

	/**
	 * Retrieves current queue status.
	 *
	 * @return string
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
		}

		foreach ( $items as $item ) {
			$result .= json_encode( $item->toArray() ) . ",\n\n";
		}

		return rtrim( $result, ",\n" ) . "\n]";
	}

	/**
	 * Retrieves parcel and warehouse information.
	 *
	 * @return string
	 */
	protected static function get_parcel_and_warehouse_info() {
		/** @var Config_Service $configService */
		$configService = ServiceRegister::getService( Config_Service::CLASS_NAME );

		$result = 'default parcel: ' . json_encode( $configService->getDefaultParcel() ?: array() );
		$result .= "\n\ndefault warehouse: " . json_encode( $configService->getDefaultWarehouse() ?: array() );

		return $result;
	}


	/**
	 * Retrieves service info.
	 *
	 * @return string
	 */
	protected static function get_services_info() {
		$result = "[\n";

		try {
			$repository = RepositoryRegistry::getRepository( ShippingMethod::CLASS_NAME );

			foreach ( $repository->select() as $item ) {
				$result .= json_encode( $item->toArray() ) . ",\n\n";
			}
		} catch ( BaseException $e ) {
		}

		return rtrim( $result, ",\n" ) . "\n]";
	}
}
