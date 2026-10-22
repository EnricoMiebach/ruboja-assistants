<?php
/**
 * Tests for the GitHub updater.
 *
 * @package RubojaAssistants
 */

use Siteware\RubojaAssistants\Updater;

/**
 * Release parsing, update offer and "View details" dialog.
 */
class Test_Updater extends WP_UnitTestCase {

	const UPDATE_URI = 'https://github.com/owner/repo';

	/**
	 * Number of requests to the GitHub API during a test.
	 *
	 * @var int
	 */
	private $github_requests = 0;

	public function set_up() {
		parent::set_up();

		$this->github_requests = 0;
		delete_site_transient( Updater::CACHE_KEY );
		add_filter( 'pre_http_request', array( $this, 'fake_github' ), 10, 3 );
		// The test checkout has a .git folder; pretend it is an installed copy.
		add_filter( 'ruboja_assistants_is_git_checkout', '__return_false' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_github' ), 10 );
		remove_filter( 'ruboja_assistants_is_git_checkout', '__return_false' );
		parent::tear_down();
	}

	/**
	 * Answers the latest-release request like the GitHub API.
	 *
	 * @param false|array $preempt Short-circuit value.
	 * @param array       $args    Request arguments.
	 * @param string      $url     Request URL.
	 * @return false|array
	 */
	public function fake_github( $preempt, $args, $url ) {
		// Any repository: plugin_information() reads the real Update URI header.
		if ( 1 !== preg_match( '#^https://api\.github\.com/repos/[^/]+/[^/]+/releases/latest$#', $url ) ) {
			return $preempt;
		}

		++$this->github_requests;

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $this->release() ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	public function test_repository_is_read_from_update_uri() {
		$this->assertSame( 'owner/repo', Updater::repository( self::UPDATE_URI ) );
		$this->assertSame( 'owner/repo', Updater::repository( self::UPDATE_URI . '.git' ) );
		$this->assertSame( '', Updater::repository( 'https://example.com/owner/repo' ) );
	}

	public function test_parse_release_uses_the_zip_asset() {
		$release = Updater::parse_release( $this->release(), false );

		$this->assertSame( '9.9.9', $release['version'] );
		$this->assertSame( 'https://github.com/owner/repo/releases/download/v9.9.9/ruboja-assistants.zip', $release['package'] );
		$this->assertSame( 'https://api.github.com/repos/owner/repo/releases/assets/1', Updater::parse_release( $this->release(), true )['package'] );

		$this->assertNull( Updater::parse_release( array( 'prerelease' => true ) + $this->release(), false ) );
		$this->assertNull( Updater::parse_release( array( 'assets' => array() ) + $this->release(), false ) );
	}

	public function test_check_update_offers_the_release_and_caches_it() {
		$updater = new Updater();
		$data    = array(
			'UpdateURI'   => self::UPDATE_URI,
			'RequiresWP'  => '6.3',
			'RequiresPHP' => '7.4',
		);
		$file    = plugin_basename( Siteware\RubojaAssistants\PLUGIN_FILE );

		$update = $updater->check_update( false, $data, $file );

		$this->assertSame( '9.9.9', $update['version'] );
		$this->assertSame( 'ruboja-assistants', $update['slug'] );

		$updater->check_update( false, $data, $file );
		$this->assertSame( 1, $this->github_requests, 'The release must be cached.' );

		$this->assertFalse( $updater->check_update( false, $data, 'other/plugin.php' ) );
	}

	public function test_git_checkouts_are_not_offered_updates() {
		remove_filter( 'ruboja_assistants_is_git_checkout', '__return_false' );
		add_filter( 'ruboja_assistants_is_git_checkout', '__return_true' );

		$update = ( new Updater() )->check_update( false, array( 'UpdateURI' => self::UPDATE_URI ), plugin_basename( Siteware\RubojaAssistants\PLUGIN_FILE ) );

		$this->assertFalse( $update );
		$this->assertSame( 0, $this->github_requests );

		remove_filter( 'ruboja_assistants_is_git_checkout', '__return_true' );
	}

	public function test_force_check_clears_the_cache() {
		set_site_transient( Updater::CACHE_KEY, array( 'repository' => 'owner/repo' ), HOUR_IN_SECONDS );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['force-check'] = '1';

		( new Updater() )->clear_cache_on_force_check();

		unset( $_GET['force-check'] );
		$this->assertFalse( get_site_transient( Updater::CACHE_KEY ) );
	}

	public function test_details_dialog_shows_release_notes() {
		$info = ( new Updater() )->plugin_information( false, 'plugin_information', (object) array( 'slug' => 'ruboja-assistants' ) );

		$this->assertIsObject( $info );
		$this->assertSame( 'Ruboja Assistants', $info->name );
		$this->assertStringContainsString( '<li>Neu: <strong>Details</strong></li>', $info->sections['changelog'] );
		$this->assertStringNotContainsString( '<script>', $info->sections['changelog'] );

		$this->assertFalse( ( new Updater() )->plugin_information( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );
	}

	/**
	 * A release as returned by the GitHub API.
	 *
	 * @return array<string, mixed>
	 */
	private function release() {
		return array(
			'tag_name'     => 'v9.9.9',
			'html_url'     => 'https://github.com/owner/repo/releases/tag/v9.9.9',
			'published_at' => '2026-10-22T12:00:00Z',
			'body'         => "### Added\n- Neu: **Details**\n- <script>alert(1)</script>",
			'assets'       => array(
				array(
					'name'                 => 'ruboja-assistants.zip',
					'url'                  => 'https://api.github.com/repos/owner/repo/releases/assets/1',
					'browser_download_url' => 'https://github.com/owner/repo/releases/download/v9.9.9/ruboja-assistants.zip',
				),
			),
		);
	}
}
