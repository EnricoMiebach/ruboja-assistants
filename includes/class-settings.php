<?php
/**
 * Settings storage.
 *
 * @package RubojaAssistants
 */

namespace Siteware\RubojaAssistants;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin options: general settings, the encrypted API key
 * and the list of assistants with their release flags.
 */
final class Settings {

	const OPTION_SETTINGS   = 'ruboja_assistants_settings';
	const OPTION_API_KEY    = 'ruboja_assistants_api_key';
	const OPTION_ASSISTANTS = 'ruboja_assistants_assistants';
	const OPTION_LAST_SYNC  = 'ruboja_assistants_last_sync';
	const API_KEY_CONSTANT  = 'RUBOJA_ASSISTANTS_API_KEY';
	const API_BASE_CONSTANT = 'RUBOJA_ASSISTANTS_API_BASE';
	const DEFAULT_API_BASE  = 'https://api.ruboja.de/api/v1';
	const DEFAULT_COLOR     = '#1f3a5f';

	/**
	 * Default values of the general settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'color'            => self::DEFAULT_COLOR,
			'hide_header'      => true,
			'consent_required' => true,
			'consent_text'     => '',
			'show_history'     => false,
			'history_days'     => 30,
			'rate_limit'       => 20,
			'attribution'      => 'none',
			'global_assistant' => '',
			'global_label'     => '',
			'global_position'  => 'right',
		);
	}

	/**
	 * All general settings merged with their defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION_SETTINGS, array() );

		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * A single general setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();

		return $all[ $key ] ?? null;
	}

	/**
	 * Validates and stores the general settings.
	 *
	 * @param array<string, mixed> $input Raw values from the settings form.
	 * @return void
	 */
	public static function save( array $input ): void {
		update_option( self::OPTION_SETTINGS, self::sanitize( $input ), false );
	}

	/**
	 * Sanitizes raw settings input.
	 *
	 * @param array<string, mixed> $input Raw values.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::defaults();

		$color  = isset( $input['color'] ) ? sanitize_hex_color( (string) $input['color'] ) : '';
		$global = isset( $input['global_assistant'] ) ? strtolower( (string) $input['global_assistant'] ) : '';

		return array(
			'color'            => $color ? $color : $defaults['color'],
			'hide_header'      => ! empty( $input['hide_header'] ),
			'consent_required' => ! empty( $input['consent_required'] ),
			'consent_text'     => isset( $input['consent_text'] ) ? sanitize_textarea_field( (string) $input['consent_text'] ) : '',
			'show_history'     => ! empty( $input['show_history'] ),
			'history_days'     => isset( $input['history_days'] ) ? min( 365, max( 1, absint( $input['history_days'] ) ) ) : $defaults['history_days'],
			'rate_limit'       => isset( $input['rate_limit'] ) ? min( 1000, max( 0, (int) $input['rate_limit'] ) ) : $defaults['rate_limit'],
			'attribution'      => ( isset( $input['attribution'] ) && 'wp_user_email' === $input['attribution'] ) ? 'wp_user_email' : 'none',
			'global_assistant' => self::is_valid_id( $global ) ? $global : '',
			'global_label'     => isset( $input['global_label'] ) ? sanitize_text_field( (string) $input['global_label'] ) : '',
			'global_position'  => ( isset( $input['global_position'] ) && 'left' === $input['global_position'] ) ? 'left' : 'right',
		);
	}

	/**
	 * Base URL of the Ruboja API.
	 *
	 * Fixed to the official endpoint. For a test system it can be changed with the
	 * constant RUBOJA_ASSISTANTS_API_BASE in wp-config.php or the filter
	 * ruboja_assistants_api_base. Only https URLs are accepted.
	 *
	 * @return string URL without trailing slash.
	 */
	public static function api_base(): string {
		$base = defined( self::API_BASE_CONSTANT ) ? (string) constant( self::API_BASE_CONSTANT ) : self::DEFAULT_API_BASE;

		/**
		 * Filters the base URL of the Ruboja API.
		 *
		 * @param string $base Base URL.
		 */
		$base = esc_url_raw( trim( (string) apply_filters( 'ruboja_assistants_api_base', $base ) ), array( 'https' ) );

		return '' !== $base ? untrailingslashit( $base ) : self::DEFAULT_API_BASE;
	}

	/**
	 * Whether an id has the format of a Ruboja id (24 hex characters).
	 *
	 * @param string $id Id to check.
	 * @return bool
	 */
	public static function is_valid_id( string $id ): bool {
		return 1 === preg_match( '/^[0-9a-f]{24}$/', $id );
	}

	/**
	 * Whether the API key is defined in wp-config.php.
	 *
	 * @return bool
	 */
	public static function api_key_from_constant(): bool {
		return defined( self::API_KEY_CONSTANT ) && '' !== (string) constant( self::API_KEY_CONSTANT );
	}

