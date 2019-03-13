<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

use Logeecom\Infrastructure\Logger\Logger;
use Packlink\BusinessLogic\Utility\PdfMerge;
use Packlink\WooCommerce\Components\Order\Order_Details_Helper;
use Packlink\WooCommerce\Components\Order\Order_Meta_Keys;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;
use WC_Order;

/**
 * Class Packlink_Order_Overview_Controller
 * @package Packlink\WooCommerce\Components\Order
 */
class Packlink_Order_Overview_Controller extends Packlink_Base_Controller {

	const COLUMN_ID = 'packlink_print_label';
	const BULK_ACTION_ID = 'packlink_print_labels';
	/**
	 * Flag if hidden endpoint url is printed.
	 *
	 * @var bool
	 */
	private static $url_added = false;

	/**
	 * Adds Packlink column for printing label.
	 *
	 * @param array $columns Columns.
	 *
	 * @return array Columns.
	 */
	public function add_packlink_order_column( $columns ) {
		$columns[ static::COLUMN_ID ] = __( 'Packlink label', 'packlink-pro-shipping' );

		return $columns;
	}

	/**
	 * Adds Packlink bulk action for printing labels.
	 *
	 * @param array $bulk_actions Bulk actions.
	 *
	 * @return array Bulk actions.
	 */
	public function add_packlink_bulk_action( $bulk_actions ) {
		$bulk_actions[ static::BULK_ACTION_ID ] = __( 'Print labels', 'packlink-pro-shipping' );

		return $bulk_actions;
	}

	/**
	 * Populates column with print label buttons.
	 *
	 * @param string $column Column.
	 */
	public function populate_packlink_column( $column ) {
		global $post;

		if ( static::COLUMN_ID === $column && Order_Details_Helper::is_packlink_order( $post ) ) {
			$status = Order_Details_Helper::get_label_status( $post );

			if ( ! $status['available'] ) {
				echo __( 'Label is not yet available.', 'packlink-pro-shipping' );
			} else {
				$class     = 'pl-print-label button ' . ( $status['printed'] ? '' : 'button-primary' );
				$label     = $status['printed'] ? __( 'Printed label', 'packlink-pro-shipping' ) : __( 'Print label', 'packlink-pro-shipping' );
				$label_url = $status['labels'][0];

				echo "<button data-pl-id=\"{$post->ID}\" data-pl-label=\"{$label_url}\" type=\"button\" class=\"$class\" >$label</button>";
				if ( ! static::$url_added ) {
					$url = Shop_Helper::get_controller_url( 'Order_Overview', 'mark_label_printed' );
					echo "<input type='hidden' name='packlink-url-callback' value='{$url}'>";
					static::$url_added = true;
				}
			}
		}
	}

	/**
	 * Marks shipment label as printed.
	 */
	public function mark_label_printed() {
		$this->validate( 'yes' );
		$raw     = $this->get_raw_input();
		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) || ! array_key_exists( 'id', $payload ) ) {
			$this->return_json( array( 'success' => false ), 400 );
		}

		$order = \WC_Order_Factory::get_order( $payload['id'] );
		if ( ! $order ) {
			$this->return_json( array( 'success' => false ), 400 );
		}

		$labels = $this->get_print_labels( $order );

		$this->return_json( array( 'success' => ! empty( $labels ) ), empty( $labels ) ? 400 : 200 );
	}

	/**
	 * Handles bulk printing of labels.
	 */
	public function bulk_print_labels() {
		// if an array with order IDs is not presented, exit the function
		if ( ! isset( $_REQUEST['post'] ) && ! is_array( $_REQUEST['post'] ) ) {
			return;
		}

		$labels = array();
		foreach ( $_REQUEST['post'] as $order_id ) {
			$order = \WC_Order_Factory::get_order( $order_id );
			if ( $order && $order->meta_exists( Order_Meta_Keys::IS_PACKLINK ) ) {
				/** @noinspection SlowArrayOperationsInLoopInspection */
				$labels = array_merge( $labels, $this->get_print_labels( $order ) );
			}
		}

		$this->merge_labels( $labels );
		$this->setDownloadCookie();

		exit;
	}

	/**
	 * Loads javascript resources on order overview page.
	 */
	public function load_scripts() {
		global $post;

		if ( $post && 'shop_order' === $post->post_type && 'raw' === $post->filter ) {
			$base_url = Shop_Helper::get_plugin_base_url() . 'resources/';
			wp_enqueue_script(
				'packlink_ajax',
				esc_url( $base_url . 'js/core/packlink-ajax-service.js' ),
				array(),
				1
			);
			wp_enqueue_script(
				'packlink_order_overview',
				esc_url( $base_url . 'js/packlink-order-overview.js' ),
				array(),
				1
			);
		}
	}

	/**
	 * Merges shipment labels and sets merged pdf file for download.
	 *
	 * @param array $labels Array of shipment labels.
	 */
	protected function merge_labels( array $labels ) {
		try {
			$upload_dir = wp_get_upload_dir();
			$path       = $upload_dir['path'];
			$paths      = array();
			foreach ( $labels as $index => $label ) {
				$realpath = realpath( "$path/$index.pdf" );
				file_put_contents( $realpath, fopen( $label, 'rb' ) );
				$paths[] = $realpath;
			}

			$file = PdfMerge::merge( $paths );
			if ( $file ) {
				$this->return_file( $file );
			}

			$this->delete_local_files( $paths );
		} catch ( \Exception $e ) {
			Logger::logError( __( 'Unable to create bulk labels file', 'packlink-pro-shipping' ), 'Integration', array( 'labels' => $labels ) );
		}
	}

	/**
	 * Returns order labels and marks them as printed.
	 *
	 * @param WC_Order $order WooCommerce order.
	 *
	 * @return string[] Label paths.
	 */
	private function get_print_labels( WC_Order $order ) {
		$labels = $order->get_meta( Order_Meta_Keys::LABELS );
		if ( ! empty( $labels ) ) {
			$order->update_meta_data( Order_Meta_Keys::LABEL_PRINTED, 'yes' );
			$order->save();
		}

		return ! empty( $labels ) ? $labels : array();
	}

	/**
	 * Sets the cookie to indicate that the file is downloaded.
	 */
	private function setDownloadCookie() {
		$token = $this->get_param( 'packlink_download_token' );
		setcookie( 'packlink_download_token', $token, time() + 3600, '/', $_SERVER['HTTP_HOST'] );
	}

	/**
	 * Removes local pdf files.
	 *
	 * @param string[] $paths Array of file paths.
	 */
	private function delete_local_files( array $paths ) {
		foreach ( $paths as $path ) {
			unlink( $path );
		}
	}

	/**
	 * Prints file to output and sets download headers.
	 *
	 * @param string $file File path.
	 */
	private function return_file( $file ) {
		header( 'Content-type:application/pdf' );

		$now  = date( 'Y-m-d' );
		$name = sys_get_temp_dir() . "/Packlink-bulk-shipment-label_$now.pdf";
		header( "Content-Disposition:attachment;filename='$name'" );

		readfile( $file );
	}
}
