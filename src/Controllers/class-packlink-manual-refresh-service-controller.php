<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

use Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException;
use Logeecom\Infrastructure\ORM\Exceptions\RepositoryClassException;
use Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\Exceptions\QueueItemDeserializationException;
use Logeecom\Infrastructure\TaskExecution\Interfaces\TaskExecutorInterface;
use Logeecom\Infrastructure\TaskExecution\Interfaces\TaskStatusProviderInterface;
use Packlink\BusinessLogic\Controllers\ManualRefreshController;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
class Packlink_Manual_Refresh_Service_Controller extends Packlink_Base_Controller {

    /**
     * @var ManualRefreshController $manual_refresh_controller
     */
    private $manual_refresh_controller;

    public function __construct(

    ) {
		/**@var TaskExecutorInterface $executor */
        $executor = ServiceRegister::getService(TaskExecutorInterface::CLASS_NAME);

	    /**
	     * @var TaskStatusProviderInterface $statusProvider
	     */
        $statusProvider = ServiceRegister::getService(TaskStatusProviderInterface::CLASS_NAME);

        $this->manual_refresh_controller = new ManualRefreshController($executor, $statusProvider);
    }

	public function refresh() {

		$this->return_json($this->manual_refresh_controller->enqueueUpdateTask()->toArray());
	}

	/**
	 * @throws QueueItemDeserializationException
	 * @throws RepositoryClassException
	 * @throws RepositoryNotRegisteredException
	 * @throws QueryFilterInvalidParamException
	 */
	public function get_task_status() {
		$this->return_json($this->manual_refresh_controller->getTaskStatus()->toArray());
	}
}