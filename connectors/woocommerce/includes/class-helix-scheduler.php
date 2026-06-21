<?php
defined( 'ABSPATH' ) || exit;

class Helix_Scheduler {

    const HOOKS = [
        'helix_weekly_cleanup',
        'helix_monthly_audit',
        'helix_daily_revision_prune',
    ];

    /* ── Init ──────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'helix_weekly_cleanup',       [ __CLASS__, 'weekly_cleanup' ] );
        add_action( 'helix_monthly_audit',        [ __CLASS__, 'monthly_audit' ] );
        add_action( 'helix_daily_revision_prune', [ __CLASS__, 'daily_revision_prune' ] );

        // Register custom 'monthly' interval if not present.
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_intervals' ] );

        // AJAX handlers for the Scheduler admin tab.
        add_action( 'wp_ajax_helix_scheduler_toggle',   [ __CLASS__, 'ajax_toggle' ] );
        add_action( 'wp_ajax_helix_scheduler_run_now',  [ __CLASS__, 'ajax_run_now' ] );
        add_action( 'wp_ajax_helix_scheduler_status',   [ __CLASS__, 'ajax_status' ] );
    }

    /* ── Custom Intervals ──────────────────────────────────────────────── */

    public static function add_cron_intervals( array $schedules ): array {
        if ( ! isset( $schedules['monthly'] ) ) {
            $schedules['monthly'] = [
                'interval' => MONTH_IN_SECONDS,
                'display'  => __( 'Once Monthly', 'helix-connector' ),
            ];
        }
        return $schedules;
    }

    /* ── Schedule / Unschedule ─────────────────────────────────────────── */

    public static function schedule_all(): void {
        if ( ! wp_next_scheduled( 'helix_weekly_cleanup' ) ) {
            wp_schedule_event( time(), 'weekly', 'helix_weekly_cleanup' );
        }
        if ( ! wp_next_scheduled( 'helix_monthly_audit' ) ) {
            wp_schedule_event( time(), 'monthly', 'helix_monthly_audit' );
        }
        // Daily revision prune only if enabled.
        if (
            get_option( 'helix_scheduler_daily_revision_prune_enabled', 0 ) &&
            ! wp_next_scheduled( 'helix_daily_revision_prune' )
        ) {
            wp_schedule_event( time(), 'daily', 'helix_daily_revision_prune' );
        }
    }

    public static function unschedule_all(): void {
        foreach ( self::HOOKS as $hook ) {
            wp_clear_scheduled_hook( $hook );
        }
    }

    /* ── Job Handlers ──────────────────────────────────────────────────── */

    public static function weekly_cleanup(): void {
        // Clear expired transients.
        $result = Helix_Quick_Actions::action_clear_transients( false );

        // Purge expired snapshots.
        Helix_Snapshot::purge_expired();

        update_option( 'helix_scheduler_helix_weekly_cleanup_last_run', current_time( 'mysql' ), false );
        update_option( 'helix_scheduler_helix_weekly_cleanup_last_result', wp_json_encode( $result ), false );
    }

    public static function monthly_audit(): void {
        $orphaned = Helix_Quick_Actions::action_find_orphaned_media( true );
        $thin     = Helix_Quick_Actions::action_find_thin_content( true );

        $audit = [
            'run_at'         => current_time( 'mysql' ),
            'orphaned_media' => $orphaned,
            'thin_content'   => $thin,
        ];

        update_option( 'helix_last_audit', $audit, false );
        update_option( 'helix_scheduler_helix_monthly_audit_last_run', current_time( 'mysql' ), false );
        update_option( 'helix_scheduler_helix_monthly_audit_last_result', wp_json_encode( [
            'orphaned_count' => $orphaned['count'],
            'thin_count'     => $thin['count'],
        ] ), false );
    }

