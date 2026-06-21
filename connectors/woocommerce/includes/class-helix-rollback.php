<?php
defined( 'ABSPATH' ) || exit;

class Helix_Rollback {

    /* ── Hooks ─────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'wp_ajax_helix_action_rollback', [ __CLASS__, 'ajax_rollback' ] );
    }

    /* ── AJAX Handler ──────────────────────────────────────────────────── */

    public static function ajax_rollback(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $snapshot_id = (int) ( $_POST['snapshot_id'] ?? 0 );
        if ( $snapshot_id <= 0 ) {
            wp_send_json_error( 'Invalid snapshot_id', 400 );
        }

        $snapshot = Helix_Snapshot::get( $snapshot_id );
        if ( null === $snapshot ) {
            wp_send_json_error( 'Snapshot not found or expired', 404 );
        }

        $action = $snapshot['action'] ?? '';

        $method = 'rollback_' . $action;
        if ( ! method_exists( __CLASS__, $method ) ) {
            wp_send_json_error( 'No rollback handler for action: ' . esc_html( $action ), 400 );
        }

        $result = self::$method( $snapshot );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message(), 500 );
        }

        Helix_Action_Log::record( [
            'action'      => 'rollback:' . $action,
            'dry_run'     => false,
            'snapshot_id' => $snapshot_id,
            'affected'    => $result['affected'] ?? null,
        ] );

        // Remove the snapshot after successful rollback.
        Helix_Snapshot::delete( $snapshot_id );

        wp_send_json_success( array_merge( $result, [
            'snapshot_id' => $snapshot_id,
            'action'      => $action,
        ] ) );
    }

    /* ── Rollback: delete_revisions ────────────────────────────────────── */

    private static function rollback_delete_revisions( array $snapshot ): array|WP_Error {
        $revisions = $snapshot['revisions'] ?? [];
        $restored  = 0;

        foreach ( $revisions as $rev ) {
            $args = [
                'post_author'           => $rev['post_author'] ?? get_current_user_id(),
                'post_date'             => $rev['post_date'] ?? current_time( 'mysql' ),
                'post_date_gmt'         => $rev['post_date_gmt'] ?? current_time( 'mysql', 1 ),
                'post_content'          => $rev['post_content'] ?? '',
                'post_title'            => $rev['post_title'] ?? '',
                'post_excerpt'          => $rev['post_excerpt'] ?? '',
                'post_status'           => 'inherit',
                'post_type'             => 'revision',
                'post_name'             => $rev['post_name'] ?? '',
                'post_parent'           => $rev['post_parent'] ?? 0,
                'post_modified'         => $rev['post_modified'] ?? current_time( 'mysql' ),
                'post_modified_gmt'     => $rev['post_modified_gmt'] ?? current_time( 'mysql', 1 ),
                'comment_status'        => 'closed',
                'ping_status'           => 'closed',
            ];

            $new_id = wp_insert_post( $args, true );
            if ( ! is_wp_error( $new_id ) && $new_id > 0 ) {
                $restored++;
            }
        }

        return [ 'affected' => $restored ];
    }

    /* ── Rollback: delete_orphaned_meta ────────────────────────────────── */

    private static function rollback_delete_orphaned_meta( array $snapshot ): array|WP_Error {
        $rows     = $snapshot['rows'] ?? [];
        $restored = 0;

        foreach ( $rows as $row ) {
            $result = add_post_meta(
                (int) $row['post_id'],
                $row['meta_key'],
                $row['meta_value'],
                false
            );
            if ( $result ) {
                $restored++;
            }
        }

        return [ 'affected' => $restored ];
    }

    /* ── Rollback: fix_autoload ────────────────────────────────────────── */

    private static function rollback_fix_autoload( array $snapshot ): array|WP_Error {
        global $wpdb;

        $rows     = $snapshot['rows'] ?? [];
        $restored = 0;

        foreach ( $rows as $row ) {
            $updated = $wpdb->update(
                $wpdb->options,
                [ 'autoload' => $row['autoload'] ],
                [ 'option_name' => $row['option_name'] ]
            );
            if ( false !== $updated ) {
                $restored++;
            }
        }

        return [ 'affected' => $restored ];
    }

    /* ── Rollback: set_heartbeat ───────────────────────────────────────── */

    private static function rollback_set_heartbeat( array $snapshot ): array|WP_Error {
        $old_value = $snapshot['old_value'] ?? 'default';
        update_option( 'helix_heartbeat_interval', $old_value, false );
        return [ 'affected' => 1, 'restored_value' => $old_value ];
    }

    /* ── Rollback: disable_heartbeat_frontend ──────────────────────────── */

    private static function rollback_disable_heartbeat_frontend( array $snapshot ): array|WP_Error {
        $old_value = $snapshot['old_value'] ?? 0;
        update_option( 'helix_disable_heartbeat_frontend', $old_value, false );
        return [ 'affected' => 1, 'restored_value' => $old_value ];
    }
}
