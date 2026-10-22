<?php
/**
 * Updates from GitHub releases.
 *
 * @package RubojaAssistants
 */

namespace Siteware\RubojaAssistants;

defined( 'ABSPATH' ) || exit;

/**
 * Offers new versions under Plugins and Dashboard → Updates when a GitHub release is published.
 *
 * The repository comes from the "Update URI" plugin header
 * (https://github.com/<owner>/<repo>). Each release must carry the asset
 * ruboja-assistants.zip, which the release workflow builds. For a private
 * repository define RUBOJA_ASSISTANTS_GITHUB_TOKEN (read access to contents)
 * in wp-config.php.
 *
 * Git checkouts (a .git folder next to the plugin file) are never offered an
 * update, so WordPress cannot overwrite a working copy during development.
 */
final class Updater {

	const SLUG           = 'ruboja-assistants';
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
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 20, 3 );
		add_filter( 'http_request_args', array( $this, 'authorize_download' ), 10, 2 );
		// Before core's own check on the same hook (priority 10).
		add_action( 'load-update-core.php', array( $this, 'clear_cache_on_force_check' ), 9 );
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
		if ( plugin_basename( PLUGIN_FILE ) !== $plugin_file || self::is_git_checkout() ) {
			return $update;
		}

		$release = $this->release_for( (string) ( $plugin_data['UpdateURI'] ?? '' ) );

		if ( null === $release ) {
			return $update;
		}

		return array(
			'id'           => $plugin_data['UpdateURI'],
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'tested'       => self::normalize_tested( $release['tested'] ),
			'requires'     => '' !== $release['requires'] ? $release['requires'] : ( $plugin_data['RequiresWP'] ?? '' ),
			'requires_php' => '' !== $release['requires_php'] ? $release['requires_php'] : ( $plugin_data['RequiresPHP'] ?? '' ),
		);
	}

	/**
	 * Fills the "View details" dialog with plugin data and the release notes.
	 *
	 * @param false|object|array $result Result from earlier filters.
	 * @param string             $action plugins_api action.
	 * @param object             $args   Request arguments, including the slug.
	 * @return false|object|array
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin  = get_plugin_data( PLUGIN_FILE, false, true );
		$release = $this->release_for( (string) ( $plugin['UpdateURI'] ?? '' ) );
		$local   = self::parse_readme_headers( (string) file_get_contents( dirname( PLUGIN_FILE ) . '/readme.txt' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

		// Requirements of the offered version, falling back to the installed one.
		$requires     = null !== $release && '' !== $release['requires'] ? $release['requires'] : $plugin['RequiresWP'];
		$requires_php = null !== $release && '' !== $release['requires_php'] ? $release['requires_php'] : $plugin['RequiresPHP'];
		$tested       = null !== $release && '' !== $release['tested'] ? $release['tested'] : $local['tested'];

		if ( null !== $release && '' !== $release['notes'] ) {
			$changelog = self::markdown_to_html( $release['notes'] );
		} else {
			$changelog = sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( null !== $release ? $release['url'] : (string) $plugin['PluginURI'] ),
				esc_html__( 'Release notes on GitHub', 'ruboja-assistants' )
			);
		}

		return (object) array(
			'name'          => $plugin['Name'],
			'slug'          => self::SLUG,
			'version'       => null !== $release ? $release['version'] : $plugin['Version'],
			'author'        => $plugin['Author'],
			'homepage'      => $plugin['PluginURI'],
			'requires'      => $requires,
			'requires_php'  => $requires_php,
			'tested'        => self::normalize_tested( $tested ),
			'last_updated'  => null !== $release ? $release['published'] : '',
			'download_link' => null !== $release ? $release['package'] : '',
			'sections'      => array(
				'description' => wpautop( esc_html( $plugin['Description'] ) ),
				'changelog'   => $changelog,
			),
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
	 * Makes "Check again" under Dashboard → Updates look for a new release right away.
	 *
	 * Core forces only its own version check there; plugins are checked at most
	 * once a minute. Opening the page and then clicking "Check again" would
	 * therefore keep the old result, so the plugin check is forced as well.
	 *
	 * @return void
	 */
	public function clear_cache_on_force_check(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag of core's "Check again" link.
		if ( ! isset( $_GET['force-check'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$this->clear_cache();

		$current = get_site_transient( 'update_plugins' );

		if ( is_object( $current ) ) {
			$current->last_checked = 0;
			set_site_transient( 'update_plugins', $current );
		}
	}

	/**
	 * Forgets the cached release.
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
	 * Turns the GitHub API answer into update data.
	 *
	 * @param mixed $data    Decoded JSON of a release.
	 * @param bool  $via_api Whether the asset must be fetched through the API (private repository).
	 * @return array<string, string>|null version, tag, url, package, notes, published, tested, requires, requires_php.
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
				'version'      => $version,
				'tag'          => (string) $data['tag_name'],
				'url'          => (string) ( $data['html_url'] ?? '' ),
				'package'      => $package,
				'notes'        => (string) ( $data['body'] ?? '' ),
				'published'    => (string) ( $data['published_at'] ?? '' ),
				// Filled from the release's readme.txt by latest_release().
				'tested'       => '',
				'requires'     => '',
				'requires_php' => '',
			);
		}

		return null;
	}

	/**
	 * Reads the requirement headers of a readme.txt.
	 *
	 * @param string $readme Content of readme.txt.
	 * @return array{tested: string, requires: string, requires_php: string}
	 */
	public static function parse_readme_headers( string $readme ): array {
		$headers = array(
			'tested'       => 'Tested up to',
			'requires'     => 'Requires at least',
			'requires_php' => 'Requires PHP',
		);

		foreach ( $headers as $key => $label ) {
			$headers[ $key ] = 1 === preg_match( '/^' . preg_quote( $label, '/' ) . ':[ \t]*([0-9][0-9.]*)/mi', $readme, $matches ) ? $matches[1] : '';
		}

		return $headers;
	}

	/**
	 * Expands "Tested up to: 7.1" to the running 7.1.x, like WordPress.org does.
	 *
	 * Otherwise WordPress 7.1.3 counts as newer than 7.1 and warns that the
	 * plugin was not tested with it.
	 *
	 * @param string $tested Value from readme.txt.
	 * @return string
	 */
	public static function normalize_tested( string $tested ): string {
		$running = (string) get_bloginfo( 'version' );

		if ( 1 === preg_match( '/^\d+\.\d+$/', $tested ) && 0 === strpos( $running, $tested . '.' ) ) {
			return $running;
		}

		return $tested;
	}

	/**
	 * Converts the small Markdown subset of release notes (headings, lists, bold, code) to HTML.
	 *
	 * @param string $markdown Release notes.
	 * @return string
	 */
	public static function markdown_to_html( string $markdown ): string {
		$html    = '';
		$in_list = false;

		foreach ( (array) preg_split( '/\R/', $markdown ) as $line ) {
			$line = trim( (string) $line );

			if ( 1 === preg_match( '/^[-*]\s+(.*)$/', $line, $matches ) ) {
				$html   .= ( $in_list ? '' : '<ul>' ) . '<li>' . self::inline_markdown( $matches[1] ) . '</li>';
				$in_list = true;
				continue;
			}

			if ( $in_list ) {
				$html   .= '</ul>';
				$in_list = false;
			}

			if ( '' === $line ) {
				continue;
			}

			if ( 1 === preg_match( '/^#{1,6}\s+(.*)$/', $line, $matches ) ) {
				$html .= '<h4>' . self::inline_markdown( $matches[1] ) . '</h4>';
			} else {
				$html .= '<p>' . self::inline_markdown( $line ) . '</p>';
			}
		}

		return $html . ( $in_list ? '</ul>' : '' );
	}

	/**
	 * Escapes a line and applies bold, inline code and links.
	 *
	 * @param string $text Markdown text.
	 * @return string
	 */
	private static function inline_markdown( string $text ): string {
		$text = esc_html( $text );
		$text = (string) preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		$text = (string) preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );

		return make_clickable( $text );
	}

	/**
	 * Latest release of the repository named in the Update URI.
	 *
	 * @param string $update_uri Value of the Update URI header.
	 * @return array<string, string>|null See parse_release().
	 */
	private function release_for( string $update_uri ): ?array {
		$repository = self::repository( $update_uri );

		return '' !== $repository ? $this->latest_release( $repository ) : null;
	}

	/**
	 * Fetches the latest release and its readme.txt headers, cached for a few hours.
	 *
	 * @param string $repository owner/repo.
	 * @return array<string, string>|null See parse_release().
	 */
	private function latest_release( string $repository ): ?array {
		$cached = get_site_transient( self::CACHE_KEY );

		// Entries written by 0.1.0/0.1.1 lack the readme fields and are fetched again.
		if ( is_array( $cached ) && ( $cached['repository'] ?? '' ) === $repository
			&& ( null === $cached['release'] || isset( $cached['release']['tested'] ) ) ) {
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

		if ( null !== $release ) {
			$readme = wp_remote_get(
				'https://api.github.com/repos/' . $repository . '/contents/readme.txt?ref=' . rawurlencode( $release['tag'] ),
				array(
					'timeout' => 10,
					'headers' => array( 'Accept' => 'application/vnd.github.raw' ),
				)
			);

			if ( ! is_wp_error( $readme ) && 200 === (int) wp_remote_retrieve_response_code( $readme ) ) {
				$release = array_merge( $release, self::parse_readme_headers( wp_remote_retrieve_body( $readme ) ) );
			}
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
	 * Whether the plugin runs from a Git working copy.
	 *
	 * @return bool
	 */
	private static function is_git_checkout(): bool {
		/**
		 * Filters whether updates are suppressed because the plugin is a Git checkout.
		 *
		 * @param bool $is_checkout True if a .git folder exists next to the plugin file.
		 */
		return (bool) apply_filters( 'ruboja_assistants_is_git_checkout', file_exists( dirname( PLUGIN_FILE ) . '/.git' ) );
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