	/**
	 * The API key in plain text, or an empty string if none is configured.
	 *
	 * @return string
	 */
	public static function get_api_key(): string {
		if ( self::api_key_from_constant() ) {
			return trim( (string) constant( self::API_KEY_CONSTANT ) );
		}

		$stored = get_option( self::OPTION_API_KEY, '' );

		return is_string( $stored ) && '' !== $stored ? Crypto::decrypt( $stored ) : '';
	}

	/**
	 * Stores the API key encrypted, or removes it when empty.
	 *
	 * @param string $key Plain-text API key.
	 * @return void
	 */
	public static function set_api_key( string $key ): void {
		$key = trim( $key );

		if ( '' === $key ) {
			delete_option( self::OPTION_API_KEY );
			return;
		}

		update_option( self::OPTION_API_KEY, Crypto::encrypt( $key ), false );
	}

	/**
	 * Whether a stored key exists that can no longer be decrypted (salts changed).
	 *
	 * @return bool
	 */
	public static function has_unreadable_api_key(): bool {
		if ( self::api_key_from_constant() ) {
			return false;
		}

		$stored = get_option( self::OPTION_API_KEY, '' );

		return is_string( $stored ) && '' !== $stored && '' === Crypto::decrypt( $stored );
	}

	/**
	 * The beginning of the API key for display, never the full key.
	 *
	 * @return string
	 */
	public static function masked_api_key(): string {
		$key = self::get_api_key();

		return '' === $key ? '' : substr( $key, 0, 12 ) . '…';
	}

	/**
	 * All known assistants, keyed by id.
	 *
	 * Each entry holds title, description, category (from Ruboja) and the local
	 * flags enabled, logged_in_only and missing.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function assistants(): array {
		$stored = get_option( self::OPTION_ASSISTANTS, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Assistants that are released for the website and still exist in Ruboja.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function released_assistants(): array {
		return array_filter(
			self::assistants(),
			static function ( $assistant ) {
				return ! empty( $assistant['enabled'] ) && empty( $assistant['missing'] );
			}
		);
	}

	/**
	 * A released assistant by id.
	 *
	 * @param string $id Assistant id.
	 * @return array<string, mixed>|null
	 */
	public static function get_released( string $id ): ?array {
		$released = self::released_assistants();
		$id       = strtolower( $id );

		return isset( $released[ $id ] ) ? array( 'id' => $id ) + $released[ $id ] : null;
	}

	/**
	 * Merges the list fetched from Ruboja into the stored list.
	 *
	 * Local flags are kept. Assistants that the key can no longer access are kept
	 * as "missing" so that their release flag survives a temporary loss of access.
	 *
	 * @param array<int, array<string, string>> $remote Assistants from the API.
	 * @return void
	 */
	public static function merge_remote_assistants( array $remote ): void {
		$existing = self::assistants();
		$merged   = array();

		foreach ( $remote as $assistant ) {
			$id            = $assistant['id'];
			$previous      = $existing[ $id ] ?? array();
			$merged[ $id ] = array(
				'title'          => $assistant['title'],
				'description'    => $assistant['description'],
				'category'       => $assistant['category'],
				'enabled'        => ! empty( $previous['enabled'] ),
				'logged_in_only' => ! empty( $previous['logged_in_only'] ),
				'missing'        => false,
			);
			unset( $existing[ $id ] );
		}

		foreach ( $existing as $id => $assistant ) {
			$assistant['missing'] = true;
			$merged[ $id ]        = $assistant;
		}

		update_option( self::OPTION_ASSISTANTS, $merged, false );
		update_option( self::OPTION_LAST_SYNC, time(), false );
	}

	/**
	 * Stores the release flags chosen on the settings page.
	 *
	 * @param string[] $enabled        Ids released for the website.
	 * @param string[] $logged_in_only Ids restricted to logged-in users.
	 * @return void
	 */
	public static function save_release_flags( array $enabled, array $logged_in_only ): void {
		$assistants = self::assistants();

		foreach ( $assistants as $id => $assistant ) {
			$assistants[ $id ]['enabled']        = in_array( $id, $enabled, true );
			$assistants[ $id ]['logged_in_only'] = in_array( $id, $logged_in_only, true );
		}

		update_option( self::OPTION_ASSISTANTS, $assistants, false );
	}

	/**
	 * Timestamp of the last successful sync with Ruboja.
	 *
	 * @return int
	 */
	public static function last_sync(): int {
		return (int) get_option( self::OPTION_LAST_SYNC, 0 );
	}

	/**
	 * Default consent notice shown before an assistant is loaded.
	 *
	 * @return string
	 */
	public static function consent_text(): string {
		$text = (string) self::get( 'consent_text' );

		if ( '' !== $text ) {
			return $text;
		}

		return __( 'This assistant is provided by Ruboja. When you start it, your input and any files you upload are transmitted to Ruboja and processed by an AI model. Please do not enter information you do not want to share.', 'ruboja-assistants' );
	}
}
