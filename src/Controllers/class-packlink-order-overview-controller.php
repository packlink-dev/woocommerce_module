<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

use iio\libmergepdf\Merger;
use Logeecom\Infrastructure\Logger\Logger;
use Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException;
use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\Http\DTO\ShipmentLabel;
use Packlink\BusinessLogic\Order\Interfaces\OrderRepository;
use Packlink\BusinessLogic\Order\OrderService;
use Packlink\WooCommerce\Components\Order\Order_Details_Helper;
use Packlink\WooCommerce\Components\Order\Order_Meta_Keys;
use Packlink\WooCommerce\Components\Order\Order_Repository;
use Packlink\WooCommerce\Components\Utility\Script_Loader;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;
use WC_Order;

/**
 * Class Packlink_Order_Overview_Controller
 *
 * @package Packlink\WooCommerce\Components\Order
 */
class Packlink_Order_Overview_Controller extends Packlink_Base_Controller {

	const COLUMN_ID          = 'packlink_print_label';
	const COLUMN_PACKLINK_ID = 'packlink_column';
	const BULK_ACTION_ID     = 'packlink_print_labels';
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
	public function add_packlink_order_columns( $columns ) {
		$result = array();

		foreach ( $columns as $key => $value ) {
			$result[ $key ] = $value;
			if ( 'order_date' === $key ) {
				$result[ static::COLUMN_PACKLINK_ID ] = __( 'Packlink PRO Shipping', 'packlink-pro-shipping' );
			}
		}

		$result[ static::COLUMN_ID ] = __( 'Packlink label', 'packlink-pro-shipping' );

		return $result;
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
	 *
	 * @throws QueryFilterInvalidParamException When invalid filter parameters are set.
	 */
	public function populate_packlink_column( $column ) {
		global $post;

		if ( static::COLUMN_ID === $column && Order_Details_Helper::is_packlink_order( $post ) ) {
			$status = Order_Details_Helper::get_label_status( $post );

			if ( ! $status['available'] ) {
				echo esc_html( __( 'Label is not yet available.', 'packlink-pro-shipping' ) );
			} else {
				$class     = 'pl-print-label button ' . ( $status['printed'] ? '' : 'button-primary' );
				$label     = $status['printed'] ? __( 'Printed label', 'packlink-pro-shipping' ) : __( 'Print label', 'packlink-pro-shipping' );

				if ( empty( $status['labels'] ) ) {
					$params = array(
						'order_id' => $post->ID
					);

					$label_url = Shop_Helper::get_controller_url( 'Order_Overview', 'print_single_label', $params );
				} else {
					$label_url = $status['labels'][0];
				}

				echo '<button data-pl-id="' . esc_attr( $post->ID ) . '" data-pl-label="' . esc_url( $label_url )
				     . '" type="button" class="' . esc_attr( $class ) . '" >' . esc_html( $label ) . '</button>';
				if ( ! static::$url_added ) {
					$url = Shop_Helper::get_controller_url( 'Order_Overview', 'mark_label_printed' );
					echo '<input type="hidden" name="packlink-url-callback" value="' . esc_url( $url ) . '">';
					static::$url_added = true;
				}
			}
		}

		if ( static::COLUMN_PACKLINK_ID === $column && Order_Details_Helper::is_packlink_order( $post, true ) ) {
			/**
			 * Repository.
			 *
			 * @var Order_Repository $repository
			 */
			$repository = ServiceRegister::getService( OrderRepository::CLASS_NAME );
			$src        = Shop_Helper::get_plugin_base_url() . 'resources/images/logo.png';
			$reference  = get_post_meta( $post->ID, Order_Meta_Keys::SHIPMENT_REFERENCE, true );
			if ( ! $repository->isShipmentDeleted( $reference ) ) {
				$country = Shop_Helper::get_country_code();
				$url     = "https://pro.packlink.{$country}/private/shipments/{$reference}";
				echo '<a class="pl-image-link" target="_blank" href="' . esc_url( $url ) . '"><img src="' . esc_url( $src ) . '" alt=""></a>';
			} else {
				echo '<div class="pl-image-link"><img src="' . esc_url( $src ) . '" alt=""></div>';
			}
		}
	}

	/**
	 * Prints single label.
	 */
	public function print_single_label() {
		$this->validate( 'no', true );

		$order_id = !empty( $_GET['order_id'] ) ? $_GET['order_id'] : null;

		if ( !$order_id ) {
			echo esc_html( __( 'Label is not yet available.', 'packlink-pro-shipping' ) );
			exit;
		}

		$order = \WC_Order_Factory::get_order( ( int ) $order_id );
		if ( !$order ) {
			echo esc_html( __( 'Label is not yet available.', 'packlink-pro-shipping' ) );
			exit;
		}

		$links = $this->get_print_labels( $order );

		if ( !empty( $links ) ) {
			header( 'Location: ' . $links[0], 302 );
			exit;
		}

		echo esc_html( __( 'Label is not yet available.', 'packlink-pro-shipping' ) );
		exit;
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
	 *
	 * @param string $redirect_to URL to redirect to.
	 * @param string $action Action name.
	 * @param array  $ids List of ids.
	 *
	 * @return string
	 */
	public function bulk_print_labels( $redirect_to, $action, $ids ) {
		if ( self::BULK_ACTION_ID !== $action ) {
			return esc_url_raw( $redirect_to );
		}

		$ids    = apply_filters( 'woocommerce_bulk_action_ids', array_reverse( array_map( 'absint', $ids ) ), $action, 'order' );
		$labels = array();
		foreach ( $ids as $order_id ) {
			$order = \WC_Order_Factory::get_order( $order_id );
			if ( $order && $order->meta_exists( Order_Meta_Keys::IS_PACKLINK ) ) {
				$labels[] = $this->get_print_labels( $order );
			}
		}

		$labels = call_user_func_array( 'array_merge', $labels );
		if ( ! empty( $labels ) ) {
			$this->merge_labels( $labels );
			$this->set_download_cookie();
			exit;
		}

		$redirect_to = add_query_arg(
			array(
				'post_type'   => 'shop_order',
				'bulk_action' => self::BULK_ACTION_ID,
				'changed'     => 0,
				'ids'         => implode( ',', $ids ),
			),
			$redirect_to
		);

		return esc_url_raw( $redirect_to );
	}

	/**
	 * Loads javascript resources on order overview page.
	 */
	public function load_scripts() {
		global $post;

		if ( $post && 'shop_order' === $post->post_type && 'raw' === $post->filter ) {
			Script_Loader::load_js(
				array(
					'js/core/packlink-ajax-service.js',
					'js/packlink-order-overview.js',
				)
			);
			Script_Loader::load_css( array( 'css/packlink-order-overview.css' ) );
		}
	}

	/**
	 * Merges shipment labels and sets merged pdf file for download.
	 *
	 * @param array $labels Array of shipment labels.
	 */
	protected function merge_labels( array $labels ) {
		try {
			$paths = array();
			foreach ( $labels as $index => $label ) {
				if ( $path = $this->download_pdf( $label ) ) {
					$paths[] = $path;
				}
			}

			if ( ! empty( $paths ) ) {
				$merger = new Merger();
				foreach ( $paths as $path ) {
					$merger->addFromFile( $path );
				}

				$file = $merger->merge();
				if ( $file ) {
					$this->return_file( $file );
				}
			}
		} catch ( \Exception $e ) {
			Logger::logError(
				__( 'Unable to create bulk labels file', 'packlink-pro-shipping' ),
				'Integration',
				array( 'labels' => $labels )
			);
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
		$status = $order->get_meta( Order_Meta_Keys::SHIPMENT_STATUS );

		/** @var OrderService $order_service */
		$order_service = ServiceRegister::getService( OrderService::CLASS_NAME );
		if ( ! $order_service->isReadyToFetchShipmentLabels( $status ) ) {
			return array();
		}

		$labels = $order->get_meta( Order_Meta_Keys::LABELS );

		if ( empty( $labels ) ) {
			$reference = $order->get_meta( Order_Meta_Keys::SHIPMENT_REFERENCE );
			$labels = $order_service->getShipmentLabels( $reference );
			$labels = array_map( function ( ShipmentLabel $label ) {
				return $label->getLink();
			}, $labels );
			$order->update_meta_data( Order_Meta_Keys::LABELS, $labels );
		}

		$order->update_meta_data( Order_Meta_Keys::LABEL_PRINTED, 'yes' );
		$order->save();

		return ! empty( $labels ) ? $labels : array();
	}

	/**
	 * Sets the cookie to indicate that the file is downloaded.
	 */
	private function set_download_cookie() {
		$token = $this->get_param( 'packlink_download_token' );
		setcookie( 'packlink_download_token', $token, time() + 3600, '/' );
	}

	/**
	 * Prints file to output and sets download headers.
	 *
	 * @param string $file File path.
	 */
	private function return_file( $file ) {
		$now  = date( 'Y-m-d' );
		$name = "Packlink-bulk-shipping-labels_$now.pdf";

		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . strlen( $file ) );
		header( 'Content-disposition: attachment; filename=' . $name );
		header( 'Cache-Control: public, must-revalidate, max-age=0' );
		header( 'Pragma: public' );
		header( 'Expires: Sat, 26 Jul 1997 05:00:00 GMT' );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s' ) . ' GMT' );

		echo $file;
	}

	/**
	 * Downloads pdf.
	 *
	 * @param string $link
	 *
	 * @return bool | string
	 */
	protected function download_pdf( $link )
	{
		if ( ( $data = file_get_contents( $link ) ) === false ) {
			return $data;
		}

		$file = tempnam( sys_get_temp_dir(), 'packlink_pdf' );
		file_put_contents( $file, $data );

		return $file;
	}
}
