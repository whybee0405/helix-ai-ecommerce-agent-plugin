<?php
/**
 * Plugin Name: Helix Connector
 * Description: Syncs your WooCommerce catalog with the Helix AI commerce intelligence platform.
 * Version: 0.5.3
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'HELIX_CONNECTOR_VERSION', '0.5.3' );
define( 'HELIX_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );

require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-pack.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-api-client.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-sync.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-webhooks.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-admin.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-widget.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-dashboard.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-updater.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-branding.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-cost-dashboard.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-migrator.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-product-ask.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-inline-chat.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-product-faq.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-snapshot.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-action-log.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-quick-actions.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-performance-dash.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-scheduler.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-rollback.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-broken-links.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-uptime.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-error-log.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-backup.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-plugin-manager.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-snippets.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-alt-text.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-seo-meta.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-reviews.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-internal-links.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-digest.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-blog-generator.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-product-descriptions.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-repurpose.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-image-optimiser.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-web-vitals.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-wp-agent-page.php';

function helix_connector_init(): void {
    Helix_Admin::init();
    Helix_Webhooks::init();
    Helix_Widget::init();
    Helix_Dashboard::init();
    Helix_Updater::init();
    Helix_Branding::init();
    Helix_Cost_Dashboard::init();
    Helix_Migrator::init();
    Helix_Product_Ask::init();
    Helix_Inline_Chat::init();
    Helix_Product_Faq::init();
    Helix_Quick_Actions::init();
    Helix_Performance_Dash::init();
    Helix_Scheduler::init();
    Helix_Rollback::init();
    Helix_Broken_Links::init();
    Helix_Uptime::init();
    Helix_Error_Log::init();
    Helix_Backup::init();
    Helix_Plugin_Manager::init();
    Helix_Snippets::init();
    Helix_Alt_Text::init();
    Helix_SEO_Meta::init();
    Helix_Reviews::init();
    Helix_Internal_Links::init();
    Helix_Digest::init();
    Helix_Blog_Generator::init();
    Helix_Product_Descriptions::init();
    Helix_Repurpose::init();
    Helix_Image_Optimiser::init();
    Helix_Web_Vitals::init();
    Helix_WP_Agent_Page::init();
}
add_action( 'plugins_loaded', 'helix_connector_init' );

register_activation_hook( __FILE__, 'helix_connector_activate' );
function helix_connector_activate(): void {
    update_option( 'helix_activated', true );
    Helix_Snapshot::install();
    Helix_Snippets::install_table();
    Helix_Scheduler::schedule_all();
}

register_deactivation_hook( __FILE__, 'helix_connector_deactivate' );
function helix_connector_deactivate(): void {
    Helix_Webhooks::remove_webhooks();
    Helix_Scheduler::unschedule_all();
    Helix_Uptime::unschedule();
    Helix_Backup::unschedule();
    Helix_Digest::unschedule();
    Helix_Web_Vitals::unschedule();
    delete_option( 'helix_tenant_id' );
    delete_option( 'helix_public_key' );
    delete_option( 'helix_webhook_secret' );
    delete_option( 'helix_admin_secret' );
}
