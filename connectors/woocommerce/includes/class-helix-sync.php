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

    // Single-value string attributes (first term only, no array wrapping).
    private const SCALAR_ATTRIBUTES = [
        // k-beauty
        'step', 'spf', 'ph_level',
        // automotive — identity fields that are always singular
        'make', 'model', 'condition', 'fuel_type', 'transmission',
        'body_type', 'colour', 'stock_number', 'vin',
    ];

    // Single-value integer attributes.
    private const INT_ATTRIBUTES = [
        'year', 'mileage_km', 'engine_cc', 'doors',
        'ncap_stars', 'price_zar', 'finance_from_zar',
    ];

    // Single-value float attributes.
    private const FLOAT_ATTRIBUTES = [ 'safety_rating' ];

    // Single-value boolean attributes ('yes'/'true'/'1' → true, else false).
    private const BOOL_ATTRIBUTES = [ 'certified_used' ];

    private static function extract_domain_attributes( WC_Product $wc_product ): array {
        $attrs = [];

        foreach ( $wc_product->get_attributes() as $slug => $attribute ) {
            $key     = str_replace( [ 'pa_', '-' ], [ '', '_' ], $slug );
            $options = $attribute->get_options();

            if ( empty( $options ) ) {
                continue;
            }

            // Taxonomy attributes: resolve term IDs to human-readable names.
            if ( $attribute->is_taxonomy() ) {
                $options = array_values( array_filter( array_map(
                    fn( $id ) => get_term_field( 'name', $id, $slug ),
                    $options
                ), fn( $v ) => is_string( $v ) && $v !== '' ) );
            } elseif ( str_starts_with( $slug, 'pa_' ) ) {
                // pa_* attribute saved without a taxonomy ID — options may be raw term IDs
                // (happens when taxonomy was not registered at migration time). Resolve to
                // names; skip any ID that can't be resolved rather than passing it through.
                $options = array_values( array_filter( array_map(
                    function ( $opt ) use ( $slug ) {
                        if ( ! is_numeric( $opt ) ) {
                            return (string) $opt;
                        }
                        $name = get_term_field( 'name', (int) $opt, $slug );
                        return ( is_string( $name ) && $name !== '' ) ? $name : null;
                    },
                    $options
                ), fn( $v ) => $v !== null && $v !== '' ) );
            }

            if ( empty( $options ) ) {
                continue;
            }

            if ( in_array( $key, self::INT_ATTRIBUTES, true ) ) {
                $attrs[ $key ] = (int) $options[0];
            } elseif ( in_array( $key, self::FLOAT_ATTRIBUTES, true ) ) {
                $attrs[ $key ] = (float) $options[0];
            } elseif ( in_array( $key, self::BOOL_ATTRIBUTES, true ) ) {
                $v = strtolower( (string) $options[0] );
                $attrs[ $key ] = in_array( $v, [ 'yes', 'true', '1' ], true );
            } elseif ( in_array( $key, self::SCALAR_ATTRIBUTES, true ) ) {
                $attrs[ $key ] = (string) $options[0];
            } else {
                // Multi-value attribute — return as lowercase array.
                $attrs[ $key ] = array_map( 'strtolower', $options );
            }
        }

        // Map WC SKU → stock_number if not already set via attribute.
        $sku = $wc_product->get_sku();
        if ( $sku && ! isset( $attrs['stock_number'] ) ) {
            $attrs['stock_number'] = $sku;
        }

        return $attrs;
    }
}
