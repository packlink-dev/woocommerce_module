<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Services;

use Logeecom\Infrastructure\Logger\Interfaces\LoggerAdapter;
use Logeecom\Infrastructure\Logger\LogData;
use Logeecom\Infrastructure\Logger\Logger;
use Logeecom\Infrastructure\Logger\LoggerConfiguration;
use Logeecom\Infrastructure\Singleton;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;

/**
 * Class Logger_Service
 * @package Packlink\WooCommerce\Components\Services
 */
class Logger_Service extends Singleton implements LoggerAdapter {

	const LOG_DEF = "[%s][%d][%s] %s\n";
	const CONTEXT_DEF = "\tContext[%s]: %s\n";

	/**
	 * Singleton instance of this class.
	 *
	 * @var static
	 */
	protected static $instance;

	/**
	 * Log message in system
	 *
	 * @param LogData $data
	 */
	public function logMessage( LogData $data ) {
		/** @var LoggerConfiguration $config_service */
		$config_service = LoggerConfiguration::getInstance();
		$min_log_level  = $config_service->getMinLogLevel();
		$log_level      = $data->getLogLevel();
		if ( ! Shop_Helper::is_woocommerce_active() ) {
			return;
		}

		// min log level is actually max log level.
		if ( $log_level > $min_log_level ) {
			return;
		}

		$logger = wc_get_logger();
		$level  = 'info';
		switch ( $log_level ) {
			case Logger::ERROR:
				$level = 'error';
				break;
			case Logger::WARNING:
				$level = 'warning';
				break;
			case Logger::DEBUG:
				$level = 'debug';
				break;
		}

		$message = sprintf( static::LOG_DEF, $level, $data->getTimestamp(), $data->getComponent(), $data->getMessage() );
		foreach ( $data->getContext() as $item ) {
			$message .= sprintf( static::CONTEXT_DEF, $item->getName(), $item->getValue() );
		}

		$context = array( 'source' => 'packlink' );
		$logger->log( $level, $message, $context );
	}
}
