<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array $location */

?>

<section class="woocommerce-columns woocommerce-columns--2 woocommerce-columns--addresses col2-set addresses">
    <div class="woocommerce-column woocommerce-column--1 woocommerce-column--billing-address col-1">

        <h2 class="woocommerce-column__title"><?php esc_html_e( 'Drop-off address', 'packlink-pro-shipping' ); ?></h2>
        <address>
			<?php echo $location['name']; ?> <br/>
			<?php echo $location['address']; ?> <br/>
			<?php echo $location['zip'] . ', ' . $location['city'] ?> <br/>
        </address>
    </div>
</section>
