<?php
/**
 * Client for the Ruboja external API.
 *
 * @package RubojaAssistants
 */

namespace Siteware\RubojaAssistants;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the endpoints the plugin needs.
 *
 * @see https://www.ruboja.de/api.md
 */
final class Api_Client {

	/**
	 * API base URL without trailing slash.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * API key (sk_live_… or sk_test_…).
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Constructor.
	 *
	 * @param string $base API base URL.
	 * @param string $key  API key.
	 */
	public function __construct( string $base, string $key ) {
		$this->base = untrailingslashit( $base );
		$this->key  = $key;
	}

	/**
	 * Creates a client from the stored settings.
	 *
	 * @return Api_Client
	 */
	public static function from_settings(): Api_Client {
		return new self( Settings::api_base(), Settings::get_api_key() );
	}

	/**
	 * Lists the assistants the API key can access.
	 *
	 * @return array<int, array<string, string>>|WP_Error
	 */
	public function list_assistants() {
		$data = $this->request( 'GET', '/assistants' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! isset( $data['assistants'] ) || ! is_array( $data['assistants'] ) ) {
			return new WP_Error( 'ruboja_invalid_response', __( 'Ruboja returned an unexpected response.', 'ruboja-assistants' ) );
		}

		$assistants = array();

		foreach ( $data['assistants'] as $item ) {
			$id = isset( $item['id'] ) ? strtolower( (string) $item['id'] ) : '';

			if ( ! Settings::is_valid_id( $id ) ) {
				continue;
			}

			$assistants[] = array(
				'id'          => $id,
				'title'       => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
				'description' => sanitize_textarea_field( (string) ( $item['description'] ?? '' ) ),
				'category'    => sanitize_text_field( (string) ( $item['category'] ?? '' ) ),
			);
		}

		return $assistants;
	}

	/**
	 * Mints a one-time URL that opens an assistant chat in an iframe.
	 *
	 * @param array<string, mixed>  $body    Request body (assistant_id, reference_id, …).
	 * @param array<string, string> $headers Additional headers, e.g. X-User-Mail.
	 * @return array{url: string, expires_at: string}|WP_Error
	 */
	public function create_chat_session( array $body, array $headers = array() ) {
		$data = $this->request( 'POST', '/chat-sessions', $body, $headers );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$url = isset( $data['url'] ) ? esc_url_raw( (string) $data['url'], array( 'https' ) ) : '';

		if ( '' === $url ) {
			return new WP_Error( 'ruboja_invalid_response', __( 'Ruboja returned an unexpected response.', 'ruboja-assistants' ) );
		}

		return array(
			'url'        => $url,
			'expires_at' => isset( $data['expires_at'] ) ? (string) $data['expires_at'] : '',
		);
	}

	/**
	 * Sends a request and decodes the JSON answer.
	 *
	 * @param string                    $method  HTTP method.
	 * @param string                    $path    Path below the base URL.
	 * @param array<string, mixed>|null $body    JSON body.
	 * @param array<string, string>     $headers Additional headers.
	 * @return array<string, mixed>|WP_Error
	 */
	private function request( string $method, string $path, ?array $body = null, array $headers = array() ) {
		if ( '' === $this->key ) {
			return new WP_Error( 'ruboja_no_api_key', __( 'No Ruboja API key is configured.', 'ruboja-assistants' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array_merge(
				array(
					'Authorization' => 'Bearer ' . $this->key,
					'Accept'        => 'application/json',
					'User-Agent'    => 'RubojaAssistants/' . VERSION . '; WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
				),
				$headers
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ruboja_http_error', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$error = is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();

			return new WP_Error(
				'ruboja_api_error',
				isset( $error['message'] ) ? (string) $error['message'] : sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Ruboja answered with HTTP status %d.', 'ruboja-assistants' ),
					$status
				),
				array(
					'status' => $status,
					'code'   => isset( $error['code'] ) ? (string) $error['code'] : '',
				)
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ruboja_invalid_response', __( 'Ruboja returned an unexpected response.', 'ruboja-assistants' ) );
		}

		return $data;
	}
}
