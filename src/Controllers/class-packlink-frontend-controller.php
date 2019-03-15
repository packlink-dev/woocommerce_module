<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\Configuration;
use Packlink\BusinessLogic\Controllers\DashboardController;
use Packlink\BusinessLogic\Controllers\DTO\ShippingMethodConfiguration;
use Packlink\BusinessLogic\Controllers\ShippingMethodController;
use Packlink\BusinessLogic\Http\DTO\ParcelInfo;
use Packlink\BusinessLogic\Http\DTO\Warehouse;
use Packlink\BusinessLogic\Location\LocationService;
use Packlink\BusinessLogic\ShippingMethod\Models\FixedPricePolicy;
use Packlink\BusinessLogic\ShippingMethod\Models\PercentPricePolicy;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\BusinessLogic\User\UserAccountService;
use Packlink\WooCommerce\Components\Services\Config_Service;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Helper;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;
use Packlink\WooCommerce\Components\Utility\Task_Queue;
use Packlink\WooCommerce\Components\Validators\Parcel_Validator;
use Packlink\WooCommerce\Components\Validators\Warehouse_Validator;

/**
 * Class Packlink_Frontend_Controller
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Frontend_Controller extends Packlink_Base_Controller {

	/**
	 * List of help URLs for different country codes.
	 *
	 * @var array
	 */
	private static $help_urls = array(
		'ES' => 'https://support-pro.packlink.com/hc/es-es/sections/202755109-Prestashop',
		'DE' => 'https://support-pro.packlink.com/hc/de/sections/202755109-Prestashop',
		'FR' => 'https://support-pro.packlink.com/hc/fr-fr/sections/202755109-Prestashop',
		'IT' => 'https://support-pro.packlink.com/hc/it/sections/202755109-Prestashop',
	);
	/**
	 * List of terms and conditions URLs for different country codes.
	 *
	 * @var array
	 */
	private static $terms_and_conditions_urls = array(
		'ES' => 'https://pro.packlink.es/terminos-y-condiciones/',
		'DE' => 'https://pro.packlink.de/agb/',
		'FR' => 'https://pro.packlink.fr/conditions-generales/',
		'IT' => 'https://pro.packlink.it/termini-condizioni/',
	);
	/**
	 * List of country names for different country codes.
	 *
	 * @var array
	 */
	private static $country_names = array(
		'ES' => 'Spain',
		'DE' => 'Germany',
		'FR' => 'France',
		'IT' => 'Italy',
	);

	/**
	 * Configuration service instance.
	 *
	 * @var Config_Service
	 */
	private $configuration;

	/**
	 * Packlink_Frontend_Controller constructor.
	 */
	public function __construct() {
		$this->configuration = ServiceRegister::getService( Config_Service::CLASS_NAME );
		$this->load_css();
		Task_Queue::wakeup();
	}

	/**
	 * Renders appropriate view.
	 *
	 * @throws \Logeecom\Infrastructure\TaskExecution\Exceptions\QueueStorageUnavailableException
	 */
	public function render() {
		$login_failure = false;
		if ( $this->is_post() && ! $this->login() ) {
			$login_failure = true;
		}

		$this->load_js();
		if ( $this->is_user_logged_in() ) {
			include dirname( __DIR__ ) . '/resources/views/dashboard.php';
		} else {
			include dirname( __DIR__ ) . '/resources/views/login.php';
		}
	}

	/**
	 * Logs in user.
	 *
	 * @throws \Logeecom\Infrastructure\TaskExecution\Exceptions\QueueStorageUnavailableException
	 */
	public function login() {
		$result = false;
		$this->validate( 'yes', true );
		$api_key = $this->get_param( 'api_key' );
		if ( $api_key ) {
			/** @var UserAccountService $user_service */
			$user_service = ServiceRegister::getService( UserAccountService::CLASS_NAME );
			$result       = $user_service->login( $api_key );
		}

		return $result;
	}

	/**
	 * Returns dashboard status.
	 */
	public function get_status() {
		$this->validate( 'no', true );

		/** @var DashboardController $dashboard_controller */
		$dashboard_controller = ServiceRegister::getService( DashboardController::CLASS_NAME );
		$status               = $dashboard_controller->getStatus();

		$this->return_json( $status->toArray() );
	}

	/**
	 * Returns debug status.
	 */
	public function get_debug_status() {
		$this->validate( 'no', true );

		$this->return_json( array( 'status' => $this->configuration->isDebugModeEnabled() ) );
	}

	/**
	 * Saves debug status.
	 */
	public function set_debug_status() {
		$this->validate( 'yes', true );
		$raw_json = $this->get_raw_input();
		$payload  = json_decode( $raw_json, true );
		if ( ! isset( $payload['status'] ) && ! is_bool( $payload['status'] ) ) {
			$this->return_json( array( 'success' => false ), 400 );
		}

		$this->configuration->setDebugModeEnabled( $payload['status'] );
		$this->return_json( array( 'status' => $payload['status'] ) );
	}

	/**
	 * Returns default parcel.
	 */
	public function get_default_parcel() {
		$this->validate( 'no', true );

		/** @var Configuration $configuration */
		$configuration = ServiceRegister::getService( Configuration::CLASS_NAME );
		$parcel        = $configuration->getDefaultParcel();

		$this->return_json( $parcel ? $parcel->toArray() : array() );
	}

	/**
	 * Saves default parcel.
	 */
	public function save_default_parcel() {
		$this->validate( 'yes', true );

		$raw_json = $this->get_raw_input();
		$payload  = json_decode( $raw_json, true );

		$validator = new Parcel_Validator();
		$errors    = $validator->validate( $payload );
		if ( ! empty( $errors ) ) {
			$this->return_json( $errors, 400 );
		}

		/** @var Configuration $configuration */
		$configuration = ServiceRegister::getService( Configuration::CLASS_NAME );
		$configuration->setDefaultParcel( ParcelInfo::fromArray( $payload ) );

		$this->return_json( array( 'success' => true ) );
	}

	/**
	 * Returns default warehouse.
	 */
	public function get_default_warehouse() {
		$this->validate( 'no', true );

		/** @var Configuration $configuration */
		$configuration = ServiceRegister::getService( Configuration::CLASS_NAME );
		$warehouse     = $configuration->getDefaultWarehouse();

		$this->return_json( $warehouse ? $warehouse->toArray() : array() );
	}

	/**
	 * Saves default warehouse.
	 */
	public function save_default_warehouse() {
		$this->validate( 'yes', true );

		$raw_json = $this->get_raw_input();
		$payload  = json_decode( $raw_json, true );

		/** @var Configuration $configuration */
		$configuration = ServiceRegister::getService( Configuration::CLASS_NAME );
		if ( ! isset( $payload['country'] ) ) {
			$user               = $configuration->getUserInfo();
			$payload['country'] = $user ? $user->country : 'ES';
		}

		$validator = new Warehouse_Validator();
		$errors    = $validator->validate( $payload );
		if ( ! empty( $errors ) ) {
			$this->return_json( $errors, 400 );
		}

		$configuration->setDefaultWarehouse( Warehouse::fromArray( $payload ) );

		$this->return_json( array( 'success' => true ) );
	}

	/**
	 * Performs locations search.
	 */
	public function search_locations() {
		$this->validate( 'yes', true );

		$raw_json = $this->get_raw_input();
		$payload  = json_decode( $raw_json, true );

		if ( empty( $payload['query'] ) ) {
			$this->return_json( array() );
		}

		/** @var Configuration $configuration */
		$configuration    = ServiceRegister::getService( Configuration::CLASS_NAME );
		$platform_country = $configuration->getUserInfo()->country;

		/** @var LocationService $location_service */
		$location_service = ServiceRegister::getService( LocationService::CLASS_NAME );

		try {
			$result          = $location_service->searchLocations( $platform_country, $payload['query'] );
			$result_as_array = array();

			foreach ( $result as $item ) {
				$result_as_array[] = $item->toArray();
			}

			$this->return_json( $result_as_array );
		} catch ( \Exception $e ) {
			$this->return_json( array() );
		}
	}

	/**
	 * Returns all shipping methods.
	 */
	public function get_all_shipping_methods() {
		$this->validate( 'no', true );

		/** @var ShippingMethodController $controller */
		$controller = ServiceRegister::getService( ShippingMethodController::CLASS_NAME );
		$result     = array();
		foreach ( $controller->getAll() as $item ) {
			$shipping_method            = $item->toArray();
			$shipping_method['logoUrl'] = Shipping_Method_Helper::get_carrier_logo( $shipping_method['carrierName'] );

			$result[] = $shipping_method;
		}

		$this->return_json( $result );
	}

	/**
	 * Activates shipping method.
	 */
	public function activate_shipping_method() {
		$this->validate( 'yes', true );

		$this->change_shipping_status();
	}

	/**
	 * Deactivates shipping method.
	 */
	public function deactivate_shipping_method() {
		$this->validate( 'yes', true );

		$this->change_shipping_status( 'no' );
	}

	/**
	 * Returns count of active shop shipping methods.
	 */
	public function get_shipping_method_count() {
		$this->validate( 'no', true );

		$this->return_json( array( 'count' => Shipping_Method_Helper::get_shop_shipping_method_count() ) );
	}

	/**
	 * Disables active shop shipping methods.
	 */
	public function disable_shop_shipping_methods() {
		$this->validate( 'no', true );

		Shipping_Method_Helper::disable_shop_shipping_methods();

		$this->return_json( array( 'success' => true ) );
	}

	/**
	 * Saves shipping method configuration.
	 */
	public function save_shipping_method() {
		$this->validate( 'yes', true );

		$raw_json = $this->get_raw_input();
		$payload  = json_decode( $raw_json, true );
		if ( ! array_key_exists( 'id', $payload ) ) {
			$this->return_json( array( 'success' => false ), 400 );
		}

		$shipping_method = $this->build_shipping_method( $payload );

		/** @var ShippingMethodController $controller */
		$controller = ServiceRegister::getService( ShippingMethodController::CLASS_NAME );
		$result     = $controller->save( $shipping_method );
		if ( $result && ! $result->selected ) {
			$result->selected = $controller->activate( $result->id );
		}

		if ( $result ) {
			$result->logoUrl = Shipping_Method_Helper::get_carrier_logo( $result->carrierName );
			$this->return_json( $result->toArray() );
		} else {
			$this->return_json( array( 'success' => false ), 400 );
		}
	}

	/**
	 * Returns list of WooCommerce statuses.
	 */
	public function get_system_order_statuses() {
		$this->validate( 'no', true );

		$result = array();
		foreach ( wc_get_order_statuses() as $code => $label ) {
			$result[] = array( 'code' => $code, 'label' => $label );
		}

		$this->return_json( $result );
	}

	/**
	 * Returns map of order and Packlink shipping statuses.
	 */
	public function get_order_status_mappings() {
		$this->validate( 'no', true );

		$this->return_json( $this->configuration->getOrderStatusMappings() ?: array() );
	}

	/**
	 * Saves map of order and Packlink shipping statuses.
	 */
	public function save_order_status_mapping() {
		$this->validate( 'yes', true );

		$raw_json   = $this->get_raw_input();
		$status_map = json_decode( $raw_json, true );
		if ( ! is_array( $status_map ) ) {
			$this->return_json( array( 'success' => false ), 400 );
		}

		$this->configuration->setOrderStatusMappings( $status_map );

		$this->return_json( array( 'success' => true ) );
	}

	/**
	 * Resolves dashboard view arguments.
	 *
	 * @return array Dashboard view arguments.
	 */
	protected function resolve_view_arguments() {
		$user_info = $this->configuration->getUserInfo();
		$locale    = 'ES';
		if ( $user_info !== null && array_key_exists( $user_info->country, self::$help_urls ) ) {
			$locale = $user_info !== null ? $user_info->country : 'ES';
		}

		return array(
			'image_base'        => Shop_Helper::get_plugin_base_url() . 'resources/images/',
			'dashboard_logo'    => Shop_Helper::get_plugin_base_url() . 'resources/images/logo-pl.svg',
			'dashboard_icon'    => Shop_Helper::get_plugin_base_url() . 'resources/images/dashboard.png',
			'terms_url'         => static::$terms_and_conditions_urls[ $locale ],
			'help_url'          => static::$help_urls[ $locale ],
			'plugin_version'    => Shop_Helper::get_plugin_version(),
			'debug_url'         => Shop_Helper::get_controller_url( 'Debug', 'download' ),
			'warehouse_country' => static::$country_names[ $locale ],
		);
	}

	/**
	 * Loads CSS for the current page.
	 */
	private function load_css() {
		$base_url = Shop_Helper::get_plugin_base_url() . 'resources/';
		wp_enqueue_style(
			'packlink-global-styles',
			$base_url . 'css/packlink.css',
			array(),
			1
		);
		wp_enqueue_style(
			'packlink-wp-override',
			$base_url . 'css/packlink-wp-override.css',
			array(),
			1
		);
	}

	/**
	 * Loads JS script for the current page.
	 */
	private function load_js() {
		$base_url     = Shop_Helper::get_plugin_base_url() . 'resources/js/';
		$js_resources = array(
			'packlink_ajax'                    => 'core/packlink-ajax-service.js',
			'packlink_footer_controller'       => 'core/packlink-footer-controller.js',
			'packlink_default_parcel'          => 'core/packlink-default-parcel-controller.js',
			'packlink_default_warehouse'       => 'core/packlink-default-warehouse-controller.js',
			'packlink_order_state_mapping'     => 'core/packlink-order-state-mapping-controller.js',
			'packlink_page_controller_factory' => 'core/packlink-page-controller-factory.js',
			'packlink_shipping_methods'        => 'core/packlink-shipping-methods-controller.js',
			'packlink_sidebar'                 => 'core/packlink-sidebar-controller.js',
			'packlink_state'                   => 'core/packlink-state-controller.js',
			'packlink_template'                => 'core/packlink-template-service.js',
			'packlink_utility'                 => 'core/packlink-utility-service.js',
		);

		foreach ( $js_resources as $handle => $file ) {
			wp_enqueue_script( $handle, esc_url( $base_url . $file ), array(), 1 );
		}
	}

	/**
	 * Changes shipping method active status.
	 *
	 * @param string $activate Shipping method should be activated.
	 */
	private function change_shipping_status( $activate = 'yes' ) {
		$raw_json = $this->get_raw_input();
		$payload  = json_decode( $raw_json, true );
		if ( ! array_key_exists( 'id', $payload ) ) {
			$this->return_json( array( 'success' => false ), 400 );
		}

		/** @var ShippingMethodController $controller */
		$controller = ServiceRegister::getService( ShippingMethodController::CLASS_NAME );
		if ( $activate === 'yes' ) {
			$result = $controller->activate( $payload['id'] );
		} else {
			$result = $controller->deactivate( $payload['id'] );
		}

		$this->return_json( array( 'success' => $result ), $result ? 200 : 400 );
	}

	/**
	 * Builds and returns shipping method DTO from request payload.
	 *
	 * @param array $payload Request payload.
	 *
	 * @return ShippingMethodConfiguration Shipping method DTO.
	 */
	private function build_shipping_method( array $payload ) {
		$shipping_method              = new ShippingMethodConfiguration();
		$shipping_method->id          = $payload['id'];
		$shipping_method->name        = $payload['name'];
		$shipping_method->showLogo    = $payload['showLogo'];
		$shipping_method->pricePolicy = $payload['pricePolicy'];

		if ( $shipping_method->pricePolicy === ShippingMethod::PRICING_POLICY_PERCENT ) {
			$percent_price_policy                = $payload['percentPricePolicy'];
			$shipping_method->percentPricePolicy = new PercentPricePolicy( $percent_price_policy['increase'], $percent_price_policy['amount'] );
		} elseif ( $shipping_method->pricePolicy === ShippingMethod::PRICING_POLICY_FIXED_PRICE_BY_WEIGHT ) {
			$shipping_method->fixedPriceByWeightPolicy = array();
			foreach ( $payload['fixedPriceByWeightPolicy'] as $item ) {
				$shipping_method->fixedPriceByWeightPolicy[] = new FixedPricePolicy( $item['from'], $item['to'], $item['amount'] );
			}
		} elseif ( $shipping_method->pricePolicy === ShippingMethod::PRICING_POLICY_FIXED_PRICE_BY_VALUE ) {
			$shipping_method->fixedPriceByValuePolicy = array();
			foreach ( $payload['fixedPriceByValuePolicy'] as $item ) {
				$shipping_method->fixedPriceByValuePolicy[] = new FixedPricePolicy( $item['from'], $item['to'], $item['amount'] );
			}
		}

		return $shipping_method;
	}

	/**
	 * Returns flag is user logged in.
	 *
	 * @return bool Authenticated flag.
	 */
	private function is_user_logged_in() {
		/** @var Configuration $configuration */
		$configuration = ServiceRegister::getService( Configuration::CLASS_NAME );
		$token         = $configuration->getAuthorizationToken();

		return null !== $token;
	}
}
