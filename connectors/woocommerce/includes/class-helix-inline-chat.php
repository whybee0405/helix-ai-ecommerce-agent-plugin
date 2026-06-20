<?php
defined( 'ABSPATH' ) || exit;

/**
 * Helix_Inline_Chat
 *
 * Registers the [helix_chat] shortcode which renders a full inline chat UI
 * instead of the floating button widget.  The embed JS detects
 * .helix-inline-chat containers and mounts the chat UI inside each one.
 */
class Helix_Inline_Chat {

    public static function init(): void {
        add_shortcode( 'helix_chat', [ self::class, 'shortcode' ] );
    }

    public static function shortcode( array $atts ): string {
        if ( ! get_option( 'helix_widget_enabled', 0 ) ) return '';

        $public_key = get_option( 'helix_public_key', '' );
        if ( ! $public_key ) return '';

        $atts = shortcode_atts(
            [
                'height' => '520',
                'class'  => '',
            ],
            $atts,
            'helix_chat'
        );

        $height    = (int) $atts['height'];
        $extra_cls = sanitize_html_class( $atts['class'] );

        return sprintf(
            '<div class="helix-inline-chat %s" data-mode="inline" data-tenant="%s" style="height:%dpx;min-height:300px;"></div>',
            esc_attr( $extra_cls ),
            esc_attr( $public_key ),
            $height
        );
    }
}
