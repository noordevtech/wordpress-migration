<?php
/**
 * Plugin Name:       NoorDev Migrate
 * Plugin URI:        https://noordev.com
 * Description:       Live site-to-site migration. Syncs database and files batch by batch to a staging area on the target site, resumes automatically after interruptions, and performs an atomic cutover that preserves the target domain.
 * Version:           1.0.5
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            NoorDev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       noordev-migrate
 */

defined( 'ABSPATH' ) || exit;

define( 'NDM_VERSION', '1.0.5' );
define( 'NDM_PLUGIN_FILE', __FILE__ );
define( 'NDM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NDM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'NDM_STAGE_TABLE_PREFIX', 'ndmstg_' );
define( 'NDM_BACKUP_TABLE_PREFIX', 'ndmbak_' );
define( 'NDM_REST_NAMESPACE', 'ndm/v1' );

require_once NDM_PLUGIN_DIR . 'includes/class-ndm-log.php';
require_once NDM_PLUGIN_DIR . 'includes/class-ndm-auth.php';
require_once NDM_PLUGIN_DIR . 'includes/class-ndm-state.php';
require_once NDM_PLUGIN_DIR . 'includes/class-ndm-search-replace.php';
require_once NDM_PLUGIN_DIR . 'includes/class-ndm-db-exporter.php';
require_once NDM_PLUGIN_DIR . 'includes/class-ndm-db-importer.php';
require_once NDM_PLUGIN_DIR . 'includes/class-ndm-file-scanner.php';
require_once NDM_PLUGIN_DIR . 'includes/class-ndm-file-receiver.php';
require_once NDM_PLUGIN_DIR . 'includes/class-ndm-client.php';
require_once NDM_PLUGIN_DIR . 'includes/class-ndm-batch-runner.php';
require_once NDM_PLUGIN_DIR . 'includes/class-ndm-finalizer.php';
require_once NDM_PLUGIN_DIR . 'includes/class-ndm-rest-api.php';
require_once NDM_PLUGIN_DIR . 'includes/class-ndm-admin.php';

/**
 * Bootstrap.
 */
function ndm_init() {
	NDM_Rest_Api::instance()->register_hooks();
	NDM_Batch_Runner::instance()->register_hooks();

	if ( is_admin() ) {
		NDM_Admin::instance()->register_hooks();
	}
}
add_action( 'plugins_loaded', 'ndm_init' );

register_activation_hook( __FILE__, 'ndm_activate' );
/**
 * On activation make sure a secret exists so the site can act as a destination.
 */
function ndm_activate() {
	if ( ! get_option( 'ndm_secret' ) ) {
		update_option( 'ndm_secret', NDM_Auth::generate_secret(), false );
	}
}

register_deactivation_hook( __FILE__, 'ndm_deactivate' );
/**
 * Clear scheduled ticks on deactivation.
 */
function ndm_deactivate() {
	wp_clear_scheduled_hook( 'ndm_cron_tick' );
}
