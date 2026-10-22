<?php
/**
 * Tests for the front-end markup and the updater.
 *
 * @package RubojaAssistants
 */

use Siteware\RubojaAssistants\Embed;
use Siteware\RubojaAssistants\Settings;
use Siteware\RubojaAssistants\Updater;

/**
 * Shortcode, block rendering and release parsing.
 */
class Test_Embed extends WP_UnitTestCase {

	const ASSISTANT = '6ad744a4c3e693b346da671a';

	public function set_up() {
		parent::set_up();

		Settings::merge_remote_assistants(
			array(
				array(
					'id'          => self::ASSISTANT,
					'title'       => 'Vorauszahlungs-Assistent <b>D + H</b>',
					'description' => '',
					'category'    => '',
				),
			)
		);
		Settings::save_release_flags( array( self::ASSISTANT ), array() );
	}

	public function test_inline_shortcode_renders_container_with_options() {
		$html = do_shortcode( '[ruboja_assistant id="' . self::ASSISTANT . '" height="500"]' );

		$this->assertStringContainsString( 'ruboja-assistant--inline', $html );
		$this->assertStringContainsString( '--ruboja-height:500px', $html );
		$this->assertStringContainsString( 'data-ruboja-assistant=', $html );
		$this->assertStringNotContainsString( '<b>', $html, 'Titles must be escaped.' );
		$this->assertTrue( wp_script_is( 'ruboja-assistants', 'enqueued' ) );
	}

	public function test_floating_shortcode_renders_launcher_and_dialog() {
		$html = do_shortcode( '[ruboja_assistant id="' . self::ASSISTANT . '" mode="floating" label="Fragen?" position="left"]' );

		$this->assertStringContainsString( 'ruboja-assistant--floating', $html );
		$this->assertStringContainsString( 'ruboja-assistant--left', $html );
		$this->assertStringContainsString( 'role="dialog"', $html );
		$this->assertStringContainsString( 'Fragen?', $html );
	}

	public function test_unreleased_assistant_renders_nothing_for_visitors() {
		Settings::save_release_flags( array(), array() );

		$this->assertSame( '', do_shortcode( '[ruboja_assistant id="' . self::ASSISTANT . '"]' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertStringContainsString( 'ruboja-assistant-notice', do_shortcode( '[ruboja_assistant id="' . self::ASSISTANT . '"]' ) );
	}

	public function test_block_is_registered_and_renders() {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'ruboja/assistant' ) );

		$html = do_blocks( '<!-- wp:ruboja/assistant {"assistantId":"' . self::ASSISTANT . '","mode":"floating"} /-->' );

		$this->assertStringContainsString( 'ruboja-assistant--floating', $html );
	}

	public function test_contrast_color() {
		$this->assertSame( '#ffffff', Embed::contrast_color( '#1f3a5f' ) );
		$this->assertSame( '#111111', Embed::contrast_color( '#ffd700' ) );
		$this->assertSame( '#111111', Embed::contrast_color( '#fff' ) );
	}

	public function test_updater_reads_repository_and_release() {
		$this->assertSame( 'owner/repo', Updater::repository( 'https://github.com/owner/repo' ) );
		$this->assertSame( '', Updater::repository( 'https://example.com/owner/repo' ) );

		$release = Updater::parse_release(
			array(
				'tag_name' => 'v1.2.3',
				'html_url' => 'https://github.com/owner/repo/releases/tag/v1.2.3',
				'assets'   => array(
					array(
						'name'                 => 'ruboja-assistants.zip',
						'url'                  => 'https://api.github.com/repos/owner/repo/releases/assets/1',
						'browser_download_url' => 'https://github.com/owner/repo/releases/download/v1.2.3/ruboja-assistants.zip',
					),
				),
			),
			false
		);

		$this->assertSame( '1.2.3', $release['version'] );
		$this->assertStringEndsWith( '/ruboja-assistants.zip', $release['package'] );
		$this->assertNull(
			Updater::parse_release(
				array(
					'tag_name'   => 'v1.2.3',
					'prerelease' => true,
				),
				false
			)
		);
	}
}
