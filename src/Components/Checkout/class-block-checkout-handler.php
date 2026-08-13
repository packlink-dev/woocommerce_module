<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Checkout;

use Logeecom\Infrastructure\Logger\Logger;
use Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException;
use Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException;
use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\ORM\Utility\IndexHelper;
use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\CashOnDelivery\Services\OfflinePaymentsServices;
use Packlink\BusinessLogic\Location\LocationService;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\WooCommerce\Components\Order\Order_Drop_Off_Map;
use Packlink\WooCommerce\Components\Services\Offline_Payments_Service;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Helper;
use Packlink\WooCommerce\Components\Utility\Script_Loader;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;
use WC_Order;

/**
 * Class Block_Checkout_Handler
 *
 * @package Packlink\WooCommerce\Components\Checkout
 */
class Block_Checkout_Handler {

    /**
     * @var Offline_Payments_Service
     */
    private $offline_payments_service;

    /**
     * Drop-off locations already fetched during this request, keyed by Packlink shipping method id.
     *
     * A DDP-capable method contributes two rate ids to one payload - the plain rate and its `:ddp`
     * variant - and both resolve to the same Packlink method. Without this memo the same drop-off
     * lookup would be sent to Packlink twice on every checkout render.
     *
     * @var array
     */
    private $drop_off_locations = array();

    public function __construct()
    {
        $this->offline_payments_service = ServiceRegister::getService(
            OfflinePaymentsServices::CLASS_NAME);
    }

    /**
	 * Returns method details for all shipping rates rendered on checkout, keyed by rate id.
	 *
	 * The key is the rate id and not the shipping-method instance id, because a DDP-capable method
	 * renders two rows out of a single instance - `packlink_shipping_method:12` and
	 * `packlink_shipping_method:12:ddp` - so the instance id no longer identifies a row. Every key is
	 * therefore exactly the `value` of that row's radio input, which is what
	 * `resources/js/packlink-block-checkout.js` selects on. `selected_shipping_method` likewise carries
	 * the chosen rate id rather than the instance id it used to.
	 *
	 * @param array $payload - Shipping rate IDs rendered on checkout.
	 *
	 * @return array
	 *
	 * @throws QueryFilterInvalidParamException
	 * @throws RepositoryNotRegisteredException
	 */
	public function initialize( array $payload ) {
		$response = [
			'translations'                  => $this->get_checkout_translations(),
			'selected_shipping_method'      => $selected_rate_id = $this->get_selected_rate_id(),
			'selected_drop_off_id'          => $this->get_selected_drop_off_id(),
			'method_details'                => [],
			'no_drop_off_locations_message' => __( 'There are no drop-off locations available for the entered address', 'packlink-pro-shipping' )
		];

        $parts = explode('_', determine_locale());
        $locale       = $parts[0];

        $response['locale'] = $locale;

        try{
            $offlinePayments = $this->offline_payments_service->getOfflinePayments();
            $accountConfig = $this->offline_payments_service->getAccountConfiguration();
            $offlinePaymentName = null;

            if($accountConfig && $accountConfig->account) {
                $id = $accountConfig->account->getOfflinePaymentMethod();

                foreach ($offlinePayments as $payment) {
                    if ($payment['name'] === $id) {
                        $offlinePaymentName = $payment['displayName'];
                        break;
                    }
                }
            }
            if ($offlinePaymentName !== null) {
                $response['offline_payment_name'] = $offlinePaymentName;
            }
        } catch (\Exception $e) {
        }

        $cart = WC()->cart;
        $totals = $cart->get_totals();

        $subtotal   = isset($totals['cart_contents_total']) ? (float) $totals['cart_contents_total'] : 0;
        $shipping   = isset($totals['shipping_total']) ? (float) $totals['shipping_total'] : 0;
        $discount   = isset($totals['discount_total']) ? (float) $totals['discount_total'] : 0;

        $current_total = $subtotal + $shipping - $discount;


        if ( ! count( $payload ) ) {
			$response['method_details'][ $selected_rate_id ] = $this->get_shipping_method_details( $selected_rate_id, $current_total );

			return $response;
		}

		foreach ( $payload as $rate_id ) {
			$response['method_details'][ $rate_id ] = $this->get_shipping_method_details( $rate_id, $current_total );
		}

		return $response;
	}

	/**
	 * Include location picker file.
	 *
	 * @return void
	 */
	public function load_data() {
		if ( Checkout_Helper::is_packlink_checkout() ) {
			Script_Loader::load_js(
				array(
					'js/packlink-block-checkout.js',
                    'js/offline-payments.js',
				), true
			);
			Script_Loader::load_css(
				array(
					'css/packlink-block-checkout.css',
					'css/packlink-location-picker.css',
				)
			);
		}
	}

