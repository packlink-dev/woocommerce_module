<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Services;

use Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException;
use Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException;
use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\Exceptions\QueueStorageUnavailableException;
use MailPoetVendor\Doctrine\DBAL\Driver\PDO\Exception;
use Packlink\BusinessLogic\OrderShipmentDetails\Models\OrderShipmentDetails;
use Packlink\BusinessLogic\OrderShipmentDetails\OrderShipmentDetailsService;
use Packlink\BusinessLogic\ShipmentDraft\Exceptions\DraftTaskMapExists;
use Packlink\BusinessLogic\ShipmentDraft\Exceptions\DraftTaskMapNotFound;
use Packlink\BusinessLogic\ShipmentDraft\ShipmentDraftService;
use Packlink\BusinessLogic\Tasks\SendDraftTask;

/**
 * Class Shipment_Draft_Service
 *
 * @package Packlink\WooCommerce\Components\Services
 */
class Shipment_Draft_Service extends ShipmentDraftService {


	/**
	 * If manual sync is enabled, executes task for creating shipment draft for provided order id
	 * and displays success or error message,
	 * otherwise it enqueues the task for creating shipment draft for provided order id
	 * and ensures proper mapping between the order and the created task are persisted.
	 *
	 * @param string $order_id Shop order id.
	 * @param bool   $is_delayed Indicates if the execution of the task should be delayed.
	 * @param int    $delay_interval Interval in minutes to delay the execution.
	 *
	 * @return void
	 * @throws \RuntimeException | QueryFilterInvalidParamException | DraftTaskMapNotFound | DraftTaskMapExists |  QueueStorageUnavailableException | RepositoryNotRegisteredException  Exceptions that can be thrown in method.
	 */
	public function enqueueCreateShipmentDraftTask( $order_id, $is_delayed = false, $delay_interval = 5 ) {
		delete_transient( 'packlink-pro-success-messages' );
		delete_transient( 'packlink-pro-error-messages' );

		if ( ! $this->get_config_service()->is_manual_sync_enabled() ) {
			parent::enqueueCreateShipmentDraftTask( $order_id, $is_delayed, $delay_interval );
		} else {
			try {
				if ( $this->is_draft_created( $order_id ) ) {
					throw new \RuntimeException( 'Draft already exists' );
				}

				( new SendDraftTask( $order_id ) )->execute();
				/* translators: %s: search term */
				$translation = __(
					'Shipment draft for order %s created successfully',
					'packlink-pro-shipping'
				);
				$text        = sprintf( $translation, $order_id );
				set_transient( 'packlink-pro-success-messages', $text, 30 );
			} catch ( \Exception $e ) {
				/* translators: %s: search term */
				$translation = __(
					'Previous attempt to create a draft failed. Error: %s',
					'packlink-pro-shipping'
				);
				$text        = sprintf( $translation, $e->getMessage() );
				set_transient( 'packlink-pro-error-messages', $text, 30 );
			}
		}
	}

	/**
	 * Retrieves config service.
	 *
	 * @return Config_Service Configuration service.
	 */
	protected function get_config_service() {
		/** Configuration service instance @var Config_Service $config_service  */
		$config_service = ServiceRegister::getService( Config_Service::CLASS_NAME );

		return $config_service;
	}

	/**
	 * Checks whether draft has already been created for a particular order.
	 *
	 * @param string $order_id Order id in an integrated system.
	 *
	 * @return boolean Returns TRUE if draft has been created; FALSE otherwise.
	 */
	private function is_draft_created( $order_id ) {
		$shipment_details = $this->get_order_shipment_details_service()->getDetailsByOrderId( $order_id );

		if ( null === $shipment_details ) {
			return false;
		}

		$reference = $shipment_details->getReference();

		return ! empty( $reference );
	}

	/**
	 * Retrieves order-shipment details service.
	 *
	 * @return OrderShipmentDetailsService Service instance.
	 */
	private function get_order_shipment_details_service() {
		/** OrderShipmentService instance @var OrderShipmentDetailsService $order_shipment_details_service */
		$order_shipment_details_service = ServiceRegister::getService( OrderShipmentDetailsService::CLASS_NAME );

		return $order_shipment_details_service;
	}
}
