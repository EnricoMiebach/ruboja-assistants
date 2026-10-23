<?php
/**
 * Plugin Name:       Ruboja Assistants
 * Plugin URI:        https://github.com/EnricoMiebach/ruboja-assistants
 * Description:       Embeds Ruboja AI assistants on your website, inline in the content or as a floating chat window.
 * Version:           0.1.8
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Siteware GmbH
 * Author URI:        https://www.siteware.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ruboja-assistants
 * Domain Path:       /languages
 * Update URI:        https://github.com/EnricoMiebach/ruboja-assistants
 *
 * @package RubojaAssistants
 */

namespace Siteware\RubojaAssistants;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.1.8';
const PLUGIN_FILE = __FILE__;

require_once __DIR__ . '/includes/class-crypto.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-api-client.php';
require_once __DIR__ . '/includes/class-rest-controller.php';
require_once __DIR__ . '/includes/class-embed.php';
require_once __DIR__ . '/includes/class-admin-page.php';
require_once __DIR__ . '/includes/class-updater.php';
require_once __DIR__ . '/includes/class-plugin.php';

Plugin::instance();
