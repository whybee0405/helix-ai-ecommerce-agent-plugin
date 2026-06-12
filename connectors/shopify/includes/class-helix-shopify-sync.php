<?php
defined('ABSPATH') || exit;

class Helix_Shopify_Sync {
    private Helix_Shopify_Api_Client $client;

    public function __construct() {
        $this->client = new Helix_Shopify_Api_Client();
    }

    public function run_full_sync(array $shopify_products): void {
        $chunks = array_chunk($shopify_products, 50);
        foreach ($chunks as $chunk) {
            $result = $this->client->sync_products($chunk);
            if (is_wp_error($result)) {
                error_log('[Helix Shopify] Sync error: ' . $result->get_error_message());
            }
        }
    }
}
