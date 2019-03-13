<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\ShippingMethod;

use Logeecom\Infrastructure\ORM\Configuration\EntityConfiguration;
use Logeecom\Infrastructure\ORM\Configuration\IndexMap;
use Logeecom\Infrastructure\ORM\Entity;

/**
 * Class Shipping_Method_Map
 * @package Packlink\WooCommerce\Components\ShippingMethod
 */
class Shipping_Method_Map extends Entity {
	/**
	 * Fully qualified name of this class.
	 */
	const CLASS_NAME = __CLASS__;
	/**
	 * WooCommerce shipping method identifier.
	 *
	 * @var int
	 */
	protected $woocommerceShippingMethodId;
	/**
	 * Packlink shipping method identifier.
	 *
	 * @var int
	 */
	protected $packlinkShippingMethodId;
	/**
	 * Packlink shipping zone identifier.
	 *
	 * @var int
	 */
	protected $zoneId;
	/**
	 * Array of field names.
	 *
	 * @var array
	 */
	protected $fields = array(
		'id',
		'woocommerceShippingMethodId',
		'packlinkShippingMethodId',
		'zoneId',
	);

	/**
	 * Returns entity configuration object.
	 *
	 * @return EntityConfiguration Configuration object.
	 */
	public function getConfig() {
		$index_map = new IndexMap();
		$index_map->addIntegerIndex( 'woocommerceShippingMethodId' );
		$index_map->addIntegerIndex( 'packlinkShippingMethodId' );

		return new EntityConfiguration( $index_map, 'ShippingMethodMap' );
	}

	/**
	 * Returns WooCommerce shipping method identifier.
	 *
	 * @return int WooCommerce shipping method identifier.
	 */
	public function getWoocommerceShippingMethodId() {
		return $this->woocommerceShippingMethodId;
	}

	/**
	 * Sets WooCommerce shipping method identifier.
	 *
	 * @param int WooCommerce shipping method identifier.
	 */
	public function setWoocommerceShippingMethodId( $woocommerceShippingMethodId ) {
		$this->woocommerceShippingMethodId = $woocommerceShippingMethodId;
	}

	/**
	 * Returns Packlink shipping method identifier.
	 *
	 * @return int Packlink shipping method identifier.
	 */
	public function getPacklinkShippingMethodId() {
		return $this->packlinkShippingMethodId;
	}

	/**
	 * Sets Packlink shipping method identifier.
	 *
	 * @param int Packlink shipping method identifier.
	 */
	public function setPacklinkShippingMethodId( $packlinkShippingMethodId ) {
		$this->packlinkShippingMethodId = $packlinkShippingMethodId;
	}

	/**
	 * Returns zone identifier.
	 *
	 * @return int Zone identifier.
	 */
	public function getZoneId() {
		return $this->zoneId;
	}

	/**
	 * Sets zone identifier.
	 *
	 * @param int $zoneId Zone identifier.
	 */
	public function setZoneId( $zoneId ) {
		$this->zoneId = $zoneId;
	}
}
