<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\ShippingMethod;

use Logeecom\Infrastructure\Logger\Logger;
use Logeecom\Infrastructure\ORM\Interfaces\RepositoryInterface;
use Logeecom\Infrastructure\ORM\QueryFilter\QueryFilter;
use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\Singleton;
use Packlink\BusinessLogic\ShippingMethod\Interfaces\ShopShippingMethodService;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;

/**
 * Class Shop_Shipping_Method_Service
 * @package Packlink\WooCommerce\Components\ShippingMethod
 */
class Shop_Shipping_Method_Service extends Singleton implements ShopShippingMethodService {

	/**
	 * Singleton instance of this class.
	 *
	 * @var static
	 */
	protected static $instance;
	/**
	 * Repository instance.
	 *
	 * @var RepositoryInterface
	 */
	protected $repository;

	/**
	 * Shop_Shipping_Method_Service constructor.
	 */
	public function __construct() {
		parent::__construct();

		/** @noinspection PhpUnhandledExceptionInspection */
		$this->repository = RepositoryRegistry::getRepository( Shipping_Method_Map::CLASS_NAME );
	}

	/**
	 * Adds / Activates shipping method in shop integration.
	 *
	 * @param ShippingMethod $shipping_method Shipping method.
	 *
	 * @return bool TRUE if activation succeeded; otherwise, FALSE.
	 */
	public function add( ShippingMethod $shipping_method ) {
		$pricing_policy = $this->get_shipping_method_pricing_policy( $shipping_method );

		try {
			foreach ( Shipping_Method_Helper::get_all_shipping_zone_ids() as $zone_id ) {
				$zone        = new \WC_Shipping_Zone( $zone_id );
				$instance_id = $zone->add_shipping_method( 'packlink_shipping_method' );

				if ( 0 !== $instance_id ) {
					$new = new Packlink_Shipping_Method( $instance_id );
					$new->set_post_data( array(
						'woocommerce_packlink_shipping_method_title'        => $shipping_method->getTitle(),
						'woocommerce_packlink_shipping_method_price_policy' => $pricing_policy,
					) );

					$_REQUEST['instance_id'] = $instance_id;
					$new->process_admin_options();
					$this->add_to_shipping_method_map( $instance_id, $shipping_method->getId(), $zone_id );
				}
			}
		} catch ( \Exception $e ) {
			Logger::logError( $e->getMessage(), 'Integration', $shipping_method->toArray() );

			return false;
		}

		return true;
	}

	/**
	 * Updates shipping method in shop integration.
	 *
	 * @param ShippingMethod $shipping_method Shipping method.
	 */
	public function update( ShippingMethod $shipping_method ) {
		$pricing_policy = $this->get_shipping_method_pricing_policy( $shipping_method );
		$is_enabled     = $shipping_method->isEnabled() && $shipping_method->isActivated() ? 'yes' : 'no';

		global $wpdb;

		$items = $this->get_woocommerce_shipping_methods( $shipping_method->getId() );
		foreach ( $items as $item ) {
			$instance_id = $item->getWoocommerceShippingMethodId();

			$packlink_shipping_method = new Packlink_Shipping_Method( $instance_id );
			$packlink_shipping_method->set_post_data( array(
				'woocommerce_packlink_shipping_method_title'        => $shipping_method->getTitle(),
				'woocommerce_packlink_shipping_method_price_policy' => $pricing_policy,
			) );

			if ( $is_enabled !== $packlink_shipping_method->enabled ) {
				$wpdb->update( "{$wpdb->prefix}woocommerce_shipping_zone_methods", array( 'is_enabled' => $is_enabled ), array( 'instance_id' => absint( $instance_id ) ) );
			}

			$_REQUEST['instance_id'] = $instance_id;
			$packlink_shipping_method->process_admin_options();
		}
	}

	/**
	 * Deletes shipping method in shop integration.
	 *
	 * @param ShippingMethod $shipping_method Shipping method.
	 *
	 * @return bool TRUE if deletion succeeded; otherwise, FALSE.
	 */
	public function delete( ShippingMethod $shipping_method ) {
		global $wpdb;

		try {
			$items = $this->get_woocommerce_shipping_methods( $shipping_method->getId() );
			foreach ( $items as $item ) {
				$instance_id                 = $item->getWoocommerceShippingMethodId();
				$woocommerce_shipping_method = new Packlink_Shipping_Method( $instance_id );
				$option_key                  = $woocommerce_shipping_method->get_instance_option_key();
				if ( $wpdb->delete( "{$wpdb->prefix}woocommerce_shipping_zone_methods", array( 'instance_id' => $instance_id ) ) ) {
					delete_option( $option_key );
				}

				$this->repository->delete( $item );
			}
		} catch ( \Exception $e ) {
			Logger::logError( $e->getMessage(), 'Integration', $shipping_method->toArray() );

			return false;
		}

		return true;
	}

	/**
	 * Returns shipping method pricing policy.
	 *
	 * @param ShippingMethod $shipping_method Shipping method object.
	 *
	 * @return string
	 */
	private function get_shipping_method_pricing_policy( ShippingMethod $shipping_method ) {
		$pricing_policy = __( 'Packlink prices', 'packlink_pro_shipping' );
		switch ( $shipping_method->getPricingPolicy() ) {
			case ShippingMethod::PRICING_POLICY_PERCENT:
				$pricing_policy = __( '% of Packlink prices', 'packlink_pro_shipping' );
				break;
			case ShippingMethod::PRICING_POLICY_FIXED:
				$pricing_policy = __( 'Fixed price', 'packlink_pro_shipping' );
				break;
		}

		return $pricing_policy;
	}

	/**
	 * Adds pair of WooCommerce and Packlink shipping methods to map.
	 *
	 * @param int $woocommerce_method_id WooCommerce shipping method identifier.
	 * @param int $packlink_method_id Packlink shipping method identifier.
	 * @param int $zone_id WooCommerce shipping zone identifier.
	 */
	private function add_to_shipping_method_map( $woocommerce_method_id, $packlink_method_id, $zone_id ) {
		$map_item = new Shipping_Method_Map();
		$map_item->setWoocommerceShippingMethodId( $woocommerce_method_id );
		$map_item->setPacklinkShippingMethodId( $packlink_method_id );
		$map_item->setZoneId( $zone_id );

		$this->repository->save( $map_item );
	}

	/**
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * Returns a list of map items for provided Packlink shipping method identifier.
	 *
	 * @param int $packlink_method_id Packlink shipping method identifier.
	 *
	 * @return Shipping_Method_Map[]
	 */
	private function get_woocommerce_shipping_methods( $packlink_method_id ) {
		$filter = new QueryFilter();
		/** @noinspection PhpUnhandledExceptionInspection */
		$filter->where( 'packlinkShippingMethodId', '=', $packlink_method_id );

		/** @var Shipping_Method_Map[] $entities */
		$entities = $this->repository->select( $filter );

		return $entities;
	}
}
