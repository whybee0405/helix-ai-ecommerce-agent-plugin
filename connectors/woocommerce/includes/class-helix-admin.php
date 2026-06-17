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

        /* Sync operational settings to backend if already connected */
        if ( get_option( 'helix_tenant_id' ) && get_option( 'helix_admin_secret' ) ) {
            $client = new Helix_API_Client(
                get_option( 'helix_api_url', '' ),
                get_option( 'helix_public_key', '' )
            );
            $client->update_branding( [
                'lead_webhook_url' => get_option( 'helix_lead_webhook_url', '' ) ?: null,
            ] );
        }

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
        <div class="wrap">
            <h1>Helix Connector</h1>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved and store connected.</p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['connect_error'] ) ) : ?>
                <div class="notice notice-error is-dismissible"><p>Connection failed: <?php echo esc_html( urldecode( $_GET['connect_error'] ) ); ?></p></div>
            <?php endif; ?>

            <h2>Connection</h2>
            <p>Status: <strong><?php echo $connected ? '&#x2713; Connected (tenant: ' . esc_html( $tenant_id ) . ')' : '&#x2717; Not connected'; ?></strong></p>
            <p>Last sync: <span id="helix-last-sync"><?php echo esc_html( $last_sync ); ?></span> &mdash; <span id="helix-sync-count"><?php echo esc_html( $sync_count ); ?></span> products</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'helix_save_settings' ); ?>
                <input type="hidden" name="action" value="helix_save_settings">
                <table class="form-table">
                    <tr><th>API URL</th><td><input type="url" name="helix_api_url" value="<?php echo esc_attr( get_option( 'helix_api_url' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th>Provision Key</th><td><input type="password" name="helix_provision_key" value="<?php echo esc_attr( get_option( 'helix_provision_key' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th>WC Consumer Key</th><td><input type="text" name="helix_consumer_key" value="<?php echo esc_attr( get_option( 'helix_consumer_key' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th>WC Consumer Secret</th><td><input type="password" name="helix_consumer_secret" value="" class="regular-text" placeholder="(unchanged)"></td></tr>
                    <tr><th colspan="2"><strong>Widget</strong></th></tr>
                    <tr>
                        <th>Auto-inject widget</th>
                        <td>
                            <label>
                                <input type="checkbox" name="helix_widget_enabled" value="1" <?php checked( get_option( 'helix_widget_enabled', 0 ), 1 ); ?>>
                                Automatically embed the Helix chat widget on all frontend pages
                            </label>
                        </td>
                    </tr>
                    <tr><th colspan="2"><strong>Search Bar Animations</strong></th></tr>
                    <tr>
                        <th>Card fly-in animation</th>
                        <td>
                            <label>
                                <input type="checkbox" name="helix_sb_animations" value="1" <?php checked( get_option( 'helix_sb_animations', 1 ), 1 ); ?>>
                                Animate product cards flying in from the search bar (rainbow sparkle effect)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Fly-to-cart animation</th>
                        <td>
                            <label>
                                <input type="checkbox" name="helix_sb_fly_to_cart" value="1" <?php checked( get_option( 'helix_sb_fly_to_cart', 1 ), 1 ); ?>>
                                Animate product thumbnail flying to the cart icon when adding to cart
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Product detail modal</th>
                        <td>
                            <label>
                                <input type="checkbox" name="helix_sb_card_modal" value="1" <?php checked( get_option( 'helix_sb_card_modal', 1 ), 1 ); ?>>
                                Open product details in a modal overlay instead of navigating to the product page
                            </label>
                        </td>
                    </tr>
                    <tr><th colspan="2"><strong>Lead Capture</strong></th></tr>
                    <tr>
                        <th>Lead webhook URL</th>
                        <td>
                            <input type="url" name="helix_lead_webhook_url" value="<?php echo esc_attr( get_option( 'helix_lead_webhook_url', '' ) ); ?>" class="regular-text" placeholder="https://hook.make.com/...">
                            <p class="description">POST new enquiries here (Zapier, Make.com, CRM). Leave blank to disable webhooks.</p>
                        </td>
                    </tr>
                    <tr><th colspan="2"><strong>WhatsApp Button</strong></th></tr>
                    <tr>
                        <th>Enable WhatsApp button</th>
                        <td>
                            <label>
                                <input type="checkbox" name="helix_wa_enabled" value="1" <?php checked( get_option( 'helix_wa_enabled', 0 ), 1 ); ?>>
                                Show a WhatsApp chat button inside the AI widget
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>WhatsApp number</th>
                        <td>
                            <input type="text" name="helix_wa_number" value="<?php echo esc_attr( get_option( 'helix_wa_number', '' ) ); ?>" class="regular-text" placeholder="e.g. 27831234567 (no + or spaces)">
                            <p class="description">International format without + sign or spaces.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>WhatsApp pre-filled message</th>
                        <td>
                            <textarea name="helix_wa_message" rows="3" class="large-text"><?php echo esc_textarea( get_option( 'helix_wa_message', "Hi! I'd like some skincare advice." ) ); ?></textarea>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save &amp; Connect' ); ?>
            </form>

            <?php if ( $connected ) : ?>
                <h2>Catalog Sync</h2>
                <p>
                    <button id="helix-sync-btn" class="button button-secondary">Sync Catalog Now</button>
                    <span id="helix-sync-spinner" class="spinner" style="float:none;margin:0 6px;vertical-align:middle;display:none;"></span>
                </p>
                <div id="helix-sync-result" style="display:none;margin-top:12px;"></div>

                <script>
                document.getElementById('helix-sync-btn').addEventListener('click', function () {
                    var btn     = this;
                    var spinner = document.getElementById('helix-sync-spinner');
                    var result  = document.getElementById('helix-sync-result');

                    btn.disabled     = true;
                    spinner.style.display = 'inline-block';
                    result.style.display  = 'none';
                    result.innerHTML      = '';

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
                        btn.disabled = false;
                        result.style.display = 'block';

                        if ( res.success ) {
                            var d   = res.data;
                            var html = '<div class="notice notice-success inline" style="margin:0;">'
                                + '<p><strong>Sync complete.</strong> '
                                + d.synced + ' product' + (d.synced !== 1 ? 's' : '') + ' synced';

                            if ( d.failed > 0 ) {
                                html += ', <strong style="color:#b32d2e;">' + d.failed + ' failed</strong>';
                            }

                            html += '.</p>';

                            if ( d.errors && d.errors.length > 0 ) {
                                html += '<details style="margin:4px 0 8px 0;"><summary style="cursor:pointer;">Show errors (' + d.errors.length + ')</summary>'
                                    + '<ul style="margin:8px 0 4px 16px;">';
                                d.errors.forEach(function (e) {
                                    html += '<li>' + e.replace(/</g, '&lt;') + '</li>';
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
                            result.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p><strong>Sync failed:</strong> ' + msg.replace(/</g, '&lt;') + '</p></div>';
                        }
                    })
                    .catch(function (err) {
                        spinner.style.display = 'none';
                        btn.disabled = false;
                        result.style.display = 'block';
                        result.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p><strong>Request failed:</strong> ' + err.message + '</p></div>';
                    });
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_leads_page(): void {
        $tenant_id = get_option( 'helix_tenant_id', '' );
        if ( ! $tenant_id ) {
            echo '<div class="wrap"><h1>Helix — Leads</h1><p>Connect your store first on the <a href="' . esc_url( admin_url( 'admin.php?page=helix-connector' ) ) . '">Helix settings</a> page.</p></div>';
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
        <div class="wrap">
            <h1>Helix — Enquiry Leads</h1>
            <p><?php echo esc_html( $total ); ?> total lead<?php echo $total !== 1 ? 's' : ''; ?></p>

            <?php if ( $error ) : ?>
                <div class="notice notice-error"><p>Could not fetch leads: <?php echo esc_html( $error ); ?></p></div>
            <?php elseif ( empty( $leads ) ) : ?>
                <p>No leads yet. Once customers submit an enquiry, they will appear here.</p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Vehicle (product ID)</th>
                            <th>Best time to call</th>
                            <th>Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $leads as $lead ) : ?>
                            <tr>
                                <td><?php echo esc_html( wp_date( 'd M Y H:i', strtotime( $lead['created_at'] ) ) ); ?></td>
                                <td><?php echo esc_html( $lead['name'] ?? '—' ); ?></td>
                                <td><?php echo esc_html( $lead['phone'] ?? '—' ); ?></td>
                                <td><?php echo esc_html( $lead['email'] ?? '—' ); ?></td>
                                <td><?php echo esc_html( $lead['product_platform_id'] ?? '—' ); ?></td>
                                <td><?php echo esc_html( $lead['preferred_contact_time'] ?? '—' ); ?></td>
                                <td><?php echo esc_html( $lead['source'] ?? '—' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ( $total_pages > 1 ) : ?>
                    <div class="tablenav bottom" style="margin-top:12px;">
                        <?php
                        echo paginate_links( [
                            'base'    => add_query_arg( 'paged', '%#%' ),
                            'format'  => '',
                            'current' => $page_num,
                            'total'   => $total_pages,
                        ] );
                        ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
