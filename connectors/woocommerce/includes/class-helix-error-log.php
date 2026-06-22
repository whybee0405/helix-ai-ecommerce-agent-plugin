<?php
defined( 'ABSPATH' ) || exit;

class Helix_Error_Log {

    const MAX_LINES = 200;

    /* ── Init ──────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'wp_ajax_helix_get_error_log',   [ __CLASS__, 'ajax_get' ] );
        add_action( 'wp_ajax_helix_clear_error_log', [ __CLASS__, 'ajax_clear' ] );
    }

    /* ── Log file path ─────────────────────────────────────────────────── */

    private static function log_path(): string {
        $wp_debug_log = WP_CONTENT_DIR . '/debug.log';
        if ( file_exists( $wp_debug_log ) ) {
            return $wp_debug_log;
        }
        $ini_log = (string) ini_get( 'error_log' );
        if ( $ini_log && file_exists( $ini_log ) ) {
            return $ini_log;
        }
        return $wp_debug_log; // Fallback — may not exist yet.
    }

    /* ── AJAX: get ──────────────────────────────────────────────────────── */

    public static function ajax_get(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $path = self::log_path();

        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            wp_send_json_success( [
                'entries' => [],
                'counts'  => [ 'Fatal' => 0, 'Warning' => 0, 'Notice' => 0, 'Deprecated' => 0 ],
                'path'    => $path,
                'exists'  => false,
            ] );
            return;
        }

        // Read last MAX_LINES lines efficiently.
        $lines = self::tail_file( $path, self::MAX_LINES );
        $parsed = [];
        foreach ( $lines as $line ) {
            $entry = self::parse_line( $line );
            if ( $entry ) {
                $parsed[] = $entry;
            }
        }

        // Group counts.
        $counts = [ 'Fatal' => 0, 'Warning' => 0, 'Notice' => 0, 'Deprecated' => 0 ];
        foreach ( $parsed as $entry ) {
            $level = $entry['level'];
            if ( isset( $counts[ $level ] ) ) {
                $counts[ $level ]++;
            }
        }

        // Filter by level if requested.
        $filter = sanitize_text_field( $_POST['level'] ?? '' );
        if ( $filter && isset( $counts[ $filter ] ) ) {
            $parsed = array_values( array_filter( $parsed, fn( $e ) => $e['level'] === $filter ) );
        }

        wp_send_json_success( [
            'entries' => array_reverse( $parsed ),
            'counts'  => $counts,
            'path'    => $path,
            'exists'  => true,
        ] );
    }

    /* ── AJAX: clear ────────────────────────────────────────────────────── */

    public static function ajax_clear(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $path = self::log_path();

        if ( ! file_exists( $path ) ) {
            wp_send_json_success( [ 'cleared' => true, 'snapshot_id' => null ] );
            return;
        }

        // Snapshot last 50 lines first.
        $last_lines = self::tail_file( $path, 50 );
        $snap_id    = Helix_Snapshot::save( 'clear_error_log', [
            'path'  => $path,
            'lines' => $last_lines,
        ] );

        // Truncate the file.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $path, '' );

        wp_send_json_success( [
            'cleared'     => true,
            'snapshot_id' => $snap_id,
        ] );
    }

    /* ── Parse a log line ───────────────────────────────────────────────── */

    private static function parse_line( string $line ): ?array {
        $line = trim( $line );
        if ( empty( $line ) ) {
            return null;
        }

        $timestamp = '';
        $message   = $line;
        $level     = 'Notice';
        $file      = '';
        $lineno    = '';

        // Match [dd-Mon-YYYY HH:MM:SS UTC] PHP Level: message in ...
        if ( preg_match( '/^\[([^\]]+)\]\s+(.+)$/s', $line, $m ) ) {
            $timestamp = $m[1];
            $rest      = $m[2];

            if ( preg_match( '/^PHP\s+(Fatal error|Warning|Notice|Deprecated|Parse error):\s+(.+?)\s+in\s+(.+?)\s+on\s+line\s+(\d+)/is', $rest, $em ) ) {
                $level_str = strtolower( $em[1] );
                $level     = match (true) {
                    str_contains( $level_str, 'fatal' ), str_contains( $level_str, 'parse' ) => 'Fatal',
                    str_contains( $level_str, 'warning' ) => 'Warning',
                    str_contains( $level_str, 'deprecated' ) => 'Deprecated',
                    default => 'Notice',
                };
                $message   = $em[2];
                $file      = $em[3];
                $lineno    = $em[4];
            } else {
                $message = $rest;
                if ( stripos( $message, 'Fatal' ) !== false || stripos( $message, 'Parse error' ) !== false ) {
                    $level = 'Fatal';
                } elseif ( stripos( $message, 'Warning' ) !== false ) {
                    $level = 'Warning';
                } elseif ( stripos( $message, 'Deprecated' ) !== false ) {
                    $level = 'Deprecated';
                }
            }
        } else {
            // Unparseable — still return as Notice.
            if ( strlen( $line ) > 300 ) {
                return null;
            }
        }

        return [
            'timestamp' => esc_html( $timestamp ),
            'level'     => $level,
            'message'   => esc_html( mb_substr( $message, 0, 300 ) ),
            'file'      => esc_html( $file ),
            'line'      => esc_html( $lineno ),
        ];
    }

    /* ── tail_file ──────────────────────────────────────────────────────── */

    private static function tail_file( string $path, int $n ): array {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $content = file_get_contents( $path );
        if ( $content === false ) {
            return [];
        }
        $lines = explode( "\n", $content );
        return array_slice( $lines, -$n );
    }
}
