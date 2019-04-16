<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Tasks;

use Logeecom\Infrastructure\Exceptions\BaseException;
use Logeecom\Infrastructure\Http\Exceptions\HttpUnhandledException;
use Logeecom\Infrastructure\Logger\Logger;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\Task;
use Logeecom\Infrastructure\Utility\TimeProvider;
use Packlink\BusinessLogic\Http\DTO\Shipment;
use Packlink\BusinessLogic\Http\Proxy;
use Packlink\BusinessLogic\Order\Exceptions\OrderNotFound;
use Packlink\BusinessLogic\Order\Interfaces\OrderRepository;
use Packlink\BusinessLogic\ShippingMethod\Utility\ShipmentStatus;
use Packlink\WooCommerce\Components\Order\Order_Meta_Keys;

/**
 * Class UpgradePacklinkOrderDetails
 *
 * @package Packlink\WooCommerce\Components\Tasks
 */
class Upgrade_Packlink_Order_Details extends Task {

	const INITIAL_PROGRESS_PERCENT = 5;
	const DEFAULT_BATCH_SIZE       = 200;

	/**
	 * Task state context.
	 *
	 * @var array
	 */
	private $state_data;
	/**
	 * Order repository.
	 *
	 * @var OrderRepository
	 */
	private $repository;
	/**
	 * Proxy instance.
	 *
	 * @var Proxy
	 */
	private $proxy;

	/**
	 * UpgradePacklinkOrderDetails constructor.
	 *
	 * @param array $order_ids Order ids.
	 */
	public function __construct( array $order_ids ) {
		/**
		 * Time provider.
		 *
		 * @var TimeProvider $time_provider
		 */
		$time_provider = ServiceRegister::getService( TimeProvider::CLASS_NAME );
		$start_date    = $time_provider->getDateTime( strtotime( '-60 days' ) )->getTimestamp();

		$this->state_data = array(
			'batch_size'       => self::DEFAULT_BATCH_SIZE,
			'all_order_ids'    => $order_ids,
			'number_of_orders' => count( $order_ids ),
			'current_progress' => self::INITIAL_PROGRESS_PERCENT,
			'start_date'       => $start_date,
		);
	}

	/**
	 * String representation of object.
	 *
	 * @link https://php.net/manual/en/serializable.serialize.php
	 *
	 * @return string the string representation of the object or null.
	 * @since 5.1.0
	 */
	public function serialize() {
		return serialize( $this->state_data );
	}

	/**
	 * Constructs the object.
	 *
	 * @param string $serialized Serialized object string.
	 */
	public function unserialize( $serialized ) {
		$this->state_data = unserialize( $serialized );
	}

	/**
	 * Runs task logic.
	 */
	public function execute() {
		$this->reportProgress( $this->state_data['current_progress'] );
		$this->report_progress_when_no_order_ids();

		$count = count( $this->state_data['all_order_ids'] );
		while ( $count > 0 ) {
			$order_ids = $this->get_batch_order_ids();
			$this->reportAlive();

			foreach ( $order_ids as $order_id ) {
				$order     = \WC_Order_Factory::get_order( $order_id );
				$reference = get_post_meta( $order_id, '_packlink_draft_reference', true );
				if ( ! $order || ! $reference ) {
					continue;
				}

				$inactive    = $order->has_status( array( 'completed', 'failed', 'cancelled', 'refunded' ) );
				$modified_at = $order->get_date_modified();

				// Check if older than 60 days, if not fetch shipment details.
				$in_time_limit = $modified_at && $modified_at->getTimestamp() >= $this->state_data['start_date'];
				if ( $in_time_limit && ! $inactive ) {
					try {
						$shipment = $this->get_proxy()->getShipment( $reference );
						if ( $shipment ) {
							$this->set_shipment_details( $order, $shipment );
						}
					} catch ( \Exception $e ) {
						Logger::logError( $e->getMessage(), 'Integration' );
					}
				} else {
					$order->update_meta_data( Order_Meta_Keys::IS_PACKLINK, 'yes' );
					$order->update_meta_data( Order_Meta_Keys::SHIPMENT_REFERENCE, $reference );
				}
			}

			// If batch is successful orders in batch should be removed.
			$this->remove_finished_batch();

			// If upload is successful progress should be reported for that batch.
			$this->report_progress_for_batch();

			$count = count( $this->state_data['all_order_ids'] );
		}

		$this->reportProgress( 100 );
	}

	/**
	 * Reduces batch size.
	 *
	 * @throws HttpUnhandledException Thrown when batch size can't be reduced.
	 */
	public function reconfigure() {
		$batch_size = $this->state_data['batch_size'];
		if ( $batch_size >= 100 ) {
			$this->state_data['batch_size'] -= 50;
		} elseif ( $batch_size > 10 && $batch_size < 100 ) {
			$this->state_data['batch_size'] -= 10;
		} elseif ( $batch_size > 1 && $batch_size <= 10 ) {
			-- $this->state_data['batch_size'];
		} else {
			throw new HttpUnhandledException( 'Batch size can not be smaller than 1' );
		}
	}