	/**
     * Renders drop-off for locations
     *
	 * @return void
	 */
    public function render_drop_off_markup() {
        if ( ! Checkout_Helper::is_packlink_checkout() ) {
            return;
        }

        include dirname( __DIR__ ) . '/../resources/views/block-checkout-shipping-method-drop-off.php';
    }

	/**
	 * This hook is used to update drop-off point and order shipping address value.
	 *
	 * @param WC_Order $order
	 *
	 * @throws QueryFilterInvalidParamException
	 * @throws RepositoryNotRegisteredException
	 */
	public function checkout_update_drop_off( WC_Order $order ) {
		// The chosen value is a rate id, and a duties-paid rate id carries a third segment, so the
		// instance id has to be parsed out of it instead of being taken for the whole value.
		$instance_id     = Ddp_Checkout::instance_id( $this->get_selected_rate_id() );
		$shipping_method = Shipping_Method_Helper::get_packlink_shipping_method(
			IndexHelper::castFieldValue( $instance_id, gettype( $instance_id ) )
		);
		if ( ! $shipping_method ) {
			return;
		}

		if ( $shipping_method->isDestinationDropOff() ) {
			$drop_off_id = wc()->session->get( Shipping_Method_Helper::DROP_OFF_ID );
			if ( empty ( $drop_off_id )) {
				wc_add_notice( __( 'Please choose a drop-off location.', 'packlink-pro-shipping' ), 'error' );

				return;
			}

			$order_drop_off_map_repository = RepositoryRegistry::getRepository( Order_Drop_Off_Map::CLASS_NAME );
			$saved_order_drop_off_map      = Shipping_Method_Helper::get_drop_off_map_for_order( $order->get_id() );
			$order_drop_off_map            = $saved_order_drop_off_map ?: new Order_Drop_Off_Map();
			$order_drop_off_map->set_order_id( $order->get_id() );
			$order_drop_off_map->set_drop_off_point_id( $drop_off_id );
			$order_drop_off_map_repository->save( $order_drop_off_map );

			$this->change_order_shipping_address( $order, wc()->session->get( Shipping_Method_Helper::DROP_OFF_EXTRA ) );

			wc()->session->set( Shipping_Method_Helper::DROP_OFF_ID, '' );
		}
	}

	/**
	 * Get details for one rendered shipping rate.
	 *
	 * Takes a rate id and resolves the Packlink method behind it with `Ddp_Checkout::instance_id()`,
	 * which reads the instance out of segment `[1]` and is therefore blind to the `:ddp` suffix - a
	 * duties-paid rate resolves to the very same method as its plain sibling, so the carrier logo, the
	 * drop-off picker and the cash-on-delivery message come out identical on both rows.
	 *
	 * `packlink_is_ddp` is false whenever no duty amount is known, even on a `:ddp` rate id, because the
	 * cart fee falls silent in exactly the same case - a row labelled "Delivery Duty Paid" that charges
	 * no duty would be a promise nothing keeps.
	 *
	 * @param string $rate_id WooCommerce shipping rate id.
	 * @param float  $current_total Cart total the cash-on-delivery fee is calculated against.
	 *
	 * @return array
	 *
	 * @throws QueryFilterInvalidParamException
	 * @throws RepositoryNotRegisteredException
	 */
	private function get_shipping_method_details( $rate_id, $current_total ) {
		$instance_id     = Ddp_Checkout::instance_id( $rate_id );
		$shipping_method = Shipping_Method_Helper::get_packlink_shipping_method(
			IndexHelper::castFieldValue( $instance_id, gettype( $instance_id ) )
		);

		if ( null === $shipping_method ) {
			return [];
		}

		$ddp_total = $this->get_ddp_total( $rate_id, $shipping_method );

		return array(
			'packlink_image_url'          => $shipping_method->getLogoUrl() ?:
				Shop_Helper::get_plugin_base_url() . 'resources/images/box.svg',
			'packlink_show_image'         => $shipping_method->isDisplayLogo(),
			'packlink_is_drop_off'        => $shipping_method->isDestinationDropOff(),
			'packlink_drop_off_locations' => $shipping_method->isDestinationDropOff() ?
				$this->get_drop_off_locations( $shipping_method->getId() ) : [],
            'packlink_cash_on_delivery'   => $this->is_cash_on_delivery_enabled($shipping_method),
            'packlink_cash_on_delivery_fee' => $this->offline_payments_service->calculateFee($shipping_method->getId(), $current_total),
			'packlink_is_ddp'             => null !== $ddp_total,
			'packlink_ddp_suffix'         => __( '- Delivery Duty Paid', 'packlink-pro-shipping' ),
			'packlink_ddp_total'          => null !== $ddp_total ? $this->format_price( $ddp_total ) : '',
		);
	}

