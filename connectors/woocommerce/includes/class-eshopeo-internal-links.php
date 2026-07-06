<?php
defined( 'ABSPATH' ) || exit;

/**
 * Eshopeo_Internal_Links — AI-powered internal link suggestions.
 */
class Eshopeo_Internal_Links {

    /* ── Init ──────────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'wp_ajax_eshopeo_internal_links_suggest', [ __CLASS__, 'ajax_suggest' ] );
        add_action( 'wp_ajax_eshopeo_internal_links_apply',   [ __CLASS__, 'ajax_apply' ] );
    }

    /* ── AJAX: suggest ─────────────────────────────────────────────────────── */

    public static function ajax_suggest(): void {
        check_ajax_referer( 'eshopeo_wp_agent', 'nonce' );
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

        // Build list of all published posts for context.
        $all_posts_raw = get_posts( [
            'post_type'      => [ 'post', 'page', 'product' ],
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'exclude'        => [ $post_id ],
            'fields'         => 'ids',
        ] );

        $all_posts = [];
        foreach ( $all_posts_raw as $pid ) {
            $p          = get_post( $pid );
            $all_posts[] = [
                'id'      => (int) $pid,
                'title'   => $p->post_title,
                'url'     => get_permalink( $pid ),
                'excerpt' => mb_substr( wp_strip_all_tags( $p->post_excerpt ?: $p->post_content ), 0, 150 ),
            ];
        }

        $api_url = get_option( 'eshopeo_api_url', '' );
        $pub_key = get_option( 'eshopeo_public_key', '' );

        if ( ! $api_url || ! $pub_key ) {
            wp_send_json_error( 'eShopeo API not configured', 400 );
        }

        $client = new Eshopeo_API_Client( $api_url, $pub_key );
        $body   = $client->content_generate(
            'internal-links/suggest',
            [
                'post_title'   => $post->post_title,
                'post_content' => mb_substr( wp_strip_all_tags( $post->post_content ), 0, 2000 ),
                'all_posts'    => $all_posts,
            ],
            45
        );

        if ( is_wp_error( $body ) ) {
            wp_send_json_error( $body->get_error_message(), 502 );
        }

        wp_send_json_success( [
            'post_id'     => $post_id,
            'suggestions' => $body['suggestions'] ?? [],
        ] );
    }

    /* ── AJAX: apply ───────────────────────────────────────────────────────── */

    public static function ajax_apply(): void {
        check_ajax_referer( 'eshopeo_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $post_id     = (int) ( $_POST['post_id'] ?? 0 );
        $anchor_text = sanitize_text_field( $_POST['anchor_text'] ?? '' );
        $target_url  = esc_url_raw( $_POST['target_url'] ?? '' );

        if ( ! $post_id || ! $anchor_text || ! $target_url ) {
            wp_send_json_error( 'Missing required fields', 400 );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( 'Post not found', 404 );
        }

        // Snapshot original content.
        $snap_id = Eshopeo_Snapshot::save( 'internal_link_' . $post_id, [
            'post_id'      => $post_id,
            'anchor_text'  => $anchor_text,
            'target_url'   => $target_url,
            'post_content' => $post->post_content,
        ] );

        // Replace first occurrence of anchor_text (plain text) with a link.
        $link          = '<a href="' . esc_url( $target_url ) . '">' . esc_html( $anchor_text ) . '</a>';
        $new_content   = preg_replace(
            '/' . preg_quote( $anchor_text, '/' ) . '/',
            $link,
            $post->post_content,
            1
        );

        if ( $new_content === $post->post_content ) {
            wp_send_json_error( 'Anchor text not found in post content', 400 );
        }

        wp_update_post( [
            'ID'           => $post_id,
            'post_content' => $new_content,
        ] );

        Eshopeo_Action_Log::record( [
            'action'      => 'internal_link_applied',
            'dry_run'     => false,
            'post_id'     => $post_id,
            'snapshot_id' => $snap_id,
        ] );

        wp_send_json_success( [
            'post_id'     => $post_id,
            'snapshot_id' => $snap_id,
            'applied'     => true,
        ] );
    }
}
