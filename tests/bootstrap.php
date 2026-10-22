<?php
/**
 * PHPUnit bootstrap for the WordPress test suite (provided by wp-env).
 *
 * @package RubojaAssistants
 */

$ruboja_assistants_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $ruboja_assistants_tests_dir ) {
	$ruboja_assistants_tests_dir = '/wordpress-phpunit';
}

if ( ! file_exists( $ruboja_assistants_tests_dir . '/includes/functions.php' ) ) {
	echo "WordPress test suite not found in {$ruboja_assistants_tests_dir}. Run the tests with: npm run test:php" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Name required by the WordPress test suite.

require_once $ruboja_assistants_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/ruboja-assistants.php';
	}
);

require $ruboja_assistants_tests_dir . '/includes/bootstrap.php';
