<?php
defined( 'ABSPATH' ) || exit;

class Helix_Broken_Links {

    const CACHE_OPTION  = 'helix_broken_links_cache';
    const CACHE_TTL     = DAY_IN_SECONDS;

    /* ── Init ──────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'wp_ajax_helix_scan_broken_links',         [ __CLASS__, 'ajax_scan' ] );
        add_action( 'wp_ajax_helix_broken_links_fix',          [ __CLASS__, 'ajax_fix_redirect' ] );
        add_action( 'wp_ajax_helix_broken_links_fix_redirect', [ __CLASS__, 'ajax_fix_redirect' ] );
    }

    /* ── AJAX: scan ───────────────────────────────────────────────────── */

    public static function ajax_scan(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
        $batch  = min( 20, max( 1, (int) ( $_POST['batch'] ?? 20 ) ) );
        $force  = filter_var( $_POST['force'] ?? false, FILTER_VALIDATE_BOOLEAN );

        // Check cache first (only on first batch, not forced).
        if ( $offset === 0 && ! $force ) {
            $cached = get_option( self::CACHE_OPTION );
            if ( $cached ) {
                $data = json_decode( $cached, true );
                if ( isset( $data['cached_at'] ) && ( time() - $data['cached_at'] ) < self::CACHE_TTL ) {
                    $data['from_cache'] = true;
                    wp_send_json_success( $data );
                    return;
                }
            }
        }

        global $wpdb;

        // Get all published posts + pages.
        $all_posts = $wpdb->get_results(
            "SELECT ID, post_title, post_content
             FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type IN ('post','page')
             LIMIT 500",
            ARRAY_A
        );

        $total_posts = count( $all_posts );

        // Collect all links.
        $all_links = [];
        foreach ( $all_posts as $post ) {
            preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $post['post_content'], $matches );
            foreach ( $matches[1] as $url ) {
                $url = trim( $url );
                if ( empty( $url ) || str_starts_with( $url, '#' ) || str_starts_with( $url, 'mailto:' ) || str_starts_with( $url, 'tel:' ) ) {
                    continue;
                }
                // Make relative URLs absolute.
                if ( str_starts_with( $url, '/' ) ) {
                    $url = site_url( $url );
                }
                if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
                    continue;
                }
                $all_links[] = [
                    'url'        => $url,
                    'post_id'    => (int) $post['ID'],
                    'post_title' => $post['post_title'],
                ];
            }
        }

        // Deduplicate by URL per post.
        $seen  = [];
        $links = [];
        foreach ( $all_links as $link ) {
            $key = $link['url'] . '|' . $link['post_id'];
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $links[]      = $link;
        }

        $total   = count( $links );
        $batch_links = array_slice( $links, $offset, $batch );
        $broken  = [];

        foreach ( $batch_links as $link ) {
            $response = wp_remote_head( $link['url'], [
                'timeout'     => 8,
                'redirection' => 5,
                'user-agent'  => 'Helix-Broken-Link-Checker/1.0',
            ] );

            $status_code = 0;
            $error       = '';

            if ( is_wp_error( $response ) ) {
                $error = $response->get_error_message();
            } else {
                $status_code = (int) wp_remote_retrieve_response_code( $response );
            }

            if ( is_wp_error( $response ) || $status_code >= 400 ) {
                $broken[] = [
                    'url'         => $link['url'],
                    'post_id'     => $link['post_id'],
                    'post_title'  => $link['post_title'],
                    'status_code' => $status_code,
                    'error'       => esc_html( $error ),
                ];
            }
        }

        $is_last_batch = ( $offset + $batch ) >= $total;

        // Store results in cache when last batch.
        if ( $is_last_batch && $offset === 0 ) {
            $cache_data = [
                'links'     => $broken,
                'total'     => $total,
                'scanned'   => count( $batch_links ),
                'cached_at' => time(),
            ];
            update_option( self::CACHE_OPTION, wp_json_encode( $cache_data ), false );
        }

        wp_send_json_success( [
            'links'        => $broken,
            'total'        => $total,
            'scanned'      => $offset + count( $batch_links ),
            'offset'       => $offset,
            'batch'        => $batch,
            'is_last_batch' => $is_last_batch,
            'total_posts'  => $total_posts,
            'from_cache'   => false,
        ] );
    }

    /* ── AJAX: fix redirect ─────────────────────────────────────────── */

    public static function ajax_fix_redirect(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        $old_url = esc_url_raw( $_POST['old_url'] ?? '' );
        $new_url = esc_url_raw( $_POST['new_url'] ?? '' );

        if ( ! $post_id || ! $old_url || ! $new_url ) {
            wp_send_json_error( 'Missing required fields', 400 );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( 'Post not found', 404 );
        }

        // Snapshot original content.
        Helix_Snapshot::save( 'fix_broken_link_' . $post_id, [
            'post_id'      => $post_id,
            'old_url'      => $old_url,
            'new_url'      => $new_url,
            'post_content' => $post->post_content,
        ] );

        $new_content = str_replace(
            esc_attr( $old_url ),
            esc_attr( $new_url ),
            $post->post_content
        );
        // Also replace unescaped version.
        $new_content = str_replace( $old_url, $new_url, $new_content );

        wp_update_post( [
            'ID'           => $post_id,
            'post_content' => $new_content,
        ] );

        // Invalidate cache.
        delete_option( self::CACHE_OPTION );

        wp_send_json_success( [
            'updated' => true,
            'post_id' => $post_id,
        ] );
    }
}
