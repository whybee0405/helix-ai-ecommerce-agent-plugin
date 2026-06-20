<?php
defined( 'ABSPATH' ) || exit;

class Helix_Cost_Dashboard {
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'add_menu' ], 30 );
    }

    public static function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            'Helix Usage & Costs',
            'Helix Usage',
            'manage_woocommerce',
            'helix-usage',
            [ self::class, 'render_page' ]
        );
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $tenant_id    = get_option( 'helix_tenant_id', '' );
        $admin_secret = get_option( 'helix_admin_secret', '' );

        // Shared styles for all states
        ?>
        <style>
        /* ── Helix Cost Dashboard ── */
        .helix-usage-page *,
        .helix-usage-page *::before,
        .helix-usage-page *::after { box-sizing: border-box; }

        .helix-usage-page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px 20px 60px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #374151;
            background: #f9fafb;
        }

        /* ── Header ── */
        .helix-usage-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 28px;
        }
        .helix-usage-header h1 {
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
        .helix-usage-subtitle {
            font-size: 13px;
            color: #6b7280;
            margin: 0;
        }

        /* ── Alert notices ── */
        .helix-usage-notice {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            line-height: 1.5;
        }
        .helix-usage-notice.warning { background: #fffbeb; border: 1px solid #fcd34d; color: #78350f; }
        .helix-usage-notice.error   { background: #fef2f2; border: 1px solid #fca5a5; color: #7f1d1d; }

        /* ── Metric cards ── */
        .helix-usage-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        .helix-usage-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
            position: relative;
            overflow: hidden;
        }
        .helix-usage-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            border-radius: 8px 8px 0 0;
            background: #e5e7eb;
        }
        .helix-usage-card.tier::before   { background: linear-gradient(90deg, #6366f1, #818cf8); }
        .helix-usage-card.spend::before  { background: #f59e0b; }
        .helix-usage-card.total::before  { background: #ef4444; }
        .helix-usage-card.status::before { background: #10b981; }
        .helix-usage-card-label {
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 10px;
        }
        .helix-usage-card-value {
            font-size: 28px;
            font-weight: 700;
            color: #1e1b4b;
            line-height: 1;
            letter-spacing: -.01em;
        }
        .helix-usage-card.tier   .helix-usage-card-value { color: #6366f1; text-transform: capitalize; }
        .helix-usage-card.spend  .helix-usage-card-value { color: #f59e0b; }
        .helix-usage-card.total  .helix-usage-card-value { color: #ef4444; }
        .helix-usage-card.status .helix-usage-card-value { font-size: 14px; font-weight: 600; }
        .helix-usage-card-sub {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 6px;
        }

        /* ── Status badge inside card ── */
        .helix-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .helix-status-badge.normal   { background: #ecfdf5; color: #065f46; }
        .helix-status-badge.degraded { background: #fffbeb; color: #78350f; }
        .helix-status-badge.blocked  { background: #fef2f2; color: #7f1d1d; }
        .helix-status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
        }
        .helix-status-badge.normal   .helix-status-dot { background: #10b981; }
        .helix-status-badge.degraded .helix-status-dot { background: #f59e0b; }
        .helix-status-badge.blocked  .helix-status-dot { background: #ef4444; }

        /* ── Budget progress ── */
        .helix-usage-section {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .helix-usage-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            border-bottom: 1px solid #e5e7eb;
            background: #fafafa;
        }
        .helix-usage-section-head h2 {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            color: #1e1b4b;
            border-left: 3px solid #6366f1;
            padding-left: 10px;
        }
        .helix-usage-section-body { padding: 24px; }

        .helix-budget-wrap {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .helix-budget-labels {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 8px;
        }
        .helix-budget-track {
            flex: 1;
            min-width: 200px;
        }
        .helix-budget-bar-bg {
            background: #f3f4f6;
            border-radius: 8px;
            height: 12px;
            overflow: hidden;
        }
        .helix-budget-bar-fill {
            height: 100%;
            border-radius: 8px;
            transition: width .4s ease;
        }
        .helix-budget-pct {
            font-size: 28px;
            font-weight: 700;
            flex-shrink: 0;
            min-width: 60px;
            text-align: right;
        }

        /* ── Daily table ── */
        .helix-usage-table-wrap { overflow-x: auto; }
        .helix-usage-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .helix-usage-table thead th {
            position: sticky;
            top: 0;
            background: #fff;
            z-index: 1;
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .05em;
            padding: 10px 16px;
            border-bottom: 2px solid #e5e7eb;
            text-align: left;
            white-space: nowrap;
        }
        .helix-usage-table thead th:not(:first-child) { text-align: right; }
        .helix-usage-table tbody td {
            padding: 11px 16px;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
            vertical-align: middle;
        }
        .helix-usage-table tbody td:not(:first-child) { text-align: right; font-variant-numeric: tabular-nums; }
        .helix-usage-table tbody tr:last-child td { border-bottom: none; }
        .helix-usage-table tbody tr:nth-child(even) td { background: #f9fafb; }
        .helix-usage-table tbody tr:hover td { background: #f5f3ff; }
        .helix-usage-table .cost-cell { font-weight: 600; color: #1e1b4b; }
        .helix-usage-table .date-cell { color: #6b7280; font-size: 12px; font-weight: 500; }
        .helix-usage-table-empty {
            text-align: center;
            padding: 48px 20px;
            color: #9ca3af;
            font-size: 13px;
        }

        /* ── Responsive ── */
        @media (max-width: 1024px) {
            .helix-usage-cards { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 782px) {
            .helix-usage-page { padding: 16px 12px 40px; }
            .helix-usage-header { flex-direction: column; align-items: flex-start; }
            .helix-usage-cards { grid-template-columns: repeat(2, 1fr); }
            .helix-usage-section-body { padding: 16px; }
        }
        @media (max-width: 480px) {
            .helix-usage-cards { grid-template-columns: 1fr; }
            .helix-budget-pct { font-size: 22px; min-width: 48px; }
        }
        </style>
        <?php

        if ( ! $tenant_id || ! $admin_secret ) {
            ?>
            <div class="helix-usage-page">
                <div class="helix-usage-header">
                    <h1>
                        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" aria-hidden="true"><path stroke="#6366f1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Helix Usage &amp; Costs
                        <span class="helix-badge">Helix AI</span>
                    </h1>
                </div>
                <div class="helix-usage-notice warning">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="#f59e0b" stroke-width="2" stroke-linecap="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                    Save your Helix settings once to bootstrap admin credentials before viewing usage data.
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=helix-connector' ) ); ?>" style="margin-left:auto;white-space:nowrap;color:#78350f;font-weight:600;">Go to Settings &rarr;</a>
                </div>
            </div>
            <?php
            return;
        }

        $client = new Helix_API_Client( get_option( 'helix_api_url', '' ), get_option( 'helix_public_key', '' ) );
        $usage  = $client->get_usage_summary( 30 );

        if ( is_wp_error( $usage ) ) {
            ?>
            <div class="helix-usage-page">
                <div class="helix-usage-header">
                    <h1>Helix Usage &amp; Costs <span class="helix-badge">Helix AI</span></h1>
                </div>
                <div class="helix-usage-notice error">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="#ef4444" stroke-width="2"/><path stroke="#ef4444" stroke-width="2" stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>
                    Could not load usage data: <?php echo esc_html( $usage->get_error_message() ); ?>
                </div>
            </div>
            <?php
            return;
        }

        $tier         = $usage['tier'] ?? 'free';
        $daily_budget = (float) ( $usage['daily_budget_usd'] ?? 0 );
        $today_spend  = (float) ( $usage['today_spend_usd'] ?? 0 );
        $total_cost   = (float) ( $usage['total_cost_usd'] ?? 0 );
        $daily        = $usage['daily'] ?? [];
        $mode         = $usage['budget_mode'] ?? 'normal';
        $pct          = $daily_budget > 0 ? min( 100, round( $today_spend / $daily_budget * 100 ) ) : 0;

        $bar_color = $pct >= 100 ? '#ef4444' : ( $pct >= 80 ? '#f59e0b' : '#10b981' );
        $pct_color = $pct >= 100 ? '#ef4444' : ( $pct >= 80 ? '#f59e0b' : '#10b981' );

        $mode_class = in_array( $mode, [ 'normal', 'degraded', 'blocked' ], true ) ? $mode : 'normal';
        $mode_labels = [
            'normal'   => 'Normal',
            'degraded' => 'Degraded (Haiku-only)',
            'blocked'  => 'Blocked (cache-only)',
        ];
        $mode_label = $mode_labels[ $mode ] ?? esc_html( $mode );
        ?>

        <div class="helix-usage-page">

            <!-- Page header -->
            <div class="helix-usage-header">
                <div>
                    <h1>
                        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" aria-hidden="true"><path stroke="#6366f1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Helix Usage &amp; Costs
                        <span class="helix-badge">Helix AI</span>
                    </h1>
                    <p class="helix-usage-subtitle" style="margin-top:6px;">Real-time AI usage and spend for the last 30 days &mdash; costs in USD</p>
                </div>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=helix-dashboard' ) ); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:6px;font-size:13px;font-weight:500;color:#6b7280;border:1px solid #e5e7eb;text-decoration:none;background:#fff;">
                    &larr; Dashboard
                </a>
            </div>

            <!-- Metric cards -->
            <div class="helix-usage-cards">
                <div class="helix-usage-card tier">
                    <div class="helix-usage-card-label">Plan Tier</div>
                    <div class="helix-usage-card-value"><?php echo esc_html( $tier ); ?></div>
                    <div class="helix-usage-card-sub">Current subscription</div>
                </div>
                <div class="helix-usage-card spend">
                    <div class="helix-usage-card-label">Today&rsquo;s Spend</div>
                    <div class="helix-usage-card-value">$<?php echo number_format( $today_spend, 4 ); ?></div>
                    <div class="helix-usage-card-sub">of $<?php echo number_format( $daily_budget, 2 ); ?> daily budget</div>
                </div>
                <div class="helix-usage-card total">
                    <div class="helix-usage-card-label">30-Day Total</div>
                    <div class="helix-usage-card-value">$<?php echo number_format( $total_cost, 2 ); ?></div>
                    <div class="helix-usage-card-sub">Rolling 30 days</div>
                </div>
                <div class="helix-usage-card status">
                    <div class="helix-usage-card-label">API Status</div>
                    <div class="helix-usage-card-value" style="margin-top:4px;">
                        <span class="helix-status-badge <?php echo esc_attr( $mode_class ); ?>">
                            <span class="helix-status-dot"></span>
                            <?php echo esc_html( $mode_label ); ?>
                        </span>
                    </div>
                    <div class="helix-usage-card-sub" style="margin-top:10px;">Current budget mode</div>
                </div>
            </div>

            <!-- Today's budget progress -->
            <div class="helix-usage-section">
                <div class="helix-usage-section-head">
                    <h2>Today&rsquo;s Budget Usage</h2>
                    <span style="font-size:12px;color:#6b7280;">$<?php echo number_format($today_spend,4); ?> of $<?php echo number_format($daily_budget,2); ?></span>
                </div>
                <div class="helix-usage-section-body">
                    <div class="helix-budget-wrap">
                        <div class="helix-budget-track">
                            <div class="helix-budget-labels">
                                <span>$0</span>
                                <span>$<?php echo number_format($daily_budget,2); ?></span>
                            </div>
                            <div class="helix-budget-bar-bg">
                                <div class="helix-budget-bar-fill" style="width:<?php echo (int) $pct; ?>%;background:<?php echo esc_attr($bar_color); ?>;"></div>
                            </div>
                            <?php if ( $pct >= 80 ) : ?>
                            <p style="font-size:12px;color:<?php echo esc_attr($pct >= 100 ? '#ef4444' : '#f59e0b'); ?>;margin:8px 0 0;font-weight:500;">
                                <?php echo $pct >= 100 ? 'Daily budget exhausted — AI responses may be limited.' : 'Approaching daily budget limit.'; ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <div class="helix-budget-pct" style="color:<?php echo esc_attr($pct_color); ?>;">
                            <?php echo (int) $pct; ?>%
                        </div>
                    </div>
                </div>
            </div>

            <!-- Daily breakdown table -->
            <div class="helix-usage-section">
                <div class="helix-usage-section-head">
                    <h2>Daily Breakdown &mdash; Last 30 Days</h2>
                    <span style="font-size:12px;color:#6b7280;"><?php echo count( $daily ); ?> days</span>
                </div>
                <div class="helix-usage-section-body" style="padding:0;">
                    <div class="helix-usage-table-wrap">
                        <table class="helix-usage-table">
                            <thead>
                                <tr>
                                    <th>Day</th>
                                    <th>API Calls</th>
                                    <th>Tokens In</th>
                                    <th>Tokens Out</th>
                                    <th>Cost (USD)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ( empty( $daily ) ) : ?>
                                    <tr>
                                        <td colspan="5" class="helix-usage-table-empty">No usage data yet for the last 30 days.</td>
                                    </tr>
                                <?php else : ?>
                                    <?php foreach ( array_reverse( $daily ) as $d ) :
                                        $cost = (float) $d['cost_usd'];
                                        $cost_color = $cost > 1 ? '#ef4444' : ( $cost > 0.5 ? '#f59e0b' : '#374151' );
                                    ?>
                                        <tr>
                                            <td class="date-cell"><?php echo esc_html( $d['day'] ); ?></td>
                                            <td><?php echo number_format( (int) $d['calls'] ); ?></td>
                                            <td><?php echo number_format( (int) $d['tokens_in'] ); ?></td>
                                            <td><?php echo number_format( (int) $d['tokens_out'] ); ?></td>
                                            <td class="cost-cell" style="color:<?php echo esc_attr($cost_color); ?>;">$<?php echo number_format( $cost, 4 ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div><!-- .helix-usage-page -->
        <?php
    }
}
