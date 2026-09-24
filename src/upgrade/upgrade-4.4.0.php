<?php

/** @noinspection PhpUnhandledExceptionInspection */

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\ShippingMethod\Utility\ShipmentStatus;
use Packlink\WooCommerce\Components\Services\Config_Service;

// This section will be triggered when upgrading to 4.4.0 or later version of plugin.
// The fix itself dates from 4.3.2 (CS-8423); it is triggered at 4.4.0 so that merchants already
// on 4.3.2 - which shipped without this script - also receive the backfill.

// Backfill order status mappings that were introduced after the merchant installed the plugin.
// The mapping is seeded only once, by Plugin::init_config() at install time, so an install
// created before a key existed never receives it. Shop_Order_Service::updateShipmentStatus()
// treats a missing key as a silent no-op, which leaves the order in its previous status with
// nothing written to the log - the 'incident' key (added in 2021) is the case reported in CS-8423.
//
// Idempotent: only absent keys are added. A key the merchant has deliberately cleared is kept
// as-is, because an empty value is a valid choice meaning "do not change the order status".

/**
 * Configuration service.
 *
 * @var Config_Service $config
 */
$config = ServiceRegister::getService( Config_Service::CLASS_NAME );

$mappings = $config->getOrderStatusMappings();

if ( ! is_array( $mappings ) ) {
	$mappings = array();
}

$defaults = array(
	ShipmentStatus::STATUS_ACCEPTED  => 'wc-processing',
	ShipmentStatus::STATUS_DELIVERED => 'wc-completed',
	ShipmentStatus::STATUS_CANCELLED => 'wc-cancelled',
	ShipmentStatus::INCIDENT         => 'wc-failed',
);

$added = false;

foreach ( $defaults as $shipment_status => $order_status ) {
	if ( ! array_key_exists( $shipment_status, $mappings ) ) {
		$mappings[ $shipment_status ] = $order_status;
		$added                        = true;
	}
}

if ( $added ) {
	$config->setOrderStatusMappings( $mappings );
}
