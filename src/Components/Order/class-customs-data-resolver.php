<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Order;

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\Order\Objects\Item;
use Packlink\WooCommerce\Components\Services\Config_Service;
use Packlink\WooCommerce\Components\Services\Customs_Mapping_Service;
use WC_Order;
use WC_Product;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Customs_Data_Resolver
 *
 * Resolves the customs-relevant values of a core Order/Item out of WooCommerce products and
 * customers, honoring the saved data-source mapping. Two surfaces need the very same resolution:
 * the shipment draft built from a placed order (Shop_Order_Service) and the duty-cost estimate
 * built from the cart at the shipping step (Cart_Order_Factory). If they disagreed, Packlink would
 * price one shipment and clear customs on another.
 *
 * The mapping is loaded once per instance, so a caller that lives for a request reads the database
 * once.
 *
 * @package Packlink\WooCommerce\Components\Order
 */
class Customs_Data_Resolver {

	/**
	 * Configuration service.
	 *
	 * @var Config_Service
	 */
	private $configuration;

	/**
	 * Cached customs mapping. `false` means "not loaded yet"; `null` means "none saved".
	 *
	 * @var \Packlink\BusinessLogic\Customs\Models\CustomsMapping|null|false
	 */
	private $customs_mapping = false;

	/**
	 * Customs_Data_Resolver constructor.
	 */
	public function __construct() {
		$this->configuration = ServiceRegister::getService( Config_Service::CLASS_NAME );
	}

	/**
	 * Sums the weight of all order items (unit weight multiplied by quantity).
	 *
	 * The core Order model keeps its own total-weight field, separate from the per-item weights, and
	 * it defaults to 0. The customs invoice request (CustomsService::getShipmentDetails) reads this
	 * order-level value for `parcels_weight`, which Packlink rejects when it is 0. So it must be set
	 * explicitly here even though the draft packages are built from the individual item weights.
	 *
	 * @param Item[] $items Formatted order items.
	 *
	 * @return float Total order weight in the store weight unit.
	 */
	public static function total_weight( array $items ) {
		$total_weight = 0.0;

		foreach ( $items as $item ) {
			$quantity      = $item->getQuantity() ? $item->getQuantity() : 1;
			$total_weight += (float) $item->getWeight() * $quantity;
		}

		return $total_weight;
	}

	/**
	 * Returns category name.
	 *
	 * @param WC_Product $product WooCommerce product.
	 *
	 * @return string|null Category name.
	 */
	public static function product_category_name( WC_Product $product ) {
		$category_ids = $product->get_category_ids();
		if ( empty( $category_ids ) ) {
			return null;
		}

		$category = WP_Term::get_instance( $category_ids[0] );

		return $category instanceof WP_Term ? $category->name : null;
	}

	/**
	 * Resolves an item tariff (HS) code: mapped product field, then the dedicated HS-code meta, then
	 * empty (core applies the configured default).
	 *
	 * @param WC_Product $product WooCommerce product.
	 *
	 * @return string
	 */
	public function resolve_item_tariff_number( WC_Product $product ) {
		$mapping = $this->get_customs_mapping();

		if ( $mapping && ! empty( $mapping->mappingTariffNumber ) ) {
			$value = $this->read_product_field( $product, $mapping->mappingTariffNumber );
			if ( '' !== $value ) {
				return $value;
			}
		}

		$fallback = $product->get_meta( Customs_Mapping_Service::PRODUCT_HS_CODE_META );

		return $fallback ? (string) $fallback : '';
	}

	/**
	 * Resolves an item country of origin: mapped product field, then the dedicated
	 * country-of-origin meta, then empty (core applies the configured default).
	 *
	 * @param WC_Product $product WooCommerce product.
	 *
	 * @return string
	 */
	public function resolve_item_country_of_origin( WC_Product $product ) {
		$mapping = $this->get_customs_mapping();

		if ( $mapping && ! empty( $mapping->mappingCountryOfOrigin ) ) {
			$value = $this->read_product_field( $product, $mapping->mappingCountryOfOrigin );
			if ( '' !== $value ) {
				return $value;
			}
		}

		$fallback = $product->get_meta( Customs_Mapping_Service::PRODUCT_COUNTRY_OF_ORIGIN_META );

		return $fallback ? (string) $fallback : '';
	}

