<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

?>
<div class="notice notice-error" style="position: relative;">
	<p><strong><?php esc_html_e( 'Packlink PRO Shipping', 'packlink-pro-shipping' ); ?>:</strong>
		<?php echo get_transient( 'packlink-pro-error-messages' ); // phpcs:ignore ?>
	</p>
</div>
