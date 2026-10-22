<?php
/**
 * Symmetric encryption for secrets stored in the database.
 *
 * @package RubojaAssistants
 */

namespace Siteware\RubojaAssistants;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts secrets with libsodium (bundled with WordPress via sodium_compat).
 *
 * The key is derived from the site's secret salts in wp-config.php, so a database
 * dump alone does not reveal the API key. Changing the salts makes stored secrets
 * unreadable; the settings page then asks for the key again.
 */
final class Crypto {

	/**
	 * Prefix that marks the storage format.
	 */
	const PREFIX = 'v1:';

	/**
	 * Encrypts a plain-text secret.
	 *
	 * @param string $plain Secret to encrypt.
	 * @return string Encoded cipher text, or an empty string for empty input.
	 */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe storage, not obfuscation.
		return self::PREFIX . base64_encode( $nonce . $cipher );
	}

	/**
	 * Decrypts a value produced by encrypt().
	 *
	 * @param string $encoded Encoded cipher text.
	 * @return string Plain text, or an empty string if the value cannot be decrypted.
	 */
	public static function decrypt( string $encoded ): string {
		if ( 0 !== strpos( $encoded, self::PREFIX ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary-safe storage, not obfuscation.
		$raw = base64_decode( substr( $encoded, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		try {
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		} catch ( \SodiumException $e ) {
			return '';
		}

		return false === $plain ? '' : $plain;
	}

	/**
	 * Derives the encryption key from the site salts.
	 *
	 * @return string Binary key.
	 */
	private static function key(): string {
		return sodium_crypto_generichash( 'ruboja-assistants|' . wp_salt( 'secure_auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
