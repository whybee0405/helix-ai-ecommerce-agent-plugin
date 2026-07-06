<?php
defined( 'ABSPATH' ) || exit;

/**
 * Eshopeo_Blog_Generator — AI-powered blog post generation.
 */
class Eshopeo_Blog_Generator {

    /* ── Init ──────────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'wp_ajax_eshopeo_blog_generate', [ __CLASS__, 'ajax_generate' ] );
    }

    /* ── AJAX: generate ────────────────────────────────────────────────────── */

    public static function ajax_generate(): void {
        check_ajax_referer( 'eshopeo_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $topic      = sanitize_text_field( $_POST['topic'] ?? '' );
        $keywords   = sanitize_text_field( $_POST['keywords'] ?? '' );
        $tone       = sanitize_text_field( $_POST['tone'] ?? 'professional' );
        $word_count = min( 2000, max( 200, (int) ( $_POST['word_count'] ?? 800 ) ) );
        $save_draft = filter_var( $_POST['save_draft'] ?? false, FILTER_VALIDATE_BOOLEAN );

        if ( empty( $topic ) ) {
            wp_send_json_error( 'Topic is required', 400 );
        }

        $api_url = get_option( 'eshopeo_api_url', '' );
        $pub_key = get_option( 'eshopeo_public_key', '' );
        $pack_id = get_option( 'eshopeo_pack_id', 'general' );

        if ( ! $api_url || ! $pub_key ) {
            wp_send_json_error( 'eShopeo API not configured', 400 );
        }

        $kw_array = array_filter( array_map( 'trim', explode( ',', $keywords ) ) );

        $client = new Eshopeo_API_Client( $api_url, $pub_key );
        $body   = $client->content_generate(
            'blog/generate',
            [
                'topic'      => $topic,
                'keywords'   => $kw_array,
                'tone'       => $tone,
                'word_count' => $word_count,
                'pack_id'    => $pack_id,
                'site_name'  => get_bloginfo( 'name' ),
            ],
            90
        );

        if ( is_wp_error( $body ) ) {
            wp_send_json_error( $body->get_error_message(), 502 );
        }

        if ( empty( $body['title'] ) ) {
            wp_send_json_error( 'eShopeo API returned an unexpected response.', 502 );
        }

        $result = [
            'title'               => sanitize_text_field( $body['title'] ?? '' ),
            'content_html'        => wp_kses_post( $body['content_html'] ?? '' ),
            'excerpt'             => sanitize_text_field( $body['excerpt'] ?? '' ),
            'suggested_categories'=> array_map( 'sanitize_text_field', (array) ( $body['suggested_categories'] ?? [] ) ),
            'suggested_tags'      => array_map( 'sanitize_text_field', (array) ( $body['suggested_tags'] ?? [] ) ),
            'seo_title'           => sanitize_text_field( $body['seo_title'] ?? '' ),
            'seo_description'     => sanitize_text_field( $body['seo_description'] ?? '' ),
        ];

        $post_id  = null;
        $edit_url = null;

        if ( $save_draft ) {
            $post_id = wp_insert_post( [
                'post_title'   => $result['title'],
                'post_content' => $result['content_html'],
                'post_excerpt' => $result['excerpt'],
                'post_status'  => 'draft',
                'post_author'  => get_current_user_id(),
            ] );

            if ( $post_id && ! is_wp_error( $post_id ) ) {
                // Apply SEO meta.
                if ( $result['seo_title'] ) {
                    update_post_meta( $post_id, '_yoast_wpseo_title', $result['seo_title'] );
                    update_post_meta( $post_id, 'rank_math_title',    $result['seo_title'] );
                }
                if ( $result['seo_description'] ) {
                    update_post_meta( $post_id, '_yoast_wpseo_metadesc', $result['seo_description'] );
                    update_post_meta( $post_id, 'rank_math_description', $result['seo_description'] );
                }
                $edit_url = get_edit_post_link( $post_id, 'raw' );

                Eshopeo_Action_Log::record( [
                    'action'  => 'blog_post_generated',
                    'dry_run' => false,
                    'post_id' => $post_id,
                ] );
            }
        }

        $result['post_id']  = $post_id;
        $result['edit_url'] = $edit_url;

        wp_send_json_success( $result );
    }
}
