<?php
/**
 * Removes all plugin data when the plugin is deleted.
 *
 * @package RubojaAssistants
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'ruboja_assistants_settings', 'ruboja_assistants_api_key', 'ruboja_assistants_assistants', 'ruboja_assistants_last_sync' ) as $ruboja_assistants_option ) {
	delete_option( $ruboja_assistants_option );
}

delete_site_transient( 'ruboja_assistants_release' );
