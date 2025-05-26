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
use Logeecom\Infrastructure\TaskExecution\Exceptions\QueueItemDeserializationException;
use Packlink\BusinessLogic\Controllers\ManualRefreshController;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
class Packlink_Manual_Refresh_Service_Controller extends Packlink_Base_Controller {

	public function refresh() {
		$controller = new ManualRefreshController();

		$this->return_json($controller->enqueueUpdateTask()->toArray());
	}

	/**
	 * @throws QueueItemDeserializationException
	 * @throws RepositoryClassException
	 * @throws RepositoryNotRegisteredException
	 * @throws QueryFilterInvalidParamException
	 */
	public function get_task_status() {
		$controller = new ManualRefreshController();

		$this->return_json($controller->getTaskStatus()->toArray());
	}
}