	/**
	 * Resolves the customer tax ID / VAT number of a placed order: mapped customer field, then the
	 * dedicated user meta of the order customer, then empty. One value serves both customs
	 * attributes - the core routes it to tax id or VAT number based on the configured receiver user
	 * type.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 *
	 * @return string
	 */
	public function resolve_tax_id_from_order( WC_Order $wc_order ) {
		$mapping = $this->get_customs_mapping();

		if ( $mapping && ! empty( $mapping->mappingReceiverTaxId ) ) {
			$value = $this->read_customer_field( $wc_order, $mapping->mappingReceiverTaxId );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return $this->read_user_meta( (int) $wc_order->get_customer_id(), Customs_Mapping_Service::USER_TAX_ID_META );
	}

	/**
	 * Resolves the customer tax ID / VAT number at the shipping step, where no order exists yet.
	 * Only the customer profile can be read here, so an `order:` mapping has no source to read from
	 * and falls through to the dedicated user meta. Guests (customer id 0) resolve to an empty
	 * string by design: no profile, no tax id.
	 *
	 * @param int $customer_id Customer user id.
	 *
	 * @return string
	 */
	public function resolve_tax_id_from_customer( $customer_id ) {
		$mapping = $this->get_customs_mapping();

		if ( $mapping && ! empty( $mapping->mappingReceiverTaxId )
			&& 0 === strpos( $mapping->mappingReceiverTaxId, Customs_Mapping_Service::PREFIX_USER_META ) ) {
			$key   = substr( $mapping->mappingReceiverTaxId, strlen( Customs_Mapping_Service::PREFIX_USER_META ) );
			$value = $this->read_user_meta( (int) $customer_id, $key );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return $this->read_user_meta( (int) $customer_id, Customs_Mapping_Service::USER_TAX_ID_META );
	}

	/**
	 * Returns the saved customs mapping, loading it once per instance.
	 *
	 * @return \Packlink\BusinessLogic\Customs\Models\CustomsMapping|null
	 */
	private function get_customs_mapping() {
		if ( false === $this->customs_mapping ) {
			$this->customs_mapping = $this->configuration->getCustomsMappings();
		}

		return $this->customs_mapping;
	}

	/**
	 * Reads a mapped product field by its namespace: an `attr:{name}` value is a product attribute
	 * (global `pa_*` taxonomy or per-product custom attribute name, read via get_attribute), a
	 * `meta:{key}` value is a product meta key. Legacy un-namespaced values fall back to the old
	 * behavior: `pa_*` is an attribute, anything else is a product meta key.
	 *
	 * @param WC_Product $product WooCommerce product.
	 * @param string     $field   Mapped field (namespaced attribute or meta key).
	 *
	 * @return string
	 */
	private function read_product_field( WC_Product $product, $field ) {
		if ( 0 === strpos( $field, Customs_Mapping_Service::PREFIX_ATTRIBUTE ) ) {
			$name = substr( $field, strlen( Customs_Mapping_Service::PREFIX_ATTRIBUTE ) );

			return (string) $product->get_attribute( $name );
		}

		if ( 0 === strpos( $field, Customs_Mapping_Service::PREFIX_PRODUCT_META ) ) {
			$key   = substr( $field, strlen( Customs_Mapping_Service::PREFIX_PRODUCT_META ) );
			$value = $product->get_meta( $key );

			return ( '' !== $value && null !== $value ) ? (string) $value : '';
		}

		if ( 0 === strpos( $field, 'pa_' ) ) {
			return (string) $product->get_attribute( $field );
		}

		$value = $product->get_meta( $field );

		return ( '' !== $value && null !== $value ) ? (string) $value : '';
	}

	/**
	 * Reads a mapped customer field by its namespace: an `order:{key}` value is an order meta key,
	 * a `user:{key}` value is a customer user meta key (empty for guest orders). Legacy
	 * un-namespaced values fall back to the old behavior: an order meta key.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 * @param string   $field    Mapped field (namespaced order or user meta key).
	 *
	 * @return string
	 */
	private function read_customer_field( WC_Order $wc_order, $field ) {
		if ( 0 === strpos( $field, Customs_Mapping_Service::PREFIX_ORDER_META ) ) {
			$key   = substr( $field, strlen( Customs_Mapping_Service::PREFIX_ORDER_META ) );
			$value = $wc_order->get_meta( $key );

			return ( '' !== $value && null !== $value ) ? (string) $value : '';
		}

		if ( 0 === strpos( $field, Customs_Mapping_Service::PREFIX_USER_META ) ) {
			$key = substr( $field, strlen( Customs_Mapping_Service::PREFIX_USER_META ) );

			return $this->read_customer_user_meta( $wc_order, $key );
		}

		$value = $wc_order->get_meta( $field );

		return ( '' !== $value && null !== $value ) ? (string) $value : '';
	}

	/**
	 * Reads a user meta value of the order customer. Guest orders (customer id 0) have no profile,
	 * so they resolve to an empty string.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 * @param string   $key      User meta key.
	 *
	 * @return string
	 */
	private function read_customer_user_meta( WC_Order $wc_order, $key ) {
		return $this->read_user_meta( (int) $wc_order->get_customer_id(), $key );
	}

	/**
	 * Reads a user meta value of a customer. Guests (customer id 0) have no profile, so they
	 * resolve to an empty string.
	 *
	 * @param int    $customer_id Customer user id.
	 * @param string $key         User meta key.
	 *
	 * @return string
	 */
	private function read_user_meta( $customer_id, $key ) {
		if ( 0 === (int) $customer_id ) {
			return '';
		}

		$value = get_user_meta( $customer_id, $key, true );

		return ( '' !== $value && null !== $value ) ? (string) $value : '';
	}
}
