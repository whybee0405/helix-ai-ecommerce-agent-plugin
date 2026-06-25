<?php
defined( 'ABSPATH' ) || exit;

/**
 * Helix_SEO_Meta — scan posts missing SEO meta and generate via AI.
 */
class Helix_SEO_Meta {

    /* ── Init ──────────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'wp_ajax_helix_seo_scan',         [ __CLASS__, 'ajax_scan' ] );
        add_action( 'wp_ajax_helix_seo_generate_one', [ __CLASS__, 'ajax_generate_one' ] );
    }

    /* ── AJAX: scan ────────────────────────────────────────────────────────── */

    public static function ajax_scan(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;

        // Posts/pages where both Yoast AND RankMath descriptions are empty/missing.
        $posts = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_excerpt, p.post_type, p.post_modified
             FROM {$wpdb->posts} p
             WHERE p.post_status = 'publish'
               AND p.post_type IN ('post','page','product')
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pm
                   WHERE pm.post_id = p.ID
                     AND pm.meta_key IN ('_yoast_wpseo_metadesc','rank_math_description','_aioseo_description')
                     AND TRIM(pm.meta_value) != ''
               )
             ORDER BY p.post_modified DESC
             LIMIT 200",
            ARRAY_A
        );

        wp_send_json_success( [
            'count' => count( $posts ?: [] ),
            'posts' => $posts ?: [],
        ] );
    }

    /* ── AJAX: generate one ────────────────────────────────────────────────── */

    public static function ajax_generate_one(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( 'Missing post_id', 400 );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( 'Post not found', 404 );
        }

        $api_url = get_option( 'helix_api_url', '' );
        $pub_key = get_option( 'helix_public_key', '' );
        $pack_id = get_option( 'helix_pack_id', 'general' );

        if ( ! $api_url || ! $pub_key ) {
            wp_send_json_error( 'Helix API not configured', 400 );
        }

        $excerpt = wp_strip_all_tags( $post->post_excerpt ?: $post->post_content );
        $excerpt = mb_substr( $excerpt, 0, 300 );

        $response = wp_remote_post(
            trailingslashit( $api_url ) . 'v1/seo-meta/generate',
            [
                'timeout' => 30,
                'headers' => [
                    'Content-Type'       => 'application/json',
                    'X-Helix-Tenant-Key' => $pub_key,
                ],
                'body'    => wp_json_encode( [
                    'post_title'   => $post->post_title,
                    'post_excerpt' => $excerpt,
                    'pack_id'      => $pack_id,
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message(), 502 );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body['seo_title'] ) ) {
            wp_send_json_error( 'API error: ' . esc_html( wp_remote_retrieve_body( $response ) ), 502 );
        }

        $seo_title       = sanitize_text_field( $body['seo_title'] );
        $seo_description = sanitize_text_field( $body['seo_description'] ?? '' );

        // Write to Yoast, RankMath, and AIOSEO meta keys.
        update_post_meta( $post_id, '_yoast_wpseo_title',       $seo_title );
        update_post_meta( $post_id, '_yoast_wpseo_metadesc',    $seo_description );
        update_post_meta( $post_id, 'rank_math_title',          $seo_title );
        update_post_meta( $post_id, 'rank_math_description',    $seo_description );
        update_post_meta( $post_id, '_aioseo_title',            $seo_title );
        update_post_meta( $post_id, '_aioseo_description',      $seo_description );

        Helix_Action_Log::record( [
            'action'  => 'seo_meta_generated',
            'dry_run' => false,
            'post_id' => $post_id,
        ] );

        wp_send_json_success( [
            'post_id'         => $post_id,
            'seo_title'       => $seo_title,
            'seo_description' => $seo_description,
        ] );
    }
}
