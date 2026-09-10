<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Services;

use Packlink\BusinessLogic\DDP\DdpCostService;
use Packlink\BusinessLogic\Http\Proxy;
use WP_Http_Proxy;

/**
 * Class Ddp_Cost_Service
 *
 * The core duty lookup, with one WordPress-specific decision layered on: whether its concurrent
 * transport may be used on this site.
 *
 * The core prices several carriers at once by driving `curl_multi` directly. It has to - the shared
 * HttpClient every integration implements has no concurrent primitive, so there is nothing to fan N
 * requests out through. On WordPress that means skipping `wp_remote_request()`, which is the only
 * place a site's outbound HTTP configuration lives: the proxy constants, the CA bundle WordPress
 * ships, per-site timeouts, and whatever a host or plugin adds through `http_request_args`.
 *
 * Skipping it on a site behind an outbound proxy means the requests never leave the server. Every
 * lookup errors, the failure is cached, and the shopper simply sees no duties option - one warning in
 * the log and nothing visible, which reads like misconfiguration rather than a network failure. That
 * silence is what makes it worth guarding.
 *
 * So concurrency is used unless this site actually has a proxy that Packlink's host would go through.
 * That keeps the fast path on the large majority of installs, and falls back to `wp_remote_*` exactly
 * where curl would fail. The fallback runs the same two waves one request at a time and produces
 * identical amounts - only the wall time changes.
 *
 * Deliberately narrower than "is a proxy defined": `WP_PROXY_BYPASS_HOSTS` may exclude the API host,
 * and on such a site curl is the right choice.
 *
 * @package Packlink\WooCommerce\Components\Services
 */
class Ddp_Cost_Service extends DdpCostService {

	/**
	 * Fully qualified name of this class.
	 */
	const CLASS_NAME = __CLASS__;

	/**
	 * Whether the concurrent transport may be used on this site.
	 *
	 * @return bool
	 */
	protected function supportsConcurrentRequests() {
		// The extension has to be there at all; the core answers that.
		if ( ! parent::supportsConcurrentRequests() ) {
			return false;
		}

		// Not a WordPress request - a CLI task or a test - so there is no site policy to respect.
		if ( ! class_exists( 'WP_Http_Proxy' ) ) {
			return true;
		}

		$proxy = new WP_Http_Proxy();

		if ( ! $proxy->is_enabled() ) {
			return true;
		}

		// A proxy IS configured. Concurrency is still fine if this host is on the bypass list, because
		// wp_remote_* would not have used the proxy for it either.
		return ! $proxy->send_through_proxy( Proxy::BASE_URL );
	}
}
