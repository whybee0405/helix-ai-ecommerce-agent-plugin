<?php
defined( 'ABSPATH' ) || exit;

class Helix_WP_Agent_Page {

    /* ── Init ──────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'wp_ajax_helix_agent_log_clear', [ __CLASS__, 'ajax_log_clear' ] );

        // Heartbeat hook — applies when helix_disable_heartbeat_frontend is on.
        add_action( 'init', [ __CLASS__, 'maybe_disable_heartbeat' ] );
    }

    public static function maybe_disable_heartbeat(): void {
        if (
            ! is_admin() &&
            get_option( 'helix_disable_heartbeat_frontend', 0 )
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
            'Helix WP Agent',
            'Helix WP Agent',
            'manage_options',
            'helix-wp-agent',
            [ __CLASS__, 'render' ]
        );
    }

    /* ── AJAX: clear log ───────────────────────────────────────────────── */

    public static function ajax_log_clear(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        Helix_Action_Log::clear();
        wp_send_json_success( [ 'cleared' => true ] );
    }

    /* ══════════════════════════════════════════════════════════════════════
     *  RENDER
     * ══════════════════════════════════════════════════════════════════════ */

    public static function render(): void {
        $nonce   = wp_create_nonce( 'helix_wp_agent' );
        $api_url = esc_js( get_option( 'helix_api_url', '' ) );
        $pub_key = esc_js( get_option( 'helix_public_key', '' ) );
        $adm_sec = esc_js( get_option( 'helix_admin_secret', '' ) );
        ?>
        <style>
        /* ── Helix WP Agent page ── */
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
        .hwpa-typing{display:flex;gap:4px;padding:6px 10px;}
        .hwpa-typing span{width:7px;height:7px;border-radius:50%;background:#9ca3af;animation:hwpa-bounce .8s infinite;}
        .hwpa-typing span:nth-child(2){animation-delay:.15s;}
        .hwpa-typing span:nth-child(3){animation-delay:.3s;}
        @keyframes hwpa-bounce{0%,60%,100%{transform:translateY(0);}30%{transform:translateY(-6px);}}

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
        </style>

        <div class="hwpa-page">

            <!-- Header -->
            <div class="hwpa-header">
                <h1>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="12" cy="12" r="10" fill="#6366f1" opacity=".12"/>
                        <path d="M8 12.5v-5l6 4.5-6 4.5v-4z" fill="#6366f1"/>
                    </svg>
                    Helix WP Agent
                    <span class="hwpa-badge">v<?php echo esc_html( HELIX_CONNECTOR_VERSION ); ?></span>
                </h1>
            </div>

            <!-- Tabs -->
            <div class="hwpa-tabs" role="tablist">
                <button class="hwpa-tab active" data-tab="quick-actions" role="tab">Quick Actions</button>
                <button class="hwpa-tab" data-tab="performance" role="tab">Performance</button>
                <button class="hwpa-tab" data-tab="scheduler" role="tab">Scheduler</button>
                <button class="hwpa-tab" data-tab="ai-assistant" role="tab">AI Assistant</button>
                <button class="hwpa-tab" data-tab="action-log" role="tab">Action Log</button>
            </div>

            <!-- ══════════ QUICK ACTIONS TAB ══════════ -->
            <div id="hwpa-panel-quick-actions" class="hwpa-panel active" role="tabpanel">
                <?php
                $actions    = Helix_Quick_Actions::get_actions();
                $categories = [
                    'database'    => [ 'label' => 'Database', 'icon' => '&#128451;' ],
                    'performance' => [ 'label' => 'Performance', 'icon' => '&#9889;' ],
                    'media'       => [ 'label' => 'Media', 'icon' => '&#128444;' ],
                    'security'    => [ 'label' => 'Security', 'icon' => '&#128274;' ],
                    'content'     => [ 'label' => 'Content', 'icon' => '&#128196;' ],
                ];
                foreach ( $categories as $cat_id => $cat ) :
                    $cat_actions = array_filter( $actions, fn( $a ) => $a['category'] === $cat_id );
                    if ( empty( $cat_actions ) ) continue;
                ?>
                    <div class="hwpa-category-head">
                        <?php echo $cat['icon']; ?> <?php echo esc_html( $cat['label'] ); ?>
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
                        <h2>Site Performance Dashboard</h2>
                        <button id="hwpa-refresh-metrics" class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm" style="margin-left:auto;">
                            Refresh Metrics
                        </button>
                        <div class="hwpa-spinner" id="hwpa-perf-spinner"></div>
                    </div>
                    <div class="hwpa-card-body" id="hwpa-perf-body">
                        <p style="color:#9ca3af;font-size:13px;">Click "Refresh Metrics" to load live data.</p>
                    </div>
                </div>
            </div>

            <!-- ══════════ SCHEDULER TAB ══════════ -->
            <div id="hwpa-panel-scheduler" class="hwpa-panel" role="tabpanel">
                <div class="hwpa-card">
                    <div class="hwpa-card-head">
                        <h2>Scheduled Maintenance</h2>
                        <button id="hwpa-refresh-sched" class="hwpa-btn hwpa-btn-secondary hwpa-btn-sm" style="margin-left:auto;">
                            Refresh
                        </button>
                    </div>
                    <div class="hwpa-card-body" id="hwpa-sched-body">
                        <p style="color:#9ca3af;font-size:13px;">Loading schedule...</p>
                    </div>
                </div>
            </div>

            <!-- ══════════ AI ASSISTANT TAB ══════════ -->
            <div id="hwpa-panel-ai-assistant" class="hwpa-panel" role="tabpanel">
                <div class="hwpa-card">
                    <div class="hwpa-card-head">
                        <h2>AI Assistant</h2>
                    </div>
                    <div class="hwpa-card-body" style="padding:0;">
                        <div class="hwpa-chat-wrap" id="hwpa-chat-wrap">
                            <div class="hwpa-chat-msgs" id="hwpa-chat-msgs">
                                <div class="hwpa-chat-bubble assistant">
                                    Hello! I'm your Helix WP Agent. I can help you optimise your WordPress site, review performance metrics, bulk-update posts, and more. What would you like to do?
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
                        <h2>Action Log</h2>
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

        <!-- ══════════ TOAST CONTAINER ══════════ -->
        <div class="hwpa-toast-container" id="hwpa-toasts"></div>

        <script>
        (function () {
            'use strict';

            var NONCE   = '<?php echo esc_js( $nonce ); ?>';
            var AJAX    = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
            var API_URL = '<?php echo $api_url; ?>';
            var PUB_KEY = '<?php echo $pub_key; ?>';
            var ADM_SEC = '<?php echo $adm_sec; ?>';
            var SITE_URL = '<?php echo esc_js( site_url() ); ?>';

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

                ajaxPost('helix_action_' + action, { dry_run: '1' }, function (res) {
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

                ajaxPost('helix_action_' + currentAction.id, { dry_run: '0' }, function (res) {
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
                ajaxPost('helix_action_rollback', { snapshot_id: snapId }, function (res) {
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
                ajaxPost('helix_performance_metrics', {}, function (res) {
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
                ajaxPost('helix_scheduler_status', {}, function (res) {
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
                        ajaxPost('helix_scheduler_toggle', { hook: cb.dataset.hook, enabled: cb.checked ? '1' : '0' }, function (res) {
                            if (res.success) { renderScheduler(res.data); }
                            else { toast('Toggle failed: ' + escHtml(String(res.data || '')), 'error'); }
                        });
                    });
                });

                // Run Now handlers.
                document.querySelectorAll('.hwpa-run-now-btn').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        btn.disabled = true;
                        ajaxPost('helix_scheduler_run_now', { hook: btn.dataset.hook }, function (res) {
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

            function showTyping() {
                var div = document.createElement('div');
                div.className = 'hwpa-chat-bubble assistant';
                div.id = 'hwpa-typing';
                div.innerHTML = '<div class="hwpa-typing"><span></span><span></span><span></span></div>';
                chatMsgs.appendChild(div);
                chatMsgs.scrollTop = chatMsgs.scrollHeight;
            }
            function hideTyping() {
                var el = document.getElementById('hwpa-typing');
                if (el) el.remove();
            }

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
                        message:      msg,
                        history:      chatHistory.slice(0, -1),
                        site_url:     SITE_URL,
                        admin_secret: ADM_SEC,
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
                    // Show tool calls.
                    if (res.tool_calls && res.tool_calls.length) {
                        res.tool_calls.forEach(function (tc) {
                            appendBubble('tool-result', 'Tool: ' + escHtml(tc.name) + '\n' + JSON.stringify(tc.result, null, 2));
                        });
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
                ajaxPost('helix_agent_log_clear', {}, function (res) {
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
        $entries = Helix_Action_Log::get( 100 );
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
