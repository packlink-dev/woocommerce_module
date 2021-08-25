<?php

namespace Packlink\WooCommerce\Controllers;

use Logeecom\Infrastructure\Configuration\Configuration;
use Logeecom\Infrastructure\ServiceRegister;

/**
 * Class Packlink_Support_Controller
 *
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Support_Controller extends Packlink_Base_Controller {
    /**
     * @var Configuration
     */
    private $config_service;

    /**
     * Retrieves async process request timeout value.
     */
    public function get() {
        $this->return_json([
            'ASYNC_PROCESS_TIMEOUT' => $this->get_config_service()->getAsyncRequestTimeout(),
        ]);
    }

    /**
     * Sets async process request timeout value.
     */
    public function set() {
        $body = json_decode($this->get_raw_input(), true);

        if (!isset($body['asyncProcessTimeout']) || !is_int($body['asyncProcessTimeout'])) {
            $this->return_json(['success' => false]);
        }

        $this->get_config_service()->setAsyncRequestTimeout($body['asyncProcessTimeout']);

        $this->return_json(['success' => true]);
    }

    /**
     * @return Configuration|object
     */
    private function get_config_service()
    {
        if ($this->config_service === null) {
            $this->config_service = ServiceRegister::getService(Configuration::CLASS_NAME);
        }

        return $this->config_service;
    }
}