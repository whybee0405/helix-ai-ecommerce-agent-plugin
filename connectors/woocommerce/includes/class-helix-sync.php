<?php
defined( 'ABSPATH' ) || exit;

class Helix_Sync {
    public static function run_full_sync(): array {
        $api_url    = get_option( 'helix_api_url', '' );
        $tenant_key = get_option( 'helix_public_key', '' );

        if ( ! $api_url || ! $tenant_key ) {
            return [ 'error' => 'Plugin not configured. Enter API URL and connect store first.' ];
        }

        $client  = new Helix_API_Client( $api_url, $tenant_key );
        $page    = 1;
        $synced  = 0;
        $failed  = 0;
        $errors  = [];

        do {
            $wc_products = wc_get_products( [
                'limit'  => 100,
                'page'   => $page,
                'status' => 'publish',
                'return' => 'objects',
            ] );

            if ( empty( $wc_products ) ) {
                break;
            }

            $batch  = array_map( [ self::class, 'translate_product' ], $wc_products );
            $result = $client->sync_products( $batch );

            if ( is_wp_error( $result ) ) {
                $failed += count( $batch );
                $errors[] = $result->get_error_message();
            } else {
                $synced += $result['synced'] ?? 0;
                $failed += $result['failed'] ?? 0;
                $errors  = array_merge( $errors, $result['errors'] ?? [] );
            }

            $page++;
        } while ( count( $wc_products ) === 100 );

        update_option( 'helix_last_sync', current_time( 'mysql' ) );
        update_option( 'helix_synced_count', $synced );

        return [ 'synced' => $synced, 'failed' => $failed, 'errors' => $errors ];
    }

    public static function translate_product( WC_Product $wc_product ): array {
        $price_str   = $wc_product->get_price() ?: '0';
        $price_minor = (int) round( (float) $price_str * 100 );

        return [
            'tenant_id'         => get_option( 'helix_tenant_id' ),
            'platform'          => 'woocommerce',
            'platform_id'       => (string) $wc_product->get_id(),
            'title'             => $wc_product->get_name(),
            'description_html'  => $wc_product->get_description() ?: null,
            'price_minor'       => $price_minor,
            'currency'          => get_woocommerce_currency(),
            'images'            => array_values( array_filter( array_map(
                fn( int $id ) => wp_get_attachment_url( $id ),
                $wc_product->get_image_id()
                    ? array_merge( [ $wc_product->get_image_id() ], $wc_product->get_gallery_image_ids() )
                    : $wc_product->get_gallery_image_ids()
            ), fn( $url ) => is_string( $url ) && $url !== '' ) ),
            'categories'        => array_map(
                fn( WP_Term $t ) => $t->name,
                get_the_terms( $wc_product->get_id(), 'product_cat' ) ?: []
            ),
            'in_stock'          => $wc_product->is_in_stock(),
            'domain_attributes' => self::extract_domain_attributes( $wc_product ),
        ];
    }

    // Attributes whose value should be a single string rather than an array.
    private const SCALAR_ATTRIBUTES = [ 'step', 'spf', 'ph_level' ];

    private static function extract_domain_attributes( WC_Product $wc_product ): array {
        $attrs = [];
        foreach ( $wc_product->get_attributes() as $slug => $attribute ) {
            $key     = str_replace( [ 'pa_', '-' ], [ '', '_' ], $slug );
            $options = $attribute->get_options();

            if ( empty( $options ) ) {
                continue;
            }

            // Taxonomy attributes return term IDs — resolve to lowercase names.
            if ( $attribute->is_taxonomy() ) {
                $options = array_values( array_filter( array_map(
                    fn( $id ) => strtolower( get_term_field( 'name', $id, $slug ) ),
                    $options
                ), fn( $v ) => is_string( $v ) && $v !== '' ) );
            }

            if ( empty( $options ) ) {
                continue;
            }

            // Scalar attributes are always a single string; everything else is an array.
            $attrs[ $key ] = in_array( $key, self::SCALAR_ATTRIBUTES, true )
                ? (string) $options[0]
                : $options;
        }
        return $attrs;
    }
}
