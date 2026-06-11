<?php
defined( 'ABSPATH' ) || exit;

class Helix_API_Client {
    private string $api_url;
    private string $tenant_key;

    public function __construct( string $api_url, string $tenant_key = '' ) {
        $this->api_url    = rtrim( $api_url, '/' );
        $this->tenant_key = $tenant_key;
    }

    public function provision( string $name, string $store_url, array $credentials ): array|WP_Error {
        $provision_key = get_option( 'helix_provision_key', '' );
        $response = wp_remote_post( $this->api_url . '/v1/tenants', [
            'headers' => [
                'Content-Type'          => 'application/json',
                'X-Helix-Provision-Key' => $provision_key,
            ],
            'body'    => wp_json_encode( [
                'name'        => $name,
                'platform'    => 'woocommerce',
                'store_url'   => $store_url,
                'credentials' => $credentials,
            ] ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 201 ) {
            return new WP_Error( 'helix_provision_failed', "Helix API returned HTTP {$code}" );
        }
        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    public function sync_products( array $products ): array|WP_Error {
        $response = wp_remote_post( $this->api_url . '/v1/sync/products', [
            'headers' => [
                'Content-Type'       => 'application/json',
                'X-Helix-Tenant-Key' => $this->tenant_key,
            ],
            'body'    => wp_json_encode( [ 'products' => $products ] ),
            'timeout' => 60,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }
        return json_decode( wp_remote_retrieve_body( $response ), true );
    }
}
