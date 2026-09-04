<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Checkout;

use Logeecom\Infrastructure\Logger\Logger;
use Logeecom\Infrastructure\ServiceRegister;
use Packlink\WooCommerce\Components\ShippingMethod\Packlink_Shipping_Method;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Helper;
use WC_Cart;
use WC_Order;
use WC_Tax;

/**
 * Class Ddp_Fee_Handler
 *
 * Charges the duties of a duties-paid shipping option as its own cart fee, and records on the order
 * what was charged.
 *
 * A fee rather than a higher shipping cost, deliberately: it gives the shopper a separate
 * "Delivery Duty Paid" line instead of an unexplained increase, it can carry its own tax treatment,
 * and WooCommerce turns it into an order fee item on checkout - so it reaches the confirmation page,
 * the emails, the invoice and the refund flow without a line of code per surface.
 *
 * @package Packlink\WooCommerce\Components\Checkout
 */
class Ddp_Fee_Handler {

	/**
	 * Adds the duties fee when the customer has chosen a duties-paid shipping option.
	 *
	 * Runs on `woocommerce_cart_calculate_fees`, which WooCommerce fires after it has totalled
	 * shipping (WC_Cart_Totals::calculate() runs shipping before fees), so the chosen rate and the
	 * amount quoted for it are always available here - including on a render served from the cached
	 * rates, where `calculate_shipping()` never ran.
	 *
	 * @param WC_Cart $cart WooCommerce cart.
	 *
	 * @return void
	 */
	public function add_fee( $cart ) {
		try {
			if ( ! $cart instanceof WC_Cart ) {
				return;
			}

			$rate_id = $this->chosen_rate_id();
			if ( '' === $rate_id || ! Ddp_Checkout::is_ddp_rate_id( $rate_id ) ) {
				return;
			}

			$name = $this->fee_name();
			if ( $this->has_fee( $cart, $name ) ) {
				// Totals can be recalculated more than once in a request; the fee must not stack.
				return;
			}

			// A duty absorbed down to zero by a cost adjustment still gets its line, at 0.00: the shopper
			// chose a duties-paid option and the totals have to say the duties were covered rather than
			// leave them unmentioned. Only an unknown amount adds nothing.
			$amount = $this->amount_for_rate( $rate_id );
			if ( null === $amount ) {
				return;
			}

			// A free-shipping coupon or threshold zeroes the shipping line and never touches a cart
			// fee, so duties keep being charged on a free-shipping order without any code here. That is
			// deliberate: duty is a government charge the merchant has to pay either way.
			$cart->add_fee( $name, $amount, $this->is_taxable( $rate_id ), $this->shipping_tax_class() );
		} catch ( \Exception $e ) {
			Logger::logWarning( 'Could not add the duties fee: ' . $e->getMessage(), 'Integration' );
		}
	}

	/**
	 * Records the duty selection and the amount charged on the WooCommerce order.
	 *
	 * Runs after the fee lines exist, so the amount is read back from the order's own fee item: what is
	 * persisted is then exactly what the customer paid, not a re-derived figure.
	 *
	 * `Shop_Order_Service` reads this meta to flag the shipment draft. Skipping it would let a
	 * mandatory-DDP order reach Packlink without the flag, which is refused at purchase with
	 * `400 mandatory_ddp_not_selected`, so this is correctness rather than bookkeeping.
	 *
	 * @param WC_Order|int $order Order or order id, depending on the hook.
	 *
	 * @return void
	 */
	public function persist_on_order( $order ) {
		try {
			$wc_order = $order instanceof WC_Order ? $order : \WC_Order_Factory::get_order( $order );
			if ( ! $wc_order instanceof WC_Order ) {
				return;
			}

			$rate_id = $this->order_rate_id( $wc_order );
			if ( '' === $rate_id || ! Ddp_Checkout::is_ddp_rate_id( $rate_id ) ) {
				return;
			}

			$amount = $this->fee_total( $wc_order );
			if ( null === $amount ) {
				// The chosen rate is the duties-paid variant, so duties were selected; a missing fee line
				// only means they cost nothing, because the merchant absorbed them with a cost adjustment.
				// The flag still has to be recorded: without it a mandatory-DDP draft is refused at
				// purchase with 400 mandatory_ddp_not_selected.
				$amount = 0.0;
			}

			$wc_order->update_meta_data( Ddp_Checkout::META_SELECTED, 'yes' );
			$wc_order->update_meta_data( Ddp_Checkout::META_COST, $amount );
			$wc_order->update_meta_data( Ddp_Checkout::META_CURRENCY, $wc_order->get_currency() );

			// Recorded only when the rate carried one: the draft distinguishes "no carrier price known"
			// from a zero, and writing 0.0 would have it declare a shipment with no transport at all.
			$porterage = $this->porterage_from_rate_meta( $rate_id );
			if ( null !== $porterage ) {
				$wc_order->update_meta_data( Ddp_Checkout::META_PORTERAGE, $porterage );
			}

			$wc_order->save();
		} catch ( \Exception $e ) {
			Logger::logWarning( 'Could not record the duties charged on the order: ' . $e->getMessage(), 'Integration' );
		}
	}

