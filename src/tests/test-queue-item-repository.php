<?php

use Logeecom\Infrastructure\Logger\LoggerConfiguration;
use Logeecom\Tests\Infrastructure\ORM\AbstractGenericQueueItemRepositoryTest;
use Packlink\WooCommerce\Components\Repositories\Queue_Item_Repository;
use Packlink\WooCommerce\Components\Utility\Database;


/**
 * Class TestQueueItemRepository
 *
 * @package Packlink_Pro_Shipping
 */
class TestQueueItemRepository extends AbstractGenericQueueItemRepositoryTest {

	/**
	 * @inheritdoc
	 */
	public function setUp() {

		parent::setUp();

		$this->createTestTable();
	}

	/**
	 * Cleanup.
	 *
	 * @inheritdoc
	 */
	protected function tearDown() {
		parent::tearDown();

		LoggerConfiguration::resetInstance();
	}

	/**
	 * @inheritdoc
	 */
	public static function tearDownAfterClass() {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . Database::BASE_TABLE );
	}

	/**
	 * @return string
	 */
	public function getQueueItemEntityRepositoryClass() {
		return Queue_Item_Repository::getClassName();
	}

	/**
	 * Cleans up all storage services used by repositories
	 */
	public function cleanUpStorage() {
		self::tearDownAfterClass();
	}

	/**
	 * Creates a table for testing purposes.
	 */
	private function createTestTable() {
		global $wpdb;

		$table = $wpdb->prefix . Database::BASE_TABLE;

		$collate = '';
		if ( $wpdb->has_cap( 'collation' ) ) {
			$collate = $wpdb->get_charset_collate();
		}

		$packlinkTestTableInstallScript = 'CREATE TABLE IF NOT EXISTS `' . $table . '` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `type` VARCHAR(255),
            `index_1` VARCHAR(100),
            `index_2` VARCHAR(100),
            `index_3` VARCHAR(100),
            `index_4` VARCHAR(100),
            `index_5` VARCHAR(100),
            `index_6` VARCHAR(100),
            `index_7` VARCHAR(100),
            `index_8` VARCHAR(100),
            `data` LONGTEXT,
            PRIMARY KEY (`id`),
            INDEX (index_1, index_2, index_3, index_4, index_5, index_6, index_7)
        ) ' . $collate;

		$wpdb->query( $packlinkTestTableInstallScript );
	}
}
