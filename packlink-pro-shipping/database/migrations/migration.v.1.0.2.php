<?php

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\User\UserAccountService;

$api_key = get_option( 'wc_settings_tab_packlink_api_key' );

if ( $api_key ) {
	/** @var UserAccountService $user_service */
	$user_service  = ServiceRegister::getService( UserAccountService::CLASS_NAME );
	$api_key_valid = $user_service->login( $api_key );
}

return array();