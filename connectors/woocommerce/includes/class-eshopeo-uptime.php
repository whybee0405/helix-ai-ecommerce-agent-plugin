<?php
defined( 'ABSPATH' ) || exit;

class Eshopeo_Uptime {

    const CRON_HOOK     = 'eshopeo_uptime_check';
    const LOG_OPTION    = 'eshopeo_uptime_log';
    const MAX_ENTRIES   = 288; // 24h × 12 per hour.
    const ALERT_OPTION  = 'eshopeo_uptime_alert_email';

    /* ── Init ──────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_interval' ] );
        add_action( self::CRON_HOOK, [ __CLASS__, 'run_check' ] );

        // Schedule on init if not already scheduled.
        add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );

        add_action( 'wp_ajax_eshopeo_uptime_data', [ __CLASS__, 'ajax_data' ] );
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /* ── Custom interval ───────────────────────────────────────────────── */

    public static function add_cron_interval( array $schedules ): array {
        if ( ! isset( $schedules['every_5_minutes'] ) ) {
            $schedules['every_5_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 5 Minutes', 'eshopeo-connector' ),
            ];
        }
        return $schedules;
    }

    public static function maybe_schedule(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'every_5_minutes', self::CRON_HOOK );
        }
    }

    /* ── Check ─────────────────────────────────────────────────────────── */

    public static function run_check(): void {
        $start    = microtime( true );
        $response = wp_remote_get( site_url(), [ 'timeout' => 10, 'redirection' => 3 ] );
        $elapsed  = round( ( microtime( true ) - $start ) * 1000 );

        $status = 0;
        if ( is_wp_error( $response ) ) {
            $status = 0;
        } else {
            $status = (int) wp_remote_retrieve_response_code( $response );
        }

        $entry = [
            'ts'          => time(),
            'status'      => $status,
            'response_ms' => (int) $elapsed,
        ];

        // Load existing log (circular buffer).
        $log = json_decode( get_option( self::LOG_OPTION, '[]' ), true );
        if ( ! is_array( $log ) ) {
            $log = [];
        }
        $log[] = $entry;

        // Keep only the last MAX_ENTRIES.
        if ( count( $log ) > self::MAX_ENTRIES ) {
            $log = array_slice( $log, -self::MAX_ENTRIES );
        }

        update_option( self::LOG_OPTION, wp_json_encode( $log ), false );

        // Alert check: 3 consecutive non-200.
        self::maybe_alert( $log );
    }

    private static function maybe_alert( array $log ): void {
        $email = get_option( self::ALERT_OPTION, '' );
        if ( ! $email || ! is_email( $email ) ) {
            return;
        }

        // Check last 3 entries.
        $last3 = array_slice( $log, -3 );
        if ( count( $last3 ) < 3 ) {
            return;
        }

        $all_down = true;
        foreach ( $last3 as $entry ) {
            if ( (int) $entry['status'] === 200 ) {
                $all_down = false;
                break;
            }
        }

        if ( ! $all_down ) {
            return;
        }

        // Throttle: don't send more than once per hour.
        $last_alert = (int) get_option( 'eshopeo_uptime_last_alert', 0 );
        if ( time() - $last_alert < HOUR_IN_SECONDS ) {
            return;
        }

        update_option( 'eshopeo_uptime_last_alert', time(), false );

        $subject = sprintf( '[eShopeo] Site Down Alert — %s', get_bloginfo( 'name' ) );
        $message = sprintf(
            "Your site %s has returned a non-200 status code 3 times in a row.\n\nLast checks:\n%s",
            site_url(),
            implode( "\n", array_map( fn( $e ) => date( 'Y-m-d H:i:s', $e['ts'] ) . ' — HTTP ' . $e['status'] . ' (' . $e['response_ms'] . 'ms)', $last3 ) )
        );

        wp_mail( $email, $subject, $message );
    }

    /* ── AJAX: uptime data ─────────────────────────────────────────────── */

    public static function ajax_data(): void {
        check_ajax_referer( 'eshopeo_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        wp_send_json_success( self::get_stats() );
    }

    public static function schedule(): void {
        self::maybe_schedule();
    }

    /* ── Stats helper ──────────────────────────────────────────────────── */

    public static function get_stats(): array {
        $log = json_decode( get_option( self::LOG_OPTION, '[]' ), true );
        if ( ! is_array( $log ) || empty( $log ) ) {
            return [
                'status'      => 'unknown',
                'uptime_pct'  => 0,
                'avg_ms'      => 0,
                'log'         => [],
                'hourly'      => [],
            ];
        }

        $last   = end( $log );
        $status = ( (int) $last['status'] === 200 ) ? 'up' : 'down';

        $up_count    = 0;
        $total_ms    = 0;
        foreach ( $log as $entry ) {
            if ( (int) $entry['status'] === 200 ) {
                $up_count++;
            }
            $total_ms += (int) $entry['response_ms'];
        }

        $uptime_pct = round( ( $up_count / count( $log ) ) * 100, 2 );
        $avg_ms     = count( $log ) > 0 ? round( $total_ms / count( $log ) ) : 0;

        // Build 24 hourly buckets for sparkline.
        $now     = time();
        $hourly  = array_fill( 0, 24, null );
        foreach ( $log as $entry ) {
            $hours_ago = (int) floor( ( $now - $entry['ts'] ) / HOUR_IN_SECONDS );
            if ( $hours_ago >= 0 && $hours_ago < 24 ) {
                $bucket = 23 - $hours_ago;
                if ( $hourly[ $bucket ] === null ) {
                    $hourly[ $bucket ] = [];
                }
                $hourly[ $bucket ][] = (int) $entry['response_ms'];
            }
        }
        $sparkline = array_map( fn( $h ) => $h ? round( array_sum( $h ) / count( $h ) ) : 0, $hourly );

        return [
            'status'     => $status,
            'uptime_pct' => $uptime_pct,
            'avg_ms'     => $avg_ms,
            'sparkline'  => $sparkline,
            'log_count'  => count( $log ),
            'last_check' => date( 'Y-m-d H:i:s', (int) $last['ts'] ),
        ];
    }
}
