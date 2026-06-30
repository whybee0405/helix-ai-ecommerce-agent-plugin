<?php
defined('ABSPATH') || exit;

class Eshopeo_Shopify_Webhooks {
    private string $eshopeo_url;

    public function __construct() {
        $this->eshopeo_url = rtrim(get_option('eshopeo_shopify_api_url', ''), '/');
    }

    public function register_webhooks(string $shopify_access_token, string $shopify_store_domain): void {
        $tenant_id = get_option('eshopeo_shopify_tenant_id', '');
        $topics = [
            'products/create',
            'products/update',
            'products/delete',
        ];
        foreach ($topics as $topic) {
            wp_remote_post("https://{$shopify_store_domain}/admin/api/2024-01/webhooks.json", [
                'headers' => [
                    'Content-Type'           => 'application/json',
                    'X-Shopify-Access-Token' => $shopify_access_token,
                ],
                'body' => wp_json_encode([
                    'webhook' => [
                        'topic'   => $topic,
                        'address' => $this->eshopeo_url . '/v1/webhooks/shopify/products',
                        'format'  => 'json',
                    ],
                ]),
                'timeout' => 15,
            ]);
        }
    }

    public function remove_webhooks(): void {
        // Webhooks are removed via Shopify Admin API during app uninstall flow
    }
}