    public static function daily_revision_prune(): void {
        if ( ! get_option( 'helix_scheduler_daily_revision_prune_enabled', 0 ) ) {
            return;
        }

        $result = Helix_Quick_Actions::action_delete_revisions( false );

        update_option( 'helix_scheduler_helix_daily_revision_prune_last_run', current_time( 'mysql' ), false );
        update_option( 'helix_scheduler_helix_daily_revision_prune_last_result', wp_json_encode( $result ), false );
    }

    /* ── AJAX: status ──────────────────────────────────────────────────── */

    public static function ajax_status(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $jobs = self::get_jobs_status();
        wp_send_json_success( $jobs );
    }

    /* ── AJAX: toggle ──────────────────────────────────────────────────── */

    public static function ajax_toggle(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $hook    = sanitize_key( $_POST['hook'] ?? '' );
        $enabled = filter_var( $_POST['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN );

        if ( ! in_array( $hook, self::HOOKS, true ) ) {
            wp_send_json_error( 'Invalid hook', 400 );
        }

        $option_key = 'helix_scheduler_' . $hook . '_enabled';
        update_option( $option_key, $enabled ? 1 : 0, false );

        if ( $enabled ) {
            if ( ! wp_next_scheduled( $hook ) ) {
                $freq = self::hook_frequency( $hook );
                wp_schedule_event( time(), $freq, $hook );
            }
        } else {
            wp_clear_scheduled_hook( $hook );
        }

        wp_send_json_success( self::get_jobs_status() );
    }

    /* ── AJAX: run now ─────────────────────────────────────────────────── */

    public static function ajax_run_now(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $hook = sanitize_key( $_POST['hook'] ?? '' );

        if ( ! in_array( $hook, self::HOOKS, true ) ) {
            wp_send_json_error( 'Invalid hook', 400 );
        }

        do_action( $hook );

        wp_send_json_success( [
            'hook'   => $hook,
            'ran_at' => current_time( 'mysql' ),
            'jobs'   => self::get_jobs_status(),
        ] );
    }

    /* ── Status Helper ─────────────────────────────────────────────────── */

    public static function get_jobs_status(): array {
        $job_defs = [
            [
                'hook'        => 'helix_weekly_cleanup',
                'label'       => 'Weekly Cleanup',
                'description' => 'Clears expired transients and purges old snapshots.',
                'frequency'   => 'weekly',
            ],
            [
                'hook'        => 'helix_monthly_audit',
                'label'       => 'Monthly Audit',
                'description' => 'Finds orphaned media and thin content; saves results for review.',
                'frequency'   => 'monthly',
            ],
            [
                'hook'        => 'helix_daily_revision_prune',
                'label'       => 'Daily Revision Prune',
                'description' => 'Deletes post revisions beyond the latest 5 per post (only when enabled).',
                'frequency'   => 'daily',
            ],
        ];

        $jobs = [];
        foreach ( $job_defs as $def ) {
            $hook        = $def['hook'];
            $enabled_opt = 'helix_scheduler_' . $hook . '_enabled';
            $last_run    = get_option( 'helix_scheduler_' . $hook . '_last_run', null );
            $last_result = get_option( 'helix_scheduler_' . $hook . '_last_result', null );
            $next        = wp_next_scheduled( $hook );

            $jobs[] = [
                'hook'        => $hook,
                'label'       => $def['label'],
                'description' => $def['description'],
                'frequency'   => $def['frequency'],
                'enabled'     => (bool) get_option( $enabled_opt, $hook !== 'helix_daily_revision_prune' ? 1 : 0 ),
                'last_run'    => $last_run,
                'last_result' => $last_result ? json_decode( $last_result, true ) : null,
                'next_run'    => $next ? date( 'Y-m-d H:i:s', $next ) : null,
            ];
        }

        return $jobs;
    }

    /* ── Helper ────────────────────────────────────────────────────────── */

    private static function hook_frequency( string $hook ): string {
        $map = [
            'helix_weekly_cleanup'       => 'weekly',
            'helix_monthly_audit'        => 'monthly',
            'helix_daily_revision_prune' => 'daily',
        ];
        return $map[ $hook ] ?? 'weekly';
    }
}
