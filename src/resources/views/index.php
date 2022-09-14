<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

use Packlink\WooCommerce\Components\Services\System_Info_Service;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$data = $this->resolve_view_arguments();

?>

<div id="pl-page">
	<header id="pl-main-header">
		<div class="pl-main-logo" style="width: 200px">
			<img src="https://cdn.packlink.com/apps/giger/logos/packlink-pro.svg" alt="logo">
		</div>
		<div class="pl-header-holder" id="pl-header-section"></div>
	</header>

	<main id="pl-main-page-holder"></main>

	<div class="pl-spinner pl-hidden" id="pl-spinner">
		<div></div>
	</div>

	<template id="pl-alert">
		<div class="pl-alert-wrapper">
			<div class="pl-alert">
				<span class="pl-alert-text"></span>
				<i class="material-icons">close</i>
			</div>
		</div>
	</template>

	<template id="pl-modal">
		<div id="pl-modal-mask" class="pl-modal-mask pl-hidden">
			<div class="pl-modal">
				<div class="pl-modal-close-button">
					<i class="material-icons">close</i>
				</div>
				<div class="pl-modal-title">

				</div>
				<div class="pl-modal-body">

				</div>
				<div class="pl-modal-footer">
				</div>
			</div>
		</div>
	</template>

	<template id="pl-error-template">
		<div class="pl-error-message" data-pl-element="error">
		</div>
	</template>
</div>

