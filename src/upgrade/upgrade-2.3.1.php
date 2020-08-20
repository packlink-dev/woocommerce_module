<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\ShippingMethod\Utility\ShipmentStatus;
use Packlink\WooCommerce\Components\Services\Config_Service;

//@codingStandardsIgnoreStart

/**
 * Configuration service.
 *
 * @var Config_Service $config_service
 */
$config_service = ServiceRegister::getService( Config_Service::CLASS_NAME );

$statuses = $config_service->getOrderStatusMappings();
if ( empty( $statuses[ ShipmentStatus::STATUS_CANCELLED ] ) ) {
	$statuses[ ShipmentStatus::STATUS_CANCELLED ] = 'wc-cancelled';
}

$config_service->setOrderStatusMappings( $statuses );

//@codingStandardsIgnoreEnd