<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Order;

/**
 * Interface Order_Meta_Keys
 *
 * @package Packlink\WooCommerce\Components\Order
 */
interface Order_Meta_Keys {
	const IS_PACKLINK = '_is_packlink_shipment';

	const LABELS        = '_packlink_shipment_labels';
	const LABEL_PRINTED = '_packlink_label_printed';

	const DROP_OFF_ID    = '_packlink_drop_off_point_id';
	const DROP_OFF_EXTRA = '_packlink_drop_off_extra';

	const TRACKING_HISTORY = '_packlink_tracking_history';

	const SHIPMENT_REFERENCE     = '_packlink_shipment_reference';
	const SHIPMENT_STATUS        = '_packlink_shipment_status';
	const SHIPMENT_STATUS_TIME   = '_packlink_shipment_status_update_time';
	const SHIPMENT_PRICE         = '_packlink_shipment_price';
	const CARRIER_TRACKING_CODES = '_packlink_carrier_tracking_code';
	const CARRIER_TRACKING_URL   = '_packlink_carrier_tracking_url';

	const SHIPPING_ID = '_packlink_shipping_method_id';

	const SEND_DRAFT_TASK_ID = '_packlink_send_draft_task_id';
}
