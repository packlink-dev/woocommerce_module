<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Document_Fetcher
 *
 * Downloads a shipment document (a label today, a customs invoice tomorrow) from Packlink's
 * storage, on the merchant's server.
 *
 * That last part is the whole reason this class exists. Until the same-origin proxy was added, the
 * document link went straight into an `<a href>` and the merchant's *browser* fetched the PDF. A
 * browser has a maintained CA store, a real User-Agent and the user's own network path. A shared
 * host frequently has none of those, and the first implementation of the proxy fetched with
 * `file_get_contents()`, which sees none of what WordPress knows about the site's outbound HTTP:
 * the proxy constants, the CA bundle WordPress ships, per-site timeouts, and whatever a host or
 * plugin adds through `http_request_args`. On such a server it simply returns `false` - so a label
 * that had downloaded for months became an empty HTTP 404 with nothing in the log, while the same
 * URL pasted into the browser still worked.
 *
 * `wp_safe_remote_get()` is used rather than `wp_remote_get()`: the URL arrives in an API response
 * and is then fetched by the server, which is textbook SSRF surface. A site that genuinely needs an
 * internal host can open it with the `http_request_host_is_external` filter.
 *
 * The payload is checked as well as the transport. An expired or rejected link is answered with an
 * error document rather than a transport failure, and streaming that back as `application/pdf` is
 * how a merchant ends up printing "the provided token has expired" on a sheet of paper.
 *
 * @package Packlink\WooCommerce\Components\Http
 */
class Document_Fetcher {

	/**
	 * Every PDF starts with this.
	 */
	const PDF_SIGNATURE = '%PDF-';

	/**
	 * Seconds to wait for the document. Generous: labels are small, but the storage is a third
	 * party and a slow answer still beats a failed download.
	 */
	const TIMEOUT = 30;

	/**
	 * Why the last fetch failed, for logging. Empty when it succeeded.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Fetches a document and returns its bytes, or false when nothing usable came back.
	 *
	 * @param string $url Document URL.
	 *
	 * @return string|false Raw PDF bytes, or false on failure.
	 */
	public function fetch( $url ) {
		$this->last_error = '';

		if ( empty( $url ) ) {
			$this->last_error = 'no document link to fetch';

			return false;
		}

		$response = $this->request( $url );

		if ( '' !== $response['error'] ) {
			$this->last_error = 'request failed: ' . $response['error'];

			return false;
		}

		if ( 200 !== $response['status'] ) {
			$this->last_error = 'storage answered HTTP ' . $response['status']
				. ' (' . $this->preview( $response['body'] ) . ')';

			return false;
		}

		if ( 0 !== strpos( $response['body'], self::PDF_SIGNATURE ) ) {
			$this->last_error = sprintf(
				'response is not a PDF: content type "%s", %d bytes, starting with "%s"',
				$response['content_type'],
				strlen( $response['body'] ),
				$this->preview( $response['body'] )
			);

			return false;
		}

		return $response['body'];
	}

	/**
	 * Returns why the last fetch failed. Empty string when it succeeded.
	 *
	 * @return string
	 */
	public function get_last_error() {
		return $this->last_error;
	}

	/**
	 * Performs the request. The only part of this class that touches WordPress, so the policy
	 * above stays testable without a network.
	 *
	 * @param string $url Document URL.
	 *
	 * @return array Normalised response: status, body, content_type, error.
	 */
	protected function request( $url ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'status'       => 0,
				'body'         => '',
				'content_type' => '',
				'error'        => $response->get_error_message(),
			);
		}

		return array(
			'status'       => (int) wp_remote_retrieve_response_code( $response ),
			'body'         => (string) wp_remote_retrieve_body( $response ),
			'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
			'error'        => '',
		);
	}

	/**
	 * Returns a short, single-line, log-safe excerpt of a response body.
	 *
	 * @param string $body Response body.
	 *
	 * @return string
	 */
	private function preview( $body ) {
		if ( '' === $body ) {
			return 'empty body';
		}

		return trim( preg_replace( '/\s+/', ' ', substr( $body, 0, 80 ) ) );
	}
}
