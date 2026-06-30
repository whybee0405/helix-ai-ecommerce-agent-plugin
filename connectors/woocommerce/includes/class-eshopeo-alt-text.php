<?php
defined( 'ABSPATH' ) || exit;

/**
 * Eshopeo_Alt_Text — scan for images missing alt text and generate via AI.
 */
class Eshopeo_Alt_Text {

    /* ── Init ──────────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'wp_ajax_eshopeo_alt_text_scan',         [ __CLASS__, 'ajax_scan' ] );
        add_action( 'wp_ajax_eshopeo_alt_text_generate_one', [ __CLASS__, 'ajax_generate_one' ] );
    }

    /* ── AJAX: scan ────────────────────────────────────────────────────────── */

    public static function ajax_scan(): void {
        check_ajax_referer( 'eshopeo_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;

        $images = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.guid
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm
               ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_image_alt'
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type LIKE 'image/%'
               AND p.post_status = 'inherit'
               AND (pm.meta_value IS NULL OR TRIM(pm.meta_value) = '')
             ORDER BY p.ID DESC
             LIMIT 200",
            ARRAY_A
        );

        $attachments = [];
        foreach ( ( $images ?: [] ) as $img ) {
            $id    = (int) $img['ID'];
            $thumb = wp_get_attachment_image_url( $id, 'thumbnail' );
            $attachments[] = [
                'id'            => $id,
                'title'         => $img['post_title'],
                'url'           => esc_url( $img['guid'] ),
                'thumbnail_url' => $thumb ? esc_url( $thumb ) : esc_url( $img['guid'] ),
            ];
        }

        wp_send_json_success( [
            'count'       => count( $attachments ),
            'attachments' => $attachments,
        ] );
    }

    /* ── AJAX: generate one ────────────────────────────────────────────────── */

    public static function ajax_generate_one(): void {
        check_ajax_referer( 'eshopeo_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $attachment_id = (int) ( $_POST['attachment_id'] ?? 0 );
        if ( ! $attachment_id ) {
            wp_send_json_error( 'Missing attachment_id', 400 );
        }

        $post = get_post( $attachment_id );
        if ( ! $post || $post->post_type !== 'attachment' ) {
            wp_send_json_error( 'Attachment not found', 404 );
        }

        $api_url = get_option( 'eshopeo_api_url', '' );
        $pub_key = get_option( 'eshopeo_public_key', '' );
        $pack_id = get_option( 'eshopeo_pack_id', 'general' );

        if ( ! $api_url || ! $pub_key ) {
            wp_send_json_error( 'eShopeo API not configured', 400 );
        }

        $response = wp_remote_post(
            trailingslashit( $api_url ) . 'v1/alt-text/generate',
            [
                'timeout' => 30,
                'headers' => [
                    'Content-Type'         => 'application/json',
                    'X-eShopeo-Tenant-Key'   => $pub_key,
                ],
                'body'    => wp_json_encode( [
                    'image_title' => $post->post_title,
                    'image_url'   => wp_get_attachment_url( $attachment_id ),
                    'pack_id'     => $pack_id,
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message(), 502 );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body['alt_text'] ) ) {
            wp_send_json_error( 'API error: ' . esc_html( wp_remote_retrieve_body( $response ) ), 502 );
        }

        $alt_text = sanitize_text_field( $body['alt_text'] );
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

        Eshopeo_Action_Log::record( [
            'action'  => 'alt_text_generated',
            'dry_run' => false,
            'post_id' => $attachment_id,
        ] );

        wp_send_json_success( [
            'attachment_id' => $attachment_id,
            'alt_text'      => $alt_text,
        ] );
    }
}
