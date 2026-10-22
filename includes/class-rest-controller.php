<?php
/**
 * REST endpoints.
 *
 * @package RubojaAssistants
 */

namespace Siteware\RubojaAssistants;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Provides the endpoint the browser calls to open an assistant, and a list of
 * released assistants for the block editor.
 *
 * The API key never leaves the server: the browser only receives the one-time
 * chat URL that Ruboja mints for this visitor.
 */
final class Rest_Controller {

	const ROUTE_NAMESPACE = 'ruboja-assistants/v1';
	const VISITOR_COOKIE  = 'ruboja_assistants_visitor';
	const SESSION_MINUTES = 10;

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/sessions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_session' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'assistant' => array(
						'required' => true,
						'type'     => 'string',
						'pattern'  => '^[0-9a-fA-F]{24}$',
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/assistants',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_released' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * Mints a chat session for a released assistant.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_session( WP_REST_Request $request ) {
		$assistant = Settings::get_released( (string) $request['assistant'] );

		if ( null === $assistant ) {
			return new WP_Error( 'ruboja_assistant_unavailable', __( 'This assistant is not available.', 'ruboja-assistants' ), array( 'status' => 404 ) );
		}

		if ( ! empty( $assistant['logged_in_only'] ) && ! is_user_logged_in() ) {
			return new WP_Error( 'ruboja_login_required', __( 'Please log in to use this assistant.', 'ruboja-assistants' ), array( 'status' => 403 ) );
		}

		if ( $this->is_rate_limited() ) {
			return new WP_Error( 'ruboja_rate_limited', __( 'Too many requests. Please try again later.', 'ruboja-assistants' ), array( 'status' => 429 ) );
		}

		$show_history = (bool) Settings::get( 'show_history' );

		$body = array(
			'assistant_id'       => $assistant['id'],
			'reference_id'       => $this->reference_id( $assistant['id'], $show_history ),
			'show_history'       => $show_history,
			'single_use'         => true,
			'expires_in_minutes' => self::SESSION_MINUTES,
		);

		$headers = array();

		if ( 'wp_user_email' === Settings::get( 'attribution' ) && is_user_logged_in() ) {
			$email = wp_get_current_user()->user_email;

			if ( is_email( $email ) ) {
				$headers['X-User-Mail'] = $email;
			}
		}

		/**
		 * Filters the request body sent to POST /chat-sessions.
		 *
		 * @param array<string, mixed> $body      Request body.
		 * @param array<string, mixed> $assistant Released assistant.
		 */
		$body = (array) apply_filters( 'ruboja_assistants_session_body', $body, $assistant );

		/**
		 * Filters the extra headers sent to POST /chat-sessions (identity headers).
		 *
		 * @param array<string, string> $headers   Headers.
		 * @param array<string, mixed>  $assistant Released assistant.
		 */
		$headers = (array) apply_filters( 'ruboja_assistants_session_headers', $headers, $assistant );

		$session = Api_Client::from_settings()->create_chat_session( $body, $headers );

		if ( is_wp_error( $session ) ) {
			$this->log_error( $session );

			return new WP_Error( 'ruboja_session_failed', __( 'The assistant could not be started. Please try again later.', 'ruboja-assistants' ), array( 'status' => 502 ) );
		}

		$response = new WP_REST_Response(
			array(
				'url'       => $session['url'],
				'expiresAt' => $session['expires_at'],
			),
			201
		);
		$response->header( 'Cache-Control', 'no-store, max-age=0' );

		return $response;
	}

	/**
	 * Lists the released assistants for the block editor.
	 *
	 * @return WP_REST_Response
	 */
	public function list_released(): WP_REST_Response {
		$items = array();

		foreach ( Settings::released_assistants() as $id => $assistant ) {
			$items[] = array(
				'id'           => $id,
				'title'        => $assistant['title'],
				'category'     => $assistant['category'],
				'loggedInOnly' => ! empty( $assistant['logged_in_only'] ),
			);
		}

		return new WP_REST_Response( $items );
	}

	/**
	 * Builds the reference id that Ruboja uses to group a visitor's conversations.
	 *
	 * With history enabled the reference is derived from a random visitor cookie,
	 * so a returning visitor sees their own conversations and nobody else's.
	 * Without history every session gets a fresh reference and no cookie is set.
	 *
	 * @param string $assistant_id Assistant id.
	 * @param bool   $persistent   Whether conversations should be shown again later.
	 * @return string
	 */
	private function reference_id( string $assistant_id, bool $persistent ): string {
		if ( ! $persistent ) {
			return 'wp-' . bin2hex( random_bytes( 16 ) );
		}

		$token = $this->visitor_token();

		return 'wp-' . substr( hash_hmac( 'sha256', $token . '|' . $assistant_id, wp_salt( 'nonce' ) ), 0, 40 );
	}

	/**
	 * Reads the visitor token from the cookie or creates a new one.
	 *
	 * @return string 32 hex characters.
	 */
	private function visitor_token(): string {
		$cookie = isset( $_COOKIE[ self::VISITOR_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::VISITOR_COOKIE ] ) ) : '';

		if ( 1 === preg_match( '/^[0-9a-f]{32}$/', $cookie ) ) {
			return $cookie;
		}

		$token = bin2hex( random_bytes( 16 ) );
		$days  = max( 1, (int) Settings::get( 'history_days' ) );

		if ( ! headers_sent() ) {
			setcookie(
				self::VISITOR_COOKIE,
				$token,
				array(
					'expires'  => time() + $days * DAY_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		return $token;
	}

	/**
	 * Counts session requests per client IP and hour.
	 *
	 * @return bool Whether the limit is exceeded.
	 */
	private function is_rate_limited(): bool {
		$limit = (int) Settings::get( 'rate_limit' );

		if ( $limit <= 0 ) {
			return false;
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the client IP used for rate limiting, e.g. behind a trusted proxy.
		 *
		 * @param string $ip Client IP.
		 */
		$ip = (string) apply_filters( 'ruboja_assistants_client_ip', $ip );

		$key   = 'ruboja_assistants_rl_' . md5( $ip . '|' . wp_salt( 'nonce' ) );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return true;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return false;
	}

	/**
	 * Writes API errors to the debug log without exposing them to visitors.
	 *
	 * @param WP_Error $error Error.
	 * @return void
	 */
	private function log_error( WP_Error $error ): void {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		$data = $error->get_error_data();
		$code = is_array( $data ) && ! empty( $data['code'] ) ? $data['code'] : $error->get_error_code();

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only with WP_DEBUG_LOG.
		error_log( sprintf( '[Ruboja Assistants] Chat session failed (%s): %s', $code, $error->get_error_message() ) );
	}
}
