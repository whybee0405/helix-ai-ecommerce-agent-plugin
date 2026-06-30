<?php
defined( 'ABSPATH' ) || exit;

class Eshopeo_Admin {
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'add_settings_page' ] );
        add_action( 'admin_init', [ self::class, 'register_settings' ] );
        add_action( 'admin_post_eshopeo_save_settings', [ self::class, 'save_settings' ] );
        add_action( 'wp_ajax_eshopeo_run_sync', [ self::class, 'ajax_run_sync' ] );
        add_action( 'wp_ajax_eshopeo_save_attr_types', [ self::class, 'ajax_save_attr_types' ] );
        add_action( 'wp_ajax_eshopeo_save_registration', [ self::class, 'ajax_save_registration' ] );
    }

    public static function add_settings_page(): void {
        add_submenu_page(
            'woocommerce',
            'eShopeo Connector',
            'eShopeo',
            'manage_woocommerce',
            'eshopeo-connector',
            [ self::class, 'render_settings_page' ]
        );
        add_submenu_page(
            'woocommerce',
            'eShopeo — Leads',
            'eShopeo Leads',
            'manage_woocommerce',
            'eshopeo-leads',
            [ self::class, 'render_leads_page' ]
        );
    }

    public static function register_settings(): void {
        register_setting( 'eshopeo_settings', 'eshopeo_api_url', [ 'sanitize_callback' => 'esc_url_raw' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_provision_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_consumer_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_consumer_secret', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_widget_enabled', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_wa_enabled', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_wa_number', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_wa_message', [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_sb_animations', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_sb_fly_to_cart', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_sb_card_modal', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_lead_webhook_url', [ 'sanitize_callback' => 'esc_url_raw' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_pack_id', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_wp_api_user', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_wp_app_password', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_uptime_alert_email', [ 'sanitize_callback' => 'sanitize_email' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_backup_schedule', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_backup_keep', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_digest_enabled', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_digest_email', [ 'sanitize_callback' => 'sanitize_email' ] );
        register_setting( 'eshopeo_settings', 'eshopeo_pagespeed_api_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
    }

    public static function save_settings(): void {
        check_admin_referer( 'eshopeo_save_settings' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized' );
        }

        update_option( 'eshopeo_api_url', esc_url_raw( $_POST['eshopeo_api_url'] ?? '' ) );
        update_option( 'eshopeo_provision_key', sanitize_text_field( $_POST['eshopeo_provision_key'] ?? '' ) );
        update_option( 'eshopeo_consumer_key', sanitize_text_field( $_POST['eshopeo_consumer_key'] ?? '' ) );
        update_option( 'eshopeo_consumer_secret', sanitize_text_field( $_POST['eshopeo_consumer_secret'] ?? '' ) );
        update_option( 'eshopeo_widget_enabled', isset( $_POST['eshopeo_widget_enabled'] ) ? 1 : 0 );
        update_option( 'eshopeo_wa_enabled', isset( $_POST['eshopeo_wa_enabled'] ) ? 1 : 0 );
        update_option( 'eshopeo_wa_number', sanitize_text_field( $_POST['eshopeo_wa_number'] ?? '' ) );
        update_option( 'eshopeo_wa_message', sanitize_textarea_field( $_POST['eshopeo_wa_message'] ?? '' ) );
        update_option( 'eshopeo_sb_animations', isset( $_POST['eshopeo_sb_animations'] ) ? 1 : 0 );
        update_option( 'eshopeo_sb_fly_to_cart', isset( $_POST['eshopeo_sb_fly_to_cart'] ) ? 1 : 0 );
        update_option( 'eshopeo_sb_card_modal', isset( $_POST['eshopeo_sb_card_modal'] ) ? 1 : 0 );
        update_option( 'eshopeo_lead_webhook_url', esc_url_raw( $_POST['eshopeo_lead_webhook_url'] ?? '' ) );
        update_option( 'eshopeo_wp_api_user', sanitize_text_field( $_POST['eshopeo_wp_api_user'] ?? '' ) );
        if ( ! empty( $_POST['eshopeo_wp_app_password'] ) ) {
            update_option( 'eshopeo_wp_app_password', sanitize_text_field( $_POST['eshopeo_wp_app_password'] ) );
        }
        update_option( 'eshopeo_uptime_alert_email', sanitize_email( $_POST['eshopeo_uptime_alert_email'] ?? '' ) );
        $allowed_schedules = [ 'daily', 'weekly', 'off' ];
        $backup_schedule   = sanitize_text_field( $_POST['eshopeo_backup_schedule'] ?? 'daily' );
        update_option( 'eshopeo_backup_schedule', in_array( $backup_schedule, $allowed_schedules, true ) ? $backup_schedule : 'daily' );
        update_option( 'eshopeo_backup_keep', absint( $_POST['eshopeo_backup_keep'] ?? 7 ) ?: 7 );
        update_option( 'eshopeo_digest_enabled', isset( $_POST['eshopeo_digest_enabled'] ) ? 1 : 0 );
        update_option( 'eshopeo_digest_email', sanitize_email( $_POST['eshopeo_digest_email'] ?? '' ) );
        update_option( 'eshopeo_pagespeed_api_key', sanitize_text_field( $_POST['eshopeo_pagespeed_api_key'] ?? '' ) );

        $allowed_packs = [ 'kbeauty', 'automotive', 'general' ];
        $pack_id       = sanitize_text_field( $_POST['eshopeo_pack_id'] ?? 'kbeauty' );
        if ( ! in_array( $pack_id, $allowed_packs, true ) ) {
            $pack_id = 'kbeauty';
        }
        update_option( 'eshopeo_pack_id', $pack_id );

        /* Sync operational settings to backend if already connected */
        if ( get_option( 'eshopeo_tenant_id' ) && get_option( 'eshopeo_admin_secret' ) ) {
            $client = new Eshopeo_API_Client(
                get_option( 'eshopeo_api_url', '' ),
                get_option( 'eshopeo_public_key', '' )
            );
            $client->update_branding( [
                'lead_webhook_url' => get_option( 'eshopeo_lead_webhook_url', '' ) ?: null,
            ] );
            // Keep pack_id in sync if it changed.
            $client->patch_tenant( get_option( 'eshopeo_tenant_id' ), [ 'pack_id' => $pack_id ] );
        }

        if ( ! get_option( 'eshopeo_tenant_id' ) ) {
            $client = new Eshopeo_API_Client( get_option( 'eshopeo_api_url', '' ) );
            $result = $client->provision(
                get_bloginfo( 'name' ),
                site_url(),
                [
                    'consumer_key'    => get_option( 'eshopeo_consumer_key' ),
                    'consumer_secret' => get_option( 'eshopeo_consumer_secret' ),
                ],
                $pack_id
            );
            if ( ! is_wp_error( $result ) ) {
                update_option( 'eshopeo_tenant_id', $result['tenant_id'] );
                update_option( 'eshopeo_public_key', $result['public_key'] );
                if ( ! empty( $result['admin_secret'] ) ) {
                    update_option( 'eshopeo_admin_secret', $result['admin_secret'] );
                }
                Eshopeo_Webhooks::register_webhooks( get_option( 'eshopeo_api_url' ), $result['tenant_id'] );
            } else {
                wp_safe_redirect( admin_url( 'admin.php?page=eshopeo-connector&connect_error=' . urlencode( $result->get_error_message() ) ) );
                exit;
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=eshopeo-connector&saved=1' ) );
        exit;
    }

    public static function ajax_run_sync(): void {
        check_ajax_referer( 'eshopeo_run_sync', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $result = Eshopeo_Sync::run_full_sync();

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        wp_send_json_success( [
            'synced'  => $result['synced'] ?? 0,
            'failed'  => $result['failed'] ?? 0,
            'errors'  => $result['errors'] ?? [],
            'last_sync' => get_option( 'eshopeo_last_sync', '' ),
        ] );
    }

    public static function ajax_save_registration(): void {
        check_ajax_referer( 'eshopeo_save_registration', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        update_option( 'eshopeo_api_url',      esc_url_raw( $_POST['eshopeo_api_url'] ?? '' ) );
        update_option( 'eshopeo_tenant_id',    sanitize_text_field( $_POST['eshopeo_tenant_id'] ?? '' ) );
        update_option( 'eshopeo_public_key',   sanitize_text_field( $_POST['eshopeo_public_key'] ?? '' ) );
        update_option( 'eshopeo_admin_secret', sanitize_text_field( $_POST['eshopeo_admin_secret'] ?? '' ) );
        update_option( 'eshopeo_pack_id',      sanitize_text_field( $_POST['eshopeo_pack_id'] ?? 'kbeauty' ) );

        wp_send_json_success();
    }

    public static function ajax_save_attr_types(): void {
        check_ajax_referer( 'eshopeo_save_attr_types', 'eshopeo_attr_types_nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $raw = sanitize_textarea_field( wp_unslash( $_POST['eshopeo_custom_attr_types'] ?? '' ) );

        if ( $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( ! is_array( $decoded ) ) {
                wp_send_json_error( 'Invalid JSON — must be an object mapping slug to type.' );
            }
        }

        update_option( 'eshopeo_custom_attr_types', $raw );
        wp_send_json_success();
    }

    public static function render_settings_page(): void {
        $tenant_id  = get_option( 'eshopeo_tenant_id', '' );
        $last_sync  = get_option( 'eshopeo_last_sync', 'Never' );
        $sync_count = get_option( 'eshopeo_synced_count', 0 );
        $connected  = ! empty( $tenant_id );
        ?>
        <style>
        /* ── eShopeo Admin Settings — shared reset & layout ── */
        .eshopeo-page *,
        .eshopeo-page *::before,
        .eshopeo-page *::after { box-sizing: border-box; }

        .eshopeo-page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px 20px 60px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #374151;
            background: #f9fafb;
        }

        /* ── Page header ── */
        .eshopeo-page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 28px;
        }
        .eshopeo-page-header h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
            color: #1e1b4b;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .eshopeo-badge {
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
        .eshopeo-status-bar {
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
        .eshopeo-status-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #374151;
        }
        .eshopeo-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .eshopeo-dot.green  { background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.15); }
        .eshopeo-dot.red    { background: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,.15); }
        .eshopeo-dot.amber  { background: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,.15); }
        .eshopeo-status-item strong { color: #1e1b4b; }
        .eshopeo-status-divider { width: 1px; height: 20px; background: #e5e7eb; }

        /* ── Notices ── */
        .eshopeo-notice {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            line-height: 1.5;
        }
        .eshopeo-notice.success { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; }
        .eshopeo-notice.error   { background: #fef2f2; border: 1px solid #fca5a5; color: #7f1d1d; }

        /* ── Section cards ── */
        .eshopeo-section {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.07);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .eshopeo-section-head {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 16px 24px;
            border-bottom: 1px solid #e5e7eb;
            background: #fafafa;
        }
        .eshopeo-section-head h2 {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            color: #1e1b4b;
            border-left: 3px solid #6366f1;
            padding-left: 10px;
        }
        .eshopeo-section-body {
            padding: 24px;
        }

        /* ── Form grid ── */
        .eshopeo-fields {
            display: grid;
            grid-template-columns: 220px 1fr;
            gap: 0;
        }
        .eshopeo-field-row {
            display: contents;
        }
        .eshopeo-field-row > .eshopeo-field-label,
        .eshopeo-field-row > .eshopeo-field-control {
            padding: 14px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .eshopeo-field-row:last-child > * {
            border-bottom: none;
        }
        .eshopeo-field-label {
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            padding-right: 20px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .eshopeo-field-label .eshopeo-helper {
            font-size: 11px;
            font-weight: 400;
            color: #9ca3af;
            margin-top: 2px;
        }
        .eshopeo-field-control {
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        /* ── Inputs ── */
        .eshopeo-input,
        .eshopeo-select,
        .eshopeo-textarea {
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
        .eshopeo-input:focus,
        .eshopeo-select:focus,
        .eshopeo-textarea:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99,102,241,.15);
        }
        .eshopeo-textarea {
            resize: vertical;
            min-height: 80px;
        }
        .eshopeo-input.wide,
        .eshopeo-textarea.wide {
            max-width: 600px;
        }
        .eshopeo-field-desc {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
            line-height: 1.5;
        }
        .eshopeo-field-desc code {
            font-size: 11px;
            background: #f3f4f6;
            padding: 1px 4px;
            border-radius: 3px;
        }

        /* ── Toggle switch ── */
        .eshopeo-toggle-wrap {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .eshopeo-toggle {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 22px;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .eshopeo-toggle input { opacity: 0; width: 0; height: 0; }
        .eshopeo-toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: #d1d5db;
            border-radius: 22px;
            transition: background .2s;
        }
        .eshopeo-toggle-slider::before {
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
        .eshopeo-toggle input:checked + .eshopeo-toggle-slider { background: #6366f1; }
        .eshopeo-toggle input:checked + .eshopeo-toggle-slider::before { transform: translateX(18px); }
        .eshopeo-toggle-text {
            font-size: 13px;
            color: #374151;
            line-height: 1.5;
        }
        .eshopeo-toggle-text span {
            display: block;
            font-size: 12px;
            color: #6b7280;
            margin-top: 2px;
        }

        /* ── Buttons ── */
        .eshopeo-btn {
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
        .eshopeo-btn-primary {
            background: #6366f1;
            color: #fff;
        }
        .eshopeo-btn-primary:hover { background: #4f46e5; color: #fff; }
        .eshopeo-btn-secondary {
            background: #fff;
            color: #374151;
            border: 1px solid #d1d5db;
        }
        .eshopeo-btn-secondary:hover { background: #f9fafb; border-color: #9ca3af; }
        .eshopeo-btn-success {
            background: #10b981;
            color: #fff;
        }
        .eshopeo-btn-success:hover { background: #059669; color: #fff; }
        .eshopeo-btn:disabled { opacity: .55; cursor: not-allowed; }

        /* ── Form actions bar ── */
        .eshopeo-form-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 24px;
            border-top: 1px solid #e5e7eb;
            background: #fafafa;
        }

        /* ── Sync section ── */
        .eshopeo-sync-row {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .eshopeo-spinner {
            width: 18px;
            height: 18px;
            border: 2px solid #e5e7eb;
            border-top-color: #6366f1;
            border-radius: 50%;
            animation: eshopeo-spin .7s linear infinite;
            display: none;
            flex-shrink: 0;
        }
        @keyframes eshopeo-spin { to { transform: rotate(360deg); } }

        .eshopeo-sync-result {
            margin-top: 16px;
        }
        .eshopeo-sync-result-inner {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.6;
        }
        .eshopeo-sync-result-inner.success { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; }
        .eshopeo-sync-result-inner.error   { background: #fef2f2; border: 1px solid #fca5a5; color: #7f1d1d; }

        /* ── Leads table ── */
        .eshopeo-table-wrap { overflow-x: auto; }
        .eshopeo-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .eshopeo-table thead th {
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
        .eshopeo-table tbody td {
            padding: 12px 14px;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
            vertical-align: middle;
        }
        .eshopeo-table tbody tr:last-child td { border-bottom: none; }
        .eshopeo-table tbody tr:nth-child(even) td { background: #f9fafb; }
        .eshopeo-table-empty {
            text-align: center;
            padding: 48px 20px;
            color: #9ca3af;
            font-size: 13px;
        }
        .eshopeo-date-cell { white-space: nowrap; color: #6b7280; font-size: 12px; }

        /* ── Pagination ── */
        .eshopeo-pager {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 16px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        .eshopeo-pager a,
        .eshopeo-pager span {
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
        .eshopeo-pager a:hover { background: #f5f3ff; border-color: #6366f1; color: #6366f1; }
        .eshopeo-pager span.current { background: #6366f1; border-color: #6366f1; color: #fff; }

        /* ── Responsive ── */
        @media (max-width: 782px) {
            .eshopeo-page { padding: 16px 12px 40px; }
            .eshopeo-page-header { flex-direction: column; align-items: flex-start; }
            .eshopeo-fields { grid-template-columns: 1fr; }
            .eshopeo-field-row > .eshopeo-field-label { padding-bottom: 4px; border-bottom: none; }
            .eshopeo-field-row > .eshopeo-field-control { padding-top: 0; }
            .eshopeo-input, .eshopeo-select, .eshopeo-textarea { max-width: 100%; }
            .eshopeo-status-bar { gap: 12px; flex-direction: column; align-items: flex-start; }
            .eshopeo-status-divider { display: none; }
            .eshopeo-form-actions { flex-direction: column; align-items: stretch; }
            .eshopeo-btn { justify-content: center; }
        }
        @media (max-width: 480px) {
            .eshopeo-section-head { padding: 12px 16px; }
            .eshopeo-section-body { padding: 16px; }
            .eshopeo-pager { justify-content: center; }
        }

        /* ── Industry pack selector ── */
        .eshopeo-pack-selector{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:8px}
        @media(max-width:782px){.eshopeo-pack-selector{grid-template-columns:1fr}}
        .eshopeo-pack-card{display:flex;flex-direction:column;align-items:center;text-align:center;padding:20px 16px;border:2px solid #e5e7eb;border-radius:10px;cursor:pointer;transition:all .15s;position:relative;border-bottom:4px solid var(--pack-color)}
        .eshopeo-pack-card:hover{border-color:#6366f1;background:#f5f3ff}
        .eshopeo-pack-card--active{border-color:#6366f1;background:#eef2ff}
        .eshopeo-pack-radio{position:absolute;opacity:0;pointer-events:none}
        .eshopeo-pack-icon{font-size:32px;margin-bottom:8px;display:block}
        .eshopeo-pack-name{font-size:14px;font-weight:600;color:#1e1b4b;margin-bottom:4px;display:block}
        .eshopeo-pack-desc{font-size:12px;color:#6b7280;display:block}
        .eshopeo-pack-check{position:absolute;top:8px;right:8px;width:20px;height:20px;background:#6366f1;color:white;border-radius:50%;font-size:11px;display:none;align-items:center;justify-content:center}
        .eshopeo-pack-card--active .eshopeo-pack-check{display:flex}
        </style>

        <script>
        document.querySelectorAll('.eshopeo-pack-radio').forEach(function(r){
            r.addEventListener('change',function(){
                document.querySelectorAll('.eshopeo-pack-card').forEach(function(c){c.classList.remove('eshopeo-pack-card--active')});
                this.closest('.eshopeo-pack-card').classList.add('eshopeo-pack-card--active');
            });
        });
        </script>

        <div class="eshopeo-page">

            <!-- Page header -->
            <div class="eshopeo-page-header">
                <h1>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <circle cx="12" cy="12" r="10" fill="#6366f1" opacity=".12"/>
                        <path d="M8 12.5v-5l6 4.5-6 4.5v-4z" fill="#6366f1"/>
                    </svg>
                    eShopeo Connector
                    <span class="eshopeo-badge">eShopeo</span>
                    <?php
                    $pack = Eshopeo_Pack::config();
                    echo '<span class="eshopeo-industry-badge" style="background:' . esc_attr( $pack['color'] ) . '20;color:' . esc_attr( $pack['color'] ) . ';border:1px solid ' . esc_attr( $pack['color'] ) . '40;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:500;margin-left:10px;">';
                    echo esc_html( $pack['icon'] . ' ' . $pack['label'] );
                    echo '</span>';
                    ?>
                </h1>
            </div>

            <!-- Notices -->
            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="eshopeo-notice success">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    Settings saved and store connected successfully.
                </div>
            <?php endif; ?>
            <?php if ( isset( $_GET['connect_error'] ) ) : ?>
                <div class="eshopeo-notice error">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="#ef4444" stroke-width="2"/><path stroke="#ef4444" stroke-width="2" stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>
                    Connection failed: <?php echo esc_html( urldecode( $_GET['connect_error'] ) ); ?>
                </div>
            <?php endif; ?>

            <!-- Status bar -->
            <div class="eshopeo-status-bar">
                <div class="eshopeo-status-item">
                    <div class="eshopeo-dot <?php echo $connected ? 'green' : 'red'; ?>"></div>
                    <?php if ( $connected ) : ?>
                        <strong>Connected</strong> &mdash; tenant: <code style="font-size:11px;background:#f3f4f6;padding:1px 5px;border-radius:3px;"><?php echo esc_html( $tenant_id ); ?></code>
                    <?php else : ?>
                        <strong>Not connected</strong> &mdash; fill in the fields below and save to connect
                    <?php endif; ?>
                </div>
                <?php if ( $connected ) : ?>
                <div class="eshopeo-status-divider"></div>
                <div class="eshopeo-status-item">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path stroke="#6b7280" stroke-width="2" stroke-linecap="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Last sync: <strong id="eshopeo-last-sync"><?php echo esc_html( $last_sync ); ?></strong>
                </div>
                <div class="eshopeo-status-divider"></div>
                <div class="eshopeo-status-item">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path stroke="#6b7280" stroke-width="2" stroke-linecap="round" d="M20 7H4a1 1 0 00-1 1v8a1 1 0 001 1h16a1 1 0 001-1V8a1 1 0 00-1-1z"/></svg>
                    <strong id="eshopeo-sync-count"><?php echo esc_html( $sync_count ); ?></strong> <?php echo esc_html( strtolower( Eshopeo_Pack::config()['sync_noun'] ) ); ?>
                </div>
                <div class="eshopeo-status-divider"></div>
                <div class="eshopeo-status-item" id="eshopeo-billing-status-item">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path stroke="#6b7280" stroke-width="2" stroke-linecap="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    Plan: <strong id="eshopeo-plan-badge" style="color:#7c3aed;">Loading…</strong>
                </div>
                <?php endif; ?>
            </div>

            <?php if ( $connected ) : ?>
            <!-- Trial / subscription banner — populated via JS after billing status fetch -->
            <div id="eshopeo-trial-banner" style="display:none;margin:12px 0;padding:12px 16px;border-radius:8px;font-size:13px;display:flex;align-items:center;justify-content:space-between;gap:12px;"></div>
            <?php endif; ?>

            <!-- Settings form -->
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'eshopeo_save_settings' ); ?>
                <input type="hidden" name="action" value="eshopeo_save_settings">

                <!-- Connection section -->
                <div class="eshopeo-section">
                    <div class="eshopeo-section-head">
                        <h2>Connection &amp; Industry</h2>
                    </div>
                    <div class="eshopeo-section-body">
                        <div class="eshopeo-fields">
                            <div class="eshopeo-field-row">
                                <div class="eshopeo-field-label">
                                    Industry / Pack
                                    <span class="eshopeo-helper">Controls AI schema &amp; behaviour</span>
                                </div>
                                <div class="eshopeo-field-control">
                                    <?php $current_pack = get_option( 'eshopeo_pack_id', 'kbeauty' ); ?>
                                    <div class="eshopeo-pack-selector">
                                        <?php foreach ( Eshopeo_Pack::all() as $id => $pack ) : ?>
                                        <label class="eshopeo-pack-card <?php echo $current_pack === $id ? 'eshopeo-pack-card--active' : ''; ?>"
                                               style="--pack-color: <?php echo esc_attr( $pack['color'] ); ?>">
                                            <input type="radio" name="eshopeo_pack_id" value="<?php echo esc_attr( $id ); ?>"
                                                   <?php checked( $current_pack, $id ); ?> class="eshopeo-pack-radio">
                                            <span class="eshopeo-pack-icon"><?php echo $pack['icon']; ?></span>
                                            <span class="eshopeo-pack-name"><?php echo esc_html( $pack['label'] ); ?></span>
                                            <span class="eshopeo-pack-desc"><?php echo esc_html( $pack['description'] ); ?></span>
                                            <span class="eshopeo-pack-check">&#10003;</span>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="eshopeo-field-desc">Determines which product schema and AI behaviour your store uses. Changing this after connecting will update the backend immediately.</p>
                                </div>
                            </div>
                            <div class="eshopeo-field-row">
                                <div class="eshopeo-field-label">
                                    API URL
                                    <span class="eshopeo-helper">eShopeo backend endpoint</span>
                                </div>
                                <div class="eshopeo-field-control">
                                    <input type="url" name="eshopeo_api_url" value="<?php echo esc_attr( get_option( 'eshopeo_api_url' ) ); ?>" class="eshopeo-input wide" placeholder="https://api.eshopeo.com">
                                </div>
                            </div>
                            <?php if ( ! $connected ) : ?>
                            <!-- Self-service registration wizard (shown when not yet connected) -->
                            <div class="eshopeo-field-row" id="eshopeo-register-row">
                                <div class="eshopeo-field-label">
                                    Get Started
                                    <span class="eshopeo-helper">Create your free eShopeo account</span>
                                </div>
                                <div class="eshopeo-field-control">
                                    <div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:10px;padding:20px;">
                                        <p style="margin:0 0 14px;font-size:13px;color:#5b21b6;font-weight:600;">Start your 14-day free trial — no credit card required.</p>
                                        <div style="display:flex;flex-direction:column;gap:10px;">
                                            <input type="email" id="eshopeo-reg-email" class="eshopeo-input wide" placeholder="your@email.com" style="border-color:#c4b5fd;">
                                            <input type="text" id="eshopeo-reg-name" class="eshopeo-input wide" placeholder="Store name" style="border-color:#c4b5fd;">
                                        </div>
                                        <button type="button" id="eshopeo-register-btn" class="eshopeo-btn" style="margin-top:14px;background:#7c3aed;color:#fff;width:100%;justify-content:center;">
                                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                            Create Free Account &amp; Connect
                                        </button>
                                        <div class="eshopeo-spinner" id="eshopeo-register-spinner" style="display:none;margin-top:10px;"></div>
                                        <div id="eshopeo-register-error" style="display:none;margin-top:10px;font-size:12px;color:#dc2626;background:#fef2f2;padding:8px 12px;border-radius:6px;"></div>
                                        <p style="margin:12px 0 0;font-size:11px;color:#7c3aed;text-align:center;">
                                            Already have an account? Enter your API URL below and use the manual provision key field.
                                        </p>
                                    </div>
                                    <details style="margin-top:12px;">
                                        <summary style="font-size:12px;color:#6b7280;cursor:pointer;">Manual provision key (advanced)</summary>
                                        <div style="margin-top:8px;">
                                            <input type="password" name="eshopeo_provision_key" value="<?php echo esc_attr( get_option( 'eshopeo_provision_key' ) ); ?>" class="eshopeo-input wide" placeholder="Provision key from eShopeo dashboard">
                                        </div>
                                    </details>
                                </div>
                            </div>
                            <?php else : ?>
                            <input type="hidden" name="eshopeo_provision_key" value="<?php echo esc_attr( get_option( 'eshopeo_provision_key' ) ); ?>">
                            <?php endif; ?>
                            <div class="eshopeo-field-row">
                                <div class="eshopeo-field-label">
                                    WC Consumer Key
                                </div>
                                <div class="eshopeo-field-control">
                                    <input type="text" name="eshopeo_consumer_key" value="<?php echo esc_attr( get_option( 'eshopeo_consumer_key' ) ); ?>" class="eshopeo-input wide">
                                </div>
                            </div>
                            <div class="eshopeo-field-row">
                                <div class="eshopeo-field-label">
                                    WC Consumer Secret
                                </div>
                                <div class="eshopeo-field-control">
                                    <input type="password" name="eshopeo_consumer_secret" value="" class="eshopeo-input" placeholder="(leave blank to keep unchanged)">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Widget section -->
                <div class="eshopeo-section">
                    <div class="eshopeo-section-head">
                        <h2>Widget</h2>
                    </div>
                    <div class="eshopeo-section-body">
                        <div class="eshopeo-fields">
                            <div class="eshopeo-field-row">
                                <div class="eshopeo-field-label">Auto-inject widget</div>
                                <div class="eshopeo-field-control">
                                    <div class="eshopeo-toggle-wrap">
                                        <label class="eshopeo-toggle">
                                            <input type="checkbox" name="eshopeo_widget_enabled" value="1" <?php checked( get_option( 'eshopeo_widget_enabled', 0 ), 1 ); ?>>
                                            <span class="eshopeo-toggle-slider"></span>
                                        </label>
                                        <div class="eshopeo-toggle-text">
                                            Automatically embed the eShopeo chat widget
                                            <span>Adds the widget script to all frontend pages</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Animations section -->
                <div class="eshopeo-section">
                    <div class="eshopeo-section-head">
                        <h2>Search Bar Animations</h2>
                    </div>
                    <div class="eshopeo-section-body">
                        <div class="eshopeo-fields">
                            <div class="eshopeo-field-row">
                                <div class="eshopeo-field-label">Card fly-in animation</div>
                                <div class="eshopeo-field-control">
                                    <div class="eshopeo-toggle-wrap">
                                        <label class="eshopeo-toggle">
                                            <input type="checkbox" name="eshopeo_sb_animations" value="1" <?php checked( get_option( 'eshopeo_sb_animations', 1 ), 1 ); ?>>
                                            <span class="eshopeo-toggle-slider"></span>
                                        </label>
                                        <div class="eshopeo-toggle-text">
                                            Product card fly-in (rainbow sparkle effect)
                                            <span>Animate product cards flying in from the search bar</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="eshopeo-field-row">
                                <div class="eshopeo-field-label">Fly-to-cart animation</div>
                                <div class="eshopeo-field-control">
                                    <div class="eshopeo-toggle-wrap">
                                        <label class="eshopeo-toggle">
                                            <input type="checkbox" name="eshopeo_sb_fly_to_cart" value="1" <?php checked( get_option( 'eshopeo_sb_fly_to_cart', 1 ), 1 ); ?>>
                                            <span class="eshopeo-toggle-slider"></span>
                                        </label>
                                        <div class="eshopeo-toggle-text">
                                            Fly-to-cart on add to cart
                                            <span>Animate product thumbnail flying to the cart icon</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="eshopeo-field-row">
                                <div class="eshopeo-field-label">Product detail modal</div>
                                <div class="eshopeo-field-control">
                                    <div class="eshopeo-toggle-wrap">
                                        <label class="eshopeo-toggle">
                                            <input type="checkbox" name="eshopeo_sb_card_modal" value="1" <?php checked( get_option( 'eshopeo_sb_card_modal', 1 ), 1 ); ?>>
                                            <span class="eshopeo-toggle-slider"></span>
                                        </label>
                                        <div class="eshopeo-toggle-text">
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
                <div class="eshopeo-section">
                    <div class="eshopeo-section-head">
                        <h2>Lead Capture</h2>
                    </div>
                    <div class="eshopeo-section-body">
                        <div class="eshopeo-fields">
                            <div class="eshopeo-field-row">
                                <div class="eshopeo-field-label">
                                    Lead webhook URL
                                    <span class="eshopeo-helper">Optional CRM / automation hook</span>
                                </div>
                                <div class="eshopeo-field-control">
                                    <input type="url" name="eshopeo_lead_webhook_url" value="<?php echo esc_attr( get_option( 'eshopeo_lead_webhook_url', '' ) ); ?>" class="eshopeo-input wide" placeholder="https://hook.make.com/...">
                                    <p class="eshopeo-field-desc">POST new enquiries here (Zapier, Make.com, CRM). Leave blank to disable webhooks.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- WP Agent credentials section -->
                <div class="eshopeo-section">
                    <div class="eshopeo-section-head">
                        <h2>AI Assistant — WordPress Credentials</h2>
                    </div>
                    <div class="eshopeo-section-body">
                        <p style="font-size:13px;color:#6b7280;margin:0 0 16px;">Required for the AI Assistant to create and edit posts, pages, and media. Uses a WordPress <strong>Application Password</strong> — no plugin needed.</p>
                        <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:12px 14px;margin-bottom:16px;font-size:12px;color:#92400e;">
                            <strong>How to generate an Application Password:</strong> Go to <em>Users → Profile</em>, scroll to <em>Application Passwords</em>, enter "eShopeo" as the name, click <em>Add New Application Password</em>, and copy the generated password here.
                        </div>
                        <div class="eshopeo-fields">
                            <div class="eshopeo-field-row">
                                <div class="eshopeo-field-label">
                                    WordPress Username
                                    <span class="eshopeo-helper">Must be an Administrator or Editor</span>
                                </div>
                                <div class="eshopeo-field-control">
                                    <input type="text" name="eshopeo_wp_api_user" value="<?php echo esc_attr( get_option( 'eshopeo_wp_api_user', '' ) ); ?>" class="eshopeo-input wide" placeholder="admin">
                                </div>
                            </div>
                            <div class="eshopeo-field-row">
                                <div class="eshopeo-field-label">
                                    Application Password
                                    <span class="eshopeo-helper">Generated from WP → Users → Profile</span>
                                </div>
                                <div class="eshopeo-field-control">
                                    <input type="password" name="eshopeo_wp_app_password" value="<?php echo esc_attr( get_option( 'eshopeo_wp_app_password', '' ) ); ?>" class="eshopeo-input wide" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx">
                                    <p class="eshopeo-field-desc">Stored securely. Used only for AI Agent → WordPress REST API calls.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- WhatsApp section -->
                <div class="eshopeo-section">
                    <div class="eshopeo-section-head">
                        <h2>WhatsApp Button</h2>
                    </div>
                    <div class="eshopeo-section-body">
                        <div class="eshopeo-fields">
                            <div class="eshopeo-field-row">
                                <div class="eshopeo-field-label">Enable WhatsApp button</div>
                                <div class="eshopeo-field-control">
                                    <div class="eshopeo-toggle-wrap">
                                        <label class="eshopeo-toggle">
                                            <input type="checkbox" name="eshopeo_wa_enabled" value="1" <?php checked( get_option( 'eshopeo_wa_enabled', 0 ), 1 ); ?>>
                                            <span class="eshopeo-toggle-slider"></span>
                                        </label>
                                        <div class="eshopeo-toggle-text">
                                            Show a WhatsApp chat button inside the AI widget
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="eshopeo-field-row">
                                <div class="eshopeo-field-label">
                                    WhatsApp number
                                    <span class="eshopeo-helper">International format, no spaces</span>
                                </div>
                                <div class="eshopeo-field-control">
                                    <input type="text" name="eshopeo_wa_number" value="<?php echo esc_attr( get_option( 'eshopeo_wa_number', '' ) ); ?>" class="eshopeo-input" placeholder="27831234567">
                                    <p class="eshopeo-field-desc">International format without + sign or spaces. e.g. <code>27831234567</code></p>
                                </div>
                            </div>
                            <div class="eshopeo-field-row">
                                <div class="eshopeo-field-label">Pre-filled message</div>
                                <div class="eshopeo-field-control">
                                    <textarea name="eshopeo_wa_message" rows="3" class="eshopeo-textarea wide"><?php echo esc_textarea( get_option( 'eshopeo_wa_message', "Hi! I'd like some skincare advice." ) ); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="eshopeo-form-actions">
                        <button type="submit" class="eshopeo-btn eshopeo-btn-primary">
                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            <?php echo $connected ? 'Save Settings' : 'Save &amp; Connect'; ?>
                        </button>
                        <?php if ( $connected ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=eshopeo-branding' ) ); ?>" class="eshopeo-btn eshopeo-btn-secondary">Branding &rarr;</a>
                        <?php endif; ?>
                    </div>
                </div>

            </form>

            <?php if ( $connected ) : ?>
            <!-- Custom Attribute Types section -->
            <div class="eshopeo-section">
                <div class="eshopeo-section-head">
                    <h2>Custom Attribute Types</h2>
                </div>
                <div class="eshopeo-section-body">
                    <p style="font-size:13px;color:#6b7280;margin:0 0 12px;">
                        Map custom WooCommerce product attributes to their data type so eShopeo can embed them correctly.
                        Enter a JSON object mapping attribute slug (without <code>pa_</code>) to one of:
                        <code>scalar</code>, <code>integer</code>, <code>number</code>, <code>boolean</code>, <code>array</code>.
                        After saving, re-run a catalog sync to re-embed affected products.
                    </p>
                    <form method="post" action="" id="eshopeo-attr-types-form">
                        <?php wp_nonce_field( 'eshopeo_save_attr_types', 'eshopeo_attr_types_nonce' ); ?>
                        <textarea
                            name="eshopeo_custom_attr_types"
                            rows="5"
                            class="eshopeo-input wide"
                            style="font-family:monospace;font-size:12px;resize:vertical;"
                            placeholder='{"material": "scalar", "weight_kg": "number", "age_group": "array", "is_organic": "boolean"}'
                        ><?php echo esc_textarea( get_option( 'eshopeo_custom_attr_types', '' ) ); ?></textarea>
                        <p style="font-size:11px;color:#9ca3af;margin:6px 0 12px;">
                            Built-in types (kbeauty/automotive packs) are already handled — only add attributes unique to your store here.
                        </p>
                        <button type="submit" class="eshopeo-btn">Save Attribute Types</button>
                        <span id="eshopeo-attr-save-msg" style="margin-left:10px;font-size:12px;color:#059669;display:none;">Saved!</span>
                    </form>
                    <script>
                    document.getElementById('eshopeo-attr-types-form').addEventListener('submit', function(e) {
                        e.preventDefault();
                        var raw = this.querySelector('[name=eshopeo_custom_attr_types]').value.trim();
                        if (raw) {
                            try { JSON.parse(raw); } catch(err) {
                                alert('Invalid JSON: ' + err.message);
                                return;
                            }
                        }
                        var data = new FormData(this);
                        data.append('action', 'eshopeo_save_attr_types');
                        fetch(ajaxurl, { method: 'POST', body: data })
                            .then(function(r) { return r.json(); })
                            .then(function(d) {
                                if (d.success) {
                                    var msg = document.getElementById('eshopeo-attr-save-msg');
                                    msg.style.display = 'inline';
                                    setTimeout(function() { msg.style.display = 'none'; }, 3000);
                                } else {
                                    alert(d.data || 'Save failed.');
                                }
                            })
                            .catch(function(err) { alert('Request failed: ' + err.message); });
                    });
                    </script>
                </div>
            </div>

            <!-- Catalog Sync section -->
            <div class="eshopeo-section">
                <div class="eshopeo-section-head">
                    <h2>Catalog Sync</h2>
                </div>
                <div class="eshopeo-section-body">
                    <p style="font-size:13px;color:#6b7280;margin:0 0 16px;">Push your WooCommerce product catalog to eShopeo. This runs automatically via cron, but you can trigger it manually here.</p>
                    <div class="eshopeo-sync-row">
                        <button id="eshopeo-sync-btn" class="eshopeo-btn eshopeo-btn-success">
                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Sync Catalog Now
                        </button>
                        <div class="eshopeo-spinner" id="eshopeo-sync-spinner"></div>
                    </div>
                    <div class="eshopeo-sync-result" id="eshopeo-sync-result" style="display:none;"></div>
                </div>
            </div>

            <script>
            document.getElementById('eshopeo-sync-btn').addEventListener('click', function () {
                var btn     = this;
                var spinner = document.getElementById('eshopeo-sync-spinner');
                var result  = document.getElementById('eshopeo-sync-result');

                btn.disabled             = true;
                spinner.style.display    = 'block';
                result.style.display     = 'none';
                result.innerHTML         = '';

                var data = new FormData();
                data.append('action', 'eshopeo_run_sync');
                data.append('nonce', '<?php echo esc_js( wp_create_nonce( 'eshopeo_run_sync' ) ); ?>');

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
                        var html = '<div class="eshopeo-sync-result-inner success">'
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
                            document.getElementById('eshopeo-last-sync').textContent = d.last_sync;
                            document.getElementById('eshopeo-sync-count').textContent = d.synced;
                        }
                        result.innerHTML = html;
                    } else {
                        var msg = (res.data && res.data.message) ? res.data.message : 'Unknown error.';
                        result.innerHTML = '<div class="eshopeo-sync-result-inner error"><strong>Sync failed:</strong> ' + msg.replace(/</g, '&lt;') + '</div>';
                    }
                })
                .catch(function (err) {
                    spinner.style.display = 'none';
                    btn.disabled          = false;
                    result.style.display  = 'block';
                    result.innerHTML = '<div class="eshopeo-sync-result-inner error"><strong>Request failed:</strong> ' + err.message + '</div>';
                });
            });
            </script>
            <?php endif; ?>

            <?php if ( ! $connected ) : ?>
            <!-- Registration wizard JS -->
            <script>
            (function () {
                var btn = document.getElementById('eshopeo-register-btn');
                if (!btn) return;
                btn.addEventListener('click', function () {
                    var email   = document.getElementById('eshopeo-reg-email').value.trim();
                    var name    = document.getElementById('eshopeo-reg-name').value.trim();
                    var apiUrl  = document.querySelector('[name=eshopeo_api_url]').value.trim()
                                  || 'https://eshopeo.cloudia.co.za';
                    var packId  = (document.querySelector('[name=eshopeo_pack_id]:checked') || {}).value || 'kbeauty';
                    var errBox  = document.getElementById('eshopeo-register-error');
                    var spinner = document.getElementById('eshopeo-register-spinner');

                    errBox.style.display = 'none';
                    if (!email || !name) { errBox.textContent = 'Please enter your email and store name.'; errBox.style.display = 'block'; return; }
                    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { errBox.textContent = 'Please enter a valid email address.'; errBox.style.display = 'block'; return; }

                    btn.disabled = true;
                    spinner.style.display = 'block';

                    fetch(apiUrl.replace(/\/$/, '') + '/v1/auth/register', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            email: email,
                            store_name: name,
                            store_url: '<?php echo esc_js( get_site_url() ); ?>',
                            platform: 'woocommerce',
                            pack_id: packId
                        })
                    })
                    .then(function (r) { return r.json().then(function(d) { return {ok: r.ok, data: d}; }); })
                    .then(function (res) {
                        spinner.style.display = 'none';
                        if (!res.ok) {
                            var msg = (res.data.detail || JSON.stringify(res.data));
                            errBox.textContent = 'Registration failed: ' + msg;
                            errBox.style.display = 'block';
                            btn.disabled = false;
                            return;
                        }
                        var d = res.data;
                        // Save credentials via AJAX then reload.
                        var fd = new FormData();
                        fd.append('action', 'eshopeo_save_registration');
                        fd.append('nonce', '<?php echo esc_js( wp_create_nonce( 'eshopeo_save_registration' ) ); ?>');
                        fd.append('eshopeo_api_url',     apiUrl);
                        fd.append('eshopeo_tenant_id',   d.tenant_id);
                        fd.append('eshopeo_public_key',  d.public_key);
                        fd.append('eshopeo_admin_secret', d.admin_secret);
                        fd.append('eshopeo_pack_id',     packId);
                        fetch(ajaxurl, {method: 'POST', body: fd})
                            .then(function () {
                                window.location = window.location.href.split('?')[0] + '?page=eshopeo-connector&saved=1';
                            });
                    })
                    .catch(function (err) {
                        spinner.style.display = 'none';
                        btn.disabled = false;
                        errBox.textContent = 'Request failed: ' + err.message;
                        errBox.style.display = 'block';
                    });
                });
            })();
            </script>
            <?php endif; ?>

            <?php if ( $connected ) : ?>
            <!-- Billing status + trial banner JS -->
            <script>
            (function () {
                var apiUrl = '<?php echo esc_js( get_option( 'eshopeo_api_url', 'https://eshopeo.cloudia.co.za' ) ); ?>';
                var publicKey = '<?php echo esc_js( get_option( 'eshopeo_public_key', '' ) ); ?>';
                if (!publicKey) return;

                fetch(apiUrl.replace(/\/$/, '') + '/v1/billing/status', {
                    headers: {'Authorization': 'Bearer ' + publicKey}
                })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (!d) return;
                    var badge = document.getElementById('eshopeo-plan-badge');
                    if (badge) badge.textContent = d.plan_display_name + ' · ' + d.subscription_status;

                    var banner = document.getElementById('eshopeo-trial-banner');
                    if (!banner) return;

                    if (d.subscription_status === 'trialing' && d.days_remaining !== null) {
                        var urgent = d.days_remaining <= 3;
                        banner.style.cssText = 'display:flex;background:' + (urgent ? '#fef3c7' : '#f5f3ff') + ';border:1px solid ' + (urgent ? '#fcd34d' : '#ddd6fe') + ';border-radius:8px;padding:12px 16px;margin:12px 0;font-size:13px;align-items:center;justify-content:space-between;gap:12px;';
                        banner.innerHTML = '<span>' + (urgent ? '⚠️' : '🎯') + ' <strong>' + d.days_remaining + ' day' + (d.days_remaining !== 1 ? 's' : '') + ' left</strong> in your free trial.</span>'
                            + '<a href="' + apiUrl.replace(/\/$/, '') + '/billing?key=' + publicKey + '" target="_blank" style="background:#7c3aed;color:#fff;padding:6px 14px;border-radius:6px;text-decoration:none;font-weight:600;font-size:12px;white-space:nowrap;">Upgrade Now</a>';
                    } else if (d.subscription_status === 'trial_expired' || (d.subscription_status === 'trialing' && d.days_remaining === 0)) {
                        banner.style.cssText = 'display:flex;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px 16px;margin:12px 0;font-size:13px;align-items:center;justify-content:space-between;gap:12px;';
                        banner.innerHTML = '<span>🔴 <strong>Your trial has ended.</strong> AI features are paused until you subscribe.</span>'
                            + '<a href="' + apiUrl.replace(/\/$/, '') + '/billing?key=' + publicKey + '" target="_blank" style="background:#dc2626;color:#fff;padding:6px 14px;border-radius:6px;text-decoration:none;font-weight:600;font-size:12px;white-space:nowrap;">Subscribe Now</a>';
                    } else if (d.subscription_status === 'paused') {
                        banner.style.cssText = 'display:flex;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px 16px;margin:12px 0;font-size:13px;align-items:center;justify-content:space-between;gap:12px;';
                        banner.innerHTML = '<span>⚠️ <strong>Payment failed.</strong> Update your payment method to restore access.</span>'
                            + '<a href="' + apiUrl.replace(/\/$/, '') + '/billing/portal?key=' + publicKey + '" target="_blank" style="background:#dc2626;color:#fff;padding:6px 14px;border-radius:6px;text-decoration:none;font-weight:600;font-size:12px;white-space:nowrap;">Fix Payment</a>';
                    }
                })
                .catch(function () {});
            })();
            </script>
            <?php endif; ?>

        </div><!-- .eshopeo-page -->
        <?php
    }

    public static function render_leads_page(): void {
        $tenant_id = get_option( 'eshopeo_tenant_id', '' );
        if ( ! $tenant_id ) {
            ?>
            <style>
            .eshopeo-page *,.eshopeo-page *::before,.eshopeo-page *::after{box-sizing:border-box;}
            .eshopeo-page{max-width:1200px;margin:0 auto;padding:24px 20px 60px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#374151;background:#f9fafb;}
            .eshopeo-page-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:28px;}
            .eshopeo-page-header h1{margin:0;font-size:22px;font-weight:700;color:#1e1b4b;}
            .eshopeo-notice{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:13px;}
            .eshopeo-notice.warning{background:#fffbeb;border:1px solid #fcd34d;color:#78350f;}
            .eshopeo-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:6px;font-size:13px;font-weight:500;min-height:36px;border:none;cursor:pointer;text-decoration:none;}
            .eshopeo-btn-primary{background:#6366f1;color:#fff;}
            .eshopeo-btn-primary:hover{background:#4f46e5;color:#fff;}
            </style>
            <div class="eshopeo-page">
                <div class="eshopeo-page-header"><h1>eShopeo &mdash; Enquiry Leads</h1></div>
                <div class="eshopeo-notice warning">
                    Connect your store first before viewing leads.
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=eshopeo-connector' ) ); ?>" class="eshopeo-btn eshopeo-btn-primary" style="margin-left:auto;white-space:nowrap;">Go to Settings</a>
                </div>
            </div>
            <?php
            return;
        }

        $api_url      = get_option( 'eshopeo_api_url', '' );
        $public_key   = get_option( 'eshopeo_public_key', '' );
        $client       = new Eshopeo_API_Client( $api_url, $public_key );
        $page_num     = max( 1, intval( $_GET['paged'] ?? 1 ) );
        $result       = $client->get_leads( $page_num, 50 );
        $error        = is_wp_error( $result ) ? $result->get_error_message() : null;
        $leads        = $error ? [] : ( $result['leads'] ?? [] );
        $total        = $error ? 0 : ( $result['total'] ?? 0 );
        $total_pages  = $total ? (int) ceil( $total / 50 ) : 1;
        ?>
        <style>
        .eshopeo-page *,.eshopeo-page *::before,.eshopeo-page *::after{box-sizing:border-box;}
        .eshopeo-page{max-width:1200px;margin:0 auto;padding:24px 20px 60px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#374151;background:#f9fafb;}
        .eshopeo-page-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:28px;}
        .eshopeo-page-header h1{margin:0;font-size:22px;font-weight:700;color:#1e1b4b;display:flex;align-items:center;gap:10px;}
        .eshopeo-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;letter-spacing:.04em;}
        .eshopeo-total-pill{display:inline-flex;align-items:center;padding:4px 12px;background:#f3f4f6;border-radius:20px;font-size:12px;color:#6b7280;}
        .eshopeo-notice{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:13px;}
        .eshopeo-notice.error{background:#fef2f2;border:1px solid #fca5a5;color:#7f1d1d;}
        .eshopeo-section{background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.07);margin-bottom:20px;overflow:hidden;}
        .eshopeo-section-body{padding:0;}
        .eshopeo-table-wrap{overflow-x:auto;}
        .eshopeo-table{width:100%;border-collapse:collapse;font-size:13px;}
        .eshopeo-table thead th{position:sticky;top:0;background:#fff;z-index:1;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;padding:12px 16px;border-bottom:2px solid #e5e7eb;text-align:left;white-space:nowrap;}
        .eshopeo-table tbody td{padding:12px 16px;border-bottom:1px solid #f3f4f6;color:#374151;vertical-align:middle;}
        .eshopeo-table tbody tr:last-child td{border-bottom:none;}
        .eshopeo-table tbody tr:nth-child(even) td{background:#f9fafb;}
        .eshopeo-table tbody tr:hover td{background:#f5f3ff;}
        .eshopeo-table-empty{text-align:center;padding:60px 20px;color:#9ca3af;font-size:13px;}
        .eshopeo-date-cell{white-space:nowrap;color:#6b7280;font-size:12px;}
        .eshopeo-source-pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:500;background:#ede9fe;color:#6366f1;}
        .eshopeo-pager{display:flex;align-items:center;gap:6px;padding:16px;justify-content:flex-end;flex-wrap:wrap;border-top:1px solid #f3f4f6;}
        .eshopeo-pager a,.eshopeo-pager span{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 10px;border-radius:6px;font-size:12px;font-weight:500;border:1px solid #e5e7eb;color:#374151;text-decoration:none;transition:background .12s,border-color .12s;}
        .eshopeo-pager a:hover{background:#f5f3ff;border-color:#6366f1;color:#6366f1;}
        .eshopeo-pager span.current{background:#6366f1;border-color:#6366f1;color:#fff;}
        @media(max-width:782px){.eshopeo-page{padding:16px 12px 40px;}.eshopeo-page-header{flex-direction:column;align-items:flex-start;}}
        @media(max-width:480px){.eshopeo-pager{justify-content:center;}}
        </style>

        <div class="eshopeo-page">
            <div class="eshopeo-page-header">
                <h1>
                    eShopeo &mdash; Enquiry Leads
                    <span class="eshopeo-badge">eShopeo</span>
                </h1>
                <span class="eshopeo-total-pill"><?php echo esc_html( $total ); ?> total lead<?php echo $total !== 1 ? 's' : ''; ?></span>
            </div>

            <?php if ( $error ) : ?>
                <div class="eshopeo-notice error">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="#ef4444" stroke-width="2"/><path stroke="#ef4444" stroke-width="2" stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>
                    Could not fetch leads: <?php echo esc_html( $error ); ?>
                </div>
            <?php endif; ?>

            <div class="eshopeo-section">
                <div class="eshopeo-section-body">
                    <?php if ( empty( $leads ) && ! $error ) : ?>
                        <div class="eshopeo-table-empty">
                            <svg width="40" height="40" fill="none" viewBox="0 0 24 24" style="margin:0 auto 12px;display:block;"><path stroke="#d1d5db" stroke-width="1.5" stroke-linecap="round" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                            No leads yet. Once customers submit an enquiry via the eShopeo widget, they will appear here.
                        </div>
                    <?php elseif ( ! empty( $leads ) ) : ?>
                        <div class="eshopeo-table-wrap">
                            <table class="eshopeo-table">
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
                                            <td class="eshopeo-date-cell"><?php echo esc_html( wp_date( 'd M Y H:i', strtotime( $lead['created_at'] ) ) ); ?></td>
                                            <td style="font-weight:500;"><?php echo esc_html( $lead['name'] ?? '—' ); ?></td>
                                            <td><?php echo esc_html( $lead['phone'] ?? '—' ); ?></td>
                                            <td><?php echo esc_html( $lead['email'] ?? '—' ); ?></td>
                                            <td><?php echo esc_html( $lead['product_platform_id'] ?? '—' ); ?></td>
                                            <td><?php echo esc_html( $lead['preferred_contact_time'] ?? '—' ); ?></td>
                                            <td>
                                                <?php if ( ! empty( $lead['source'] ) ) : ?>
                                                    <span class="eshopeo-source-pill"><?php echo esc_html( $lead['source'] ); ?></span>
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
                            <div class="eshopeo-pager">
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

        </div><!-- .eshopeo-page -->
        <?php
    }
}
