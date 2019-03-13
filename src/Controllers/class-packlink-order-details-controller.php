<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Packlink\BusinessLogic\Tasks\SendDraftTask;
use Packlink\WooCommerce\Components\Order\Order_Details_Helper;
use Packlink\WooCommerce\Components\Order\Order_Meta_Keys;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;
use Packlink\WooCommerce\Components\Utility\Task_Queue;
use WP_Post;

/**
 * Class Packlink_Order_Detail
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Order_Details_Controller extends Packlink_Base_Controller {
	/**
	 * Packlink_Order_Details_Controller constructor.
	 */
	public function __construct() {
		$this->load_css();
		$this->load_js();
	}

	/**
	 * Renders Packlink PRO Shipping post box content.
	 *
	 * @param WP_Post $wp_post WordPress post object.
	 */
	public function render( WP_Post $wp_post ) {
		/** @noinspection PhpUnusedLocalVariableInspection */
		$order_details = Order_Details_Helper::get_order_details( $wp_post );

		include dirname( __DIR__ ) . '/resources/views/meta-post-box.php';
	}

	/**
	 * Forces create of shipment draft for order.
	 */
	public function create_draft() {
		$this->validate( 'yes' );
		$raw     = $this->get_raw_input();
		$payload = json_decode( $raw, true );
		if ( ! array_key_exists( 'id', $payload ) ) {
			$this->return_json( array( 'success' => false ), 400 );
		}

		$order = \WC_Order_Factory::get_order( $payload['id'] );
		if ( ! $order || $order->meta_exists( Order_Meta_Keys::SHIPMENT_REFERENCE ) ) {
			$this->return_json( array( 'success' => false ), 400 );
		}

		/** @noinspection PhpUnhandledExceptionInspection */
		$task_id = Task_Queue::enqueue( new SendDraftTask( $order->get_id() ) );
		$order->update_meta_data( Order_Meta_Keys::IS_PACKLINK, 'yes' );
		$order->update_meta_data( Order_Meta_Keys::SEND_DRAFT_TASK_ID, $task_id );
		$order->save();

		$this->return_json( array( 'success' => true ) );
	}

	/**
	 * Loads CSS for the current page.
	 */
	private function load_css() {
		$base_url = Shop_Helper::get_plugin_base_url() . 'resources/';
		wp_enqueue_style(
			'packlink-global-styles',
			$base_url . 'css/packlink-order-details.css',
			array(),
			1
		);
	}

	/**
	 * Loads javascript resources on order details page.
	 */
	private function load_js() {
		$base_url = Shop_Helper::get_plugin_base_url() . 'resources/';
		wp_enqueue_script(
			'packlink_ajax',
			esc_url( $base_url . 'js/core/packlink-ajax-service.js' ),
			array(),
			1
		);
		wp_enqueue_script(
			'packlink_order_details',
			esc_url( $base_url . 'js/packlink-order-details.js' ),
			array(),
			1
		);
	}
}
