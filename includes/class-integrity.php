<?php
/**
 * Integrity check of the plugin files, reported to the plugin developer.
 *
 * @package RubojaAssistants
 */

namespace Siteware\RubojaAssistants;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the installed plugin files with the SHA-256 checksums published with
 * the GitHub release of the installed version (checksums.json, built by
 * bin/build-zip.sh) and tells the plugin developer when files were changed,
 * removed or added, and again when everything is back to normal.
 *
 * The report always runs and is documented in readme.txt and on the settings
 * page. It contains the site address, plugin, WordPress and PHP version, the
 * status and the file names; no personal data. Today it is sent by e-mail; a
 * Ruboja data source can be added as a second channel via REPORT_WEBHOOK.
 *
 * The checksums come from GitHub, not from the site, so changing a file and the
 * list on the server is not enough to hide a change. Someone who can write the
 * plugin files can still remove this check itself; it catches edits, broken
 * uploads and planted files, not a determined attacker with full server access.
 */
final class Integrity {

	const OPTION        = 'ruboja_assistants_integrity';
	const CRON_HOOK     = 'ruboja_assistants_integrity_check';
	const ACTION_CHECK  = 'ruboja_assistants_integrity_check_now';
	const MANIFEST_NAME = 'checksums.json';
	const CACHE_KEY     = 'ruboja_assistants_manifest';
	const CACHE_TTL     = 12 * HOUR_IN_SECONDS;

	/**
	 * Recipient of the integrity reports (plugin developer).
	 */
	const DEVELOPER_EMAIL = 'enricomiebach@gmail.com';

	/**
	 * Webhook of a Ruboja data source that collects the reports of all sites.
	 * Empty until the data source exists; then every report is also posted there as JSON.
	 */
	const REPORT_WEBHOOK = '';

	/**
	 * Files that operating systems and FTP clients leave behind; never reported as added.
	 */
	const IGNORED_FILES = array( '.DS_Store', 'Thumbs.db', 'desktop.ini' );

