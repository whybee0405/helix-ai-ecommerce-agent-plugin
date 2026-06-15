<?php
defined( 'ABSPATH' ) || exit;

class Helix_Dashboard {

    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'register_menu' ], 5 );
        add_action( 'wp_ajax_helix_get_conversation', [ self::class, 'ajax_get_conversation' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
    }

    public static function register_menu(): void {
        add_menu_page(
            'Helix AI',
            'Helix AI',
            'manage_woocommerce',
            'helix-dashboard',
            [ self::class, 'render_dashboard' ],
            'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#a78bfa"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>' ),
            56
        );
        add_submenu_page( 'helix-dashboard', 'Dashboard', 'Dashboard', 'manage_woocommerce', 'helix-dashboard', [ self::class, 'render_dashboard' ] );
        add_submenu_page( 'helix-dashboard', 'Conversations', 'Conversations', 'manage_woocommerce', 'helix-conversations', [ self::class, 'render_conversations' ] );
        add_submenu_page( 'helix-dashboard', 'Analytics', 'Analytics', 'manage_woocommerce', 'helix-analytics', [ self::class, 'render_analytics' ] );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'helix-' ) === false ) return;
        wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', [], '4.4.0', true );
    }

    private static function get_client(): ?Helix_API_Client {
        $api_url = get_option( 'helix_api_url', '' );
        $pub_key = get_option( 'helix_public_key', '' );
        if ( ! $api_url || ! $pub_key ) return null;
        return new Helix_API_Client( $api_url, $pub_key );
    }

    private static function safe_get( Helix_API_Client $client, string $method, ...$args ): array {
        $result = $client->$method( ...$args );
        return is_wp_error( $result ) ? [] : ( $result ?: [] );
    }

    // ── Shared header ──────────────────────────────────────────────────────

    private static function render_header( string $title, string $active ): void {
        $links = [
            'helix-dashboard'     => 'Dashboard',
            'helix-conversations' => 'Conversations',
            'helix-analytics'     => 'Analytics',
        ];
        ?>
        <style>
        .hx-admin{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:1200px;padding:20px 20px 40px;}
        .hx-topbar{display:flex;align-items:center;gap:16px;margin-bottom:28px;flex-wrap:wrap;}
        .hx-topbar h1{margin:0;font-size:22px;font-weight:700;color:#1C1C1E;display:flex;align-items:center;gap:10px;}
        .hx-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:linear-gradient(135deg,#7C3AED,#4F46E5);color:#fff;letter-spacing:.03em;}
        .hx-nav{display:flex;gap:4px;margin-left:auto;}
        .hx-nav a{padding:7px 16px;border-radius:8px;font-size:13px;font-weight:500;text-decoration:none;color:#6B6B6F;transition:all .15s;}
        .hx-nav a:hover{background:rgba(124,58,237,.08);color:#7C3AED;}
        .hx-nav a.active{background:linear-gradient(135deg,#7C3AED,#4F46E5);color:#fff;}
        .hx-nav a.active:hover{color:#fff;}
        .hx-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-bottom:28px;}
        .hx-card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.07),0 0 0 1px rgba(0,0,0,.04);}
        .hx-card-label{font-size:11px;font-weight:600;color:#6B6B6F;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;}
        .hx-card-value{font-size:28px;font-weight:700;color:#1C1C1E;line-height:1;}
        .hx-card-sub{font-size:11px;color:#AEAEB2;margin-top:5px;}
        .hx-card.accent .hx-card-value{background:linear-gradient(135deg,#7C3AED,#4F46E5);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
        .hx-card.green .hx-card-value{color:#16a34a;}
        .hx-card.amber .hx-card-value{color:#d97706;}
        .hx-section{background:#fff;border-radius:14px;padding:22px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.07),0 0 0 1px rgba(0,0,0,.04);}
        .hx-section-title{font-size:14px;font-weight:600;color:#1C1C1E;margin:0 0 18px;display:flex;align-items:center;justify-content:space-between;}
        .hx-section-title a{font-size:12px;font-weight:500;color:#7C3AED;text-decoration:none;}
        .hx-table{width:100%;border-collapse:collapse;}
        .hx-table th{font-size:11px;font-weight:600;color:#6B6B6F;text-transform:uppercase;letter-spacing:.05em;padding:0 12px 10px;text-align:left;border-bottom:1px solid rgba(0,0,0,.07);}
        .hx-table td{padding:12px;border-bottom:1px solid rgba(0,0,0,.04);font-size:13px;color:#1C1C1E;vertical-align:middle;}
        .hx-table tr:last-child td{border-bottom:none;}
        .hx-table tr.hx-conv-row{cursor:pointer;transition:background .12s;}
        .hx-table tr.hx-conv-row:hover td{background:rgba(124,58,237,.04);}
        .hx-table tr.hx-conv-row:hover td:first-child{border-radius:8px 0 0 8px;}
        .hx-table tr.hx-conv-row:hover td:last-child{border-radius:0 8px 8px 0;}
        .hx-pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;}
        .hx-pill.green{background:#dcfce7;color:#16a34a;}
        .hx-pill.purple{background:#ede9fe;color:#7C3AED;}
        .hx-pill.gray{background:#f3f4f6;color:#6B7280;}
        .hx-pill.amber{background:#fef3c7;color:#d97706;}
        .hx-empty{text-align:center;padding:48px 16px;color:#AEAEB2;font-size:13px;}
        .hx-thread{background:rgba(242,242,247,.6);border-radius:10px;padding:16px;margin-top:8px;}
        .hx-msg-row{display:flex;gap:10px;margin-bottom:12px;}
        .hx-msg-row:last-child{margin-bottom:0;}
        .hx-msg-row.user{flex-direction:row-reverse;}
        .hx-msg-bubble{max-width:72%;padding:10px 14px;border-radius:16px;font-size:13px;line-height:1.55;}
        .hx-msg-bubble.user{background:linear-gradient(135deg,#7C3AED,#4F46E5);color:#fff;border-radius:16px 16px 4px 16px;}
        .hx-msg-bubble.assistant{background:#fff;color:#1C1C1E;border-radius:16px 16px 16px 4px;box-shadow:0 1px 4px rgba(0,0,0,.08);}
        .hx-msg-time{font-size:10px;color:#AEAEB2;margin-top:4px;text-align:right;}
        .hx-loading{text-align:center;padding:24px;color:#AEAEB2;}
        .hx-conv-expand{display:none;padding:4px 12px 12px;}
        .hx-funnel{display:flex;flex-direction:column;gap:8px;}
        .hx-funnel-step{display:flex;align-items:center;gap:12px;}
        .hx-funnel-bar-wrap{flex:1;background:rgba(0,0,0,.05);border-radius:8px;height:28px;overflow:hidden;}
        .hx-funnel-bar{height:100%;border-radius:8px;background:linear-gradient(90deg,#7C3AED,#4F46E5);transition:width .6s cubic-bezier(.175,.885,.32,1.275);}
        .hx-funnel-label{font-size:12px;font-weight:600;color:#6B6B6F;width:140px;flex-shrink:0;}
        .hx-funnel-count{font-size:13px;font-weight:700;color:#1C1C1E;width:50px;text-align:right;flex-shrink:0;}
        .hx-chart-wrap{position:relative;height:220px;}
        .hx-2col{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
        @media(max-width:768px){.hx-2col{grid-template-columns:1fr;}}
        .hx-no-connect{text-align:center;padding:60px 20px;color:#6B6B6F;}
        .hx-no-connect h2{font-size:18px;color:#1C1C1E;margin-bottom:10px;}
        .hx-no-connect a.button{margin-top:16px;}
        .hx-pager{display:flex;align-items:center;gap:8px;margin-top:16px;justify-content:flex-end;}
        .hx-pager a,.hx-pager span{padding:5px 12px;border-radius:7px;font-size:12px;font-weight:500;text-decoration:none;border:1px solid rgba(0,0,0,.12);color:#6B6B6F;}
        .hx-pager a:hover{background:#f3f0ff;border-color:#7C3AED;color:#7C3AED;}
        .hx-pager span.current{background:linear-gradient(135deg,#7C3AED,#4F46E5);color:#fff;border-color:transparent;}
        </style>
        <div class="hx-admin">
        <div class="hx-topbar">
            <h1>🤖 <?php echo esc_html( $title ); ?> <span class="hx-badge">Helix AI</span></h1>
            <nav class="hx-nav">
                <?php foreach ( $links as $slug => $label ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
                       class="<?php echo $active === $slug ? 'active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=helix-connector' ) ); ?>" style="border:1px solid rgba(0,0,0,.1);">⚙ Settings</a>
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
            <h2>Helix is not connected yet</h2>
            <p>Set your API URL and connect your store to start seeing data.</p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=helix-connector' ) ); ?>" class="button button-primary">Go to Settings</a>
        </div>
        <?php
    }

    // ── Dashboard ──────────────────────────────────────────────────────────

    public static function render_dashboard(): void {
        self::render_header( 'Dashboard', 'helix-dashboard' );

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
        // Collect all days in last 30
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
        ?>

        <!-- Stats cards -->
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
            <div class="hx-card" style="--c:#25D366;">
                <div class="hx-card-label">WhatsApp Clicks</div>
                <div class="hx-card-value" style="color:#16a34a;"><?php echo esc_html( $ev_map['whatsapp_click'] ?? 0 ); ?></div>
                <div class="hx-card-sub">This month</div>
            </div>
            <div class="hx-card amber">
                <div class="hx-card-label">LLM Cost</div>
                <div class="hx-card-value">$<?php echo number_format( $summary['cost_this_month_usd'] ?? 0, 4 ); ?></div>
                <div class="hx-card-sub">This month</div>
            </div>
            <div class="hx-card">
                <div class="hx-card-label">Products</div>
                <div class="hx-card-value"><?php echo esc_html( $summary['product_count'] ?? 0 ); ?></div>
                <div class="hx-card-sub">Synced</div>
            </div>
            <div class="hx-card">
                <div class="hx-card-label">Quota Used</div>
                <div class="hx-card-value"><?php echo esc_html( $summary['quota_used'] ?? 0 ); ?></div>
                <div class="hx-card-sub">of <?php echo esc_html( $summary['quota_limit'] ?? '—' ); ?> queries</div>
            </div>
        </div>

        <!-- Activity chart -->
        <div class="hx-section">
            <div class="hx-section-title">Activity — Last 30 Days</div>
            <div class="hx-chart-wrap">
                <canvas id="hx-activity-chart"></canvas>
            </div>
        </div>

        <!-- Recent conversations -->
        <div class="hx-section">
            <div class="hx-section-title">
                Recent Conversations
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=helix-conversations' ) ); ?>">View all →</a>
            </div>
            <?php self::render_conversation_table( $convos['items'] ?? [], false ); ?>
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
                            borderColor: '#7C3AED',
                            backgroundColor: 'rgba(124,58,237,.08)',
                            fill: true,
                            tension: .4,
                            pointRadius: 3,
                        },
                        {
                            label: 'Add to Cart',
                            data: <?php echo wp_json_encode( $chart_atc ); ?>,
                            borderColor: '#16a34a',
                            backgroundColor: 'rgba(22,163,74,.06)',
                            fill: true,
                            tension: .4,
                            pointRadius: 3,
                        },
                        {
                            label: 'WhatsApp',
                            data: <?php echo wp_json_encode( $chart_wa ); ?>,
                            borderColor: '#25D366',
                            backgroundColor: 'rgba(37,211,102,.06)',
                            fill: true,
                            tension: .4,
                            pointRadius: 3,
                        },
                    ],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'top', labels: { font: { size: 12 }, usePointStyle: true, padding: 16 } } },
                    scales: {
                        x: { grid: { display: false }, ticks: { font: { size: 11 }, maxTicksLimit: 10 } },
                        y: { grid: { color: 'rgba(0,0,0,.05)' }, ticks: { font: { size: 11 }, stepSize: 1, precision: 0 }, beginAtZero: true },
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
        self::render_header( 'Conversations', 'helix-conversations' );

        $client = self::get_client();
        if ( ! $client ) { self::not_connected(); self::render_footer(); return; }

        $page   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $data   = self::safe_get( $client, 'get_conversations', $page, 20 );
        $items  = $data['items'] ?? [];
        $total  = $data['total'] ?? 0;
        $pages  = max( 1, (int) ceil( $total / 20 ) );
        ?>

        <div class="hx-section">
            <div class="hx-section-title">
                All Conversations
                <span style="font-size:12px;font-weight:400;color:#6B6B6F;"><?php echo esc_html( $total ); ?> total</span>
            </div>
            <?php self::render_conversation_table( $items, true ); ?>

            <?php if ( $pages > 1 ) : ?>
            <div class="hx-pager">
                <?php if ( $page > 1 ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'paged', $page - 1 ) ); ?>">← Prev</a>
                <?php endif; ?>
                <?php for ( $p = max(1,$page-2); $p <= min($pages,$page+2); $p++ ) : ?>
                    <?php if ( $p === $page ) : ?>
                        <span class="current"><?php echo $p; ?></span>
                    <?php else : ?>
                        <a href="<?php echo esc_url( add_query_arg( 'paged', $p ) ); ?>"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ( $page < $pages ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'paged', $page + 1 ) ); ?>">Next →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <script>
        (function () {
            var nonce = <?php echo wp_json_encode( wp_create_nonce( 'helix_get_conversation' ) ); ?>;
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

                    inner.innerHTML = '<div class="hx-loading">Loading conversation…</div>';
                    var fd = new FormData();
                    fd.append( 'action', 'helix_get_conversation' );
                    fd.append( 'nonce', nonce );
                    fd.append( 'conversation_id', id );
                    fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } )
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if ( ! res.success ) { inner.innerHTML = '<div class="hx-loading">Could not load conversation.</div>'; return; }
                            var msgs = res.data.messages || [];
                            var html = '<div class="hx-thread">';
                            msgs.forEach(function (m) {
                                var isUser = m.role === 'user';
                                var time = new Date(m.created_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
                                html += '<div class="hx-msg-row ' + m.role + '">';
                                html += '<div>';
                                html += '<div class="hx-msg-bubble ' + m.role + '">' + m.content.replace(/</g,'&lt;') + '</div>';
                                html += '<div class="hx-msg-time">' + time;
                                if (m.feedback) html += ' · ' + (m.feedback === 'thumbs_up' ? '👍' : '👎');
                                html += '</div>';
                                html += '</div></div>';
                            });
                            html += '</div>';
                            inner.innerHTML = html;
                            inner.dataset.loaded = '1';
                        })
                        .catch(function () { inner.innerHTML = '<div class="hx-loading">Error loading conversation.</div>'; });
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
                    <td style="white-space:nowrap;color:#6B6B6F;"><?php echo esc_html( $date ); ?></td>
                    <td><span class="hx-pill purple"><?php echo esc_html( $conv['message_count'] ); ?></span></td>
                    <td><?php echo $atc > 0 ? '<span class="hx-pill green">✓ ' . esc_html($atc) . '</span>' : '<span class="hx-pill gray">—</span>'; ?></td>
                    <td><?php echo $wa > 0 ? '<span class="hx-pill" style="background:#dcfce7;color:#16a34a;">✓ ' . esc_html($wa) . '</span>' : '<span class="hx-pill gray">—</span>'; ?></td>
                </tr>
                <?php if ( $expandable ) : ?>
                <tr class="hx-conv-expand" style="display:none;">
                    <td colspan="5" style="padding:0 12px 12px;">
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
        self::render_header( 'Analytics', 'helix-analytics' );

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
            'message_sent'   => '#7C3AED',
            'add_to_cart'    => '#16a34a',
            'whatsapp_click' => '#25D366',
        ];
        foreach ( $events['events'] ?? [] as $ev ) {
            $ev_labels[] = str_replace( '_', ' ', ucfirst( $ev['event_type'] ) );
            $ev_counts[] = $ev['count'];
            $ev_colors[] = $color_map[ $ev['event_type'] ] ?? '#94a3b8';
        }

        // 30-day message_sent for sparkline
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
        ?>

        <div class="hx-2col">
            <!-- Funnel -->
            <div class="hx-section">
                <div class="hx-section-title">Conversion Funnel — This Month</div>
                <div class="hx-funnel">
                    <?php
                    $funnel = [
                        [ 'Conversations started', $total_convos, $max_funnel ],
                        [ 'Messages sent', $msgs_sent, $max_funnel ],
                        [ 'Add to Cart', $atc, $max_funnel ],
                        [ 'WhatsApp click', $wa, $max_funnel ],
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

            <!-- Event breakdown donut -->
            <div class="hx-section">
                <div class="hx-section-title">Event Breakdown — This Month</div>
                <?php if ( empty( $ev_labels ) ) : ?>
                    <div class="hx-empty">No events recorded yet.</div>
                <?php else : ?>
                    <div class="hx-chart-wrap" style="height:200px;">
                        <canvas id="hx-donut-chart"></canvas>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 30-day trend -->
        <div class="hx-section">
            <div class="hx-section-title">30-Day Trend</div>
            <div class="hx-chart-wrap">
                <canvas id="hx-trend-chart"></canvas>
            </div>
        </div>

        <!-- LLM cost card -->
        <div class="hx-section">
            <div class="hx-section-title">Cost Summary — This Month</div>
            <div class="hx-cards" style="margin-bottom:0;">
                <div class="hx-card amber">
                    <div class="hx-card-label">Total LLM Cost</div>
                    <div class="hx-card-value">$<?php echo number_format($summary['cost_this_month_usd'] ?? 0, 4); ?></div>
                    <div class="hx-card-sub">USD this month</div>
                </div>
                <div class="hx-card">
                    <div class="hx-card-label">Queries</div>
                    <div class="hx-card-value"><?php echo esc_html($summary['queries_this_month'] ?? 0); ?></div>
                    <div class="hx-card-sub">This month</div>
                </div>
                <div class="hx-card">
                    <div class="hx-card-label">Cost per query</div>
                    <div class="hx-card-value">
                        <?php
                        $q = (int)($summary['queries_this_month'] ?? 0);
                        $c = (float)($summary['cost_this_month_usd'] ?? 0);
                        echo $q > 0 ? '$' . number_format($c / $q, 5) : '—';
                        ?>
                    </div>
                    <div class="hx-card-sub">Average</div>
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
                    datasets: [{ data: <?php echo wp_json_encode($ev_counts); ?>, backgroundColor: <?php echo wp_json_encode($ev_colors); ?>, borderWidth: 0, hoverOffset: 6 }],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { font: {size:12}, padding:16, usePointStyle:true } } },
                    cutout: '62%',
                },
            });
            <?php endif; ?>

            new Chart(document.getElementById('hx-trend-chart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?php echo wp_json_encode($spark_labels); ?>,
                    datasets: [
                        { label: 'Messages sent', data: <?php echo wp_json_encode($spark_msgs); ?>, backgroundColor: 'rgba(124,58,237,.7)', borderRadius: 4 },
                        { label: 'Add to Cart', data: <?php echo wp_json_encode($spark_atc); ?>, backgroundColor: 'rgba(22,163,74,.7)', borderRadius: 4 },
                    ],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position:'top', labels:{ font:{size:12}, usePointStyle:true, padding:16 } } },
                    scales: {
                        x: { grid:{display:false}, ticks:{ font:{size:11}, maxTicksLimit:10 } },
                        y: { grid:{ color:'rgba(0,0,0,.05)' }, ticks:{ font:{size:11}, stepSize:1, precision:0 }, beginAtZero:true },
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
        check_ajax_referer( 'helix_get_conversation', 'nonce' );
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
