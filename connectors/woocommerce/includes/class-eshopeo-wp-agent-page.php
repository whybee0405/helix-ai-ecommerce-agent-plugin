<?php
defined( 'ABSPATH' ) || exit;

class Eshopeo_WP_Agent_Page {

    /* ── Init ──────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'wp_ajax_eshopeo_agent_log_clear', [ __CLASS__, 'ajax_log_clear' ] );

        // Heartbeat hook — applies when eshopeo_disable_heartbeat_frontend is on.
        add_action( 'init', [ __CLASS__, 'maybe_disable_heartbeat' ] );
    }

    public static function maybe_disable_heartbeat(): void {
        if (
            ! is_admin() &&
            get_option( 'eshopeo_disable_heartbeat_frontend', 0 )
        ) {
            add_action( 'wp_enqueue_scripts', function () {
                wp_deregister_script( 'heartbeat' );
            } );
        }
    }

    /* ── Menu ──────────────────────────────────────────────────────────── */

    public static function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            'eShopeo WP Agent',
            'eShopeo WP Agent',
            'manage_options',
            'eshopeo-wp-agent',
            [ __CLASS__, 'render' ]
        );
    }

    /* ── AJAX: clear log ───────────────────────────────────────────────── */

    public static function ajax_log_clear(): void {
        check_ajax_referer( 'eshopeo_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        Eshopeo_Action_Log::clear();
        wp_send_json_success( [ 'cleared' => true ] );
    }

    /* ══════════════════════════════════════════════════════════════════════
     *  RENDER
     * ══════════════════════════════════════════════════════════════════════ */

    public static function render(): void {
        $nonce   = wp_create_nonce( 'eshopeo_wp_agent' );
        $api_url      = esc_js( get_option( 'eshopeo_api_url', '' ) );
        $pub_key      = esc_js( get_option( 'eshopeo_public_key', '' ) );
        $adm_sec      = esc_js( get_option( 'eshopeo_admin_secret', '' ) );
        $wp_api_user  = esc_js( get_option( 'eshopeo_wp_api_user', '' ) );
        $wp_app_pass  = esc_js( get_option( 'eshopeo_wp_app_password', '' ) );
        ?>
        <style>
        /* ── eShopeo WP Agent page ── */
        .hwpa-page *,.hwpa-page *::before,.hwpa-page *::after{box-sizing:border-box;}
        .hwpa-page{
            max-width:1260px;margin:0 auto;
            padding:24px 20px 80px;
            font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
            color:#374151;background:#f9fafb;
        }

        /* header */
        .hwpa-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
        .hwpa-header h1{margin:0;font-size:22px;font-weight:700;color:#1e1b4b;display:flex;align-items:center;gap:10px;}
        .hwpa-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;letter-spacing:.04em;}

        /* tabs */
        .hwpa-tabs{display:flex;gap:2px;flex-wrap:wrap;margin-bottom:20px;border-bottom:2px solid #e5e7eb;}
        .hwpa-tab{
            padding:10px 18px;font-size:13px;font-weight:500;color:#6b7280;
            border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;
            margin-bottom:-2px;transition:color .15s,border-color .15s;
        }
        .hwpa-tab:hover{color:#6366f1;}
        .hwpa-tab.active{color:#6366f1;border-bottom-color:#6366f1;font-weight:600;}

        /* panels */
        .hwpa-panel{display:none;}
        .hwpa-panel.active{display:block;}

        /* cards & grid */
        .hwpa-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.07);margin-bottom:16px;overflow:hidden;}
        .hwpa-card-head{display:flex;align-items:center;gap:10px;padding:14px 20px;border-bottom:1px solid #e5e7eb;background:#fafafa;}
        .hwpa-card-head h2{margin:0;font-size:14px;font-weight:600;color:#1e1b4b;border-left:3px solid #6366f1;padding-left:10px;}
        .hwpa-card-body{padding:20px;}

        .hwpa-metric-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:16px;}
        .hwpa-metric-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px;position:relative;}
        .hwpa-metric-val{font-size:22px;font-weight:700;color:#1e1b4b;margin-bottom:2px;}
        .hwpa-metric-lbl{font-size:12px;color:#6b7280;}
        .hwpa-grade-pill{
            position:absolute;top:12px;right:12px;
            display:inline-flex;align-items:center;justify-content:center;
            width:28px;height:28px;border-radius:50%;font-size:12px;font-weight:700;
        }
        .hwpa-grade-A{background:#d1fae5;color:#065f46;}
        .hwpa-grade-B{background:#dbeafe;color:#1e40af;}
        .hwpa-grade-C{background:#fef3c7;color:#92400e;}
        .hwpa-grade-D{background:#fed7aa;color:#9a3412;}
        .hwpa-grade-F{background:#fee2e2;color:#7f1d1d;}

        .hwpa-overall-grade{
            display:inline-flex;align-items:center;justify-content:center;
            width:72px;height:72px;border-radius:50%;font-size:36px;font-weight:800;
            margin-right:16px;flex-shrink:0;
        }

        .hwpa-metric-fix{
            display:inline-flex;align-items:center;gap:4px;
            margin-top:8px;padding:4px 10px;border-radius:4px;font-size:11px;
            font-weight:500;border:1px solid #6366f1;color:#6366f1;background:none;cursor:pointer;
        }
        .hwpa-metric-fix:hover{background:#6366f1;color:#fff;}

        /* action categories */
        .hwpa-category-head{
            font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
            color:#6b7280;margin:20px 0 10px;display:flex;align-items:center;gap:8px;
        }
        .hwpa-category-head::after{content:'';flex:1;height:1px;background:#e5e7eb;}

        .hwpa-action-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;}
        .hwpa-action-card{
            background:#fff;border:1px solid #e5e7eb;border-radius:8px;
            padding:16px;display:flex;flex-direction:column;gap:8px;
            box-shadow:0 1px 2px rgba(0,0,0,.05);
        }
        .hwpa-action-title{font-size:14px;font-weight:600;color:#1e1b4b;display:flex;align-items:center;gap:8px;}
        .hwpa-action-desc{font-size:12px;color:#6b7280;line-height:1.5;flex:1;}
        .hwpa-action-footer{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
        .hwpa-badge-rev{display:inline-flex;align-items:center;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:500;}
        .hwpa-badge-rev.yes{background:#d1fae5;color:#065f46;}
        .hwpa-badge-rev.no{background:#fef3c7;color:#92400e;}
        .hwpa-badge-risk{background:#fee2e2;color:#7f1d1d;display:inline-flex;align-items:center;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:500;}

        /* buttons */
        .hwpa-btn{
            display:inline-flex;align-items:center;gap:6px;
            padding:7px 16px;border-radius:6px;font-size:12px;font-weight:500;
            border:none;cursor:pointer;transition:background .15s,opacity .15s;
            font-family:inherit;min-height:32px;
        }
        .hwpa-btn-primary{background:#6366f1;color:#fff;}
        .hwpa-btn-primary:hover{background:#4f46e5;color:#fff;}
        .hwpa-btn-secondary{background:#fff;color:#374151;border:1px solid #d1d5db;}
        .hwpa-btn-secondary:hover{background:#f9fafb;}
        .hwpa-btn-danger{background:#ef4444;color:#fff;}
        .hwpa-btn-danger:hover{background:#dc2626;color:#fff;}
        .hwpa-btn-ghost{background:none;color:#6366f1;border:1px solid #6366f1;}
        .hwpa-btn-ghost:hover{background:#6366f1;color:#fff;}
        .hwpa-btn-sm{padding:4px 10px;font-size:11px;min-height:26px;}
        .hwpa-btn:disabled{opacity:.5;cursor:not-allowed;}

        /* spinner */
        .hwpa-spinner{
            width:16px;height:16px;border:2px solid #e5e7eb;border-top-color:#6366f1;
            border-radius:50%;animation:hwpa-spin .7s linear infinite;display:none;flex-shrink:0;
        }
        @keyframes hwpa-spin{to{transform:rotate(360deg);}}

        /* modal */
        .hwpa-modal-overlay{
            display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:99999;
            align-items:center;justify-content:center;
        }
        .hwpa-modal-overlay.open{display:flex;}
        .hwpa-modal{
            background:#fff;border-radius:12px;width:90%;max-width:560px;
            box-shadow:0 20px 60px rgba(0,0,0,.2);overflow:hidden;
        }
        .hwpa-modal-head{
            display:flex;align-items:center;justify-content:space-between;
            padding:16px 20px;border-bottom:1px solid #e5e7eb;
        }
        .hwpa-modal-head h3{margin:0;font-size:15px;font-weight:600;color:#1e1b4b;}
        .hwpa-modal-close{background:none;border:none;cursor:pointer;color:#9ca3af;font-size:20px;line-height:1;padding:0;}
        .hwpa-modal-close:hover{color:#374151;}
        .hwpa-modal-body{padding:20px;font-size:13px;color:#374151;line-height:1.6;max-height:50vh;overflow-y:auto;}
        .hwpa-modal-foot{
            display:flex;align-items:center;justify-content:flex-end;gap:10px;
            padding:14px 20px;border-top:1px solid #e5e7eb;background:#fafafa;
        }
        .hwpa-modal-warning{
            background:#fef2f2;border:1px solid #fca5a5;color:#7f1d1d;
            border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:12px;
        }
        .hwpa-confirm-input{
            border:1px solid #e5e7eb;border-radius:6px;padding:8px 12px;
            font-size:13px;width:100%;margin-top:8px;font-family:inherit;
        }
        .hwpa-confirm-input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.15);}

        /* toast */
        .hwpa-toast-container{position:fixed;bottom:24px;right:24px;z-index:999999;display:flex;flex-direction:column;gap:8px;pointer-events:none;}
        .hwpa-toast{
            background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;
            box-shadow:0 4px 20px rgba(0,0,0,.12);font-size:13px;color:#374151;
            max-width:360px;pointer-events:auto;display:flex;align-items:flex-start;gap:10px;
            animation:hwpa-slide-in .25s ease;
        }
        .hwpa-toast.success .hwpa-toast-icon{color:#10b981;}
        .hwpa-toast.error   .hwpa-toast-icon{color:#ef4444;}
        @keyframes hwpa-slide-in{from{transform:translateY(16px);opacity:0;}to{transform:translateY(0);opacity:1;}}

        /* scheduler table */
        .hwpa-sched-table{width:100%;border-collapse:collapse;font-size:13px;}
        .hwpa-sched-table th{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;padding:10px 14px;border-bottom:2px solid #e5e7eb;text-align:left;white-space:nowrap;}
        .hwpa-sched-table td{padding:12px 14px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
        .hwpa-sched-table tr:last-child td{border-bottom:none;}

        /* toggle */
        .hwpa-toggle{position:relative;display:inline-block;width:38px;height:20px;flex-shrink:0;}
        .hwpa-toggle input{opacity:0;width:0;height:0;}
        .hwpa-toggle-slider{position:absolute;cursor:pointer;inset:0;background:#d1d5db;border-radius:20px;transition:background .2s;}
        .hwpa-toggle-slider::before{content:'';position:absolute;width:14px;height:14px;left:3px;top:3px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2);}
        .hwpa-toggle input:checked + .hwpa-toggle-slider{background:#6366f1;}
        .hwpa-toggle input:checked + .hwpa-toggle-slider::before{transform:translateX(18px);}

        /* log table */
        .hwpa-log-table{width:100%;border-collapse:collapse;font-size:12px;}
        .hwpa-log-table th{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;padding:10px 12px;border-bottom:2px solid #e5e7eb;text-align:left;}
        .hwpa-log-table td{padding:10px 12px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
        .hwpa-log-table tr:last-child td{border-bottom:none;}
        .hwpa-log-dry{display:inline-flex;align-items:center;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:600;background:#dbeafe;color:#1e40af;}
        .hwpa-log-live{display:inline-flex;align-items:center;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:600;background:#d1fae5;color:#065f46;}

        /* AI chat */
        .hwpa-chat-wrap{height:440px;display:flex;flex-direction:column;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;}
        .hwpa-chat-msgs{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:12px;background:#f9fafb;}
        .hwpa-chat-bubble{max-width:78%;padding:10px 14px;border-radius:10px;font-size:13px;line-height:1.6;}
        .hwpa-chat-bubble.user{background:#6366f1;color:#fff;align-self:flex-end;border-bottom-right-radius:2px;}
        .hwpa-chat-bubble.assistant{background:#fff;color:#374151;border:1px solid #e5e7eb;align-self:flex-start;border-bottom-left-radius:2px;}
        .hwpa-chat-bubble.tool-result{background:#f0fdf4;border:1px solid #bbf7d0;color:#14532d;font-family:monospace;font-size:11px;white-space:pre-wrap;align-self:flex-start;}
        .hwpa-chat-input-row{display:flex;gap:8px;padding:12px;border-top:1px solid #e5e7eb;background:#fff;}
        .hwpa-chat-input{flex:1;border:1px solid #e5e7eb;border-radius:6px;padding:9px 12px;font-size:13px;font-family:inherit;resize:none;}
        .hwpa-chat-input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.15);}
        .hwpa-prompts-bar{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#f5f3ff;border-bottom:1px solid #e5e7eb;}
        .hwpa-prompts-hint{font-size:12px;color:#6b7280;}
        .hwpa-prompts-btn{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid #6366f1;color:#6366f1;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:500;cursor:pointer;transition:all .15s;}
        .hwpa-prompts-btn:hover{background:#6366f1;color:#fff;}
        /* Prompts modal */
        .hwpa-prompts-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100050;display:none;align-items:center;justify-content:center;padding:16px;}
        .hwpa-prompts-overlay.open{display:flex;}
        .hwpa-prompts-modal{background:#fff;border-radius:12px;width:100%;max-width:640px;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.2);}
        .hwpa-prompts-modal-head{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid #e5e7eb;}
        .hwpa-prompts-modal-head h3{margin:0;font-size:16px;font-weight:600;color:#1e1b4b;}
        .hwpa-prompts-modal-head p{margin:4px 0 0;font-size:12px;color:#6b7280;}
        .hwpa-prompts-modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:#9ca3af;line-height:1;padding:0;}
        .hwpa-prompts-modal-close:hover{color:#374151;}
        .hwpa-prompts-body{overflow-y:auto;padding:20px;}
        .hwpa-prompt-category{margin-bottom:20px;}
        .hwpa-prompt-category-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:8px;}
        .hwpa-prompt-chips{display:flex;flex-wrap:wrap;gap:8px;}
        .hwpa-prompt-chip{display:inline-flex;align-items:center;gap:6px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:20px;padding:7px 14px;font-size:12px;color:#374151;cursor:pointer;transition:all .15s;text-align:left;}
        .hwpa-prompt-chip:hover{background:#eef2ff;border-color:#6366f1;color:#4f46e5;}
        .hwpa-prompt-chip .chip-icon{font-size:14px;}
        @media(max-width:480px){.hwpa-prompts-modal{max-height:90vh;border-radius:12px 12px 0 0;}.hwpa-prompts-overlay{align-items:flex-end;padding:0;}}
        .hwpa-thinking{display:flex;align-items:center;gap:10px;padding:4px 2px;}
        .hwpa-thinking-dots{display:flex;gap:5px;}
        .hwpa-thinking-dots span{width:8px;height:8px;border-radius:50%;background:#6366f1;animation:hwpa-bounce .8s infinite;}
        .hwpa-thinking-dots span:nth-child(2){animation-delay:.15s;}
        .hwpa-thinking-dots span:nth-child(3){animation-delay:.3s;}
        @keyframes hwpa-bounce{0%,60%,100%{transform:translateY(0);}30%{transform:translateY(-6px);}}
        .hwpa-thinking-status{font-size:13px;color:#6b7280;font-style:italic;transition:opacity .2s ease;}
        /* tool call chips */
        .hwpa-tool-calls{display:flex;flex-direction:column;gap:4px;margin-bottom:6px;}
        .hwpa-tool-detail{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;}
        .hwpa-tool-detail summary{padding:6px 10px;cursor:pointer;list-style:none;display:flex;align-items:center;user-select:none;}
        .hwpa-tool-detail summary::-webkit-details-marker{display:none;}
        .hwpa-tool-chip{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:500;color:#4b5563;}
        .hwpa-tool-chip-icon{opacity:.6;}
        .hwpa-tool-pre{margin:0;padding:10px;font-size:11px;background:#fff;border-top:1px solid #e5e7eb;overflow-x:auto;white-space:pre-wrap;word-break:break-word;max-height:180px;overflow-y:auto;}

        /* responsive */
        @media(max-width:1024px){.hwpa-metric-grid{grid-template-columns:repeat(2,1fr);}}
        @media(max-width:782px){
            .hwpa-page{padding:16px 12px 60px;}
            .hwpa-metric-grid{grid-template-columns:1fr 1fr;}
            .hwpa-action-grid{grid-template-columns:1fr;}
            .hwpa-tabs{gap:0;}
            .hwpa-tab{padding:8px 12px;font-size:12px;}
        }
        @media(max-width:480px){.hwpa-metric-grid{grid-template-columns:1fr;}}

        /* Info button */
        .hwpa-info-btn{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:none;border:1.5px solid #9ca3af;color:#9ca3af;font-size:10px;font-weight:700;cursor:pointer;line-height:1;padding:0;margin-left:6px;flex-shrink:0;transition:all .15s;}
        .hwpa-info-btn:hover{border-color:#6366f1;color:#6366f1;background:#eef2ff;}
        /* Info modal */
        .hwpa-info-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100060;display:none;align-items:center;justify-content:center;padding:16px;}
        .hwpa-info-overlay.open{display:flex;}
        .hwpa-info-modal{background:#fff;border-radius:12px;width:100%;max-width:520px;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.2);}
        .hwpa-info-modal-head{display:flex;align-items:flex-start;justify-content:space-between;padding:18px 20px 14px;border-bottom:1px solid #e5e7eb;}
        .hwpa-info-modal-head h3{margin:0;font-size:15px;font-weight:600;color:#1e1b4b;}
        .hwpa-info-modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:#9ca3af;line-height:1;padding:0;margin-left:12px;flex-shrink:0;}
        .hwpa-info-modal-close:hover{color:#374151;}
        .hwpa-info-modal-body{padding:18px 20px;overflow-y:auto;font-size:13px;line-height:1.7;color:#374151;}
        .hwpa-info-modal-body p{margin:0 0 10px;}
        .hwpa-info-modal-body p:last-child{margin:0;}
        .hwpa-info-modal-body .hwpa-info-cost{display:inline-block;background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:500;margin-top:4px;}
        .hwpa-info-modal-body .hwpa-info-free{display:inline-block;background:#f0f9ff;border:1px solid #bae6fd;color:#0369a1;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:500;margin-top:4px;}
        </style>

        <div class="hwpa-page">

            <!-- Header -->
            <div class="hwpa-header">
                <h1>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="12" cy="12" r="10" fill="#6366f1" opacity=".12"/>
                        <path d="M8 12.5v-5l6 4.5-6 4.5v-4z" fill="#6366f1"/>
                    </svg>
                    eShopeo WP Agent
                    <span class="hwpa-badge">v<?php echo esc_html( ESHOPEO_CONNECTOR_VERSION ); ?></span>
                </h1>
            </div>

            <!-- Tabs -->
            <div class="hwpa-tabs" role="tablist">
                <button class="hwpa-tab active" data-tab="quick-actions" role="tab">Quick Actions</button>
                <button class="hwpa-tab" data-tab="performance" role="tab">Performance</button>
                <button class="hwpa-tab" data-tab="scheduler" role="tab">Scheduler</button>
                <button class="hwpa-tab" data-tab="broken-links" role="tab">&#128279; Broken Links</button>
                <button class="hwpa-tab" data-tab="error-log" role="tab">&#129811; Error Log</button>
                <button class="hwpa-tab" data-tab="backups" role="tab">&#128190; Backups</button>
                <button class="hwpa-tab" data-tab="plugins" role="tab">&#128268; Plugins</button>
                <button class="hwpa-tab" data-tab="snippets" role="tab">&#129513; Snippets</button>
                <button class="hwpa-tab" data-tab="alt-text" role="tab">&#128444;&#65039; Alt Text</button>
                <button class="hwpa-tab" data-tab="seo-meta" role="tab">&#127991;&#65039; SEO Meta</button>
                <button class="hwpa-tab" data-tab="reviews" role="tab">&#11088; Reviews</button>
                <button class="hwpa-tab" data-tab="int-links" role="tab">&#128279; Int. Links</button>
                <button class="hwpa-tab" data-tab="blog" role="tab">&#9997;&#65039; Blog</button>
                <button class="hwpa-tab" data-tab="descriptions" role="tab">&#128717; Descriptions</button>
                <button class="hwpa-tab" data-tab="repurpose" role="tab">&#9851;&#65039; Repurpose</button>
                <button class="hwpa-tab" data-tab="images" role="tab">&#128444;&#65039; Images</button>
                <button class="hwpa-tab" data-tab="ai-assistant" role="tab">AI Assistant</button>
                <button class="hwpa-tab" data-tab="action-log" role="tab">Action Log</button>
            </div>

            <!-- ══════════ QUICK ACTIONS TAB ══════════ -->
            <div id="hwpa-panel-quick-actions" class="hwpa-panel active" role="tabpanel">
                <?php
                $actions    = Eshopeo_Quick_Actions::get_actions();
                $categories = [
                    'database'    => [ 'label' => 'Database',    'icon' => '&#128451;', 'help_title' => 'Database Cleanup',    'help_body' => '&lt;p&gt;These actions clean up your WordPress database to improve performance. Each one runs a &lt;strong&gt;dry-run preview first&lt;/strong&gt; — showing exactly what will be affected — before you confirm.&lt;/p&gt;&lt;p&gt;Affected rows are &lt;strong&gt;snapshotted before deletion&lt;/strong&gt; so you can undo from the Action Log tab.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-free&quot;&gt;Zero cost — pure PHP/MySQL&lt;/span&gt;&lt;/p&gt;' ],
                    'performance' => [ 'label' => 'Performance', 'icon' => '&#9889;',   'help_title' => 'Performance Tweaks',  'help_body' => '&lt;p&gt;One-click adjustments that reduce WordPress admin overhead. The Heartbeat API fires AJAX calls every 15–60 seconds in the admin — slowing every page load. Flushing rewrite rules fixes permalink issues without touching the database.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-free&quot;&gt;Zero cost — pure PHP/WordPress&lt;/span&gt;&lt;/p&gt;' ],
                    'media'       => [ 'label' => 'Media',       'icon' => '&#128444;', 'help_title' => 'Media Audit',         'help_body' => '&lt;p&gt;Finds orphaned uploads not referenced anywhere on the site, images missing alt text, and unregistered image sizes taking up disk space. These are &lt;strong&gt;scan-only&lt;/strong&gt; actions — no files are deleted without your confirmation.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-free&quot;&gt;Zero cost — pure PHP/WordPress&lt;/span&gt;&lt;/p&gt;' ],
                    'security'    => [ 'label' => 'Security',    'icon' => '&#128274;', 'help_title' => 'Security Audit',      'help_body' => '&lt;p&gt;Read-only checks that flag common WordPress security risks: admin accounts with default usernames, inactive plugins increasing your attack surface, and file permission issues on key directories.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-free&quot;&gt;Zero cost — no changes made&lt;/span&gt;&lt;/p&gt;' ],
                    'content'     => [ 'label' => 'Content',     'icon' => '&#128196;', 'help_title' => 'Content Audit',       'help_body' => '&lt;p&gt;Scans all posts and pages for common content problems: missing meta descriptions, thin content under 200 words, and drafts sitting unpublished for over 90 days. Results are returned as a list — no changes are made until you act on them.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-free&quot;&gt;Zero cost — read-only scan&lt;/span&gt;&lt;/p&gt;' ],
                ];
                foreach ( $categories as $cat_id => $cat ) :
                    $cat_actions = array_filter( $actions, fn( $a ) => $a['category'] === $cat_id );
                    if ( empty( $cat_actions ) ) continue;
                ?>
                    <div class="hwpa-category-head">
                        <?php echo $cat['icon']; ?> <?php echo esc_html( $cat['label'] ); ?>
                        <button class="hwpa-info-btn" data-help-title="<?php echo esc_attr( $cat['help_title'] ); ?>" data-help-body="<?php echo esc_attr( $cat['help_body'] ); ?>">&#8505;</button>
                    </div>
                    <div class="hwpa-action-grid">
                        <?php foreach ( $cat_actions as $action ) : ?>
                        <div class="hwpa-action-card">
                            <div class="hwpa-action-title">
                                <?php echo esc_html( $action['label'] ); ?>
                            </div>
                            <div class="hwpa-action-desc"><?php echo esc_html( $action['description'] ); ?></div>
                            <div class="hwpa-action-footer">
                                <?php if ( $action['reversible'] ) : ?>
                                    <span class="hwpa-badge-rev yes">Reversible</span>
                                <?php elseif ( $action['destructive'] ) : ?>
                                    <span class="hwpa-badge-rev no">No rollback</span>
                                <?php endif; ?>
                                <?php if ( $action['high_risk'] ) : ?>
                                    <span class="hwpa-badge-risk">High risk</span>
                                <?php endif; ?>
                                <button class="hwpa-btn hwpa-btn-primary hwpa-btn-sm hwpa-preview-btn"
                                        data-action="<?php echo esc_attr( $action['id'] ); ?>"
                                        data-label="<?php echo esc_attr( $action['label'] ); ?>"
                                        data-high-risk="<?php echo $action['high_risk'] ? '1' : '0'; ?>"
                                        data-reversible="<?php echo $action['reversible'] ? '1' : '0'; ?>"
                                        data-destructive="<?php echo $action['destructive'] ? '1' : '0'; ?>">
                                    Preview
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- ══════════ PERFORMANCE TAB ══════════ -->
            <div id="hwpa-panel-performance" class="hwpa-panel" role="tabpanel">
                <div class="hwpa-card">
                    <div class="hwpa-card-head">
                        <h2>Site Performance Dashboard</h2><button class="hwpa-info-btn" data-help-title="Site Performance Dashboard" data-help-body="&lt;p&gt;Collects 15 live metrics from your WordPress database and PHP environment — autoload data size, expired transients, table overhead, orphaned meta, revision count, memory usage, and more.&lt;/p&gt;&lt;p&gt;Each metric is graded &lt;strong&gt;A–F&lt;/strong&gt; against known thresholds (e.g. autoload over 1 MB = grade D). Click &lt;strong&gt;Fix&lt;/strong&gt; next to any metric to run the corresponding quick action immediately.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-free&quot;&gt;Zero cost — pure PHP/MySQL&lt;/span&gt;&lt;/p&gt;">&#8505;</button>
                        <button id="hwpa-refresh-metrics" class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm" style="margin-left:auto;">
                            Refresh Metrics
                        </button>
                        <div class="hwpa-spinner" id="hwpa-perf-spinner"></div>
                    </div>
                    <div class="hwpa-card-body" id="hwpa-perf-body">
                        <p style="color:#9ca3af;font-size:13px;">Click "Refresh Metrics" to load live data.</p>
                    </div>
                </div>

                <!-- Uptime Monitor card -->
                <div class="hwpa-card" id="hwpa-uptime-card">
                    <div class="hwpa-card-head">
                        <h2>Uptime Monitor</h2><button class="hwpa-info-btn" data-help-title="Uptime Monitor" data-help-body="&lt;p&gt;Pings your site every &lt;strong&gt;5 minutes&lt;/strong&gt; using WordPress's built-in cron system. Records the HTTP status code and response time for each check and keeps a rolling &lt;strong&gt;24-hour log&lt;/strong&gt; (288 checks).&lt;/p&gt;&lt;p&gt;If your site returns a non-200 response &lt;strong&gt;3 times in a row&lt;/strong&gt;, it sends an email alert to your configured address so you know before your customers do.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-free&quot;&gt;Zero cost — uses WP-Cron, no external service&lt;/span&gt;&lt;/p&gt;">&#8505;</button>
                        <button id="hwpa-uptime-refresh" class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm" style="margin-left:auto;">Refresh</button>
                        <div class="hwpa-spinner" id="hwpa-uptime-spinner"></div>
                    </div>
                    <div class="hwpa-card-body" id="hwpa-uptime-body">
                        <p style="color:#9ca3af;font-size:13px;">Loading uptime data&hellip;</p>
                    </div>
                </div>

                <!-- Web Vitals card -->
                <div class="hwpa-card" id="hwpa-vitals-card">
                    <div class="hwpa-card-head">
                        <h2>Core Web Vitals</h2><button class="hwpa-info-btn" data-help-title="Core Web Vitals" data-help-body="&lt;p&gt;Uses &lt;strong&gt;Google's free PageSpeed Insights API&lt;/strong&gt; to measure how fast your site feels to real users. Tracks:&lt;/p&gt;&lt;p&gt;&lt;strong&gt;LCP&lt;/strong&gt; — how long until the main content loads &lt;br&gt;&lt;strong&gt;INP&lt;/strong&gt; — responsiveness to clicks and taps &lt;br&gt;&lt;strong&gt;CLS&lt;/strong&gt; — how much the layout shifts while loading &lt;br&gt;&lt;strong&gt;FCP&lt;/strong&gt; — when the first content appears &lt;br&gt;&lt;strong&gt;TTFB&lt;/strong&gt; — your server's initial response time&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-free&quot;&gt;Free — Google PageSpeed API (25,000 requests/day)&lt;/span&gt;&lt;/p&gt;">&#8505;</button>
                        <button id="hwpa-vitals-scan" class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm" style="margin-left:auto;">Scan Now</button>
                        <div class="hwpa-spinner" id="hwpa-vitals-spinner"></div>
                    </div>
                    <div class="hwpa-card-body" id="hwpa-vitals-body">
                        <p style="color:#9ca3af;font-size:13px;">Requires a PageSpeed API key in eShopeo Settings. Click "Scan Now" to run.</p>
                    </div>
                </div>
            </div>

            <!-- ══════════ SCHEDULER TAB ══════════ -->
            <div id="hwpa-panel-scheduler" class="hwpa-panel" role="tabpanel">
                <div class="hwpa-card">
                    <div class="hwpa-card-head">
                        <h2>Scheduled Maintenance</h2><button class="hwpa-info-btn" data-help-title="Scheduled Maintenance" data-help-body="&lt;p&gt;Runs cleanup jobs automatically in the background using &lt;strong&gt;WordPress WP-Cron&lt;/strong&gt; — no manual action needed. Each job logs what it did and how many rows were affected in the &lt;strong&gt;Action Log&lt;/strong&gt;.&lt;/p&gt;&lt;p&gt;Toggle each job on or off individually, or trigger any job immediately with &lt;strong&gt;Run Now&lt;/strong&gt;.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-free&quot;&gt;Zero cost — pure PHP/WordPress&lt;/span&gt;&lt;/p&gt;">&#8505;</button>
                        <button id="hwpa-refresh-sched" class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm" style="margin-left:auto;">
                            Refresh
                        </button>
                    </div>
                    <div class="hwpa-card-body" id="hwpa-sched-body">
                        <p style="color:#9ca3af;font-size:13px;">Loading schedule...</p>
                    </div>
                </div>

                <!-- Weekly Digest controls -->
                <div class="hwpa-card">
                    <div class="hwpa-card-head"><h2>Weekly Digest Email</h2><button class="hwpa-info-btn" data-help-title="Weekly Digest Email" data-help-body="&lt;p&gt;Every &lt;strong&gt;Monday at 8am&lt;/strong&gt;, eShopeo collects your site stats for the past 7 days — new posts, orders, leads, uptime %, top errors — then sends them to &lt;strong&gt;Claude Haiku&lt;/strong&gt; which writes a short plain-English summary email.&lt;/p&gt;&lt;p&gt;Use &lt;strong&gt;Send Test&lt;/strong&gt; to preview it immediately.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-cost&quot;&gt;~$0.01/week in AI usage (Claude Haiku)&lt;/span&gt;&lt;/p&gt;">&#8505;</button></div>
                    <div class="hwpa-card-body">
                        <p style="font-size:13px;color:#6b7280;margin:0 0 14px;">Sends an AI-generated weekly site summary every Monday at 8am UTC.</p>
                        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                            <label style="font-size:13px;font-weight:500;">Digest email:</label>
                            <input type="email" id="hwpa-digest-email" value="<?php echo esc_attr( get_option( 'admin_email', '' ) ); ?>"
                                   style="border:1px solid #e5e7eb;border-radius:6px;padding:6px 10px;font-size:13px;width:260px;">
                            <button id="hwpa-digest-test" class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm">Send Test Digest</button>
                            <div class="hwpa-spinner" id="hwpa-digest-spinner"></div>
                        </div>
                        <p id="hwpa-digest-result" style="margin:10px 0 0;font-size:12px;display:none;"></p>
                    </div>
                </div>
            </div>

            <!-- ══════════ BROKEN LINKS TAB ══════════ -->
            <div id="hwpa-panel-broken-links" class="hwpa-panel" role="tabpanel">
                <div class="hwpa-card">
                    <div class="hwpa-card-head">
                        <h2>Broken Link Checker</h2><button class="hwpa-info-btn" data-help-title="Broken Link Checker" data-help-body="&lt;p&gt;Scans all published posts and pages, extracts every hyperlink, and sends an HTTP HEAD request to each URL. Links returning &lt;strong&gt;404, 410, 500, or connection errors&lt;/strong&gt; are flagged.&lt;/p&gt;&lt;p&gt;Runs in &lt;strong&gt;batches of 20&lt;/strong&gt; to avoid PHP timeouts. Use the &lt;strong&gt;Fix&lt;/strong&gt; button to replace a broken URL — original content is snapshotted first so you can undo.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-free&quot;&gt;Zero cost — pure PHP/HTTP&lt;/span&gt;&lt;/p&gt;">&#8505;</button>
                        <button id="hwpa-bl-scan" class="hwpa-btn hwpa-btn-primary hwpa-btn-sm" style="margin-left:auto;">Scan Now</button>
                        <div class="hwpa-spinner" id="hwpa-bl-spinner"></div>
                    </div>
                    <div class="hwpa-card-body">
                        <div id="hwpa-bl-progress" style="display:none;margin-bottom:12px;">
                            <div style="background:#f3f4f6;border-radius:4px;height:8px;overflow:hidden;">
                                <div id="hwpa-bl-bar" style="height:100%;background:#6366f1;width:0%;transition:width .3s;"></div>
                            </div>
                            <p id="hwpa-bl-progress-text" style="font-size:12px;color:#6b7280;margin:6px 0 0;"></p>
                        </div>
                        <p id="hwpa-bl-last-scan" style="font-size:12px;color:#9ca3af;margin:0 0 12px;"></p>
                        <div id="hwpa-bl-results"></div>
                    </div>
                </div>
            </div>

            <!-- ══════════ ERROR LOG TAB ══════════ -->
            <div id="hwpa-panel-error-log" class="hwpa-panel" role="tabpanel">
                <div class="hwpa-card">
                    <div class="hwpa-card-head">
                        <h2>PHP Error Log</h2><button class="hwpa-info-btn" data-help-title="PHP Error Log" data-help-body="&lt;p&gt;Reads your &lt;strong&gt;WordPress debug.log&lt;/strong&gt; file and parses each line into structured entries: timestamp, severity level, message, and file/line number. Errors are grouped by type — &lt;strong&gt;Fatal, Warning, Notice, Deprecated&lt;/strong&gt;.&lt;/p&gt;&lt;p&gt;&lt;strong&gt;Clear Log&lt;/strong&gt; saves the last 50 lines as a snapshot before truncating the file.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-free&quot;&gt;Zero cost — reads local file&lt;/span&gt;&lt;/p&gt;">&#8505;</button>
                        <button id="hwpa-el-refresh" class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm" style="margin-left:auto;">Refresh</button>
                        <button id="hwpa-el-clear" class="hwpa-btn hwpa-btn-danger hwpa-btn-sm" style="margin-left:8px;">Clear Log</button>
                        <div class="hwpa-spinner" id="hwpa-el-spinner"></div>
                    </div>
                    <div class="hwpa-card-body">
                        <div id="hwpa-el-badges" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;"></div>
                        <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
                            <button class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm hwpa-el-filter" data-level="">All</button>
                            <button class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm hwpa-el-filter" data-level="Fatal" style="color:#ef4444;border-color:#ef4444;">Fatal</button>
                            <button class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm hwpa-el-filter" data-level="Warning" style="color:#f59e0b;border-color:#f59e0b;">Warning</button>
                            <button class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm hwpa-el-filter" data-level="Notice">Notice</button>
                            <button class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm hwpa-el-filter" data-level="Deprecated">Deprecated</button>
                        </div>
                        <div id="hwpa-el-results"><p style="color:#9ca3af;font-size:13px;">Click Refresh to load error log.</p></div>
                    </div>
                </div>
            </div>

            <!-- ══════════ BACKUPS TAB ══════════ -->
            <div id="hwpa-panel-backups" class="hwpa-panel" role="tabpanel">
                <div class="hwpa-card">
                    <div class="hwpa-card-head">
                        <h2>Database Backups</h2><button class="hwpa-info-btn" data-help-title="Database Backups" data-help-body="&lt;p&gt;Exports your entire WordPress database to a &lt;strong&gt;compressed .sql.gz file&lt;/strong&gt; stored in &lt;code&gt;wp-content/eshopeo-backups/&lt;/code&gt; — protected from direct browser access by an .htaccess rule.&lt;/p&gt;&lt;p&gt;Old backups are &lt;strong&gt;auto-deleted&lt;/strong&gt; once you exceed your limit (default: keep last 7). Download any backup directly from this interface.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-free&quot;&gt;Zero cost — uses PHP gzencode(), no external service&lt;/span&gt;&lt;/p&gt;">&#8505;</button>
                        <button id="hwpa-bk-run" class="hwpa-btn hwpa-btn-primary hwpa-btn-sm" style="margin-left:auto;">Backup Now</button>
                        <div class="hwpa-spinner" id="hwpa-bk-spinner"></div>
                    </div>
                    <div class="hwpa-card-body">
                        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
                            <label style="font-size:13px;font-weight:500;">Schedule:</label>
                            <select id="hwpa-bk-schedule" style="border:1px solid #e5e7eb;border-radius:6px;padding:5px 10px;font-size:13px;">
                                <option value="daily" <?php selected( get_option( 'eshopeo_backup_schedule', 'daily' ), 'daily' ); ?>>Daily</option>
                                <option value="weekly" <?php selected( get_option( 'eshopeo_backup_schedule', 'daily' ), 'weekly' ); ?>>Weekly</option>
                                <option value="off" <?php selected( get_option( 'eshopeo_backup_schedule', 'daily' ), 'off' ); ?>>Off</option>
                            </select>
                            <label style="font-size:13px;font-weight:500;">Keep last:</label>
                            <input type="number" id="hwpa-bk-keep" value="<?php echo esc_attr( get_option( 'eshopeo_backup_keep', 7 ) ); ?>" min="1" max="30" style="width:60px;border:1px solid #e5e7eb;border-radius:6px;padding:5px 8px;font-size:13px;">
                        </div>
                        <div id="hwpa-bk-results"><p style="color:#9ca3af;font-size:13px;">Loading backup list&hellip;</p></div>
                    </div>
                </div>
            </div>

            <!-- ══════════ PLUGINS TAB ══════════ -->
            <div id="hwpa-panel-plugins" class="hwpa-panel" role="tabpanel">
                <div class="hwpa-card">
                    <div class="hwpa-card-head">
                        <h2>Plugin Manager</h2><button class="hwpa-info-btn" data-help-title="Plugin Manager" data-help-body="&lt;p&gt;Shows all installed plugins with their current version, available updates, and active/inactive status. Before &lt;strong&gt;updating or toggling&lt;/strong&gt; a plugin, the current state is saved as a snapshot so you can undo if something breaks.&lt;/p&gt;&lt;p&gt;Updates use WordPress's built-in &lt;strong&gt;Plugin Upgrader&lt;/strong&gt; — the same mechanism as the standard WP admin updates page.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-free&quot;&gt;Zero cost — uses WordPress core APIs&lt;/span&gt;&lt;/p&gt;">&#8505;</button>
                        <button id="hwpa-pm-update-all" class="hwpa-btn hwpa-btn-primary hwpa-btn-sm" style="margin-left:auto;">Update All</button>
                        <div class="hwpa-spinner" id="hwpa-pm-spinner"></div>
                    </div>
                    <div class="hwpa-card-body">
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
                            <button class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm hwpa-pm-filter active" data-filter="all">All</button>
                            <button class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm hwpa-pm-filter" data-filter="updates" style="color:#f59e0b;border-color:#f59e0b;">Updates Available</button>
                            <button class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm hwpa-pm-filter" data-filter="active">Active</button>
                            <button class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm hwpa-pm-filter" data-filter="inactive">Inactive</button>
                        </div>
                        <div id="hwpa-pm-results"><p style="color:#9ca3af;font-size:13px;">Loading plugins&hellip;</p></div>
                    </div>
                </div>
            </div>

            <!-- ══════════ SNIPPETS TAB ══════════ -->
            <div id="hwpa-panel-snippets" class="hwpa-panel" role="tabpanel">
                <div class="hwpa-card">
                    <div class="hwpa-card-head">
                        <h2>Code Snippets</h2><button class="hwpa-info-btn" data-help-title="Code Snippets" data-help-body="&lt;p&gt;Run small PHP snippets &lt;strong&gt;without editing theme files&lt;/strong&gt;. Each snippet is attached to a WordPress hook (init, wp_head, wp_footer, etc.) and runs automatically.&lt;/p&gt;&lt;p&gt;If a snippet throws a PHP error, it is &lt;strong&gt;automatically disabled&lt;/strong&gt; and the error is recorded — your site will not crash. Always test on staging first.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-free&quot;&gt;Zero cost — pure PHP execution&lt;/span&gt;&lt;/p&gt;">&#8505;</button>
                        <button id="hwpa-sn-add" class="hwpa-btn hwpa-btn-primary hwpa-btn-sm" style="margin-left:auto;">+ Add Snippet</button>
                    </div>
                    <div class="hwpa-card-body">
                        <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#7f1d1d;">
                            <strong>&#9888; Warning:</strong> Code snippets run as PHP on your site. A syntax error or bug can break your site. Only add code you fully understand.
                        </div>
                        <div id="hwpa-sn-list"><p style="color:#9ca3af;font-size:13px;">Loading snippets&hellip;</p></div>
                    </div>
                </div>
            </div>

            <!-- ══════════ ALT TEXT TAB ══════════ -->
            <div id="hwpa-panel-alt-text" class="hwpa-panel" role="tabpanel">
                <div class="hwpa-card">
                    <div class="hwpa-card-head">
                        <h2>AI Alt Text Generator</h2><button class="hwpa-info-btn" data-help-title="AI Alt Text Generator" data-help-body="&lt;p&gt;Scans your &lt;strong&gt;Media Library&lt;/strong&gt; for images missing alt text. Sends each image title to &lt;strong&gt;Claude Haiku&lt;/strong&gt;, which writes a descriptive, SEO-friendly alt text under 120 characters.&lt;/p&gt;&lt;p&gt;Saved directly to the &lt;strong&gt;_wp_attachment_image_alt&lt;/strong&gt; meta field — the standard field used by all themes and page builders.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-cost&quot;&gt;~$0.0005 per image (Claude Haiku)&lt;/span&gt;&lt;/p&gt;">&#8505;</button>
                        <button id="hwpa-at-scan" class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm" style="margin-left:auto;">Scan Missing</button>
                        <button id="hwpa-at-gen-all" class="hwpa-btn hwpa-btn-primary hwpa-btn-sm" style="margin-left:8px;">Generate All</button>
                        <div class="hwpa-spinner" id="hwpa-at-spinner"></div>
                    </div>
                    <div class="hwpa-card-body">
                        <p id="hwpa-at-count" style="font-size:13px;color:#6b7280;margin:0 0 12px;"></p>
                        <div id="hwpa-at-results"></div>
                    </div>
                </div>
            </div>

            <!-- ══════════ SEO META TAB ══════════ -->
            <div id="hwpa-panel-seo-meta" class="hwpa-panel" role="tabpanel">
                <div class="hwpa-card">
                    <div class="hwpa-card-head">
                        <h2>SEO Meta Generator</h2><button class="hwpa-info-btn" data-help-title="SEO Meta Generator" data-help-body="&lt;p&gt;Finds all posts and pages where both &lt;strong&gt;Yoast SEO&lt;/strong&gt; and &lt;strong&gt;RankMath&lt;/strong&gt; meta description fields are empty. Generates a focused SEO title (max 60 chars) and meta description (max 160 chars) via Claude Haiku.&lt;/p&gt;&lt;p&gt;Updates &lt;strong&gt;both Yoast and RankMath meta keys&lt;/strong&gt; — works regardless of which SEO plugin you use.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-cost&quot;&gt;~$0.001 per page (Claude Haiku)&lt;/span&gt;&lt;/p&gt;">&#8505;</button>
                        <button id="hwpa-sm-scan" class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm" style="margin-left:auto;">Scan Missing</button>
                        <button id="hwpa-sm-gen-all" class="hwpa-btn hwpa-btn-primary hwpa-btn-sm" style="margin-left:8px;">Generate All</button>
                        <div class="hwpa-spinner" id="hwpa-sm-spinner"></div>
                    </div>
                    <div class="hwpa-card-body">
                        <p id="hwpa-sm-count" style="font-size:13px;color:#6b7280;margin:0 0 12px;"></p>
                        <div id="hwpa-sm-results"></div>
                    </div>
                </div>
            </div>

            <!-- ══════════ REVIEWS TAB ══════════ -->
            <div id="hwpa-panel-reviews" class="hwpa-panel" role="tabpanel">
                <div class="hwpa-card">
                    <div class="hwpa-card-head">
                        <h2>Review Synthesiser</h2><button class="hwpa-info-btn" data-help-title="Review Synthesiser" data-help-body="&lt;p&gt;Reads all &lt;strong&gt;WooCommerce product reviews&lt;/strong&gt; for a selected product and sends them to Claude Haiku. Produces a structured summary: 2–3 sentence overview, list of pros, list of cons, and an overall sentiment label.&lt;/p&gt;&lt;p&gt;Results are &lt;strong&gt;cached for 7 days&lt;/strong&gt; — you won't be charged again until the cache expires.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-cost&quot;&gt;~$0.005 per product (Claude Haiku)&lt;/span&gt;&lt;/p&gt;">&#8505;</button>
                    </div>
                    <div class="hwpa-card-body">
                        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
                            <select id="hwpa-rv-product" style="border:1px solid #e5e7eb;border-radius:6px;padding:6px 10px;font-size:13px;min-width:240px;">
                                <option value="">— Select a product —</option>
                            </select>
                            <button id="hwpa-rv-synthesise" class="hwpa-btn hwpa-btn-primary hwpa-btn-sm" disabled>Synthesise Reviews</button>
                            <div class="hwpa-spinner" id="hwpa-rv-spinner"></div>
                        </div>
                        <div id="hwpa-rv-result"></div>
                    </div>
                </div>
            </div>

            <!-- ══════════ INTERNAL LINKS TAB ══════════ -->
            <div id="hwpa-panel-int-links" class="hwpa-panel" role="tabpanel">
                <div class="hwpa-card">
                    <div class="hwpa-card-head">
                        <h2>Internal Link Suggester</h2><button class="hwpa-info-btn" data-help-title="Internal Link Suggester" data-help-body="&lt;p&gt;Sends your post's content plus all published post titles/URLs to &lt;strong&gt;Claude Haiku&lt;/strong&gt;, which identifies the &lt;strong&gt;5 most relevant linking opportunities&lt;/strong&gt; — places where a link to another post adds value for readers and SEO.&lt;/p&gt;&lt;p&gt;Click &lt;strong&gt;Apply&lt;/strong&gt; to insert a link. Original content is snapshotted first — undo anytime from the Action Log.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-cost&quot;&gt;~$0.003 per post (Claude Haiku)&lt;/span&gt;&lt;/p&gt;">&#8505;</button>
                    </div>
                    <div class="hwpa-card-body">
                        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
                            <input type="number" id="hwpa-il-post-id" placeholder="Post ID" min="1"
                                   style="border:1px solid #e5e7eb;border-radius:6px;padding:6px 10px;font-size:13px;width:120px;">
                            <button id="hwpa-il-suggest" class="hwpa-btn hwpa-btn-primary hwpa-btn-sm">Suggest Links</button>
                            <div class="hwpa-spinner" id="hwpa-il-spinner"></div>
                        </div>
                        <div id="hwpa-il-results"></div>
                    </div>
                </div>
            </div>

            <!-- ══════════ BLOG TAB ══════════ -->
            <div id="hwpa-panel-blog" class="hwpa-panel" role="tabpanel">
                <div class="hwpa-card">
                    <div class="hwpa-card-head">
                        <h2>AI Blog Post Generator</h2><button class="hwpa-info-btn" data-help-title="AI Blog Post Generator" data-help-body="&lt;p&gt;Provide a &lt;strong&gt;topic, keywords, tone&lt;/strong&gt; (Professional/Conversational/Technical), and word count. Claude Sonnet writes a complete HTML blog post with proper heading hierarchy, natural flow, SEO title, meta description, suggested categories, and tags.&lt;/p&gt;&lt;p&gt;Saved as a &lt;strong&gt;WordPress draft&lt;/strong&gt; — you review and edit before publishing.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-cost&quot;&gt;~$0.04 per post (Claude Sonnet)&lt;/span&gt;&lt;/p&gt;">&#8505;</button>
                        <span style="margin-left:auto;font-size:12px;color:#6b7280;background:#f3f4f6;padding:3px 8px;border-radius:12px;">~$0.04 / post</span>
                    </div>
                    <div class="hwpa-card-body">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                            <div>
                                <label style="font-size:12px;font-weight:500;color:#374151;display:block;margin-bottom:4px;">Topic *</label>
                                <input type="text" id="hwpa-bg-topic" placeholder="e.g. 10 tips for glowing skin"
                                       style="width:100%;border:1px solid #e5e7eb;border-radius:6px;padding:7px 10px;font-size:13px;">
                            </div>
                            <div>
                                <label style="font-size:12px;font-weight:500;color:#374151;display:block;margin-bottom:4px;">Keywords (comma-sep)</label>
                                <input type="text" id="hwpa-bg-keywords" placeholder="skincare, vitamin C, serum"
                                       style="width:100%;border:1px solid #e5e7eb;border-radius:6px;padding:7px 10px;font-size:13px;">
                            </div>
                            <div>
                                <label style="font-size:12px;font-weight:500;color:#374151;display:block;margin-bottom:4px;">Tone</label>
                                <div style="display:flex;gap:8px;">
                                    <?php foreach ( [ 'professional', 'conversational', 'technical' ] as $tone ) : ?>
                                    <label style="font-size:12px;display:flex;align-items:center;gap:4px;cursor:pointer;">
                                        <input type="radio" name="hwpa-bg-tone" value="<?php echo esc_attr( $tone ); ?>"
                                               <?php checked( $tone, 'professional' ); ?>>
                                        <?php echo esc_html( ucfirst( $tone ) ); ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div>
                                <label style="font-size:12px;font-weight:500;color:#374151;display:block;margin-bottom:4px;">Word count</label>
                                <select id="hwpa-bg-wordcount" style="border:1px solid #e5e7eb;border-radius:6px;padding:6px 10px;font-size:13px;">
                                    <option value="400">~400 words</option>
                                    <option value="800" selected>~800 words</option>
                                    <option value="1200">~1200 words</option>
                                    <option value="1800">~1800 words</option>
                                </select>
                            </div>
                        </div>
                        <div style="display:flex;gap:10px;align-items:center;">
                            <button id="hwpa-bg-generate" class="hwpa-btn hwpa-btn-primary">Generate Post</button>
                            <div class="hwpa-spinner" id="hwpa-bg-spinner"></div>
                        </div>
                        <div id="hwpa-bg-result" style="margin-top:16px;"></div>
                    </div>
                </div>
            </div>

            <!-- ══════════ DESCRIPTIONS TAB ══════════ -->
            <div id="hwpa-panel-descriptions" class="hwpa-panel" role="tabpanel">
                <div class="hwpa-card">
                    <div class="hwpa-card-head">
                        <h2>Product Description Rewriter</h2><button class="hwpa-info-btn" data-help-title="Product Description Rewriter" data-help-body="&lt;p&gt;Scans WooCommerce products for &lt;strong&gt;thin or empty descriptions&lt;/strong&gt; (under 80 words). Sends the title and domain attributes to Claude Haiku, which writes a compelling product description in HTML.&lt;/p&gt;&lt;p&gt;The &lt;strong&gt;original description is snapshotted&lt;/strong&gt; before overwriting — use Undo in the Action Log to restore any individual product.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-cost&quot;&gt;~$0.003 per product (Claude Haiku)&lt;/span&gt;&lt;/p&gt;">&#8505;</button>
                        <button id="hwpa-pd-scan" class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm" style="margin-left:auto;">Scan Thin</button>
                        <div class="hwpa-spinner" id="hwpa-pd-spinner"></div>
                    </div>
                    <div class="hwpa-card-body">
                        <p id="hwpa-pd-count" style="font-size:13px;color:#6b7280;margin:0 0 12px;"></p>
                        <div id="hwpa-pd-results"></div>
                    </div>
                </div>
            </div>

            <!-- ══════════ REPURPOSE TAB ══════════ -->
            <div id="hwpa-panel-repurpose" class="hwpa-panel" role="tabpanel">
                <div class="hwpa-card">
                    <div class="hwpa-card-head">
                        <h2>Content Repurposer</h2><button class="hwpa-info-btn" data-help-title="Content Repurposer" data-help-body="&lt;p&gt;Takes any post or page and repurposes it into up to &lt;strong&gt;4 formats in one API call&lt;/strong&gt;: Social Caption (Twitter/Instagram), Email (newsletter-ready), FAQ (3–5 Q&amp;amp;A pairs), and Short Summary (2–3 sentences).&lt;/p&gt;&lt;p&gt;Select only the formats you need. Results appear as &lt;strong&gt;copyable cards&lt;/strong&gt; — nothing is saved unless you copy it.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-cost&quot;&gt;~$0.02–$0.05 per run (Claude Sonnet)&lt;/span&gt;&lt;/p&gt;">&#8505;</button>
                        <span style="margin-left:auto;font-size:12px;color:#6b7280;background:#f3f4f6;padding:3px 8px;border-radius:12px;">~$0.02 / post</span>
                    </div>
                    <div class="hwpa-card-body">
                        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
                            <input type="number" id="hwpa-rp-post-id" placeholder="Post ID" min="1"
                                   style="border:1px solid #e5e7eb;border-radius:6px;padding:6px 10px;font-size:13px;width:120px;">
                            <span style="font-size:13px;color:#6b7280;">Formats:</span>
                            <?php foreach ( [ 'social_caption' => 'Social Caption', 'email' => 'Email', 'faq' => 'FAQ', 'short_summary' => 'Summary' ] as $val => $lbl ) : ?>
                            <label style="font-size:13px;display:flex;align-items:center;gap:4px;cursor:pointer;">
                                <input type="checkbox" class="hwpa-rp-format" value="<?php echo esc_attr( $val ); ?>" checked>
                                <?php echo esc_html( $lbl ); ?>
                            </label>
                            <?php endforeach; ?>
                            <button id="hwpa-rp-generate" class="hwpa-btn hwpa-btn-primary hwpa-btn-sm">Repurpose</button>
                            <div class="hwpa-spinner" id="hwpa-rp-spinner"></div>
                        </div>
                        <div id="hwpa-rp-result"></div>
                    </div>
                </div>
            </div>

            <!-- ══════════ IMAGES TAB ══════════ -->
            <div id="hwpa-panel-images" class="hwpa-panel" role="tabpanel">
                <div class="hwpa-card">
                    <div class="hwpa-card-head">
                        <h2>Image Optimiser (WebP)</h2><button class="hwpa-info-btn" data-help-title="Image Optimiser (WebP)" data-help-body="&lt;p&gt;Uses &lt;strong&gt;PHP's Imagick extension&lt;/strong&gt; to convert JPEG and PNG images to WebP format at quality 82. WebP files are typically &lt;strong&gt;25–40% smaller&lt;/strong&gt; than JPEG at equivalent quality.&lt;/p&gt;&lt;p&gt;WebP is saved &lt;strong&gt;alongside the original&lt;/strong&gt; — no images are deleted. Falls back gracefully if Imagick is not installed.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-free&quot;&gt;Zero cost — uses PHP Imagick, no external API&lt;/span&gt;&lt;/p&gt;">&#8505;</button>
                        <button id="hwpa-io-scan" class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm" style="margin-left:auto;">Scan</button>
                        <button id="hwpa-io-opt-all" class="hwpa-btn hwpa-btn-primary hwpa-btn-sm" style="margin-left:8px;">Optimise All</button>
                        <div class="hwpa-spinner" id="hwpa-io-spinner"></div>
                    </div>
                    <div class="hwpa-card-body">
                        <?php
                        $imagick_ok = extension_loaded( 'imagick' );
                        $badge_bg   = $imagick_ok ? '#d1fae5' : '#fee2e2';
                        $badge_col  = $imagick_ok ? '#065f46' : '#7f1d1d';
                        $badge_txt  = $imagick_ok ? 'Imagick Available' : 'Imagick Not Available';
                        ?>
                        <div style="display:inline-flex;align-items:center;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:<?php echo esc_attr( $badge_bg ); ?>;color:<?php echo esc_attr( $badge_col ); ?>;margin-bottom:12px;">
                            <?php echo esc_html( $badge_txt ); ?>
                        </div>
                        <?php if ( ! $imagick_ok ) : ?>
                        <p style="font-size:13px;color:#6b7280;">Imagick PHP extension is required for WebP conversion. Contact your host to enable it.</p>
                        <?php endif; ?>
                        <p id="hwpa-io-count" style="font-size:13px;color:#6b7280;margin:0 0 12px;"></p>
                        <div id="hwpa-io-results"></div>
                    </div>
                </div>
            </div>

            <!-- ══════════ AI ASSISTANT TAB ══════════ -->
            <div id="hwpa-panel-ai-assistant" class="hwpa-panel" role="tabpanel">
                <div class="hwpa-card">
                    <div class="hwpa-card-head">
                        <h2>AI Assistant</h2><button class="hwpa-info-btn" data-help-title="AI Assistant" data-help-body="&lt;p&gt;A conversational interface powered by &lt;strong&gt;Claude (Anthropic's AI)&lt;/strong&gt;. Ask it to do anything on your WordPress site in plain English — create posts, analyse performance, fix SEO issues, bulk-update products, and more.&lt;/p&gt;&lt;p&gt;Uses &lt;strong&gt;tools&lt;/strong&gt; to call your WordPress REST API directly — it doesn't just give advice, it actually makes the changes. Write operations require a &lt;strong&gt;WordPress Application Password&lt;/strong&gt; configured in eShopeo Settings.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-cost&quot;&gt;~$0.01–$0.10 per conversation (Claude Sonnet)&lt;/span&gt;&lt;/p&gt;">&#8505;</button>
                    </div>
                    <div class="hwpa-card-body" style="padding:0;">
                        <?php
                        $pack        = Eshopeo_Pack::config();
                        $pack_id     = Eshopeo_Pack::current();
                        $intro       = "I can help you manage your {$pack['label']} store. Ask me to analyse content, fix performance issues, bulk-update {$pack['product_noun']}s, or anything else.";

                        $prompts_universal = [
                            [
                                'category' => '⚡ Performance & Speed',
                                'chips'    => [
                                    [ 'icon' => '🐢', 'text' => 'What is making my wp-admin so slow when I click on something?' ],
                                    [ 'icon' => '🩺', 'text' => 'Run a full site health check and tell me what needs fixing urgently.' ],
                                    [ 'icon' => '💾', 'text' => 'Show me my autoload data size and which options are the biggest culprits.' ],
                                    [ 'icon' => '🔌', 'text' => 'Which active plugins are adding the most overhead to every admin page?' ],
                                    [ 'icon' => '💗', 'text' => 'Is the WordPress Heartbeat API causing performance problems on my site?' ],
                                ],
                            ],
                            [
                                'category' => '🗄️ Database & Cleanup',
                                'chips'    => [
                                    [ 'icon' => '🧹', 'text' => 'How many expired transients do I have and should I clear them?' ],
                                    [ 'icon' => '📚', 'text' => 'How many post revisions are in my database and how much space do they use?' ],
                                    [ 'icon' => '🔧', 'text' => 'Check my database tables for overhead and tell me which ones need optimising.' ],
                                    [ 'icon' => '🗑️', 'text' => 'Find orphaned post meta records I can safely delete.' ],
                                    [ 'icon' => '📊', 'text' => 'Give me a full database health score with a breakdown by category.' ],
                                ],
                            ],
                            [
                                'category' => '🔒 Security Audit',
                                'chips'    => [
                                    [ 'icon' => '👤', 'text' => 'Audit my administrator accounts and flag anything suspicious.' ],
                                    [ 'icon' => '💤', 'text' => 'List all inactive plugins I should remove to reduce my attack surface.' ],
                                    [ 'icon' => '🗂️', 'text' => 'Check my key file and folder permissions for security issues.' ],
                                    [ 'icon' => '🕐', 'text' => 'Are there any stuck or backlogged WP-Cron jobs I should know about?' ],
                                ],
                            ],
                            [
                                'category' => '📝 Content Analysis',
                                'chips'    => [
                                    [ 'icon' => '📉', 'text' => 'Find all pages and posts with thin content (under 200 words) and list them.' ],
                                    [ 'icon' => '🏷️', 'text' => 'Which posts and pages are missing meta descriptions?' ],
                                    [ 'icon' => '🗒️', 'text' => 'Show me drafts that have been sitting unpublished for more than 90 days.' ],
                                    [ 'icon' => '🖼️', 'text' => 'Find media uploads not used anywhere on the site that I can safely delete.' ],
                                ],
                            ],
                        ];

                        $prompts_industry = [];
                        if ( 'kbeauty' === $pack_id ) {
                            $prompts_industry = [
                                [
                                    'category' => '💄 Products & Catalogue',
                                    'chips'    => [
                                        [ 'icon' => '🔍', 'text' => 'Find all skincare products missing skin type or concern attributes.' ],
                                        [ 'icon' => '🏷️', 'text' => 'Rewrite the meta descriptions for my top 10 bestselling products.' ],
                                        [ 'icon' => '❓', 'text' => 'Generate FAQ schema markup for my bestselling serums.' ],
                                        [ 'icon' => '🗂️', 'text' => 'Which products have no category assigned?' ],
                                        [ 'icon' => '✍️', 'text' => 'Write SEO-optimised descriptions for products with descriptions under 50 words.' ],
                                    ],
                                ],
                            ];
                        } elseif ( 'automotive' === $pack_id ) {
                            $prompts_industry = [
                                [
                                    'category' => '🚗 Vehicle Listings',
                                    'chips'    => [
                                        [ 'icon' => '🔍', 'text' => 'Find all vehicle listings missing VIN, mileage, or fuel type.' ],
                                        [ 'icon' => '🖼️', 'text' => 'Which vehicle listings have no images uploaded?' ],
                                        [ 'icon' => '📅', 'text' => 'Show me vehicles that have been listed for more than 90 days without an enquiry.' ],
                                        [ 'icon' => '✍️', 'text' => 'Write an enquiry-optimised description for a specific vehicle — just tell me the make and model.' ],
                                        [ 'icon' => '📊', 'text' => 'Give me a full inventory audit: missing attributes, no images, and stale listings.' ],
                                    ],
                                ],
                            ];
                        } else {
                            $prompts_industry = [
                                [
                                    'category' => '🛍️ Products & SEO',
                                    'chips'    => [
                                        [ 'icon' => '✍️', 'text' => 'Write SEO-optimised descriptions for my top 10 products.' ],
                                        [ 'icon' => '🏷️', 'text' => 'Which products are missing meta descriptions or have very short ones?' ],
                                        [ 'icon' => '🗂️', 'text' => 'Find products with no category assigned.' ],
                                        [ 'icon' => '❓', 'text' => 'Generate FAQ schema for my most popular products.' ],
                                    ],
                                ],
                            ];
                        }

                        $all_prompts = array_merge( $prompts_universal, $prompts_industry );
                        ?>
                        <div class="hwpa-chat-wrap" id="hwpa-chat-wrap">
                            <div class="hwpa-prompts-bar">
                                <span class="hwpa-prompts-hint">Not sure what to ask?</span>
                                <button class="hwpa-prompts-btn" id="hwpa-prompts-open">
                                    💡 Example Prompts
                                </button>
                            </div>
                            <div class="hwpa-chat-msgs" id="hwpa-chat-msgs">
                                <div class="hwpa-chat-bubble assistant">
                                    <?php echo esc_html( $intro ); ?>
                                </div>
                            </div>
                            <div class="hwpa-chat-input-row">
                                <textarea id="hwpa-chat-input" class="hwpa-chat-input" rows="2" placeholder="Ask me anything about your WordPress site…"></textarea>
                                <button id="hwpa-chat-send" class="hwpa-btn hwpa-btn-primary">Send</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══════════ ACTION LOG TAB ══════════ -->
            <div id="hwpa-panel-action-log" class="hwpa-panel" role="tabpanel">
                <div class="hwpa-card">
                    <div class="hwpa-card-head">
                        <h2>Action Log</h2><button class="hwpa-info-btn" data-help-title="Action Log" data-help-body="&lt;p&gt;A &lt;strong&gt;timestamped audit trail&lt;/strong&gt; of every action performed through this panel: who ran it, when, what was affected, and whether a rollback snapshot is available.&lt;/p&gt;&lt;p&gt;For any &lt;strong&gt;reversible action&lt;/strong&gt;, clicking Undo restores the snapshotted data exactly as it was. Snapshots expire after &lt;strong&gt;7 days&lt;/strong&gt;.&lt;/p&gt;&lt;p&gt;&lt;span class=&quot;hwpa-info-free&quot;&gt;Zero cost — local database only&lt;/span&gt;&lt;/p&gt;">&#8505;</button>
                        <button id="hwpa-log-clear" class="hwpa-btn hwpa-btn-danger hwpa-btn-sm" style="margin-left:auto;">
                            Clear Log
                        </button>
                    </div>
                    <div class="hwpa-card-body" style="padding:0;" id="hwpa-log-body">
                        <?php self::render_log_table(); ?>
                    </div>
                </div>
            </div>

        </div><!-- .hwpa-page -->

        <!-- ══════════ CONFIRM MODAL ══════════ -->
        <div class="hwpa-modal-overlay" id="hwpa-modal">
            <div class="hwpa-modal">
                <div class="hwpa-modal-head">
                    <h3 id="hwpa-modal-title">Preview</h3>
                    <button class="hwpa-modal-close" id="hwpa-modal-close">&times;</button>
                </div>
                <div class="hwpa-modal-body" id="hwpa-modal-body">Loading…</div>
                <div class="hwpa-modal-foot">
                    <div class="hwpa-spinner" id="hwpa-modal-spinner"></div>
                    <button class="hwpa-btn hwpa-btn-secondary" id="hwpa-modal-cancel">Cancel</button>
                    <button class="hwpa-btn hwpa-btn-primary" id="hwpa-modal-execute" style="display:none;">Execute</button>
                </div>
            </div>
        </div>

        <!-- ══════════ PROMPTS MODAL ══════════ -->
        <div class="hwpa-prompts-overlay" id="hwpa-prompts-overlay">
            <div class="hwpa-prompts-modal">
                <div class="hwpa-prompts-modal-head">
                    <div>
                        <h3>💡 Example Prompts</h3>
                        <p>Click any prompt to send it to the AI Assistant instantly.</p>
                    </div>
                    <button class="hwpa-prompts-modal-close" id="hwpa-prompts-close">&times;</button>
                </div>
                <div class="hwpa-prompts-body">
                    <?php foreach ( $all_prompts as $group ) : ?>
                    <div class="hwpa-prompt-category">
                        <div class="hwpa-prompt-category-label"><?php echo esc_html( $group['category'] ); ?></div>
                        <div class="hwpa-prompt-chips">
                            <?php foreach ( $group['chips'] as $chip ) : ?>
                            <button class="hwpa-prompt-chip" data-prompt="<?php echo esc_attr( $chip['text'] ); ?>">
                                <span class="chip-icon"><?php echo esc_html( $chip['icon'] ); ?></span>
                                <?php echo esc_html( $chip['text'] ); ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ══════════ INFO MODAL ══════════ -->
        <div class="hwpa-info-overlay" id="hwpa-info-overlay">
            <div class="hwpa-info-modal">
                <div class="hwpa-info-modal-head">
                    <h3 id="hwpa-info-title"></h3>
                    <button class="hwpa-info-modal-close" id="hwpa-info-close">&times;</button>
                </div>
                <div class="hwpa-info-modal-body" id="hwpa-info-body"></div>
            </div>
        </div>

        <!-- ══════════ TOAST CONTAINER ══════════ -->
        <div class="hwpa-toast-container" id="hwpa-toasts"></div>

        <script>
        (function () {
            'use strict';

            var NONCE       = '<?php echo esc_js( $nonce ); ?>';
            var AJAX        = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
            var API_URL     = '<?php echo $api_url; ?>';
            var PUB_KEY     = '<?php echo $pub_key; ?>';
            var ADM_SEC     = '<?php echo $adm_sec; ?>';
            var SITE_URL    = '<?php echo esc_js( site_url() ); ?>';
            var WP_API_USER = '<?php echo $wp_api_user; ?>';
            var WP_APP_PASS = '<?php echo $wp_app_pass; ?>';

            /* ── Tab switching ─────────────────────────────────────────── */
            document.querySelectorAll('.hwpa-tab').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    document.querySelectorAll('.hwpa-tab').forEach(function (b) { b.classList.remove('active'); });
                    document.querySelectorAll('.hwpa-panel').forEach(function (p) { p.classList.remove('active'); });
                    btn.classList.add('active');
                    var panel = document.getElementById('hwpa-panel-' + btn.dataset.tab);
                    if (panel) panel.classList.add('active');
                    if (btn.dataset.tab === 'scheduler') loadScheduler();
                });
            });

            /* ── Toast ─────────────────────────────────────────────────── */
            function toast(msg, type, undoFn) {
                type = type || 'success';
                var el = document.createElement('div');
                el.className = 'hwpa-toast ' + type;
                var icon = type === 'success'
                    ? '<svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="#10b981" stroke-width="2" stroke-linecap="round" d="M5 13l4 4L19 7"/></svg>'
                    : '<svg width="16" height="16" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="#ef4444" stroke-width="2"/><path stroke="#ef4444" stroke-width="2" stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>';
                el.innerHTML = '<span class="hwpa-toast-icon">' + icon + '</span><span style="flex:1;">' + msg + '</span>';
                if (undoFn) {
                    var undoBtn = document.createElement('button');
                    undoBtn.style.cssText = 'background:none;border:none;color:#6366f1;cursor:pointer;font-size:12px;font-weight:600;padding:0;';
                    undoBtn.textContent = 'Undo';
                    undoBtn.addEventListener('click', function () {
                        undoFn();
                        el.remove();
                    });
                    el.appendChild(undoBtn);
                }
                document.getElementById('hwpa-toasts').appendChild(el);
                setTimeout(function () { el.remove(); }, 6000);
            }

            /* ── AJAX helper ───────────────────────────────────────────── */
            function ajaxPost(action, data, cb) {
                var fd = new FormData();
                fd.append('action', action);
                fd.append('nonce', NONCE);
                for (var k in data) { if (data.hasOwnProperty(k)) fd.append(k, data[k]); }
                fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(cb)
                    .catch(function (e) { cb({ success: false, data: e.message }); });
            }

            /* ═══════════════════════════════════════════════════════════
             *  QUICK ACTIONS
             * ═══════════════════════════════════════════════════════════ */
            var currentAction = null;
            var modal         = document.getElementById('hwpa-modal');
            var modalTitle    = document.getElementById('hwpa-modal-title');
            var modalBody     = document.getElementById('hwpa-modal-body');
            var modalExec     = document.getElementById('hwpa-modal-execute');
            var modalCancel   = document.getElementById('hwpa-modal-cancel');
            var modalClose    = document.getElementById('hwpa-modal-close');
            var modalSpinner  = document.getElementById('hwpa-modal-spinner');

            function openModal(action, label, highRisk, reversible) {
                currentAction = { id: action, label: label, highRisk: highRisk, reversible: reversible };
                modalTitle.textContent = label + ' — Preview';
                modalBody.innerHTML = '<p style="color:#9ca3af;">Running dry-run preview…</p>';
                modalExec.style.display = 'none';
                modal.classList.add('open');

                ajaxPost('eshopeo_action_' + action, { dry_run: '1' }, function (res) {
                    if (!res.success) {
                        modalBody.innerHTML = '<p style="color:#ef4444;">Error: ' + escHtml(String(res.data || 'Unknown error')) + '</p>';
                        return;
                    }
                    var d = res.data;
                    var html = '';
                    if (highRisk) {
                        html += '<div class="hwpa-modal-warning">&#9888; This action is <strong>high-risk</strong> and cannot be undone. Type <strong>CONFIRM</strong> below to proceed.</div>';
                    }
                    html += '<pre style="background:#f3f4f6;border-radius:6px;padding:12px;font-size:12px;white-space:pre-wrap;word-break:break-all;">' + escHtml(JSON.stringify(d, null, 2)) + '</pre>';
                    if (highRisk) {
                        html += '<label style="font-size:12px;font-weight:500;color:#374151;">Type CONFIRM to enable execution:</label>';
                        html += '<input type="text" id="hwpa-confirm-input" class="hwpa-confirm-input" placeholder="CONFIRM">';
                    }
                    modalBody.innerHTML = html;
                    modalExec.style.display = 'inline-flex';

                    if (highRisk) {
                        var confirmInput = document.getElementById('hwpa-confirm-input');
                        confirmInput.addEventListener('input', function () {
                            modalExec.disabled = this.value !== 'CONFIRM';
                        });
                        modalExec.disabled = true;
                    }
                });
            }

            function closeModal() {
                modal.classList.remove('open');
                currentAction = null;
            }

            modalClose.addEventListener('click', closeModal);
            modalCancel.addEventListener('click', closeModal);
            modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

            modalExec.addEventListener('click', function () {
                if (!currentAction) return;
                modalExec.disabled = true;
                modalSpinner.style.display = 'inline-block';

                ajaxPost('eshopeo_action_' + currentAction.id, { dry_run: '0' }, function (res) {
                    modalSpinner.style.display = 'none';
                    closeModal();

                    if (!res.success) {
                        toast('Error: ' + escHtml(String(res.data || 'Unknown error')), 'error');
                        return;
                    }
                    var d        = res.data;
                    var snapId   = d.snapshot_id || null;
                    var label    = currentAction ? currentAction.label : '';
                    var affected = d.affected !== undefined ? d.affected : (d.count || 0);
                    var msg      = '<strong>' + escHtml(label) + '</strong> completed. ' + affected + ' row(s) affected.';

                    var undoFn = null;
                    if (snapId && currentAction && currentAction.reversible) {
                        undoFn = function () { runRollback(snapId, label); };
                    }

                    toast(msg, 'success', undoFn);
                    currentAction = null;
                });
            });

            document.querySelectorAll('.hwpa-preview-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    openModal(
                        btn.dataset.action,
                        btn.dataset.label,
                        btn.dataset.highRisk === '1',
                        btn.dataset.reversible === '1'
                    );
                });
            });

            /* metric fix buttons — opened via Performance tab */
            document.addEventListener('click', function (e) {
                if (e.target.classList.contains('hwpa-metric-fix')) {
                    var btn = e.target;
                    openModal(btn.dataset.action, btn.dataset.label, false, btn.dataset.reversible === '1');
                }
            });

            /* ── Rollback ──────────────────────────────────────────────── */
            function runRollback(snapId, label) {
                ajaxPost('eshopeo_action_rollback', { snapshot_id: snapId }, function (res) {
                    if (res.success) {
                        toast('<strong>' + escHtml(label) + '</strong> rolled back successfully.', 'success');
                    } else {
                        toast('Rollback failed: ' + escHtml(String(res.data || 'Unknown error')), 'error');
                    }
                });
            }

            /* ═══════════════════════════════════════════════════════════
             *  PERFORMANCE DASHBOARD
             * ═══════════════════════════════════════════════════════════ */
            var METRIC_FIX_MAP = {
                expired_transient_count: { action: 'clear_transients',   label: 'Clear Expired Transients',   reversible: false },
                table_overhead_mb:       { action: 'optimize_tables',    label: 'Optimize Tables',            reversible: false },
                revision_count:          { action: 'delete_revisions',   label: 'Delete Old Revisions',       reversible: true  },
                orphaned_meta_count:     { action: 'delete_orphaned_meta',label: 'Delete Orphaned Meta',      reversible: true  },
                autoload_size_mb:        { action: 'fix_autoload',       label: 'Fix Autoloaded Options',     reversible: true  },
            };

            document.getElementById('hwpa-refresh-metrics').addEventListener('click', function () {
                var spinner = document.getElementById('hwpa-perf-spinner');
                spinner.style.display = 'inline-block';
                ajaxPost('eshopeo_performance_metrics', {}, function (res) {
                    spinner.style.display = 'none';
                    if (!res.success) {
                        document.getElementById('hwpa-perf-body').innerHTML = '<p style="color:#ef4444;">Failed to load metrics.</p>';
                        return;
                    }
                    renderMetrics(res.data);
                });
            });

            function gradeClass(g) { return 'hwpa-grade-' + g; }

            function renderMetrics(d) {
                var gradeColors = { A:'#10b981',B:'#3b82f6',C:'#f59e0b',D:'#f97316',F:'#ef4444' };
                var gc = gradeColors[d.overall_grade] || '#6b7280';

                var html = '<div style="display:flex;align-items:center;margin-bottom:20px;">';
                html += '<div class="hwpa-overall-grade ' + gradeClass(d.overall_grade) + '" style="background:' + gc + '1a;color:' + gc + ';">' + escHtml(d.overall_grade) + '</div>';
                html += '<div><div style="font-size:16px;font-weight:700;color:#1e1b4b;">Overall Score: ' + escHtml(d.overall_grade) + '</div>';
                html += '<div style="font-size:12px;color:#6b7280;">PHP ' + escHtml(d.php_version) + ' &bull; WP ' + escHtml(d.wp_version) + ' &bull; Memory ' + escHtml(String(d.memory_pct)) + '% of ' + escHtml(String(d.memory_limit_mb)) + ' MB</div></div>';
                html += '</div>';

                var metrics = [
                    { key: 'autoload_size_mb',        label: 'Autoload Size',          val: d.autoload_size_mb + ' MB',  grade: d.autoload_grade,        fix: 'fix_autoload' },
                    { key: 'total_options',            label: 'Total Options',          val: d.total_options,             grade: d.total_options_grade,    fix: null },
                    { key: 'expired_transient_count',  label: 'Expired Transients',     val: d.expired_transient_count,   grade: d.transient_grade,        fix: 'clear_transients' },
                    { key: 'table_overhead_mb',        label: 'Table Overhead',         val: d.table_overhead_mb + ' MB', grade: d.overhead_grade,         fix: 'optimize_tables' },
                    { key: 'revision_count',           label: 'Post Revisions',         val: d.revision_count,            grade: d.revision_grade,         fix: 'delete_revisions' },
                    { key: 'orphaned_meta_count',      label: 'Orphaned Post Meta',     val: d.orphaned_meta_count,       grade: d.orphaned_meta_grade,    fix: 'delete_orphaned_meta' },
                    { key: 'spam_count',               label: 'Spam + Trash',           val: d.spam_count + ' spam / ' + d.trash_count + ' trash', grade: d.spam_grade, fix: null },
                    { key: 'active_plugin_count',      label: 'Active Plugins',         val: d.active_plugin_count,       grade: d.plugin_grade,           fix: null },
                    { key: 'cron_backlog',             label: 'Cron Backlog',           val: d.cron_backlog + ' events',  grade: d.cron_grade,             fix: null },
                    { key: 'php_version',              label: 'PHP Version',            val: d.php_version,               grade: d.php_grade,              fix: null },
                    { key: 'object_cache_enabled',     label: 'Object Cache',           val: d.object_cache_enabled ? 'Enabled' : 'Disabled', grade: d.object_cache_enabled ? 'A' : 'C', fix: null },
                    { key: 'heartbeat_interval',       label: 'Heartbeat Interval',     val: d.heartbeat_interval,        grade: d.heartbeat_interval == 60 ? 'A' : 'C', fix: 'set_heartbeat' },
                ];

                html += '<div class="hwpa-metric-grid">';
                metrics.forEach(function (m) {
                    var fixBtn = '';
                    if (m.fix) {
                        var fixDef = METRIC_FIX_MAP[m.key] || { action: m.fix, label: m.label, reversible: false };
                        fixBtn = '<br><button class="hwpa-metric-fix hwpa-preview-btn" data-action="' + escAttr(fixDef.action) + '" data-label="' + escAttr(fixDef.label) + '" data-high-risk="0" data-reversible="' + (fixDef.reversible ? '1' : '0') + '">&#9889; Fix</button>';
                    }
                    html += '<div class="hwpa-metric-card">';
                    html += '<span class="hwpa-grade-pill ' + gradeClass(m.grade) + '">' + escHtml(m.grade) + '</span>';
                    html += '<div class="hwpa-metric-val">' + escHtml(String(m.val)) + '</div>';
                    html += '<div class="hwpa-metric-lbl">' + escHtml(m.label) + '</div>';
                    html += fixBtn;
                    html += '</div>';
                });
                html += '</div>';

                document.getElementById('hwpa-perf-body').innerHTML = html;
            }

            /* ═══════════════════════════════════════════════════════════
             *  SCHEDULER
             * ═══════════════════════════════════════════════════════════ */
            function loadScheduler() {
                ajaxPost('eshopeo_scheduler_status', {}, function (res) {
                    if (!res.success) {
                        document.getElementById('hwpa-sched-body').innerHTML = '<p style="color:#ef4444;">Failed to load schedule.</p>';
                        return;
                    }
                    renderScheduler(res.data);
                });
            }

            function renderScheduler(jobs) {
                var html = '<div style="overflow-x:auto;"><table class="hwpa-sched-table">';
                html += '<thead><tr><th>Job</th><th>Frequency</th><th>Last Run</th><th>Next Run</th><th>Status</th><th>Actions</th></tr></thead>';
                html += '<tbody>';
                jobs.forEach(function (job) {
                    var toggleId = 'sched-toggle-' + job.hook;
                    html += '<tr>';
                    html += '<td><strong>' + escHtml(job.label) + '</strong><br><span style="font-size:11px;color:#6b7280;">' + escHtml(job.description) + '</span></td>';
                    html += '<td><span style="text-transform:capitalize;">' + escHtml(job.frequency) + '</span></td>';
                    html += '<td>' + (job.last_run ? escHtml(job.last_run) : '<span style="color:#9ca3af;">Never</span>') + '</td>';
                    html += '<td>' + (job.next_run ? escHtml(job.next_run) : '<span style="color:#9ca3af;">Not scheduled</span>') + '</td>';
                    html += '<td><label class="hwpa-toggle"><input type="checkbox" id="' + escAttr(toggleId) + '"' + (job.enabled ? ' checked' : '') + ' data-hook="' + escAttr(job.hook) + '"><span class="hwpa-toggle-slider"></span></label></td>';
                    html += '<td><button class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm hwpa-run-now-btn" data-hook="' + escAttr(job.hook) + '">Run Now</button></td>';
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
                document.getElementById('hwpa-sched-body').innerHTML = html;

                // Toggle handlers.
                document.querySelectorAll('.hwpa-sched-table input[type=checkbox]').forEach(function (cb) {
                    cb.addEventListener('change', function () {
                        ajaxPost('eshopeo_scheduler_toggle', { hook: cb.dataset.hook, enabled: cb.checked ? '1' : '0' }, function (res) {
                            if (res.success) { renderScheduler(res.data); }
                            else { toast('Toggle failed: ' + escHtml(String(res.data || '')), 'error'); }
                        });
                    });
                });

                // Run Now handlers.
                document.querySelectorAll('.hwpa-run-now-btn').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        btn.disabled = true;
                        ajaxPost('eshopeo_scheduler_run_now', { hook: btn.dataset.hook }, function (res) {
                            btn.disabled = false;
                            if (res.success) {
                                toast('Job <strong>' + escHtml(btn.dataset.hook) + '</strong> executed.', 'success');
                                renderScheduler(res.data.jobs);
                            } else {
                                toast('Run failed: ' + escHtml(String(res.data || '')), 'error');
                            }
                        });
                    });
                });
            }

            /* ═══════════════════════════════════════════════════════════
             *  AI ASSISTANT
             * ═══════════════════════════════════════════════════════════ */
            var chatMsgs    = document.getElementById('hwpa-chat-msgs');
            var chatInput   = document.getElementById('hwpa-chat-input');
            var chatSend    = document.getElementById('hwpa-chat-send');
            var chatHistory = [];

            function appendBubble(cls, html) {
                var div = document.createElement('div');
                div.className = 'hwpa-chat-bubble ' + cls;
                div.innerHTML = html;
                chatMsgs.appendChild(div);
                chatMsgs.scrollTop = chatMsgs.scrollHeight;
                return div;
            }

            var _hwpaThinkTimer = null;
            var _hwpaThinkMsgs  = [
                'Thinking…',
                'Connecting to WordPress…',
                'Fetching data…',
                'Running tools…',
                'Analyzing results…',
                'Writing response…'
            ];

            function showTyping() {
                var div = document.createElement('div');
                div.className = 'hwpa-chat-bubble assistant';
                div.id = 'hwpa-typing';
                div.innerHTML = '<div class="hwpa-thinking"><div class="hwpa-thinking-dots"><span></span><span></span><span></span></div><span class="hwpa-thinking-status" id="hwpa-thinking-status">Thinking…</span></div>';
                chatMsgs.appendChild(div);
                chatMsgs.scrollTop = chatMsgs.scrollHeight;
                chatInput.disabled = true;
                var idx = 0;
                _hwpaThinkTimer = setInterval(function () {
                    idx = (idx + 1) % _hwpaThinkMsgs.length;
                    var el = document.getElementById('hwpa-thinking-status');
                    if (!el) return;
                    el.style.opacity = '0';
                    setTimeout(function () {
                        var el2 = document.getElementById('hwpa-thinking-status');
                        if (el2) { el2.textContent = _hwpaThinkMsgs[idx]; el2.style.opacity = '1'; }
                    }, 200);
                }, 2500);
            }
            function hideTyping() {
                if (_hwpaThinkTimer) { clearInterval(_hwpaThinkTimer); _hwpaThinkTimer = null; }
                var el = document.getElementById('hwpa-typing');
                if (el) el.remove();
                chatInput.disabled = false;
            }

            /* ── Prompts modal ──────────────────────────────────────────── */
            var promptsOverlay = document.getElementById('hwpa-prompts-overlay');
            document.getElementById('hwpa-prompts-open').addEventListener('click', function () {
                promptsOverlay.classList.add('open');
            });
            document.getElementById('hwpa-prompts-close').addEventListener('click', function () {
                promptsOverlay.classList.remove('open');
            });
            promptsOverlay.addEventListener('click', function (e) {
                if (e.target === promptsOverlay) promptsOverlay.classList.remove('open');
            });
            document.querySelectorAll('.hwpa-prompt-chip').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    var prompt = chip.dataset.prompt;
                    promptsOverlay.classList.remove('open');
                    // Switch to AI Assistant tab first
                    document.querySelectorAll('.hwpa-tab').forEach(function (b) { b.classList.remove('active'); });
                    document.querySelectorAll('.hwpa-panel').forEach(function (p) { p.classList.remove('active'); });
                    var aiTab = document.querySelector('.hwpa-tab[data-tab="ai-assistant"]');
                    if (aiTab) aiTab.classList.add('active');
                    var aiPanel = document.getElementById('hwpa-panel-ai-assistant');
                    if (aiPanel) aiPanel.classList.add('active');
                    // Fill and send
                    chatInput.value = prompt;
                    sendChat();
                });
            });

            chatSend.addEventListener('click', sendChat);
            chatInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChat(); }
            });

            function sendChat() {
                var msg = chatInput.value.trim();
                if (!msg) return;
                chatInput.value = '';
                appendBubble('user', escHtml(msg));
                chatHistory.push({ role: 'user', content: msg });
                chatSend.disabled = true;
                showTyping();

                fetch(API_URL + '/v1/wp-agent/chat', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        message:         msg,
                        history:         chatHistory.slice(0, -1),
                        site_url:        SITE_URL,
                        admin_secret:    ADM_SEC,
                        wp_username:     WP_API_USER,
                        wp_app_password: WP_APP_PASS,
                    }),
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    hideTyping();
                    chatSend.disabled = false;
                    if (res.error) {
                        appendBubble('assistant', '<span style="color:#ef4444;">' + escHtml(res.error) + '</span>');
                        return;
                    }
                    // Show tool calls as collapsible chips.
                    if (res.tool_calls && res.tool_calls.length) {
                        var tcHtml = '<div class="hwpa-tool-calls">';
                        res.tool_calls.forEach(function (tc) {
                            var label = tc.name.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
                            var detail = JSON.stringify(tc.result, null, 2);
                            tcHtml += '<details class="hwpa-tool-detail"><summary><span class="hwpa-tool-chip"><span class="hwpa-tool-chip-icon">⚙</span> ' + escHtml(label) + '</span></summary><pre class="hwpa-tool-pre">' + escHtml(detail) + '</pre></details>';
                        });
                        tcHtml += '</div>';
                        appendBubble('tool-result', tcHtml);
                    }
                    var reply = res.response || '(No response)';
                    appendBubble('assistant', escHtml(reply).replace(/\n/g, '<br>'));
                    chatHistory.push({ role: 'assistant', content: reply });
                })
                .catch(function (e) {
                    hideTyping();
                    chatSend.disabled = false;
                    appendBubble('assistant', '<span style="color:#ef4444;">Request failed: ' + escHtml(e.message) + '</span>');
                });
            }

            /* ═══════════════════════════════════════════════════════════
             *  ACTION LOG
             * ═══════════════════════════════════════════════════════════ */
            document.getElementById('hwpa-log-clear').addEventListener('click', function () {
                if (!confirm('Clear the entire action log?')) return;
                ajaxPost('eshopeo_agent_log_clear', {}, function (res) {
                    if (res.success) {
                        document.getElementById('hwpa-log-body').innerHTML = '<p style="text-align:center;color:#9ca3af;padding:40px 20px;">Log cleared.</p>';
                        toast('Action log cleared.', 'success');
                    } else {
                        toast('Failed to clear log.', 'error');
                    }
                });
            });

            /* Log undo buttons (delegated) */
            document.getElementById('hwpa-log-body').addEventListener('click', function (e) {
                if (e.target.classList.contains('hwpa-log-undo-btn')) {
                    var btn    = e.target;
                    var snapId = btn.dataset.snapshot;
                    var label  = btn.dataset.label;
                    runRollback(snapId, label);
                }
            });

            /* ── Init scheduler tab if active ─────────────────────────── */
            if (document.querySelector('.hwpa-tab[data-tab="scheduler"]').classList.contains('active')) {
                loadScheduler();
            }

            /* ═══════════════════════════════════════════════════════════
             *  UPTIME MONITOR
             * ═══════════════════════════════════════════════════════════ */
            (function () {
                var uptimeBody = document.getElementById('hwpa-uptime-body');
                function loadUptime() {
                    ajaxPost('eshopeo_uptime_data', {}, function (res) {
                        if (!res.success) { uptimeBody.innerHTML = '<p style="color:#ef4444;">Failed to load uptime data.</p>'; return; }
                        var d = res.data;
                        var statusColor = d.status === 'up' ? '#10b981' : '#ef4444';
                        var statusLabel = d.status === 'up' ? 'UP' : 'DOWN';
                        var html = '<div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">';
                        html += '<div style="display:inline-flex;align-items:center;padding:4px 14px;background:' + statusColor + '20;color:' + statusColor + ';border-radius:20px;font-size:14px;font-weight:700;">' + escHtml(statusLabel) + '</div>';
                        html += '<div style="font-size:13px;"><strong>' + escHtml(String(d.uptime_pct)) + '%</strong> uptime</div>';
                        html += '<div style="font-size:13px;"><strong>' + escHtml(String(d.avg_ms)) + 'ms</strong> avg response</div>';
                        if (d.last_check) { html += '<div style="font-size:12px;color:#9ca3af;">Last check: ' + escHtml(d.last_check) + '</div>'; }
                        html += '</div>';
                        if (d.sparkline && d.sparkline.length) {
                            var maxMs = Math.max.apply(null, d.sparkline) || 1;
                            html += '<div style="display:flex;align-items:flex-end;gap:2px;height:40px;margin-top:12px;">';
                            d.sparkline.forEach(function (v) {
                                var h = v ? Math.max(4, Math.round((v / maxMs) * 40)) : 2;
                                var c = v > 0 ? '#6366f1' : '#e5e7eb';
                                html += '<div style="flex:1;background:' + c + ';height:' + h + 'px;border-radius:2px 2px 0 0;" title="' + v + 'ms"></div>';
                            });
                            html += '</div><div style="font-size:10px;color:#9ca3af;margin-top:2px;">24h response time (hourly avg)</div>';
                        }
                        uptimeBody.innerHTML = html;
                    });
                }
                document.getElementById('hwpa-uptime-refresh').addEventListener('click', loadUptime);
                // Load when performance tab is active.
                document.querySelectorAll('.hwpa-tab[data-tab="performance"]').forEach(function (t) {
                    t.addEventListener('click', function () { setTimeout(loadUptime, 50); });
                });
                loadUptime();
            })();

            /* ═══════════════════════════════════════════════════════════
             *  WEB VITALS
             * ═══════════════════════════════════════════════════════════ */
            (function () {
                var vitalsBody    = document.getElementById('hwpa-vitals-body');
                var vitalsSpinner = document.getElementById('hwpa-vitals-spinner');
                document.getElementById('hwpa-vitals-scan').addEventListener('click', function () {
                    vitalsSpinner.style.display = 'inline-block';
                    ajaxPost('eshopeo_web_vitals_scan', { force: '1' }, function (res) {
                        vitalsSpinner.style.display = 'none';
                        if (!res.success) { vitalsBody.innerHTML = '<p style="color:#ef4444;">' + escHtml(String(res.data || 'Failed')) + '</p>'; return; }
                        var d   = res.data;
                        var sc  = d.score || 0;
                        var col = sc >= 90 ? '#10b981' : sc >= 50 ? '#f59e0b' : '#ef4444';
                        var html = '<div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:16px;">';
                        html += '<div style="width:60px;height:60px;border-radius:50%;background:' + col + '20;color:' + col + ';display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:800;">' + sc + '</div>';
                        html += '<div><div style="font-size:14px;font-weight:600;">Performance Score</div><div style="font-size:12px;color:#6b7280;">' + escHtml(d.strategy || 'mobile') + ' · ' + (d.from_cache ? 'Cached' : 'Live') + '</div></div></div>';
                        var metrics = [
                            { key: 'lcp',  label: 'LCP' },
                            { key: 'fcp',  label: 'FCP' },
                            { key: 'inp',  label: 'INP' },
                            { key: 'cls',  label: 'CLS' },
                            { key: 'ttfb', label: 'TTFB' },
                        ];
                        html += '<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px;">';
                        metrics.forEach(function (m) {
                            var mv = d[m.key] || {};
                            var rc = { good: '#10b981', 'needs-improvement': '#f59e0b', poor: '#ef4444' }[mv.rating] || '#6b7280';
                            html += '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px;text-align:center;">';
                            html += '<div style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;">' + escHtml(m.label) + '</div>';
                            html += '<div style="font-size:16px;font-weight:700;color:' + rc + ';margin:4px 0;">' + escHtml(mv.display || '—') + '</div>';
                            html += '<div style="font-size:10px;color:' + rc + ';text-transform:capitalize;">' + escHtml(mv.rating || '') + '</div>';
                            html += '</div>';
                        });
                        html += '</div>';
                        vitalsBody.innerHTML = html;
                    });
                });
            })();

            /* ═══════════════════════════════════════════════════════════
             *  BROKEN LINKS
             * ═══════════════════════════════════════════════════════════ */
            (function () {
                var blResults  = document.getElementById('hwpa-bl-results');
                var blProgress = document.getElementById('hwpa-bl-progress');
                var blBar      = document.getElementById('hwpa-bl-bar');
                var blProgText = document.getElementById('hwpa-bl-progress-text');
                var blSpinner  = document.getElementById('hwpa-bl-spinner');
                var allBroken  = [];

                document.getElementById('hwpa-bl-scan').addEventListener('click', function () {
                    allBroken = [];
                    blProgress.style.display = 'block';
                    blBar.style.width = '0%';
                    blResults.innerHTML = '';
                    blSpinner.style.display = 'inline-block';
                    scanBatch(0);
                });

                function scanBatch(offset) {
                    ajaxPost('eshopeo_scan_broken_links', { offset: offset, batch: 20, force: '1' }, function (res) {
                        if (!res.success) {
                            blSpinner.style.display = 'none';
                            blProgress.style.display = 'none';
                            blResults.innerHTML = '<p style="color:#ef4444;">Scan failed.</p>';
                            return;
                        }
                        var d = res.data;
                        if (d.from_cache) {
                            allBroken = d.links || [];
                            renderBrokenLinks();
                            blSpinner.style.display = 'none';
                            blProgress.style.display = 'none';
                            document.getElementById('hwpa-bl-last-scan').textContent = 'Results from cache.';
                            return;
                        }
                        allBroken = allBroken.concat(d.links || []);
                        var pct = d.total > 0 ? Math.round((d.scanned / d.total) * 100) : 100;
                        blBar.style.width = pct + '%';
                        blProgText.textContent = 'Scanned ' + d.scanned + ' of ' + d.total + ' links…';
                        if (d.is_last_batch) {
                            blSpinner.style.display = 'none';
                            blProgress.style.display = 'none';
                            document.getElementById('hwpa-bl-last-scan').textContent = 'Scan complete. Found ' + allBroken.length + ' broken link(s).';
                            renderBrokenLinks();
                        } else {
                            scanBatch(d.offset + d.batch);
                        }
                    });
                }

                function renderBrokenLinks() {
                    if (!allBroken.length) {
                        blResults.innerHTML = '<p style="color:#10b981;font-weight:600;">No broken links found!</p>';
                        return;
                    }
                    var html = '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;">';
                    html += '<thead><tr><th style="text-align:left;padding:8px;border-bottom:2px solid #e5e7eb;">Post</th><th style="text-align:left;padding:8px;border-bottom:2px solid #e5e7eb;">Broken URL</th><th style="padding:8px;border-bottom:2px solid #e5e7eb;">Status</th><th style="padding:8px;border-bottom:2px solid #e5e7eb;">Fix</th></tr></thead><tbody>';
                    allBroken.forEach(function (l) {
                        html += '<tr>';
                        html += '<td style="padding:8px;border-bottom:1px solid #f3f4f6;">' + escHtml(l.post_title) + '</td>';
                        html += '<td style="padding:8px;border-bottom:1px solid #f3f4f6;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escAttr(l.url) + '">' + escHtml(l.url) + '</td>';
                        html += '<td style="padding:8px;border-bottom:1px solid #f3f4f6;text-align:center;"><span style="background:#fee2e2;color:#7f1d1d;padding:2px 8px;border-radius:12px;font-size:11px;">' + escHtml(String(l.status_code || l.error || '?')) + '</span></td>';
                        html += '<td style="padding:8px;border-bottom:1px solid #f3f4f6;text-align:center;"><button class="hwpa-btn hwpa-btn-ghost hwpa-btn-sm hwpa-bl-fix-btn" data-post-id="' + escAttr(String(l.post_id)) + '" data-old-url="' + escAttr(l.url) + '">Fix</button></td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table></div>';
                    blResults.innerHTML = html;
                    blResults.querySelectorAll('.hwpa-bl-fix-btn').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var newUrl = prompt('Replace broken URL:\n' + btn.dataset.oldUrl + '\n\nEnter new URL:');
                            if (!newUrl) return;
                            btn.disabled = true;
                            ajaxPost('eshopeo_broken_links_fix', { post_id: btn.dataset.postId, old_url: btn.dataset.oldUrl, new_url: newUrl }, function (res) {
                                if (res.success) {
                                    toast('Link replaced successfully.', 'success');
                                    btn.closest('tr').remove();
                                } else {
                                    toast('Fix failed: ' + escHtml(String(res.data || '')), 'error');
                                    btn.disabled = false;
                                }
                            });
                        });
                    });
                }
            })();

            /* ═══════════════════════════════════════════════════════════
             *  ERROR LOG
             * ═══════════════════════════════════════════════════════════ */
            (function () {
                var elResults  = document.getElementById('hwpa-el-results');
                var elBadges   = document.getElementById('hwpa-el-badges');
                var elSpinner  = document.getElementById('hwpa-el-spinner');
                var elLevel    = '';
                var allEntries = [];

                function loadErrorLog(level) {
                    elSpinner.style.display = 'inline-block';
                    ajaxPost('eshopeo_get_error_log', { level: level || '' }, function (res) {
                        elSpinner.style.display = 'none';
                        if (!res.success) { elResults.innerHTML = '<p style="color:#ef4444;">Failed to load log.</p>'; return; }
                        var d = res.data;
                        allEntries = d.entries || [];
                        var counts  = d.counts || {};
                        var badgeColors = { Fatal: '#ef4444', Warning: '#f59e0b', Notice: '#6366f1', Deprecated: '#6b7280' };
                        var badgeHtml = '';
                        Object.keys(counts).forEach(function (k) {
                            badgeHtml += '<span style="background:' + (badgeColors[k] || '#e5e7eb') + '20;color:' + (badgeColors[k] || '#374151') + ';border:1px solid currentColor;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;">' + escHtml(k) + ': ' + counts[k] + '</span>';
                        });
                        elBadges.innerHTML = badgeHtml;
                        if (!d.exists) { elResults.innerHTML = '<p style="color:#9ca3af;">No debug.log file found.</p>'; return; }
                        if (!allEntries.length) { elResults.innerHTML = '<p style="color:#10b981;">Log is empty.</p>'; return; }
                        var html = '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:11px;">';
                        html += '<thead><tr><th style="text-align:left;padding:6px 8px;border-bottom:2px solid #e5e7eb;">Timestamp</th><th style="padding:6px 8px;border-bottom:2px solid #e5e7eb;">Level</th><th style="text-align:left;padding:6px 8px;border-bottom:2px solid #e5e7eb;">Message</th><th style="text-align:left;padding:6px 8px;border-bottom:2px solid #e5e7eb;">File:Line</th></tr></thead><tbody>';
                        allEntries.forEach(function (e) {
                            var lc = badgeColors[e.level] || '#6b7280';
                            html += '<tr>';
                            html += '<td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;white-space:nowrap;color:#9ca3af;">' + escHtml(e.timestamp) + '</td>';
                            html += '<td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;text-align:center;"><span style="background:' + lc + '20;color:' + lc + ';padding:1px 6px;border-radius:10px;font-size:10px;font-weight:600;">' + escHtml(e.level) + '</span></td>';
                            html += '<td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;max-width:360px;">' + escHtml(e.message) + '</td>';
                            html += '<td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;color:#6b7280;">' + (e.file ? escHtml(e.file.split('/').slice(-1)[0]) + ':' + escHtml(e.line) : '—') + '</td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table></div>';
                        elResults.innerHTML = html;
                    });
                }

                document.getElementById('hwpa-el-refresh').addEventListener('click', function () { loadErrorLog(elLevel); });
                document.getElementById('hwpa-el-clear').addEventListener('click', function () {
                    if (!confirm('Clear the PHP error log? This cannot be undone.')) return;
                    elSpinner.style.display = 'inline-block';
                    ajaxPost('eshopeo_clear_error_log', {}, function (res) {
                        elSpinner.style.display = 'none';
                        if (res.success) { toast('Error log cleared.', 'success'); loadErrorLog(''); }
                        else { toast('Clear failed.', 'error'); }
                    });
                });
                document.querySelectorAll('.hwpa-el-filter').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        elLevel = btn.dataset.level;
                        loadErrorLog(elLevel);
                    });
                });
                // Auto-load when tab opened.
                document.querySelectorAll('.hwpa-tab[data-tab="error-log"]').forEach(function (t) {
                    t.addEventListener('click', function () { loadErrorLog(''); });
                });
            })();

            /* ═══════════════════════════════════════════════════════════
             *  BACKUPS
             * ═══════════════════════════════════════════════════════════ */
            (function () {
                var bkResults = document.getElementById('hwpa-bk-results');
                var bkSpinner = document.getElementById('hwpa-bk-spinner');

                function loadBackups() {
                    ajaxPost('eshopeo_list_backups', {}, function (res) {
                        if (!res.success) { bkResults.innerHTML = '<p style="color:#ef4444;">Failed to load backups.</p>'; return; }
                        var d = res.data;
                        var backups = d.backups || [];
                        if (!backups.length) { bkResults.innerHTML = '<p style="color:#9ca3af;font-size:13px;">No backups yet. Click "Backup Now" to create one.</p>'; return; }
                        var html = '<p style="font-size:12px;color:#6b7280;margin:0 0 8px;">Total: ' + escHtml(String(d.total_size_kb)) + ' KB</p>';
                        html += '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:13px;">';
                        html += '<thead><tr><th style="text-align:left;padding:8px;border-bottom:2px solid #e5e7eb;">Filename</th><th style="text-align:left;padding:8px;border-bottom:2px solid #e5e7eb;">Date</th><th style="padding:8px;border-bottom:2px solid #e5e7eb;">Size</th><th style="padding:8px;border-bottom:2px solid #e5e7eb;">Actions</th></tr></thead><tbody>';
                        backups.forEach(function (b) {
                            html += '<tr>';
                            html += '<td style="padding:8px;border-bottom:1px solid #f3f4f6;font-size:12px;font-family:monospace;">' + escHtml(b.filename) + '</td>';
                            html += '<td style="padding:8px;border-bottom:1px solid #f3f4f6;">' + escHtml(b.date) + '</td>';
                            html += '<td style="padding:8px;border-bottom:1px solid #f3f4f6;text-align:center;">' + escHtml(String(b.size_kb)) + ' KB</td>';
                            html += '<td style="padding:8px;border-bottom:1px solid #f3f4f6;text-align:center;display:flex;gap:6px;justify-content:center;">';
                            html += '<button class="hwpa-btn hwpa-btn-ghost hwpa-btn-sm hwpa-bk-dl" data-fn="' + escAttr(b.filename) + '">Download</button>';
                            html += '<button class="hwpa-btn hwpa-btn-danger hwpa-btn-sm hwpa-bk-del" data-fn="' + escAttr(b.filename) + '">Delete</button>';
                            html += '</td></tr>';
                        });
                        html += '</tbody></table></div>';
                        bkResults.innerHTML = html;
                        bkResults.querySelectorAll('.hwpa-bk-dl').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                var form = document.createElement('form');
                                form.method = 'POST';
                                form.action = AJAX;
                                ['action','nonce','filename'].forEach(function (k) {
                                    var i = document.createElement('input');
                                    i.type = 'hidden'; i.name = k;
                                    i.value = k === 'action' ? 'eshopeo_download_backup' : (k === 'nonce' ? NONCE : btn.dataset.fn);
                                    form.appendChild(i);
                                });
                                document.body.appendChild(form);
                                form.submit();
                                form.remove();
                            });
                        });
                        bkResults.querySelectorAll('.hwpa-bk-del').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                if (!confirm('Delete ' + btn.dataset.fn + '?')) return;
                                ajaxPost('eshopeo_delete_backup', { filename: btn.dataset.fn }, function (res) {
                                    if (res.success) { toast('Backup deleted.', 'success'); loadBackups(); }
                                    else { toast('Delete failed.', 'error'); }
                                });
                            });
                        });
                    });
                }

                document.getElementById('hwpa-bk-run').addEventListener('click', function () {
                    var btn = this;
                    btn.disabled = true;
                    bkSpinner.style.display = 'inline-block';
                    ajaxPost('eshopeo_run_backup', {}, function (res) {
                        btn.disabled = false;
                        bkSpinner.style.display = 'none';
                        if (res.success) {
                            toast('Backup created: ' + escHtml(res.data.filename) + ' (' + res.data.size_kb + ' KB)', 'success');
                            loadBackups();
                        } else {
                            toast('Backup failed: ' + escHtml(String(res.data || '')), 'error');
                        }
                    });
                });

                document.querySelectorAll('.hwpa-tab[data-tab="backups"]').forEach(function (t) {
                    t.addEventListener('click', loadBackups);
                });
                loadBackups();
            })();

            /* ═══════════════════════════════════════════════════════════
             *  PLUGIN MANAGER
             * ═══════════════════════════════════════════════════════════ */
            (function () {
                var pmResults = document.getElementById('hwpa-pm-results');
                var pmSpinner = document.getElementById('hwpa-pm-spinner');
                var pmFilter  = 'all';
                var allPlugins = [];

                function loadPlugins() {
                    pmSpinner.style.display = 'inline-block';
                    ajaxPost('eshopeo_list_plugins', {}, function (res) {
                        pmSpinner.style.display = 'none';
                        if (!res.success) { pmResults.innerHTML = '<p style="color:#ef4444;">Failed to load plugins.</p>'; return; }
                        allPlugins = res.data.plugins || [];
                        renderPlugins();
                    });
                }

                function renderPlugins() {
                    var list = allPlugins.filter(function (p) {
                        if (pmFilter === 'updates') return p.update_available;
                        if (pmFilter === 'active')  return p.active;
                        if (pmFilter === 'inactive') return !p.active;
                        return true;
                    });
                    if (!list.length) { pmResults.innerHTML = '<p style="color:#9ca3af;font-size:13px;">No plugins found for filter: ' + escHtml(pmFilter) + '.</p>'; return; }
                    var html = '<div style="display:flex;flex-direction:column;gap:8px;">';
                    list.forEach(function (p) {
                        html += '<div style="display:flex;align-items:center;gap:12px;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;flex-wrap:wrap;">';
                        html += '<div style="flex:1;min-width:200px;"><div style="font-size:13px;font-weight:600;">' + escHtml(p.name) + '</div>';
                        html += '<div style="font-size:11px;color:#6b7280;">v' + escHtml(p.version) + (p.update_available ? ' → <strong style="color:#f59e0b;">v' + escHtml(p.new_version) + ' available</strong>' : '') + '</div></div>';
                        html += '<label class="hwpa-toggle" title="Toggle active"><input type="checkbox"' + (p.active ? ' checked' : '') + ' data-file="' + escAttr(p.file) + '" class="hwpa-pm-toggle"><span class="hwpa-toggle-slider"></span></label>';
                        if (p.update_available) {
                            html += '<button class="hwpa-btn hwpa-btn-primary hwpa-btn-sm hwpa-pm-update-btn" data-file="' + escAttr(p.file) + '">Update</button>';
                        }
                        html += '</div>';
                    });
                    html += '</div>';
                    pmResults.innerHTML = html;

                    pmResults.querySelectorAll('.hwpa-pm-toggle').forEach(function (cb) {
                        cb.addEventListener('change', function () {
                            cb.disabled = true;
                            ajaxPost('eshopeo_toggle_plugin', { plugin_file: cb.dataset.file, activate: cb.checked ? '1' : '0' }, function (res) {
                                cb.disabled = false;
                                if (res.success) { toast('Plugin ' + (cb.checked ? 'activated' : 'deactivated') + '.', 'success'); }
                                else { toast('Error: ' + escHtml(String(res.data || '')), 'error'); cb.checked = !cb.checked; }
                            });
                        });
                    });

                    pmResults.querySelectorAll('.hwpa-pm-update-btn').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            btn.disabled = true; btn.textContent = 'Updating…';
                            ajaxPost('eshopeo_update_plugin', { plugin_file: btn.dataset.file }, function (res) {
                                if (res.success) { toast('Updated to v' + escHtml(res.data.new_version), 'success'); loadPlugins(); }
                                else { toast('Update failed: ' + escHtml(String(res.data || '')), 'error'); btn.disabled = false; btn.textContent = 'Update'; }
                            });
                        });
                    });
                }

                document.querySelectorAll('.hwpa-pm-filter').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        document.querySelectorAll('.hwpa-pm-filter').forEach(function (b) { b.classList.remove('active'); });
                        btn.classList.add('active');
                        pmFilter = btn.dataset.filter;
                        renderPlugins();
                    });
                });

                document.getElementById('hwpa-pm-update-all').addEventListener('click', function () {
                    var updates = allPlugins.filter(function (p) { return p.update_available; });
                    if (!updates.length) { toast('No updates available.', 'success'); return; }
                    if (!confirm('Update ' + updates.length + ' plugin(s)?')) return;
                    pmSpinner.style.display = 'inline-block';
                    var i = 0;
                    function updateNext() {
                        if (i >= updates.length) { pmSpinner.style.display = 'none'; toast('All updates complete.', 'success'); loadPlugins(); return; }
                        ajaxPost('eshopeo_update_plugin', { plugin_file: updates[i].file }, function () { i++; updateNext(); });
                    }
                    updateNext();
                });

                document.querySelectorAll('.hwpa-tab[data-tab="plugins"]').forEach(function (t) {
                    t.addEventListener('click', loadPlugins);
                });
            })();

            /* ═══════════════════════════════════════════════════════════
             *  SNIPPETS
             * ═══════════════════════════════════════════════════════════ */
            (function () {
                var snList    = document.getElementById('hwpa-sn-list');
                var editModal = null;

                function loadSnippets() {
                    ajaxPost('eshopeo_snippets_list', {}, function (res) {
                        if (!res.success) { snList.innerHTML = '<p style="color:#ef4444;">Failed.</p>'; return; }
                        var snippets = res.data.snippets || [];
                        if (!snippets.length) { snList.innerHTML = '<p style="color:#9ca3af;font-size:13px;">No snippets yet. Click "+ Add Snippet" to create one.</p>'; return; }
                        var html = '<div style="display:flex;flex-direction:column;gap:8px;">';
                        snippets.forEach(function (s) {
                            html += '<div style="display:flex;align-items:center;gap:12px;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;flex-wrap:wrap;">';
                            html += '<label class="hwpa-toggle"><input type="checkbox"' + (parseInt(s.enabled) ? ' checked' : '') + ' class="hwpa-sn-toggle" data-id="' + escAttr(s.id) + '"><span class="hwpa-toggle-slider"></span></label>';
                            html += '<div style="flex:1;min-width:150px;"><div style="font-size:13px;font-weight:600;">' + escHtml(s.name) + '</div>';
                            html += '<div style="font-size:11px;color:#6b7280;">Hook: <code>' + escHtml(s.hook) + '</code></div></div>';
                            html += '<button class="hwpa-btn hwpa-btn-ghost hwpa-btn-sm hwpa-sn-edit-btn" data-id="' + escAttr(s.id) + '">Edit</button>';
                            html += '<button class="hwpa-btn hwpa-btn-danger hwpa-btn-sm hwpa-sn-del-btn" data-id="' + escAttr(s.id) + '">Delete</button>';
                            html += '</div>';
                        });
                        html += '</div>';
                        snList.innerHTML = html;
                        attachSnippetHandlers(snippets);
                    });
                }

                function attachSnippetHandlers(snippets) {
                    snList.querySelectorAll('.hwpa-sn-toggle').forEach(function (cb) {
                        cb.addEventListener('change', function () {
                            ajaxPost('eshopeo_snippets_toggle', { id: cb.dataset.id, enabled: cb.checked ? '1' : '0' }, function (res) {
                                if (!res.success) { toast('Toggle failed.', 'error'); cb.checked = !cb.checked; }
                            });
                        });
                    });
                    snList.querySelectorAll('.hwpa-sn-del-btn').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            if (!confirm('Delete this snippet?')) return;
                            ajaxPost('eshopeo_snippets_delete', { id: btn.dataset.id }, function (res) {
                                if (res.success) { toast('Snippet deleted.', 'success'); loadSnippets(); }
                                else { toast('Delete failed.', 'error'); }
                            });
                        });
                    });
                    snList.querySelectorAll('.hwpa-sn-edit-btn').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var s = snippets.find(function (x) { return String(x.id) === String(btn.dataset.id); });
                            if (s) openSnippetModal(s);
                        });
                    });
                }

                function openSnippetModal(snippet) {
                    var overlay = document.createElement('div');
                    overlay.className = 'hwpa-modal-overlay open';
                    var isNew = !snippet || !snippet.id;
                    overlay.innerHTML = '<div class="hwpa-modal" style="max-width:640px;">'
                        + '<div class="hwpa-modal-head"><h3>' + (isNew ? 'Add Snippet' : 'Edit Snippet') + '</h3><button class="hwpa-modal-close" id="hwpa-sn-close">&times;</button></div>'
                        + '<div class="hwpa-modal-body" style="max-height:60vh;">'
                        + '<div style="display:flex;flex-direction:column;gap:12px;">'
                        + '<div><label style="font-size:12px;font-weight:500;">Name *</label><input type="text" id="hwpa-sn-name" value="' + escAttr(snippet ? snippet.name : '') + '" style="width:100%;border:1px solid #e5e7eb;border-radius:6px;padding:7px 10px;font-size:13px;margin-top:4px;"></div>'
                        + '<div><label style="font-size:12px;font-weight:500;">Hook</label><input type="text" id="hwpa-sn-hook" value="' + escAttr(snippet ? snippet.hook : 'init') + '" style="width:100%;border:1px solid #e5e7eb;border-radius:6px;padding:7px 10px;font-size:13px;margin-top:4px;"></div>'
                        + '<div><label style="font-size:12px;font-weight:500;">PHP Code *</label><textarea id="hwpa-sn-code" rows="8" style="width:100%;border:1px solid #e5e7eb;border-radius:6px;padding:7px 10px;font-size:12px;font-family:monospace;margin-top:4px;resize:vertical;">' + escHtml(snippet ? snippet.code : '') + '</textarea></div>'
                        + '</div></div>'
                        + '<div class="hwpa-modal-foot"><button class="hwpa-btn hwpa-btn-secondary" id="hwpa-sn-cancel">Cancel</button><button class="hwpa-btn hwpa-btn-primary" id="hwpa-sn-save">Save Snippet</button></div>'
                        + '</div>';
                    document.body.appendChild(overlay);
                    overlay.querySelector('#hwpa-sn-close').addEventListener('click', function () { overlay.remove(); });
                    overlay.querySelector('#hwpa-sn-cancel').addEventListener('click', function () { overlay.remove(); });
                    overlay.querySelector('#hwpa-sn-save').addEventListener('click', function () {
                        var data = {
                            id:   snippet ? snippet.id : '0',
                            name: overlay.querySelector('#hwpa-sn-name').value,
                            hook: overlay.querySelector('#hwpa-sn-hook').value,
                            code: overlay.querySelector('#hwpa-sn-code').value,
                        };
                        ajaxPost('eshopeo_snippets_save', data, function (res) {
                            if (res.success) { overlay.remove(); toast('Snippet saved.', 'success'); loadSnippets(); }
                            else { toast('Save failed: ' + escHtml(String(res.data || '')), 'error'); }
                        });
                    });
                }

                document.getElementById('hwpa-sn-add').addEventListener('click', function () { openSnippetModal({}); });
                document.querySelectorAll('.hwpa-tab[data-tab="snippets"]').forEach(function (t) { t.addEventListener('click', loadSnippets); });
                loadSnippets();
            })();

            /* ═══════════════════════════════════════════════════════════
             *  ALT TEXT
             * ═══════════════════════════════════════════════════════════ */
            (function () {
                var atResults = document.getElementById('hwpa-at-results');
                var atCount   = document.getElementById('hwpa-at-count');
                var atSpinner = document.getElementById('hwpa-at-spinner');
                var atItems   = [];

                function loadAlt() {
                    atSpinner.style.display = 'inline-block';
                    ajaxPost('eshopeo_alt_text_scan', {}, function (res) {
                        atSpinner.style.display = 'none';
                        if (!res.success) { atResults.innerHTML = '<p style="color:#ef4444;">Scan failed.</p>'; return; }
                        atItems = res.data.attachments || [];
                        atCount.textContent = res.data.count + ' image(s) missing alt text.';
                        if (!atItems.length) { atResults.innerHTML = '<p style="color:#10b981;font-weight:600;">All images have alt text!</p>'; return; }
                        renderAlt();
                    });
                }

                function renderAlt() {
                    var html = '<div style="display:flex;flex-direction:column;gap:8px;">';
                    atItems.forEach(function (img) {
                        html += '<div style="display:flex;align-items:center;gap:12px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;">';
                        html += '<img src="' + escAttr(img.thumbnail_url) + '" style="width:48px;height:48px;object-fit:cover;border-radius:4px;" loading="lazy">';
                        html += '<div style="flex:1;min-width:0;"><div style="font-size:13px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escHtml(img.title || img.url) + '</div><div style="font-size:11px;color:#9ca3af;">Missing alt text</div></div>';
                        html += '<button class="hwpa-btn hwpa-btn-primary hwpa-btn-sm hwpa-at-gen-one" data-id="' + escAttr(String(img.id)) + '">Generate</button>';
                        html += '</div>';
                    });
                    html += '</div>';
                    atResults.innerHTML = html;
                    atResults.querySelectorAll('.hwpa-at-gen-one').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            btn.disabled = true; btn.textContent = '…';
                            ajaxPost('eshopeo_alt_text_generate_one', { attachment_id: btn.dataset.id }, function (res) {
                                if (res.success) { toast('Alt text set: "' + escHtml(res.data.alt_text) + '"', 'success'); btn.closest('div[style]').remove(); }
                                else { toast('Error: ' + escHtml(String(res.data || '')), 'error'); btn.disabled = false; btn.textContent = 'Generate'; }
                            });
                        });
                    });
                }

                document.getElementById('hwpa-at-scan').addEventListener('click', loadAlt);
                document.getElementById('hwpa-at-gen-all').addEventListener('click', function () {
                    if (!atItems.length) { toast('Scan first.', 'error'); return; }
                    atSpinner.style.display = 'inline-block';
                    var i = 0;
                    function genNext() {
                        if (i >= atItems.length) { atSpinner.style.display = 'none'; toast('All alt texts generated.', 'success'); loadAlt(); return; }
                        ajaxPost('eshopeo_alt_text_generate_one', { attachment_id: atItems[i].id }, function () { i++; genNext(); });
                    }
                    genNext();
                });
                document.querySelectorAll('.hwpa-tab[data-tab="alt-text"]').forEach(function (t) { t.addEventListener('click', loadAlt); });
            })();

            /* ═══════════════════════════════════════════════════════════
             *  SEO META
             * ═══════════════════════════════════════════════════════════ */
            (function () {
                var smResults = document.getElementById('hwpa-sm-results');
                var smCount   = document.getElementById('hwpa-sm-count');
                var smSpinner = document.getElementById('hwpa-sm-spinner');
                var smItems   = [];

                function loadSeo() {
                    smSpinner.style.display = 'inline-block';
                    ajaxPost('eshopeo_seo_scan', {}, function (res) {
                        smSpinner.style.display = 'none';
                        if (!res.success) { smResults.innerHTML = '<p style="color:#ef4444;">Scan failed.</p>'; return; }
                        smItems = res.data.posts || [];
                        smCount.textContent = res.data.count + ' post(s) missing SEO meta.';
                        if (!smItems.length) { smResults.innerHTML = '<p style="color:#10b981;font-weight:600;">All posts have SEO meta!</p>'; return; }
                        renderSeo();
                    });
                }

                function renderSeo() {
                    var html = '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:13px;">';
                    html += '<thead><tr><th style="text-align:left;padding:8px;border-bottom:2px solid #e5e7eb;">Post</th><th style="padding:8px;border-bottom:2px solid #e5e7eb;">Type</th><th style="padding:8px;border-bottom:2px solid #e5e7eb;">Action</th></tr></thead><tbody>';
                    smItems.forEach(function (p) {
                        html += '<tr id="hwpa-sm-row-' + p.ID + '">';
                        html += '<td style="padding:8px;border-bottom:1px solid #f3f4f6;">' + escHtml(p.post_title) + '</td>';
                        html += '<td style="padding:8px;border-bottom:1px solid #f3f4f6;text-align:center;"><span style="background:#ede9fe;color:#6366f1;padding:2px 8px;border-radius:10px;font-size:11px;">' + escHtml(p.post_type) + '</span></td>';
                        html += '<td style="padding:8px;border-bottom:1px solid #f3f4f6;text-align:center;"><button class="hwpa-btn hwpa-btn-primary hwpa-btn-sm hwpa-sm-gen-one" data-id="' + escAttr(String(p.ID)) + '">Generate</button></td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table></div>';
                    smResults.innerHTML = html;
                    smResults.querySelectorAll('.hwpa-sm-gen-one').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            btn.disabled = true; btn.textContent = '…';
                            ajaxPost('eshopeo_seo_generate_one', { post_id: btn.dataset.id }, function (res) {
                                if (res.success) { toast('SEO meta updated.', 'success'); document.getElementById('hwpa-sm-row-' + btn.dataset.id).remove(); }
                                else { toast('Error: ' + escHtml(String(res.data || '')), 'error'); btn.disabled = false; btn.textContent = 'Generate'; }
                            });
                        });
                    });
                }

                document.getElementById('hwpa-sm-scan').addEventListener('click', loadSeo);
                document.getElementById('hwpa-sm-gen-all').addEventListener('click', function () {
                    if (!smItems.length) { toast('Scan first.', 'error'); return; }
                    smSpinner.style.display = 'inline-block';
                    var i = 0;
                    function genNext() {
                        if (i >= smItems.length) { smSpinner.style.display = 'none'; toast('All SEO meta generated.', 'success'); loadSeo(); return; }
                        ajaxPost('eshopeo_seo_generate_one', { post_id: smItems[i].ID }, function () { i++; genNext(); });
                    }
                    genNext();
                });
                document.querySelectorAll('.hwpa-tab[data-tab="seo-meta"]').forEach(function (t) { t.addEventListener('click', loadSeo); });
            })();

            /* ═══════════════════════════════════════════════════════════
             *  REVIEWS
             * ═══════════════════════════════════════════════════════════ */
            (function () {
                var rvProduct   = document.getElementById('hwpa-rv-product');
                var rvSynth     = document.getElementById('hwpa-rv-synthesise');
                var rvResult    = document.getElementById('hwpa-rv-result');
                var rvSpinner   = document.getElementById('hwpa-rv-spinner');

                function loadProducts() {
                    ajaxPost('eshopeo_reviews_get_products', {}, function (res) {
                        if (!res.success) return;
                        var products = res.data.products || [];
                        rvProduct.innerHTML = '<option value="">— Select a product —</option>';
                        products.forEach(function (p) {
                            var opt = document.createElement('option');
                            opt.value = p.id;
                            opt.textContent = p.title + ' (' + p.review_count + ' reviews)' + (p.has_synthesis ? ' ✓' : '');
                            rvProduct.appendChild(opt);
                        });
                    });
                }

                rvProduct.addEventListener('change', function () { rvSynth.disabled = !rvProduct.value; });

                rvSynth.addEventListener('click', function () {
                    var pid = rvProduct.value;
                    if (!pid) return;
                    rvSynth.disabled = true;
                    rvSpinner.style.display = 'inline-block';
                    ajaxPost('eshopeo_reviews_synthesise', { product_id: pid }, function (res) {
                        rvSpinner.style.display = 'none';
                        rvSynth.disabled = false;
                        if (!res.success) { rvResult.innerHTML = '<p style="color:#ef4444;">' + escHtml(String(res.data || 'Failed')) + '</p>'; return; }
                        var d = res.data;
                        var sentCol = { positive: '#10b981', negative: '#ef4444', mixed: '#f59e0b' }[d.sentiment] || '#6b7280';
                        var html = '<div style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin-top:12px;">';
                        html += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">';
                        html += '<span style="font-size:22px;font-weight:700;">' + escHtml(String(d.avg_rating)) + '&#9733;</span>';
                        html += '<span style="background:' + sentCol + '20;color:' + sentCol + ';padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;text-transform:capitalize;">' + escHtml(d.sentiment) + '</span></div>';
                        html += '<p style="font-size:13px;line-height:1.6;margin:0 0 12px;">' + escHtml(d.summary) + '</p>';
                        if (d.pros && d.pros.length) {
                            html += '<div style="margin-bottom:8px;"><strong style="font-size:12px;color:#10b981;">Pros</strong><div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px;">';
                            d.pros.forEach(function (p) { html += '<span style="background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:20px;font-size:12px;">' + escHtml(p) + '</span>'; });
                            html += '</div></div>';
                        }
                        if (d.cons && d.cons.length) {
                            html += '<div><strong style="font-size:12px;color:#ef4444;">Cons</strong><div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px;">';
                            d.cons.forEach(function (c) { html += '<span style="background:#fee2e2;color:#7f1d1d;padding:3px 10px;border-radius:20px;font-size:12px;">' + escHtml(c) + '</span>'; });
                            html += '</div></div>';
                        }
                        html += '</div>';
                        rvResult.innerHTML = html;
                    });
                });

                document.querySelectorAll('.hwpa-tab[data-tab="reviews"]').forEach(function (t) { t.addEventListener('click', loadProducts); });
                loadProducts();
            })();

            /* ═══════════════════════════════════════════════════════════
             *  INTERNAL LINKS
             * ═══════════════════════════════════════════════════════════ */
            (function () {
                var ilResults = document.getElementById('hwpa-il-results');
                var ilSpinner = document.getElementById('hwpa-il-spinner');

                document.getElementById('hwpa-il-suggest').addEventListener('click', function () {
                    var pid = document.getElementById('hwpa-il-post-id').value;
                    if (!pid) { toast('Enter a Post ID.', 'error'); return; }
                    ilSpinner.style.display = 'inline-block';
                    ajaxPost('eshopeo_internal_links_suggest', { post_id: pid }, function (res) {
                        ilSpinner.style.display = 'none';
                        if (!res.success) { ilResults.innerHTML = '<p style="color:#ef4444;">' + escHtml(String(res.data || 'Failed')) + '</p>'; return; }
                        var sugs = res.data.suggestions || [];
                        if (!sugs.length) { ilResults.innerHTML = '<p style="color:#9ca3af;">No link suggestions found for this post.</p>'; return; }
                        var html = '<div style="display:flex;flex-direction:column;gap:8px;">';
                        sugs.forEach(function (s, idx) {
                            html += '<div style="display:flex;align-items:center;gap:12px;padding:10px 14px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;">';
                            html += '<div style="flex:1;"><div style="font-size:13px;"><strong>&ldquo;' + escHtml(s.anchor_text) + '&rdquo;</strong> &rarr; <a href="' + escAttr(s.target_url) + '" target="_blank" style="color:#6366f1;">' + escHtml(s.target_title) + '</a></div></div>';
                            html += '<button class="hwpa-btn hwpa-btn-primary hwpa-btn-sm hwpa-il-apply" data-idx="' + idx + '" data-anchor="' + escAttr(s.anchor_text) + '" data-url="' + escAttr(s.target_url) + '" data-pid="' + escAttr(String(pid)) + '">Apply</button>';
                            html += '<button class="hwpa-btn hwpa-btn-ghost hwpa-btn-sm hwpa-il-undo" data-snap="" style="display:none;">Undo</button>';
                            html += '</div>';
                        });
                        html += '</div>';
                        ilResults.innerHTML = html;
                        ilResults.querySelectorAll('.hwpa-il-apply').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                btn.disabled = true;
                                ajaxPost('eshopeo_internal_links_apply', { post_id: btn.dataset.pid, anchor_text: btn.dataset.anchor, target_url: btn.dataset.url }, function (res) {
                                    if (res.success) {
                                        toast('Link applied.', 'success');
                                        btn.style.display = 'none';
                                        var undoBtn = btn.nextElementSibling;
                                        undoBtn.dataset.snap = res.data.snapshot_id;
                                        undoBtn.style.display = 'inline-flex';
                                    } else {
                                        toast('Error: ' + escHtml(String(res.data || '')), 'error');
                                        btn.disabled = false;
                                    }
                                });
                            });
                        });
                        ilResults.querySelectorAll('.hwpa-il-undo').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                if (!btn.dataset.snap) return;
                                ajaxPost('eshopeo_action_rollback', { snapshot_id: btn.dataset.snap }, function (res) {
                                    if (res.success) { toast('Link undone.', 'success'); btn.style.display = 'none'; }
                                    else { toast('Undo failed.', 'error'); }
                                });
                            });
                        });
                    });
                });
            })();

            /* ═══════════════════════════════════════════════════════════
             *  BLOG GENERATOR
             * ═══════════════════════════════════════════════════════════ */
            (function () {
                var bgResult  = document.getElementById('hwpa-bg-result');
                var bgSpinner = document.getElementById('hwpa-bg-spinner');
                var bgData    = null;

                document.getElementById('hwpa-bg-generate').addEventListener('click', function () {
                    var topic = document.getElementById('hwpa-bg-topic').value.trim();
                    if (!topic) { toast('Enter a topic.', 'error'); return; }
                    var tone = document.querySelector('input[name="hwpa-bg-tone"]:checked');
                    bgSpinner.style.display = 'inline-block';
                    bgResult.innerHTML = '';
                    bgData = null;

                    ajaxPost('eshopeo_blog_generate', {
                        topic:      topic,
                        keywords:   document.getElementById('hwpa-bg-keywords').value,
                        tone:       tone ? tone.value : 'professional',
                        word_count: document.getElementById('hwpa-bg-wordcount').value,
                        save_draft: '0',
                    }, function (res) {
                        bgSpinner.style.display = 'none';
                        if (!res.success) { bgResult.innerHTML = '<p style="color:#ef4444;">' + escHtml(String(res.data || 'Failed')) + '</p>'; return; }
                        bgData = res.data;
                        var html = '<div style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;">';
                        html += '<h3 style="margin:0 0 8px;font-size:16px;color:#1e1b4b;">' + escHtml(bgData.title) + '</h3>';
                        html += '<p style="font-size:12px;color:#6b7280;margin:0 0 12px;">' + escHtml(bgData.excerpt) + '</p>';
                        html += '<div style="font-size:13px;line-height:1.6;max-height:300px;overflow-y:auto;border:1px solid #f3f4f6;border-radius:6px;padding:12px;">' + bgData.content_html + '</div>';
                        html += '<div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;">';
                        html += '<button id="hwpa-bg-save" class="hwpa-btn hwpa-btn-primary hwpa-btn-sm">Save as Draft</button>';
                        if (bgData.suggested_tags && bgData.suggested_tags.length) {
                            html += '<div style="font-size:12px;color:#6b7280;display:flex;gap:4px;align-items:center;flex-wrap:wrap;">Tags:';
                            bgData.suggested_tags.forEach(function (t) { html += '<span style="background:#f3f4f6;padding:2px 8px;border-radius:10px;">' + escHtml(t) + '</span>'; });
                            html += '</div>';
                        }
                        html += '</div></div>';
                        bgResult.innerHTML = html;
                        document.getElementById('hwpa-bg-save').addEventListener('click', function () {
                            var saveBtn = this;
                            saveBtn.disabled = true;
                            ajaxPost('eshopeo_blog_generate', {
                                topic:      topic,
                                keywords:   document.getElementById('hwpa-bg-keywords').value,
                                tone:       tone ? tone.value : 'professional',
                                word_count: document.getElementById('hwpa-bg-wordcount').value,
                                save_draft: '1',
                            }, function (res2) {
                                if (res2.success && res2.data.edit_url) {
                                    toast('Draft saved! <a href="' + escAttr(res2.data.edit_url) + '" target="_blank" style="color:#6366f1;">Edit it</a>', 'success');
                                } else {
                                    toast('Save failed.', 'error');
                                    saveBtn.disabled = false;
                                }
                            });
                        });
                    });
                });
            })();

            /* ═══════════════════════════════════════════════════════════
             *  PRODUCT DESCRIPTIONS
             * ═══════════════════════════════════════════════════════════ */
            (function () {
                var pdResults = document.getElementById('hwpa-pd-results');
                var pdCount   = document.getElementById('hwpa-pd-count');
                var pdSpinner = document.getElementById('hwpa-pd-spinner');

                function loadDescriptions() {
                    pdSpinner.style.display = 'inline-block';
                    ajaxPost('eshopeo_desc_scan', {}, function (res) {
                        pdSpinner.style.display = 'none';
                        if (!res.success) { pdResults.innerHTML = '<p style="color:#ef4444;">Scan failed.</p>'; return; }
                        var products = res.data.products || [];
                        pdCount.textContent = res.data.count + ' product(s) with thin descriptions (<80 words).';
                        if (!products.length) { pdResults.innerHTML = '<p style="color:#10b981;font-weight:600;">All products have full descriptions!</p>'; return; }
                        var html = '<div style="display:flex;flex-direction:column;gap:8px;">';
                        products.forEach(function (p) {
                            html += '<div id="hwpa-pd-row-' + p.id + '" style="display:flex;align-items:center;gap:12px;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;flex-wrap:wrap;">';
                            html += '<div style="flex:1;min-width:150px;"><div style="font-size:13px;font-weight:600;">' + escHtml(p.title) + '</div>';
                            html += '<div style="font-size:11px;color:#9ca3af;">' + p.word_count + ' words</div></div>';
                            html += '<button class="hwpa-btn hwpa-btn-primary hwpa-btn-sm hwpa-pd-rewrite" data-id="' + escAttr(String(p.id)) + '">Rewrite</button>';
                            html += '<button class="hwpa-btn hwpa-btn-ghost hwpa-btn-sm hwpa-pd-undo" data-snap="" style="display:none;">Undo</button>';
                            html += '</div>';
                        });
                        html += '</div>';
                        pdResults.innerHTML = html;
                        pdResults.querySelectorAll('.hwpa-pd-rewrite').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                btn.disabled = true; btn.textContent = '…';
                                ajaxPost('eshopeo_desc_rewrite_one', { product_id: btn.dataset.id }, function (res) {
                                    if (res.success) {
                                        toast('Description rewritten.', 'success');
                                        btn.style.display = 'none';
                                        var undo = btn.nextElementSibling;
                                        undo.dataset.snap = res.data.snapshot_id;
                                        undo.style.display = 'inline-flex';
                                    } else {
                                        toast('Error: ' + escHtml(String(res.data || '')), 'error');
                                        btn.disabled = false; btn.textContent = 'Rewrite';
                                    }
                                });
                            });
                        });
                        pdResults.querySelectorAll('.hwpa-pd-undo').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                ajaxPost('eshopeo_action_rollback', { snapshot_id: btn.dataset.snap }, function (res) {
                                    if (res.success) { toast('Reverted.', 'success'); loadDescriptions(); }
                                    else { toast('Undo failed.', 'error'); }
                                });
                            });
                        });
                    });
                }

                document.getElementById('hwpa-pd-scan').addEventListener('click', loadDescriptions);
                document.querySelectorAll('.hwpa-tab[data-tab="descriptions"]').forEach(function (t) { t.addEventListener('click', loadDescriptions); });
            })();

            /* ═══════════════════════════════════════════════════════════
             *  REPURPOSE
             * ═══════════════════════════════════════════════════════════ */
            (function () {
                var rpResult  = document.getElementById('hwpa-rp-result');
                var rpSpinner = document.getElementById('hwpa-rp-spinner');

                document.getElementById('hwpa-rp-generate').addEventListener('click', function () {
                    var pid = document.getElementById('hwpa-rp-post-id').value;
                    if (!pid) { toast('Enter a Post ID.', 'error'); return; }
                    var formats = [];
                    document.querySelectorAll('.hwpa-rp-format:checked').forEach(function (cb) { formats.push(cb.value); });
                    if (!formats.length) { toast('Select at least one format.', 'error'); return; }
                    rpSpinner.style.display = 'inline-block';
                    ajaxPost('eshopeo_repurpose', { post_id: pid, formats: formats.join(',') }, function (res) {
                        rpSpinner.style.display = 'none';
                        if (!res.success) { rpResult.innerHTML = '<p style="color:#ef4444;">' + escHtml(String(res.data || 'Failed')) + '</p>'; return; }
                        var d = res.data;
                        var html = '<div style="display:flex;flex-direction:column;gap:12px;">';
                        var items = [
                            { key: 'social_caption', label: 'Social Caption' },
                            { key: 'email_body',      label: 'Email Body' },
                            { key: 'short_summary',   label: 'Short Summary' },
                        ];
                        items.forEach(function (item) {
                            if (!d[item.key]) return;
                            html += '<div style="border:1px solid #e5e7eb;border-radius:8px;padding:14px;">';
                            html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">';
                            html += '<strong style="font-size:13px;">' + escHtml(item.label) + '</strong>';
                            html += '<button class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm hwpa-rp-copy" data-text="' + escAttr(d[item.key]) + '">Copy</button></div>';
                            html += '<p style="font-size:13px;line-height:1.6;margin:0;white-space:pre-wrap;">' + escHtml(d[item.key]) + '</p></div>';
                        });
                        if (d.faq && d.faq.length) {
                            html += '<div style="border:1px solid #e5e7eb;border-radius:8px;padding:14px;">';
                            html += '<strong style="font-size:13px;">FAQ</strong>';
                            d.faq.forEach(function (qa) {
                                html += '<div style="margin-top:10px;"><strong style="font-size:12px;">' + escHtml(qa.question || '') + '</strong><p style="font-size:12px;color:#374151;margin:4px 0 0;">' + escHtml(qa.answer || '') + '</p></div>';
                            });
                            html += '</div>';
                        }
                        html += '</div>';
                        rpResult.innerHTML = html;
                        rpResult.querySelectorAll('.hwpa-rp-copy').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                navigator.clipboard.writeText(btn.dataset.text).then(function () { toast('Copied!', 'success'); });
                            });
                        });
                    });
                });
            })();

            /* ═══════════════════════════════════════════════════════════
             *  IMAGE OPTIMISER
             * ═══════════════════════════════════════════════════════════ */
            (function () {
                var ioResults = document.getElementById('hwpa-io-results');
                var ioCount   = document.getElementById('hwpa-io-count');
                var ioSpinner = document.getElementById('hwpa-io-spinner');
                var ioItems   = [];

                function loadImages() {
                    ioSpinner.style.display = 'inline-block';
                    ajaxPost('eshopeo_image_scan', {}, function (res) {
                        ioSpinner.style.display = 'none';
                        if (!res.success) { ioResults.innerHTML = '<p style="color:#ef4444;">Scan failed.</p>'; return; }
                        ioItems = res.data.images || [];
                        ioCount.textContent = res.data.count + ' image(s) without WebP version.';
                        if (!ioItems.length) { ioResults.innerHTML = '<p style="color:#10b981;font-weight:600;">All images have WebP versions!</p>'; return; }
                        renderImages();
                    });
                }

                function renderImages() {
                    var html = '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:13px;">';
                    html += '<thead><tr><th style="text-align:left;padding:8px;border-bottom:2px solid #e5e7eb;">Image</th><th style="padding:8px;border-bottom:2px solid #e5e7eb;">Original</th><th style="padding:8px;border-bottom:2px solid #e5e7eb;">Action</th></tr></thead><tbody>';
                    ioItems.forEach(function (img) {
                        html += '<tr id="hwpa-io-row-' + img.id + '">';
                        html += '<td style="padding:8px;border-bottom:1px solid #f3f4f6;">' + escHtml(img.title) + '</td>';
                        html += '<td style="padding:8px;border-bottom:1px solid #f3f4f6;text-align:center;">' + escHtml(String(img.size_kb)) + ' KB</td>';
                        html += '<td style="padding:8px;border-bottom:1px solid #f3f4f6;text-align:center;"><button class="hwpa-btn hwpa-btn-primary hwpa-btn-sm hwpa-io-opt-one" data-id="' + escAttr(String(img.id)) + '">Optimise</button><span class="hwpa-io-result-' + img.id + '" style="font-size:11px;margin-left:6px;display:none;"></span></td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table></div>';
                    ioResults.innerHTML = html;
                    ioResults.querySelectorAll('.hwpa-io-opt-one').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            btn.disabled = true; btn.textContent = '…';
                            ajaxPost('eshopeo_image_optimise_batch', { attachment_ids: [btn.dataset.id] }, function (res) {
                                if (res.success && res.data.results && res.data.results[0]) {
                                    var r = res.data.results[0];
                                    if (r.error) { toast('Error: ' + escHtml(r.error), 'error'); btn.disabled = false; btn.textContent = 'Optimise'; return; }
                                    var sp = ioResults.querySelector('.hwpa-io-result-' + r.id);
                                    if (sp) { sp.style.display = 'inline'; sp.style.color = '#10b981'; sp.textContent = r.webp_kb + 'KB (-' + r.saved_pct + '%)'; }
                                    btn.style.display = 'none';
                                } else {
                                    toast('Optimise failed.', 'error'); btn.disabled = false; btn.textContent = 'Optimise';
                                }
                            });
                        });
                    });
                }

                document.getElementById('hwpa-io-scan').addEventListener('click', loadImages);
                document.getElementById('hwpa-io-opt-all').addEventListener('click', function () {
                    if (!ioItems.length) { toast('Scan first.', 'error'); return; }
                    ioSpinner.style.display = 'inline-block';
                    var batches = [];
                    for (var i = 0; i < ioItems.length; i += 20) {
                        batches.push(ioItems.slice(i, i + 20).map(function (x) { return x.id; }));
                    }
                    var bi = 0;
                    function nextBatch() {
                        if (bi >= batches.length) { ioSpinner.style.display = 'none'; toast('All images optimised.', 'success'); loadImages(); return; }
                        ajaxPost('eshopeo_image_optimise_batch', { attachment_ids: batches[bi].join(',') }, function () { bi++; nextBatch(); });
                    }
                    nextBatch();
                });
                document.querySelectorAll('.hwpa-tab[data-tab="images"]').forEach(function (t) { t.addEventListener('click', loadImages); });
            })();

            /* ═══════════════════════════════════════════════════════════
             *  WEEKLY DIGEST
             * ═══════════════════════════════════════════════════════════ */
            (function () {
                var digestBtn     = document.getElementById('hwpa-digest-test');
                var digestSpinner = document.getElementById('hwpa-digest-spinner');
                var digestResult  = document.getElementById('hwpa-digest-result');

                digestBtn.addEventListener('click', function () {
                    var email = document.getElementById('hwpa-digest-email').value.trim();
                    if (!email) { toast('Enter an email address.', 'error'); return; }
                    digestBtn.disabled = true;
                    digestSpinner.style.display = 'inline-block';
                    ajaxPost('eshopeo_digest_send_test', { email: email }, function (res) {
                        digestBtn.disabled = false;
                        digestSpinner.style.display = 'none';
                        digestResult.style.display = 'block';
                        if (res.success) {
                            digestResult.style.color = '#10b981';
                            digestResult.textContent = 'Digest sent to ' + res.data.email + '.';
                            toast('Test digest sent.', 'success');
                        } else {
                            digestResult.style.color = '#ef4444';
                            digestResult.textContent = 'Failed: ' + String(res.data || 'Check API configuration.');
                        }
                    });
                });
            })();

            /* ── Info modal ──────────────────────────────────────────────── */
            (function(){
                var infoOverlay = document.getElementById('hwpa-info-overlay');
                var infoTitle   = document.getElementById('hwpa-info-title');
                var infoBody    = document.getElementById('hwpa-info-body');
                document.getElementById('hwpa-info-close').addEventListener('click', function(){ infoOverlay.classList.remove('open'); });
                infoOverlay.addEventListener('click', function(e){ if(e.target===infoOverlay) infoOverlay.classList.remove('open'); });
                document.addEventListener('click', function(e){
                    var btn = e.target.closest('.hwpa-info-btn');
                    if(!btn) return;
                    infoTitle.textContent = btn.dataset.helpTitle || '';
                    infoBody.innerHTML    = btn.dataset.helpBody  || '';
                    infoOverlay.classList.add('open');
                });
            })();

            /* ── Helpers ───────────────────────────────────────────────── */
            function escHtml(str) {
                var d = document.createElement('div');
                d.appendChild(document.createTextNode(str));
                return d.innerHTML;
            }
            function escAttr(str) {
                return String(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }
        })();
        </script>
        <?php
    }

    /* ── Log Table Renderer ────────────────────────────────────────────── */

    private static function render_log_table(): void {
        $entries = Eshopeo_Action_Log::get( 100 );
        if ( empty( $entries ) ) {
            echo '<p style="text-align:center;color:#9ca3af;padding:40px 20px;">No actions logged yet.</p>';
            return;
        }
        ?>
        <div style="overflow-x:auto;">
        <table class="hwpa-log-table">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Mode</th>
                    <th>Affected</th>
                    <th>Snapshot</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $entries as $entry ) : ?>
                <tr>
                    <td style="white-space:nowrap;color:#6b7280;font-size:11px;"><?php echo esc_html( $entry['timestamp'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $entry['user'] ?? '' ); ?></td>
                    <td><code style="font-size:11px;"><?php echo esc_html( $entry['action'] ?? '' ); ?></code></td>
                    <td>
                        <?php if ( ! empty( $entry['dry_run'] ) ) : ?>
                            <span class="hwpa-log-dry">Dry run</span>
                        <?php else : ?>
                            <span class="hwpa-log-live">Live</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo isset( $entry['affected'] ) ? esc_html( (string) $entry['affected'] ) : '—'; ?></td>
                    <td>
                        <?php if ( ! empty( $entry['snapshot_id'] ) ) : ?>
                            <span style="font-size:11px;color:#6b7280;">#<?php echo esc_html( (string) $entry['snapshot_id'] ); ?></span>
                        <?php else : ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( ! empty( $entry['snapshot_id'] ) ) : ?>
                            <button class="hwpa-btn hwpa-btn-ghost hwpa-btn-sm hwpa-log-undo-btn"
                                    data-snapshot="<?php echo esc_attr( (string) $entry['snapshot_id'] ); ?>"
                                    data-label="<?php echo esc_attr( $entry['action'] ?? '' ); ?>">
                                Undo
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
    }
}
