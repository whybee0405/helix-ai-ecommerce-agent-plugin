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
        if ( ! $tenant_id || ! $admin_secret ) {
            echo '<div class="wrap"><h1>Helix Usage</h1><div class="notice notice-warning"><p>Save your Helix branding once to bootstrap admin credentials.</p></div></div>';
            return;
        }

        $client = new Helix_API_Client( get_option( 'helix_api_url', '' ), get_option( 'helix_public_key', '' ) );
        $usage  = $client->get_usage_summary( 30 );

        if ( is_wp_error( $usage ) ) {
            echo '<div class="wrap"><h1>Helix Usage</h1><div class="notice notice-error"><p>Could not load usage: ' . esc_html( $usage->get_error_message() ) . '</p></div></div>';
            return;
        }

        $tier         = $usage['tier'] ?? 'free';
        $daily_budget = (float) ( $usage['daily_budget_usd'] ?? 0 );
        $today_spend  = (float) ( $usage['today_spend_usd'] ?? 0 );
        $total_cost   = (float) ( $usage['total_cost_usd'] ?? 0 );
        $daily        = $usage['daily'] ?? [];
        $mode         = $usage['budget_mode'] ?? 'normal';
        $pct          = $daily_budget > 0 ? min( 100, round( $today_spend / $daily_budget * 100 ) ) : 0;

        $mode_label = [
            'normal'   => '<span style="color:#16a34a;">Normal</span>',
            'degraded' => '<span style="color:#d97706;">Degraded (Haiku-only)</span>',
            'blocked'  => '<span style="color:#dc2626;">Blocked (cache-only)</span>',
        ][ $mode ] ?? esc_html( $mode );
        ?>
        <div class="wrap">
            <h1>Helix Usage & Costs</h1>
            <p class="description">Real-time view of AI usage and spend for the last 30 days. Costs are billed in USD.</p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin:24px 0;">
                <div class="card" style="padding:16px;">
                    <h3 style="margin:0 0 4px;">Tier</h3>
                    <p style="font-size:24px;margin:0;text-transform:capitalize;"><strong><?php echo esc_html( $tier ); ?></strong></p>
                </div>
                <div class="card" style="padding:16px;">
                    <h3 style="margin:0 0 4px;">Today's spend</h3>
                    <p style="font-size:24px;margin:0;"><strong>$<?php echo number_format( $today_spend, 4 ); ?></strong></p>
                    <p style="margin:6px 0 0;font-size:12px;color:#666;">of $<?php echo number_format( $daily_budget, 2 ); ?> daily</p>
                </div>
                <div class="card" style="padding:16px;">
                    <h3 style="margin:0 0 4px;">Status</h3>
                    <p style="font-size:18px;margin:0;"><strong><?php echo $mode_label; ?></strong></p>
                </div>
                <div class="card" style="padding:16px;">
                    <h3 style="margin:0 0 4px;">30-day total</h3>
                    <p style="font-size:24px;margin:0;"><strong>$<?php echo number_format( $total_cost, 2 ); ?></strong></p>
                </div>
            </div>

            <h2>Today's budget usage</h2>
            <div style="background:#e5e7eb;border-radius:8px;height:24px;overflow:hidden;margin-bottom:24px;">
                <div style="background:<?php echo $pct >= 100 ? '#dc2626' : ( $pct >= 80 ? '#d97706' : '#16a34a' ); ?>;width:<?php echo (int) $pct; ?>%;height:100%;text-align:center;color:#fff;line-height:24px;font-weight:600;">
                    <?php echo (int) $pct; ?>%
                </div>
            </div>

            <h2>Daily breakdown (last 30 days)</h2>
            <table class="widefat striped" style="max-width:900px;">
                <thead>
                    <tr><th>Day</th><th>Calls</th><th>Tokens in</th><th>Tokens out</th><th>Cost (USD)</th></tr>
                </thead>
                <tbody>
                    <?php if ( empty( $daily ) ) : ?>
                        <tr><td colspan="5" style="text-align:center;">No usage yet.</td></tr>
                    <?php else : ?>
                        <?php foreach ( array_reverse( $daily ) as $d ) : ?>
                            <tr>
                                <td><?php echo esc_html( $d['day'] ); ?></td>
                                <td><?php echo (int) $d['calls']; ?></td>
                                <td><?php echo number_format( (int) $d['tokens_in'] ); ?></td>
                                <td><?php echo number_format( (int) $d['tokens_out'] ); ?></td>
                                <td>$<?php echo number_format( (float) $d['cost_usd'], 4 ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
