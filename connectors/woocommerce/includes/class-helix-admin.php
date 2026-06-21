<?php
defined( 'ABSPATH' ) || exit;

class Helix_Admin {
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'add_settings_page' ] );
        add_action( 'admin_init', [ self::class, 'register_settings' ] );
        add_action( 'admin_post_helix_save_settings', [ self::class, 'save_settings' ] );
        add_action( 'wp_ajax_helix_run_sync', [ self::class, 'ajax_run_sync' ] );
    }

    public static function add_settings_page(): void {
        add_submenu_page(
            'woocommerce',
            'Helix Connector',
            'Helix',
            'manage_woocommerce',
            'helix-connector',
            [ self::class, 'render_settings_page' ]
        );
        add_submenu_page(
            'woocommerce',
            'Helix — Leads',
            'Helix Leads',
            'manage_woocommerce',
            'helix-leads',
            [ self::class, 'render_leads_page' ]
        );
    }

    public static function register_settings(): void {
        register_setting( 'helix_settings', 'helix_api_url', [ 'sanitize_callback' => 'esc_url_raw' ] );
        register_setting( 'helix_settings', 'helix_provision_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'helix_settings', 'helix_consumer_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'helix_settings', 'helix_consumer_secret', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'helix_settings', 'helix_widget_enabled', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'helix_settings', 'helix_wa_enabled', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'helix_settings', 'helix_wa_number', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'helix_settings', 'helix_wa_message', [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
        register_setting( 'helix_settings', 'helix_sb_animations', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'helix_settings', 'helix_sb_fly_to_cart', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'helix_settings', 'helix_sb_card_modal', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'helix_settings', 'helix_lead_webhook_url', [ 'sanitize_callback' => 'esc_url_raw' ] );
        register_setting( 'helix_settings', 'helix_pack_id', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'helix_settings', 'helix_wp_api_user', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'helix_settings', 'helix_wp_app_password', [ 'sanitize_callback' => 'sanitize_text_field' ] );
    }

    public static function save_settings(): void {
        check_admin_referer( 'helix_save_settings' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized' );
        }

        update_option( 'helix_api_url', esc_url_raw( $_POST['helix_api_url'] ?? '' ) );
        update_option( 'helix_provision_key', sanitize_text_field( $_POST['helix_provision_key'] ?? '' ) );
        update_option( 'helix_consumer_key', sanitize_text_field( $_POST['helix_consumer_key'] ?? '' ) );
        update_option( 'helix_consumer_secret', sanitize_text_field( $_POST['helix_consumer_secret'] ?? '' ) );
        update_option( 'helix_widget_enabled', isset( $_POST['helix_widget_enabled'] ) ? 1 : 0 );
        update_option( 'helix_wa_enabled', isset( $_POST['helix_wa_enabled'] ) ? 1 : 0 );
        update_option( 'helix_wa_number', sanitize_text_field( $_POST['helix_wa_number'] ?? '' ) );
        update_option( 'helix_wa_message', sanitize_textarea_field( $_POST['helix_wa_message'] ?? '' ) );
        update_option( 'helix_sb_animations', isset( $_POST['helix_sb_animations'] ) ? 1 : 0 );
        update_option( 'helix_sb_fly_to_cart', isset( $_POST['helix_sb_fly_to_cart'] ) ? 1 : 0 );
        update_option( 'helix_sb_card_modal', isset( $_POST['helix_sb_card_modal'] ) ? 1 : 0 );
        update_option( 'helix_lead_webhook_url', esc_url_raw( $_POST['helix_lead_webhook_url'] ?? '' ) );
        update_option( 'helix_wp_api_user', sanitize_text_field( $_POST['helix_wp_api_user'] ?? '' ) );
        if ( ! empty( $_POST['helix_wp_app_password'] ) ) {
            update_option( 'helix_wp_app_password', sanitize_text_field( $_POST['helix_wp_app_password'] ) );
        }

        $allowed_packs = [ 'kbeauty', 'automotive', 'general' ];
        $pack_id       = sanitize_text_field( $_POST['helix_pack_id'] ?? 'kbeauty' );
        if ( ! in_array( $pack_id, $allowed_packs, true ) ) {
            $pack_id = 'kbeauty';
        }
        update_option( 'helix_pack_id', $pack_id );

        /* Sync operational settings to backend if already connected */
        if ( get_option( 'helix_tenant_id' ) && get_option( 'helix_admin_secret' ) ) {
            $client = new Helix_API_Client(
                get_option( 'helix_api_url', '' ),
                get_option( 'helix_public_key', '' )
            );
            $client->update_branding( [
                'lead_webhook_url' => get_option( 'helix_lead_webhook_url', '' ) ?: null,
            ] );
            // Keep pack_id in sync if it changed.
            $client->patch_tenant( get_option( 'helix_tenant_id' ), [ 'pack_id' => $pack_id ] );
        }

        if ( ! get_option( 'helix_tenant_id' ) ) {
            $client = new Helix_API_Client( get_option( 'helix_api_url', '' ) );
            $result = $client->provision(
                get_bloginfo( 'name' ),
                site_url(),
                [
                    'consumer_key'    => get_option( 'helix_consumer_key' ),
                    'consumer_secret' => get_option( 'helix_consumer_secret' ),
                ],
                $pack_id
            );
            if ( ! is_wp_error( $result ) ) {
                update_option( 'helix_tenant_id', $result['tenant_id'] );
                update_option( 'helix_public_key', $result['public_key'] );
                if ( ! empty( $result['admin_secret'] ) ) {
                    update_option( 'helix_admin_secret', $result['admin_secret'] );
                }
                Helix_Webhooks::register_webhooks( get_option( 'helix_api_url' ), $result['tenant_id'] );
            } else {
                wp_safe_redirect( admin_url( 'admin.php?page=helix-connector&connect_error=' . urlencode( $result->get_error_message() ) ) );
                exit;
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=helix-connector&saved=1' ) );
        exit;
    }

    public static function ajax_run_sync(): void {
        check_ajax_referer( 'helix_run_sync', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $result = Helix_Sync::run_full_sync();

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        wp_send_json_success( [
            'synced'  => $result['synced'] ?? 0,
            'failed'  => $result['failed'] ?? 0,
            'errors'  => $result['errors'] ?? [],
            'last_sync' => get_option( 'helix_last_sync', '' ),
        ] );
    }

    public static function render_settings_page(): void {
        $tenant_id  = get_option( 'helix_tenant_id', '' );
        $last_sync  = get_option( 'helix_last_sync', 'Never' );
        $sync_count = get_option( 'helix_synced_count', 0 );
        $connected  = ! empty( $tenant_id );
        ?>
        <style>
        /* ── Helix Admin Settings — shared reset & layout ── */
        .helix-page *,
        .helix-page *::before,
        .helix-page *::after { box-sizing: border-box; }

        .helix-page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px 20px 60px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #374151;
            background: #f9fafb;
        }

        /* ── Page header ── */
        .helix-page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 28px;
        }
        .helix-page-header h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
            color: #1e1b4b;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .helix-badge {
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

        /* ── Status bar ── */
        .helix-status-bar {
            display: flex;
            align-items: center;
            gap: 24px;
            flex-wrap: wrap;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 14px 20px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }
        .helix-status-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #374151;
        }
        .helix-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .helix-dot.green  { background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.15); }
        .helix-dot.red    { background: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,.15); }
        .helix-dot.amber  { background: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,.15); }
        .helix-status-item strong { color: #1e1b4b; }
        .helix-status-divider { width: 1px; height: 20px; background: #e5e7eb; }

        /* ── Notices ── */
        .helix-notice {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            line-height: 1.5;
        }
        .helix-notice.success { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; }
        .helix-notice.error   { background: #fef2f2; border: 1px solid #fca5a5; color: #7f1d1d; }

        /* ── Section cards ── */
        .helix-section {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.07);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .helix-section-head {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 16px 24px;
            border-bottom: 1px solid #e5e7eb;
            background: #fafafa;
        }
        .helix-section-head h2 {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            color: #1e1b4b;
            border-left: 3px solid #6366f1;
            padding-left: 10px;
        }
        .helix-section-body {
            padding: 24px;
        }

        /* ── Form grid ── */
        .helix-fields {
            display: grid;
            grid-template-columns: 220px 1fr;
            gap: 0;
        }
        .helix-field-row {
            display: contents;
        }
        .helix-field-row > .helix-field-label,
        .helix-field-row > .helix-field-control {
            padding: 14px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .helix-field-row:last-child > * {
            border-bottom: none;
        }
        .helix-field-label {
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            padding-right: 20px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .helix-field-label .helix-helper {
            font-size: 11px;
            font-weight: 400;
            color: #9ca3af;
            margin-top: 2px;
        }
        .helix-field-control {
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        /* ── Inputs ── */
        .helix-input,
        .helix-select,
        .helix-textarea {
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
        .helix-input:focus,
        .helix-select:focus,
        .helix-textarea:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99,102,241,.15);
        }
        .helix-textarea {
            resize: vertical;
            min-height: 80px;
        }
        .helix-input.wide,
        .helix-textarea.wide {
            max-width: 600px;
        }
        .helix-field-desc {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
            line-height: 1.5;
        }
        .helix-field-desc code {
            font-size: 11px;
            background: #f3f4f6;
            padding: 1px 4px;
            border-radius: 3px;
        }

        /* ── Toggle switch ── */
        .helix-toggle-wrap {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .helix-toggle {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 22px;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .helix-toggle input { opacity: 0; width: 0; height: 0; }
        .helix-toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: #d1d5db;
            border-radius: 22px;
            transition: background .2s;
        }
        .helix-toggle-slider::before {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            left: 3px;
            top: 3px;
            background: #fff;
            border-radius: 50%;
            transition: transform .2s;
            box-shadow: 0 1px 3px rgba(0,0,0,.2);
        }
        .helix-toggle input:checked + .helix-toggle-slider { background: #6366f1; }
        .helix-toggle input:checked + .helix-toggle-slider::before { transform: translateX(18px); }
        .helix-toggle-text {
            font-size: 13px;
            color: #374151;
            line-height: 1.5;
        }
        .helix-toggle-text span {
            display: block;
            font-size: 12px;
            color: #6b7280;
            margin-top: 2px;
        }

        /* ── Buttons ── */
        .helix-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            min-height: 36px;
            border: none;
            cursor: pointer;
            transition: background .15s, opacity .15s;
            text-decoration: none;
            font-family: inherit;
        }
        .helix-btn-primary {
            background: #6366f1;
            color: #fff;
        }
        .helix-btn-primary:hover { background: #4f46e5; color: #fff; }
        .helix-btn-secondary {
            background: #fff;
            color: #374151;
            border: 1px solid #d1d5db;
        }
        .helix-btn-secondary:hover { background: #f9fafb; border-color: #9ca3af; }
        .helix-btn-success {
            background: #10b981;
            color: #fff;
        }
        .helix-btn-success:hover { background: #059669; color: #fff; }
        .helix-btn:disabled { opacity: .55; cursor: not-allowed; }

        /* ── Form actions bar ── */
        .helix-form-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 24px;
            border-top: 1px solid #e5e7eb;
            background: #fafafa;
        }

        /* ── Sync section ── */
        .helix-sync-row {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .helix-spinner {
            width: 18px;
            height: 18px;
            border: 2px solid #e5e7eb;
            border-top-color: #6366f1;
            border-radius: 50%;
            animation: helix-spin .7s linear infinite;
            display: none;
            flex-shrink: 0;
        }
        @keyframes helix-spin { to { transform: rotate(360deg); } }

        .helix-sync-result {
            margin-top: 16px;
        }
        .helix-sync-result-inner {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.6;
        }
        .helix-sync-result-inner.success { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; }
        .helix-sync-result-inner.error   { background: #fef2f2; border: 1px solid #fca5a5; color: #7f1d1d; }

        /* ── Leads table ── */
        .helix-table-wrap { overflow-x: auto; }
        .helix-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .helix-table thead th {
            position: sticky;
            top: 0;
            background: #fff;
            z-index: 1;
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .05em;
            padding: 10px 14px;
            border-bottom: 2px solid #e5e7eb;
            text-align: left;
            white-space: nowrap;
        }
        .helix-table tbody td {
            padding: 12px 14px;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
            vertical-align: middle;
        }
        .helix-table tbody tr:last-child td { border-bottom: none; }
        .helix-table tbody tr:nth-child(even) td { background: #f9fafb; }
        .helix-table-empty {
            text-align: center;
            padding: 48px 20px;
            color: #9ca3af;
            font-size: 13px;
        }
        .helix-date-cell { white-space: nowrap; color: #6b7280; font-size: 12px; }

        /* ── Pagination ── */
        .helix-pager {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 16px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        .helix-pager a,
        .helix-pager span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            padding: 0 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid #e5e7eb;
            color: #374151;
            text-decoration: none;
            transition: background .12s, border-color .12s;
        }
        .helix-pager a:hover { background: #f5f3ff; border-color: #6366f1; color: #6366f1; }
        .helix-pager span.current { background: #6366f1; border-color: #6366f1; color: #fff; }

        /* ── Responsive ── */
        @media (max-width: 782px) {
            .helix-page { padding: 16px 12px 40px; }
            .helix-page-header { flex-direction: column; align-items: flex-start; }
            .helix-fields { grid-template-columns: 1fr; }
            .helix-field-row > .helix-field-label { padding-bottom: 4px; border-bottom: none; }
            .helix-field-row > .helix-field-control { padding-top: 0; }
            .helix-input, .helix-select, .helix-textarea { max-width: 100%; }
            .helix-status-bar { gap: 12px; flex-direction: column; align-items: flex-start; }
            .helix-status-divider { display: none; }
            .helix-form-actions { flex-direction: column; align-items: stretch; }
            .helix-btn { justify-content: center; }
        }
        @media (max-width: 480px) {
            .helix-section-head { padding: 12px 16px; }
            .helix-section-body { padding: 16px; }
            .helix-pager { justify-content: center; }
        }

        /* ── Industry pack selector ── */
        .helix-pack-selector{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:8px}
        @media(max-width:782px){.helix-pack-selector{grid-template-columns:1fr}}
        .helix-pack-card{display:flex;flex-direction:column;align-items:center;text-align:center;padding:20px 16px;border:2px solid #e5e7eb;border-radius:10px;cursor:pointer;transition:all .15s;position:relative;border-bottom:4px solid var(--pack-color)}
        .helix-pack-card:hover{border-color:#6366f1;background:#f5f3ff}
        .helix-pack-card--active{border-color:#6366f1;background:#eef2ff}
        .helix-pack-radio{position:absolute;opacity:0;pointer-events:none}
        .helix-pack-icon{font-size:32px;margin-bottom:8px;display:block}
        .helix-pack-name{font-size:14px;font-weight:600;color:#1e1b4b;margin-bottom:4px;display:block}
        .helix-pack-desc{font-size:12px;color:#6b7280;display:block}
        .helix-pack-check{position:absolute;top:8px;right:8px;width:20px;height:20px;background:#6366f1;color:white;border-radius:50%;font-size:11px;display:none;align-items:center;justify-content:center}
        .helix-pack-card--active .helix-pack-check{display:flex}
        </style>

        <script>
        document.querySelectorAll('.helix-pack-radio').forEach(function(r){
            r.addEventListener('change',function(){
                document.querySelectorAll('.helix-pack-card').forEach(function(c){c.classList.remove('helix-pack-card--active')});
                this.closest('.helix-pack-card').classList.add('helix-pack-card--active');
            });
        });
        </script>

        <div class="helix-page">

            <!-- Page header -->
            <div class="helix-page-header">
                <h1>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <circle cx="12" cy="12" r="10" fill="#6366f1" opacity=".12"/>
                        <path d="M8 12.5v-5l6 4.5-6 4.5v-4z" fill="#6366f1"/>
                    </svg>
                    Helix Connector
                    <span class="helix-badge">Helix AI</span>
                    <?php
                    $pack = Helix_Pack::config();
                    echo '<span class="helix-industry-badge" style="background:' . esc_attr( $pack['color'] ) . '20;color:' . esc_attr( $pack['color'] ) . ';border:1px solid ' . esc_attr( $pack['color'] ) . '40;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:500;margin-left:10px;">';
                    echo esc_html( $pack['icon'] . ' ' . $pack['label'] );
                    echo '</span>';
                    ?>
                </h1>
            </div>

            <!-- Notices -->
            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="helix-notice success">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    Settings saved and store connected successfully.
                </div>
            <?php endif; ?>
            <?php if ( isset( $_GET['connect_error'] ) ) : ?>
                <div class="helix-notice error">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="#ef4444" stroke-width="2"/><path stroke="#ef4444" stroke-width="2" stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>
                    Connection failed: <?php echo esc_html( urldecode( $_GET['connect_error'] ) ); ?>
                </div>
            <?php endif; ?>

            <!-- Status bar -->
            <div class="helix-status-bar">
                <div class="helix-status-item">
                    <div class="helix-dot <?php echo $connected ? 'green' : 'red'; ?>"></div>
                    <?php if ( $connected ) : ?>
                        <strong>Connected</strong> &mdash; tenant: <code style="font-size:11px;background:#f3f4f6;padding:1px 5px;border-radius:3px;"><?php echo esc_html( $tenant_id ); ?></code>
                    <?php else : ?>
                        <strong>Not connected</strong> &mdash; fill in the fields below and save to connect
                    <?php endif; ?>
                </div>
                <?php if ( $connected ) : ?>
                <div class="helix-status-divider"></div>
                <div class="helix-status-item">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path stroke="#6b7280" stroke-width="2" stroke-linecap="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Last sync: <strong id="helix-last-sync"><?php echo esc_html( $last_sync ); ?></strong>
                </div>
                <div class="helix-status-divider"></div>
                <div class="helix-status-item">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path stroke="#6b7280" stroke-width="2" stroke-linecap="round" d="M20 7H4a1 1 0 00-1 1v8a1 1 0 001 1h16a1 1 0 001-1V8a1 1 0 00-1-1z"/></svg>
                    <strong id="helix-sync-count"><?php echo esc_html( $sync_count ); ?></strong> <?php echo esc_html( strtolower( Helix_Pack::config()['sync_noun'] ) ); ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Settings form -->
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'helix_save_settings' ); ?>
                <input type="hidden" name="action" value="helix_save_settings">

                <!-- Connection section -->
                <div class="helix-section">
                    <div class="helix-section-head">
                        <h2>Connection &amp; Industry</h2>
                    </div>
                    <div class="helix-section-body">
                        <div class="helix-fields">
                            <div class="helix-field-row">
                                <div class="helix-field-label">
                                    Industry / Pack
                                    <span class="helix-helper">Controls AI schema &amp; behaviour</span>
                                </div>
                                <div class="helix-field-control">
                                    <?php $current_pack = get_option( 'helix_pack_id', 'kbeauty' ); ?>
                                    <div class="helix-pack-selector">
                                        <?php foreach ( Helix_Pack::all() as $id => $pack ) : ?>
                                        <label class="helix-pack-card <?php echo $current_pack === $id ? 'helix-pack-card--active' : ''; ?>"
                                               style="--pack-color: <?php echo esc_attr( $pack['color'] ); ?>">
                                            <input type="radio" name="helix_pack_id" value="<?php echo esc_attr( $id ); ?>"
                                                   <?php checked( $current_pack, $id ); ?> class="helix-pack-radio">
                                            <span class="helix-pack-icon"><?php echo $pack['icon']; ?></span>
                                            <span class="helix-pack-name"><?php echo esc_html( $pack['label'] ); ?></span>
                                            <span class="helix-pack-desc"><?php echo esc_html( $pack['description'] ); ?></span>
                                            <span class="helix-pack-check">&#10003;</span>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="helix-field-desc">Determines which product schema and AI behaviour your store uses. Changing this after connecting will update the backend immediately.</p>
                                </div>
                            </div>
                            <div class="helix-field-row">
                                <div class="helix-field-label">
                                    API URL
                                    <span class="helix-helper">Helix backend endpoint</span>
                                </div>
                                <div class="helix-field-control">
                                    <input type="url" name="helix_api_url" value="<?php echo esc_attr( get_option( 'helix_api_url' ) ); ?>" class="helix-input wide" placeholder="https://api.helix.ai">
                                </div>
                            </div>
                            <div class="helix-field-row">
                                <div class="helix-field-label">
                                    Provision Key
                                    <span class="helix-helper">Used for first-time provisioning</span>
                                </div>
                                <div class="helix-field-control">
                                    <input type="password" name="helix_provision_key" value="<?php echo esc_attr( get_option( 'helix_provision_key' ) ); ?>" class="helix-input">
                                </div>
                            </div>
                            <div class="helix-field-row">
                                <div class="helix-field-label">
                                    WC Consumer Key
                                </div>
                                <div class="helix-field-control">
                                    <input type="text" name="helix_consumer_key" value="<?php echo esc_attr( get_option( 'helix_consumer_key' ) ); ?>" class="helix-input wide">
                                </div>
                            </div>
                            <div class="helix-field-row">
                                <div class="helix-field-label">
                                    WC Consumer Secret
                                </div>
                                <div class="helix-field-control">
                                    <input type="password" name="helix_consumer_secret" value="" class="helix-input" placeholder="(leave blank to keep unchanged)">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Widget section -->
                <div class="helix-section">
                    <div class="helix-section-head">
                        <h2>Widget</h2>
                    </div>
                    <div class="helix-section-body">
                        <div class="helix-fields">
                            <div class="helix-field-row">
                                <div class="helix-field-label">Auto-inject widget</div>
                                <div class="helix-field-control">
                                    <div class="helix-toggle-wrap">
                                        <label class="helix-toggle">
                                            <input type="checkbox" name="helix_widget_enabled" value="1" <?php checked( get_option( 'helix_widget_enabled', 0 ), 1 ); ?>>
                                            <span class="helix-toggle-slider"></span>
                                        </label>
                                        <div class="helix-toggle-text">
                                            Automatically embed the Helix chat widget
                                            <span>Adds the widget script to all frontend pages</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Animations section -->
                <div class="helix-section">
                    <div class="helix-section-head">
                        <h2>Search Bar Animations</h2>
                    </div>
                    <div class="helix-section-body">
                        <div class="helix-fields">
                            <div class="helix-field-row">
                                <div class="helix-field-label">Card fly-in animation</div>
                                <div class="helix-field-control">
                                    <div class="helix-toggle-wrap">
                                        <label class="helix-toggle">
                                            <input type="checkbox" name="helix_sb_animations" value="1" <?php checked( get_option( 'helix_sb_animations', 1 ), 1 ); ?>>
                                            <span class="helix-toggle-slider"></span>
                                        </label>
                                        <div class="helix-toggle-text">
                                            Product card fly-in (rainbow sparkle effect)
                                            <span>Animate product cards flying in from the search bar</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="helix-field-row">
                                <div class="helix-field-label">Fly-to-cart animation</div>
                                <div class="helix-field-control">
                                    <div class="helix-toggle-wrap">
                                        <label class="helix-toggle">
                                            <input type="checkbox" name="helix_sb_fly_to_cart" value="1" <?php checked( get_option( 'helix_sb_fly_to_cart', 1 ), 1 ); ?>>
                                            <span class="helix-toggle-slider"></span>
                                        </label>
                                        <div class="helix-toggle-text">
                                            Fly-to-cart on add to cart
                                            <span>Animate product thumbnail flying to the cart icon</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="helix-field-row">
                                <div class="helix-field-label">Product detail modal</div>
                                <div class="helix-field-control">
                                    <div class="helix-toggle-wrap">
                                        <label class="helix-toggle">
                                            <input type="checkbox" name="helix_sb_card_modal" value="1" <?php checked( get_option( 'helix_sb_card_modal', 1 ), 1 ); ?>>
                                            <span class="helix-toggle-slider"></span>
                                        </label>
                                        <div class="helix-toggle-text">
                                            Open product details in modal
                                            <span>Show a modal overlay instead of navigating to the product page</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Lead Capture section -->
                <div class="helix-section">
                    <div class="helix-section-head">
                        <h2>Lead Capture</h2>
                    </div>
                    <div class="helix-section-body">
                        <div class="helix-fields">
                            <div class="helix-field-row">
                                <div class="helix-field-label">
                                    Lead webhook URL
                                    <span class="helix-helper">Optional CRM / automation hook</span>
                                </div>
                                <div class="helix-field-control">
                                    <input type="url" name="helix_lead_webhook_url" value="<?php echo esc_attr( get_option( 'helix_lead_webhook_url', '' ) ); ?>" class="helix-input wide" placeholder="https://hook.make.com/...">
                                    <p class="helix-field-desc">POST new enquiries here (Zapier, Make.com, CRM). Leave blank to disable webhooks.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- WP Agent credentials section -->
                <div class="helix-section">
                    <div class="helix-section-head">
                        <h2>AI Assistant — WordPress Credentials</h2>
                    </div>
                    <div class="helix-section-body">
                        <p style="font-size:13px;color:#6b7280;margin:0 0 16px;">Required for the AI Assistant to create and edit posts, pages, and media. Uses a WordPress <strong>Application Password</strong> — no plugin needed.</p>
                        <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:12px 14px;margin-bottom:16px;font-size:12px;color:#92400e;">
                            <strong>How to generate an Application Password:</strong> Go to <em>Users → Profile</em>, scroll to <em>Application Passwords</em>, enter "Helix AI" as the name, click <em>Add New Application Password</em>, and copy the generated password here.
                        </div>
                        <div class="helix-fields">
                            <div class="helix-field-row">
                                <div class="helix-field-label">
                                    WordPress Username
                                    <span class="helix-helper">Must be an Administrator or Editor</span>
                                </div>
                                <div class="helix-field-control">
                                    <input type="text" name="helix_wp_api_user" value="<?php echo esc_attr( get_option( 'helix_wp_api_user', '' ) ); ?>" class="helix-input wide" placeholder="admin">
                                </div>
                            </div>
                            <div class="helix-field-row">
                                <div class="helix-field-label">
                                    Application Password
                                    <span class="helix-helper">Generated from WP → Users → Profile</span>
                                </div>
                                <div class="helix-field-control">
                                    <input type="password" name="helix_wp_app_password" value="<?php echo esc_attr( get_option( 'helix_wp_app_password', '' ) ); ?>" class="helix-input wide" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx">
                                    <p class="helix-field-desc">Stored securely. Used only for AI Agent → WordPress REST API calls.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- WhatsApp section -->
                <div class="helix-section">
                    <div class="helix-section-head">
                        <h2>WhatsApp Button</h2>
                    </div>
                    <div class="helix-section-body">
                        <div class="helix-fields">
                            <div class="helix-field-row">
                                <div class="helix-field-label">Enable WhatsApp button</div>
                                <div class="helix-field-control">
                                    <div class="helix-toggle-wrap">
                                        <label class="helix-toggle">
                                            <input type="checkbox" name="helix_wa_enabled" value="1" <?php checked( get_option( 'helix_wa_enabled', 0 ), 1 ); ?>>
                                            <span class="helix-toggle-slider"></span>
                                        </label>
                                        <div class="helix-toggle-text">
                                            Show a WhatsApp chat button inside the AI widget
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="helix-field-row">
                                <div class="helix-field-label">
                                    WhatsApp number
                                    <span class="helix-helper">International format, no spaces</span>
                                </div>
                                <div class="helix-field-control">
                                    <input type="text" name="helix_wa_number" value="<?php echo esc_attr( get_option( 'helix_wa_number', '' ) ); ?>" class="helix-input" placeholder="27831234567">
                                    <p class="helix-field-desc">International format without + sign or spaces. e.g. <code>27831234567</code></p>
                                </div>
                            </div>
                            <div class="helix-field-row">
                                <div class="helix-field-label">Pre-filled message</div>
                                <div class="helix-field-control">
                                    <textarea name="helix_wa_message" rows="3" class="helix-textarea wide"><?php echo esc_textarea( get_option( 'helix_wa_message', "Hi! I'd like some skincare advice." ) ); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="helix-form-actions">
                        <button type="submit" class="helix-btn helix-btn-primary">
                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            <?php echo $connected ? 'Save Settings' : 'Save &amp; Connect'; ?>
                        </button>
                        <?php if ( $connected ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=helix-branding' ) ); ?>" class="helix-btn helix-btn-secondary">Branding &rarr;</a>
                        <?php endif; ?>
                    </div>
                </div>

            </form>

            <?php if ( $connected ) : ?>
            <!-- Catalog Sync section -->
            <div class="helix-section">
                <div class="helix-section-head">
                    <h2>Catalog Sync</h2>
                </div>
                <div class="helix-section-body">
                    <p style="font-size:13px;color:#6b7280;margin:0 0 16px;">Push your WooCommerce product catalog to Helix. This runs automatically via cron, but you can trigger it manually here.</p>
                    <div class="helix-sync-row">
                        <button id="helix-sync-btn" class="helix-btn helix-btn-success">
                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Sync Catalog Now
                        </button>
                        <div class="helix-spinner" id="helix-sync-spinner"></div>
                    </div>
                    <div class="helix-sync-result" id="helix-sync-result" style="display:none;"></div>
                </div>
            </div>

            <script>
            document.getElementById('helix-sync-btn').addEventListener('click', function () {
                var btn     = this;
                var spinner = document.getElementById('helix-sync-spinner');
                var result  = document.getElementById('helix-sync-result');

                btn.disabled             = true;
                spinner.style.display    = 'block';
                result.style.display     = 'none';
                result.innerHTML         = '';

                var data = new FormData();
                data.append('action', 'helix_run_sync');
                data.append('nonce', '<?php echo esc_js( wp_create_nonce( 'helix_run_sync' ) ); ?>');

                fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: data,
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    spinner.style.display = 'none';
                    btn.disabled          = false;
                    result.style.display  = 'block';

                    if ( res.success ) {
                        var d    = res.data;
                        var html = '<div class="helix-sync-result-inner success">'
                                 + '<strong>Sync complete.</strong> '
                                 + d.synced + ' product' + (d.synced !== 1 ? 's' : '') + ' synced';

                        if ( d.failed > 0 ) {
                            html += ', <strong style="color:#b91c1c;">' + d.failed + ' failed</strong>';
                        }
                        html += '.';

                        if ( d.errors && d.errors.length > 0 ) {
                            html += '<details style="margin-top:8px;"><summary style="cursor:pointer;font-weight:500;">Show errors (' + d.errors.length + ')</summary>'
                                + '<ul style="margin:8px 0 4px 16px;padding:0;">';
                            d.errors.forEach(function (e) {
                                html += '<li style="font-size:12px;">' + e.replace(/</g, '&lt;') + '</li>';
                            });
                            html += '</ul></details>';
                        }
                        html += '</div>';

                        if ( d.last_sync ) {
                            document.getElementById('helix-last-sync').textContent = d.last_sync;
                            document.getElementById('helix-sync-count').textContent = d.synced;
                        }
                        result.innerHTML = html;
                    } else {
                        var msg = (res.data && res.data.message) ? res.data.message : 'Unknown error.';
                        result.innerHTML = '<div class="helix-sync-result-inner error"><strong>Sync failed:</strong> ' + msg.replace(/</g, '&lt;') + '</div>';
                    }
                })
                .catch(function (err) {
                    spinner.style.display = 'none';
                    btn.disabled          = false;
                    result.style.display  = 'block';
                    result.innerHTML = '<div class="helix-sync-result-inner error"><strong>Request failed:</strong> ' + err.message + '</div>';
                });
            });
            </script>
            <?php endif; ?>

        </div><!-- .helix-page -->
        <?php
    }

    public static function render_leads_page(): void {
        $tenant_id = get_option( 'helix_tenant_id', '' );
        if ( ! $tenant_id ) {
            ?>
            <style>
            .helix-page *,.helix-page *::before,.helix-page *::after{box-sizing:border-box;}
            .helix-page{max-width:1200px;margin:0 auto;padding:24px 20px 60px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#374151;background:#f9fafb;}
            .helix-page-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:28px;}
            .helix-page-header h1{margin:0;font-size:22px;font-weight:700;color:#1e1b4b;}
            .helix-notice{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:13px;}
            .helix-notice.warning{background:#fffbeb;border:1px solid #fcd34d;color:#78350f;}
            .helix-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:6px;font-size:13px;font-weight:500;min-height:36px;border:none;cursor:pointer;text-decoration:none;}
            .helix-btn-primary{background:#6366f1;color:#fff;}
            .helix-btn-primary:hover{background:#4f46e5;color:#fff;}
            </style>
            <div class="helix-page">
                <div class="helix-page-header"><h1>Helix &mdash; Enquiry Leads</h1></div>
                <div class="helix-notice warning">
                    Connect your store first before viewing leads.
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=helix-connector' ) ); ?>" class="helix-btn helix-btn-primary" style="margin-left:auto;white-space:nowrap;">Go to Settings</a>
                </div>
            </div>
            <?php
            return;
        }

        $api_url      = get_option( 'helix_api_url', '' );
        $public_key   = get_option( 'helix_public_key', '' );
        $client       = new Helix_API_Client( $api_url, $public_key );
        $page_num     = max( 1, intval( $_GET['paged'] ?? 1 ) );
        $result       = $client->get_leads( $page_num, 50 );
        $error        = is_wp_error( $result ) ? $result->get_error_message() : null;
        $leads        = $error ? [] : ( $result['leads'] ?? [] );
        $total        = $error ? 0 : ( $result['total'] ?? 0 );
        $total_pages  = $total ? (int) ceil( $total / 50 ) : 1;
        ?>
        <style>
        .helix-page *,.helix-page *::before,.helix-page *::after{box-sizing:border-box;}
        .helix-page{max-width:1200px;margin:0 auto;padding:24px 20px 60px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#374151;background:#f9fafb;}
        .helix-page-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:28px;}
        .helix-page-header h1{margin:0;font-size:22px;font-weight:700;color:#1e1b4b;display:flex;align-items:center;gap:10px;}
        .helix-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;letter-spacing:.04em;}
        .helix-total-pill{display:inline-flex;align-items:center;padding:4px 12px;background:#f3f4f6;border-radius:20px;font-size:12px;color:#6b7280;}
        .helix-notice{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:13px;}
        .helix-notice.error{background:#fef2f2;border:1px solid #fca5a5;color:#7f1d1d;}
        .helix-section{background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.07);margin-bottom:20px;overflow:hidden;}
        .helix-section-body{padding:0;}
        .helix-table-wrap{overflow-x:auto;}
        .helix-table{width:100%;border-collapse:collapse;font-size:13px;}
        .helix-table thead th{position:sticky;top:0;background:#fff;z-index:1;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;padding:12px 16px;border-bottom:2px solid #e5e7eb;text-align:left;white-space:nowrap;}
        .helix-table tbody td{padding:12px 16px;border-bottom:1px solid #f3f4f6;color:#374151;vertical-align:middle;}
        .helix-table tbody tr:last-child td{border-bottom:none;}
        .helix-table tbody tr:nth-child(even) td{background:#f9fafb;}
        .helix-table tbody tr:hover td{background:#f5f3ff;}
        .helix-table-empty{text-align:center;padding:60px 20px;color:#9ca3af;font-size:13px;}
        .helix-date-cell{white-space:nowrap;color:#6b7280;font-size:12px;}
        .helix-source-pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:500;background:#ede9fe;color:#6366f1;}
        .helix-pager{display:flex;align-items:center;gap:6px;padding:16px;justify-content:flex-end;flex-wrap:wrap;border-top:1px solid #f3f4f6;}
        .helix-pager a,.helix-pager span{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 10px;border-radius:6px;font-size:12px;font-weight:500;border:1px solid #e5e7eb;color:#374151;text-decoration:none;transition:background .12s,border-color .12s;}
        .helix-pager a:hover{background:#f5f3ff;border-color:#6366f1;color:#6366f1;}
        .helix-pager span.current{background:#6366f1;border-color:#6366f1;color:#fff;}
        @media(max-width:782px){.helix-page{padding:16px 12px 40px;}.helix-page-header{flex-direction:column;align-items:flex-start;}}
        @media(max-width:480px){.helix-pager{justify-content:center;}}
        </style>

        <div class="helix-page">
            <div class="helix-page-header">
                <h1>
                    Helix &mdash; Enquiry Leads
                    <span class="helix-badge">Helix AI</span>
                </h1>
                <span class="helix-total-pill"><?php echo esc_html( $total ); ?> total lead<?php echo $total !== 1 ? 's' : ''; ?></span>
            </div>

            <?php if ( $error ) : ?>
                <div class="helix-notice error">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="#ef4444" stroke-width="2"/><path stroke="#ef4444" stroke-width="2" stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>
                    Could not fetch leads: <?php echo esc_html( $error ); ?>
                </div>
            <?php endif; ?>

            <div class="helix-section">
                <div class="helix-section-body">
                    <?php if ( empty( $leads ) && ! $error ) : ?>
                        <div class="helix-table-empty">
                            <svg width="40" height="40" fill="none" viewBox="0 0 24 24" style="margin:0 auto 12px;display:block;"><path stroke="#d1d5db" stroke-width="1.5" stroke-linecap="round" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                            No leads yet. Once customers submit an enquiry via the Helix widget, they will appear here.
                        </div>
                    <?php elseif ( ! empty( $leads ) ) : ?>
                        <div class="helix-table-wrap">
                            <table class="helix-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Name</th>
                                        <th>Phone</th>
                                        <th>Email</th>
                                        <th>Vehicle / Product</th>
                                        <th>Best time to call</th>
                                        <th>Source</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $leads as $lead ) : ?>
                                        <tr>
                                            <td class="helix-date-cell"><?php echo esc_html( wp_date( 'd M Y H:i', strtotime( $lead['created_at'] ) ) ); ?></td>
                                            <td style="font-weight:500;"><?php echo esc_html( $lead['name'] ?? '—' ); ?></td>
                                            <td><?php echo esc_html( $lead['phone'] ?? '—' ); ?></td>
                                            <td><?php echo esc_html( $lead['email'] ?? '—' ); ?></td>
                                            <td><?php echo esc_html( $lead['product_platform_id'] ?? '—' ); ?></td>
                                            <td><?php echo esc_html( $lead['preferred_contact_time'] ?? '—' ); ?></td>
                                            <td>
                                                <?php if ( ! empty( $lead['source'] ) ) : ?>
                                                    <span class="helix-source-pill"><?php echo esc_html( $lead['source'] ); ?></span>
                                                <?php else : ?>
                                                    <span style="color:#9ca3af;">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ( $total_pages > 1 ) : ?>
                            <div class="helix-pager">
                                <?php if ( $page_num > 1 ) : ?>
                                    <a href="<?php echo esc_url( add_query_arg( 'paged', $page_num - 1 ) ); ?>">&larr; Prev</a>
                                <?php endif; ?>
                                <?php for ( $p = max(1,$page_num-2); $p <= min($total_pages,$page_num+2); $p++ ) : ?>
                                    <?php if ( $p === $page_num ) : ?>
                                        <span class="current"><?php echo $p; ?></span>
                                    <?php else : ?>
                                        <a href="<?php echo esc_url( add_query_arg( 'paged', $p ) ); ?>"><?php echo $p; ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                <?php if ( $page_num < $total_pages ) : ?>
                                    <a href="<?php echo esc_url( add_query_arg( 'paged', $page_num + 1 ) ); ?>">Next &rarr;</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- .helix-page -->
        <?php
    }
}
