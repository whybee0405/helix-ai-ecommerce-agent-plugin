<?php
defined( 'ABSPATH' ) || exit;

/**
 * Eshopeo_Repurpose — repurpose existing content into multiple formats.
 */
class Eshopeo_Repurpose {

    /* ── Init ──────────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'wp_ajax_eshopeo_repurpose', [ __CLASS__, 'ajax_repurpose' ] );
    }

    /* ── AJAX: repurpose ───────────────────────────────────────────────────── */

    public static function ajax_repurpose(): void {
        check_ajax_referer( 'eshopeo_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        $formats_raw = sanitize_text_field( $_POST['formats'] ?? '' );

        if ( ! $post_id ) {
            wp_send_json_error( 'Missing post_id', 400 );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( 'Post not found', 404 );
        }

        $allowed_formats = [ 'social_caption', 'email', 'faq', 'short_summary' ];
        $formats         = array_filter(
            array_map( 'trim', explode( ',', $formats_raw ) ),
            fn( $f ) => in_array( $f, $allowed_formats, true )
        );

        if ( empty( $formats ) ) {
            wp_send_json_error( 'At least one valid format is required', 400 );
        }

        $api_url = get_option( 'eshopeo_api_url', '' );
        $pub_key = get_option( 'eshopeo_public_key', '' );
        $pack_id = get_option( 'eshopeo_pack_id', 'general' );

        if ( ! $api_url || ! $pub_key ) {
            wp_send_json_error( 'eShopeo API not configured', 400 );
        }

        $content = wp_strip_all_tags( $post->post_content );

        $client = new Eshopeo_API_Client( $api_url, $pub_key );
        $body   = $client->content_generate(
            'content/repurpose',
            [
                'source_title'   => $post->post_title,
                'source_content' => mb_substr( $content, 0, 3000 ),
                'formats'        => array_values( $formats ),
                'pack_id'        => $pack_id,
            ],
            60
        );

        if ( is_wp_error( $body ) ) {
            wp_send_json_error( $body->get_error_message(), 502 );
        }

        wp_send_json_success( [
            'post_id'        => $post_id,
            'social_caption' => $body['social_caption'] ?? null,
            'email_body'     => $body['email_body'] ?? null,
            'faq'            => $body['faq'] ?? null,
            'short_summary'  => $body['short_summary'] ?? null,
        ] );
    }
}
