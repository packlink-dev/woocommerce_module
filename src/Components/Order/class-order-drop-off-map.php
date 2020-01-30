<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Order;

use Logeecom\Infrastructure\ORM\Configuration\EntityConfiguration;
use Logeecom\Infrastructure\ORM\Configuration\IndexMap;
use Logeecom\Infrastructure\ORM\Entity;

/**
 * Class Order_Drop_Off_Map
 *
 * @package Packlink\WooCommerce\Components\Order
 */
class Order_Drop_Off_Map extends Entity {
	/**
	 * Fully qualified name of this class.
	 */
	const CLASS_NAME = __CLASS__;
	/**
	 * WooCommerce order ID.
	 *
	 * @var int
	 */
	protected $orderId;
	/**
	 * Packlink drop-off point ID.
	 *
	 * @var int
	 */
	protected $dropOffPointId;
	/**
	 * Array of field names.
	 *
	 * @var array
	 */
	protected $fields = array(
		'id',
		'orderId',
		'dropOffPointId',
	);

	/**
	 * @inheritDoc
	 */
	public function getConfig() {
		$map = new IndexMap();
		$map->addIntegerIndex('orderId')
		    ->addIntegerIndex('dropOffPointId');

		return new EntityConfiguration($map, 'OrderDropOffMap');
	}

	/**
	 * Returns order ID.
	 *
	 * @return int
	 */
	public function getOrderId() {
		return $this->orderId;
	}

	/**
	 * Sets order ID.
	 *
	 * @param int $orderId
	 */
	public function setOrderId( $orderId ) {
		$this->orderId = $orderId;
	}

	/**
	 * Returns drop-off point ID.
	 *
	 * @return int
	 */
	public function getDropOffPointId() {
		return $this->dropOffPointId;
	}

	/**
	 * Sets drop-off point ID.
	 *
	 * @param int $dropOffPointId
	 */
	public function setDropOffPointId( $dropOffPointId ) {
		$this->dropOffPointId = $dropOffPointId;
	}
}
