<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

use Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException;
use Packlink\BusinessLogic\Http\DTO\CashOnDelivery;
use Packlink\BusinessLogic\Controllers\CashOnDeliveryController as CoreController;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
class Packlink_Cash_On_Delivery_Controller extends Packlink_Base_Controller {

    /**
     * @var CoreController $controller
     */
    protected $controller;

    public function __construct()
    {
        $this->controller = new CoreController();
    }

    public function save_data() {
        $this->return_json( [ 'success' => true]);
    }

    /**
     * @throws QueryFilterInvalidParamException
     */
    public function get_data() {
        $configuration = $this->getAccountConfiguration();
        $configArray = array();

        if ($configuration !== null) {
            $configArray = $configuration->toArray();
        }

        $this->return_json( [ 'paymentMethods' => [],
            'configuration' => $configArray,
        ]);
    }

    /**
     * Retrieves Packlink account configuration and checks if an account exists.
     *
     * @return CashOnDelivery|null
     *
     * @throws QueryFilterInvalidParamException
     */
    private function getAccountConfiguration()
    {
        return $this->controller->getCashOnDeliveryConfiguration();
    }
}