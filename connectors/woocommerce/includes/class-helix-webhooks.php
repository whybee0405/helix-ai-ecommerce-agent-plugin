<?php
defined( 'ABSPATH' ) || exit;

class Helix_Webhooks {
    private const WEBHOOK_TOPICS = [ 'product.created', 'product.updated', 'product.deleted' ];

    public static function init(): void {
        add_action( 'woocommerce_webhook_payload', [ self::class, 'sign_outbound_webhook' ], 10, 4 );
    }

    public static function register_webhooks( string $api_url, string $tenant_id ): void {
        $secret       = wp_generate_password( 32, false );
        $delivery_url = trailingslashit( $api_url ) . 'v1/webhooks/products';

        foreach ( self::WEBHOOK_TOPICS as $topic ) {
            $webhook = new WC_Webhook();
            $webhook->set_name( "Helix — {$topic}" );
            $webhook->set_topic( $topic );
            $webhook->set_delivery_url( $delivery_url );
            $webhook->set_secret( $secret );
            $webhook->set_status( 'active' );
            $webhook->save();
        }

        update_option( 'helix_webhook_secret', $secret );
        update_option( 'helix_tenant_id', $tenant_id );
    }

    public static function remove_webhooks(): void {
        $data_store  = WC_Data_Store::load( 'webhook' );
        $webhook_ids = $data_store->search_webhooks( [ 'limit' => 100, 'status' => 'active' ] );
        foreach ( $webhook_ids as $id ) {
            $webhook = new WC_Webhook( $id );
            if ( str_starts_with( $webhook->get_name(), 'Helix — ' ) ) {
                $webhook->delete( true );
            }
        }
    }

    public static function sign_outbound_webhook( array $payload, string $resource, string $event, int $webhook_id ): array {
        $webhook = new WC_Webhook( $webhook_id );
        if ( ! str_starts_with( $webhook->get_name(), 'Helix — ' ) ) {
            return $payload;
        }
        // WooCommerce signs using the webhook secret automatically via X-WC-Webhook-Signature.
        return $payload;
    }
}
