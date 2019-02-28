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
 * Class Order_Shipment_Entity
 * @package Packlink\WooCommerce\Components\Order
 */
class Order_Shipment_Entity extends Entity {
	/**
	 * Fully qualified name of this class.
	 */
	const CLASS_NAME = __CLASS__;
	/**
	 * Array of field names.
	 *
	 * @var array
	 */
	protected $fields = array( 'id', 'woocommerceOrderId', 'packlinkShipmentReference', 'status');
	/**
	 * WooCommerce order identifier.
	 *
	 * @var int
	 */
	protected $woocommerceOrderId;
	/**
	 * Packlink shipment reference.
	 *
	 * @var string
	 */
	protected $packlinkShipmentReference;
	/**
	 * Packlink shipment status.
	 *
	 * @var string
	 */
	protected $status = '';

	/**
	 * Returns entity configuration object.
	 *
	 * @return EntityConfiguration Configuration object.
	 */
	public function getConfig() {
		$index_map = new IndexMap();
		$index_map->addIntegerIndex( 'woocommerceOrderId' );
		$index_map->addStringIndex( 'packlinkShipmentReference' );
		$index_map->addStringIndex( 'status' );

		return new EntityConfiguration( $index_map, 'OrderShipmentEntity' );
	}

	/**
	 * Returns WooCommerce order identifier.
	 *
	 * @return int Order id.
	 */
	public function getWoocommerceOrderId() {
		return $this->woocommerceOrderId;
	}

	/**
	 * Sets WooCommerce order identifier.
	 *
	 * @param int $woocommerceOrderId Order identifier.
	 */
	public function setWoocommerceOrderId( $woocommerceOrderId ) {
		$this->woocommerceOrderId = $woocommerceOrderId;
	}

	/**
	 * Returns Packlink shipment reference.
	 *
	 * @return string Shipment reference.
	 */
	public function getPacklinkShipmentReference() {
		return $this->packlinkShipmentReference;
	}

	/**
	 * Sets Packlink shipment reference.
	 *
	 * @param string Shipment reference.
	 */
	public function setPacklinkShipmentReference( $packlinkShipmentReference ) {
		$this->packlinkShipmentReference = $packlinkShipmentReference;
	}

	/**
	 * Returns shipment status.
	 *
	 * @return string Status.
	 */
	public function getStatus() {
		return $this->status;
	}

	/**
	 * Sets shipment status.
	 *
	 * @param string $status Status.
	 */
	public function setStatus( $status ) {
		$this->status = $status;
	}
}
