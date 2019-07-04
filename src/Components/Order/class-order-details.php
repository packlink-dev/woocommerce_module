<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Order;

use Packlink\BusinessLogic\ShippingMethod\Utility\ShipmentStatus;

/**
 * Class Order_Details
 *
 * @package Packlink\WooCommerce\Components\Models
 */
class Order_Details {

	/**
	 * Shipment status translation map.
	 *
	 * @var array
	 */
	private static $status_translations = array(
		ShipmentStatus::STATUS_PENDING    => 'Pending',
		ShipmentStatus::STATUS_READY      => 'Ready for shipping',
		ShipmentStatus::STATUS_IN_TRANSIT => 'In transit',
		ShipmentStatus::STATUS_ACCEPTED   => 'Processing',
		ShipmentStatus::STATUS_DELIVERED  => 'Delivered',
	);
	/**
	 * Order identifier.
	 *
	 * @var int
	 */
	private $id;
	/**
	 * Packlink order flag.
	 *
	 * @var bool
	 */
	private $packlink_order;
	/**
	 * Carrier image.
	 *
	 * @var string
	 */
	private $carrier_image = '';
	/**
	 * Carrier name.
	 *
	 * @var string
	 */
	private $carrier_name = '';
	/**
	 * Carrier tracking url.
	 *
	 * @var string
	 */
	private $carrier_url = '';
	/**
	 * Carrier tracking codes.
	 *
	 * @var string[]
	 */
	private $carrier_codes = array();
	/**
	 * Shipment status.
	 *
	 * @var string
	 */
	private $status = '';
	/**
	 * Shipment status update time.
	 *
	 * @var string
	 */
	private $status_time = '';
	/**
	 * Reference number.
	 *
	 * @var string
	 */
	private $reference = '';
	/**
	 * Message.
	 *
	 * @var string
	 */
	private $message = '';
	/**
	 * Shipment label.
	 *
	 * @var string
	 */
	private $label = '';
	/**
	 * Packlink shipping price.
	 *
	 * @var float
	 */
	private $packlink_price = 0.0;

	/**
	 * Is being created.
	 *
	 * @var bool
	 */
	private $creating = false;

	/**
	 * Returns order identifier.
	 *
	 * @return int Order identifier.
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Sets order identifier.
	 *
	 * @param int $id Order identifier.
	 */
	public function set_id( $id ) {
		$this->id = $id;
	}

	/**
	 * Returns Packlink order flag.
	 *
	 * @return bool Packlink order flag.
	 */
	public function is_packlink_order() {
		return $this->packlink_order;
	}

	/**
	 * Sets Packlink order flag.
	 *
	 * @param bool $packlink_order Packlink order flag.
	 */
	public function set_packlink_order( $packlink_order ) {
		$this->packlink_order = $packlink_order;
	}

	/**
	 * Returns carrier image.
	 *
	 * @return string Carrier image.
	 */
	public function get_carrier_image() {
		return $this->carrier_image;
	}

	/**
	 * Sets carrier image
	 *
	 * @param string $carrier_image Carrier image.
	 */
	public function set_carrier_image( $carrier_image ) {
		$this->carrier_image = $carrier_image;
	}

	/**
	 * Returns carrier name.
	 *
	 * @return string Carrier name.
	 */
	public function get_carrier_name() {
		return $this->carrier_name;
	}

	/**
	 * Sets carrier name.
	 *
	 * @param string $carrier_name Carrier name.
	 */
	public function set_carrier_name( $carrier_name ) {
		$this->carrier_name = $carrier_name;
	}

	/**
	 * Returns shipment status.
	 *
	 * @return string Shipment status.
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * Sets shipment status.
	 *
	 * @param string $status Shipment status.
	 */
	public function set_status( $status ) {
		$this->status = $status;
	}

	/**
	 * Returns shipment status label.
	 *
	 * @return string Shipment status label.
	 */
	public function get_status_translation() {
		$code = $this->status ?: ShipmentStatus::STATUS_PENDING;
		if ( ! array_key_exists( $code, static::$status_translations ) ) {
			return $code;
		}

		return __( static::$status_translations[ $code ], 'packlink-pro-shipping' );
	}

	/**
	 * Returns status update time.
	 *
	 * @return string Status update time.
	 */
	public function get_status_time() {
		return $this->status_time;
	}

	/**
	 * Sets status update time.
	 *
	 * @param string $status_time Status update time.
	 */
	public function set_status_time( $status_time ) {
		$this->status_time = $status_time;
	}

	/**
	 * Returns reference number.
	 *
	 * @return string Reference number.
	 */
	public function get_reference() {
		return $this->reference;
	}

	/**
	 * Sets reference number.
	 *
	 * @param string $reference Reference number.
	 */
	public function set_reference( $reference ) {
		$this->reference = $reference;
	}

	/**
	 * Returns message.
	 *
	 * @return string Message.
	 */
	public function get_message() {
		return $this->message;
	}

	/**
	 * Sets message.
	 *
	 * @param string $message Message.
	 */
	public function set_message( $message ) {
		$this->message = $message;
	}

	/**
	 * Returns label.
	 *
	 * @return string label.
	 */
	public function get_label() {
		return $this->label;
	}

	/**
	 * Sets label.
	 *
	 * @param string $label Label.
	 */
	public function set_label( $label ) {
		$this->label = $label;
	}

	/**
	 * Returns carrier tracking url.
	 *
	 * @return string Carrier tracking url.
	 */
	public function get_carrier_url() {
		return $this->carrier_url;
	}

	/**
	 * Sets carrier tracking url.
	 *
	 * @param string $carrier_url Carrier tracking url.
	 */
	public function set_carrier_url( $carrier_url ) {
		$this->carrier_url = $carrier_url;
	}

	/**
	 * Returns carrier tracking codes.
	 *
	 * @return array Carrier tracking codes.
	 */
	public function get_carrier_codes() {
		return $this->carrier_codes;
	}

	/**
	 * Sets carrier tracking codes.
	 *
	 * @param array $carrier_codes Carrier tracking codes.
	 */
	public function set_carrier_codes( $carrier_codes ) {
		$this->carrier_codes = $carrier_codes;
	}

	/**
	 * Returns Packlink shipping price.
	 *
	 * @return float Packlink shipping price.
	 */
	public function get_packlink_price() {
		return $this->packlink_price;
	}

	/**
	 * Sets Packlink shipping price.
	 *
	 * @param float $packlink_price Packlink shipping price.
	 */
	public function set_packlink_price( $packlink_price ) {
		$this->packlink_price = $packlink_price;
	}

	/**
	 * Returns last carrier tracking code.
	 *
	 * @return string|null
	 */
	public function get_last_carrier_code() {
		return empty( $this->carrier_codes ) ? null : $this->carrier_codes[0];
	}

	/**
	 * Returns creating.
	 *
	 * @return bool creating.
	 */
	public function is_creating() {
		return $this->creating;
	}

	/**
	 * Sets creating flag.
	 *
	 * @param bool $creating Creating flag.
	 */
	public function set_creating( $creating ) {
		$this->creating = $creating;
	}
}
