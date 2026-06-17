<?php
defined( 'ABSPATH' ) || exit;

class Helix_API_Client {
    private string $api_url;
    private string $tenant_key;

    public function __construct( string $api_url, string $tenant_key = '' ) {
        $this->api_url    = rtrim( $api_url, '/' );
        $this->tenant_key = $tenant_key;
    }

    public function provision( string $name, string $store_url, array $credentials, string $pack_id = 'kbeauty' ): array|WP_Error {
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
                'pack_id'     => $pack_id,
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

    public function patch_tenant( string $tenant_id, array $patch ): array|WP_Error {
        $provision_key = get_option( 'helix_provision_key', '' );
        $response = wp_remote_request( $this->api_url . '/v1/tenants/' . $tenant_id, [
            'method'  => 'PATCH',
            'headers' => [
                'Content-Type'          => 'application/json',
                'X-Helix-Provision-Key' => $provision_key,
            ],
            'body'    => wp_json_encode( $patch ),
            'timeout' => 15,
        ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 400 ) {
            return new WP_Error( 'helix_patch_tenant_failed', "Helix API returned HTTP {$code}" );
        }
        return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
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

    public function get( string $path ): array|WP_Error {
        $response = wp_remote_get( $this->api_url . $path, [
            'headers' => [ 'X-Helix-Tenant-Key' => $this->tenant_key ],
            'timeout' => 15,
        ] );
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 400 ) return new WP_Error( 'helix_api_error', "HTTP {$code}", $response );
        return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
    }

    public function get_dashboard(): array|WP_Error {
        return $this->get( '/v1/dashboard' );
    }

    public function get_widget_events(): array|WP_Error {
        return $this->get( '/v1/dashboard/widget-events' );
    }

    public function get_conversations( int $page = 1, int $limit = 20 ): array|WP_Error {
        return $this->get( "/v1/dashboard/conversations?page={$page}&limit={$limit}" );
    }

    public function get_conversation_messages( string $conversation_id ): array|WP_Error {
        return $this->get( "/v1/dashboard/conversations/{$conversation_id}/messages" );
    }

    public function get_daily_events( int $days = 30 ): array|WP_Error {
        return $this->get( "/v1/dashboard/events/daily?days={$days}" );
    }

    private function admin_headers( string $body ): array {
        $tenant_id    = get_option( 'helix_tenant_id', '' );
        $admin_secret = get_option( 'helix_admin_secret', '' );
        $timestamp    = (string) time();
        $payload      = $timestamp . '.' . $body;
        $signature    = base64_encode( hash_hmac( 'sha256', $payload, $admin_secret, true ) );
        return [
            'Content-Type'      => 'application/json',
            'X-Helix-Tenant-Id' => $tenant_id,
            'X-Helix-Timestamp' => $timestamp,
            'X-Helix-Signature' => $signature,
        ];
    }

    public function bootstrap_admin_secret(): array|WP_Error {
        $tenant_id     = get_option( 'helix_tenant_id', '' );
        $provision_key = get_option( 'helix_provision_key', '' );
        if ( ! $tenant_id || ! $provision_key ) {
            return new WP_Error( 'helix_no_tenant', 'Tenant not provisioned' );
        }
        $response = wp_remote_post(
            $this->api_url . '/v1/tenants/' . $tenant_id . '/admin-secret',
            [
                'headers' => [ 'X-Helix-Provision-Key' => $provision_key ],
                'timeout' => 15,
            ]
        );
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) return new WP_Error( 'helix_bootstrap_failed', "HTTP {$code}" );
        return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
    }

    public function get_branding(): array|WP_Error {
        $body     = '';
        $response = wp_remote_get(
            $this->api_url . '/v1/admin/branding',
            [
                'headers' => $this->admin_headers( $body ),
                'timeout' => 15,
            ]
        );
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 400 ) return new WP_Error( 'helix_branding_get_failed', "HTTP {$code}" );
        return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
    }

    public function update_branding( array $patch ): array|WP_Error {
        $body     = wp_json_encode( $patch );
        $response = wp_remote_post(
            $this->api_url . '/v1/admin/branding',
            [
                'headers' => $this->admin_headers( $body ),
                'body'    => $body,
                'timeout' => 15,
            ]
        );
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 400 ) return new WP_Error( 'helix_branding_save_failed', "HTTP {$code}: " . wp_remote_retrieve_body( $response ) );
        return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
    }

    public function apply_branding_preset( string $preset_id ): array|WP_Error {
        $body     = wp_json_encode( [ 'preset_id' => $preset_id ] );
        $response = wp_remote_post(
            $this->api_url . '/v1/admin/branding/apply-preset',
            [
                'headers' => $this->admin_headers( $body ),
                'body'    => $body,
                'timeout' => 15,
            ]
        );
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 400 ) return new WP_Error( 'helix_preset_apply_failed', "HTTP {$code}" );
        return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
    }

    public function list_presets(): array|WP_Error {
        $body     = '';
        $response = wp_remote_get(
            $this->api_url . '/v1/admin/presets',
            [
                'headers' => $this->admin_headers( $body ),
                'timeout' => 15,
            ]
        );
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 400 ) return new WP_Error( 'helix_presets_failed', "HTTP {$code}" );
        return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
    }

    public function get_leads( int $page = 1, int $limit = 50 ): array|WP_Error {
        $body     = '';
        $response = wp_remote_get(
            $this->api_url . "/v1/admin/leads?page={$page}&limit={$limit}",
            [
                'headers' => $this->admin_headers( $body ),
                'timeout' => 15,
            ]
        );
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 400 ) return new WP_Error( 'helix_leads_failed', "HTTP {$code}" );
        return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
    }

    public function get_usage_summary( int $days = 30 ): array|WP_Error {
        $body     = '';
        $response = wp_remote_get(
            $this->api_url . "/v1/admin/usage?days={$days}",
            [
                'headers' => $this->admin_headers( $body ),
                'timeout' => 15,
            ]
        );
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 400 ) return new WP_Error( 'helix_usage_failed', "HTTP {$code}" );
        return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
    }
}
