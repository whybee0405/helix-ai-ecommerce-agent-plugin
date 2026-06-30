<?php
defined( 'ABSPATH' ) || exit;

class Eshopeo_Branding {
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'add_menu' ], 20 );
        add_action( 'admin_post_eshopeo_save_branding', [ self::class, 'save_branding' ] );
        add_action( 'admin_post_eshopeo_apply_preset', [ self::class, 'apply_preset' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
    }

    public static function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            'eShopeo Branding',
            'eShopeo Branding',
            'manage_woocommerce',
            'eshopeo-branding',
            [ self::class, 'render_page' ]
        );
    }

    public static function enqueue_assets( $hook ): void {
        if ( $hook !== 'woocommerce_page_eshopeo-branding' ) return;
        wp_enqueue_media();
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
    }

    private static function ensure_admin_secret(): bool {
        if ( get_option( 'eshopeo_admin_secret' ) ) return true;
        $client = new Eshopeo_API_Client( get_option( 'eshopeo_api_url', '' ), get_option( 'eshopeo_public_key', '' ) );
        $res = $client->bootstrap_admin_secret();
        if ( is_wp_error( $res ) || empty( $res['admin_secret'] ) ) return false;
        update_option( 'eshopeo_admin_secret', $res['admin_secret'] );
        return true;
    }

    public static function save_branding(): void {
        check_admin_referer( 'eshopeo_save_branding' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        if ( ! self::ensure_admin_secret() ) {
            wp_safe_redirect( admin_url( 'admin.php?page=eshopeo-branding&error=no_secret' ) );
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

        $client = new Eshopeo_API_Client( get_option( 'eshopeo_api_url', '' ), get_option( 'eshopeo_public_key', '' ) );
        $result = $client->update_branding( $patch );

        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=eshopeo-branding&error=' . urlencode( $result->get_error_message() ) ) );
            exit;
        }
        wp_safe_redirect( admin_url( 'admin.php?page=eshopeo-branding&saved=1' ) );
        exit;
    }

    public static function apply_preset(): void {
        check_admin_referer( 'eshopeo_apply_preset' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        if ( ! self::ensure_admin_secret() ) {
            wp_safe_redirect( admin_url( 'admin.php?page=eshopeo-branding&error=no_secret' ) );
            exit;
        }

        $preset_id = isset( $_POST['preset_id'] ) ? sanitize_text_field( wp_unslash( $_POST['preset_id'] ) ) : 'general';

        $client = new Eshopeo_API_Client( get_option( 'eshopeo_api_url', '' ), get_option( 'eshopeo_public_key', '' ) );
        $result = $client->apply_branding_preset( $preset_id );
        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=eshopeo-branding&error=' . urlencode( $result->get_error_message() ) ) );
            exit;
        }
        wp_safe_redirect( admin_url( 'admin.php?page=eshopeo-branding&preset=' . urlencode( $preset_id ) ) );
        exit;
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );
        ?>
        <style>
        /* ── eShopeo Branding Page ── */
        .eshopeo-branding-page *,
        .eshopeo-branding-page *::before,
        .eshopeo-branding-page *::after { box-sizing: border-box; }

        .eshopeo-branding-page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px 20px 60px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #374151;
            background: #f9fafb;
        }

        /* ── Header ── */
        .hbp-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 28px;
        }
        .hbp-header h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
            color: #1e1b4b;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .hbp-badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: #fff;
            letter-spacing: .04em;
        }

        /* ── Notices ── */
        .hbp-notice {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            line-height: 1.5;
        }
        .hbp-notice.success { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; }
        .hbp-notice.error   { background: #fef2f2; border: 1px solid #fca5a5; color: #7f1d1d; }
        .hbp-notice.warning { background: #fffbeb; border: 1px solid #fcd34d; color: #78350f; }

        /* ── Section cards ── */
        .hbp-section {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .hbp-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            border-bottom: 1px solid #e5e7eb;
            background: #fafafa;
        }
        .hbp-section-head h2 {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            color: #1e1b4b;
            border-left: 3px solid #6366f1;
            padding-left: 10px;
        }
        .hbp-section-head p {
            margin: 0;
            font-size: 12px;
            color: #6b7280;
        }
        .hbp-section-body { padding: 24px; }

        /* ── Form field rows ── */
        .hbp-fields {
            display: grid;
            grid-template-columns: 200px 1fr;
            gap: 0;
        }
        .hbp-field-row { display: contents; }
        .hbp-field-row > .hbp-label,
        .hbp-field-row > .hbp-control {
            padding: 14px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .hbp-field-row:last-child > * { border-bottom: none; }
        .hbp-label {
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            padding-right: 20px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .hbp-label-hint {
            font-size: 11px;
            font-weight: 400;
            color: #9ca3af;
            margin-top: 2px;
        }
        .hbp-control {
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        /* ── Inputs ── */
        .hbp-input,
        .hbp-select,
        .hbp-textarea {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 13px;
            color: #374151;
            background: #fff;
            width: 100%;
            max-width: 480px;
            transition: border-color .15s, box-shadow .15s;
            font-family: inherit;
        }
        .hbp-input:focus,
        .hbp-select:focus,
        .hbp-textarea:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99,102,241,.15);
        }
        .hbp-textarea { resize: vertical; min-height: 80px; }
        .hbp-input.wide,
        .hbp-textarea.wide { max-width: 600px; }
        .hbp-input.narrow,
        .hbp-select.narrow { max-width: 160px; }
        .hbp-input.code { font-family: 'SF Mono', 'Fira Mono', monospace; font-size: 12px; }
        .hbp-desc {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
            line-height: 1.5;
        }
        .hbp-desc code {
            font-size: 11px;
            background: #f3f4f6;
            padding: 1px 4px;
            border-radius: 3px;
            font-family: 'SF Mono', 'Fira Mono', monospace;
        }

        /* ── Color swatch row ── */
        .hbp-color-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .hbp-color-preview {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
            flex-shrink: 0;
        }

        /* ── Logo preview ── */
        .hbp-logo-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .hbp-logo-preview {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            object-fit: cover;
            display: none;
            background: #f3f4f6;
        }
        .hbp-logo-preview.has-image { display: block; }

        /* ── Buttons ── */
        .hbp-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            min-height: 36px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: background .15s;
            font-family: inherit;
        }
        .hbp-btn-primary   { background: #6366f1; color: #fff; }
        .hbp-btn-primary:hover { background: #4f46e5; color: #fff; }
        .hbp-btn-secondary { background: #fff; color: #374151; border: 1px solid #d1d5db; }
        .hbp-btn-secondary:hover { background: #f9fafb; border-color: #9ca3af; }
        .hbp-btn-danger    { background: #ef4444; color: #fff; }
        .hbp-btn-danger:hover { background: #dc2626; color: #fff; }

        /* ── Form actions bar ── */
        .hbp-form-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 24px;
            border-top: 1px solid #e5e7eb;
            background: #fafafa;
        }

        /* ── Preset section ── */
        .hbp-preset-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .hbp-preset-warning {
            font-size: 12px;
            color: #6b7280;
        }

        /* ── Chips table ── */
        .hbp-chips-table {
            width: 100%;
            border-collapse: collapse;
        }
        .hbp-chips-table thead th {
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .05em;
            padding: 0 0 10px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
        }
        .hbp-chips-table thead th:first-child { width: 35%; }
        .hbp-chips-table tbody td { padding: 8px 0; border-bottom: 1px solid #f3f4f6; }
        .hbp-chips-table tbody tr:last-child td { border-bottom: none; }
        .hbp-chips-table tbody td:first-child { padding-right: 12px; }

        /* ── Custom CSS textarea ── */
        .hbp-code-area {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 12px;
            font-size: 12px;
            font-family: 'SF Mono', 'Fira Mono', 'Consolas', monospace;
            color: #374151;
            background: #fafafa;
            width: 100%;
            resize: vertical;
            min-height: 200px;
            transition: border-color .15s, box-shadow .15s;
        }
        .hbp-code-area:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99,102,241,.15);
        }

        /* ── 2-col for colors ── */
        .hbp-color-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }
        .hbp-color-item label {
            display: block;
            font-size: 12px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 6px;
        }

        /* ── Not-connected placeholder ── */
        .hbp-gated {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 48px 24px;
            text-align: center;
        }
        .hbp-gated h2 { font-size: 16px; color: #1e1b4b; margin-bottom: 8px; }
        .hbp-gated p  { font-size: 13px; color: #6b7280; margin-bottom: 20px; }

        /* ── Responsive ── */
        @media (max-width: 782px) {
            .eshopeo-branding-page { padding: 16px 12px 40px; }
            .hbp-header { flex-direction: column; align-items: flex-start; }
            .hbp-fields { grid-template-columns: 1fr; }
            .hbp-field-row > .hbp-label { padding-bottom: 4px; border-bottom: none; }
            .hbp-field-row > .hbp-control { padding-top: 0; }
            .hbp-input, .hbp-select, .hbp-textarea { max-width: 100%; }
            .hbp-color-grid { grid-template-columns: 1fr 1fr; }
            .hbp-section-body { padding: 16px; }
            .hbp-form-actions { flex-direction: column; align-items: stretch; }
            .hbp-btn { justify-content: center; }
        }
        @media (max-width: 480px) {
            .hbp-color-grid { grid-template-columns: 1fr; }
            .hbp-preset-row { flex-direction: column; align-items: stretch; }
        }
        </style>

        <div class="eshopeo-branding-page">

            <!-- Page header -->
            <div class="hbp-header">
                <h1>
                    <svg width="22" height="22" fill="none" viewBox="0 0 24 24" aria-hidden="true"><path stroke="#6366f1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                    eShopeo Branding
                    <span class="hbp-badge">eShopeo</span>
                </h1>
            </div>

        <?php if ( ! get_option( 'eshopeo_tenant_id' ) ) : ?>
            <div class="hbp-gated">
                <svg width="40" height="40" fill="none" viewBox="0 0 24 24" style="margin:0 auto 12px;display:block;"><path stroke="#d1d5db" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                <h2>Store not connected</h2>
                <p>Connect your store in the eShopeo settings first before configuring branding.</p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=eshopeo-connector' ) ); ?>" class="hbp-btn hbp-btn-primary">Go to Settings &rarr;</a>
            </div>
        </div>
        <?php return; endif; ?>

        <?php if ( ! self::ensure_admin_secret() ) : ?>
            <div class="hbp-notice error">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="#ef4444" stroke-width="2"/><path stroke="#ef4444" stroke-width="2" stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>
                Could not bootstrap admin credentials. Check your eShopeo API URL and Provision Key.
            </div>
        </div>
        <?php return; endif; ?>

        <?php
        $client = new Eshopeo_API_Client( get_option( 'eshopeo_api_url', '' ), get_option( 'eshopeo_public_key', '' ) );

        $branding = $client->get_branding();
        if ( is_wp_error( $branding ) ) : ?>
            <div class="hbp-notice error">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="#ef4444" stroke-width="2"/><path stroke="#ef4444" stroke-width="2" stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>
                Could not load branding: <?php echo esc_html( $branding->get_error_message() ); ?>
            </div>
        </div>
        <?php return; endif; ?>

        <?php
        $presets = $client->list_presets();
        if ( is_wp_error( $presets ) ) $presets = [];

        $chips = isset( $branding['suggestion_chips'] ) && is_array( $branding['suggestion_chips'] )
            ? $branding['suggestion_chips'] : [];
        if ( count( $chips ) < 4 ) {
            while ( count( $chips ) < 4 ) $chips[] = [ 'label' => '', 'query' => '' ];
        }
        ?>

            <!-- Notices -->
            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="hbp-notice success">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    Branding saved successfully. Refresh your storefront to see the changes.
                </div>
            <?php endif; ?>
            <?php if ( isset( $_GET['preset'] ) ) : ?>
                <div class="hbp-notice success">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    Preset <strong><?php echo esc_html( $_GET['preset'] ); ?></strong> applied. Refresh your storefront to see changes.
                </div>
            <?php endif; ?>
            <?php if ( isset( $_GET['error'] ) ) : ?>
                <div class="hbp-notice error">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="#ef4444" stroke-width="2"/><path stroke="#ef4444" stroke-width="2" stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>
                    Error: <?php echo esc_html( $_GET['error'] ); ?>
                </div>
            <?php endif; ?>

            <!-- Industry Preset -->
            <?php if ( ! empty( $presets ) ) : ?>
            <div class="hbp-section">
                <div class="hbp-section-head">
                    <div>
                        <h2>Industry Preset</h2>
                        <p style="margin-top:4px;">Apply a pre-configured branding template. This will overwrite all current branding fields.</p>
                    </div>
                </div>
                <div class="hbp-section-body">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'eshopeo_apply_preset' ); ?>
                        <input type="hidden" name="action" value="eshopeo_apply_preset">
                        <div class="hbp-preset-row">
                            <select name="preset_id" class="hbp-select">
                                <?php foreach ( $presets as $p ) : ?>
                                    <option value="<?php echo esc_attr( $p['preset_id'] ); ?>">
                                        <?php echo esc_html( $p['brand_name'] . ' — ' . $p['preset_id'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="hbp-btn hbp-btn-danger"
                                onclick="return confirm('This will overwrite all current branding fields. Continue?');">
                                <svg width="13" height="13" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                Apply Preset
                            </button>
                            <span class="hbp-preset-warning">Warning: overwrites all branding fields immediately</span>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Main branding form -->
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'eshopeo_save_branding' ); ?>
                <input type="hidden" name="action" value="eshopeo_save_branding">

                <!-- Identity -->
                <div class="hbp-section">
                    <div class="hbp-section-head">
                        <h2>Identity</h2>
                    </div>
                    <div class="hbp-section-body">
                        <div class="hbp-fields">
                            <div class="hbp-field-row">
                                <div class="hbp-label">Brand name<span class="hbp-label-hint">Max 60 chars</span></div>
                                <div class="hbp-control">
                                    <input type="text" name="brand_name" value="<?php echo esc_attr( $branding['brand_name'] ?? '' ); ?>" class="hbp-input" maxlength="60">
                                </div>
                            </div>
                            <div class="hbp-field-row">
                                <div class="hbp-label">Short name<span class="hbp-label-hint">Max 30 chars</span></div>
                                <div class="hbp-control">
                                    <input type="text" name="brand_short_name" value="<?php echo esc_attr( $branding['brand_short_name'] ?? '' ); ?>" class="hbp-input" maxlength="30">
                                </div>
                            </div>
                            <div class="hbp-field-row">
                                <div class="hbp-label">Tagline<span class="hbp-label-hint">Max 120 chars</span></div>
                                <div class="hbp-control">
                                    <input type="text" name="tagline" value="<?php echo esc_attr( $branding['tagline'] ?? '' ); ?>" class="hbp-input wide" maxlength="120">
                                </div>
                            </div>
                            <div class="hbp-field-row">
                                <div class="hbp-label">Logo / Avatar<span class="hbp-label-hint">96×96px PNG/SVG</span></div>
                                <div class="hbp-control">
                                    <div class="hbp-logo-row">
                                        <?php $av = $branding['avatar_url'] ?? ''; ?>
                                        <img id="eshopeo-logo-preview" class="hbp-logo-preview<?php echo $av ? ' has-image' : ''; ?>"
                                             src="<?php echo esc_url( $av ); ?>" alt="Logo preview">
                                        <div>
                                            <input type="url" name="avatar_url" id="eshopeo_avatar_url" value="<?php echo esc_attr( $av ); ?>" class="hbp-input" placeholder="https://..." style="margin-bottom:8px;">
                                            <button type="button" class="hbp-btn hbp-btn-secondary" id="eshopeo_pick_logo">
                                                <svg width="13" height="13" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                                Media library
                                            </button>
                                        </div>
                                    </div>
                                    <p class="hbp-desc">Leave blank to use the default eShopeo gradient avatar.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Colors -->
                <div class="hbp-section">
                    <div class="hbp-section-head">
                        <h2>Colors</h2>
                    </div>
                    <div class="hbp-section-body">
                        <div class="hbp-color-grid">
                            <div class="hbp-color-item">
                                <label for="hbp-primary">Primary</label>
                                <input type="text" name="primary_color" id="hbp-primary" value="<?php echo esc_attr( $branding['primary_color'] ?? '#7C3AED' ); ?>" class="eshopeo-color hbp-input narrow">
                            </div>
                            <div class="hbp-color-item">
                                <label for="hbp-secondary">Secondary</label>
                                <input type="text" name="secondary_color" id="hbp-secondary" value="<?php echo esc_attr( $branding['secondary_color'] ?? '#4F46E5' ); ?>" class="eshopeo-color hbp-input narrow">
                            </div>
                            <div class="hbp-color-item">
                                <label for="hbp-accent">Accent</label>
                                <input type="text" name="accent_color" id="hbp-accent" value="<?php echo esc_attr( $branding['accent_color'] ?? '#C7B8F5' ); ?>" class="eshopeo-color hbp-input narrow">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Copy / Text -->
                <div class="hbp-section">
                    <div class="hbp-section-head">
                        <div>
                            <h2>Copy &amp; Text</h2>
                            <p style="margin-top:4px;">Use <code>{{brand_name}}</code> to substitute the brand name dynamically.</p>
                        </div>
                    </div>
                    <div class="hbp-section-body">
                        <div class="hbp-fields">
                            <div class="hbp-field-row">
                                <div class="hbp-label">Headline<span class="hbp-label-hint">Search bar heading</span></div>
                                <div class="hbp-control">
                                    <input type="text" name="headline_text" value="<?php echo esc_attr( $branding['headline_text'] ?? '' ); ?>" class="hbp-input wide" maxlength="200">
                                </div>
                            </div>
                            <div class="hbp-field-row">
                                <div class="hbp-label">Search placeholder</div>
                                <div class="hbp-control">
                                    <input type="text" name="search_placeholder" value="<?php echo esc_attr( $branding['search_placeholder'] ?? '' ); ?>" class="hbp-input" maxlength="120">
                                </div>
                            </div>
                            <div class="hbp-field-row">
                                <div class="hbp-label">Chat placeholder</div>
                                <div class="hbp-control">
                                    <input type="text" name="chat_placeholder" value="<?php echo esc_attr( $branding['chat_placeholder'] ?? '' ); ?>" class="hbp-input" maxlength="120">
                                </div>
                            </div>
                            <div class="hbp-field-row">
                                <div class="hbp-label">&ldquo;Open in chat&rdquo; label</div>
                                <div class="hbp-control">
                                    <input type="text" name="footer_cta_label" value="<?php echo esc_attr( $branding['footer_cta_label'] ?? '' ); ?>" class="hbp-input" maxlength="40">
                                </div>
                            </div>
                            <div class="hbp-field-row">
                                <div class="hbp-label">Greeting<span class="hbp-label-hint">Max 400 chars</span></div>
                                <div class="hbp-control">
                                    <textarea name="greeting" rows="3" class="hbp-textarea wide" maxlength="400"><?php echo esc_textarea( $branding['greeting'] ?? '' ); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Suggestion Chips -->
                <div class="hbp-section">
                    <div class="hbp-section-head">
                        <div>
                            <h2>Suggestion Chips</h2>
                            <p style="margin-top:4px;">Tappable quick-search buttons below the search bar. Up to 8 chips.</p>
                        </div>
                        <button type="button" class="hbp-btn hbp-btn-secondary" id="eshopeo-add-chip">
                            <svg width="12" height="12" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
                            Add chip
                        </button>
                    </div>
                    <div class="hbp-section-body">
                        <table class="hbp-chips-table" id="eshopeo-chips-table">
                            <thead>
                                <tr>
                                    <th>Label (shown to user)</th>
                                    <th>Query (sent to AI)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $chips as $i => $c ) : ?>
                                    <tr>
                                        <td><input type="text" name="chip_label[]" value="<?php echo esc_attr( $c['label'] ?? '' ); ?>" class="hbp-input" maxlength="40" placeholder="e.g. Serums"></td>
                                        <td><input type="text" name="chip_query[]" value="<?php echo esc_attr( $c['query'] ?? '' ); ?>" class="hbp-input wide" maxlength="200" placeholder="e.g. Show me your best serums"></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- AI Tone & Locale -->
                <div class="hbp-section">
                    <div class="hbp-section-head">
                        <h2>AI Tone &amp; Locale</h2>
                    </div>
                    <div class="hbp-section-body">
                        <div class="hbp-fields">
                            <div class="hbp-field-row">
                                <div class="hbp-label">Tone<span class="hbp-label-hint">Drives AI writing style</span></div>
                                <div class="hbp-control">
                                    <textarea name="tone" rows="2" class="hbp-textarea wide" maxlength="400" placeholder='e.g. "warm, expert, reassuring"'><?php echo esc_textarea( $branding['tone'] ?? '' ); ?></textarea>
                                    <p class="hbp-desc">Drives how the AI writes. e.g. "warm, expert, reassuring".</p>
                                </div>
                            </div>
                            <div class="hbp-field-row">
                                <div class="hbp-label">Locale</div>
                                <div class="hbp-control">
                                    <input type="text" name="locale" value="<?php echo esc_attr( $branding['locale'] ?? 'en-ZA' ); ?>" class="hbp-input narrow" maxlength="12" placeholder="en-ZA">
                                </div>
                            </div>
                            <div class="hbp-field-row">
                                <div class="hbp-label">Currency</div>
                                <div class="hbp-control">
                                    <input type="text" name="currency" value="<?php echo esc_attr( $branding['currency'] ?? 'ZAR' ); ?>" class="hbp-input narrow" maxlength="3" placeholder="ZAR">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Custom CSS -->
                <div class="hbp-section">
                    <div class="hbp-section-head">
                        <div>
                            <h2>Custom CSS</h2>
                            <p style="margin-top:4px;">Scoped to <code>#hx-*</code> and <code>.hx-*</code> selectors. Disallowed: <code>@import</code>, <code>position:fixed</code>, <code>expression()</code>, <code>javascript:</code>.</p>
                        </div>
                    </div>
                    <div class="hbp-section-body">
                        <textarea name="custom_css" rows="10" class="hbp-code-area" maxlength="8000" placeholder="#hx-sb-inner { border-radius: 16px; }"><?php echo esc_textarea( $branding['custom_css'] ?? '' ); ?></textarea>
                    </div>
                    <div class="hbp-form-actions">
                        <button type="submit" class="hbp-btn hbp-btn-primary">
                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            Save Branding
                        </button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=eshopeo-connector' ) ); ?>" class="hbp-btn hbp-btn-secondary">
                            &larr; Settings
                        </a>
                    </div>
                </div>

            </form>

        </div><!-- .eshopeo-branding-page -->

        <script>
        jQuery(function ($) {
            // Initialize color pickers
            $('.eshopeo-color').wpColorPicker();

            // Logo media picker
            $('#eshopeo_pick_logo').on('click', function (e) {
                e.preventDefault();
                var frame = wp.media({
                    title: 'Choose logo',
                    button: { text: 'Use this image' },
                    multiple: false,
                });
                frame.on('select', function () {
                    var attach = frame.state().get('selection').first().toJSON();
                    $('#eshopeo_avatar_url').val(attach.url);
                    var preview = document.getElementById('eshopeo-logo-preview');
                    preview.src = attach.url;
                    preview.classList.add('has-image');
                });
                frame.open();
            });

            // Update logo preview when URL changes manually
            $('#eshopeo_avatar_url').on('input', function () {
                var url = $(this).val();
                var preview = document.getElementById('eshopeo-logo-preview');
                if (url) {
                    preview.src = url;
                    preview.classList.add('has-image');
                } else {
                    preview.classList.remove('has-image');
                }
            });

            // Add chip row
            $('#eshopeo-add-chip').on('click', function () {
                var rows = $('#eshopeo-chips-table tbody tr').length;
                if (rows >= 8) { alert('Maximum 8 chips.'); return; }
                $('#eshopeo-chips-table tbody').append(
                    '<tr>' +
                    '<td><input type="text" name="chip_label[]" class="hbp-input" maxlength="40" placeholder="e.g. Serums"></td>' +
                    '<td><input type="text" name="chip_query[]" class="hbp-input wide" maxlength="200" placeholder="e.g. Show me your best serums"></td>' +
                    '</tr>'
                );
            });
        });
        </script>
        <?php
    }
}
