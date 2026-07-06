<?php
defined( 'ABSPATH' ) || exit;

/**
 * Eshopeo_Product_Descriptions — scan and rewrite thin WC product descriptions.
 */
class Eshopeo_Product_Descriptions {

    const MIN_WORDS = 80;

    /* ── Init ──────────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'wp_ajax_eshopeo_desc_scan',         [ __CLASS__, 'ajax_scan' ] );
        add_action( 'wp_ajax_eshopeo_desc_rewrite_one',  [ __CLASS__, 'ajax_rewrite_one' ] );
    }

    /* ── AJAX: scan ────────────────────────────────────────────────────────── */

    public static function ajax_scan(): void {
        check_ajax_referer( 'eshopeo_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;

        $products = $wpdb->get_results(
            "SELECT ID, post_title, post_content
             FROM {$wpdb->posts}
             WHERE post_type = 'product'
               AND post_status = 'publish'
             ORDER BY ID DESC
             LIMIT 500",
            ARRAY_A
        );

        $thin = [];
        foreach ( ( $products ?: [] ) as $p ) {
            $word_count = str_word_count( wp_strip_all_tags( $p['post_content'] ) );
            if ( $word_count < self::MIN_WORDS ) {
                $thin[] = [
                    'id'          => (int) $p['ID'],
                    'title'       => $p['post_title'],
                    'word_count'  => $word_count,
                    'description' => mb_substr( wp_strip_all_tags( $p['post_content'] ), 0, 200 ),
                    'edit_url'    => get_edit_post_link( (int) $p['ID'], 'raw' ),
                ];
            }
        }

        wp_send_json_success( [
            'count'    => count( $thin ),
            'products' => $thin,
        ] );
    }

    /* ── AJAX: rewrite one ─────────────────────────────────────────────────── */

    public static function ajax_rewrite_one(): void {
        check_ajax_referer( 'eshopeo_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $product_id = (int) ( $_POST['product_id'] ?? 0 );
        if ( ! $product_id ) {
            wp_send_json_error( 'Missing product_id', 400 );
        }

        $post = get_post( $product_id );
        if ( ! $post || $post->post_type !== 'product' ) {
            wp_send_json_error( 'Product not found', 404 );
        }

        $api_url = get_option( 'eshopeo_api_url', '' );
        $pub_key = get_option( 'eshopeo_public_key', '' );
        $pack_id = get_option( 'eshopeo_pack_id', 'general' );

        if ( ! $api_url || ! $pub_key ) {
            wp_send_json_error( 'eShopeo API not configured', 400 );
        }

        // Build domain attributes from product meta.
        $domain_attrs = [];
        if ( function_exists( 'wc_get_product' ) ) {
            $wc_product = wc_get_product( $product_id );
            if ( $wc_product ) {
                $domain_attrs = [
                    'price'      => $wc_product->get_price_html(),
                    'sku'        => $wc_product->get_sku(),
                    'categories' => implode( ', ', wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] ) ),
                ];
            }
        }

        $client = new Eshopeo_API_Client( $api_url, $pub_key );
        $body   = $client->content_generate(
            'products/rewrite-description',
            [
                'product_title'       => $post->post_title,
                'current_description' => $post->post_content,
                'domain_attributes'   => $domain_attrs,
                'pack_id'             => $pack_id,
            ],
            45
        );

        if ( is_wp_error( $body ) ) {
            wp_send_json_error( $body->get_error_message(), 502 );
        }

        if ( empty( $body['description_html'] ) ) {
            wp_send_json_error( 'eShopeo API returned an unexpected response.', 502 );
        }

        $new_description = wp_kses_post( $body['description_html'] );

        // Snapshot original.
        $snap_id = Eshopeo_Snapshot::save( 'product_desc_' . $product_id, [
            'product_id'  => $product_id,
            'old_content' => $post->post_content,
        ] );

        wp_update_post( [
            'ID'           => $product_id,
            'post_content' => $new_description,
        ] );

        Eshopeo_Action_Log::record( [
            'action'      => 'product_description_rewritten',
            'dry_run'     => false,
            'post_id'     => $product_id,
            'snapshot_id' => $snap_id,
        ] );

        wp_send_json_success( [
            'product_id'      => $product_id,
            'description_html'=> $new_description,
            'snapshot_id'     => $snap_id,
        ] );
    }
}
