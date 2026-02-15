<?php

namespace Packlink\WooCommerce\Components\Services;

use Logeecom\Infrastructure\Configuration\Configuration;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\Interfaces\TaskExecutorInterface;
use Packlink\BusinessLogic\Tasks\DefaultTaskMetadataProvider;
use Packlink\BusinessLogic\Tasks\Interfaces\BusinessTask;
use Packlink\BusinessLogic\Tasks\Interfaces\TaskMetadataProviderInterface;
use Packlink\BusinessLogic\Tasks\TaskExecutionConfig;

class WordPress_Task_Executor implements TaskExecutorInterface
{
	/**
	 * WordPress_Task_Executor constructor.
	 */
	public function __construct()
	{
		// Initialization happens via registerExecutionCallback()
		// called from plugin initialization
	}
	/**
	 * Enqueue business task via Action Scheduler.
	 *
	 * Task will be executed asynchronously via wp-cron or real cron.
	 *
	 * Uses TaskMetadataProvider to get execution configuration.
	 *
	 * @param BusinessTask $businessTask Business task (e.g., SendDraftBusinessTask).
	 *
	 * @return void
	 */
	public function enqueue(BusinessTask $businessTask)
	{
		// Check if Action Scheduler is available
		if (!function_exists('as_enqueue_async_action')) {
			throw new \RuntimeException('Action Scheduler not available. Please install WooCommerce.');
		}

		// Get execution configuration from metadata provider (BusinessLogic layer)
		$executionConfig = $this->getExecutionConfig($businessTask);

		// Enqueue action with configuration
		as_enqueue_async_action(
			'packlink_execute_task',
			[
				[
					'task_data' => $businessTask->toArray(),
					'task_class' => get_class($businessTask),
					'context' => $executionConfig->getContext()
				]
			],
			$executionConfig->getQueueName(),
			false,
			$this->convertPriority($executionConfig->getPriority())
		);
	}

	/**
	 * Schedule delayed business task via Action Scheduler.
	 *
	 * Task will be executed after specified delay.
	 *
	 * Uses TaskMetadataProvider to get execution configuration.
	 *
	 * @param BusinessTask $businessTask Business task.
	 * @param int $delaySeconds Delay in seconds before execution.
	 *
	 * @return void
	 */
	public function scheduleDelayed(BusinessTask $businessTask, int $delaySeconds)
	{
		// Check if Action Scheduler is available
		if (!function_exists('as_schedule_single_action')) {
			throw new \RuntimeException('Action Scheduler not available. Please install WooCommerce.');
		}

		$delaySeconds = max(0, (int)$delaySeconds);
		// Get execution configuration from metadata provider (BusinessLogic layer)
		$executionConfig = $this->getExecutionConfig($businessTask);

		// Schedule action with configuration
		as_schedule_single_action(
			time() + $delaySeconds,
			'packlink_execute_task',
			[
				'task_data' => $businessTask->toArray(),
				'task_class' => get_class($businessTask),
				'context' => $executionConfig->getContext()
			],
			$executionConfig->getQueueName(), // Group from metadata provider
			false,
			$this->convertPriority($executionConfig->getPriority()) // Priority from metadata provider
		);
	}

	/**
	 * Get execution configuration for business task.
	 *
	 * Uses TaskMetadataProvider service (BusinessLogic layer) to get configuration.
	 * Falls back to DefaultTaskMetadataProvider if not registered.
	 *
	 * @param BusinessTask $businessTask Business task.
	 *
	 * @return TaskExecutionConfig Execution configuration.
	 */
	private function getExecutionConfig(BusinessTask $businessTask)
	{
		// Try to get registered metadata provider
		if (ServiceRegister::getService(TaskMetadataProviderInterface::CLASS_NAME)) {
			/** @var TaskMetadataProviderInterface $metadataProvider */
			$metadataProvider = ServiceRegister::getService(TaskMetadataProviderInterface::CLASS_NAME);
		} else {
			// Fallback to default provider (BusinessLogic layer)
			$metadataProvider = new DefaultTaskMetadataProvider(
				ServiceRegister::getService(Configuration::CLASS_NAME),
				ServiceRegister::getService(TaskRunnerConfigInterface::CLASS_NAME)
			);
		}

		return $metadataProvider->getExecutionConfig($businessTask);
	}

	/**
	 * Convert priority from infrastructure scale (0-100) to Action Scheduler scale (0-10).
	 *
	 * Infrastructure priorities:
	 * - Priority::LOW = 0-33
	 * - Priority::NORMAL = 34-66
	 * - Priority::HIGH = 67-100
	 *
	 * Action Scheduler priorities:
	 * - 0 = lowest
	 * - 10 = highest
	 *
	 * @param int $priority Priority from TaskExecutionConfig (0-100).
	 *
	 * @return int Priority for Action Scheduler (0-10).
	 */
	private function convertPriority($priority)
	{
		$priority = (int)$priority;
		$p = (int) round($priority / 10);

		// Clamp to Action Scheduler allowed range
		return max(0, min(10, $p));
	}

	/**
	 * Register Action Scheduler callback for task execution.
	 *
	 * Call this method during plugin initialization to register the callback.
	 *
	 * @return void
	 */
	public function registerExecutionCallback()
	{
		add_action('init', function () {
			$executor = ServiceRegister::getService(TaskExecutorInterface::CLASS_NAME);
			$executor->registerExecutionCallback();
		});
		add_action('packlink_execute_task', [$this, 'executeTaskCallback'], 10, 1);
		add_action('packlink_scheduler', [$this, 'runSc'], 10, 1);

	}

	public function runSc ($args) {
		return $args['task_class'];
	}

	/**
	 * Action Scheduler callback for task execution.
	 *
	 * Deserializes business task and executes it.
	 * Handles yield-based progress tracking.
	 *
	 * @param array $args Task arguments from Action Scheduler.
	 *
	 * @return void
	 *
	 * @throws \Exception If task execution fails.
	 */
	public function executeTaskCallback($args)
	{
		$taskClass = $args['task_class'];
		$taskData = $args['task_data'];
		$context = $args['context'] ?? '';

		// Reconstruct business task
		/** @var BusinessTask $businessTask */
		$businessTask = call_user_func([$taskClass, 'fromArray'], $taskData);

		// Execute task
		$result = $businessTask->execute();

		// Check if task returned a Generator (yield-based progress)
		if ($result instanceof \Generator) {
			$this->executeWithProgressTracking($result);
		}

		// If not generator, task executed normally
	}

	/**
	 * Execute generator with progress tracking.
	 *
	 * Iterates over generator and handles yielded values:
	 * - null (yield;) → log keep-alive
	 * - int/float (yield 50;) → log progress
	 * - string (yield 'message';) → log message
	 *
	 * Note: Action Scheduler doesn't have reportProgress/reportAlive like QueueItem,
	 * so we just log the progress for debugging purposes.
	 *
	 * @param \Generator $generator Generator returned by business task.
	 *
	 * @return void
	 */
	private function executeWithProgressTracking(\Generator $generator)
	{
		foreach ($generator as $value) {
			if ($value === null) {
				// yield; (no value) → keep-alive signal
				error_log('[Packlink] Task keep-alive signal');
			} elseif (is_int($value) || is_float($value)) {
				// yield 50; → report progress 50%
				error_log(sprintf('[Packlink] Task progress: %s%%', $value));
			} elseif (is_string($value)) {
				// yield 'Processing...'; → log message
				error_log(sprintf('[Packlink] Task message: %s', $value));
			}
		}
	}
}