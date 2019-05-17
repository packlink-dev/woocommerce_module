<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\WooCommerce\Components\Checkout\Checkout_Handler;
use Packlink\WooCommerce\Components\Order\Order_Meta_Keys;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;

/**
 * Shipping method model.
 *
 * @var ShippingMethod $shipping_method
 */
/**
 * Checkout handler.
 *
 * @var Checkout_Handler $this
 */

$id_value     = wc()->session->get( Order_Meta_Keys::DROP_OFF_ID, '' );
$button_label = $id_value ? __( 'Change Drop-Off Location', 'packlink-pro-shipping' ) : __( 'Select Drop-Off Location', 'packlink-pro-shipping' );
$parts        = explode( '_', get_locale() );
$locale       = $parts[0];

$translations = array(
	'pickDropOff'   => __( 'Select Drop-Off Location', 'packlink-pro-shipping' ),
	'changeDropOff' => __( 'Change Drop-Off Location', 'packlink-pro-shipping' ),
	'dropOffTitle'  => __( 'Package will be delivered to:', 'packlink-pro-shipping' ),
);

$locations = $this->get_drop_off_locations( $shipping_method->getId() );
?>

<script style="display: none;">
	Packlink.checkout.setLocale('<?php echo $locale; ?>');
	Packlink.checkout.setTranslations(<?php echo wp_json_encode( $translations ); ?>);
	Packlink.checkout.setIsCart(<?php echo is_cart() ? 'true' : 'false'; ?>);
	Packlink.checkout.setLocations(<?php echo wp_json_encode( $locations ); ?>);
	Packlink.checkout.setSelectedLocationId(<?php echo $id_value; ?>);
	Packlink.checkout.setSaveEndpoint('<?php echo Shop_Helper::get_controller_url( 'Checkout', 'save_selected' ); ?>');
	<?php if ( ! is_cart() ) : ?>
	Packlink.checkout.setDropOffAddress();
	<?php endif; ?>
</script>

<?php if ( ! is_cart() ) : ?>
<button type="button" id="packlink-drop-off-picker"><?php echo $button_label; ?></button>

<div id="pl-picker-modal" style="display: none;">
	<location-picker>
		<div class="lp-content" data-lp-id="content">
			<div class="lp-locations">
				<div class="lp-input-wrapper">
					<div class="input">
						<input type="text" data-lp-id="search-box" required="required" title=""/>
						<span class="label" data-lp-id="search-box-label"></span>
					</div>
				</div>

				<div data-lp-id="locations"></div>
			</div>
		</div>
	</location-picker>

	<svg id="pl-picker-modal-close" viewBox="0 0 22 22" xmlns="http://www.w3.org/2000/svg">
		<g fill="none" fill-rule="evenodd">
			<path d="M7.5 7.5l8 7M15.5 7.5l-8 7" stroke="#627482" stroke-linecap="square"/>
		</g>
	</svg>
</div>

<location-picker-template>
	<div class="lp-template" id="template-container">
		<div data-lp-id="working-hours-template" class="lp-hour-wrapper">
			<div class="day" data-lp-id="day">
			</div>
			<div class="hours" data-lp-id="hours">
			</div>
		</div>

		<div class="lp-location-wrapper" data-lp-id="location-template">
			<div class="radio-button lp-collapse">
				<div class="lp-radio"></div>
			</div>
			<div class="composite lp-expand">
				<div class="street-name uppercase" data-lp-id="composite-address"></div>
				<div class="lp-working-hours-btn excluded" data-lp-composite data-lp-id="show-composite-working-hours-btn"></div>
				<div data-lp-id="composite-working-hours" class="lp-working-hours">

				</div>
                <div class="lp-select-column">
                    <div class="lp-select-button excluded" data-lp-id="composite-select-btn"></div>
                    <a class="excluded" href="#" data-lp-id="composite-show-on-map" target="_blank"></a>
                </div>
			</div>
			<div class="name uppercase lp-collapse" data-lp-id="location-name"></div>
			<div class="street lp-collapse">
				<div class="street-name uppercase" data-lp-id="location-street"></div>
				<div class="lp-working-hours-btn excluded" data-lp-id="show-working-hours-btn"></div>
				<div data-lp-id="working-hours" class="lp-working-hours">

				</div>
			</div>
			<div class="city uppercase lp-collapse" data-lp-id="location-city">
			</div>
            <div class="lp-select-column lp-collapse">
				<div class="lp-select-button excluded" data-lp-id="select-btn"></div>
			</div>
            <a class="excluded lp-collapse" href="#" data-lp-id="show-on-map" target="_blank">
				<div class="lp-show-on-map-btn excluded"></div>
			</a>
		</div>
	</div>
</location-picker-template>
<?php endif; ?>
