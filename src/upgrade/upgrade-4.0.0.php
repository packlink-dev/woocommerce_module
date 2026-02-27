<?php

/** @noinspection PhpUnhandledExceptionInspection */

use Logeecom\Infrastructure\TaskExecution\QueueItem;
use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\Scheduler\DTO\ScheduleConfig;
use Packlink\BusinessLogic\Scheduler\Interfaces\SchedulerInterface;
use Packlink\BusinessLogic\ShipmentDraft\Objects\ShipmentDraftStatus;
use Packlink\BusinessLogic\Tasks\BusinessTasks\UpdateShippingServicesBusinessTask;
use Packlink\WooCommerce\Components\Utility\Database;

// This section will be triggered when upgrading to 4.0.0 or later version of plugin.

global $wpdb;

$table_name = $wpdb->prefix . Database::BASE_TABLE;

$order_rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT id, data FROM {$table_name} WHERE type = %s",
		'OrderShipmentDetails'
	),
	ARRAY_A
);

foreach ( $order_rows as $row ) {
	if ( empty( $row['data'] ) ) {
		continue;
	}

	$data = json_decode( $row['data'], true );
	if ( ! is_array( $data ) ) {
		continue;
	}

	$has_draft_status = array_key_exists( 'draftStatus', $data ) && $data['draftStatus'] !== null && $data['draftStatus'] !== '';
	if ( $has_draft_status ) {
		continue;
	}

	$order_id  = $data['orderId'] ?? null;
	$reference = $data['reference'] ?? null;
	if ( empty( $order_id ) ) {
		continue;
	}

	$draft_status = null;

	if ( ! empty( $reference ) ) {
		$map_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT data FROM {$table_name} WHERE type = %s AND index_1 = %s LIMIT 1",
				'OrderSendDraftTaskMap',
				(string) $order_id
			),
			ARRAY_A
		);

		if ( empty( $map_row ) || empty( $map_row['data'] ) ) {
			$draft_status = ShipmentDraftStatus::NOT_QUEUED;
		} else {
			$map_data = json_decode( $map_row['data'], true );
			$execution_id = is_array( $map_data ) ? ( $map_data['executionId'] ?? null ) : null;

			if ( empty( $execution_id ) ) {
				$draft_status = ShipmentDraftStatus::DELAYED;
			} else {
				$queue_row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT data FROM {$table_name} WHERE type = %s AND id = %d LIMIT 1",
						'QueueItem',
						(int) $execution_id
					),
					ARRAY_A
				);

				if ( ! empty( $queue_row ) && ! empty( $queue_row['data'] ) ) {
					$queue_data = json_decode( $queue_row['data'], true );
					$draft_status = is_array( $queue_data ) && ! empty( $queue_data['status'] )
						? $queue_data['status']
						: QueueItem::FAILED;
				} else {
					$draft_status = QueueItem::FAILED;
				}
			}
		}
	} else {
		// No reference and no status assigned; do not enqueue, mark as not queued.
		$draft_status = ShipmentDraftStatus::NOT_QUEUED;
	}

	if ( $draft_status === null ) {
		continue;
	}

	$data['draftStatus'] = $draft_status;

	$wpdb->update(
		$table_name,
		array( 'data' => wp_json_encode( $data ) ),
		array( 'id' => (int) $row['id'] )
	);
}

// STEP 2. Schedule weekly update shipping services business task.
/** @var SchedulerInterface $scheduler */
$scheduler = ServiceRegister::getService( SchedulerInterface::CLASS_NAME );
$schedule_config = new ScheduleConfig(
	rand( 1, 7 ),
	rand( 0, 5 ),
	rand( 0, 59 ),
	true
);
$scheduler->scheduleWeekly( new UpdateShippingServicesBusinessTask(), $schedule_config );

// STEP 3. Cleanup obsolete TaskRunner/Queue-related entities and outdated mappings.
$types_to_delete = array(
	'OrderSendDraftTaskMap',
	'Process',
	'QueueItem',
);
$type_placeholders = implode( ',', array_fill( 0, count( $types_to_delete ), '%s' ) );
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$table_name} WHERE type IN ({$type_placeholders})",
		$types_to_delete
	)
);

$task_runner_config_keys = array(
	'taskRunnerStatus',
	'taskRunnerWakeupDelay',
	'taskRunnerMaxAliveTime',
	'maxStartedTasksLimit',
	'maxTaskExecutionRetries',
	'maxTaskInactivityPeriod',
	'asyncStarterBatchSize',
);
$config_placeholders = implode( ',', array_fill( 0, count( $task_runner_config_keys ), '%s' ) );
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$table_name} WHERE type = %s AND index_1 IN ({$config_placeholders})",
		array_merge( array( 'Configuration' ), $task_runner_config_keys )
	)
);
