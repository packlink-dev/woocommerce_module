<?php

namespace Packlink\WooCommerce\Components\Services;

use Packlink\BusinessLogic\Http\DTO\Draft;
use Packlink\BusinessLogic\Order\Objects\Order;
use Packlink\BusinessLogic\Order\OrderService;

class Order_Service extends OrderService {

	/**
	 * Get store unit from woocommerce
	 *
	 * @return string
	 */
	protected function getStoreUnit() {
		return \get_option('woocommerce_weight_unit', 'kg');
	}

	/**
	 * Prepares the draft, then declares the GOODS as its content value rather than the order total.
	 *
	 * Core sets `contentValue` from `$order->getTotalPrice()`, which Shop_Order_Service fills with
	 * `WC_Order::get_total()` - goods PLUS shipping, plus tax, plus the duties fee on a duties-paid
	 * order. That is the shipment's declared value, so over-declaring it prices Packlink's shipment
	 * protection off a figure the contents are not worth and inflates the compensation basis a claim
	 * would be settled against.
	 *
	 * Corrected here rather than in core because the same `getTotalPrice()` also feeds
	 * `addCashOnDeliveryDetails()`, and the COD amount MUST stay the order total - that is what the
	 * customer hands over on delivery. One core value serves two different meanings, so the platform
	 * fixes the one it can see is wrong.
	 *
	 * Summed from the order items WITHOUT multiplying by the quantity, because on this platform
	 * `getTotalPrice()` is already a LINE total - Shop_Order_Service fills it from
	 * `WC_Order_Item::get_total()`, and `getPrice()` from the unit price. The sibling PrestaShop module
	 * fills the same field from `unit_price_tax_incl` and so must multiply. The two look like the same
	 * expression and are not; do not harmonise them.
	 *
	 * Left alone when the sum is not positive: declaring 0.00 would be worse than over-declaring,
	 * because a shipment worth nothing cannot be insured or compensated.
	 *
	 * The duty is untouched by this. Packlink prices it from the customs invoice's own per-item values
	 * plus the carrier's porterage, not from `contentValue`.
	 *
	 * @param Order $order Checkout order.
	 *
	 * @return Draft Prepared shipment draft.
	 *
	 * @throws \Packlink\BusinessLogic\Order\Exceptions\EmptyOrderException When order has no items.
	 */
	public function prepareDraft( Order $order ) {
		$draft = parent::prepareDraft( $order );

		$goods = 0.0;

		foreach ( $order->getItems() as $item ) {
			$goods += (float) $item->getTotalPrice();
		}

		if ( $goods > 0.0 ) {
			$draft->contentValue = round( $goods, 2 );
		}

		return $draft;
	}
}