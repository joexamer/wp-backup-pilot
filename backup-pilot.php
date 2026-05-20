<?php
/**
 * Plugin Name: Backup Pilot
 * Description: Create, restore, and migrate full WordPress site backups from the admin area.
 * Version: 1.0.0
 * Author: Yousef Amer
 * Author URI: https://github.com/joexamer
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: backup-pilot-main
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package WPBackupPilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPBP_VERSION', '1.0.0' );
define( 'WPBP_FILE', __FILE__ );
define( 'WPBP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPBP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPBP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once WPBP_PLUGIN_DIR . 'includes/class-wpbp-filesystem.php';
require_once WPBP_PLUGIN_DIR . 'includes/class-wpbp-settings.php';
require_once WPBP_PLUGIN_DIR . 'includes/class-wpbp-restore-history.php';
require_once WPBP_PLUGIN_DIR . 'includes/class-wpbp-diagnostics.php';
require_once WPBP_PLUGIN_DIR . 'includes/class-wpbp-retention.php';
require_once WPBP_PLUGIN_DIR . 'includes/class-wpbp-remote-storage.php';
require_once WPBP_PLUGIN_DIR . 'includes/class-wpbp-scheduler.php';
require_once WPBP_PLUGIN_DIR . 'includes/class-wpbp-database.php';
require_once WPBP_PLUGIN_DIR . 'includes/class-wpbp-archive.php';
require_once WPBP_PLUGIN_DIR . 'includes/class-wpbp-search-replace.php';
require_once WPBP_PLUGIN_DIR . 'includes/class-wpbp-backup-manager.php';
require_once WPBP_PLUGIN_DIR . 'includes/class-wpbp-restore-manager.php';
require_once WPBP_PLUGIN_DIR . 'includes/class-wpbp-job-manager.php';
require_once WPBP_PLUGIN_DIR . 'includes/class-wpbp-admin.php';
require_once WPBP_PLUGIN_DIR . 'includes/class-wpbp-plugin.php';

register_activation_hook( __FILE__, array( 'WPBP_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPBP_Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		WPBP_Plugin::instance()->init();
	}
);
