<?php
defined( 'ABSPATH' ) || exit;

class Helix_Admin {
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'add_settings_page' ] );
        add_action( 'admin_init', [ self::class, 'register_settings' ] );
        add_action( 'admin_post_helix_save_settings', [ self::class, 'save_settings' ] );
        add_action( 'admin_post_helix_run_sync', [ self::class, 'handle_sync' ] );
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
    }

    public static function register_settings(): void {
        register_setting( 'helix_settings', 'helix_api_url', [ 'sanitize_callback' => 'esc_url_raw' ] );
        register_setting( 'helix_settings', 'helix_provision_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'helix_settings', 'helix_consumer_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'helix_settings', 'helix_consumer_secret', [ 'sanitize_callback' => 'sanitize_text_field' ] );
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

        if ( ! get_option( 'helix_tenant_id' ) ) {
            $client = new Helix_API_Client( get_option( 'helix_api_url', '' ) );
            $result = $client->provision(
                get_bloginfo( 'name' ),
                site_url(),
                [
                    'consumer_key'    => get_option( 'helix_consumer_key' ),
                    'consumer_secret' => get_option( 'helix_consumer_secret' ),
                ]
            );
            if ( ! is_wp_error( $result ) ) {
                update_option( 'helix_tenant_id', $result['tenant_id'] );
                update_option( 'helix_public_key', $result['public_key'] );
                Helix_Webhooks::register_webhooks( get_option( 'helix_api_url' ), $result['tenant_id'] );
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=helix-connector&saved=1' ) );
        exit;
    }

    public static function handle_sync(): void {
        check_admin_referer( 'helix_run_sync' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized' );
        }
        $result = Helix_Sync::run_full_sync();
        $synced = $result['synced'] ?? 0;
        wp_safe_redirect( admin_url( "admin.php?page=helix-connector&synced={$synced}" ) );
        exit;
    }

    public static function render_settings_page(): void {
        $tenant_id  = get_option( 'helix_tenant_id', '' );
        $last_sync  = get_option( 'helix_last_sync', 'Never' );
        $sync_count = get_option( 'helix_synced_count', 0 );
        $connected  = ! empty( $tenant_id );
        ?>
        <div class="wrap">
            <h1>Helix Connector</h1>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success"><p>Settings saved.</p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['synced'] ) ) : ?>
                <div class="notice notice-success"><p>Sync complete. <?php echo esc_html( (int) $_GET['synced'] ); ?> products synced.</p></div>
            <?php endif; ?>

            <h2>Connection</h2>
            <p>Status: <strong><?php echo $connected ? '&#x2713; Connected (tenant: ' . esc_html( $tenant_id ) . ')' : '&#x2717; Not connected'; ?></strong></p>
            <p>Last sync: <?php echo esc_html( $last_sync ); ?> (<?php echo esc_html( $sync_count ); ?> products)</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'helix_save_settings' ); ?>
                <input type="hidden" name="action" value="helix_save_settings">
                <table class="form-table">
                    <tr><th>API URL</th><td><input type="url" name="helix_api_url" value="<?php echo esc_attr( get_option( 'helix_api_url' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th>Provision Key</th><td><input type="password" name="helix_provision_key" value="<?php echo esc_attr( get_option( 'helix_provision_key' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th>WC Consumer Key</th><td><input type="text" name="helix_consumer_key" value="<?php echo esc_attr( get_option( 'helix_consumer_key' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th>WC Consumer Secret</th><td><input type="password" name="helix_consumer_secret" value="" class="regular-text" placeholder="(unchanged)"></td></tr>
                </table>
                <?php submit_button( 'Save &amp; Connect' ); ?>
            </form>

            <?php if ( $connected ) : ?>
                <h2>Catalog Sync</h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'helix_run_sync' ); ?>
                    <input type="hidden" name="action" value="helix_run_sync">
                    <?php submit_button( 'Sync Catalog Now', 'secondary' ); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}
