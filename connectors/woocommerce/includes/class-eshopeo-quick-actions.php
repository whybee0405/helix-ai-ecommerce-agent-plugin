<?php
defined( 'ABSPATH' ) || exit;

class Eshopeo_Quick_Actions {

    /* ── Registry ─────────────────────────────────────────────────────── */

    public static function get_actions(): array {
        return [
            // Database
            [
                'id'          => 'clear_transients',
                'label'       => 'Clear Expired Transients',
                'description' => 'Removes expired transients from the options table.',
                'category'    => 'database',
                'destructive' => true,
                'reversible'  => false,
                'high_risk'   => false,
                'icon'        => 'dashicons-trash',
            ],
            [
                'id'          => 'optimize_tables',
                'label'       => 'Optimize Database Tables',
                'description' => 'Runs OPTIMIZE TABLE on all WordPress database tables to reclaim overhead space.',
                'category'    => 'database',
                'destructive' => false,
                'reversible'  => false,
                'high_risk'   => false,
                'icon'        => 'dashicons-database',
            ],
            [
                'id'          => 'delete_revisions',
                'label'       => 'Delete Old Revisions',
                'description' => 'Deletes post revisions beyond the most recent 5 per post.',
                'category'    => 'database',
                'destructive' => true,
                'reversible'  => true,
                'high_risk'   => false,
                'icon'        => 'dashicons-backup',
            ],
            [
                'id'          => 'delete_orphaned_meta',
                'label'       => 'Delete Orphaned Post Meta',
                'description' => 'Removes post meta rows whose parent post no longer exists.',
                'category'    => 'database',
                'destructive' => true,
                'reversible'  => true,
                'high_risk'   => false,
                'icon'        => 'dashicons-editor-unlink',
            ],
            [
                'id'          => 'delete_spam_trash',
                'label'       => 'Delete Spam & Trash',
                'description' => 'Permanently deletes posts and comments with spam or trash status. Not reversible.',
                'category'    => 'database',
                'destructive' => true,
                'reversible'  => false,
                'high_risk'   => true,
                'icon'        => 'dashicons-warning',
            ],
            [
                'id'          => 'fix_autoload',
                'label'       => 'Fix Autoloaded Options',
                'description' => 'Disables autoload for options larger than 10 KB that are currently set to autoload.',
                'category'    => 'database',
                'destructive' => false,
                'reversible'  => true,
                'high_risk'   => false,
                'icon'        => 'dashicons-performance',
            ],
            // Performance
            [
                'id'          => 'set_heartbeat',
                'label'       => 'Set Heartbeat Interval',
                'description' => 'Sets the WordPress heartbeat interval to 60 seconds to reduce server load.',
                'category'    => 'performance',
                'destructive' => false,
                'reversible'  => true,
                'high_risk'   => false,
                'icon'        => 'dashicons-heart',
            ],
            [
                'id'          => 'flush_rewrite_rules',
                'label'       => 'Flush Rewrite Rules',
                'description' => 'Regenerates WordPress rewrite rules (equivalent to saving Permalinks).',
                'category'    => 'performance',
                'destructive' => false,
                'reversible'  => false,
                'high_risk'   => false,
                'icon'        => 'dashicons-randomize',
            ],
            [
                'id'          => 'flush_object_cache',
                'label'       => 'Flush Object Cache',
                'description' => 'Clears the WordPress object cache (memcached / Redis / APCu).',
                'category'    => 'performance',
                'destructive' => false,
                'reversible'  => false,
                'high_risk'   => false,
                'icon'        => 'dashicons-update',
            ],
            [
                'id'          => 'disable_heartbeat_frontend',
                'label'       => 'Disable Heartbeat on Frontend',
                'description' => 'Stops the heartbeat API from firing on non-editor frontend pages.',
                'category'    => 'performance',
                'destructive' => false,
                'reversible'  => true,
                'high_risk'   => false,
                'icon'        => 'dashicons-minus',
            ],
            // Media
            [
                'id'          => 'find_orphaned_media',
                'label'       => 'Find Orphaned Media',
                'description' => 'Lists attachments not referenced in any post content or meta (dry-run only — deletion requires AI confirmation).',
                'category'    => 'media',
                'destructive' => false,
                'reversible'  => false,
                'high_risk'   => false,
                'icon'        => 'dashicons-images-alt2',
            ],
            [
                'id'          => 'find_missing_alt',
                'label'       => 'Find Missing Alt Text',
                'description' => 'Finds image attachments with no alt text set.',
                'category'    => 'media',
                'destructive' => false,
                'reversible'  => false,
                'high_risk'   => false,
                'icon'        => 'dashicons-format-image',
            ],
            [
                'id'          => 'clean_image_sizes',
                'label'       => 'Audit Registered Image Sizes',
                'description' => 'Compares registered image sizes against files in uploads and flags unregistered variants.',
                'category'    => 'media',
                'destructive' => false,
                'reversible'  => false,
                'high_risk'   => false,
                'icon'        => 'dashicons-editor-expand',
            ],
            // Security
            [
                'id'          => 'audit_admin_users',
                'label'       => 'Audit Admin Users',
                'description' => 'Lists all users with the administrator role.',
                'category'    => 'security',
                'destructive' => false,
                'reversible'  => false,
                'high_risk'   => false,
                'icon'        => 'dashicons-admin-users',
            ],
            [
                'id'          => 'check_username_admin',
                'label'       => 'Check "admin" Username',
                'description' => 'Checks if a user with the login name "admin" exists — a common attack vector.',
                'category'    => 'security',
                'destructive' => false,
                'reversible'  => false,
                'high_risk'   => false,
                'icon'        => 'dashicons-lock',
            ],
            [
                'id'          => 'list_inactive_plugins',
                'label'       => 'List Inactive Plugins',
                'description' => 'Lists all installed but not activated plugins that could pose a security risk.',
                'category'    => 'security',
                'destructive' => false,
                'reversible'  => false,
                'high_risk'   => false,
                'icon'        => 'dashicons-plugins-checked',
            ],
            [
                'id'          => 'check_file_permissions',
                'label'       => 'Check File Permissions',
                'description' => 'Verifies wp-config.php and uploads directory have recommended permissions.',
                'category'    => 'security',
                'destructive' => false,
                'reversible'  => false,
                'high_risk'   => false,
                'icon'        => 'dashicons-shield',
            ],
            // Content
            [
                'id'          => 'find_no_meta',
                'label'       => 'Find Posts Missing SEO Meta',
                'description' => 'Lists published posts and pages that have no SEO meta description.',
                'category'    => 'content',
                'destructive' => false,
                'reversible'  => false,
                'high_risk'   => false,
                'icon'        => 'dashicons-search',
            ],
            [
                'id'          => 'find_thin_content',
                'label'       => 'Find Thin Content',
                'description' => 'Lists published posts with fewer than 200 words of body text.',
                'category'    => 'content',
                'destructive' => false,
                'reversible'  => false,
                'high_risk'   => false,
                'icon'        => 'dashicons-text',
            ],
            [
                'id'          => 'find_draft_old',
                'label'       => 'Find Old Drafts',
                'description' => 'Lists draft posts that have not been updated in over 90 days.',
                'category'    => 'content',
                'destructive' => false,
                'reversible'  => false,
                'high_risk'   => false,
                'icon'        => 'dashicons-clock',
            ],
        ];
    }

