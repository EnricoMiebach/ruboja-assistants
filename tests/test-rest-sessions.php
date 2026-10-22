<?php
/**
 * Tests for the session endpoint.
 *
 * @package RubojaAssistants
 */

use Siteware\RubojaAssistants\Settings;

/**
 * POST /ruboja-assistants/v1/sessions.
 */
class Test_Rest_Sessions extends WP_UnitTestCase {

	const ASSISTANT = '6ad744a4c3e693b346da671a';

	/**
	 * Requests sent to Ruboja during a test.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $requests = array();

	public function set_up() {
		parent::set_up();

		$this->requests = array();

		Settings::set_api_key( 'sk_test_unit' );
		Settings::save( array( 'rate_limit' => 3 ) );
		Settings::merge_remote_assistants(
			array(
				array(
					'id'          => self::ASSISTANT,
					'title'       => 'Vorauszahlungs-Assistent',
					'description' => '',
					'category'    => 'Steuern',
				),
			)
		);
		Settings::save_release_flags( array( self::ASSISTANT ), array() );

		add_filter( 'pre_http_request', array( $this, 'fake_ruboja' ), 10, 3 );

		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_ruboja' ), 10 );
		parent::tear_down();
	}

	/**
	 * Answers requests to the Ruboja API like the real endpoint.
	 *
	 * @param false|array $preempt Short-circuit value.
	 * @param array       $args    Request arguments.
	 * @param string      $url     Request URL.
	 * @return array
	 */
	public function fake_ruboja( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'url'        => 'https://www.ruboja.de/external/chat/abc123',
					'expires_at' => '2026-10-22T12:00:00.000Z',
					'single_use' => true,
					'mode'       => 'operator',
				)
			),
			'response' => array(
				'code'    => 201,
				'message' => 'Created',
			),
			'cookies'  => array(),
		);
	}

	public function test_released_assistant_gets_a_session_url() {
		$response = $this->post( self::ASSISTANT );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'https://www.ruboja.de/external/chat/abc123', $response->get_data()['url'] );

		$this->assertCount( 1, $this->requests );
		$request = $this->requests[0];
		$body    = json_decode( $request['args']['body'], true );

		$this->assertSame( 'https://api.ruboja.de/api/v1/chat-sessions', $request['url'] );
		$this->assertSame( 'Bearer sk_test_unit', $request['args']['headers']['Authorization'] );
		$this->assertSame( self::ASSISTANT, $body['assistant_id'] );
		$this->assertTrue( $body['single_use'] );
		$this->assertFalse( $body['show_history'] );
		$this->assertMatchesRegularExpression( '/^wp-[0-9a-f]{32}$/', $body['reference_id'] );
	}

	public function test_api_key_is_never_returned_to_the_browser() {
		$response = $this->post( self::ASSISTANT );

		$this->assertStringNotContainsString( 'sk_test_unit', wp_json_encode( $response->get_data() ) );
	}

	public function test_unreleased_assistant_is_refused() {
		$response = $this->post( str_repeat( 'f', 24 ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_invalid_id_is_refused() {
		$response = $this->post( 'abc' );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_logged_in_only_assistant_requires_login() {
		Settings::save_release_flags( array( self::ASSISTANT ), array( self::ASSISTANT ) );

		$this->assertSame( 403, $this->post( self::ASSISTANT )->get_status() );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 201, $this->post( self::ASSISTANT )->get_status() );
	}

	public function test_rate_limit_per_ip() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 201, $this->post( self::ASSISTANT )->get_status() );
		}

		$this->assertSame( 429, $this->post( self::ASSISTANT )->get_status() );
	}

	public function test_user_attribution_sends_email_of_logged_in_user() {
		Settings::save(
			array(
				'rate_limit'  => 0,
				'attribution' => 'wp_user_email',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'user_email' => 'mitarbeiter@example.com' ) ) );

		$this->post( self::ASSISTANT );

		$this->assertSame( 'mitarbeiter@example.com', $this->requests[0]['args']['headers']['X-User-Mail'] );
	}

	/**
	 * Calls the endpoint.
	 *
	 * @param string $assistant Assistant id.
	 * @return WP_REST_Response
	 */
	private function post( $assistant ) {
		$request = new WP_REST_Request( 'POST', '/ruboja-assistants/v1/sessions' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'assistant' => $assistant ) ) );

		return rest_get_server()->dispatch( $request );
	}
}
