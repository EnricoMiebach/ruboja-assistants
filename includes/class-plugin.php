<?php
/**
 * Plugin bootstrap.
 *
 * @package RubojaAssistants
 */

namespace Siteware\RubojaAssistants;

defined( 'ABSPATH' ) || exit;

/**
 * Wires all components into WordPress.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the booted plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Registers the hooks of every component.
	 *
	 * @return void
	 */
	private function boot(): void {
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );

		( new Embed() )->register();
		( new Rest_Controller() )->register();
		( new Updater() )->register();

		if ( is_admin() ) {
			( new Admin_Page() )->register();
		}
	}

	/**
	 * Loads the plugin translations from the bundled languages folder.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'ruboja-assistants', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
	}
}
