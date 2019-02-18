<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components;

use Logeecom\Infrastructure\Configuration\ConfigEntity;
use Logeecom\Infrastructure\Configuration\Configuration;
use Logeecom\Infrastructure\Http\CurlHttpClient;
use Logeecom\Infrastructure\Http\HttpClient;
use Logeecom\Infrastructure\Logger\Interfaces\ShopLoggerAdapter;
use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\Process;
use Logeecom\Infrastructure\TaskExecution\QueueItem;
use Packlink\BusinessLogic\Order\Interfaces\OrderRepository;
use Packlink\BusinessLogic\Scheduler\Models\Schedule;
use Packlink\BusinessLogic\ShippingMethod\Interfaces\ShopShippingMethodService;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\WooCommerce\Components\Order\Order_Repository;
use Packlink\WooCommerce\Components\Order\Order_Shipment_Entity;
use Packlink\WooCommerce\Components\Repositories\Base_Repository;
use Packlink\WooCommerce\Components\Repositories\Queue_Item_Repository;
use Packlink\WooCommerce\Components\Services\Config_Service;
use Packlink\WooCommerce\Components\Services\Logger_Service;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Map;
use Packlink\WooCommerce\Components\ShippingMethod\Shop_Shipping_Method_Service;

/**
 * Class Bootstrap_Component
 *
 * @package Packlink\WooCommerce\Components
 */
class Bootstrap_Component extends \Packlink\BusinessLogic\BootstrapComponent {
	/**
	 * @inheritdoc
	 */
	protected static function initServices() {
		parent::initServices();

		ServiceRegister::registerService( Configuration::CLASS_NAME, function () {
			return Config_Service::getInstance();
		} );

		ServiceRegister::registerService( ShopLoggerAdapter::CLASS_NAME, function () {
			return Logger_Service::getInstance();
		} );

		ServiceRegister::registerService( ShopShippingMethodService::CLASS_NAME, function () {
			return Shop_Shipping_Method_Service::getInstance();
		} );

		ServiceRegister::registerService( OrderRepository::CLASS_NAME, function () {
			return Order_Repository::getInstance();
		} );

		ServiceRegister::registerService( HttpClient::CLASS_NAME, function () {
			return new CurlHttpClient();
		} );
	}

	/**
	 * @inheritdoc
	 * @throws \Logeecom\Infrastructure\ORM\Exceptions\RepositoryClassException
	 */
	protected static function initRepositories() {
		parent::initRepositories();

		RepositoryRegistry::registerRepository( ConfigEntity::CLASS_NAME, Base_Repository::getClassName() );
		RepositoryRegistry::registerRepository( Process::CLASS_NAME, Base_Repository::getClassName() );
		RepositoryRegistry::registerRepository( ShippingMethod::CLASS_NAME, Base_Repository::getClassName() );
		RepositoryRegistry::registerRepository( Shipping_Method_Map::CLASS_NAME, Base_Repository::getClassName() );
		RepositoryRegistry::registerRepository( Order_Shipment_Entity::CLASS_NAME, Base_Repository::getClassName() );
		RepositoryRegistry::registerRepository( Schedule::CLASS_NAME, Base_Repository::getClassName() );
		RepositoryRegistry::registerRepository( QueueItem::CLASS_NAME, Queue_Item_Repository::getClassName() );
	}
}
