<?php
/**
 * Tests for the front-end markup.
 *
 * @package RubojaAssistants
 */

use Siteware\RubojaAssistants\Embed;
use Siteware\RubojaAssistants\Settings;

/**
 * Shortcode and block rendering.
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

	public function test_ruboja_header_is_hidden_unless_switched_off() {
		$shortcode = '[ruboja_assistant id="' . self::ASSISTANT . '"]';

		$this->assertStringContainsString( 'ruboja-assistant--no-header', do_shortcode( $shortcode ) );

		Settings::save( array( 'hide_header' => '' ) );
		$this->assertStringNotContainsString( 'ruboja-assistant--no-header', do_shortcode( $shortcode ) );
	}

	public function test_cutting_the_greeting_is_off_by_default_and_implies_the_header() {
		$shortcode = '[ruboja_assistant id="' . self::ASSISTANT . '"]';

		$this->assertStringNotContainsString( 'ruboja-assistant--no-greeting', do_shortcode( $shortcode ) );

		Settings::save(
			array(
				'hide_header'   => '',
				'hide_greeting' => '1',
			)
		);
		$html = do_shortcode( $shortcode );

		$this->assertStringContainsString( 'ruboja-assistant--no-greeting', $html );
		$this->assertStringContainsString( 'ruboja-assistant--no-header', $html, 'The greeting sits below the header.' );
	}

	public function test_contrast_color() {
		$this->assertSame( '#ffffff', Embed::contrast_color( '#1f3a5f' ) );
		$this->assertSame( '#111111', Embed::contrast_color( '#ffd700' ) );
		$this->assertSame( '#111111', Embed::contrast_color( '#fff' ) );
	}
}
