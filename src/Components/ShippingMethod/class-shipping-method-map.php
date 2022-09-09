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
 *
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
	protected $woocommerce_shipping_method_id;
	/**
	 * Packlink shipping method identifier.
	 *
	 * @var int
	 */
	protected $packlink_shipping_method_id;
	/**
	 * Packlink shipping zone identifier.
	 *
	 * @var int
	 */
	protected $zone_id;
	/**
	 * Array of field names.
	 *
	 * @var array
	 */
	protected $fields = array(
		'id',
		'woocommerce_shipping_method_id',
		'packlink_shipping_method_id',
		'zone_id',
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
		return $this->woocommerce_shipping_method_id;
	}

	/**
	 * Sets WooCommerce shipping method identifier.
	 *
	 * @param int $woocommerce_shipping_method_id WooCommerce shipping method identifier.
	 */
	public function setWoocommerceShippingMethodId( $woocommerce_shipping_method_id ) {
		$this->woocommerce_shipping_method_id = $woocommerce_shipping_method_id;
	}

	/**
	 * Returns Packlink shipping method identifier.
	 *
	 * @return int Packlink shipping method identifier.
	 */
	public function getPacklinkShippingMethodId() {
		return $this->packlink_shipping_method_id;
	}

	/**
	 * Sets Packlink shipping method identifier.
	 *
	 * @param int $packlink_shipping_method_id Packlink shipping method identifier.
	 */
	public function setPacklinkShippingMethodId( $packlink_shipping_method_id ) {
		$this->packlink_shipping_method_id = $packlink_shipping_method_id;
	}

	/**
	 * Returns zone identifier.
	 *
	 * @return int Zone identifier.
	 */
	public function getZoneId() {
		return $this->zone_id;
	}

	/**
	 * Sets zone identifier.
	 *
	 * @param int $zone_id Zone identifier.
	 */
	public function setZoneId( $zone_id ) {
		$this->zone_id = $zone_id;
	}
}
