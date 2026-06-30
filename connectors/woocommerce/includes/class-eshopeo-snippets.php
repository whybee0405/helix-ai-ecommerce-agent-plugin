<?php
defined( 'ABSPATH' ) || exit;

/**
 * Eshopeo_Snippets — lightweight code snippets manager.
 *
 * Stores custom PHP snippets in {prefix}eshopeo_snippets, loads enabled ones
 * on init priority 99, and exposes AJAX CRUD.
 */
class Eshopeo_Snippets {

    const TABLE = 'eshopeo_snippets';

    /* ── Install ───────────────────────────────────────────────────────────── */

    public static function install_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(200) NOT NULL DEFAULT '',
            description TEXT,
            code        LONGTEXT NOT NULL,
            hook        VARCHAR(100) NOT NULL DEFAULT 'init',
            enabled     TINYINT(1) NOT NULL DEFAULT 1,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY enabled (enabled),
            KEY hook (hook)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /* ── Init ──────────────────────────────────────────────────────────────── */

    public static function init(): void {
        // Load enabled snippets at priority 99 so other plugins have registered.
        add_action( 'init', [ __CLASS__, 'load_snippets' ], 99 );

        add_action( 'wp_ajax_eshopeo_snippets_list',   [ __CLASS__, 'ajax_list' ] );
        add_action( 'wp_ajax_eshopeo_snippets_save',   [ __CLASS__, 'ajax_save' ] );
        add_action( 'wp_ajax_eshopeo_snippets_delete', [ __CLASS__, 'ajax_delete' ] );
        add_action( 'wp_ajax_eshopeo_snippets_toggle', [ __CLASS__, 'ajax_toggle' ] );
    }

    /* ── Load snippets ─────────────────────────────────────────────────────── */

    public static function load_snippets(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Table may not exist yet on fresh install before activation.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( "SELECT id, hook, code FROM {$table} WHERE enabled = 1", ARRAY_A );
        if ( ! $rows ) {
            return;
        }

        foreach ( $rows as $row ) {
            $snippet_id   = (int) $row['id'];
            $snippet_code = $row['code'];
            $hook         = sanitize_key( $row['hook'] ) ?: 'init';

            add_action( $hook, static function () use ( $snippet_id, $snippet_code ) {
                try {
                    // phpcs:ignore Squiz.PHP.Eval.Discouraged
                    eval( $snippet_code ); // @phpstan-ignore-line
                } catch ( \Throwable $e ) {
                    // Disable the broken snippet so it doesn't loop.
                    global $wpdb;
                    $wpdb->update(
                        $wpdb->prefix . Eshopeo_Snippets::TABLE,
                        [ 'enabled' => 0 ],
                        [ 'id' => $snippet_id ]
                    );
                    // Silently disable; logging errors here could cause recursion.
                }
            } );
        }
    }

    /* ── AJAX: list ────────────────────────────────────────────────────────── */

    public static function ajax_list(): void {
        check_ajax_referer( 'eshopeo_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows  = $wpdb->get_results( "SELECT id, name, description, hook, enabled, created_at, updated_at FROM {$table} ORDER BY id DESC", ARRAY_A );

        wp_send_json_success( [
            'snippets' => $rows ?: [],
            'total'    => count( $rows ?: [] ),
        ] );
    }

    /* ── AJAX: save (insert or update) ───────────────────────────────────── */

    public static function ajax_save(): void {
        check_ajax_referer( 'eshopeo_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $id          = (int) ( $_POST['id'] ?? 0 );
        $name        = sanitize_text_field( $_POST['name'] ?? '' );
        $description = sanitize_textarea_field( $_POST['description'] ?? '' );
        $code        = wp_unslash( $_POST['code'] ?? '' );
        $hook        = sanitize_key( $_POST['hook'] ?? 'init' ) ?: 'init';
        $enabled     = (int) filter_var( $_POST['enabled'] ?? 1, FILTER_VALIDATE_BOOLEAN );

        if ( empty( $name ) || empty( $code ) ) {
            wp_send_json_error( 'Name and code are required', 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $data = [
            'name'        => $name,
            'description' => $description,
            'code'        => $code,
            'hook'        => $hook,
            'enabled'     => $enabled,
        ];

        if ( $id > 0 ) {
            $wpdb->update( $table, $data, [ 'id' => $id ] );
            wp_send_json_success( [ 'id' => $id, 'updated' => true ] );
        } else {
            $wpdb->insert( $table, $data );
            wp_send_json_success( [ 'id' => (int) $wpdb->insert_id, 'created' => true ] );
        }
    }

    /* ── AJAX: delete ─────────────────────────────────────────────────────── */

    public static function ajax_delete(): void {
        check_ajax_referer( 'eshopeo_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $id = (int) ( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Missing ID', 400 );
        }

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . self::TABLE, [ 'id' => $id ] );

        wp_send_json_success( [ 'deleted' => true ] );
    }

    /* ── AJAX: toggle ─────────────────────────────────────────────────────── */

    public static function ajax_toggle(): void {
        check_ajax_referer( 'eshopeo_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $id      = (int) ( $_POST['id'] ?? 0 );
        $enabled = (int) filter_var( $_POST['enabled'] ?? 0, FILTER_VALIDATE_BOOLEAN );

        if ( ! $id ) {
            wp_send_json_error( 'Missing ID', 400 );
        }

        global $wpdb;
        $wpdb->update( $wpdb->prefix . self::TABLE, [ 'enabled' => $enabled ], [ 'id' => $id ] );

        wp_send_json_success( [ 'id' => $id, 'enabled' => $enabled ] );
    }
}
