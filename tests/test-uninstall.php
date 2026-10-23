<?php
/**
 * Tests for uninstall.php.
 *
 * @package RubojaAssistants
 */

use Siteware\RubojaAssistants\Settings;

/**
 * Deleting the plugin must remove the API key and all other data.
 */
class Test_Uninstall extends WP_UnitTestCase {

	public function test_deleting_the_plugin_removes_the_api_key_and_settings() {
		Settings::set_api_key( 'sk_live_must_be_removed' );
		Settings::save( array( 'color' => '#123456' ) );
		Settings::merge_remote_assistants(
			array(
				array(
					'id'          => str_repeat( 'a', 24 ),
					'title'       => 'A',
					'description' => '',
					'category'    => '',
				),
			)
		);
		set_site_transient( 'ruboja_assistants_release', array( 'repository' => 'owner/repo' ) );

		$this->assertNotFalse( get_option( Settings::OPTION_API_KEY ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'ruboja-assistants/ruboja-assistants.php' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';

		foreach ( array( Settings::OPTION_API_KEY, Settings::OPTION_SETTINGS, Settings::OPTION_ASSISTANTS, Settings::OPTION_LAST_SYNC ) as $option ) {
			$this->assertFalse( get_option( $option ), $option . ' must be deleted.' );
		}
		$this->assertSame( '', Settings::get_api_key() );
		$this->assertFalse( get_site_transient( 'ruboja_assistants_release' ) );
	}
}
