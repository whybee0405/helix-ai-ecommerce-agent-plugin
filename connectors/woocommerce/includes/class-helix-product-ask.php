<?php
defined( 'ABSPATH' ) || exit;

/**
 * Helix_Product_Ask
 *
 * Adds an "Ask AI" button on WooCommerce product pages (after the Add to Cart
 * button) and registers the [helix_ask_ai product_id="123"] shortcode.
 *
 * Clicking the button opens the Helix chat widget pre-seeded with the product
 * name so the customer can immediately ask about that product.
 */
class Helix_Product_Ask {

    public static function init(): void {
        add_action( 'woocommerce_after_add_to_cart_button', [ self::class, 'render_ask_button' ] );
        add_shortcode( 'helix_ask_ai', [ self::class, 'shortcode' ] );
    }

    /**
     * Render the "Ask AI" button on the single product page.
     */
    public static function render_ask_button(): void {
        if ( ! get_option( 'helix_widget_enabled', 0 ) ) return;
        if ( ! class_exists( 'WooCommerce' ) ) return;

        global $product;
        if ( ! $product instanceof WC_Product ) return;

        $product_name = esc_js( $product->get_name() );
        echo self::_button_html( $product_name );
    }

    /**
     * [helix_ask_ai product_id="123"] shortcode.
     * Falls back to the global $product when product_id is omitted.
     */
    public static function shortcode( array $atts ): string {
        if ( ! get_option( 'helix_widget_enabled', 0 ) ) return '';
        if ( ! class_exists( 'WooCommerce' ) ) return '';

        $atts = shortcode_atts( [ 'product_id' => '' ], $atts, 'helix_ask_ai' );

        global $product;
        $wc_product = null;
        if ( ! empty( $atts['product_id'] ) ) {
            $wc_product = wc_get_product( (int) $atts['product_id'] );
        }
        if ( ! $wc_product instanceof WC_Product ) {
            $wc_product = ( $product instanceof WC_Product ) ? $product : null;
        }

        $product_name = $wc_product instanceof WC_Product ? $wc_product->get_name() : '';
        return self::_button_html( esc_js( $product_name ) );
    }

    /**
     * Build the button HTML + inline script.
     *
     * @param string $product_name Already js-escaped product name.
     */
    private static function _button_html( string $product_name ): string {
        ob_start();
        ?>
        <button
            type="button"
            class="helix-ask-ai-btn button alt"
            style="margin-left:8px;background:linear-gradient(135deg,#7C3AED,#4F46E5);color:#fff;border:none;cursor:pointer;"
            onclick="(function(){
                var p = document.getElementById('hx-panel');
                var btn = document.getElementById('hx-btn');
                if (!p) { console.warn('[Helix] Widget not loaded'); return; }
                /* Open the panel */
                if (!p.classList.contains('hx-open')) {
                    p.classList.add('hx-open');
                    if (btn) btn.setAttribute('aria-expanded','true');
                }
                /* Pre-seed the input with the product name */
                var inp = document.getElementById('hx-inp');
                if (inp && inp.value === '') {
                    inp.value = 'Tell me more about <?php echo $product_name; ?>';
                    inp.dispatchEvent(new Event('input'));
                    inp.focus();
                }
            })()"
        >
            Ask AI
        </button>
        <?php
        return ob_get_clean();
    }
}
