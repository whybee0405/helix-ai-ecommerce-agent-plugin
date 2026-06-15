<?php
defined( 'ABSPATH' ) || exit;

class Helix_Widget {
    public static function init(): void {
        add_action( 'wp_footer', [ self::class, 'inject' ] );
    }

    public static function inject(): void {
        if ( is_admin() ) return;
        if ( ! get_option( 'helix_widget_enabled', 0 ) ) return;

        $public_key = get_option( 'helix_public_key', '' );
        $api_url    = rtrim( get_option( 'helix_api_url', '' ), '/' );
        if ( ! $public_key || ! $api_url ) return;

        $wa_enabled = get_option( 'helix_wa_enabled', 0 ) && get_option( 'helix_wa_number', '' );
        $wa_number  = get_option( 'helix_wa_number', '' );
        $wa_message = get_option( 'helix_wa_message', "Hi! I'd like some skincare advice." );

        $config = wp_json_encode( [
            'wa'    => (bool) $wa_enabled,
            'waNum' => $wa_number,
            'waMsg' => $wa_message,
        ] );
        ?>
        <script>window.helixConfig = <?php echo $config; ?>;</script>
        <script src="<?php echo esc_url( $api_url . '/v1/widget/embed.js' ); ?>?key=<?php echo esc_attr( $public_key ); ?>" defer></script>
        <?php
    }
}
