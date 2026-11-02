<?php
/**
 * Asserts the plugin deactivates itself once WooCommerce is no longer active.
 *
 * @package Packlink_Pro_Shipping
 */

use Packlink\WooCommerce\Components\Utility\Shop_Helper;
use Packlink\WooCommerce\Plugin;

/**
 * Class WooCommerceDependencyTest
 *
 * @package Packlink_Pro_Shipping
 */
class WooCommerceDependencyTest extends WP_UnitTestCase {

	/**
	 * Plugin under test.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Value of the global $pagenow before the test.
	 *
	 * @var string|null
	 */
	private $pagenow;

	/**
	 * @inheritDoc
	 */
	public function setUp() {
		parent::setUp();

		global $wpdb;
		$this->pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : null;
		$this->plugin = new Plugin( $wpdb, dirname( __DIR__ ) . '/packlink-pro-shipping.php' );
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'after_plugin_row_' . Shop_Helper::get_plugin_name() );
	}

	/**
	 * @inheritDoc
	 */
	public function tearDown() {
		unset( $_GET['activate'] );
		$GLOBALS['pagenow'] = $this->pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		parent::tearDown();
	}

	/**
	 * On the plugin list the plugin is deactivated and the reason is shown under its row, not as
	 * WordPress' "Plugin activated." notice.
	 */
	public function test_deactivates_and_explains_under_the_plugin_row_when_woocommerce_is_inactive() {
		$GLOBALS['pagenow'] = 'plugins.php';
		$_GET['activate']   = 'true';
		update_option( 'active_plugins', array( Shop_Helper::get_plugin_name() ) );

		$this->plugin->deactivate_if_woocommerce_inactive();

		$this->assertNotContains( Shop_Helper::get_plugin_name(), get_option( 'active_plugins' ) );
		$this->assertArrayNotHasKey( 'activate', $_GET );
		$this->assertNotFalse(
			has_action( 'after_plugin_row_' . Shop_Helper::get_plugin_name(), array( $this->plugin, 'woocommerce_inactive_plugin_row' ) )
		);
		$this->assertFalse( has_action( 'admin_notices', array( $this->plugin, 'woocommerce_inactive_notice' ) ) );
	}

	/**
	 * Outside the plugin list there is no plugin row, so the reason is shown as an admin notice.
	 */
	public function test_deactivates_and_notifies_outside_the_plugin_list_when_woocommerce_is_inactive() {
		$GLOBALS['pagenow'] = 'index.php';
		update_option( 'active_plugins', array( Shop_Helper::get_plugin_name() ) );

		$this->plugin->deactivate_if_woocommerce_inactive();

		$this->assertNotContains( Shop_Helper::get_plugin_name(), get_option( 'active_plugins' ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( $this->plugin, 'woocommerce_inactive_notice' ) ) );
	}

	/**
	 * Activating without WooCommerce returns to the plugin list instead of stopping on an error page.
	 */
	public function test_activation_without_woocommerce_does_not_stop_on_an_error_page() {
		update_option( 'active_plugins', array() );

		$this->plugin->activate( false );

		$this->assertTrue( true );
	}

	/**
	 * With WooCommerce active nothing changes.
	 */
	public function test_stays_active_when_woocommerce_is_active() {
		$GLOBALS['pagenow'] = 'plugins.php';
		update_option( 'active_plugins', array( 'woocommerce/woocommerce.php', Shop_Helper::get_plugin_name() ) );

		$this->plugin->deactivate_if_woocommerce_inactive();

		$this->assertContains( Shop_Helper::get_plugin_name(), get_option( 'active_plugins' ) );
		$this->assertFalse(
			has_action( 'after_plugin_row_' . Shop_Helper::get_plugin_name(), array( $this->plugin, 'woocommerce_inactive_plugin_row' ) )
		);
	}

	/**
	 * The Configuration link is registered while the plugin is still active, before the same request
	 * deactivates it; the inactive row must not link to a settings page that no longer exists.
	 */
	public function test_configuration_link_is_not_shown_once_the_plugin_is_deactivated() {
		$GLOBALS['pagenow'] = 'plugins.php';
		update_option( 'active_plugins', array( Shop_Helper::get_plugin_name() ) );

		$this->plugin->deactivate_if_woocommerce_inactive();
		$links = $this->plugin->create_configuration_link( array( 'activate' => '<a href="#">Activate</a>' ) );

		$this->assertArrayNotHasKey( 'configuration', $links );
		$this->assertArrayHasKey( 'activate', $links );
	}

	/**
	 * While the plugin is active the Configuration link is shown first.
	 */
	public function test_configuration_link_is_shown_while_the_plugin_is_active() {
		update_option( 'active_plugins', array( 'woocommerce/woocommerce.php', Shop_Helper::get_plugin_name() ) );

		$links = $this->plugin->create_configuration_link( array( 'deactivate' => '<a href="#">Deactivate</a>' ) );

		$this->assertSame( array( 'configuration', 'deactivate' ), array_keys( $links ) );
	}

	/**
	 * The plugin row message spans the list and names the missing dependency.
	 */
	public function test_plugin_row_explains_the_deactivation() {
		ob_start();
		$this->plugin->woocommerce_inactive_plugin_row();
		$output = ob_get_clean();

		$this->assertNotFalse( strpos( $output, 'plugin-update-tr' ) );
		$this->assertNotFalse( strpos( $output, 'WooCommerce' ) );
	}

	/**
	 * The notice names the plugin and the missing dependency.
	 */
	public function test_notice_explains_the_deactivation() {
		ob_start();
		$this->plugin->woocommerce_inactive_notice();
		$output = ob_get_clean();

		$this->assertNotFalse( strpos( $output, 'notice-error' ) );
		$this->assertNotFalse( strpos( $output, 'WooCommerce' ) );
	}
}
