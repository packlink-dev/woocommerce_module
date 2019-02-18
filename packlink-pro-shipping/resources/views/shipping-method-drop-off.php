<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Packlink\WooCommerce\Components\Checkout\Checkout_Handler;
use Packlink\WooCommerce\Components\Order\Order_Meta_Keys;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;

/** @var \Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod $shipping_method */
/** @var Checkout_Handler $this */

$id_value     = wc()->session->get( Order_Meta_Keys::DROP_OFF_ID, '' );
$button_label = $id_value ? __( 'Change Drop-Off Location', 'packlink-pro-shipping' ) : __( 'Pick Drop-Off Location', 'packlink-pro-shipping' );
$parts        = explode( '_', get_locale() );
$locale       = $parts[0];

$translations = array(
	'pickDropOff'   => __( 'Choose Drop-Off Location', 'packlink-pro-shipping' ),
	'changeDropOff' => __( 'Change Drop-Off Location', 'packlink-pro-shipping' ),
	'dropOffTitle'  => __( 'Package will be delivered to:', 'packlink-pro-shipping' ),
);

$locations = $this->get_drop_off_locations( $shipping_method->getServiceId() );
?>

<script>
    Packlink.checkout.setTranslations(<?php echo json_encode( $translations ); ?>);
    Packlink.checkout.setIsCart(<?php  echo is_cart() ? 'true' : 'false'; ?>);
    Packlink.checkout.setLocations(<?php  echo json_encode( $locations ); ?>);
    Packlink.checkout.setSelectedLocationId(<?php  echo $id_value; ?>);
    Packlink.checkout.setSaveEndpoint('<?php  echo Shop_Helper::get_controller_url( 'Checkout', 'save_selected' ); ?>');
	<?php if ( ! is_cart() ) : ?>
    Packlink.checkout.setDropOffAddress();
	<?php endif; ?>
</script>

<button type="button" id="packlink-drop-off-picker"><?php echo $button_label; ?></button>

<div id="pl-picker-modal">
    <iframe src="http://pro.packlink.fr/index.html?lang=<?php echo $locale; ?>" frameborder="0"
            id="pl-drop-off-iframe">
    </iframe>
    <svg id="pl-picker-modal-close" viewBox="0 0 22 22" xmlns="http://www.w3.org/2000/svg">
        <g fill="none" fill-rule="evenodd">
            <path d="M7.5 7.5l8 7M15.5 7.5l-8 7" stroke="#627482" stroke-linecap="square"/>
        </g>
    </svg>
</div>