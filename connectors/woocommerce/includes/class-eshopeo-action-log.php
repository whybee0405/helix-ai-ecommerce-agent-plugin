<?php
defined( 'ABSPATH' ) || exit;

class Eshopeo_Action_Log {
    const OPTION      = 'eshopeo_action_log';
    const MAX_ENTRIES = 200;

    public static function record( array $entry ): void {
        $log = get_option( self::OPTION, [] );
        array_unshift(
            $log,
            array_merge(
                $entry,
                [
                    'timestamp' => current_time( 'mysql' ),
                    'user'      => wp_get_current_user()->user_login,
                ]
            )
        );
        $log = array_slice( $log, 0, self::MAX_ENTRIES );
        update_option( self::OPTION, $log, false );
    }

    public static function get( int $limit = 50 ): array {
        return array_slice( get_option( self::OPTION, [] ), 0, $limit );
    }

    public static function clear(): void {
        delete_option( self::OPTION );
    }
}
