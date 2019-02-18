<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\WooCommerce\Components\Services\Config_Service;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;

/** @var \Packlink\WooCommerce\Components\Order\Order_Details $order_details */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var Config_Service $config */
$config  = ServiceRegister::getService( Config_Service::CLASS_NAME );
$country = 'es';
$user    = $config->getUserInfo();
if ( $user && $user->country ) {
	$country = strtolower( $user->country );
}

$url           = "https://pro.packlink.{$country}/private/shipments/{$order_details->get_reference()}";
$carrier_codes = $order_details->get_carrier_codes();

?>

<ul class="order_actions submitbox" xmlns="http://www.w3.org/1999/html">
	<?php if ( $order_details->get_reference() ) : ?>
        <li class="wide">
			<?php if ( $order_details->get_carrier_image() ) : ?>
                <div class="pl-order-detail-section">
                    <h4><?php echo __( 'Carrier', 'packlink-pro-shipping' ); ?></h4>
                    <img class="pl-carrier-image" src="<?php echo $order_details->get_carrier_image(); ?>"
                         alt="carrier image"/>
                    <span><?php echo $order_details->get_carrier_name(); ?></span>

					<?php if ( ! empty( $carrier_codes ) ) : ?>
                        <dl>
                            <dt><?php echo __( 'Carrier tracking codes:', 'packlink-pro-shipping' ); ?></dt>
							<?php foreach ( $carrier_codes as $carrier_code ): ?>
                                <dd><?php echo $carrier_code; ?></dd>
							<?php endforeach; ?>
                        </dl>
					<?php endif; ?>

					<?php if ( $order_details->get_carrier_url() ) : ?>
                        <a href="<?php echo $order_details->get_carrier_url(); ?>" target="_blank">
                            <button type="button" class="button pl-button-view pl-carrier-button"
                                    name="view on packlink" value="View">
								<?php echo __( 'Track it!', 'packlink-pro-shipping' ); ?>
                            </button>
                        </a>
                        <br/>
					<?php endif; ?>
                </div>
			<?php endif; ?>

            <div class="pl-order-detail-section">
                <h4><?php echo __( 'Status', 'packlink-pro-shipping' ); ?></h4>
                <span class="pl-timestamp"><?php echo $order_details->get_status_time(); ?> <b><?php echo $order_details->get_status(); ?></b></span>
            </div>

            <div class="pl-order-detail-section">
                <h4><?php echo __( 'Reference number', 'packlink-pro-shipping' ); ?></h4>
                <span><?php echo $order_details->get_reference(); ?></span>
            </div>

			<?php if ( $order_details->get_packlink_price() > 0 ) : ?>
                <div class="pl-order-detail-section">
                    <h4><?php echo __( 'Packlink shipping price', 'packlink-pro-shipping' ); ?></h4>
					<?php echo \wc_price( $order_details->get_packlink_price() ); ?>
                </div>
			<?php endif; ?>
        </li>

        <li class="wide">
            <a href="<?php echo $url; ?>" target="_blank">
                <button type="button" class="button pl-button-view" name="view on packlink" value="View">
					<?php echo __( 'View on Packlink PRO', 'packlink-pro-shipping' ); ?>
                </button>
            </a>

			<?php if ( $order_details->get_label() ) : ?>
                <a href="<?php echo $order_details->get_label(); ?>" target="_blank">
                    <button type="button" class="button button-primary" name="print label" value="Print">
						<?php echo __( 'Print label', 'packlink-pro-shipping' ); ?>
                    </button>
                </a>
			<?php endif; ?>
        </li>
	<?php elseif ( ! $order_details->is_packlink_order() || ! $order_details->is_creating() ) : ?>
        <li class="wide">
            <div class="pl-order-detail-section pl-create-draft">

				<?php if ( 'failed' === $order_details->get_status() ) : ?>
                    <span><?php echo sprintf( __( 'Previous attempt to create a draft failed. Error: %s', 'packlink-pro-shipping' ), $order_details->get_message() ); ?></span>
                    <br/>
				<?php endif; ?>

                <input type="hidden" id="pl-create-endpoint"
                       value="<?php echo Shop_Helper::get_controller_url( 'Order_Details', 'create_draft' ); ?>"/>
                <button type="button" class="button button-primary" id="pl-create-draft"
                        value="<?php echo $order_details->get_id(); ?>">
					<?php echo __( 'Create draft', 'packlink-pro-shipping' ); ?>
                </button>
            </div>
        </li>
	<?php else : ?>
        <li class="wide">
            <div class="pl-order-detail-section pl-create-draft">
                <span><?php echo __( 'Draft is currently being created in Packlink', 'packlink-pro-shipping' ); ?></span>
            </div>
        </li>
	<?php endif; ?>
</ul>
