<?php
defined( 'ABSPATH' ) || exit;

class Helix_Branding {
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'add_menu' ], 20 );
        add_action( 'admin_post_helix_save_branding', [ self::class, 'save_branding' ] );
        add_action( 'admin_post_helix_apply_preset', [ self::class, 'apply_preset' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
    }

    public static function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            'Helix Branding',
            'Helix Branding',
            'manage_woocommerce',
            'helix-branding',
            [ self::class, 'render_page' ]
        );
    }

    public static function enqueue_assets( $hook ): void {
        if ( $hook !== 'woocommerce_page_helix-branding' ) return;
        wp_enqueue_media();
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
    }

    private static function ensure_admin_secret(): bool {
        if ( get_option( 'helix_admin_secret' ) ) return true;
        $client = new Helix_API_Client( get_option( 'helix_api_url', '' ), get_option( 'helix_public_key', '' ) );
        $res = $client->bootstrap_admin_secret();
        if ( is_wp_error( $res ) || empty( $res['admin_secret'] ) ) return false;
        update_option( 'helix_admin_secret', $res['admin_secret'] );
        return true;
    }

    public static function save_branding(): void {
        check_admin_referer( 'helix_save_branding' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        if ( ! self::ensure_admin_secret() ) {
            wp_safe_redirect( admin_url( 'admin.php?page=helix-branding&error=no_secret' ) );
            exit;
        }

        $patch = [];
        foreach ( [
            'brand_name', 'brand_short_name', 'tagline',
            'headline_text', 'search_placeholder', 'chat_placeholder',
            'footer_cta_label', 'greeting', 'tone',
        ] as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                $patch[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
            }
        }
        if ( isset( $_POST['avatar_url'] ) ) {
            $patch['avatar_url'] = esc_url_raw( wp_unslash( $_POST['avatar_url'] ) );
        }
        foreach ( [ 'primary_color', 'secondary_color', 'accent_color' ] as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                $v = sanitize_hex_color( wp_unslash( $_POST[ $field ] ) );
                if ( $v ) $patch[ $field ] = $v;
            }
        }
        if ( isset( $_POST['locale'] ) )   $patch['locale']   = sanitize_text_field( wp_unslash( $_POST['locale'] ) );
        if ( isset( $_POST['currency'] ) ) $patch['currency'] = strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ) ) );
        if ( isset( $_POST['custom_css'] ) ) {
            $patch['custom_css'] = wp_strip_all_tags( wp_unslash( $_POST['custom_css'] ) );
        }

        if ( isset( $_POST['chip_label'] ) && is_array( $_POST['chip_label'] ) ) {
            $chips = [];
            foreach ( $_POST['chip_label'] as $i => $label ) {
                $label = trim( sanitize_text_field( wp_unslash( $label ) ) );
                $query = isset( $_POST['chip_query'][ $i ] )
                    ? trim( sanitize_text_field( wp_unslash( $_POST['chip_query'][ $i ] ) ) )
                    : '';
                if ( $label === '' ) continue;
                $chips[] = [
                    'label' => $label,
                    'query' => $query !== '' ? $query : $label,
                ];
                if ( count( $chips ) >= 8 ) break;
            }
            $patch['suggestion_chips'] = $chips;
        }

        $client = new Helix_API_Client( get_option( 'helix_api_url', '' ), get_option( 'helix_public_key', '' ) );
        $result = $client->update_branding( $patch );

        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=helix-branding&error=' . urlencode( $result->get_error_message() ) ) );
            exit;
        }
        wp_safe_redirect( admin_url( 'admin.php?page=helix-branding&saved=1' ) );
        exit;
    }

    public static function apply_preset(): void {
        check_admin_referer( 'helix_apply_preset' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        if ( ! self::ensure_admin_secret() ) {
            wp_safe_redirect( admin_url( 'admin.php?page=helix-branding&error=no_secret' ) );
            exit;
        }

        $preset_id = isset( $_POST['preset_id'] ) ? sanitize_text_field( wp_unslash( $_POST['preset_id'] ) ) : 'general';

        $client = new Helix_API_Client( get_option( 'helix_api_url', '' ), get_option( 'helix_public_key', '' ) );
        $result = $client->apply_branding_preset( $preset_id );
        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=helix-branding&error=' . urlencode( $result->get_error_message() ) ) );
            exit;
        }
        wp_safe_redirect( admin_url( 'admin.php?page=helix-branding&preset=' . urlencode( $preset_id ) ) );
        exit;
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        if ( ! get_option( 'helix_tenant_id' ) ) {
            echo '<div class="wrap"><h1>Helix Branding</h1><div class="notice notice-warning"><p>Connect your store in the Helix settings first.</p></div></div>';
            return;
        }
        if ( ! self::ensure_admin_secret() ) {
            echo '<div class="wrap"><h1>Helix Branding</h1><div class="notice notice-error"><p>Could not bootstrap admin credentials. Check your Helix API URL and Provision Key.</p></div></div>';
            return;
        }

        $client = new Helix_API_Client( get_option( 'helix_api_url', '' ), get_option( 'helix_public_key', '' ) );

        $branding = $client->get_branding();
        if ( is_wp_error( $branding ) ) {
            echo '<div class="wrap"><h1>Helix Branding</h1><div class="notice notice-error"><p>Could not load branding: ' . esc_html( $branding->get_error_message() ) . '</p></div></div>';
            return;
        }

        $presets = $client->list_presets();
        if ( is_wp_error( $presets ) ) $presets = [];

        $chips = isset( $branding['suggestion_chips'] ) && is_array( $branding['suggestion_chips'] )
            ? $branding['suggestion_chips'] : [];
        if ( count( $chips ) < 4 ) {
            while ( count( $chips ) < 4 ) $chips[] = [ 'label' => '', 'query' => '' ];
        }
        ?>
        <div class="wrap">
            <h1>Helix Branding</h1>
            <p class="description">Configure how Helix appears on your storefront. All settings sync to your tenant on the Helix backend.</p>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>Branding saved.</p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['preset'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>Preset <strong><?php echo esc_html( $_GET['preset'] ); ?></strong> applied. Refresh your storefront to see changes.</p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['error'] ) ) : ?>
                <div class="notice notice-error is-dismissible"><p>Error: <?php echo esc_html( $_GET['error'] ); ?></p></div>
            <?php endif; ?>

            <h2>Industry preset</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:24px;">
                <?php wp_nonce_field( 'helix_apply_preset' ); ?>
                <input type="hidden" name="action" value="helix_apply_preset">
                <p>
                    <select name="preset_id">
                        <?php foreach ( $presets as $p ) : ?>
                            <option value="<?php echo esc_attr( $p['preset_id'] ); ?>">
                                <?php echo esc_html( $p['brand_name'] . ' — ' . $p['preset_id'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php submit_button( 'Apply preset (overwrites everything)', 'secondary', 'submit', false, [ 'onclick' => "return confirm('This will overwrite all current branding fields. Continue?');" ] ); ?>
                </p>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'helix_save_branding' ); ?>
                <input type="hidden" name="action" value="helix_save_branding">

                <h2>Identity</h2>
                <table class="form-table">
                    <tr><th>Brand name</th><td><input type="text" name="brand_name" value="<?php echo esc_attr( $branding['brand_name'] ?? '' ); ?>" class="regular-text" maxlength="60"></td></tr>
                    <tr><th>Short name</th><td><input type="text" name="brand_short_name" value="<?php echo esc_attr( $branding['brand_short_name'] ?? '' ); ?>" class="regular-text" maxlength="30"></td></tr>
                    <tr><th>Tagline</th><td><input type="text" name="tagline" value="<?php echo esc_attr( $branding['tagline'] ?? '' ); ?>" class="regular-text" maxlength="120"></td></tr>
                    <tr>
                        <th>Logo / Avatar</th>
                        <td>
                            <input type="url" name="avatar_url" id="helix_avatar_url" value="<?php echo esc_attr( $branding['avatar_url'] ?? '' ); ?>" class="regular-text" placeholder="https://...">
                            <button type="button" class="button" id="helix_pick_logo">Choose from media library</button>
                            <p class="description">Recommended: 96×96 square PNG/SVG. Leave blank to use the default rainbow gradient.</p>
                        </td>
                    </tr>
                </table>

                <h2>Colors</h2>
                <table class="form-table">
                    <tr><th>Primary</th><td><input type="text" name="primary_color" value="<?php echo esc_attr( $branding['primary_color'] ?? '#7C3AED' ); ?>" class="helix-color"></td></tr>
                    <tr><th>Secondary</th><td><input type="text" name="secondary_color" value="<?php echo esc_attr( $branding['secondary_color'] ?? '#4F46E5' ); ?>" class="helix-color"></td></tr>
                    <tr><th>Accent</th><td><input type="text" name="accent_color" value="<?php echo esc_attr( $branding['accent_color'] ?? '#C7B8F5' ); ?>" class="helix-color"></td></tr>
                </table>

                <h2>Copy</h2>
                <p class="description">Use <code>{{brand_name}}</code> to substitute the brand name dynamically.</p>
                <table class="form-table">
                    <tr><th>Headline (search bar)</th><td><input type="text" name="headline_text" value="<?php echo esc_attr( $branding['headline_text'] ?? '' ); ?>" class="large-text" maxlength="200"></td></tr>
                    <tr><th>Search placeholder</th><td><input type="text" name="search_placeholder" value="<?php echo esc_attr( $branding['search_placeholder'] ?? '' ); ?>" class="regular-text" maxlength="120"></td></tr>
                    <tr><th>Chat placeholder</th><td><input type="text" name="chat_placeholder" value="<?php echo esc_attr( $branding['chat_placeholder'] ?? '' ); ?>" class="regular-text" maxlength="120"></td></tr>
                    <tr><th>"Open in chat" label</th><td><input type="text" name="footer_cta_label" value="<?php echo esc_attr( $branding['footer_cta_label'] ?? '' ); ?>" class="regular-text" maxlength="40"></td></tr>
                    <tr><th>Greeting</th><td><textarea name="greeting" rows="3" class="large-text" maxlength="400"><?php echo esc_textarea( $branding['greeting'] ?? '' ); ?></textarea></td></tr>
                </table>

                <h2>Suggestion chips</h2>
                <p class="description">Tappable quick-search buttons that appear below the search bar. Up to 8.</p>
                <table class="form-table" id="helix-chips-table">
                    <thead><tr><th style="width:30%">Label</th><th>Query (what gets sent to the AI)</th></tr></thead>
                    <tbody>
                        <?php foreach ( $chips as $i => $c ) : ?>
                            <tr>
                                <td><input type="text" name="chip_label[]" value="<?php echo esc_attr( $c['label'] ?? '' ); ?>" class="regular-text" maxlength="40"></td>
                                <td><input type="text" name="chip_query[]" value="<?php echo esc_attr( $c['query'] ?? '' ); ?>" class="large-text" maxlength="200"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button" id="helix-add-chip">+ Add chip</button></p>

                <h2>AI tone</h2>
                <table class="form-table">
                    <tr><th>Tone description</th><td><textarea name="tone" rows="2" class="large-text" maxlength="400"><?php echo esc_textarea( $branding['tone'] ?? '' ); ?></textarea><p class="description">Drives how the AI writes. e.g. "warm, expert, reassuring".</p></td></tr>
                    <tr><th>Locale</th><td><input type="text" name="locale" value="<?php echo esc_attr( $branding['locale'] ?? 'en-ZA' ); ?>" class="small-text" maxlength="12"></td></tr>
                    <tr><th>Currency</th><td><input type="text" name="currency" value="<?php echo esc_attr( $branding['currency'] ?? 'ZAR' ); ?>" class="small-text" maxlength="3"></td></tr>
                </table>

                <h2>Custom CSS</h2>
                <p class="description">Scoped to widget selectors (<code>#hx-*</code>, <code>.hx-*</code>). Disallowed: <code>@import</code>, <code>position:fixed</code>, <code>expression()</code>, <code>javascript:</code>.</p>
                <textarea name="custom_css" rows="10" class="large-text code" maxlength="8000" placeholder="#hx-sb-inner { ... }"><?php echo esc_textarea( $branding['custom_css'] ?? '' ); ?></textarea>

                <?php submit_button( 'Save branding' ); ?>
            </form>
        </div>

        <script>
        jQuery(function ($) {
            $('.helix-color').wpColorPicker();

            $('#helix_pick_logo').on('click', function (e) {
                e.preventDefault();
                var frame = wp.media({
                    title: 'Choose logo',
                    button: { text: 'Use this image' },
                    multiple: false,
                });
                frame.on('select', function () {
                    var attach = frame.state().get('selection').first().toJSON();
                    $('#helix_avatar_url').val(attach.url);
                });
                frame.open();
            });

            $('#helix-add-chip').on('click', function () {
                var rows = $('#helix-chips-table tbody tr').length;
                if (rows >= 8) { alert('Maximum 8 chips.'); return; }
                $('#helix-chips-table tbody').append(
                    '<tr><td><input type="text" name="chip_label[]" class="regular-text" maxlength="40"></td>' +
                    '<td><input type="text" name="chip_query[]" class="large-text" maxlength="200"></td></tr>'
                );
            });
        });
        </script>
        <?php
    }
}
