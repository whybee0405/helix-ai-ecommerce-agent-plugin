<?php
/**
 * Plugin Name: Helix Connector
 * Description: Syncs your WooCommerce catalog with the Helix AI commerce intelligence platform.
 * Version: 0.4.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'HELIX_CONNECTOR_VERSION', '0.4.0' );
define( 'HELIX_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );

require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-api-client.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-sync.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-webhooks.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-admin.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-widget.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-dashboard.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-updater.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-branding.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-cost-dashboard.php';

function helix_connector_init(): void {
    Helix_Admin::init();
    Helix_Webhooks::init();
    Helix_Widget::init();
    Helix_Dashboard::init();
    Helix_Updater::init();
    Helix_Branding::init();
    Helix_Cost_Dashboard::init();
}
add_action( 'plugins_loaded', 'helix_connector_init' );

register_activation_hook( __FILE__, 'helix_connector_activate' );
function helix_connector_activate(): void {
    update_option( 'helix_activated', true );
}

register_deactivation_hook( __FILE__, 'helix_connector_deactivate' );
function helix_connector_deactivate(): void {
    Helix_Webhooks::remove_webhooks();
    delete_option( 'helix_tenant_id' );
    delete_option( 'helix_public_key' );
    delete_option( 'helix_webhook_secret' );
    delete_option( 'helix_admin_secret' );
}
