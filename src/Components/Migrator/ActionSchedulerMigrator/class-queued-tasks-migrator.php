<?php

use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecutor\Interfaces\TaskExecutorInterface;
use Packlink\BusinessLogic\Tasks\BusinessTasks\SendDraftBusinessTask;
use Packlink\BusinessLogic\Tasks\BusinessTasks\UpdateShippingServicesBusinessTask;
use Packlink\WooCommerce\Components\Utility\Database;

class Queued_Tasks_Migrator {
	public function migrateQueuedItems() {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			add_action( 'init', [ $this, 'migrateQueuedItems' ] );
			return;
		}

		global $wpdb;

		/** @var TaskExecutorInterface $taskExecutor */
		$taskExecutor = ServiceRegister::getService( TaskExecutorInterface::class );

		$table_name = $wpdb->prefix . Database::BASE_TABLE;
		$rows       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT index_2, data FROM {$table_name} WHERE type = %s AND index_1 IN (%s, %s)",
				'QueueItem',
				'queued',
				'in_progress'
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$task_type = isset( $row['index_2'] ) ? (string) $row['index_2'] : '';
			if ( $task_type === '' ) {
				continue;
			}

			if ( $this->isTaskType( $task_type, 'SendDraftTask' ) ) {
				$order_id = $this->extractOrderIdFromQueueItemData( $row['data'] ?? null );
				if ( $order_id !== null && $order_id !== '' ) {
					$taskExecutor->enqueue( new SendDraftBusinessTask( (string) $order_id ) );
				}

				continue;
			}

			if ( $this->isTaskType( $task_type, 'UpdateShippingServicesTask' ) ) {
				$taskExecutor->enqueue( new UpdateShippingServicesBusinessTask() );
			}
		}
	}

	/**
	 * Check if task type matches by short name or fully-qualified class name.
	 *
	 * @param string $task_type Task type from queue item.
	 * @param string $short_name Expected short class name.
	 *
	 * @return bool
	 */
	private function isTaskType( $task_type, $short_name ) {
		if ( $task_type === $short_name ) {
			return true;
		}

		return substr( $task_type, - strlen( $short_name ) ) === $short_name;
	}

	/**
	 * Extracts order id from serialized task payload.
	 *
	 * @param string|null $data Queue item data column (JSON).
	 *
	 * @return string|null
	 */
	private function extractOrderIdFromQueueItemData( $data ) {
		if ( empty( $data ) || ! is_string( $data ) ) {
			return null;
		}

		$decoded = json_decode( $data, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$serialized = $decoded['serializedTask'] ?? '';
		if ( empty( $serialized ) || ! is_string( $serialized ) ) {
			return null;
		}

		if ( preg_match( '/"order_id";s:\d+:"([^"]+)"/', $serialized, $matches ) ) {
			return (string) $matches[1];
		}

		if ( preg_match( '/"orderId";s:\d+:"([^"]+)"/', $serialized, $matches ) ) {
			return (string) $matches[1];
		}

		return null;
	}
}
