<?php
defined( 'ABSPATH' ) || exit;

class Eshopeo_Sync {
    public static function run_full_sync(): array {
        $api_url    = get_option( 'eshopeo_api_url', '' );
        $tenant_key = get_option( 'eshopeo_public_key', '' );

        if ( ! $api_url || ! $tenant_key ) {
            return [ 'error' => 'Plugin not configured. Enter API URL and connect store first.' ];
        }

        $client  = new Eshopeo_API_Client( $api_url, $tenant_key );
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

        update_option( 'eshopeo_last_sync', current_time( 'mysql' ) );
        update_option( 'eshopeo_synced_count', $synced );

        return [ 'synced' => $synced, 'failed' => $failed, 'errors' => $errors ];
    }

    public static function translate_product( WC_Product $wc_product ): array {
        $price_str   = $wc_product->get_price() ?: '0';
        $price_minor = (int) round( (float) $price_str * 100 );

        return [
            'tenant_id'         => get_option( 'eshopeo_tenant_id' ),
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

    // Built-in type maps — covers kbeauty and automotive pack attributes.
    // Store owners can extend or override these via the eShopeo settings
    // (Appearance → eShopeo → Sync tab) without touching code.
    private const SCALAR_ATTRIBUTES = [
        'step', 'spf', 'ph_level',
        'make', 'model', 'condition', 'fuel_type', 'transmission',
        'body_type', 'colour', 'stock_number', 'vin',
    ];
    private const INT_ATTRIBUTES = [
        'year', 'mileage_km', 'engine_cc', 'doors',
        'ncap_stars', 'price_zar', 'finance_from_zar',
    ];
    private const FLOAT_ATTRIBUTES = [ 'safety_rating' ];
    private const BOOL_ATTRIBUTES  = [ 'certified_used' ];

    /**
     * Merge built-in type lists with any custom overrides stored in
     * eshopeo_custom_attr_types (a JSON object mapping slug → type string:
     * "scalar" | "integer" | "number" | "boolean" | "array").
     *
     * @return array{scalar: string[], integer: string[], number: string[], boolean: string[]}
     */
    private static function get_type_map(): array {
        $map = [
            'scalar'  => self::SCALAR_ATTRIBUTES,
            'integer' => self::INT_ATTRIBUTES,
            'number'  => self::FLOAT_ATTRIBUTES,
            'boolean' => self::BOOL_ATTRIBUTES,
        ];

        $raw = get_option( 'eshopeo_custom_attr_types', '' );
        if ( $raw ) {
            $custom = json_decode( $raw, true );
            if ( is_array( $custom ) ) {
                foreach ( $custom as $slug => $type ) {
                    $slug = sanitize_key( $slug );
                    $type = in_array( $type, [ 'scalar', 'integer', 'number', 'boolean', 'array' ], true )
                        ? $type : 'scalar';
                    // Remove from all lists first (prevents duplicates), then add.
                    foreach ( $map as $t => &$list ) {
                        $list = array_values( array_diff( $list, [ $slug ] ) );
                    }
                    unset( $list );
                    if ( $type !== 'array' ) {
                        $map[ $type ][] = $slug;
                    }
                }
            }
        }

        return $map;
    }

    private static function extract_domain_attributes( WC_Product $wc_product ): array {
        $attrs    = [];
        $type_map = self::get_type_map();

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

            if ( in_array( $key, $type_map['integer'], true ) ) {
                $attrs[ $key ] = (int) $options[0];
            } elseif ( in_array( $key, $type_map['number'], true ) ) {
                $attrs[ $key ] = (float) $options[0];
            } elseif ( in_array( $key, $type_map['boolean'], true ) ) {
                $v = strtolower( (string) $options[0] );
                $attrs[ $key ] = in_array( $v, [ 'yes', 'true', '1' ], true );
            } elseif ( in_array( $key, $type_map['scalar'], true ) ) {
                $attrs[ $key ] = (string) $options[0];
            } else {
                // Default: multi-value array (lowercase).
                $attrs[ $key ] = array_map( 'strtolower', $options );
            }
        }

        $sku = $wc_product->get_sku();
        if ( $sku && ! isset( $attrs['stock_number'] ) ) {
            $attrs['stock_number'] = $sku;
        }

        return $attrs;
    }
}
