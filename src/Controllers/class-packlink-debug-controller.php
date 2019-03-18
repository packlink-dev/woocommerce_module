<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Packlink\WooCommerce\Components\Utility\Debug_Helper;

/**
 * Class Packlink_Debug_Controller
 *
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Debug_Controller extends Packlink_Base_Controller {

	const SYSTEM_INFO_FILE_NAME = 'packlink-debug-data.zip';

	/**
	 * Starts download of debug information.
	 */
	public function download() {
		$this->validate( 'no', true );

		$this->return_file( Debug_Helper::get_system_info(), static::SYSTEM_INFO_FILE_NAME );
	}

	/**
	 * Sets file specified by $filePath as response.
	 *
	 * @param string $file_path Temporary file path.
	 * @param string $output_file_name Output file name.
	 */
	private function return_file( $file_path, $output_file_name = '' ) {
		$file_name = '' !== $output_file_name ? $output_file_name : basename( $file_path );

		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename=' . $file_name );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate' );
		header( 'Pragma: public' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		readfile( $file_path );

		status_header( 200 );
		die();
	}
}
