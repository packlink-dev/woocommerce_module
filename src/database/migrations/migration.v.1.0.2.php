<?php

use Logeecom\Infrastructure\Logger\Logger;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\Exceptions\QueueStorageUnavailableException;
use Packlink\BusinessLogic\Order\Interfaces\OrderRepository;
use Packlink\BusinessLogic\User\UserAccountService;
use Packlink\WooCommerce\Components\Order\Order_Meta_Keys;

try {
	$api_key = get_option( 'wc_settings_tab_packlink_api_key' );
	if ( $api_key ) {
		/** @var UserAccountService $user_service */
		$user_service = ServiceRegister::getService( UserAccountService::CLASS_NAME );
		$user_service->login( $api_key );
		delete_option( 'wc_settings_tab_packlink_api_key' );
	} else {
		return;
	}
} catch ( QueueStorageUnavailableException $e ) {
	Logger::logError( 'Migration of users API key failed.', 'Integration' );
}

try {
	global $wpdb;

	$offset = 0;
	$limit  = 1000;

	/** @var OrderRepository $repository */
	$repository = ServiceRegister::getService( OrderRepository::CLASS_NAME );
	do {
		$order_posts = $wpdb->get_results(
			"SELECT `ID` FROM $wpdb->posts WHERE `post_type` = 'shop_order' LIMIT $offset, $limit",
			ARRAY_A
		);
		if ( empty( $order_posts ) ) {
			break;
		}

		foreach ( $order_posts as $post ) {
			$order     = WC_Order_Factory::get_order( $post['ID'] );
			$reference = get_post_meta( $order->get_id(), '_packlink_draft_reference', true );
			if ( ! $reference ) {
				continue;
			}

			$order->update_meta_data( Order_Meta_Keys::IS_PACKLINK, 'yes' );
			$order->save();

			$repository->setReference( $order->get_id(), $reference );
		}

		$offset += $limit;
	} while ( $limit === count( $order_posts ) );
} catch ( \Exception $e ) {
	Logger::logError( 'Migration of order shipments failed.', 'Integration' );
}

return array();
