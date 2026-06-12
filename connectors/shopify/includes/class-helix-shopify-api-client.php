<?php
defined('ABSPATH') || exit;

class Helix_Shopify_Api_Client {
    private string $api_url;
    private string $public_key;
    private string $tenant_id;

    public function __construct() {
        $this->api_url    = rtrim(get_option('helix_shopify_api_url', ''), '/');
        $this->public_key = get_option('helix_shopify_public_key', '');
        $this->tenant_id  = get_option('helix_shopify_tenant_id', '');
    }

    public function sync_products(array $products): array|WP_Error {
        $canonical = array_map([$this, 'translate_product'], $products);
        foreach ($canonical as &$p) {
            $p['tenant_id'] = $this->tenant_id;
        }
        return $this->post('/v1/sync/products', ['products' => $canonical]);
    }

    public function translate_product(array $product): array {
        $variant   = $product['variants'][0] ?? [];
        $price_str = $variant['price'] ?? '0';
        $price     = (int)(floatval($price_str) * 100);
        return [
            'tenant_id'         => $this->tenant_id,
            'platform'          => 'shopify',
            'platform_id'       => (string)($product['id'] ?? ''),
            'title'             => $product['title'] ?? '',
            'description_html'  => $product['body_html'] ?? null,
            'price_minor'       => $price,
            'currency'          => 'USD',
            'images'            => array_column($product['images'] ?? [], 'src'),
            'categories'        => [],
            'in_stock'          => ($variant['inventory_quantity'] ?? 1) > 0,
            'domain_attributes' => [],
            'deleted'           => ($product['status'] ?? '') === 'archived',
        ];
    }

    private function post(string $path, array $body): array|WP_Error {
        $response = wp_remote_post($this->api_url . $path, [
            'headers' => [
                'Content-Type'       => 'application/json',
                'X-Helix-Tenant-Key' => $this->public_key,
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        return json_decode(wp_remote_retrieve_body($response), true) ?? [];
    }
}
