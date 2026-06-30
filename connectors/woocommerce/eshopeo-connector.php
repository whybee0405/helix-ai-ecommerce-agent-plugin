<?php
/**
 * Plugin Name: eShopeo AI Agent
 * Description: Syncs your WooCommerce catalog with the eShopeo commerce intelligence platform.
 * Version: 0.5.5
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'ESHOPEO_CONNECTOR_VERSION', '0.5.5' );
define( 'ESHOPEO_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );

require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-pack.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-api-client.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-sync.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-webhooks.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-admin.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-widget.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-dashboard.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-updater.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-branding.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-cost-dashboard.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-migrator.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-migrator-v1.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-product-ask.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-inline-chat.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-product-faq.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-snapshot.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-action-log.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-quick-actions.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-performance-dash.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-scheduler.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-rollback.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-broken-links.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-uptime.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-error-log.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-backup.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-plugin-manager.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-snippets.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-alt-text.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-seo-meta.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-reviews.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-internal-links.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-digest.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-blog-generator.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-product-descriptions.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-repurpose.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-image-optimiser.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-web-vitals.php';
require_once ESHOPEO_CONNECTOR_DIR . 'includes/class-eshopeo-wp-agent-page.php';

function eshopeo_connector_init(): void {
    Eshopeo_Admin::init();
    Eshopeo_Webhooks::init();
    Eshopeo_Widget::init();
    Eshopeo_Dashboard::init();
    Eshopeo_Updater::init();
    Eshopeo_Branding::init();
    Eshopeo_Cost_Dashboard::init();
    Eshopeo_Migrator::init();
    Eshopeo_Product_Ask::init();
    Eshopeo_Inline_Chat::init();
    Eshopeo_Product_Faq::init();
    Eshopeo_Quick_Actions::init();
    Eshopeo_Performance_Dash::init();
    Eshopeo_Scheduler::init();
    Eshopeo_Rollback::init();
    Eshopeo_Broken_Links::init();
    Eshopeo_Uptime::init();
    Eshopeo_Error_Log::init();
    Eshopeo_Backup::init();
    Eshopeo_Plugin_Manager::init();
    Eshopeo_Snippets::init();
    Eshopeo_Alt_Text::init();
    Eshopeo_SEO_Meta::init();
    Eshopeo_Reviews::init();
    Eshopeo_Internal_Links::init();
    Eshopeo_Digest::init();
    Eshopeo_Blog_Generator::init();
    Eshopeo_Product_Descriptions::init();
    Eshopeo_Repurpose::init();
    Eshopeo_Image_Optimiser::init();
    Eshopeo_Web_Vitals::init();
    Eshopeo_WP_Agent_Page::init();
}
add_action( 'plugins_loaded', 'eshopeo_connector_init' );

// Run the one-time WP options rename (helix_* → eshopeo_*) on every load;
// the method checks an internal flag and exits immediately once done.
add_action( 'plugins_loaded', [ 'Eshopeo_Migrator_V1', 'run_migration' ] );

register_activation_hook( __FILE__, 'eshopeo_connector_activate' );
function eshopeo_connector_activate(): void {
    update_option( 'eshopeo_activated', true );
    Eshopeo_Snapshot::install();
    Eshopeo_Snippets::install_table();
    Eshopeo_Scheduler::schedule_all();
}

register_deactivation_hook( __FILE__, 'eshopeo_connector_deactivate' );
function eshopeo_connector_deactivate(): void {
    Eshopeo_Webhooks::remove_webhooks();
    Eshopeo_Scheduler::unschedule_all();
    Eshopeo_Uptime::unschedule();
    Eshopeo_Backup::unschedule();
    Eshopeo_Digest::unschedule();
    Eshopeo_Web_Vitals::unschedule();
    delete_option( 'eshopeo_tenant_id' );
    delete_option( 'eshopeo_public_key' );
    delete_option( 'eshopeo_webhook_secret' );
    delete_option( 'eshopeo_admin_secret' );
}
