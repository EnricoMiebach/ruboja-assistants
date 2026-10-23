<?php
/**
 * Removes all plugin data, including the encrypted API key, when the plugin is
 * deleted under Plugins → Delete. Deactivating keeps the data.
 *
 * Removing the plugin folder by FTP does not run this file; the key then stays
 * in the database until the plugin is installed and deleted again.
 *
 * @package RubojaAssistants
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes the options and transients of the current site.
 *
 * @return void
 */
function ruboja_assistants_uninstall_site() {
	foreach ( array( 'ruboja_assistants_api_key', 'ruboja_assistants_settings', 'ruboja_assistants_assistants', 'ruboja_assistants_last_sync' ) as $option ) {
		delete_option( $option );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $ruboja_assistants_site_id ) {
		switch_to_blog( $ruboja_assistants_site_id );
		ruboja_assistants_uninstall_site();
		restore_current_blog();
	}
} else {
	ruboja_assistants_uninstall_site();
}

delete_site_transient( 'ruboja_assistants_release' );