	/**
	 * Combined transport-plus-duties price of a duties-paid rate, or null when the row is not a
	 * duties-paid one or no duty amount is known for it.
	 *
	 * Never performs a lookup. This runs on a Store API request on which the shipping-rate path may
	 * never have run at all, and one lookup is two Packlink requests the first of which permanently
	 * creates a customs invoice - so a cache miss simply means the row is presented exactly as it was
	 * before this feature existed.
	 *
	 * @param string         $rate_id WooCommerce shipping rate id.
	 * @param ShippingMethod $shipping_method Packlink shipping method the rate belongs to.
	 *
	 * @return float|null Transport cost plus duties, or null when there is nothing to present.
	 */
	private function get_ddp_total( $rate_id, ShippingMethod $shipping_method ) {
		if ( ! Ddp_Checkout::is_ddp_rate_id( $rate_id ) ) {
			return null;
		}

		$rate = $this->cached_rate( $rate_id );
		if ( null === $rate ) {
			// Without the rate WooCommerce cached there is no transport price to add the duties to, and
			// presenting duties on their own would understate what the shopper is asked to pay.
			return null;
		}

		$amount = $this->ddp_amount( $rate, $shipping_method );
		if ( null === $amount || $amount <= 0 ) {
			return null;
		}

		return (float) $rate->get_cost() + $amount;
	}

	/**
	 * Duty amount quoted for a rate: the rate's own meta first, the session-cached quote as fallback.
	 *
	 * Mirrors `Ddp_Fee_Handler::amount_for_rate()` deliberately, so the figure presented on the row and
	 * the figure charged as a cart fee are read from the same place and cannot disagree. The fetching
	 * accessor `Ddp_Checkout_Service::amount_for_method()` is never called from here.
	 *
	 * @param \WC_Shipping_Rate $rate Rate WooCommerce cached for this request.
	 * @param ShippingMethod    $shipping_method Packlink shipping method the rate belongs to.
	 *
	 * @return float|null
	 */
	private function ddp_amount( $rate, ShippingMethod $shipping_method ) {
		if ( method_exists( $rate, 'get_meta_data' ) ) {
			$meta = $rate->get_meta_data();
			if ( isset( $meta[ Ddp_Checkout::RATE_META_AMOUNT ] ) ) {
				return (float) $meta[ Ddp_Checkout::RATE_META_AMOUNT ];
			}
		}

		return $this->ddp_amount_from_cache( $shipping_method );
	}

	/**
	 * Falls back to the quote the shipping-rate path cached for this cart.
	 *
	 * @param ShippingMethod $shipping_method Packlink shipping method.
	 *
	 * @return float|null
	 */
	private function ddp_amount_from_cache( ShippingMethod $shipping_method ) {
		$packages = ( function_exists( 'WC' ) && WC()->shipping() ) ? WC()->shipping()->get_packages() : array();
		if ( empty( $packages ) ) {
			return null;
		}

		/** @var Ddp_Checkout_Service $service */ // phpcs:ignore
		$service = ServiceRegister::getService( Ddp_Checkout_Service::CLASS_NAME );

		return $service->cached_amount_for_method( $shipping_method, reset( $packages ) );
	}

	/**
	 * The shipping rate WooCommerce has cached for this request under the given rate id.
	 *
	 * WooCommerce stores the calculated rates of every package in the session as
	 * `shipping_for_package_<index> => array( 'package_hash' => ..., 'rates' => array( rate_id => WC_Shipping_Rate ) )`
	 * and serves later renders straight from there without calling `calculate_shipping()` again, so this
	 * is the only place the duty amount is reliably available at presentation time.
	 *
	 * @param string $rate_id WooCommerce shipping rate id.
	 *
	 * @return \WC_Shipping_Rate|null
	 */
	private function cached_rate( $rate_id ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}

		$packages = WC()->shipping() ? WC()->shipping()->get_packages() : array();
		$count    = max( 1, count( $packages ) );

		for ( $index = 0; $index < $count; $index ++ ) {
			$stored = WC()->session->get( 'shipping_for_package_' . $index );
			if ( ! is_array( $stored ) || ! isset( $stored['rates'] ) || ! is_array( $stored['rates'] ) ) {
				continue;
			}

			if ( isset( $stored['rates'][ $rate_id ] ) ) {
				return $stored['rates'][ $rate_id ];
			}
		}

