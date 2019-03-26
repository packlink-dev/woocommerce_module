<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\ShippingMethod;

use Logeecom\Infrastructure\ORM\Interfaces\RepositoryInterface;
use Logeecom\Infrastructure\ORM\QueryFilter\QueryFilter;
use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\Http\DTO\Package;
use Packlink\BusinessLogic\Http\DTO\ParcelInfo;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\BusinessLogic\ShippingMethod\ShippingCostCalculator;
use Packlink\BusinessLogic\ShippingMethod\ShippingMethodService;
use Packlink\WooCommerce\Components\Services\Config_Service;
use WC_Product;

/**
 * Class Packlink_Shipping_Method
 *
 * @package Packlink\WooCommerce\Components\ShippingMethod
 */
class Packlink_Shipping_Method extends \WC_Shipping_Method {
	/**
	 * Fully qualified name of this interface.
	 */
	const CLASS_NAME               = __CLASS__;
	const PACKLINK_SHIPPING_METHOD = 'packlink_shipping_method';

	/**
	 * Available shipping services
	 *
	 * @var array
	 */
	private static $shipping_services = array();
	/**
	 * Available shipping services loaded.
	 *
	 * @var bool
	 */
	private static $loaded = false;
	/**
	 * Pricing policy.
	 *
	 * @var string
	 */
	public $price_policy;
	/**
	 * Configuration service.
	 *
	 * @var Config_Service
	 */
	private $configuration;
	/**
	 * Shipping method service.
	 *
	 * @var ShippingMethodService
	 */
	private $shipping_method_service;
	/**
	 * Base repository.
	 *
	 * @var RepositoryInterface
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * @param int $instance_id Instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		parent::__construct( $instance_id );

		$this->id                 = static::PACKLINK_SHIPPING_METHOD;
		$this->method_title       = __( 'Packlink Shipping', 'packlink_pro_shipping' );
		$this->method_description = __( 'Custom Shipping Method for Packlink', 'packlink_pro_shipping' );

		$this->init();

		$this->enabled  = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'yes';
		$this->supports = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);

		/** @noinspection PhpUnhandledExceptionInspection */
		$this->repository              = RepositoryRegistry::getRepository( Shipping_Method_Map::CLASS_NAME );
		$this->shipping_method_service = ServiceRegister::getService( ShippingMethodService::CLASS_NAME );
		$this->configuration           = ServiceRegister::getService( Config_Service::CLASS_NAME );
	}

	/**
	 * Initialize settings.
	 */
	public function init() {
		// Load the settings API.
		$this->init_form_fields();
		$this->init_settings();

		$this->title        = $this->get_option( 'title', __( 'Packlink Shipping', 'packlink_pro_shipping' ) );
		$this->price_policy = $this->get_option( 'price_policy', __( 'Packlink prices', 'packlink_pro_shipping' ) );

		// Save settings in admin if you have any defined.
		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Initialise settings form fields.
	 *
	 * Add an array of fields to be displayed on the gateway's settings screen.
	 */
	public function init_form_fields() {
		$this->instance_form_fields = array(
			'title'        => array(
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
				'default'     => isset( $this->settings['title'] ) ? $this->settings['title'] : __( 'Packlink Shipping', 'packlink_pro_shipping' ),
				'desc_tip'    => true,
			),
			'price_policy' => array(
				'title'       => __( 'Pricing policy', 'packlink_pro_shipping' ),
				'type'        => 'text',
				'description' => __( 'Pricing policy selected in Packlink PRO Shipping settings.', 'packlink_pro_shipping' ),
				'default'     => isset( $this->settings['price_policy'] ) ? $this->settings['price_policy'] : __( 'Packlink prices', 'packlink_pro_shipping' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Called to calculate shipping rates for this method. Rates can be added using the add_rate() method.
	 *
	 * @param array $package Package array.
	 */
	public function calculate_shipping( $package = array() ) {
		$shipping_method = $this->get_packlink_shipping_method();
		if ( ! $shipping_method || ! $this->load_shipping_costs( $package, $shipping_method ) ) {
			return;
		}

		$id = $shipping_method->getId();
		$this->add_rate(
			array(
				'id'      => $this->get_rate_id(),
				'label'   => $this->title,
				'cost'    => -1 === $id ? min( static::$shipping_services ) : static::$shipping_services[ $id ],
				'package' => $package,
			)
		);
	}

	/**
	 * Is this method available?
	 *
	 * @param array $package Package.
	 *
	 * @return bool
	 */
	public function is_available( $package ) {
		$shipping_method = $this->get_packlink_shipping_method();

		return $shipping_method && $this->load_shipping_costs( $package, $shipping_method );
	}

	/**
	 * Returns Packlink shipping method that is assigned to this WooCommerce shipping method.
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * @return ShippingMethod Shipping method.
	 */
	private function get_packlink_shipping_method() {
		$filter = new QueryFilter();
		/** @noinspection PhpUnhandledExceptionInspection */
		$filter->where( 'woocommerceShippingMethodId', '=', $this->instance_id );

		/**
		 * Shipping method map.
		 *
		 * @var Shipping_Method_Map $map_entry
		 */
		$map_entry = $this->repository->selectOne( $filter );
		if ( null === $map_entry ) {
			return null;
		}

		$id = $map_entry->getPacklinkShippingMethodId();
		if ( - 1 === $id ) {
			return $this->configuration->get_default_shipping_method();
		}

		return $this->shipping_method_service->getShippingMethod( $id );
	}

	/**
	 * Builds parcels out of shipping packages.
	 *
	 * @param array      $package Packages.
	 * @param ParcelInfo $default Default parcel.
	 *
	 * @return Package[] Array of parcels.
	 */
	private function build_parcels( array $package, ParcelInfo $default ) {
		$parcels  = array();
		$contents = isset( $package['contents'] ) ? $package['contents'] : array();
		foreach ( $contents as $item ) {
			/**
			 * WooCommerce product.
			 *
			 * @var WC_Product $product
			 */
			$product = $item['data'];
			for ( $i = 0; $i < $item['quantity']; $i ++ ) {
				$parcel = new Package();

				$parcel->weight = wc_get_weight( $product->get_weight(), 'kg' ) ?: $default->weight;
				$parcel->height = wc_get_dimension( $product->get_height(), 'cm' ) ?: $default->height;
				$parcel->width  = wc_get_dimension( $product->get_width(), 'cm' ) ?: $default->width;
				$parcel->length = wc_get_dimension( $product->get_length(), 'cm' ) ?: $default->length;

				$parcels[] = $parcel;
			}
		}

		return $parcels;
	}

	/**
	 * Loads shipping costs.
	 *
	 * @param array          $package Package.
	 * @param ShippingMethod $shipping_method Shipping method.
	 *
	 * @return bool Success indicator.
	 */
	private function load_shipping_costs( array $package, ShippingMethod $shipping_method ) {
		$warehouse      = $this->configuration->getDefaultWarehouse();
		$default_parcel = $this->configuration->getDefaultParcel();

		if ( null === $warehouse || null === $default_parcel ) {
			return null;
		}

		$id         = $shipping_method->getId();
		$to_country = isset( $package['destination']['country'] ) ? $package['destination']['country'] : $warehouse->country;
		$to_zip     = isset( $package['destination']['postcode'] ) ? $package['destination']['postcode'] : $warehouse->postalCode;
		if ( ! static::$loaded ) {
			static::$shipping_services = ShippingCostCalculator::getShippingCosts(
				$this->shipping_method_service->getAllMethods(),
				$warehouse->country,
				$warehouse->postalCode,
				$to_country,
				$to_zip,
				$this->build_parcels( $package, $default_parcel ),
				$package['contents_cost']
			);

			static::$loaded = true;
		}

		return array_key_exists( $id, static::$shipping_services ) || ( -1 === $id && ! empty( static::$shipping_services ) );
	}
}
