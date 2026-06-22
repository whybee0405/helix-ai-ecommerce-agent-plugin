<?php
defined( 'ABSPATH' ) || exit;

/**
 * Helix_Image_Optimiser — convert images to WebP via Imagick on upload.
 */
class Helix_Image_Optimiser {

    const WEBP_QUALITY = 82;

    /* ── Init ──────────────────────────────────────────────────────────────── */

    public static function init(): void {
        if ( self::imagick_available() ) {
            add_action( 'add_attachment', [ __CLASS__, 'on_add_attachment' ] );
        }

        add_action( 'wp_ajax_helix_image_scan',            [ __CLASS__, 'ajax_scan' ] );
        add_action( 'wp_ajax_helix_image_optimise_batch',  [ __CLASS__, 'ajax_optimise_batch' ] );
    }

    /* ── Imagick availability ──────────────────────────────────────────────── */

    public static function imagick_available(): bool {
        return extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
    }

    /* ── On attachment upload ─────────────────────────────────────────────── */

    public static function on_add_attachment( int $attachment_id ): void {
        $mime = get_post_mime_type( $attachment_id );
        if ( ! str_starts_with( $mime ?: '', 'image/' ) ) {
            return;
        }
        if ( $mime === 'image/webp' ) {
            return;
        }

        $filepath = get_attached_file( $attachment_id );
        if ( ! $filepath || ! file_exists( $filepath ) ) {
            return;
        }

        self::convert_to_webp( $filepath );
    }

    /* ── Convert file to WebP ─────────────────────────────────────────────── */

    private static function convert_to_webp( string $source_path ): bool {
        if ( ! self::imagick_available() ) {
            return false;
        }

        $webp_path = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $source_path );
        if ( $webp_path === $source_path ) {
            $webp_path = $source_path . '.webp';
        }

        if ( file_exists( $webp_path ) ) {
            return true;
        }

        try {
            $imagick = new \Imagick( $source_path );
            $imagick->setImageFormat( 'webp' );
            $imagick->setImageCompressionQuality( self::WEBP_QUALITY );
            $imagick->setOption( 'webp:method', '6' );
            $result = $imagick->writeImage( $webp_path );
            $imagick->clear();
            $imagick->destroy();
            return (bool) $result;
        } catch ( \Exception $e ) {
            return false;
        }
    }

    /* ── AJAX: scan ────────────────────────────────────────────────────────── */

    public static function ajax_scan(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;

        $images = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.guid, p.post_mime_type
             FROM {$wpdb->posts} p
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type IN ('image/jpeg','image/png','image/gif')
               AND p.post_status = 'inherit'
             ORDER BY p.ID DESC
             LIMIT 500",
            ARRAY_A
        );

        $missing_webp = [];
        foreach ( ( $images ?: [] ) as $img ) {
            $filepath = get_attached_file( (int) $img['ID'] );
            if ( ! $filepath ) {
                continue;
            }
            $webp_path = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $filepath );
            if ( ! file_exists( $webp_path ) ) {
                $size = file_exists( $filepath ) ? (int) filesize( $filepath ) : 0;
                $missing_webp[] = [
                    'id'          => (int) $img['ID'],
                    'title'       => $img['post_title'],
                    'mime_type'   => $img['post_mime_type'],
                    'size_kb'     => round( $size / 1024, 1 ),
                    'url'         => esc_url( $img['guid'] ),
                ];
            }
        }

        wp_send_json_success( [
            'imagick_available' => self::imagick_available(),
            'count'             => count( $missing_webp ),
            'images'            => $missing_webp,
        ] );
    }

    /* ── AJAX: optimise batch ──────────────────────────────────────────────── */

    public static function ajax_optimise_batch(): void {
        check_ajax_referer( 'helix_wp_agent', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        if ( ! self::imagick_available() ) {
            wp_send_json_error( 'Imagick is not available on this server', 500 );
        }

        // Accept JSON or form-encoded.
        $body = json_decode( file_get_contents( 'php://input' ), true );
        $attachment_ids = (array) ( $body['attachment_ids'] ?? ( isset( $_POST['attachment_ids'] ) ? (array) $_POST['attachment_ids'] : [] ) );

        if ( empty( $attachment_ids ) ) {
            wp_send_json_error( 'No attachment_ids provided', 400 );
        }

        $results = [];
        foreach ( array_slice( $attachment_ids, 0, 20 ) as $id ) {
            $id       = (int) $id;
            $filepath = get_attached_file( $id );
            if ( ! $filepath || ! file_exists( $filepath ) ) {
                $results[] = [ 'id' => $id, 'error' => 'File not found' ];
                continue;
            }

            $original_bytes = (int) filesize( $filepath );
            $webp_path      = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $filepath );
            $success        = self::convert_to_webp( $filepath );

            if ( ! $success || ! file_exists( $webp_path ) ) {
                $results[] = [ 'id' => $id, 'error' => 'Conversion failed' ];
                continue;
            }

            $webp_bytes = (int) filesize( $webp_path );
            $saved_pct  = $original_bytes > 0 ? round( ( 1 - $webp_bytes / $original_bytes ) * 100, 1 ) : 0;

            $results[] = [
                'id'          => $id,
                'original_kb' => round( $original_bytes / 1024, 1 ),
                'webp_kb'     => round( $webp_bytes / 1024, 1 ),
                'saved_pct'   => $saved_pct,
            ];
        }

        Helix_Action_Log::record( [
            'action'   => 'image_optimise_batch',
            'dry_run'  => false,
            'affected' => count( array_filter( $results, fn( $r ) => ! isset( $r['error'] ) ) ),
        ] );

        wp_send_json_success( [ 'results' => $results ] );
    }
}
