<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

global $wp_list_table;

$packlink_colspan = $wp_list_table ? $wp_list_table->get_column_count() : 3;

?>
<tr class="plugin-update-tr">
	<td colspan="<?php echo esc_attr( $packlink_colspan ); ?>" class="plugin-update colspanchange">
		<div class="notice inline notice-error notice-alt">
			<p><?php esc_html_e( 'The plugin has been deactivated because it requires WooCommerce. Please install and activate WooCommerce, then activate Packlink PRO Shipping again.', 'packlink-pro-shipping' ); ?></p>
		</div>
	</td>
</tr>
