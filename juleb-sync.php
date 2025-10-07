<?php
/**
 * Plugin Name: Juleb WooCommerce Sync
 * Plugin URI:  https://ucpksa.com/
 * Description: A custom plugin to synchronize WooCommerce data (Products, Orders, Customers) with Juleb ERP.
 * Version:     1.0.0
 * Author:      mohammed
 * Author URI:  https://mohamedyussry.github.io/
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: juleb-sync
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Define Constants
 */
define( 'JULEB_SYNC_VERSION', '1.0.0' );
define( 'JULEB_SYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JULEB_SYNC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Composer Autoloader
 */
require_once JULEB_SYNC_PLUGIN_DIR . 'vendor/autoload.php';

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require JULEB_SYNC_PLUGIN_DIR . 'includes/class-juleb-sync.php';

/**
 * QR Code Handler
 */
require JULEB_SYNC_PLUGIN_DIR . 'includes/class-qr-code-handler.php';

/**
 * Begins execution of the plugin.
 *
 * @since    1.0.0
 */
function run_juleb_sync() {

    $plugin = new Juleb_Sync();
    $plugin->run();

    // Initialize the QR Code handler
    new Juleb_QR_Code_Handler();

}
run_juleb_sync();


// --- Juleb Sync Licensing Debug Tools ---

// 1. Display the exact site URL to be used in the Google Sheet.
add_action('admin_notices', function() {
    if (current_user_can('manage_options')) {
        $site_url = get_site_url();
        $cache_clear_url = esc_url(add_query_arg('juleb_force_cache_delete', 'true'));
        
        echo "<div class='notice notice-info is-dismissible'>";
        echo "<p><strong>Juleb Sync Debug:</strong> The site URL for licensing is: <strong>" . esc_html($site_url) . "</strong></p>";
        echo "<p>Please copy this URL exactly into the 'site_url' column in your Google Sheet and ensure 'is_active' is set to 1.</p>";
        echo '<p>After updating the sheet, <a href="' . $cache_clear_url . '">click here to clear the license cache and re-check</a>.</p>';
        echo "</div>";
    }
});

// 2. Allow admins to force-delete the license cache by visiting a specific URL.
add_action('admin_init', function() {
    if (current_user_can('manage_options') && isset($_GET['juleb_force_cache_delete'])) {
        delete_transient('juleb_sync_license_status');
        // Redirect to remove the query arg and show a confirmation notice.
        wp_safe_redirect(add_query_arg('juleb_cache_deleted', 'true', remove_query_arg('juleb_force_cache_delete')));
        exit;
    }
});

// 3. Show a confirmation notice after the cache has been cleared.
add_action('admin_notices', function() {
    if (current_user_can('manage_options') && isset($_GET['juleb_cache_deleted'])) {
        echo "<div class='notice notice-success is-dismissible'><p><strong>Juleb Sync:</strong> The license cache has been cleared. The license status will be re-checked now.</p></div>";
    }
});