	/**
	 * Report progress when there are no orders for sync
	 */
	private function report_progress_when_no_order_ids() {
		if ( count( $this->state_data['all_order_ids'] ) === 0 ) {
			$this->state_data['current_progress'] = 100;
			$this->reportProgress( $this->state_data['current_progress'] );
		}
	}

	/**
	 * Returns array of order ids that should be processed in this batch.
	 *
	 * @return array Batch of order ids.
	 */
	private function get_batch_order_ids() {
		return array_slice( $this->state_data['all_order_ids'], 0, $this->state_data['batch_size'] );
	}

	/**
	 * Remove finished batch orders
	 */
	private function remove_finished_batch() {
		$this->state_data['all_order_ids'] = array_slice(
			$this->state_data['all_order_ids'],
			$this->state_data['batch_size']
		);
	}

	/**
	 * Report progress for batch
	 */
	private function report_progress_for_batch() {
		$synced = $this->state_data['number_of_orders'] - count( $this->state_data['all_order_ids'] );

		$progress_step = $synced * ( 100 - self::INITIAL_PROGRESS_PERCENT ) / $this->state_data['number_of_orders'];

		$this->state_data['current_progress'] = self::INITIAL_PROGRESS_PERCENT + $progress_step;

		$this->reportProgress( $this->state_data['current_progress'] );
	}

	/**
	 * Sets order shipment details.
	 *
	 * @param \WC_Order $order Order object.
	 * @param Shipment  $shipment Shipment details.
	 */
	private function set_shipment_details( \WC_Order $order, Shipment $shipment ) {
		if ( $this->set_reference( $order, $shipment->reference ) ) {
			$this->set_labels( $shipment );
			$this->set_shipping_status( $shipment );
			$this->set_tracking_info( $shipment );
		}
	}

	/**
	 * Sets reference number for order.
	 *
	 * @param \WC_Order $order Order object.
	 * @param string    $reference Shipment reference number.
	 *
	 * @return bool Success flag.
	 */
	private function set_reference( \WC_Order $order, $reference ) {
		try {
			$order->update_meta_data( Order_Meta_Keys::IS_PACKLINK, 'yes' );
			$order->save();

			$this->get_repository()->setReference( $order->get_id(), $reference );
		} catch ( OrderNotFound $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Sets order shipment labels.
	 *
	 * @param Shipment $shipment Shipment details.
	 */
	private function set_labels( Shipment $shipment ) {
		$statuses = array(
			'READY_TO_PRINT',
			'READY_FOR_COLLECTION',
			'IN_TRANSIT',
			'DELIVERED',
		);

		if ( in_array( $shipment->status, $statuses, true ) ) {
			try {
				$labels = $this->get_proxy()->getLabels( $shipment->reference );
				$this->get_repository()->setLabelsByReference( $shipment->reference, $labels );
			} catch ( BaseException $e ) {
				Logger::logError( $e->getMessage(), 'Integration' );
			}
		}
	}

	/**
	 * Sets order shipment status.
	 *
	 * @param Shipment $shipment Shipment details.
	 */
	private function set_shipping_status( Shipment $shipment ) {
		try {
			$shipping_status = ShipmentStatus::getStatus( $shipment->status );
			$this->get_repository()->setShippingStatusByReference( $shipment->reference, $shipping_status );
		} catch ( OrderNotFound $e ) {
			Logger::logError( $e->getMessage(), 'Integration' );
		}
	}

	/**
	 * Sets order shipment tracking info.
	 *
	 * @param Shipment $shipment Shipment details.
	 */
	private function set_tracking_info( Shipment $shipment ) {
		try {
			$tracking_info = $this->get_proxy()->getTrackingInfo( $shipment->reference );
			$this->get_repository()->updateTrackingInfo( $shipment->reference, $tracking_info, $shipment );
		} catch ( \Exception $e ) {
			Logger::logError( $e->getMessage(), 'Integration' );
		}
	}

	/**
	 * Returns order repository instance.
	 *
	 * @return OrderRepository Order repository.
	 */
	private function get_repository() {
		if ( ! $this->repository ) {
			$this->repository = ServiceRegister::getService( OrderRepository::CLASS_NAME );
		}

		return $this->repository;
	}

	/**
	 * Returns proxy instance.
	 *
	 * @return Proxy Proxy instance.
	 */
	private function get_proxy() {
		if ( ! $this->proxy ) {
			$this->proxy = ServiceRegister::getService( Proxy::CLASS_NAME );
		}

		return $this->proxy;
	}
}
