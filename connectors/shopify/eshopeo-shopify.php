<?php
/**
 * Plugin Name: eShopeo Commerce – Shopify Connector
 * Description: Connects your Shopify store to the eShopeo commerce intelligence platform.
 * Version: 1.0.0
 * Requires PHP: 8.0
 */

defined('ABSPATH') || exit;

define('ESHOPEO_SHOPIFY_VERSION', '1.0.0');
define('ESHOPEO_SHOPIFY_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once ESHOPEO_SHOPIFY_PLUGIN_DIR . 'includes/class-eshopeo-shopify-api-client.php';
require_once ESHOPEO_SHOPIFY_PLUGIN_DIR . 'includes/class-eshopeo-shopify-sync.php';
require_once ESHOPEO_SHOPIFY_PLUGIN_DIR . 'includes/class-eshopeo-shopify-webhooks.php';

register_activation_hook(__FILE__, 'eshopeo_shopify_activate');
register_deactivation_hook(__FILE__, 'eshopeo_shopify_deactivate');

function eshopeo_shopify_activate(): void {
    add_option('eshopeo_shopify_api_url', '');
    add_option('eshopeo_shopify_public_key', '');
    add_option('eshopeo_shopify_webhook_secret', '');
    add_option('eshopeo_shopify_tenant_id', '');
}

function eshopeo_shopify_deactivate(): void {
    $webhooks = new Eshopeo_Shopify_Webhooks();
    $webhooks->remove_webhooks();
}

add_action('admin_menu', 'eshopeo_shopify_admin_menu');
function eshopeo_shopify_admin_menu(): void {
    add_options_page(
        'eShopeo Shopify',
        'eShopeo Shopify',
        'manage_options',
        'eshopeo-shopify',
        'eshopeo_shopify_admin_page'
    );
}

function eshopeo_shopify_admin_page(): void {
    if (isset($_POST['eshopeo_shopify_save']) && check_admin_referer('eshopeo_shopify_save')) {
        update_option('eshopeo_shopify_api_url', sanitize_url($_POST['api_url'] ?? ''));
        update_option('eshopeo_shopify_public_key', sanitize_text_field($_POST['public_key'] ?? ''));
        update_option('eshopeo_shopify_webhook_secret', sanitize_text_field($_POST['webhook_secret'] ?? ''));
        update_option('eshopeo_shopify_tenant_id', sanitize_text_field($_POST['tenant_id'] ?? ''));
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }
    $api_url        = get_option('eshopeo_shopify_api_url', '');
    $public_key     = get_option('eshopeo_shopify_public_key', '');
    $webhook_secret = get_option('eshopeo_shopify_webhook_secret', '');
    $tenant_id      = get_option('eshopeo_shopify_tenant_id', '');
    ?>
    <div class="wrap">
        <h1>eShopeo Shopify Connector</h1>
        <form method="post">
            <?php wp_nonce_field('eshopeo_shopify_save'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">eShopeo API URL</th>
                    <td><input name="api_url" type="url" value="<?php echo esc_attr($api_url); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row">Public Key</th>
                    <td><input name="public_key" type="text" value="<?php echo esc_attr($public_key); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row">Webhook Secret</th>
                    <td><input name="webhook_secret" type="password" value="<?php echo esc_attr($webhook_secret); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row">eShopeo Tenant ID</th>
                    <td><input name="tenant_id" type="text" value="<?php echo esc_attr($tenant_id); ?>" class="regular-text" /></td>
                </tr>
            </table>
            <?php submit_button('Save Settings', 'primary', 'eshopeo_shopify_save'); ?>
        </form>
    </div>
    <?php
}
