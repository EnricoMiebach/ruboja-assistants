<?php
/**
 * Tests for Settings and Crypto.
 *
 * @package RubojaAssistants
 */

use Siteware\RubojaAssistants\Crypto;
use Siteware\RubojaAssistants\Settings;

/**
 * Settings, encryption and assistant list handling.
 */
class Test_Settings extends WP_UnitTestCase {

	public function test_encrypt_round_trip() {
		$cipher = Crypto::encrypt( 'sk_live_secret' );

		$this->assertStringStartsWith( Crypto::PREFIX, $cipher );
		$this->assertStringNotContainsString( 'sk_live_secret', $cipher );
		$this->assertSame( 'sk_live_secret', Crypto::decrypt( $cipher ) );
	}

	public function test_decrypt_rejects_tampered_values() {
		$cipher = Crypto::encrypt( 'sk_live_secret' );

		$this->assertSame( '', Crypto::decrypt( substr( $cipher, 0, -4 ) . 'AAAA' ) );
		$this->assertSame( '', Crypto::decrypt( 'not encrypted' ) );
	}

	public function test_api_key_is_stored_encrypted() {
		Settings::set_api_key( ' sk_live_abcdefghijklmnop ' );

		$this->assertSame( 'sk_live_abcdefghijklmnop', Settings::get_api_key() );
		$this->assertStringNotContainsString( 'sk_live', (string) get_option( Settings::OPTION_API_KEY ) );
		$this->assertSame( 'sk_live_abcd…', Settings::masked_api_key() );

		Settings::set_api_key( '' );
		$this->assertSame( '', Settings::get_api_key() );
	}

	public function test_sanitize_falls_back_to_safe_values() {
		$clean = Settings::sanitize(
			array(
				'api_base'         => 'http://insecure.example.com',
				'color'            => 'red',
				'rate_limit'       => '-5',
				'history_days'     => '9999',
				'attribution'      => 'everyone',
				'global_assistant' => 'not-an-id',
				'global_position'  => 'top',
			)
		);

		$this->assertSame( Settings::DEFAULT_API_BASE, $clean['api_base'] );
		$this->assertSame( Settings::DEFAULT_COLOR, $clean['color'] );
		$this->assertSame( 0, $clean['rate_limit'] );
		$this->assertSame( 365, $clean['history_days'] );
		$this->assertSame( 'none', $clean['attribution'] );
		$this->assertSame( '', $clean['global_assistant'] );
		$this->assertSame( 'right', $clean['global_position'] );
		$this->assertFalse( $clean['consent_required'] );
	}

	public function test_merge_keeps_flags_and_marks_missing_assistants() {
		$a = str_repeat( 'a', 24 );
		$b = str_repeat( 'b', 24 );

		Settings::merge_remote_assistants( array( $this->assistant( $a, 'A' ), $this->assistant( $b, 'B' ) ) );
		Settings::save_release_flags( array( $a, $b ), array( $b ) );

		Settings::merge_remote_assistants( array( $this->assistant( $a, 'A renamed' ) ) );

		$all = Settings::assistants();
		$this->assertSame( 'A renamed', $all[ $a ]['title'] );
		$this->assertTrue( $all[ $a ]['enabled'] );
		$this->assertTrue( $all[ $b ]['missing'] );
		$this->assertTrue( $all[ $b ]['logged_in_only'] );

		$this->assertNotNull( Settings::get_released( strtoupper( $a ) ) );
		$this->assertNull( Settings::get_released( $b ), 'Missing assistants must not be embeddable.' );
	}

	/**
	 * Builds an assistant as returned by the API client.
	 *
	 * @param string $id    Id.
	 * @param string $title Title.
	 * @return array<string, string>
	 */
	private function assistant( $id, $title ) {
		return array(
			'id'          => $id,
			'title'       => $title,
			'description' => '',
			'category'    => '',
		);
	}
}
