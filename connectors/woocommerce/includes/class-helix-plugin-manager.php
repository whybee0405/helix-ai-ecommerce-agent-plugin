<?php
defined( 'ABSPATH' ) || exit;

class Helix_Plugin_Manager {

    /* ── Init ──────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'wp_ajax_helix_list_plugins',   [ __CLASS__, 'ajax_list' ] );
        add_action( 'wp_ajax_helix_update_plugin',  [ __CLASS__, 'ajax_update' ] );
        add_action( 'wp_ajax_helix_toggle_plugin',  [ __CLASS__, 'ajax_toggle' ] );
    }

    /* ── AJAX: list ─────────────────────────────────────────────────────── */

    public static function ajax_list(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        wp_send_json_success( self::get_plugins_list() );
    }

    public static function get_plugins_list(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active      = (array) get_option( 'active_plugins', [] );
        $updates     = get_site_transient( 'update_plugins' );

        $list = [];
        foreach ( $all_plugins as $file => $data ) {
            $has_update = false;
            $new_version = '';
            if ( isset( $updates->response[ $file ] ) ) {
                $has_update  = true;
                $new_version = $updates->response[ $file ]->new_version ?? '';
            }

            $list[] = [
                'file'            => $file,
                'name'            => $data['Name'],
                'version'         => $data['Version'],
                'author'          => wp_strip_all_tags( $data['Author'] ?? '' ),
                'description'     => wp_strip_all_tags( $data['Description'] ?? '' ),
                'active'          => in_array( $file, $active, true ),
                'update_available' => $has_update,
                'new_version'     => $new_version,
            ];
        }

        usort( $list, fn( $a, $b ) => $b['update_available'] - $a['update_available'] );

        return [
            'plugins'       => $list,
            'total'         => count( $list ),
            'updates_count' => count( array_filter( $list, fn( $p ) => $p['update_available'] ) ),
        ];
    }

    /* ── AJAX: update ───────────────────────────────────────────────────── */

    public static function ajax_update(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $plugin_file = sanitize_text_field( $_POST['plugin_file'] ?? '' );
        if ( ! $plugin_file ) {
            wp_send_json_error( 'No plugin specified', 400 );
        }

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all = get_plugins();
        if ( ! isset( $all[ $plugin_file ] ) ) {
            wp_send_json_error( 'Plugin not found', 404 );
        }

        $old_version = $all[ $plugin_file ]['Version'];

        // Snapshot the old version.
        $snap_id = Helix_Snapshot::save( 'plugin_update_' . sanitize_key( $plugin_file ), [
            'plugin_file' => $plugin_file,
            'old_version' => $old_version,
            'plugin_name' => $all[ $plugin_file ]['Name'],
        ] );

        // Run the update.
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';

        wp_update_plugins();

        $skin     = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );
        $result   = $upgrader->upgrade( $plugin_file );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        // Get new version.
        $all_fresh   = get_plugins();
        $new_version = $all_fresh[ $plugin_file ]['Version'] ?? $old_version;

        Helix_Action_Log::record( [
            'action'      => 'plugin_update',
            'dry_run'     => false,
            'plugin'      => $plugin_file,
            'old_version' => $old_version,
            'new_version' => $new_version,
            'snapshot_id' => $snap_id,
        ] );

        wp_send_json_success( [
            'plugin_file' => $plugin_file,
            'old_version' => $old_version,
            'new_version' => $new_version,
            'snapshot_id' => $snap_id,
        ] );
    }

    /* ── AJAX: toggle ───────────────────────────────────────────────────── */

    public static function ajax_toggle(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'activate_plugins' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $plugin_file = sanitize_text_field( $_POST['plugin_file'] ?? '' );
        $activate    = filter_var( $_POST['activate'] ?? false, FILTER_VALIDATE_BOOLEAN );

        if ( ! $plugin_file ) {
            wp_send_json_error( 'No plugin specified', 400 );
        }

        if ( ! function_exists( 'activate_plugin' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active     = (array) get_option( 'active_plugins', [] );
        $was_active = in_array( $plugin_file, $active, true );

        // Snapshot state.
        $snap_id = Helix_Snapshot::save( 'plugin_toggle_' . sanitize_key( $plugin_file ), [
            'plugin_file' => $plugin_file,
            'was_active'  => $was_active,
        ] );

        if ( $activate ) {
            $result = activate_plugin( $plugin_file );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( $result->get_error_message() );
            }
        } else {
            deactivate_plugins( $plugin_file );
        }

        Helix_Action_Log::record( [
            'action'      => $activate ? 'plugin_activate' : 'plugin_deactivate',
            'dry_run'     => false,
            'plugin'      => $plugin_file,
            'snapshot_id' => $snap_id,
        ] );

        wp_send_json_success( [
            'plugin_file' => $plugin_file,
            'active'      => $activate,
            'snapshot_id' => $snap_id,
        ] );
    }
}