		return null;
	}

	/**
	 * Formats a money amount for display.
	 *
	 * Server-side on purpose: the currency symbol, its position, the decimal and thousand separators and
	 * the number of decimals all live in WooCommerce settings, and money is never formatted in
	 * JavaScript. Tags are stripped and entities decoded because the value travels as JSON and is
	 * written into the DOM as text, where `wc_price()`'s `&euro;` would otherwise show up verbatim.
	 *
	 * @param float $amount Amount to format.
	 *
	 * @return string
	 */
	private function format_price( $amount ) {
		return html_entity_decode(
			wp_strip_all_tags( wc_price( $amount, array( 'currency' => get_woocommerce_currency() ) ) ),
			ENT_QUOTES,
			'UTF-8'
		);
	}

    /**
     * @param ShippingMethod $shippingMethod
     *
     * @return bool
     */
    private function is_cash_on_delivery_enabled($shippingMethod) {
        foreach ($shippingMethod->getShippingServices() as $service) {
            if ($service->cashOnDeliveryConfig && $service->cashOnDeliveryConfig->offered) {
                return true;
            }
        }

        return false;
    }

	/**
	 * Get available locations for drop-off shipping method.
	 *
	 * Memoized per Packlink method for the length of the request: a DDP-capable drop-off method now
	 * contributes two rate ids to one payload and both resolve to the same method, so without the memo
	 * the same lookup would be sent to Packlink twice for a single checkout render.
	 *
	 * @param $method_id
	 *
	 * @return array
	 */
	private function get_drop_off_locations( $method_id ) {
		$key = (string) $method_id;

		if ( array_key_exists( $key, $this->drop_off_locations ) ) {
			return $this->drop_off_locations[ $key ];
		}

		$customer = wc()->session->customer;

		/**
		 * Location service.
		 *
		 * @var LocationService $location_service
		 */
		$location_service = ServiceRegister::getService( LocationService::CLASS_NAME );

		$this->drop_off_locations[ $key ] = $location_service->getLocations(
			$method_id,
			$customer['shipping_country'],
			$customer['shipping_postcode']
		);

		return $this->drop_off_locations[ $key ];
	}

	/**
	 * The full rate id of the shipping option the customer has chosen - `packlink_shipping_method:12`,
	 * or `packlink_shipping_method:12:ddp` for a duties-paid option.
	 *
	 * Returns the rate id where it used to return the instance id: `method_details` is now keyed by rate
	 * id, and the callers that need the instance take it from `Ddp_Checkout::instance_id()`, which reads
	 * segment `[1]` and is therefore blind to the suffix.
	 *
	 * @return string Rate id, or an empty string when no option is chosen.
	 */
	private function get_selected_rate_id() {
		$chosen_shipping_methods = wc()->session->get( 'chosen_shipping_methods', '' );

		if ( empty( $chosen_shipping_methods ) ) {
			return '';
		}

		return (string) reset( $chosen_shipping_methods );
	}

	/**
	 * Returns the saved drop-off location id, but only when it belongs to the
	 * currently chosen shipping method; an empty string otherwise.
	 *
	 * @return string
	 */
	private function get_selected_drop_off_id() {
		$chosen_methods = wc()->session->get( 'chosen_shipping_methods', array() );
		$chosen         = ! empty( $chosen_methods ) ? reset( $chosen_methods ) : '';

		if ( '' === $chosen || wc()->session->get( Shipping_Method_Helper::SHIPPING_ID, '' ) !== $chosen ) {
			return '';
		}

		return (string) wc()->session->get( Shipping_Method_Helper::DROP_OFF_ID, '' );
	}

	/**
	 * Change order shipping address when shipping method is drop-off.
	 *
	 * @param WC_Order $order
	 * @param array    $drop_off_address
	 *
	 * @return void
	 */
	private function change_order_shipping_address( WC_Order $order, array $drop_off_address ) {
		try {
			$order->set_shipping_company( $drop_off_address['name'] );
			$order->set_shipping_city( $drop_off_address['city'] );
			$order->set_shipping_postcode( $drop_off_address['zip'] );
			$order->set_shipping_address_1( $drop_off_address['address'] );
			if (!empty($drop_off_address['state'])) {
				$order->set_shipping_state($drop_off_address['state']);
			}
		} catch ( \WC_Data_Exception $e ) {
			Logger::logError(
				'Unable to substitute delivery address with drop-off location.',
				'Integration',
				$drop_off_address
			);
		}
	}

	/**
	 * All translations needed for checkout.
	 *
	 * @return array
	 */
	private function get_checkout_translations() {
		return [
			'pickDropOff'   => __( 'Select Drop-Off Location', 'packlink-pro-shipping' ),
			'changeDropOff' => __( 'Change Drop-Off Location', 'packlink-pro-shipping' ),
			'dropOffTitle'  => __( 'Package will be delivered to:', 'packlink-pro-shipping' )
		];
	}
}