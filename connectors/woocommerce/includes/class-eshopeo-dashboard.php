<?php
defined( 'ABSPATH' ) || exit;

class Eshopeo_Dashboard {

    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'register_menu' ], 5 );
        add_action( 'wp_ajax_eshopeo_get_conversation', [ self::class, 'ajax_get_conversation' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
    }

    public static function register_menu(): void {
        add_menu_page(
            'eShopeo',
            'eShopeo',
            'manage_woocommerce',
            'eshopeo-dashboard',
            [ self::class, 'render_dashboard' ],
            'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#a78bfa"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>' ),
            56
        );
        add_submenu_page( 'eshopeo-dashboard', 'Dashboard', 'Dashboard', 'manage_woocommerce', 'eshopeo-dashboard', [ self::class, 'render_dashboard' ] );
        add_submenu_page( 'eshopeo-dashboard', 'Conversations', 'Conversations', 'manage_woocommerce', 'eshopeo-conversations', [ self::class, 'render_conversations' ] );
        add_submenu_page( 'eshopeo-dashboard', 'Analytics', 'Analytics', 'manage_woocommerce', 'eshopeo-analytics', [ self::class, 'render_analytics' ] );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'eshopeo-' ) === false ) return;
        wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', [], '4.4.0', true );
    }

    private static function get_client(): ?Eshopeo_API_Client {
        $api_url = get_option( 'eshopeo_api_url', '' );
        $pub_key = get_option( 'eshopeo_public_key', '' );
        if ( ! $api_url || ! $pub_key ) return null;
        return new Eshopeo_API_Client( $api_url, $pub_key );
    }

    private static function safe_get( Eshopeo_API_Client $client, string $method, ...$args ): array {
        $result = $client->$method( ...$args );
        return is_wp_error( $result ) ? [] : ( $result ?: [] );
    }

    // ── Shared CSS (injected once per page load) ───────────────────────────

    private static function render_styles(): void {
        ?>
        <style>
        /* ── eShopeo Dashboard — design system ── */
        .hx-admin *,
        .hx-admin *::before,
        .hx-admin *::after { box-sizing: border-box; }

        .hx-admin {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px 20px 60px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #374151;
            background: #f9fafb;
        }

        /* ── Topbar ── */
        .hx-topbar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }
        .hx-topbar h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
            color: #1e1b4b;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .hx-badge {
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
        .hx-nav {
            display: flex;
            gap: 4px;
            margin-left: auto;
            flex-wrap: wrap;
        }
        .hx-nav a {
            padding: 7px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            color: #6b7280;
            transition: background .13s, color .13s;
            white-space: nowrap;
        }
        .hx-nav a:hover { background: rgba(99,102,241,.08); color: #6366f1; }
        .hx-nav a.active {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: #fff;
        }
        .hx-nav a.active:hover { color: #fff; }
        .hx-nav a.settings-link {
            border: 1px solid #e5e7eb;
        }

        /* ── Metric cards grid ── */
        .hx-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }
        .hx-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
            position: relative;
            overflow: hidden;
        }
        .hx-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: #e5e7eb;
            border-radius: 8px 8px 0 0;
        }
        .hx-card.accent::before   { background: linear-gradient(90deg, #6366f1, #818cf8); }
        .hx-card.green::before    { background: #10b981; }
        .hx-card.amber::before    { background: #f59e0b; }
        .hx-card.whatsapp::before { background: #25d366; }
        .hx-card-label {
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 10px;
        }
        .hx-card-value {
            font-size: 32px;
            font-weight: 700;
            color: #1e1b4b;
            line-height: 1;
            letter-spacing: -.01em;
        }
        .hx-card.accent  .hx-card-value { color: #6366f1; }
        .hx-card.green   .hx-card-value { color: #10b981; }
        .hx-card.amber   .hx-card-value { color: #f59e0b; }
        .hx-card.whatsapp .hx-card-value { color: #25d366; }
        .hx-card-sub {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 6px;
        }

        /* ── Section panels ── */
        .hx-section {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .hx-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            background: #fafafa;
        }
        .hx-section-title {
            font-size: 14px;
            font-weight: 600;
            color: #1e1b4b;
            border-left: 3px solid #6366f1;
            padding-left: 10px;
            margin: 0;
        }
        .hx-section-link {
            font-size: 12px;
            font-weight: 500;
            color: #6366f1;
            text-decoration: none;
        }
        .hx-section-link:hover { color: #4f46e5; text-decoration: underline; }
        .hx-section-body { padding: 20px; }
        .hx-section-meta {
            font-size: 12px;
            font-weight: 400;
            color: #9ca3af;
        }

        /* ── Chart containers ── */
        .hx-chart-wrap { position: relative; height: 240px; }
        .hx-chart-wrap.short { height: 200px; }

        /* ── 2-col grid ── */
        .hx-2col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        /* ── Conversation table ── */
        .hx-table-wrap { overflow-x: auto; }
        .hx-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .hx-table thead th {
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
        .hx-table tbody td {
            padding: 12px 14px;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
            vertical-align: middle;
        }
        .hx-table tbody tr:last-child td { border-bottom: none; }
        .hx-table tr.hx-conv-row { cursor: pointer; transition: background .12s; }
        .hx-table tr.hx-conv-row:hover td { background: #f5f3ff; }

        /* ── Pills ── */
        .hx-pill {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .hx-pill.purple { background: #ede9fe; color: #6366f1; }
        .hx-pill.green  { background: #dcfce7; color: #16a34a; }
        .hx-pill.gray   { background: #f3f4f6; color: #6b7280; }
        .hx-pill.amber  { background: #fef3c7; color: #d97706; }

        /* ── Conversation expand ── */
        .hx-conv-expand { display: none; }
        .hx-thread {
            background: #f9fafb;
            border-radius: 8px;
            padding: 16px;
            margin: 4px 0 8px;
        }
        .hx-msg-row {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        .hx-msg-row:last-child { margin-bottom: 0; }
        .hx-msg-row.user { flex-direction: row-reverse; }
        .hx-msg-bubble {
            max-width: 72%;
            padding: 10px 14px;
            border-radius: 16px;
            font-size: 13px;
            line-height: 1.55;
        }
        .hx-msg-bubble.user {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: #fff;
            border-radius: 16px 16px 4px 16px;
        }
        .hx-msg-bubble.assistant {
            background: #fff;
            color: #1e1b4b;
            border-radius: 16px 16px 16px 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,.08);
            border: 1px solid #e5e7eb;
        }
        .hx-msg-time {
            font-size: 10px;
            color: #9ca3af;
            margin-top: 4px;
        }
        .hx-msg-row.user .hx-msg-time { text-align: right; }
        .hx-loading {
            text-align: center;
            padding: 24px;
            color: #9ca3af;
            font-size: 13px;
        }

        /* ── Funnel ── */
        .hx-funnel { display: flex; flex-direction: column; gap: 12px; }
        .hx-funnel-step { display: flex; align-items: center; gap: 12px; }
        .hx-funnel-label {
            font-size: 12px;
            font-weight: 500;
            color: #6b7280;
            width: 150px;
            flex-shrink: 0;
        }
        .hx-funnel-bar-wrap {
            flex: 1;
            background: #f3f4f6;
            border-radius: 8px;
            height: 24px;
            overflow: hidden;
        }
        .hx-funnel-bar {
            height: 100%;
            border-radius: 8px;
            background: linear-gradient(90deg, #6366f1, #818cf8);
            transition: width .6s cubic-bezier(.175,.885,.32,1.275);
        }
        .hx-funnel-count {
            font-size: 13px;
            font-weight: 700;
            color: #1e1b4b;
            width: 50px;
            text-align: right;
            flex-shrink: 0;
        }

        /* ── Pager ── */
        .hx-pager {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 16px 20px;
            justify-content: flex-end;
            border-top: 1px solid #f3f4f6;
            flex-wrap: wrap;
        }
        .hx-pager a,
        .hx-pager span {
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
        .hx-pager a:hover { background: #f5f3ff; border-color: #6366f1; color: #6366f1; }
        .hx-pager span.current { background: #6366f1; border-color: #6366f1; color: #fff; }

        /* ── Empty & not-connected states ── */
        .hx-empty {
            text-align: center;
            padding: 48px 20px;
            color: #9ca3af;
            font-size: 13px;
        }
        .hx-no-connect {
            text-align: center;
            padding: 80px 20px;
            color: #6b7280;
        }
        .hx-no-connect h2 {
            font-size: 18px;
            font-weight: 600;
            color: #1e1b4b;
            margin-bottom: 10px;
        }
        .hx-no-connect p { font-size: 13px; margin-bottom: 20px; }
        .hx-btn {
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
            text-decoration: none;
            transition: background .15s;
        }
        .hx-btn-primary { background: #6366f1; color: #fff; }
        .hx-btn-primary:hover { background: #4f46e5; color: #fff; }

        /* ── Responsive ── */
        @media (max-width: 1024px) {
            .hx-cards { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 782px) {
            .hx-admin { padding: 16px 12px 40px; }
            .hx-topbar { flex-direction: column; align-items: flex-start; }
            .hx-nav { margin-left: 0; overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; width: 100%; }
            .hx-cards { grid-template-columns: repeat(2, 1fr); }
            .hx-2col { grid-template-columns: 1fr; }
            .hx-section-head { flex-direction: column; align-items: flex-start; gap: 6px; }
        }
        @media (max-width: 480px) {
            .hx-cards { grid-template-columns: 1fr; }
            .hx-pager { justify-content: center; }
            .hx-funnel-label { width: 100px; font-size: 11px; }
        }
        </style>
        <?php
    }

    // ── Shared header ──────────────────────────────────────────────────────

    private static function render_header( string $title, string $active ): void {
        self::render_styles();
        $links = [
            'eshopeo-dashboard'     => 'Dashboard',
            'eshopeo-conversations' => 'Conversations',
            'eshopeo-analytics'     => 'Analytics',
        ];
        ?>
        <div class="hx-admin">
        <div class="hx-topbar">
            <h1>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="flex-shrink:0;">
                    <circle cx="12" cy="12" r="10" fill="#6366f1" opacity=".12"/>
                    <path d="M8 12.5v-5l6 4.5-6 4.5v-4z" fill="#6366f1"/>
                </svg>
                <?php echo esc_html( $title ); ?>
                <span class="hx-badge">eShopeo</span>
            </h1>
            <nav class="hx-nav">
                <?php foreach ( $links as $slug => $label ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
                       class="<?php echo $active === $slug ? 'active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=eshopeo-connector' ) ); ?>" class="settings-link">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" style="vertical-align:-.1em;"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 100-6 3 3 0 000 6z"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                    Settings
                </a>
            </nav>
        </div>
        <?php
    }

    private static function render_footer(): void {
        echo '</div>'; // .hx-admin
    }

    private static function not_connected(): void {
        ?>
        <div class="hx-no-connect">
            <svg width="48" height="48" fill="none" viewBox="0 0 24 24" style="margin:0 auto 16px;display:block;"><circle cx="12" cy="12" r="10" stroke="#e5e7eb" stroke-width="1.5"/><path stroke="#d1d5db" stroke-width="1.5" stroke-linecap="round" d="M8.5 12.5l2 2 5-5"/></svg>
            <h2>eShopeo is not connected yet</h2>
            <p>Set your API URL and connect your store to start seeing data.</p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=eshopeo-connector' ) ); ?>" class="hx-btn hx-btn-primary">Go to Settings</a>
        </div>
        <?php
    }

    // ── Dashboard ──────────────────────────────────────────────────────────

    public static function render_dashboard(): void {
        self::render_header( 'Dashboard', 'eshopeo-dashboard' );

        $client = self::get_client();
        if ( ! $client ) { self::not_connected(); self::render_footer(); return; }

        $summary  = self::safe_get( $client, 'get_dashboard' );
        $events   = self::safe_get( $client, 'get_widget_events' );
        $convos   = self::safe_get( $client, 'get_conversations', 1, 5 );
        $daily    = self::safe_get( $client, 'get_daily_events', 30 );

        // Parse event summary
        $ev_map = [];
        foreach ( $events['events'] ?? [] as $ev ) {
            $ev_map[ $ev['event_type'] ] = $ev['count'];
        }

        // Build daily chart data
        $chart_labels = [];
        $chart_msgs   = [];
        $chart_atc    = [];
        $chart_wa     = [];
        $day_data = [];
        foreach ( $daily['rows'] ?? [] as $row ) {
            $day_data[ $row['day'] ][ $row['event_type'] ] = $row['count'];
        }
        for ( $i = 29; $i >= 0; $i-- ) {
            $d = date( 'Y-m-d', strtotime( "-{$i} days" ) );
            $chart_labels[] = date( 'M j', strtotime( $d ) );
            $chart_msgs[]   = $day_data[ $d ]['message_sent']    ?? 0;
            $chart_atc[]    = $day_data[ $d ]['add_to_cart']     ?? 0;
            $chart_wa[]     = $day_data[ $d ]['whatsapp_click']  ?? 0;
        }

        $quota_used  = (int) ( $summary['quota_used'] ?? 0 );
        $quota_limit = $summary['quota_limit'] ?? null;
        $quota_pct   = ( $quota_limit && $quota_limit > 0 ) ? min( 100, round( $quota_used / $quota_limit * 100 ) ) : 0;
        ?>

        <!-- Metric cards -->
        <div class="hx-cards">
            <div class="hx-card accent">
                <div class="hx-card-label">Conversations</div>
                <div class="hx-card-value"><?php echo esc_html( $summary['conversations_this_month'] ?? 0 ); ?></div>
                <div class="hx-card-sub">This month</div>
            </div>
            <div class="hx-card green">
                <div class="hx-card-label">Add to Cart</div>
                <div class="hx-card-value"><?php echo esc_html( $ev_map['add_to_cart'] ?? 0 ); ?></div>
                <div class="hx-card-sub">From widget this month</div>
            </div>
            <div class="hx-card whatsapp">
                <div class="hx-card-label">WhatsApp Clicks</div>
                <div class="hx-card-value"><?php echo esc_html( $ev_map['whatsapp_click'] ?? 0 ); ?></div>
                <div class="hx-card-sub">This month</div>
            </div>
            <div class="hx-card amber">
                <div class="hx-card-label">LLM Cost</div>
                <div class="hx-card-value">$<?php echo number_format( $summary['cost_this_month_usd'] ?? 0, 4 ); ?></div>
                <div class="hx-card-sub">This month (USD)</div>
            </div>
            <div class="hx-card">
                <div class="hx-card-label"><?php echo esc_html( Eshopeo_Pack::config()['sync_noun'] ); ?></div>
                <div class="hx-card-value"><?php echo esc_html( $summary['product_count'] ?? 0 ); ?></div>
                <div class="hx-card-sub">In catalog</div>
            </div>
            <div class="hx-card">
                <div class="hx-card-label">Quota Used</div>
                <div class="hx-card-value"><?php echo esc_html( $quota_used ); ?></div>
                <div class="hx-card-sub">
                    of <?php echo esc_html( $quota_limit ?? '—' ); ?> queries
                    <?php if ( $quota_limit ) : ?>
                    <div style="margin-top:8px;background:#f3f4f6;border-radius:4px;height:4px;overflow:hidden;">
                        <div style="background:<?php echo $quota_pct >= 90 ? '#ef4444' : ( $quota_pct >= 70 ? '#f59e0b' : '#6366f1' ); ?>;width:<?php echo $quota_pct; ?>%;height:100%;border-radius:4px;transition:width .3s;"></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Activity chart -->
        <div class="hx-section">
            <div class="hx-section-head">
                <h3 class="hx-section-title">Activity — Last 30 Days</h3>
            </div>
            <div class="hx-section-body">
                <div class="hx-chart-wrap">
                    <canvas id="hx-activity-chart"></canvas>
                </div>
            </div>
        </div>

        <!-- Recent conversations -->
        <div class="hx-section">
            <div class="hx-section-head">
                <h3 class="hx-section-title">Recent Conversations</h3>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=eshopeo-conversations' ) ); ?>" class="hx-section-link">View all &rarr;</a>
            </div>
            <div class="hx-section-body" style="padding:0;">
                <div class="hx-table-wrap">
                    <?php self::render_conversation_table( $convos['items'] ?? [], false ); ?>
                </div>
            </div>
        </div>

        <script>
        (function () {
            var ctx = document.getElementById('hx-activity-chart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo wp_json_encode( $chart_labels ); ?>,
                    datasets: [
                        {
                            label: 'Messages',
                            data: <?php echo wp_json_encode( $chart_msgs ); ?>,
                            borderColor: '#6366f1',
                            backgroundColor: 'rgba(99,102,241,.08)',
                            fill: true,
                            tension: .4,
                            pointRadius: 3,
                            pointBackgroundColor: '#6366f1',
                        },
                        {
                            label: 'Add to Cart',
                            data: <?php echo wp_json_encode( $chart_atc ); ?>,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16,185,129,.06)',
                            fill: true,
                            tension: .4,
                            pointRadius: 3,
                            pointBackgroundColor: '#10b981',
                        },
                        {
                            label: 'WhatsApp',
                            data: <?php echo wp_json_encode( $chart_wa ); ?>,
                            borderColor: '#25d366',
                            backgroundColor: 'rgba(37,211,102,.06)',
                            fill: true,
                            tension: .4,
                            pointRadius: 3,
                            pointBackgroundColor: '#25d366',
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: { font: { size: 12 }, usePointStyle: true, padding: 16 }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 11 }, maxTicksLimit: 10, color: '#9ca3af' }
                        },
                        y: {
                            grid: { color: 'rgba(0,0,0,.05)' },
                            ticks: { font: { size: 11 }, stepSize: 1, precision: 0, color: '#9ca3af' },
                            beginAtZero: true
                        },
                    },
                },
            });
        })();
        </script>

        <?php
        self::render_footer();
    }

    // ── Conversations ──────────────────────────────────────────────────────

    public static function render_conversations(): void {
        self::render_header( 'Conversations', 'eshopeo-conversations' );

        $client = self::get_client();
        if ( ! $client ) { self::not_connected(); self::render_footer(); return; }

        $page   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $data   = self::safe_get( $client, 'get_conversations', $page, 20 );
        $items  = $data['items'] ?? [];
        $total  = $data['total'] ?? 0;
        $pages  = max( 1, (int) ceil( $total / 20 ) );
        ?>

        <div class="hx-section">
            <div class="hx-section-head">
                <h3 class="hx-section-title">All Conversations</h3>
                <span class="hx-section-meta"><?php echo esc_html( $total ); ?> total</span>
            </div>
            <div class="hx-section-body" style="padding:0;">
                <div class="hx-table-wrap">
                    <?php self::render_conversation_table( $items, true ); ?>
                </div>
            </div>

            <?php if ( $pages > 1 ) : ?>
            <div class="hx-pager">
                <?php if ( $page > 1 ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'paged', $page - 1 ) ); ?>">&larr; Prev</a>
                <?php endif; ?>
                <?php for ( $p = max(1,$page-2); $p <= min($pages,$page+2); $p++ ) : ?>
                    <?php if ( $p === $page ) : ?>
                        <span class="current"><?php echo $p; ?></span>
                    <?php else : ?>
                        <a href="<?php echo esc_url( add_query_arg( 'paged', $p ) ); ?>"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ( $page < $pages ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'paged', $page + 1 ) ); ?>">Next &rarr;</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <script>
        (function () {
            var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'eshopeo_get_conversation' ) ); ?>;
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

            document.querySelectorAll('.hx-conv-row').forEach(function (row) {
                row.addEventListener('click', function () {
                    var id   = this.dataset.id;
                    var next = this.nextElementSibling;
                    if ( ! next || ! next.classList.contains('hx-conv-expand') ) return;

                    var isOpen = next.style.display === 'table-row';
                    document.querySelectorAll('.hx-conv-expand').forEach(function (el) {
                        el.style.display = 'none';
                    });
                    if ( isOpen ) return;

                    next.style.display = 'table-row';
                    var inner = next.querySelector('.hx-conv-expand-inner');
                    if ( inner.dataset.loaded ) return;

                    inner.innerHTML = '<div class="hx-loading">Loading conversation&hellip;</div>';

                    var fd = new FormData();
                    fd.append( 'action', 'eshopeo_get_conversation' );
                    fd.append( 'nonce', nonce );
                    fd.append( 'conversation_id', id );
                    fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } )
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if ( ! res.success ) {
                                inner.innerHTML = '<div class="hx-loading">Could not load conversation.</div>';
                                return;
                            }
                            var msgs = res.data.messages || [];
                            var html = '<div class="hx-thread">';
                            msgs.forEach(function (m) {
                                var time = new Date(m.created_at).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
                                html += '<div class="hx-msg-row ' + m.role + '">';
                                html += '<div>';
                                html += '<div class="hx-msg-bubble ' + m.role + '">' + m.content.replace(/</g, '&lt;') + '</div>';
                                html += '<div class="hx-msg-time">' + time;
                                if (m.feedback) html += ' &middot; ' + (m.feedback === 'thumbs_up' ? '&#128077;' : '&#128078;');
                                html += '</div>';
                                html += '</div></div>';
                            });
                            html += '</div>';
                            inner.innerHTML = html;
                            inner.dataset.loaded = '1';
                        })
                        .catch(function () {
                            inner.innerHTML = '<div class="hx-loading">Error loading conversation.</div>';
                        });
                });
            });
        })();
        </script>

        <?php
        self::render_footer();
    }

    private static function render_conversation_table( array $items, bool $expandable ): void {
        if ( empty( $items ) ) {
            echo '<div class="hx-empty">No conversations yet. The widget needs to be active on your store.</div>';
            return;
        }
        ?>
        <table class="hx-table">
            <thead>
                <tr>
                    <th>First Message</th>
                    <th>Date</th>
                    <th>Messages</th>
                    <th>Add to Cart</th>
                    <th>WhatsApp</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $items as $conv ) :
                    $preview = mb_strimwidth( $conv['first_message'] ?? '(no messages)', 0, 80, '…' );
                    $date    = $conv['created_at'] ? date( 'M j, g:ia', strtotime( $conv['created_at'] ) ) : '—';
                    $atc     = (int) ( $conv['add_to_cart_count'] ?? 0 );
                    $wa      = (int) ( $conv['whatsapp_click_count'] ?? 0 );
                ?>
                <tr class="hx-conv-row" data-id="<?php echo esc_attr( $conv['id'] ); ?>">
                    <td><?php echo esc_html( $preview ); ?></td>
                    <td style="white-space:nowrap;color:#6b7280;font-size:12px;"><?php echo esc_html( $date ); ?></td>
                    <td><span class="hx-pill purple"><?php echo esc_html( $conv['message_count'] ); ?></span></td>
                    <td><?php echo $atc > 0 ? '<span class="hx-pill green">&#10003; ' . esc_html($atc) . '</span>' : '<span class="hx-pill gray">—</span>'; ?></td>
                    <td><?php echo $wa > 0  ? '<span class="hx-pill green">&#10003; ' . esc_html($wa) . '</span>'  : '<span class="hx-pill gray">—</span>'; ?></td>
                </tr>
                <?php if ( $expandable ) : ?>
                <tr class="hx-conv-expand" style="display:none;">
                    <td colspan="5" style="padding:0 14px 14px;background:#f9fafb;">
                        <div class="hx-conv-expand-inner"></div>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // ── Analytics ──────────────────────────────────────────────────────────

    public static function render_analytics(): void {
        self::render_header( 'Analytics', 'eshopeo-analytics' );

        $client = self::get_client();
        if ( ! $client ) { self::not_connected(); self::render_footer(); return; }

        $events  = self::safe_get( $client, 'get_widget_events' );
        $daily   = self::safe_get( $client, 'get_daily_events', 30 );
        $summary = self::safe_get( $client, 'get_dashboard' );
        $convos  = self::safe_get( $client, 'get_conversations', 1, 1 );

        $ev_map = [];
        foreach ( $events['events'] ?? [] as $ev ) {
            $ev_map[ $ev['event_type'] ] = $ev['count'];
        }

        $total_convos  = (int) ( $summary['conversations_this_month'] ?? 0 );
        $msgs_sent     = (int) ( $ev_map['message_sent']    ?? 0 );
        $atc           = (int) ( $ev_map['add_to_cart']     ?? 0 );
        $wa            = (int) ( $ev_map['whatsapp_click']  ?? 0 );
        $max_funnel    = max( 1, $total_convos );

        // Per-event-type chart data
        $ev_labels = [];
        $ev_counts = [];
        $ev_colors = [];
        $color_map = [
            'message_sent'   => '#6366f1',
            'add_to_cart'    => '#10b981',
            'whatsapp_click' => '#25d366',
        ];
        foreach ( $events['events'] ?? [] as $ev ) {
            $ev_labels[] = str_replace( '_', ' ', ucfirst( $ev['event_type'] ) );
            $ev_counts[] = $ev['count'];
            $ev_colors[] = $color_map[ $ev['event_type'] ] ?? '#94a3b8';
        }

        // 30-day data for trend chart
        $day_data = [];
        foreach ( $daily['rows'] ?? [] as $row ) {
            $day_data[ $row['day'] ][ $row['event_type'] ] = $row['count'];
        }
        $spark_labels = [];
        $spark_msgs   = [];
        $spark_atc    = [];
        for ( $i = 29; $i >= 0; $i-- ) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $spark_labels[] = date('M j', strtotime($d));
            $spark_msgs[]   = $day_data[$d]['message_sent']  ?? 0;
            $spark_atc[]    = $day_data[$d]['add_to_cart']   ?? 0;
        }

        $q = (int) ( $summary['queries_this_month'] ?? 0 );
        $c = (float) ( $summary['cost_this_month_usd'] ?? 0 );
        ?>

        <!-- Cost metric cards -->
        <div class="hx-cards" style="grid-template-columns:repeat(3,1fr);">
            <div class="hx-card amber">
                <div class="hx-card-label">Total LLM Cost</div>
                <div class="hx-card-value">$<?php echo number_format($c, 4); ?></div>
                <div class="hx-card-sub">USD this month</div>
            </div>
            <div class="hx-card">
                <div class="hx-card-label">Queries</div>
                <div class="hx-card-value"><?php echo esc_html($q); ?></div>
                <div class="hx-card-sub">This month</div>
            </div>
            <div class="hx-card">
                <div class="hx-card-label">Cost per Query</div>
                <div class="hx-card-value" style="font-size:24px;"><?php echo $q > 0 ? '$' . number_format($c / $q, 5) : '—'; ?></div>
                <div class="hx-card-sub">Average</div>
            </div>
        </div>

        <div class="hx-2col">
            <!-- Conversion Funnel -->
            <div class="hx-section">
                <div class="hx-section-head">
                    <h3 class="hx-section-title">Conversion Funnel — This Month</h3>
                </div>
                <div class="hx-section-body">
                    <div class="hx-funnel">
                        <?php
                        $funnel = [
                            [ 'Conversations', $total_convos, $max_funnel ],
                            [ 'Messages sent', $msgs_sent,   $max_funnel ],
                            [ 'Add to Cart',   $atc,         $max_funnel ],
                            [ 'WhatsApp click',$wa,          $max_funnel ],
                        ];
                        foreach ( $funnel as [$label, $val, $max] ) :
                            $pct = $max > 0 ? round( $val / $max * 100 ) : 0;
                        ?>
                        <div class="hx-funnel-step">
                            <div class="hx-funnel-label"><?php echo esc_html( $label ); ?></div>
                            <div class="hx-funnel-bar-wrap">
                                <div class="hx-funnel-bar" style="width:<?php echo $pct; ?>%"></div>
                            </div>
                            <div class="hx-funnel-count"><?php echo esc_html( $val ); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Event breakdown donut -->
            <div class="hx-section">
                <div class="hx-section-head">
                    <h3 class="hx-section-title">Event Breakdown — This Month</h3>
                </div>
                <div class="hx-section-body">
                    <?php if ( empty( $ev_labels ) ) : ?>
                        <div class="hx-empty">No events recorded yet.</div>
                    <?php else : ?>
                        <div class="hx-chart-wrap short">
                            <canvas id="hx-donut-chart"></canvas>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 30-day trend bar chart -->
        <div class="hx-section">
            <div class="hx-section-head">
                <h3 class="hx-section-title">30-Day Trend</h3>
            </div>
            <div class="hx-section-body">
                <div class="hx-chart-wrap">
                    <canvas id="hx-trend-chart"></canvas>
                </div>
            </div>
        </div>

        <script>
        (function () {
            <?php if ( ! empty( $ev_labels ) ) : ?>
            new Chart(document.getElementById('hx-donut-chart').getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: <?php echo wp_json_encode($ev_labels); ?>,
                    datasets: [{
                        data: <?php echo wp_json_encode($ev_counts); ?>,
                        backgroundColor: <?php echo wp_json_encode($ev_colors); ?>,
                        borderWidth: 0,
                        hoverOffset: 6,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { font: {size:12}, padding:16, usePointStyle:true } }
                    },
                    cutout: '62%',
                },
            });
            <?php endif; ?>

            new Chart(document.getElementById('hx-trend-chart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?php echo wp_json_encode($spark_labels); ?>,
                    datasets: [
                        {
                            label: 'Messages sent',
                            data: <?php echo wp_json_encode($spark_msgs); ?>,
                            backgroundColor: 'rgba(99,102,241,.75)',
                            borderRadius: 3,
                        },
                        {
                            label: 'Add to Cart',
                            data: <?php echo wp_json_encode($spark_atc); ?>,
                            backgroundColor: 'rgba(16,185,129,.75)',
                            borderRadius: 3,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top', labels: { font: {size:12}, usePointStyle:true, padding:16 } }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { font: {size:11}, maxTicksLimit:10, color:'#9ca3af' }
                        },
                        y: {
                            grid: { color: 'rgba(0,0,0,.05)' },
                            ticks: { font: {size:11}, stepSize:1, precision:0, color:'#9ca3af' },
                            beginAtZero: true,
                        },
                    },
                },
            });
        })();
        </script>

        <?php
        self::render_footer();
    }

    // ── AJAX: get conversation detail ──────────────────────────────────────

    public static function ajax_get_conversation(): void {
        check_ajax_referer( 'eshopeo_get_conversation', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $conv_id = sanitize_text_field( $_POST['conversation_id'] ?? '' );
        if ( ! $conv_id ) {
            wp_send_json_error( [ 'message' => 'Missing conversation_id' ] );
        }

        $client = self::get_client();
        if ( ! $client ) {
            wp_send_json_error( [ 'message' => 'Not connected' ] );
        }

        $result = $client->get_conversation_messages( $conv_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }
}
