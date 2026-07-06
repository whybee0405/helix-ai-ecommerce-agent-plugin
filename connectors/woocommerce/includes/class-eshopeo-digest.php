<?php
defined( 'ABSPATH' ) || exit;

/**
 * Eshopeo_Digest — weekly AI-generated site digest email.
 */
class Eshopeo_Digest {

    const CRON_HOOK = 'eshopeo_weekly_digest';

    /* ── Init ──────────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( self::CRON_HOOK, [ __CLASS__, 'send_digest' ] );
        add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );

        add_action( 'wp_ajax_eshopeo_digest_send_test', [ __CLASS__, 'ajax_send_test' ] );
    }

    public static function schedule(): void {
        self::maybe_schedule();
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public static function maybe_schedule(): void {
        if ( ! get_option( 'eshopeo_digest_enabled', 0 ) ) {
            return;
        }
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            // Schedule for next Monday 8am UTC.
            $next_monday = strtotime( 'next monday 08:00 UTC' );
            wp_schedule_event( $next_monday, 'weekly', self::CRON_HOOK );
        }
    }

    /* ── Build stats ───────────────────────────────────────────────────────── */

    private static function collect_stats(): array {
        global $wpdb;

        $week_ago = date( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

        $new_posts = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type = 'post' AND post_date >= %s",
            $week_ago
        ) );

        $new_orders = 0;
        if ( function_exists( 'wc_get_orders' ) ) {
            $new_orders = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_type = 'shop_order' AND post_date >= %s",
                $week_ago
            ) );
        }

        $broken_links = 0;
        $cached = get_option( 'eshopeo_broken_links_cache' );
        if ( $cached ) {
            $data         = json_decode( $cached, true );
            $broken_links = count( $data['links'] ?? [] );
        }

        $uptime_pct = 100.0;
        $uptime_log = json_decode( get_option( 'eshopeo_uptime_log', '[]' ), true );
        if ( is_array( $uptime_log ) && ! empty( $uptime_log ) ) {
            $up_count   = count( array_filter( $uptime_log, fn( $e ) => (int) $e['status'] === 200 ) );
            $uptime_pct = round( ( $up_count / count( $uptime_log ) ) * 100, 1 );
        }

        // Top errors from error log.
        $top_errors = [];
        $log_path   = WP_CONTENT_DIR . '/debug.log';
        if ( file_exists( $log_path ) && is_readable( $log_path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $content    = file_get_contents( $log_path );
            $lines      = array_slice( array_filter( explode( "\n", $content ) ), -20 );
            foreach ( $lines as $line ) {
                if ( stripos( $line, 'Fatal' ) !== false || stripos( $line, 'Error' ) !== false ) {
                    $top_errors[] = mb_substr( wp_strip_all_tags( $line ), 0, 120 );
                    if ( count( $top_errors ) >= 3 ) {
                        break;
                    }
                }
            }
        }

        return [
            'site_name'    => get_bloginfo( 'name' ),
            'new_posts'    => $new_posts,
            'new_orders'   => $new_orders,
            'new_leads'    => 0,
            'broken_links' => $broken_links,
            'uptime_pct'   => $uptime_pct,
            'top_errors'   => $top_errors,
        ];
    }

    /* ── Generate digest content ──────────────────────────────────────────── */

    private static function generate_digest_content(): array|WP_Error {
        $api_url = get_option( 'eshopeo_api_url', '' );
        $pub_key = get_option( 'eshopeo_public_key', '' );

        if ( ! $api_url || ! $pub_key ) {
            return new WP_Error( 'eshopeo_not_configured', 'eShopeo API not configured' );
        }

        $client = new Eshopeo_API_Client( $api_url, $pub_key );
        $body   = $client->content_generate( 'digest/generate', self::collect_stats(), 30 );

        if ( is_wp_error( $body ) ) {
            return $body;
        }
        if ( empty( $body['subject'] ) ) {
            return new WP_Error( 'eshopeo_digest_empty', 'eShopeo API returned an unexpected response.' );
        }
        return $body;
    }

    /* ── Send digest ───────────────────────────────────────────────────────── */

    public static function send_digest( string $to = '' ): bool {
        $body = self::generate_digest_content();
        if ( is_wp_error( $body ) ) {
            return false;
        }

        $email = $to ?: get_option( 'eshopeo_digest_email', get_option( 'admin_email', '' ) );
        if ( ! $email ) {
            return false;
        }

        return wp_mail(
            $email,
            sanitize_text_field( $body['subject'] ),
            wp_kses_post( $body['body'] )
        );
    }

    /* ── AJAX: send test ───────────────────────────────────────────────────── */

    public static function ajax_send_test(): void {
        check_ajax_referer( 'eshopeo_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $email = sanitize_email( $_POST['email'] ?? get_option( 'admin_email', '' ) );
        $body  = self::generate_digest_content();

        if ( is_wp_error( $body ) ) {
            wp_send_json_error( $body->get_error_message(), 502 );
        }

        if ( ! $email || ! wp_mail( $email, sanitize_text_field( $body['subject'] ), wp_kses_post( $body['body'] ) ) ) {
            wp_send_json_error( 'Digest generated but the email could not be sent. Check your mail configuration.', 502 );
        }

        wp_send_json_success( [
            'sent'    => true,
            'email'   => $email,
            'subject' => sanitize_text_field( $body['subject'] ),
            'body'    => wp_kses_post( $body['body'] ),
            'meta'    => $body['meta'] ?? null,
        ] );
    }
}
