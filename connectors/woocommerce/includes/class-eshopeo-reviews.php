<?php
defined( 'ABSPATH' ) || exit;

/**
 * Eshopeo_Reviews — synthesise WooCommerce product reviews via AI.
 */
class Eshopeo_Reviews {

    const META_SYNTHESIS = '_eshopeo_review_synthesis';
    const CACHE_TTL      = WEEK_IN_SECONDS;

    /* ── Init ──────────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'wp_ajax_eshopeo_reviews_get_products', [ __CLASS__, 'ajax_get_products' ] );
        add_action( 'wp_ajax_eshopeo_reviews_synthesise',   [ __CLASS__, 'ajax_synthesise' ] );
    }

    /* ── AJAX: get products with reviews ──────────────────────────────────── */

    public static function ajax_get_products(): void {
        check_ajax_referer( 'eshopeo_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;

        $products = $wpdb->get_results(
            "SELECT ID, post_title, comment_count
             FROM {$wpdb->posts}
             WHERE post_type = 'product'
               AND post_status = 'publish'
               AND comment_count > 0
             ORDER BY comment_count DESC
             LIMIT 100",
            ARRAY_A
        );

        // Check for cached synthesis.
        $list = [];
        foreach ( ( $products ?: [] ) as $p ) {
            $cached = get_post_meta( (int) $p['ID'], self::META_SYNTHESIS, true );
            $cached_at = 0;
            if ( $cached ) {
                $data      = json_decode( $cached, true );
                $cached_at = $data['cached_at'] ?? 0;
            }
            $list[] = [
                'id'            => (int) $p['ID'],
                'title'         => $p['post_title'],
                'review_count'  => (int) $p['comment_count'],
                'has_synthesis' => $cached && ( time() - $cached_at ) < self::CACHE_TTL,
            ];
        }

        wp_send_json_success( [
            'products' => $list,
            'total'    => count( $list ),
        ] );
    }

    /* ── AJAX: synthesise reviews ─────────────────────────────────────────── */

    public static function ajax_synthesise(): void {
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

        // Return cached synthesis if fresh.
        $cached_raw = get_post_meta( $product_id, self::META_SYNTHESIS, true );
        if ( $cached_raw ) {
            $cached = json_decode( $cached_raw, true );
            if ( isset( $cached['cached_at'] ) && ( time() - (int) $cached['cached_at'] ) < self::CACHE_TTL ) {
                unset( $cached['cached_at'] );
                wp_send_json_success( $cached );
                return;
            }
        }

        // Fetch all approved reviews for this product.
        $comments = get_comments( [
            'post_id' => $product_id,
            'status'  => 'approve',
            'number'  => 50,
        ] );

        if ( empty( $comments ) ) {
            wp_send_json_error( 'No reviews found for this product', 400 );
        }

        $reviews = [];
        foreach ( $comments as $c ) {
            $rating    = (int) get_comment_meta( $c->comment_ID, 'rating', true );
            $reviews[] = [
                'rating'  => $rating ?: 5,
                'content' => mb_substr( wp_strip_all_tags( $c->comment_content ), 0, 400 ),
            ];
        }

        $api_url = get_option( 'eshopeo_api_url', '' );
        $pub_key = get_option( 'eshopeo_public_key', '' );

        if ( ! $api_url || ! $pub_key ) {
            wp_send_json_error( 'eShopeo API not configured', 400 );
        }

        $client = new Eshopeo_API_Client( $api_url, $pub_key );
        $body   = $client->content_generate(
            'reviews/synthesise',
            [
                'product_title' => $post->post_title,
                'reviews'       => $reviews,
            ],
            45
        );

        if ( is_wp_error( $body ) ) {
            wp_send_json_error( $body->get_error_message(), 502 );
        }

        if ( empty( $body['summary'] ) ) {
            wp_send_json_error( 'eShopeo API returned an unexpected response.', 502 );
        }

        // Cache in post meta with timestamp.
        $to_cache              = $body;
        $to_cache['cached_at'] = time();
        update_post_meta( $product_id, self::META_SYNTHESIS, wp_json_encode( $to_cache ) );

        unset( $body['cached_at'] );
        wp_send_json_success( $body );
    }
}