    /* ── Hooks ─────────────────────────────────────────────────────────── */

    public static function init(): void {
        $ids = wp_list_pluck( self::get_actions(), 'id' );
        foreach ( $ids as $id ) {
            add_action( 'wp_ajax_eshopeo_action_' . $id, [ __CLASS__, 'ajax_dispatch' ] );
        }
        add_action( 'wp_ajax_eshopeo_wp_agent_actions', [ __CLASS__, 'ajax_list_actions' ] );
    }

    /* ── Dispatcher ─────────────────────────────────────────────────────── */

    public static function ajax_dispatch(): void {
        check_ajax_referer( 'eshopeo_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        // Extract action id from the AJAX action name.
        $hook      = isset( $_POST['action'] ) ? sanitize_key( $_POST['action'] ) : '';
        $action_id = str_replace( 'eshopeo_action_', '', $hook );
        $dry_run   = filter_var( $_POST['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN );

        $method = 'action_' . $action_id;
        if ( ! method_exists( __CLASS__, $method ) ) {
            wp_send_json_error( 'Unknown action: ' . esc_html( $action_id ), 400 );
        }

        $result = self::$method( $dry_run );
        wp_send_json_success( $result );
    }

    public static function ajax_list_actions(): void {
        check_ajax_referer( 'eshopeo_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        wp_send_json_success( self::get_actions() );
    }

    /* ══════════════════════════════════════════════════════════════════════
     *  DATABASE ACTIONS
     * ══════════════════════════════════════════════════════════════════════ */

    public static function action_clear_transients( bool $dry_run ): array {
        global $wpdb;

        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE '\_transient\_%'
               AND option_name NOT LIKE '\_transient\_timeout\_%'
               AND CAST(
                   (SELECT option_value FROM {$wpdb->options} o2
                    WHERE o2.option_name = CONCAT('_transient_timeout_', SUBSTRING(option_name, 13))
                   ) AS UNSIGNED
               ) < UNIX_TIMESTAMP()"
        );

        if ( $dry_run ) {
            return [
                'dry_run'  => true,
                'count'    => $count,
                'preview'  => "Approximately {$count} expired transient(s) will be deleted.",
            ];
        }

        // Simpler production delete: remove all _transient_ rows whose timeout has passed.
        $wpdb->query(
            "DELETE o FROM {$wpdb->options} o
             INNER JOIN {$wpdb->options} o2
               ON o2.option_name = CONCAT('_transient_timeout_', SUBSTRING(o.option_name, 13))
              AND CAST(o2.option_value AS UNSIGNED) < UNIX_TIMESTAMP()
             WHERE o.option_name LIKE '\_transient\_%'
               AND o.option_name NOT LIKE '\_transient\_timeout\_%'"
        );
        $affected = (int) $wpdb->rows_affected;

        // Also delete orphaned timeout keys.
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '\_transient\_timeout\_%'
               AND CAST(option_value AS UNSIGNED) < UNIX_TIMESTAMP()"
        );

        Eshopeo_Action_Log::record( [
            'action'   => 'clear_transients',
            'dry_run'  => false,
            'affected' => $affected,
        ] );

        return [
            'dry_run'  => false,
            'affected' => $affected,
        ];
    }

    public static function action_optimize_tables( bool $dry_run ): array {
        global $wpdb;

        $tables = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
        $wp_tables = [];
        foreach ( $tables as $t ) {
            if ( str_starts_with( $t['Name'], $wpdb->prefix ) ) {
                $wp_tables[] = [
                    'name'          => $t['Name'],
                    'data_free_kb'  => round( (int) $t['Data_free'] / 1024, 1 ),
                ];
            }
        }
        $total_overhead = array_sum( array_column( $wp_tables, 'data_free_kb' ) );

        if ( $dry_run ) {
            return [
                'dry_run'           => true,
                'tables'            => $wp_tables,
                'total_overhead_kb' => $total_overhead,
                'preview'           => count( $wp_tables ) . ' tables, ' . $total_overhead . ' KB overhead can be reclaimed.',
            ];
        }

        foreach ( $wp_tables as $t ) {
            $wpdb->query( 'OPTIMIZE TABLE `' . esc_sql( $t['name'] ) . '`' );
        }

        Eshopeo_Action_Log::record( [
            'action'      => 'optimize_tables',
            'dry_run'     => false,
            'table_count' => count( $wp_tables ),
        ] );

        return [
            'dry_run'     => false,
            'table_count' => count( $wp_tables ),
        ];
    }

    public static function action_delete_revisions( bool $dry_run ): array {
        global $wpdb;

        // Get all revision IDs beyond the latest 5 per parent post.
        $revisions = $wpdb->get_results(
            "SELECT p.ID, p.post_parent, p.post_content, p.post_title, p.post_date
             FROM {$wpdb->posts} p
             WHERE p.post_type = 'revision'
               AND p.ID NOT IN (
                   SELECT ID FROM (
                       SELECT ID
                       FROM {$wpdb->posts} inner_p
                       WHERE inner_p.post_type = 'revision'
                         AND inner_p.post_parent = p.post_parent
                       ORDER BY inner_p.post_date DESC
                       LIMIT 5
                   ) AS keep_ids
               )",
            ARRAY_A
        );

        $count = count( $revisions );

        if ( $dry_run ) {
            return [
                'dry_run' => true,
                'count'   => $count,
                'preview' => "{$count} old revision(s) beyond the latest 5 per post will be deleted.",
            ];
        }

        // Snapshot revision data for rollback.
        $snap_id = null;
        if ( $count > 0 ) {
            $snap_id = Eshopeo_Snapshot::save( 'delete_revisions', [
                'action'    => 'delete_revisions',
                'revisions' => $revisions,
            ] );
        }

        $ids = array_column( $revisions, 'ID' );
        foreach ( $ids as $revision_id ) {
            wp_delete_post_revision( (int) $revision_id );
        }

        Eshopeo_Action_Log::record( [
            'action'      => 'delete_revisions',
            'dry_run'     => false,
            'affected'    => $count,
            'snapshot_id' => $snap_id,
        ] );

        return [
            'dry_run'     => false,
            'affected'    => $count,
            'snapshot_id' => $snap_id,
        ];
    }

    public static function action_delete_orphaned_meta( bool $dry_run ): array {
        global $wpdb;

        $orphans = $wpdb->get_results(
            "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value
             FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.ID IS NULL
             LIMIT 500",
            ARRAY_A
        );

        $count  = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.ID IS NULL"
        );

        if ( $dry_run ) {
            $sample = array_slice( $orphans, 0, 10 );
            return [
                'dry_run' => true,
                'count'   => $count,
                'sample'  => $sample,
                'preview' => "{$count} orphaned post meta row(s) found.",
            ];
        }

        // Snapshot for rollback.
        $snap_id = null;
        if ( ! empty( $orphans ) ) {
            $snap_id = Eshopeo_Snapshot::save( 'delete_orphaned_meta', [
                'action' => 'delete_orphaned_meta',
                'rows'   => $orphans,
            ] );
        }

        $wpdb->query(
            "DELETE pm FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.ID IS NULL"
        );
        $affected = (int) $wpdb->rows_affected;

        Eshopeo_Action_Log::record( [
            'action'      => 'delete_orphaned_meta',
            'dry_run'     => false,
            'affected'    => $affected,
            'snapshot_id' => $snap_id,
        ] );

        return [
            'dry_run'     => false,
            'affected'    => $affected,
            'snapshot_id' => $snap_id,
        ];
    }

    public static function action_delete_spam_trash( bool $dry_run ): array {
        global $wpdb;

        $trash_posts = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"
        );
        $spam_comments = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
        );
        $trash_comments = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'"
        );

        if ( $dry_run ) {
            return [
                'dry_run'        => true,
                'trash_posts'    => $trash_posts,
                'spam_comments'  => $spam_comments,
                'trash_comments' => $trash_comments,
                'preview'        => "{$trash_posts} trashed post(s), {$spam_comments} spam comment(s), and {$trash_comments} trashed comment(s) will be permanently deleted. This is NOT reversible.",
            ];
        }

        // Delete trashed posts.
        $post_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash'"
        );
        foreach ( $post_ids as $post_id ) {
            wp_delete_post( (int) $post_id, true );
        }

        // Delete spam + trash comments.
        $comment_ids = $wpdb->get_col(
            "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash')"
        );
        foreach ( $comment_ids as $cid ) {
            wp_delete_comment( (int) $cid, true );
        }

        $affected = count( $post_ids ) + count( $comment_ids );

        Eshopeo_Action_Log::record( [
            'action'   => 'delete_spam_trash',
            'dry_run'  => false,
            'affected' => $affected,
        ] );

        return [
            'dry_run'  => false,
            'affected' => $affected,
        ];
    }

    public static function action_fix_autoload( bool $dry_run ): array {
        global $wpdb;

        $offenders = $wpdb->get_results(
            "SELECT option_name, LENGTH(option_value) AS size_bytes
             FROM {$wpdb->options}
             WHERE autoload = 'yes'
               AND LENGTH(option_value) > 10000
             ORDER BY size_bytes DESC",
            ARRAY_A
        );

        if ( $dry_run ) {
            $formatted = array_map( function ( $r ) {
                $r['size_kb'] = round( (int) $r['size_bytes'] / 1024, 1 );
                return $r;
            }, $offenders );
            return [
                'dry_run'  => true,
                'count'    => count( $offenders ),
                'offenders' => $formatted,
                'preview'  => count( $offenders ) . ' autoloaded option(s) larger than 10 KB found.',
            ];
        }

        if ( empty( $offenders ) ) {
            return [ 'dry_run' => false, 'affected' => 0 ];
        }

        // Snapshot old autoload values.
        $snap_data = [];
        foreach ( $offenders as $row ) {
            $snap_data[] = [
                'option_name' => $row['option_name'],
                'autoload'    => 'yes',
            ];
        }
        $snap_id = Eshopeo_Snapshot::save( 'fix_autoload', [
            'action' => 'fix_autoload',
            'rows'   => $snap_data,
        ] );

        foreach ( $offenders as $row ) {
            $wpdb->update(
                $wpdb->options,
                [ 'autoload' => 'no' ],
                [ 'option_name' => $row['option_name'] ]
            );
        }

        Eshopeo_Action_Log::record( [
            'action'      => 'fix_autoload',
            'dry_run'     => false,
            'affected'    => count( $offenders ),
            'snapshot_id' => $snap_id,
        ] );

        return [
            'dry_run'     => false,
            'affected'    => count( $offenders ),
            'snapshot_id' => $snap_id,
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
     *  PERFORMANCE ACTIONS
     * ══════════════════════════════════════════════════════════════════════ */

    public static function action_set_heartbeat( bool $dry_run ): array {
        $current = get_option( 'eshopeo_heartbeat_interval', 'default' );

        if ( $dry_run ) {
            return [
                'dry_run'  => true,
                'current'  => $current,
                'preview'  => "Current heartbeat interval: {$current}. Will be set to 60 seconds.",
            ];
        }

        $snap_id = Eshopeo_Snapshot::save( 'set_heartbeat', [
            'action'   => 'set_heartbeat',
            'old_value' => $current,
        ] );

        update_option( 'eshopeo_heartbeat_interval', 60, false );

        Eshopeo_Action_Log::record( [
            'action'      => 'set_heartbeat',
            'dry_run'     => false,
            'old_value'   => $current,
            'new_value'   => 60,
            'snapshot_id' => $snap_id,
        ] );

        return [
            'dry_run'     => false,
            'old_value'   => $current,
            'new_value'   => 60,
            'snapshot_id' => $snap_id,
        ];
    }

    public static function action_flush_rewrite_rules( bool $dry_run ): array {
        if ( $dry_run ) {
            return [
                'dry_run' => true,
                'preview' => 'WordPress rewrite rules will be regenerated. Safe operation.',
            ];
        }

        flush_rewrite_rules();

        Eshopeo_Action_Log::record( [
            'action'  => 'flush_rewrite_rules',
            'dry_run' => false,
        ] );

        return [ 'dry_run' => false, 'success' => true ];
    }

    public static function action_flush_object_cache( bool $dry_run ): array {
        if ( $dry_run ) {
            return [
                'dry_run' => true,
                'preview' => 'Object cache will be flushed. Safe — cache will rebuild on next requests.',
            ];
        }

        wp_cache_flush();

        Eshopeo_Action_Log::record( [
            'action'  => 'flush_object_cache',
            'dry_run' => false,
        ] );

        return [ 'dry_run' => false, 'success' => true ];
    }

    public static function action_disable_heartbeat_frontend( bool $dry_run ): array {
        $current = get_option( 'eshopeo_disable_heartbeat_frontend', 0 );

        if ( $dry_run ) {
            return [
                'dry_run'  => true,
                'current'  => (bool) $current,
                'preview'  => $current ? 'Heartbeat is already disabled on the frontend.' : 'Heartbeat will be disabled on frontend (non-editor) pages.',
            ];
        }

        $snap_id = Eshopeo_Snapshot::save( 'disable_heartbeat_frontend', [
            'action'    => 'disable_heartbeat_frontend',
            'old_value' => $current,
        ] );

        update_option( 'eshopeo_disable_heartbeat_frontend', 1, false );

        Eshopeo_Action_Log::record( [
            'action'      => 'disable_heartbeat_frontend',
            'dry_run'     => false,
            'snapshot_id' => $snap_id,
        ] );

        return [
            'dry_run'     => false,
            'success'     => true,
            'snapshot_id' => $snap_id,
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
     *  MEDIA ACTIONS (read-only / dry-run only)
     * ══════════════════════════════════════════════════════════════════════ */

    public static function action_find_orphaned_media( bool $dry_run ): array {
        global $wpdb;

        $attachments = $wpdb->get_results(
            "SELECT ID, post_title, guid
             FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_status = 'inherit'
               AND ID NOT IN (
                   SELECT DISTINCT post_parent FROM {$wpdb->posts}
                   WHERE post_parent > 0
               )
               AND ID NOT IN (
                   SELECT DISTINCT CAST(meta_value AS UNSIGNED) FROM {$wpdb->postmeta}
                   WHERE meta_key IN ('_thumbnail_id','_product_image_gallery')
                     AND meta_value REGEXP '^[0-9]+$'
               )",
            ARRAY_A
        );

        return [
            'dry_run'     => true,
            'count'       => count( $attachments ),
            'attachments' => array_slice( $attachments, 0, 50 ),
            'preview'     => count( $attachments ) . ' potentially orphaned attachment(s) found. Deletion requires AI confirmation.',
        ];
    }

    public static function action_find_missing_alt( bool $dry_run ): array {
        global $wpdb;

        $images = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.guid
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm
               ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_image_alt'
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type LIKE 'image/%'
               AND (pm.meta_value IS NULL OR pm.meta_value = '')
             LIMIT 200",
            ARRAY_A
        );

        return [
            'dry_run' => true,
            'count'   => count( $images ),
            'images'  => $images,
            'preview' => count( $images ) . ' image(s) with missing alt text.',
        ];
    }

    public static function action_clean_image_sizes( bool $dry_run ): array {
        global $_wp_additional_image_sizes;

        $registered = array_merge(
            [ 'thumbnail', 'medium', 'medium_large', 'large', 'full' ],
            array_keys( $_wp_additional_image_sizes ?? [] )
        );

        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'];

        // Sample unregistered suffixes by scanning a subset of files.
        $unregistered = [];
        if ( is_dir( $base_dir ) ) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            $checked = 0;
            foreach ( $iterator as $file ) {
                if ( $checked > 500 ) {
                    break;
                }
                $filename = $file->getFilename();
                if ( preg_match( '/-(\d+x\d+)\.[a-z]+$/i', $filename, $m ) ) {
                    $suffix = $m[1];
                    // Map suffix to a registered size.
                    $found = false;
                    foreach ( $registered as $size_name ) {
                        $w = (int) get_option( $size_name . '_size_w', 0 );
                        $h = (int) get_option( $size_name . '_size_h', 0 );
                        if ( $w && $h && $suffix === "{$w}x{$h}" ) {
                            $found = true;
                            break;
                        }
                        if ( isset( $_wp_additional_image_sizes[ $size_name ] ) ) {
                            $aw = $_wp_additional_image_sizes[ $size_name ]['width'];
                            $ah = $_wp_additional_image_sizes[ $size_name ]['height'];
                            if ( $suffix === "{$aw}x{$ah}" ) {
                                $found = true;
                                break;
                            }
                        }
                    }
                    if ( ! $found ) {
                        $unregistered[ $suffix ] = ( $unregistered[ $suffix ] ?? 0 ) + 1;
                    }
                }
                $checked++;
            }
        }

        return [
            'dry_run'       => true,
            'registered'    => $registered,
            'unregistered'  => $unregistered,
            'preview'       => count( $unregistered ) . ' unregistered image size suffix(es) detected in uploads.',
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
     *  SECURITY ACTIONS (read-only)
     * ══════════════════════════════════════════════════════════════════════ */

    public static function action_audit_admin_users( bool $dry_run ): array {
        $admins = get_users( [ 'role' => 'administrator', 'fields' => [ 'ID', 'user_login', 'user_email', 'user_registered' ] ] );
        $list   = [];
        foreach ( $admins as $u ) {
            $list[] = [
                'id'         => $u->ID,
                'login'      => $u->user_login,
                'email'      => $u->user_email,
                'registered' => $u->user_registered,
            ];
        }
        return [
            'dry_run' => true,
            'admins'  => $list,
            'count'   => count( $list ),
            'preview' => count( $list ) . ' administrator account(s) found.',
        ];
    }

    public static function action_check_username_admin( bool $dry_run ): array {
        $user   = get_user_by( 'login', 'admin' );
        $exists = $user instanceof WP_User;
        return [
            'dry_run'  => true,
            'exists'   => $exists,
            'user_id'  => $exists ? $user->ID : null,
            'email'    => $exists ? $user->user_email : null,
            'preview'  => $exists
                ? 'WARNING: A user with login "admin" exists (ID ' . $user->ID . '). Consider renaming it.'
                : 'Good — no user with login "admin" found.',
        ];
    }

    public static function action_list_inactive_plugins( bool $dry_run ): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all    = get_plugins();
        $active = (array) get_option( 'active_plugins', [] );
        $inactive = [];
        foreach ( $all as $file => $data ) {
            if ( ! in_array( $file, $active, true ) ) {
                $inactive[] = [
                    'file'    => $file,
                    'name'    => $data['Name'],
                    'version' => $data['Version'],
                    'author'  => $data['Author'],
                ];
            }
        }
        return [
            'dry_run'  => true,
            'plugins'  => $inactive,
            'count'    => count( $inactive ),
            'preview'  => count( $inactive ) . ' inactive plugin(s) found.',
        ];
    }

    public static function action_check_file_permissions( bool $dry_run ): array {
        $checks = [];

        $wp_config = ABSPATH . 'wp-config.php';
        if ( file_exists( $wp_config ) ) {
            $perms = substr( sprintf( '%o', fileperms( $wp_config ) ), -3 );
            $ok    = in_array( $perms, [ '600', '640', '644' ], true );
            $checks[] = [
                'file'        => 'wp-config.php',
                'permissions' => $perms,
                'recommended' => '600 or 640',
                'ok'          => $ok,
                'note'        => $ok ? 'Good' : 'WARNING: permissions are too open.',
            ];
        }

        $upload_dir = wp_upload_dir();
        $uploads    = $upload_dir['basedir'];
        if ( is_dir( $uploads ) ) {
            $perms = substr( sprintf( '%o', fileperms( $uploads ) ), -3 );
            $ok    = in_array( $perms, [ '755', '750', '700' ], true );
            $checks[] = [
                'file'        => 'uploads dir',
                'permissions' => $perms,
                'recommended' => '755',
                'ok'          => $ok,
                'note'        => $ok ? 'Good' : 'WARNING: uploads directory permissions may be too open.',
            ];
        }

        $all_ok = array_reduce( $checks, fn( $carry, $item ) => $carry && $item['ok'], true );

        return [
            'dry_run'  => true,
            'checks'   => $checks,
            'all_ok'   => $all_ok,
            'preview'  => $all_ok ? 'All file permissions look good.' : 'One or more file permission issues detected.',
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
     *  CONTENT ACTIONS (read-only)
     * ══════════════════════════════════════════════════════════════════════ */

    public static function action_find_no_meta( bool $dry_run ): array {
        global $wpdb;

        $posts = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_type, p.post_date
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm
               ON pm.post_id = p.ID
              AND pm.meta_key IN ('_yoast_wpseo_metadesc','rank_math_description','_aioseo_description')
             WHERE p.post_status = 'publish'
               AND p.post_type IN ('post','page')
               AND (pm.meta_value IS NULL OR pm.meta_value = '')
             GROUP BY p.ID
             ORDER BY p.post_date DESC
             LIMIT 100",
            ARRAY_A
        );

        return [
            'dry_run' => true,
            'posts'   => $posts,
            'count'   => count( $posts ),
            'preview' => count( $posts ) . ' published post(s)/page(s) missing SEO meta description.',
        ];
    }

    public static function action_find_thin_content( bool $dry_run ): array {
        global $wpdb;

        $posts = $wpdb->get_results(
            "SELECT ID, post_title, post_type, post_date,
                    LENGTH(post_content) AS content_length
             FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type IN ('post','page')
               AND LENGTH(TRIM(post_content)) > 0
             ORDER BY content_length ASC
             LIMIT 200",
            ARRAY_A
        );

        $thin = [];
        foreach ( $posts as $p ) {
            $word_count = str_word_count( wp_strip_all_tags( $p['post_content'] ?? '' ) );
            // Re-fetch actual content since the SELECT returns length only.
            $content    = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d",
                $p['ID']
            ) );
            $word_count = str_word_count( wp_strip_all_tags( $content ) );
            if ( $word_count < 200 ) {
                $thin[] = [
                    'id'         => $p['ID'],
                    'title'      => $p['post_title'],
                    'type'       => $p['post_type'],
                    'date'       => $p['post_date'],
                    'word_count' => $word_count,
                ];
            }
        }

        return [
            'dry_run' => true,
            'posts'   => $thin,
            'count'   => count( $thin ),
            'preview' => count( $thin ) . ' published post(s)/page(s) with fewer than 200 words.',
        ];
    }

    public static function action_find_draft_old( bool $dry_run ): array {
        global $wpdb;

        $threshold = date( 'Y-m-d H:i:s', strtotime( '-90 days' ) );

        $drafts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title, post_type, post_modified
                 FROM {$wpdb->posts}
                 WHERE post_status = 'draft'
                   AND post_type IN ('post','page')
                   AND post_modified < %s
                 ORDER BY post_modified ASC
                 LIMIT 100",
                $threshold
            ),
            ARRAY_A
        );

        return [
            'dry_run' => true,
            'drafts'  => $drafts,
            'count'   => count( $drafts ),
            'preview' => count( $drafts ) . ' draft(s) not updated in over 90 days.',
        ];
    }
}
