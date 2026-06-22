<?php
defined( 'ABSPATH' ) || exit;

class Helix_Backup {

    const CRON_HOOK    = 'helix_db_backup';
    const BACKUP_DIR   = 'helix-backups';
    const KEEP_BACKUPS = 7;

    /* ── Init ──────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( self::CRON_HOOK, [ __CLASS__, 'run_backup' ] );
        add_action( 'init',          [ __CLASS__, 'maybe_schedule' ] );

        add_action( 'wp_ajax_helix_run_backup',      [ __CLASS__, 'ajax_run' ] );
        add_action( 'wp_ajax_helix_list_backups',    [ __CLASS__, 'ajax_list' ] );
        add_action( 'wp_ajax_helix_download_backup', [ __CLASS__, 'ajax_download' ] );
        add_action( 'wp_ajax_helix_delete_backup',   [ __CLASS__, 'ajax_delete' ] );
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public static function maybe_schedule(): void {
        $schedule = get_option( 'helix_backup_schedule', 'daily' );
        if ( $schedule === 'off' ) {
            return;
        }
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), $schedule, self::CRON_HOOK );
        }
    }

    /* ── Backup directory ──────────────────────────────────────────────── */

    private static function backup_dir(): string {
        return WP_CONTENT_DIR . '/' . self::BACKUP_DIR;
    }

    private static function ensure_dir(): bool {
        $dir = self::backup_dir();
        if ( ! is_dir( $dir ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
            if ( ! mkdir( $dir, 0750, true ) ) {
                return false;
            }
        }
        // Create .htaccess to deny direct access.
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $htaccess, "Order Allow,Deny\nDeny from all\n" );
        }
        // Create index.php to prevent directory listing.
        $index = $dir . '/index.php';
        if ( ! file_exists( $index ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $index, '<?php // Silence is golden.' );
        }
        return true;
    }

    /* ── Run backup ────────────────────────────────────────────────────── */

    public static function run_backup(): array {
        global $wpdb;

        if ( ! self::ensure_dir() ) {
            return [ 'error' => 'Could not create backup directory.' ];
        }

        $sql  = "-- Helix DB Backup\n-- Date: " . current_time( 'mysql' ) . "\n-- Site: " . site_url() . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        // Get all tables.
        $tables = $wpdb->get_col( 'SHOW TABLES' );

        foreach ( $tables as $table ) {
            // DDL.
            $create_row = $wpdb->get_row( "SHOW CREATE TABLE `" . esc_sql( $table ) . "`", ARRAY_N );
            if ( $create_row ) {
                $sql .= "DROP TABLE IF EXISTS `" . esc_sql( $table ) . "`;\n";
                $sql .= $create_row[1] . ";\n\n";
            }

            // Data — fetch in chunks of 500.
            $offset = 0;
            $chunk  = 500;
            do {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows = $wpdb->get_results(
                    "SELECT * FROM `" . esc_sql( $table ) . "` LIMIT {$offset}, {$chunk}",
                    ARRAY_A
                );
                if ( ! empty( $rows ) ) {
                    foreach ( $rows as $row ) {
                        $values = array_map( function ( $val ) use ( $wpdb ) {
                            if ( $val === null ) {
                                return 'NULL';
                            }
                            return "'" . esc_sql( $val ) . "'";
                        }, $row );
                        $sql .= "INSERT INTO `" . esc_sql( $table ) . "` VALUES (" . implode( ', ', $values ) . ");\n";
                    }
                }
                $offset += $chunk;
            } while ( count( $rows ?? [] ) === $chunk );

            $sql .= "\n";
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        // Gzip and save.
        $filename = 'backup-' . date( 'Y-m-d-His' ) . '.sql.gz';
        $filepath = self::backup_dir() . '/' . $filename;

        $gz = gzencode( $sql, 6 );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $filepath, $gz );

        $size = filesize( $filepath );

        // Prune old backups.
        self::prune_old_backups();

        update_option( 'helix_backup_last_run', current_time( 'mysql' ), false );

        return [
            'filename' => $filename,
            'size'     => $size,
            'size_kb'  => round( $size / 1024, 1 ),
        ];
    }

    private static function prune_old_backups(): void {
        $keep     = max( 1, (int) get_option( 'helix_backup_keep', self::KEEP_BACKUPS ) );
        $dir      = self::backup_dir();
        $files    = glob( $dir . '/backup-*.sql.gz' );
        if ( ! $files ) {
            return;
        }
        usort( $files, fn( $a, $b ) => filemtime( $a ) <=> filemtime( $b ) );
        while ( count( $files ) > $keep ) {
            $old = array_shift( $files );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $old );
        }
    }

    /* ── AJAX: run ──────────────────────────────────────────────────────── */

    public static function ajax_run(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $result = self::run_backup();
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }
        wp_send_json_success( $result );
    }

    /* ── AJAX: list ─────────────────────────────────────────────────────── */

    public static function ajax_list(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        wp_send_json_success( self::get_backups() );
    }

    public static function get_backups(): array {
        $dir   = self::backup_dir();
        $files = glob( $dir . '/backup-*.sql.gz' );
        if ( ! $files ) {
            return [ 'backups' => [], 'total_size_kb' => 0 ];
        }

        usort( $files, fn( $a, $b ) => filemtime( $b ) <=> filemtime( $a ) );

        $backups    = [];
        $total_size = 0;
        foreach ( $files as $file ) {
            $size        = (int) filesize( $file );
            $total_size += $size;
            $backups[]   = [
                'filename' => basename( $file ),
                'date'     => date( 'Y-m-d H:i:s', (int) filemtime( $file ) ),
                'size'     => $size,
                'size_kb'  => round( $size / 1024, 1 ),
            ];
        }

        return [
            'backups'       => $backups,
            'total_size_kb' => round( $total_size / 1024, 1 ),
        ];
    }

    /* ── AJAX: download ─────────────────────────────────────────────────── */

    public static function ajax_download(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $filename = sanitize_file_name( $_POST['filename'] ?? '' );
        if ( ! $filename ) {
            wp_send_json_error( 'No filename specified', 400 );
        }

        $filepath = self::backup_dir() . '/' . $filename;

        // Security: ensure file is within backup dir and ends with .sql.gz.
        if (
            ! str_ends_with( $filename, '.sql.gz' ) ||
            ! file_exists( $filepath ) ||
            realpath( dirname( $filepath ) ) !== realpath( self::backup_dir() )
        ) {
            wp_send_json_error( 'Invalid file', 400 );
        }

        // Stream file.
        header( 'Content-Type: application/gzip' );
        header( 'Content-Disposition: attachment; filename="' . esc_attr( $filename ) . '"' );
        header( 'Content-Length: ' . filesize( $filepath ) );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        readfile( $filepath );
        exit;
    }

    /* ── AJAX: delete ───────────────────────────────────────────────────── */

    public static function ajax_delete(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $filename = sanitize_file_name( $_POST['filename'] ?? '' );
        if ( ! $filename ) {
            wp_send_json_error( 'No filename specified', 400 );
        }

        $filepath = self::backup_dir() . '/' . $filename;

        if (
            ! str_ends_with( $filename, '.sql.gz' ) ||
            ! file_exists( $filepath ) ||
            realpath( dirname( $filepath ) ) !== realpath( self::backup_dir() )
        ) {
            wp_send_json_error( 'Invalid file', 400 );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        unlink( $filepath );

        wp_send_json_success( self::get_backups() );
    }
}
