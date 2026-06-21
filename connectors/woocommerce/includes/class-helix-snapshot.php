<?php
defined( 'ABSPATH' ) || exit;

class Helix_Snapshot {
    const TABLE    = 'helix_snapshots';
    const TTL_DAYS = 7;

    public static function install(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action_id VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            snapshot LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY action_id (action_id),
            KEY expires_at (expires_at)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function save( string $action_id, array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->insert(
            $table,
            [
                'action_id'  => $action_id,
                'user_id'    => get_current_user_id(),
                'snapshot'   => wp_json_encode( $data ),
                'created_at' => current_time( 'mysql' ),
                'expires_at' => date( 'Y-m-d H:i:s', strtotime( '+' . self::TTL_DAYS . ' days' ) ),
            ]
        );
        return (int) $wpdb->insert_id;
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE id = %d AND expires_at > NOW()",
                $id
            )
        );
        return $row ? json_decode( $row->snapshot, true ) : null;
    }

    public static function delete( int $id ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . self::TABLE, [ 'id' => $id ] );
    }

    public static function purge_expired(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}" . self::TABLE . ' WHERE expires_at < NOW()' );
    }
}
