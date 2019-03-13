<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

$controller = new \Packlink\WooCommerce\Controllers\Packlink_Frontend_Controller();
$controller->process( 'render' );