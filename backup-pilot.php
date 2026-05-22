<?php
/**
 * Plugin Name: Backup Pilot
 * Description: Create, restore, and migrate full WordPress site backups from the admin area.
 * Version: 1.0.0
 * Author: Yousef Amer
 * Author URI: https://github.com/joexamer
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: backup-pilot
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package BackupPilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BPILOT_VERSION', '1.0.0' );
define( 'BPILOT_FILE', __FILE__ );
define( 'BPILOT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BPILOT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BPILOT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once BPILOT_PLUGIN_DIR . 'includes/class-bpilot-filesystem.php';
require_once BPILOT_PLUGIN_DIR . 'includes/class-bpilot-settings.php';
require_once BPILOT_PLUGIN_DIR . 'includes/class-bpilot-restore-history.php';
require_once BPILOT_PLUGIN_DIR . 'includes/class-bpilot-diagnostics.php';
require_once BPILOT_PLUGIN_DIR . 'includes/class-bpilot-retention.php';
require_once BPILOT_PLUGIN_DIR . 'includes/class-bpilot-remote-storage.php';
require_once BPILOT_PLUGIN_DIR . 'includes/class-bpilot-scheduler.php';
require_once BPILOT_PLUGIN_DIR . 'includes/class-bpilot-database.php';
require_once BPILOT_PLUGIN_DIR . 'includes/class-bpilot-archive.php';
require_once BPILOT_PLUGIN_DIR . 'includes/class-bpilot-search-replace.php';
require_once BPILOT_PLUGIN_DIR . 'includes/class-bpilot-backup-manager.php';
require_once BPILOT_PLUGIN_DIR . 'includes/class-bpilot-restore-manager.php';
require_once BPILOT_PLUGIN_DIR . 'includes/class-bpilot-job-manager.php';
require_once BPILOT_PLUGIN_DIR . 'includes/class-bpilot-admin.php';
require_once BPILOT_PLUGIN_DIR . 'includes/class-bpilot-plugin.php';

register_activation_hook( __FILE__, array( 'BPILOT_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BPILOT_Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		BPILOT_Plugin::instance()->init();
	}
);