	const STATUS_OK          = 'ok';
	const STATUS_MODIFIED    = 'modified';
	const STATUS_UNAVAILABLE = 'unavailable';
	const STATUS_DEVELOPMENT = 'development';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		add_action( 'admin_post_' . self::ACTION_CHECK, array( $this, 'handle_check_now' ) );
		add_filter( 'site_status_tests', array( $this, 'add_site_health_test' ) );
		add_action( 'upgrader_process_complete', array( $this, 'forget_after_update' ), 20, 2 );
	}

	/**
	 * Schedules the daily check.
	 *
	 * @return void
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Removes the daily check (plugin deactivation).
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Checks the files, stores the result and reports changes to the developer.
	 *
	 * @return array<string, mixed> See result().
	 */
	public function run(): array {
		$previous = self::result();
		$result   = $this->check();

		update_option( self::OPTION, $result, false );

		$changed = self::STATUS_MODIFIED === $result['status'] && self::findings_key( $result ) !== self::findings_key( $previous );
		$healed  = self::STATUS_OK === $result['status'] && self::STATUS_MODIFIED === $previous['status'];

		if ( $changed || $healed ) {
			$this->report( $result );
		}

		return $result;
	}

	/**
	 * Forgets the cached checksums after this plugin was updated or reinstalled, as they may belong
	 * to another version. The last result is kept, so a reinstall that repairs the files is reported
	 * as "back to normal" on the next check.
	 *
	 * @param \WP_Upgrader         $upgrader Upgrader.
	 * @param array<string, mixed> $options  Update details.
	 * @return void
	 */
	public function forget_after_update( $upgrader, array $options ): void {
		$plugins = isset( $options['plugins'] ) ? (array) $options['plugins'] : array();
		$ours    = in_array( plugin_basename( PLUGIN_FILE ), $plugins, true ) || 'install' === ( $options['action'] ?? '' );

		if ( 'plugin' === ( $options['type'] ?? '' ) && $ours ) {
			delete_transient( self::CACHE_KEY );
		}
	}

	/**
	 * Checks the files without storing or reporting the result.
	 *
	 * @return array<string, mixed>
	 */
	public function check(): array {
		/**
		 * Filters the folder that is checked (for tests).
		 *
		 * @param string $dir Plugin folder.
		 */
		$dir = (string) apply_filters( 'ruboja_assistants_integrity_dir', dirname( PLUGIN_FILE ) );

		/** This filter is documented in includes/class-updater.php */
		if ( (bool) apply_filters( 'ruboja_assistants_is_git_checkout', file_exists( $dir . '/.git' ) ) ) {
			return self::make_result( self::STATUS_DEVELOPMENT );
		}

		$manifest = $this->manifest();

		if ( null === $manifest ) {
			return self::make_result( self::STATUS_UNAVAILABLE );
		}

		$diff  = self::compare( $manifest, self::hash_directory( $dir ) );
		$clean = empty( $diff['modified'] ) && empty( $diff['missing'] ) && empty( $diff['added'] );

		return self::make_result( $clean ? self::STATUS_OK : self::STATUS_MODIFIED, $diff );
	}

	/**
	 * The last stored result.
	 *
	 * @return array<string, mixed> status, checked_at, version, modified, missing, added.
	 */
	public static function result(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) && isset( $stored['status'] ) ? $stored : self::make_result( '' );
	}

	/**
	 * SHA-256 of every file below a directory, keyed by relative path.
	 *
	 * @param string $dir Directory.
	 * @return array<string, string>
	 */
	public static function hash_directory( string $dir ): array {
		$hashes = array();
		$dir    = untrailingslashit( wp_normalize_path( $dir ) );

		if ( ! is_dir( $dir ) ) {
			return $hashes;
		}

		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( ! $file->isFile() || in_array( $file->getFilename(), self::IGNORED_FILES, true ) ) {
				continue;
			}

			$path                = wp_normalize_path( $file->getPathname() );
			$relative            = ltrim( substr( $path, strlen( $dir ) ), '/' );
			$hashes[ $relative ] = (string) hash_file( 'sha256', $path );
		}

		ksort( $hashes );

		return $hashes;
	}

	/**
	 * Compares published and local checksums.
	 *
	 * @param array<string, string> $expected Checksums from the release.
	 * @param array<string, string> $actual   Checksums of the installed files.
	 * @return array{modified: string[], missing: string[], added: string[]}
	 */
	public static function compare( array $expected, array $actual ): array {
		$modified = array();

		foreach ( $expected as $path => $hash ) {
			if ( isset( $actual[ $path ] ) && ! hash_equals( (string) $hash, (string) $actual[ $path ] ) ) {
				$modified[] = (string) $path;
			}
		}

		return array(
			'modified' => $modified,
			'missing'  => array_values( array_map( 'strval', array_keys( array_diff_key( $expected, $actual ) ) ) ),
			'added'    => array_values( array_map( 'strval', array_keys( array_diff_key( $actual, $expected ) ) ) ),
		);
	}

	/**
	 * The report sent to the developer.
	 *
	 * @param array<string, mixed> $result Result.
	 * @return array<string, mixed>
	 */
	public static function report_payload( array $result ): array {
		return array(
			'site'       => home_url( '/' ),
			'plugin'     => 'ruboja-assistants',
			'version'    => VERSION,
			'wordpress'  => get_bloginfo( 'version' ),
			'php'        => PHP_VERSION,
			'status'     => $result['status'],
			'checked_at' => gmdate( 'c', (int) $result['checked_at'] ),
			'modified'   => $result['modified'],
			'missing'    => $result['missing'],
			'added'      => $result['added'],
		);
	}

	/**
	 * Runs the check from the settings page.
	 *
	 * @return void
	 */
	public function handle_check_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'ruboja-assistants' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_CHECK );
		delete_transient( self::CACHE_KEY );
		$this->run();

		wp_safe_redirect( admin_url( 'options-general.php?page=' . Admin_Page::SLUG . '#ruboja-assistants-integrity' ) );
		exit;
	}

	/**
	 * Adds the check to Tools → Site Health.
	 *
	 * @param array<string, mixed> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public function add_site_health_test( array $tests ): array {
		$tests['direct']['ruboja_assistants_integrity'] = array(
			'label' => __( 'Ruboja Assistants file integrity', 'ruboja-assistants' ),
			'test'  => array( $this, 'site_health_test' ),
		);

		return $tests;
	}

	/**
	 * Site Health result (runs a fresh check).
	 *
	 * @return array<string, mixed>
	 */
	public function site_health_test(): array {
		$result = $this->run();
		$base   = array(
			'badge' => array(
				'label' => __( 'Security', 'ruboja-assistants' ),
				'color' => 'blue',
			),
			'test'  => 'ruboja_assistants_integrity',
		);

		switch ( $result['status'] ) {
			case self::STATUS_OK:
				return $base + array(
					'label'       => __( 'The files of Ruboja Assistants are unchanged', 'ruboja-assistants' ),
					'status'      => 'good',
					'description' => '<p>' . esc_html__( 'All plugin files match the checksums published with this version on GitHub.', 'ruboja-assistants' ) . '</p>',
				);

			case self::STATUS_MODIFIED:
				return $base + array(
					'label'       => __( 'The files of Ruboja Assistants were changed', 'ruboja-assistants' ),
					'status'      => 'critical',
					'description' => '<p>' . esc_html__( 'Some plugin files differ from the published version. The plugin developer has been informed. Reinstall the plugin from the release zip.', 'ruboja-assistants' ) . '</p>' . self::findings_html( $result ),
				);

			case self::STATUS_DEVELOPMENT:
				return $base + array(
					'label'       => __( 'Ruboja Assistants runs from a development copy', 'ruboja-assistants' ),
					'status'      => 'good',
					'description' => '<p>' . esc_html__( 'The plugin folder is a Git working copy, so its files are not compared with a release.', 'ruboja-assistants' ) . '</p>',
				);

			default:
				return $base + array(
					'label'       => __( 'The files of Ruboja Assistants could not be checked', 'ruboja-assistants' ),
					'status'      => 'recommended',
					'description' => '<p>' . esc_html__( 'The checksums for the installed version could not be loaded from GitHub. The check runs again automatically.', 'ruboja-assistants' ) . '</p>',
				);
		}
	}

	/**
	 * HTML list of changed, missing and added files.
	 *
	 * @param array<string, mixed> $result Result.
	 * @return string
	 */
	public static function findings_html( array $result ): string {
		$groups = array(
			'modified' => __( 'Changed', 'ruboja-assistants' ),
			'missing'  => __( 'Missing', 'ruboja-assistants' ),
			'added'    => __( 'Added', 'ruboja-assistants' ),
		);
		$html   = '';

		foreach ( $groups as $key => $label ) {
			if ( empty( $result[ $key ] ) ) {
				continue;
			}

			$items = array_map(
				static function ( $path ) {
					return '<li><code>' . esc_html( $path ) . '</code></li>';
				},
				(array) $result[ $key ]
			);

			$html .= sprintf( '<p><strong>%1$s</strong></p><ul class="ruboja-assistants-admin__files">%2$s</ul>', esc_html( $label ), implode( '', $items ) );
		}

		return $html;
	}

	/**
	 * Loads the published checksums of the installed version.
	 *
	 * @return array<string, string>|null
	 */
	private function manifest(): ?array {
		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) && ( $cached['version'] ?? '' ) === VERSION && is_array( $cached['files'] ?? null ) ) {
			return $cached['files'];
		}

		$headers    = get_file_data( PLUGIN_FILE, array( 'update_uri' => 'Update URI' ) );
		$repository = Updater::repository( (string) $headers['update_uri'] );

		if ( '' === $repository ) {
			return null;
		}

		$response = wp_remote_get(
			'https://github.com/' . $repository . '/releases/download/v' . VERSION . '/' . self::MANIFEST_NAME,
			array( 'timeout' => 15 )
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || VERSION !== ( $data['version'] ?? '' ) || ! is_array( $data['files'] ?? null ) ) {
			return null;
		}

		$files = array();

		foreach ( $data['files'] as $path => $hash ) {
			if ( is_string( $path ) && is_string( $hash ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $hash ) ) {
				$files[ $path ] = $hash;
			}
		}

		set_transient(
			self::CACHE_KEY,
			array(
				'version' => VERSION,
				'files'   => $files,
			),
			self::CACHE_TTL
		);

		return $files;
	}

	/**
	 * Sends the report to the developer: by e-mail, and to the Ruboja data source once configured.
	 *
	 * @param array<string, mixed> $result Result.
	 * @return void
	 */
	private function report( array $result ): void {
		$payload = self::report_payload( $result );
		$host    = (string) wp_parse_url( $payload['site'], PHP_URL_HOST );
		$healed  = self::STATUS_OK === $result['status'];
		$lines   = array();

		foreach ( array(
			'modified' => 'Geändert',
			'missing'  => 'Fehlt',
			'added'    => 'Hinzugefügt',
		) as $key => $label ) {
			foreach ( (array) $result[ $key ] as $path ) {
				$lines[] = '- ' . $label . ': ' . $path;
			}
		}

		// Written for the developer, so not translated.
		$subject = $healed
			? sprintf( '[Ruboja Assistants] Dateien wieder in Ordnung: %s', $host )
			: sprintf( '[Ruboja Assistants] Dateien verändert: %s', $host );

		$body = $healed
			? sprintf( "Die Plugin-Dateien auf %1\$s stimmen wieder mit Version %2\$s überein.\n\n", $payload['site'], $payload['version'] )
			: sprintf( "Auf %1\$s weichen Dateien von Ruboja Assistants %2\$s von der veröffentlichten Version ab:\n\n%3\$s\n\n", $payload['site'], $payload['version'], implode( "\n", $lines ) );

		$body .= sprintf(
			"WordPress %1\$s, PHP %2\$s, geprüft %3\$s\n\nBericht als JSON:\n%4\$s\n",
			$payload['wordpress'],
			$payload['php'],
			$payload['checked_at'],
			wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);

		wp_mail( self::DEVELOPER_EMAIL, $subject, $body );

		if ( '' !== self::REPORT_WEBHOOK ) {
			wp_remote_post(
				self::REPORT_WEBHOOK,
				array(
					'timeout'  => 5,
					'blocking' => false,
					'headers'  => array( 'Content-Type' => 'application/json' ),
					'body'     => wp_json_encode( $payload ),
				)
			);
		}
	}

	/**
	 * Builds a result array.
	 *
	 * @param string                  $status Status.
	 * @param array<string, string[]> $diff   Changed, missing and added files.
	 * @return array<string, mixed>
	 */
	private static function make_result( string $status, array $diff = array() ): array {
		return array(
			'status'     => $status,
			'checked_at' => '' === $status ? 0 : time(),
			'version'    => VERSION,
			'modified'   => $diff['modified'] ?? array(),
			'missing'    => $diff['missing'] ?? array(),
			'added'      => $diff['added'] ?? array(),
		);
	}

	/**
	 * Fingerprint of the findings, so the same finding is reported only once.
	 *
	 * @param array<string, mixed> $result Result.
	 * @return string
	 */
	private static function findings_key( array $result ): string {
		return md5( (string) wp_json_encode( array( $result['status'] ?? '', $result['modified'] ?? array(), $result['missing'] ?? array(), $result['added'] ?? array() ) ) );
	}
}
