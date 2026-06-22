<?php
defined( 'ABSPATH' ) || exit;

/**
 * Helix_Web_Vitals — PageSpeed Insights integration for Core Web Vitals.
 */
class Helix_Web_Vitals {

    const CRON_HOOK     = 'helix_web_vitals_check';
    const CACHE_OPTION  = 'helix_web_vitals_cache';
    const CACHE_TTL     = DAY_IN_SECONDS;

    /* ── Init ──────────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( self::CRON_HOOK, [ __CLASS__, 'run_check' ] );
        add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );

        add_action( 'wp_ajax_helix_web_vitals_scan', [ __CLASS__, 'ajax_scan' ] );
    }

    public static function maybe_schedule(): void {
        if ( ! get_option( 'helix_pagespeed_api_key', '' ) ) {
            return;
        }
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'weekly', self::CRON_HOOK );
        }
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /* ── Fetch metrics ─────────────────────────────────────────────────────── */

    private static function fetch_metrics( string $strategy = 'mobile' ): array|false {
        $api_key = get_option( 'helix_pagespeed_api_key', '' );
        if ( ! $api_key ) {
            return false;
        }

        $url = add_query_arg( [
            'url'      => rawurlencode( site_url() ),
            'strategy' => $strategy,
            'key'      => $api_key,
            'category' => 'performance',
        ], 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed' );

        $response = wp_remote_get( $url, [ 'timeout' => 30 ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body['lighthouseResult'] ) ) {
            return false;
        }

        $audits    = $body['lighthouseResult']['audits'] ?? [];
        $categories = $body['lighthouseResult']['categories'] ?? [];

        $score = round( ( $categories['performance']['score'] ?? 0 ) * 100 );

        $get_metric = function ( string $key ) use ( $audits ): array {
            $audit   = $audits[ $key ] ?? [];
            $value   = $audit['numericValue'] ?? null;
            $display = $audit['displayValue'] ?? '—';
            $rating  = $audit['score'] !== null ? ( $audit['score'] >= 0.9 ? 'good' : ( $audit['score'] >= 0.5 ? 'needs-improvement' : 'poor' ) ) : 'unknown';
            return [
                'value'   => $value !== null ? round( $value, 2 ) : null,
                'display' => $display,
                'rating'  => $rating,
            ];
        };

        return [
            'score'     => $score,
            'strategy'  => $strategy,
            'lcp'       => $get_metric( 'largest-contentful-paint' ),
            'inp'       => $get_metric( 'interactive' ),
            'cls'       => $get_metric( 'cumulative-layout-shift' ),
            'fcp'       => $get_metric( 'first-contentful-paint' ),
            'ttfb'      => $get_metric( 'server-response-time' ),
            'checked_at'=> time(),
        ];
    }

    /* ── Cron check ────────────────────────────────────────────────────────── */

    public static function run_check(): void {
        $metrics = self::fetch_metrics( 'mobile' );
        if ( $metrics ) {
            update_option( self::CACHE_OPTION, wp_json_encode( $metrics ), false );
        }
    }

    /* ── AJAX: scan ────────────────────────────────────────────────────────── */

    public static function ajax_scan(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $force = filter_var( $_POST['force'] ?? false, FILTER_VALIDATE_BOOLEAN );

        // Return cache if fresh and not forced.
        if ( ! $force ) {
            $cached_raw = get_option( self::CACHE_OPTION );
            if ( $cached_raw ) {
                $cached = json_decode( $cached_raw, true );
                if ( isset( $cached['checked_at'] ) && ( time() - (int) $cached['checked_at'] ) < self::CACHE_TTL ) {
                    $cached['from_cache'] = true;
                    wp_send_json_success( $cached );
                    return;
                }
            }
        }

        $api_key = get_option( 'helix_pagespeed_api_key', '' );
        if ( ! $api_key ) {
            wp_send_json_error( 'PageSpeed API key not configured. Add it in Helix Settings.', 400 );
        }

        $metrics = self::fetch_metrics( 'mobile' );
        if ( false === $metrics ) {
            wp_send_json_error( 'Failed to fetch PageSpeed data. Check your API key and try again.', 502 );
        }

        update_option( self::CACHE_OPTION, wp_json_encode( $metrics ), false );

        $metrics['from_cache'] = false;
        wp_send_json_success( $metrics );
    }
}
