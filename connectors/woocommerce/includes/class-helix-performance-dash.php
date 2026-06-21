<?php
defined( 'ABSPATH' ) || exit;

class Helix_Performance_Dash {

    /* ── Hooks ─────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'wp_ajax_helix_performance_metrics', [ __CLASS__, 'ajax_metrics' ] );
    }

    /* ── AJAX Handler ──────────────────────────────────────────────────── */

    public static function ajax_metrics(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $metrics = self::collect();
        wp_send_json_success( $metrics );
    }

    /* ── Data Collection ───────────────────────────────────────────────── */

    public static function collect(): array {
        global $wpdb, $wp_version;

        // ── autoload_size ─────────────────────────────────────────────────
        $autoload_bytes  = (int) $wpdb->get_var(
            "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'"
        );
        $autoload_mb     = round( $autoload_bytes / 1048576, 2 );
        $autoload_grade  = self::grade_autoload( $autoload_mb );

        // ── total_options ─────────────────────────────────────────────────
        $total_options      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
        $total_options_grade = self::grade_options( $total_options );

        // ── transient counts ──────────────────────────────────────────────
        $transient_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_%'"
        );
        $expired_transient_count = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->options} o
             INNER JOIN {$wpdb->options} o2
               ON o2.option_name = CONCAT('_transient_timeout_', SUBSTRING(o.option_name, 13))
              AND CAST(o2.option_value AS UNSIGNED) < UNIX_TIMESTAMP()
             WHERE o.option_name LIKE '\_transient\_%'
               AND o.option_name NOT LIKE '\_transient\_timeout\_%'"
        );
        $transient_grade = self::grade_transients( $expired_transient_count );

        // ── table_overhead ────────────────────────────────────────────────
        $tables_status     = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
        $overhead_bytes    = 0;
        foreach ( $tables_status as $t ) {
            if ( str_starts_with( $t['Name'], $wpdb->prefix ) ) {
                $overhead_bytes += (int) $t['Data_free'];
            }
        }
        $overhead_mb      = round( $overhead_bytes / 1048576, 2 );
        $overhead_grade   = self::grade_overhead( $overhead_mb );

        // ── revision_count ────────────────────────────────────────────────
        $revision_count  = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"
        );
        $revision_grade  = self::grade_revisions( $revision_count );

        // ── orphaned_meta_count ───────────────────────────────────────────
        $orphaned_meta_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.ID IS NULL"
        );
        $orphaned_meta_grade = self::grade_orphaned( $orphaned_meta_count );

        // ── spam & trash counts ───────────────────────────────────────────
        $spam_count  = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
        );
        $trash_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"
        );
        $spam_grade  = self::grade_spam( $spam_count + $trash_count );

        // ── memory ────────────────────────────────────────────────────────
        $mem_used     = memory_get_usage( true );
        $mem_limit    = wp_convert_hr_to_bytes( WP_MEMORY_LIMIT );
        $mem_pct      = $mem_limit > 0 ? round( $mem_used / $mem_limit * 100, 1 ) : 0;
        $mem_used_mb  = round( $mem_used / 1048576, 1 );
        $mem_limit_mb = round( $mem_limit / 1048576, 1 );
        $mem_grade    = self::grade_memory( $mem_pct );

        // ── system info ───────────────────────────────────────────────────
        $php_version = PHP_VERSION;
        $php_grade   = self::grade_php( $php_version );

        $wp_ver       = $wp_version;
        $wp_ver_grade = 'A'; // just informational

        // ── active plugins ────────────────────────────────────────────────
        $active_plugin_count = count( (array) get_option( 'active_plugins', [] ) );
        $plugin_grade        = self::grade_plugins( $active_plugin_count );

        // ── cron backlog ──────────────────────────────────────────────────
        $cron_events = 0;
        $cron_array  = _get_cron_array();
        if ( is_array( $cron_array ) ) {
            foreach ( $cron_array as $events ) {
                $cron_events += count( $events );
            }
        }
        $cron_grade = self::grade_cron( $cron_events );

        // ── heartbeat & object cache ──────────────────────────────────────
        $heartbeat_interval   = get_option( 'helix_heartbeat_interval', 'default' );
        $object_cache_enabled = (bool) wp_using_ext_object_cache();

        // ── overall grade ─────────────────────────────────────────────────
        $grade_values = [
            $autoload_grade, $total_options_grade, $transient_grade,
            $overhead_grade, $revision_grade, $orphaned_meta_grade,
            $spam_grade, $mem_grade, $php_grade, $plugin_grade, $cron_grade,
        ];
        $overall_grade = self::overall_grade( $grade_values );

        return [
            'overall_grade'          => $overall_grade,
            'autoload_size_mb'       => $autoload_mb,
            'autoload_grade'         => $autoload_grade,
            'total_options'          => $total_options,
            'total_options_grade'    => $total_options_grade,
            'transient_count'        => $transient_count,
            'expired_transient_count'=> $expired_transient_count,
            'transient_grade'        => $transient_grade,
            'table_overhead_mb'      => $overhead_mb,
            'overhead_grade'         => $overhead_grade,
            'revision_count'         => $revision_count,
            'revision_grade'         => $revision_grade,
            'orphaned_meta_count'    => $orphaned_meta_count,
            'orphaned_meta_grade'    => $orphaned_meta_grade,
            'spam_count'             => $spam_count,
            'trash_count'            => $trash_count,
            'spam_grade'             => $spam_grade,
            'memory_used_mb'         => $mem_used_mb,
            'memory_limit_mb'        => $mem_limit_mb,
            'memory_pct'             => $mem_pct,
            'memory_grade'           => $mem_grade,
            'php_version'            => $php_version,
            'php_grade'              => $php_grade,
            'wp_version'             => $wp_ver,
            'wp_version_grade'       => $wp_ver_grade,
            'active_plugin_count'    => $active_plugin_count,
            'plugin_grade'           => $plugin_grade,
            'cron_backlog'           => $cron_events,
            'cron_grade'             => $cron_grade,
            'heartbeat_interval'     => $heartbeat_interval,
            'object_cache_enabled'   => $object_cache_enabled,
        ];
    }

    /* ── Grading helpers ───────────────────────────────────────────────── */

    private static function grade_autoload( float $mb ): string {
        if ( $mb < 1 )   return 'A';
        if ( $mb < 3 )   return 'B';
        if ( $mb < 8 )   return 'C';
        if ( $mb < 20 )  return 'D';
        return 'F';
    }

    private static function grade_options( int $count ): string {
        if ( $count < 500 )  return 'A';
        if ( $count < 1000 ) return 'B';
        if ( $count < 2000 ) return 'C';
        if ( $count < 5000 ) return 'D';
        return 'F';
    }

    private static function grade_transients( int $expired ): string {
        if ( $expired < 10 )  return 'A';
        if ( $expired < 50 )  return 'B';
        if ( $expired < 200 ) return 'C';
        if ( $expired < 500 ) return 'D';
        return 'F';
    }

    private static function grade_overhead( float $mb ): string {
        if ( $mb < 1 )   return 'A';
        if ( $mb < 5 )   return 'B';
        if ( $mb < 20 )  return 'C';
        if ( $mb < 100 ) return 'D';
        return 'F';
    }

    private static function grade_revisions( int $count ): string {
        if ( $count < 50 )   return 'A';
        if ( $count < 200 )  return 'B';
        if ( $count < 500 )  return 'C';
        if ( $count < 2000 ) return 'D';
        return 'F';
    }

    private static function grade_orphaned( int $count ): string {
        if ( $count < 10 )   return 'A';
        if ( $count < 100 )  return 'B';
        if ( $count < 500 )  return 'C';
        if ( $count < 2000 ) return 'D';
        return 'F';
    }

    private static function grade_spam( int $count ): string {
        if ( $count < 5 )   return 'A';
        if ( $count < 50 )  return 'B';
        if ( $count < 200 ) return 'C';
        if ( $count < 500 ) return 'D';
        return 'F';
    }

    private static function grade_memory( float $pct ): string {
        if ( $pct < 40 )  return 'A';
        if ( $pct < 60 )  return 'B';
        if ( $pct < 75 )  return 'C';
        if ( $pct < 90 )  return 'D';
        return 'F';
    }

    private static function grade_php( string $version ): string {
        if ( version_compare( $version, '8.2', '>=' ) ) return 'A';
        if ( version_compare( $version, '8.1', '>=' ) ) return 'B';
        if ( version_compare( $version, '8.0', '>=' ) ) return 'C';
        if ( version_compare( $version, '7.4', '>=' ) ) return 'D';
        return 'F';
    }

    private static function grade_plugins( int $count ): string {
        if ( $count < 15 ) return 'A';
        if ( $count < 25 ) return 'B';
        if ( $count < 40 ) return 'C';
        if ( $count < 60 ) return 'D';
        return 'F';
    }

    private static function grade_cron( int $events ): string {
        if ( $events < 10 )  return 'A';
        if ( $events < 30 )  return 'B';
        if ( $events < 100 ) return 'C';
        if ( $events < 300 ) return 'D';
        return 'F';
    }

    private static function overall_grade( array $grades ): string {
        $map    = [ 'A' => 4, 'B' => 3, 'C' => 2, 'D' => 1, 'F' => 0 ];
        $rev    = array_flip( $map );
        $scores = array_map( fn( $g ) => $map[ $g ] ?? 0, $grades );
        $avg    = count( $scores ) > 0 ? array_sum( $scores ) / count( $scores ) : 0;
        $rounded = (int) round( $avg );
        return $rev[ max( 0, min( 4, $rounded ) ) ] ?? 'F';
    }
}
