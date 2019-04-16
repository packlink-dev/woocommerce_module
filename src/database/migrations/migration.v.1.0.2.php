<?php

use Logeecom\Infrastructure\Logger\Logger;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\Exceptions\QueueStorageUnavailableException;
use Packlink\BusinessLogic\User\UserAccountService;
use Packlink\WooCommerce\Components\Utility\Task_Queue;
use Packlink\WooCommerce\Components\Tasks\Upgrade_Packlink_Order_Details;

try {
	$api_key = get_option( 'wc_settings_tab_packlink_api_key' );
	if ( $api_key ) {
		/** @var UserAccountService $user_service */
		$user_service = ServiceRegister::getService( UserAccountService::CLASS_NAME );
		$user_service->login( $api_key );
		delete_option( 'wc_settings_tab_packlink_api_key' );

		try {
			global $wpdb;

			$order_posts = $wpdb->get_results(
				"SELECT `ID` FROM $wpdb->posts WHERE `post_type` = 'shop_order'",
				ARRAY_A
			);

			Task_Queue::enqueue(new Upgrade_Packlink_Order_Details(array_column($order_posts , 'ID')));
		} catch ( \Exception $e ) {
			Logger::logError( 'Migration of order shipments failed.', 'Integration' );
		}
	}
} catch ( QueueStorageUnavailableException $e ) {
	Logger::logError( 'Migration of users API key failed.', 'Integration' );
}

return array();