<script>
	document.addEventListener(
		'DOMContentLoaded',
		() => {
			hideNotifications();

			Packlink.translations = {
				default: <?php echo json_encode( $data['lang']['default'] ); ?>,
				current: <?php echo json_encode( $data['lang']['current'] ); ?>
			};

			const pageConfiguration = {
				'login': {
					submit: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Login', 'login' ) ); ?>",
					listOfCountriesUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Regions', 'get_regions' ) ); ?>",
					logoPath: "" // Not used. Logos are retrieved based on the base resource url.
				},
				'register': {
					getRegistrationData: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Register', 'get' ) ); ?>",
					submit: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Register', 'submit' ) ); ?>"
				},
				'onboarding-state': {
					getState: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Onboarding_State', 'get_current_state' ) ); ?>"
				},
				'onboarding-welcome': {},
				'onboarding-overview': {
					defaultParcelGet: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Parcel', 'get' ) ); ?>",
					defaultWarehouseGet: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Warehouse', 'get' ) ); ?>"
				},
				'default-parcel': {
					getUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Parcel', 'get' ) ); ?>",
					submitUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Parcel', 'submit' ) ); ?>"
				},
				'default-warehouse': {
					getUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Warehouse', 'get' ) ); ?>",
					getSupportedCountriesUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Warehouse', 'get_countries' ) ); ?>",
					submitUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Warehouse', 'submit' ) ); ?>",
					searchPostalCodesUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Warehouse', 'search_postal_codes' ) ); ?>"
				},
				'manual-sync': {
					getUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Manual_Sync', 'is_manual_sync_enabled' ) ); ?>",
					submitUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Manual_Sync', 'set_manual_sync_enabled' ) ); ?>",
				},
				'configuration': {
					getDataUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Configuration', 'get' ) ); ?>"
				},
				'system-info': {
					getStatusUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Debug', 'get_status' ) ); ?>",
					setStatusUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Debug', 'set_status' ) ); ?>"
				},
				'order-status-mapping': {
					getMappingAndStatusesUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Order_Status', 'get' ) ); ?>",
					setUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Order_Status', 'submit' ) ); ?>"
				},
				'my-shipping-services': {
					getServicesUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'My_Shipping_Services', 'get' ) ); ?>",
					deleteServiceUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'My_Shipping_Services', 'deactivate' ) ); ?>",
					getCurrencyDetailsUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'System_Info', 'get' ) ); ?>",
					systemId: "<?php echo esc_attr( System_Info_Service::SYSTEM_ID ); ?>"
				},
				'pick-shipping-service': {
					getActiveServicesUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Shipping_Service', 'get_active' ) ); ?>",
					getServicesUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Shipping_Service', 'get' ) ); ?>",
					getTaskStatusUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Shipping_Service', 'get_task_status' ) ); ?>",
					startAutoConfigureUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Auto_Configure', 'start' ) ); ?>",
					disableCarriersUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Shop_Shipping_Methods', 'disable_shop_shipping_methods' ) ); ?>",
					getCurrencyDetailsUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'System_Info', 'get' ) ); ?>",
					systemId: "<?php echo esc_attr( System_Info_Service::SYSTEM_ID ); ?>"
				},
				'edit-service': {
					getServiceUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Edit_Service', 'get_service' ) ); ?>",
					saveServiceUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Edit_Service', 'update_service' ) ); ?>",
					getTaxClassesUrl: "",
					getCountriesListUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Shipping_Zones', 'get_shipping_zones' ) ); ?>",
					getCurrencyDetailsUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'System_Info', 'get' ) ); ?>",
					hasTaxConfiguration: false,
					hasCountryConfiguration: true,
					canDisplayCarrierLogos: true
				}
			};

			Packlink.state = new Packlink.StateController(
				{
					baseResourcesUrl: "<?php echo esc_url_raw( Shop_Helper::get_plugin_base_url() . '/resources/packlink/' ); ?>",
					stateUrl: "<?php echo esc_url_raw( Shop_Helper::get_controller_url( 'Module_State', 'get_state' ) ); ?>",
					pageConfiguration: pageConfiguration,
					templates: {
						'pl-login-page': {
							'pl-main-page-holder': <?php echo json_encode( $data['templates']['login'] ); ?>
						},
						'pl-register-page': {
							'pl-main-page-holder': <?php echo json_encode( $data['templates']['register'] ); ?>
						},
						'pl-register-modal': <?php echo json_encode( $data['templates']['register-modal'] ); ?>
						,
						'pl-onboarding-welcome-page': {
							'pl-main-page-holder': <?php echo json_encode( $data['templates']['onboarding-welcome'] ); ?>
						},
						'pl-onboarding-overview-page': {
							'pl-main-page-holder': <?php echo json_encode( $data['templates']['onboarding-overview'] ); ?>
						},
						'pl-default-parcel-page': {
							'pl-main-page-holder': <?php echo json_encode( $data['templates']['default-parcel'] ); ?>
						},
						'pl-default-warehouse-page': {
							'pl-main-page-holder': <?php echo json_encode( $data['templates']['default-warehouse'] ); ?>
						},
						'pl-manual-sync-page': {
							'pl-main-page-holder': <?php echo json_encode( $data['templates']['manual-sync'] ); ?>
						},
						'pl-configuration-page': {
							'pl-main-page-holder': <?php echo json_encode( $data['templates']['configuration'] ); ?>,
							'pl-header-section': ''
						},
						'pl-order-status-mapping-page': {
							'pl-main-page-holder': <?php echo json_encode( $data['templates']['order-status-mapping'] ); ?>,
							'pl-header-section': ''
						},
						'pl-system-info-modal': <?php echo json_encode( $data['templates']['system-info-modal'] ); ?>,
						'pl-my-shipping-services-page': {
							'pl-main-page-holder': <?php echo json_encode( $data['templates']['my-shipping-services'] ); ?>,
							'pl-header-section': <?php echo json_encode( $data['templates']['shipping-services-header'] ); ?>,
							'pl-shipping-services-table': <?php echo json_encode( $data['templates']['shipping-services-table'] ); ?>,
							'pl-shipping-services-list': <?php echo json_encode( $data['templates']['shipping-services-list'] ); ?>
						},
						'pl-disable-carriers-modal': <?php echo json_encode( $data['templates']['disable-carriers-modal'] ); ?>,
						'pl-pick-service-page': {
							'pl-header-section': '',
							'pl-main-page-holder': <?php echo json_encode( $data['templates']['pick-shipping-services'] ); ?>,
							'pl-shipping-services-table': <?php echo json_encode( $data['templates']['shipping-services-table'] ); ?>,
							'pl-shipping-services-list': <?php echo json_encode( $data['templates']['shipping-services-list'] ); ?>
						},
						'pl-edit-service-page': {
							'pl-header-section': '',
							'pl-main-page-holder': <?php echo json_encode( $data['templates']['edit-shipping-service'] ); ?>,
							'pl-pricing-policies': <?php echo json_encode( $data['templates']['pricing-policies-list'] ); ?>
						},
						'pl-pricing-policy-modal': <?php echo json_encode( $data['templates']['pricing-policy-modal'] ); ?>,
						'pl-countries-selection-modal': <?php echo json_encode( $data['templates']['countries-selection-modal'] ); ?>,
					}
				}
			);

			Packlink.state.display();

			calculateContentHeight(5);

			/**
			 * Calculates content height.
			 *
			 * Footer can be dynamically hidden or displayed by WooCommerce,
			 * so we have to periodically recalculate content height.
			 *
			 * @param {number} offset
			 */
			function calculateContentHeight(offset) {
				if (typeof offset === 'undefined') {
					offset = 0;
				}

				let content = document.getElementById('pl-page');
				let localOffset = offset + content.offsetTop + 20;

				localOffset += <?php echo esc_attr( Shop_Helper::get_footer_height() ); ?>;

				content.style.height = `calc(100% - ${localOffset}px`;

				setTimeout(calculateContentHeight, 250, offset);
			}

			/**
			 * Hides notifications on the Packlink configuration page.
			 */
			function hideNotifications() {
				let notices = document.querySelectorAll('.notice');
				for (let element of notices) {
					element.remove();
				}

				let updates = document.querySelectorAll('.updated');
				for (let element of updates) {
					element.remove();
				}
			}
		},
		false
	);
</script>
