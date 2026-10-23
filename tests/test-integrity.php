<?php
/**
 * Tests for the integrity check.
 *
 * @package RubojaAssistants
 */

use Siteware\RubojaAssistants\Integrity;
use const Siteware\RubojaAssistants\VERSION;

/**
 * Checksums, findings and the report to the developer.
 */
class Test_Integrity extends WP_UnitTestCase {

	/**
	 * Temporary plugin folder.
	 *
	 * @var string
	 */
	private $dir = '';

	/**
	 * Published checksums served by the fake GitHub.
	 *
	 * @var array<string, string>|null
	 */
	private $manifest = null;

	public function set_up() {
		parent::set_up();

		$this->dir = trailingslashit( get_temp_dir() ) . 'ruboja-integrity-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->dir . '/includes' );
		file_put_contents( $this->dir . '/ruboja-assistants.php', "<?php // main\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->dir . '/includes/class-a.php', "<?php // a\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->manifest = Integrity::hash_directory( $this->dir );

		delete_option( Integrity::OPTION );
		delete_transient( Integrity::CACHE_KEY );
		reset_phpmailer_instance();

		add_filter( 'ruboja_assistants_integrity_dir', array( $this, 'dir' ) );
		add_filter( 'ruboja_assistants_is_git_checkout', '__return_false' );
		add_filter( 'pre_http_request', array( $this, 'fake_github' ), 10, 3 );
		// The test site is "localhost", and wordpress@localhost is not a valid sender.
		add_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
	}

	public function tear_down() {
		remove_filter( 'ruboja_assistants_integrity_dir', array( $this, 'dir' ) );
		remove_filter( 'ruboja_assistants_is_git_checkout', '__return_false' );
		remove_filter( 'pre_http_request', array( $this, 'fake_github' ), 10 );
		remove_filter( 'wp_mail_from', array( $this, 'mail_from' ) );

		foreach ( array_keys( Integrity::hash_directory( $this->dir ) ) as $file ) {
			wp_delete_file( $this->dir . '/' . $file );
		}

		parent::tear_down();
	}

	/**
	 * Valid sender address for the test site.
	 *
	 * @return string
	 */
	public function mail_from() {
		return 'wordpress@example.com';
	}

	/**
	 * Folder under test.
	 *
	 * @return string
	 */
	public function dir() {
		return $this->dir;
	}

	/**
	 * Serves checksums.json of the installed version.
	 *
	 * @param false|array $preempt Short-circuit value.
	 * @param array       $args    Request arguments.
	 * @param string      $url     Request URL.
	 * @return false|array
	 */
	public function fake_github( $preempt, $args, $url ) {
		if ( false === strpos( $url, '/releases/download/v' . VERSION . '/checksums.json' ) ) {
			return $preempt;
		}

		return array(
			'headers'  => array(),
			'body'     => null === $this->manifest ? '' : wp_json_encode(
				array(
					'version' => VERSION,
					'files'   => $this->manifest,
				)
			),
			'response' => array(
				'code'    => null === $this->manifest ? 404 : 200,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	public function test_compare_finds_changed_missing_and_added_files() {
		$diff = Integrity::compare(
			array(
				'a.php' => str_repeat( 'a', 64 ),
				'b.php' => str_repeat( 'b', 64 ),
			),
			array(
				'a.php'        => str_repeat( 'f', 64 ),
				'backdoor.php' => str_repeat( 'c', 64 ),
			)
		);

		$this->assertSame( array( 'a.php' ), $diff['modified'] );
		$this->assertSame( array( 'b.php' ), $diff['missing'] );
		$this->assertSame( array( 'backdoor.php' ), $diff['added'] );
	}

	public function test_hash_directory_uses_relative_paths_and_ignores_os_files() {
		file_put_contents( $this->dir . '/.DS_Store', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$hashes = Integrity::hash_directory( $this->dir );

		$this->assertSame( array( 'includes/class-a.php', 'ruboja-assistants.php' ), array_keys( $hashes ) );
		$this->assertSame( hash( 'sha256', "<?php // a\n" ), $hashes['includes/class-a.php'] );
	}

	public function test_unchanged_files_are_ok_and_not_reported() {
		$result = ( new Integrity() )->run();

		$this->assertSame( Integrity::STATUS_OK, $result['status'] );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent( 0 ) );
	}

	public function test_changes_are_reported_to_the_developer_once_and_again_when_healed() {
		$integrity = new Integrity();
		$integrity->run();

		file_put_contents( $this->dir . '/includes/class-a.php', "<?php // a\neval( \$_GET['x'] );\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->dir . '/shell.php', "<?php // planted\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = $integrity->run();

		$this->assertSame( Integrity::STATUS_MODIFIED, $result['status'] );
		$this->assertSame( array( 'includes/class-a.php' ), $result['modified'] );
		$this->assertSame( array( 'shell.php' ), $result['added'] );

		$mail = tests_retrieve_phpmailer_instance()->get_sent( 0 );
		$this->assertSame( Integrity::DEVELOPER_EMAIL, $mail->to[0][0] );
		$this->assertStringContainsString( 'Dateien verändert', $mail->subject );
		$this->assertStringContainsString( 'Geändert: includes/class-a.php', $mail->body );
		$this->assertStringContainsString( 'Hinzugefügt: shell.php', $mail->body );
		$this->assertStringNotContainsString( (string) get_option( 'admin_email' ), $mail->body, 'No personal data in the report.' );

		$integrity->run();
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent( 1 ), 'The same finding is reported only once.' );

		file_put_contents( $this->dir . '/includes/class-a.php', "<?php // a\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		wp_delete_file( $this->dir . '/shell.php' );

		$this->assertSame( Integrity::STATUS_OK, $integrity->run()['status'] );
		$this->assertStringContainsString( 'wieder in Ordnung', tests_retrieve_phpmailer_instance()->get_sent( 1 )->subject );
	}

	public function test_missing_checksums_are_not_reported_as_a_change() {
		$this->manifest = null;

		$result = ( new Integrity() )->run();

		$this->assertSame( Integrity::STATUS_UNAVAILABLE, $result['status'] );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent( 0 ) );
	}

	public function test_git_checkouts_are_not_checked() {
		remove_filter( 'ruboja_assistants_is_git_checkout', '__return_false' );
		add_filter( 'ruboja_assistants_is_git_checkout', '__return_true' );

		$this->assertSame( Integrity::STATUS_DEVELOPMENT, ( new Integrity() )->run()['status'] );

		remove_filter( 'ruboja_assistants_is_git_checkout', '__return_true' );
	}

	public function test_report_payload_holds_no_personal_data() {
		$payload = Integrity::report_payload( Integrity::result() );

		$this->assertSame( array( 'site', 'plugin', 'version', 'wordpress', 'php', 'status', 'checked_at', 'modified', 'missing', 'added' ), array_keys( $payload ) );
	}
}
