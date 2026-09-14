<?php
/**
 * Tests that a shipment document is only ever served when the storage actually returned a PDF,
 * and that every other outcome is reported with a reason instead of collapsing into a bare false.
 *
 * Regression cover for the label download that answered an empty HTTP 404 on any server where
 * the server-side fetch could not succeed (issue #88).
 *
 * @package Packlink_Pro_Shipping
 */

use Packlink\WooCommerce\Components\Http\Document_Fetcher;

/**
 * Document fetcher with a scripted transport, so the policy can be tested without a network.
 *
 * @package Packlink_Pro_Shipping
 */
class Scripted_Document_Fetcher extends Document_Fetcher {

	/**
	 * Response the scripted transport returns.
	 *
	 * @var array
	 */
	private $response;

	/**
	 * URL the transport was asked for.
	 *
	 * @var string
	 */
	public $requested_url = '';

	/**
	 * Scripted_Document_Fetcher constructor.
	 *
	 * @param array $response Response for the transport to return.
	 */
	public function __construct( array $response ) {
		$this->response = array_merge(
			array(
				'status'       => 200,
				'body'         => '',
				'content_type' => '',
				'error'        => '',
			),
			$response
		);
	}

	/**
	 * Returns the scripted response instead of performing a request.
	 *
	 * @param string $url Document URL.
	 *
	 * @return array
	 */
	protected function request( $url ) {
		$this->requested_url = $url;

		return $this->response;
	}
}

/**
 * Class DocumentFetcherTest
 *
 * @package Packlink_Pro_Shipping
 */
class DocumentFetcherTest extends WP_UnitTestCase {

	/**
	 * A minimal but valid PDF payload.
	 */
	const PDF = "%PDF-1.4\ntrailer<</Root 1 0 R>>\n%%EOF\n";

	/**
	 * The happy path: a PDF comes back and is handed over untouched.
	 */
	public function test_returns_the_body_when_the_storage_returns_a_pdf() {
		$fetcher = new Scripted_Document_Fetcher(
			array(
				'body'         => self::PDF,
				'content_type' => 'application/pdf',
			)
		);

		$this->assertSame( self::PDF, $fetcher->fetch( 'https://api.eu.shipengine.com/v1/downloads/10/abc/label.pdf' ) );
		$this->assertSame( '', $fetcher->get_last_error() );
	}

	/**
	 * A transport failure is what made the download an empty 404: the server could not reach the
	 * storage at all (proxy, CA bundle, allow_url_fopen). It has to be reported, not swallowed.
	 */
	public function test_reports_a_transport_failure() {
		$fetcher = new Scripted_Document_Fetcher(
			array(
				'status' => 0,
				'error'  => 'cURL error 60: SSL certificate problem',
			)
		);

		$this->assertFalse( $fetcher->fetch( 'https://api.eu.shipengine.com/v1/downloads/10/abc/label.pdf' ) );
		$this->assertContainsString( 'SSL certificate problem', $fetcher->get_last_error() );
	}

	/**
	 * A rejected or expired link answers with an error status, which must not be streamed.
	 */
	public function test_reports_a_non_200_status() {
		$fetcher = new Scripted_Document_Fetcher(
			array(
				'status'       => 403,
				'body'         => '<Error><Code>ExpiredToken</Code></Error>',
				'content_type' => 'application/xml',
			)
		);

		$this->assertFalse( $fetcher->fetch( 'https://api.eu.shipengine.com/v1/downloads/10/abc/label.pdf' ) );
		$this->assertContainsString( '403', $fetcher->get_last_error() );
	}

	/**
	 * The storage can answer 200 with an error document. Streaming that as application/pdf is how a
	 * merchant ends up printing "the provided token has expired" instead of a label.
	 */
	public function test_refuses_a_200_response_that_is_not_a_pdf() {
		$fetcher = new Scripted_Document_Fetcher(
			array(
				'body'         => '<?xml version="1.0"?><Error><Code>ExpiredToken</Code></Error>',
				'content_type' => 'application/xml',
			)
		);

		$this->assertFalse( $fetcher->fetch( 'https://api.eu.shipengine.com/v1/downloads/10/abc/label.pdf' ) );
		$this->assertContainsString( 'not a PDF', $fetcher->get_last_error() );
	}

	/**
	 * An HTML error page is the other shape a proxy or WAF returns with status 200.
	 */
	public function test_refuses_an_html_body() {
		$fetcher = new Scripted_Document_Fetcher(
			array(
				'body'         => '<!DOCTYPE html><html><body>Access denied</body></html>',
				'content_type' => 'text/html',
			)
		);

		$this->assertFalse( $fetcher->fetch( 'https://api.eu.shipengine.com/v1/downloads/10/abc/label.pdf' ) );
		$this->assertContainsString( 'not a PDF', $fetcher->get_last_error() );
	}

	/**
	 * An order without a usable link must not reach the transport at all.
	 */
	public function test_does_not_request_an_empty_link() {
		$fetcher = new Scripted_Document_Fetcher( array( 'body' => self::PDF ) );

		$this->assertFalse( $fetcher->fetch( '' ) );
		$this->assertSame( '', $fetcher->requested_url );
	}

	/**
	 * The error of a previous fetch must not leak into the next one.
	 */
	public function test_clears_the_error_between_fetches() {
		$failing = new Scripted_Document_Fetcher( array( 'status' => 500 ) );
		$failing->fetch( 'https://api.eu.shipengine.com/v1/downloads/10/abc/label.pdf' );
		$this->assertNotSame( '', $failing->get_last_error() );

		$succeeding = new Scripted_Document_Fetcher( array( 'body' => self::PDF ) );
		$succeeding->fetch( 'https://api.eu.shipengine.com/v1/downloads/10/abc/label.pdf' );
		$this->assertSame( '', $succeeding->get_last_error() );
	}

	/**
	 * Asserts a substring is present, across the PHPUnit versions this suite runs on.
	 *
	 * @param string $needle Expected substring.
	 * @param string $haystack String to search.
	 */
	private function assertContainsString( $needle, $haystack ) {
		$this->assertTrue(
			false !== strpos( $haystack, $needle ),
			"Expected '$haystack' to contain '$needle'."
		);
	}
}
