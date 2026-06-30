<?php
defined( 'ABSPATH' ) || exit;

/**
 * One-time WP options migration: renames all helix_* options to eshopeo_*.
 * Triggered as a plugin upgrade hook when the plugin version bumps.
 */
class Eshopeo_Migrator_V1 {

    /**
     * Run the migration exactly once.
     *
     * Checks the eshopeo_migration_v1_done flag before doing anything,
     * so it is safe to register this on every plugin load.
     */
    public static function run_migration() {
        if ( get_option( 'eshopeo_migration_v1_done' ) ) {
            return;
        }

        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'helix_%'",
            ARRAY_A
        );

        foreach ( $rows as $row ) {
            $old = $row['option_name'];
            $new = 'eshopeo_' . substr( $old, strlen( 'helix_' ) );

            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->options} SET option_name = %s WHERE option_name = %s",
                    $new,
                    $old
                )
            );
        }

        update_option( 'eshopeo_migration_v1_done', true );
    }
}
