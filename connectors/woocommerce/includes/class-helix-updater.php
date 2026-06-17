<?php
defined( 'ABSPATH' ) || exit;

class Helix_Updater {

    const VERSION      = HELIX_CONNECTOR_VERSION;
    const MANIFEST_URL = 'https://helix.cloudia.co.za/v1/plugin/manifest';
    const PLUGIN_SLUG  = 'helix-connector/helix-connector.php';

    public static function init(): void {
        add_filter( 'pre_set_site_transient_update_plugins', [ self::class, 'check_update' ] );
        add_filter( 'plugins_api', [ self::class, 'plugin_info' ], 20, 3 );
        add_action( 'upgrader_process_complete', [ self::class, 'after_update' ], 10, 2 );
        // Always fetch a fresh manifest when an admin opens the Updates screen.
        add_action( 'load-update-core.php', function () {
            delete_transient( 'helix_plugin_manifest' );
        } );
    }

    private static function get_manifest(): array|false {
        $transient = get_transient( 'helix_plugin_manifest' );
        if ( $transient !== false ) return $transient;

        $response = wp_remote_get( self::MANIFEST_URL, [ 'timeout' => 10, 'sslverify' => true ] );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['version'] ) ) return false;

        set_transient( 'helix_plugin_manifest', $data, 5 * MINUTE_IN_SECONDS );
        return $data;
    }

    public static function check_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;

        $manifest = self::get_manifest();
        if ( ! $manifest ) return $transient;

        if ( version_compare( $manifest['version'], self::VERSION, '>' ) ) {
            $transient->response[ self::PLUGIN_SLUG ] = (object) [
                'slug'        => 'helix-connector',
                'plugin'      => self::PLUGIN_SLUG,
                'new_version' => $manifest['version'],
                'url'         => 'https://helix.cloudia.co.za',
                'package'     => $manifest['download_url'],
            ];
        }

        return $transient;
    }

    public static function plugin_info( $result, string $action, $args ) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( ! isset( $args->slug ) || $args->slug !== 'helix-connector' ) return $result;

        $manifest = self::get_manifest();
        if ( ! $manifest ) return $result;

        return (object) [
            'name'          => $manifest['name'],
            'slug'          => 'helix-connector',
            'version'       => $manifest['version'],
            'download_link' => $manifest['download_url'],
            'tested'        => $manifest['tested'] ?? '6.5',
            'requires'      => $manifest['requires'] ?? '7.0',
            'last_updated'  => $manifest['last_updated'] ?? '',
            'sections'      => [ 'description' => 'Helix AI e-commerce intelligence connector for WooCommerce.' ],
        ];
    }

    public static function after_update( $upgrader, array $hook_extra ): void {
        if ( isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === self::PLUGIN_SLUG ) {
            delete_transient( 'helix_plugin_manifest' );
        }
    }
}
