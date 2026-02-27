<?php

/** @noinspection PhpUnhandledExceptionInspection */

use Packlink\WooCommerce\Components\Migrator\ActionSchedulerMigrator\Queued_Tasks_Migrator;

// This section will be triggered when upgrading to 4.0.0 or later version of plugin.

$queued_tasks_migrator = new Queued_Tasks_Migrator();

// STEP 2.2. Migrate draft statuses based on legacy queue data.
$queued_tasks_migrator->migrateDraftStatuses();

// STEP 2. Schedule weekly update shipping services business task.
$queued_tasks_migrator->scheduleWeeklyUpdateShippingServices();

// STEP 2.1. Migrate queued items into Action Scheduler if available.
$queued_tasks_migrator->migrateQueuedItems();


// STEP 3. Cleanup obsolete TaskRunner/Queue-related entities and outdated mappings.
$queued_tasks_migrator->cleanupLegacyQueueData();
