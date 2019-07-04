<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Utility;

use Logeecom\Infrastructure\Logger\Logger;
use Packlink\WooCommerce\Components\Bootstrap_Component;
use wpdb;

/**
 * Class Database
 *
 * @package Packlink\WooCommerce\Components\Utility
 */
class Database {
	const BASE_TABLE = 'packlink_entity';

	/**
	 * WordPress database session.
	 *
	 * @var wpdb
	 */
	private $db;

	/**
	 * Database constructor.
	 *
	 * @param wpdb $db Database session.
	 */
	public function __construct( $db ) {
		$this->db = $db;
	}

	/**
	 * Checks if plugin was already installed and initialized.
	 *
	 * @return bool
	 */
	public function plugin_already_initialized() {
		$table_name = $this->db->prefix . self::BASE_TABLE;

		return $this->db->get_var( "SHOW TABLES LIKE '" . $table_name . "'" ) === $table_name;
	}

	/**
	 * Executes installation scripts.
	 */
	public function install() {
		$queries = $this->prepare_queries_for_install();
		foreach ( $queries as $query ) {
			$this->db->query( $query );
		}
	}

	/**
	 * Executes uninstall scripts.
	 */
	public function uninstall() {
		$queries = $this->prepare_queries_for_deleting();
		foreach ( $queries as $query ) {
			$this->db->query( $query );
		}
	}

	/**
	 * Executes update database functions.
	 *
	 * @param Version_File_Reader $version_file_reader Version file reader.
	 *
	 * @return bool
	 */
	public function update( $version_file_reader ) {
		while ( $version_file_reader->has_next() ) {
			$statements = $version_file_reader->read_next();
			foreach ( $statements as $statement ) {
				try {
					$this->db->query( $statement );
				} catch ( \Exception $ex ) {
					Bootstrap_Component::init();
					Logger::logInfo( $ex->getMessage(), 'Database Update' );
					Logger::logInfo( $statement, 'SQL' );

					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Prepares database queries for inserting tables.
	 *
	 * @return array
	 */
	private function prepare_queries_for_install() {
		$table_name = $this->db->prefix . self::BASE_TABLE;

		$queries   = array();
		$queries[] = 'CREATE TABLE IF NOT EXISTS `' . $table_name . '` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `type` VARCHAR(127),
            `index_1` VARCHAR(127),
            `index_2` VARCHAR(127),
            `index_3` VARCHAR(127),
            `index_4` VARCHAR(127),
            `index_5` VARCHAR(127),
            `index_6` VARCHAR(127),
            `index_7` VARCHAR(127),
            `data` LONGTEXT,
            PRIMARY KEY (`id`)
        )';

		return $queries;
	}

	/**
	 * Preparing database queries for dropping tables and removing meta data.
	 *
	 * @return array
	 */
	private function prepare_queries_for_deleting() {
		$table_name = $this->db->prefix . self::BASE_TABLE;

		$queries   = array();
		$queries[] = 'DROP TABLE IF EXISTS ' . $table_name;

		$post_meta_table = $this->db->postmeta;
		$queries[]       = "DELETE FROM `$post_meta_table` WHERE `meta_key` LIKE \"%_packlink_%\"";

		return $queries;
	}
}
