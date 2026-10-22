<?php
/**
 * Updates from GitHub releases.
 *
 * @package RubojaAssistants
 */

namespace Siteware\RubojaAssistants;

defined( 'ABSPATH' ) || exit;

/**
 * Offers new versions in Dashboard → Updates when a GitHub release is published.
 *
 * The repository comes from the "Update URI" plugin header
 * (https://github.com/<owner>/<repo>). Each release must carry the asset
 * ruboja-assistants.zip, which the release workflow builds. For a private
 * repository define RUBOJA_ASSISTANTS_GITHUB_TOKEN (read access to contents)
 * in wp-config.php.
 */
final class Updater {

	const ASSET_NAME     = 'ruboja-assistants.zip';
	const TOKEN_CONSTANT = 'RUBOJA_ASSISTANTS_GITHUB_TOKEN';
	const CACHE_KEY      = 'ruboja_assistants_release';
	const CACHE_TTL      = 6 * HOUR_IN_SECONDS;

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'update_plugins_github.com', array( $this, 'check_update' ), 10, 3 );
		add_filter( 'http_request_args', array( $this, 'authorize_download' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 0 );
	}

	/**
	 * Answers WordPress' update check for plugins hosted on github.com.
	 *
	 * @param array<string, mixed>|false $update      Update data from earlier filters.
	 * @param array<string, string>      $plugin_data Plugin headers.
	 * @param string                     $plugin_file Plugin basename.
	 * @return array<string, mixed>|false
	 */
	public function check_update( $update, array $plugin_data, string $plugin_file ) {
		if ( plugin_basename( PLUGIN_FILE ) !== $plugin_file ) {
			return $update;
		}

		$repository = self::repository( (string) ( $plugin_data['UpdateURI'] ?? '' ) );
		$release    = '' !== $repository ? $this->latest_release( $repository ) : null;

		if ( null === $release ) {
			return $update;
		}

		return array(
			'id'           => $plugin_data['UpdateURI'],
			'slug'         => dirname( $plugin_file ),
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => $plugin_data['RequiresWP'] ?? '',
			'requires_php' => $plugin_data['RequiresPHP'] ?? '',
		);
	}

	/**
	 * Adds the token and the binary Accept header when downloading a private asset.
	 *
	 * @param array<string, mixed> $args HTTP request arguments.
	 * @param string               $url  Request URL.
	 * @return array<string, mixed>
	 */
	public function authorize_download( array $args, string $url ): array {
		$token = self::token();

		if ( '' === $token || 0 !== strpos( $url, 'https://api.github.com/repos/' ) ) {
			return $args;
		}

		$args['headers'] = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();

		$args['headers']['Authorization'] = 'Bearer ' . $token;

		if ( false !== strpos( $url, '/releases/assets/' ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}

		return $args;
	}

	/**
	 * Forgets the cached release after an update.
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Extracts owner/repo from a github.com URL.
	 *
	 * @param string $update_uri Value of the Update URI header.
	 * @return string owner/repo or an empty string.
	 */
	public static function repository( string $update_uri ): string {
		if ( 1 !== preg_match( '#^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', $update_uri, $matches ) ) {
			return '';
		}

		return $matches[1] . '/' . $matches[2];
	}

	/**
	 * Fetches the latest release, cached for a few hours.
	 *
	 * @param string $repository owner/repo.
	 * @return array{version: string, url: string, package: string}|null
	 */
	private function latest_release( string $repository ): ?array {
		$cached = get_site_transient( self::CACHE_KEY );

		if ( is_array( $cached ) && ( $cached['repository'] ?? '' ) === $repository ) {
			return $cached['release'];
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $repository . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		$release = null;

		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$release = self::parse_release( json_decode( wp_remote_retrieve_body( $response ), true ), '' !== self::token() );
		}

		// Cache failures too, so a missing repository does not slow down every admin request.
		set_site_transient(
			self::CACHE_KEY,
			array(
				'repository' => $repository,
				'release'    => $release,
			),
			null === $release ? HOUR_IN_SECONDS : self::CACHE_TTL
		);

		return $release;
	}

	/**
	 * Turns the GitHub API answer into update data.
	 *
	 * @param mixed $data    Decoded JSON of a release.
	 * @param bool  $via_api Whether the asset must be fetched through the API (private repository).
	 * @return array{version: string, url: string, package: string}|null
	 */
	public static function parse_release( $data, bool $via_api ): ?array {
		if ( ! is_array( $data ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) || empty( $data['tag_name'] ) ) {
			return null;
		}

		$version = ltrim( (string) $data['tag_name'], 'vV' );

		if ( 1 !== preg_match( '/^\d+\.\d+\.\d+/', $version ) ) {
			return null;
		}

		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( ! is_array( $asset ) || self::ASSET_NAME !== ( $asset['name'] ?? '' ) ) {
				continue;
			}

			$package = $via_api ? (string) ( $asset['url'] ?? '' ) : (string) ( $asset['browser_download_url'] ?? '' );

			if ( '' === $package ) {
				return null;
			}

			return array(
				'version' => $version,
				'url'     => (string) ( $data['html_url'] ?? '' ),
				'package' => $package,
			);
		}

		return null;
	}

	/**
	 * GitHub token for private repositories.
	 *
	 * @return string
	 */
	private static function token(): string {
		return defined( self::TOKEN_CONSTANT ) ? trim( (string) constant( self::TOKEN_CONSTANT ) ) : '';
	}
}