	/**
	 * Translated fee name, used both to add the fee and to find it again on the order.
	 *
	 * @return string
	 */
	private function fee_name() {
		return __( 'Delivery Duty Paid', 'packlink-pro-shipping' );
	}

	/**
	 * The shipping rate the customer has chosen.
	 *
	 * @return string Rate id, or an empty string when none is chosen.
	 */
	private function chosen_rate_id() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return '';
		}

		$chosen = WC()->session->get( 'chosen_shipping_methods' );

		return is_array( $chosen ) && ! empty( $chosen ) ? (string) reset( $chosen ) : '';
	}

	/**
	 * The shipping rate recorded on an order.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 *
	 * @return string Rate id, or an empty string when the order has no Packlink shipping line.
	 */
	private function order_rate_id( WC_Order $wc_order ) {
		foreach ( $wc_order->get_shipping_methods() as $item ) {
			$data        = $item->get_data();
			$method_id   = isset( $data['method_id'] ) ? $data['method_id'] : '';
			$instance_id = isset( $data['instance_id'] ) ? $data['instance_id'] : '';

			if ( Packlink_Shipping_Method::PACKLINK_SHIPPING_METHOD !== $method_id ) {
				continue;
			}

			// WooCommerce splits the rate id into method and instance on the order line and drops the
			// suffix, so the chosen variant is recovered from the session instead.
			$chosen = $this->chosen_rate_id();
			if ( '' !== $chosen && (int) $instance_id === Ddp_Checkout::instance_id( $chosen ) ) {
				return $chosen;
			}
		}

		return '';
	}

	/**
	 * Whether the cart already carries the duties fee.
	 *
	 * @param WC_Cart $cart WooCommerce cart.
	 * @param string  $name Fee name.
	 *
	 * @return bool
	 */
	private function has_fee( WC_Cart $cart, $name ) {
		foreach ( $cart->get_fees() as $fee ) {
			if ( isset( $fee->name ) && $fee->name === $name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Total of the duties fee line on an order.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 *
	 * @return float|null Fee total, or null when the order carries no duties fee.
	 */
	private function fee_total( WC_Order $wc_order ) {
		$name = $this->fee_name();

		foreach ( $wc_order->get_items( 'fee' ) as $item ) {
			if ( $item->get_name() === $name ) {
				return (float) $item->get_total();
			}
		}

		return null;
	}

	/**
	 * Duty amount quoted for a rate.
	 *
	 * Read from the rate's own meta first, because that is the one place the amount survives
	 * WooCommerce serving a render from its cached rates. The session-cached quote is the fallback, and
	 * the fetching accessor is deliberately never used: a fee callback must not spend two Packlink
	 * requests and permanently create a customs invoice.
	 *
	 * @param string $rate_id Chosen rate id.
	 *
	 * @return float|null Charged duty amount, or null when none is known.
	 */
	private function amount_for_rate( $rate_id ) {
		$amount = $this->amount_from_rate_meta( $rate_id );
		if ( null !== $amount ) {
			return $amount;
		}

		return $this->amount_from_cache( $rate_id );
	}

	/**
	 * Reads the quoted amount off the cached shipping rate.
	 *
	 * @param string $rate_id Chosen rate id.
	 *
	 * @return float|null
	 */
	private function porterage_from_rate_meta( $rate_id ) {
		foreach ( $this->cached_rates() as $rates ) {
			if ( ! isset( $rates[ $rate_id ] ) ) {
				continue;
			}

			$rate = $rates[ $rate_id ];
			if ( ! method_exists( $rate, 'get_meta_data' ) ) {
				continue;
			}

			$meta = $rate->get_meta_data();
			if ( isset( $meta[ Ddp_Checkout::RATE_META_PORTERAGE ] )
				&& '' !== $meta[ Ddp_Checkout::RATE_META_PORTERAGE ]
				&& null !== $meta[ Ddp_Checkout::RATE_META_PORTERAGE ] ) {
				$porterage = (float) $meta[ Ddp_Checkout::RATE_META_PORTERAGE ];

				return $porterage > 0.0 ? $porterage : null;
			}
		}

		return null;
	}

	/**
	 * Reads the quoted amount off the cached shipping rate.
	 *
	 * @param string $rate_id Chosen rate id.
	 *
	 * @return float|null
	 */
	private function amount_from_rate_meta( $rate_id ) {
		foreach ( $this->cached_rates() as $rates ) {
			if ( ! isset( $rates[ $rate_id ] ) ) {
				continue;
			}

			$rate = $rates[ $rate_id ];
			if ( ! method_exists( $rate, 'get_meta_data' ) ) {
				continue;
			}

			$meta = $rate->get_meta_data();
			if ( isset( $meta[ Ddp_Checkout::RATE_META_AMOUNT ] ) ) {
				return (float) $meta[ Ddp_Checkout::RATE_META_AMOUNT ];
			}
		}

		return null;
	}

	/**
	 * Rate sets WooCommerce has stored for this request's packages.
	 *
	 * @return array[] Arrays of WC_Shipping_Rate keyed by rate id.
	 */
	private function cached_rates() {
		$sets = array();

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return $sets;
		}

		$packages = WC()->shipping() ? WC()->shipping()->get_packages() : array();
		$count    = max( 1, count( $packages ) );

		for ( $index = 0; $index < $count; $index++ ) {
			$stored = WC()->session->get( 'shipping_for_package_' . $index );
			if ( is_array( $stored ) && isset( $stored['rates'] ) && is_array( $stored['rates'] ) ) {
				$sets[] = $stored['rates'];
			}
		}

		return $sets;
	}

	/**
	 * Falls back to the quote cached by the rate path for this cart.
	 *
	 * @param string $rate_id Chosen rate id.
	 *
	 * @return float|null
	 */
	private function amount_from_cache( $rate_id ) {
		$method = Shipping_Method_Helper::get_packlink_shipping_method( Ddp_Checkout::instance_id( $rate_id ) );
		if ( null === $method ) {
			return null;
		}

		$packages = ( function_exists( 'WC' ) && WC()->shipping() ) ? WC()->shipping()->get_packages() : array();
		if ( empty( $packages ) ) {
			return null;
		}

		/** @var Ddp_Checkout_Service $service */ // phpcs:ignore
		$service = ServiceRegister::getService( Ddp_Checkout_Service::CLASS_NAME );

		return $service->cached_amount_for_method( $method, reset( $packages ) );
	}

	/**
	 * Whether the duties fee is taxed, following the Tax status of the shipping method instance that
	 * carries it - so the duties line behaves like the transport line it accompanies.
	 *
	 * @param string $rate_id Chosen rate id.
	 *
	 * @return bool
	 */
	private function is_taxable( $rate_id ) {
		$instance_id = Ddp_Checkout::instance_id( $rate_id );
		if ( 0 === $instance_id ) {
			return false;
		}

		$method = new Packlink_Shipping_Method( $instance_id );

		return 'taxable' === $method->tax_status;
	}

	/**
	 * The tax class the duties fee is charged under: the one WooCommerce taxes the shipping line with.
	 *
	 * is_taxable() already carries the shipping method's Tax status onto this fee so that the duties
	 * line behaves like the transport line it accompanies. The class is the other half of that, and it
	 * was missing: add_fee()'s fourth argument defaults to '', which is the STANDARD rate, while
	 * WooCommerce taxes shipping through the woocommerce_shipping_tax_class option. On any store whose
	 * shipping tax class is not the standard one, the duty was taxed at a different rate than the
	 * transport it belongs to - two halves of one shipping charge, taxed differently.
	 *
	 * An explicitly configured class is used as-is. 'inherit' means "whatever the cart's items use",
	 * which WooCommerce resolves in WC_Tax::get_shipping_tax_rates() from the classes present in the
	 * cart, taking the highest-priority one when they differ. That resolution is mirrored here rather
	 * than guessed, because guessing the standard rate is exactly the defect being fixed.
	 *
	 * Every branch that cannot be answered returns '', which is the behaviour this replaces - so a
	 * store whose configuration cannot be resolved is left as it was rather than made differently
	 * wrong.
	 *
	 * @return string Tax class slug; '' for the standard rate.
	 */
	private function shipping_tax_class() {
		$configured = get_option( 'woocommerce_shipping_tax_class', 'inherit' );

		if ( 'inherit' !== $configured ) {
			return (string) $configured;
		}

		if ( ! function_exists( 'WC' ) || null === WC() || ! class_exists( 'WC_Tax' ) ) {
			return '';
		}

		$cart = WC()->cart;
		if ( ! $cart instanceof WC_Cart || ! method_exists( $cart, 'get_cart_item_tax_classes_for_shipping' ) ) {
			return '';
		}

		$found = $cart->get_cart_item_tax_classes_for_shipping();
		if ( empty( $found ) || ! is_array( $found ) ) {
			return '';
		}

		// One class across the cart is the ordinary case, and resolves exactly.
		if ( 1 === count( $found ) ) {
			return (string) reset( $found );
		}

		// Mixed classes: WooCommerce takes the first in its own configured order of precedence.
		foreach ( WC_Tax::get_tax_class_slugs() as $slug ) {
			if ( in_array( $slug, $found, true ) ) {
				return (string) $slug;
			}
		}

		return '';
	}
}
