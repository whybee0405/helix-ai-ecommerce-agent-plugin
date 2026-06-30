<?php
defined( 'ABSPATH' ) || exit;

/**
 * One-shot automotive attribute migration tool.
 * Reads existing WC products, infers automotive attributes from titles /
 * categories / descriptions, creates global WC attribute taxonomies if
 * missing, and writes values — skipping any attribute already populated.
 */
class Eshopeo_Migrator {

    /* ── Global attributes to create / populate ─────────────────────────── */

    private const ATTRS = [
        'make'             => 'Make',
        'model'            => 'Model',
        'year'             => 'Year',
        'condition'        => 'Condition',
        'mileage-km'       => 'Mileage (km)',
        'fuel-type'        => 'Fuel Type',
        'transmission'     => 'Transmission',
        'body-type'        => 'Body Type',
        'colour'           => 'Colour',
        'engine-cc'        => 'Engine (cc)',
        'doors'            => 'Doors',
        'safety-rating'    => 'Safety Rating',
        'ncap-stars'       => 'NCAP Stars',
        'finance-from-zar' => 'Finance From (ZAR/mo)',
        'certified-used'   => 'Certified Used',
    ];

    /* ── Known makes — longest / most-specific first to avoid false matches */

    private const MAKES = [
        'Land Rover', 'Range Rover', 'Mercedes-Benz', 'Alfa Romeo',
        'Aston Martin', 'Rolls-Royce', 'Great Wall', 'Plug-In',
        'Toyota', 'Volkswagen', 'Ford', 'Hyundai', 'Kia', 'BMW',
        'Mercedes', 'Audi', 'Nissan', 'Suzuki', 'Haval', 'Mahindra',
        'Isuzu', 'Renault', 'Peugeot', 'Opel', 'Vauxhall', 'Chevrolet',
        'Honda', 'Mazda', 'Mitsubishi', 'Subaru', 'Volvo', 'Jeep',
        'Lexus', 'Infiniti', 'Porsche', 'Lamborghini', 'Ferrari',
        'Chery', 'BAIC', 'GWM', 'MG', 'Geely', 'Fiat', 'Citroen',
        'Seat', 'Skoda', 'Dacia', 'VW',
    ];

    /* ── Make normalisation map ──────────────────────────────────────────── */

    private const MAKE_NORMALISE = [
        'VW'       => 'Volkswagen',
        'Mercedes' => 'Mercedes-Benz',
    ];

    /* ── Body-type keyword → enum value ─────────────────────────────────── */

    private const BODY_KEYWORDS = [
        'bakkie'    => 'bakkie',   'pickup'   => 'bakkie',
        'suv'       => 'suv',      'crossover'=> 'suv',   '4x4' => 'suv',
        'hatchback' => 'hatchback','hatch'    => 'hatchback',
        'sedan'     => 'sedan',    'saloon'   => 'sedan',
        'coupe'     => 'coupe',    'convertible'=>'convertible',
        'minivan'   => 'minivan',  'mpv'      => 'minivan',
        'wagon'     => 'wagon',    'estate'   => 'wagon',
        'ute'       => 'bakkie',
    ];

    /* ── Default doors by body type ─────────────────────────────────────── */

    private const DOORS_FOR_BODY = [
        'bakkie'      => '4',
        'coupe'       => '2',
        'convertible' => '2',
        'hatchback'   => '5',
        'suv'         => '5',
        'minivan'     => '5',
        'wagon'       => '5',
        'sedan'       => '4',
    ];

    /* ─────────────────────────────────────────────────────────────────────── */

    public static function init(): void {
        if ( Eshopeo_Pack::is_hidden('migrator') ) return;
        add_action( 'admin_menu', [ self::class, 'add_menu' ] );
        add_action( 'wp_ajax_eshopeo_run_migration', [ self::class, 'ajax_run' ] );
    }

    public static function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            'eShopeo — Data Migration',
            'eShopeo Migrate',
            'manage_woocommerce',
            'eshopeo-migrator',
            [ self::class, 'render_page' ]
        );
    }

    /* ── Ensure global WC attribute taxonomies exist ─────────────────────── */

    public static function ensure_attributes(): void {
        foreach ( self::ATTRS as $slug => $label ) {
            if ( wc_attribute_taxonomy_id_by_name( $slug ) ) {
                continue;
            }
            wc_create_attribute( [
                'name'         => $label,
                'slug'         => $slug,
                'type'         => 'select',
                'order_by'     => 'name',
                'has_archives' => false,
            ] );
            // Register taxonomy immediately so term operations work in the same request.
            $tax = 'pa_' . $slug;
            if ( ! taxonomy_exists( $tax ) ) {
                register_taxonomy( $tax, [ 'product' ], [
                    'hierarchical' => false,
                    'show_ui'      => false,
                    'query_var'    => true,
                    'rewrite'      => false,
                ] );
            }
        }
        // Flush WC attribute cache.
        delete_transient( 'wc_attribute_taxonomies' );
    }

    /* ── Infer attribute values from product data ─────────────────────────── */

    public static function infer( WC_Product $p ): array {
        $title = $p->get_name();
        $desc  = wp_strip_all_tags( $p->get_description() . ' ' . $p->get_short_description() );
        $full  = $title . ' ' . $desc;
        $price = (float) $p->get_price();
        $cats  = array_map(
            'strtolower',
            wp_get_post_terms( $p->get_id(), 'product_cat', [ 'fields' => 'names' ] ) ?: []
        );

        $out = [];

        /* Year ------------------------------------------------------------ */
        if ( preg_match( '/\b(19[5-9]\d|20[0-2]\d)\b/', $title, $m ) ) {
            $out['year'] = $m[1];
        }

        /* Make ------------------------------------------------------------ */
        foreach ( self::MAKES as $make ) {
            if ( stripos( $title, $make ) !== false ) {
                $out['make'] = self::MAKE_NORMALISE[ $make ] ?? $make;
                break;
            }
        }

        /* Model — words between make and year/engine pattern -------------- */
        if ( ! empty( $out['make'] ) ) {
            $stripped = preg_replace( '/\b' . preg_quote( $out['make'], '/' ) . '\b/i', '', $title );
            $stripped = preg_replace( '/\b(19|20)\d{2}\b/', '', $stripped );
            $stripped = preg_replace( '/\b\d+\.\d+[a-z]?\b/i', '', $stripped ); // engine
            $stripped = preg_replace( '/\b(4x4|awd|rwd|fwd)\b/i', '', $stripped );
            $model    = trim( preg_replace( '/\s{2,}/', ' ', $stripped ) );
            // Take first 2–3 meaningful words as model name.
            $words = array_values( array_filter( explode( ' ', $model ), fn( $w ) => strlen( $w ) >= 2 ) );
            if ( ! empty( $words ) ) {
                $out['model'] = implode( ' ', array_slice( $words, 0, 2 ) );
            }
        }

        /* Fuel type ------------------------------------------------------- */
        if ( preg_match( '/\b(plug.?in|phev)\b/i', $full ) ) {
            $out['fuel-type'] = 'plug-in-hybrid';
        } elseif ( preg_match( '/\bhybrid\b/i', $full ) ) {
            $out['fuel-type'] = 'hybrid';
        } elseif ( preg_match( '/\b(electric|ev\b|bev)\b/i', $full ) ) {
            $out['fuel-type'] = 'electric';
        } elseif ( preg_match( '/\bdiesel\b/i', $full ) ) {
            $out['fuel-type'] = 'diesel';
        } else {
            $out['fuel-type'] = 'petrol';
        }

        /* Transmission ---------------------------------------------------- */
        if ( preg_match( '/\b(auto(matic)?|dsg|pdk|cvt|dct|tiptronic|s.?tronic)\b/i', $full ) ) {
            $out['transmission'] = 'automatic';
        } elseif ( preg_match( '/\b(manual|mt\b|6.?speed|5.?speed)\b/i', $full ) ) {
            $out['transmission'] = 'manual';
        } else {
            $out['transmission'] = 'automatic';
        }

        /* Engine CC from title ------------------------------------------- */
        if ( preg_match( '/\b(\d+\.\d+)[litLT]?\b/', $title, $m ) ) {
            $cc = (int) round( (float) $m[1] * 1000 );
            if ( $cc >= 600 && $cc <= 7000 ) {
                $out['engine-cc'] = (string) $cc;
            }
        }

        /* Body type — categories first, then title/desc keywords ---------- */
        foreach ( $cats as $cat ) {
            foreach ( self::BODY_KEYWORDS as $kw => $val ) {
                if ( strpos( $cat, $kw ) !== false ) {
                    $out['body-type'] = $val;
                    break 2;
                }
            }
        }
        if ( empty( $out['body-type'] ) ) {
            foreach ( self::BODY_KEYWORDS as $kw => $val ) {
                if ( stripos( $full, $kw ) !== false ) {
                    $out['body-type'] = $val;
                    break;
                }
            }
        }
        $out['body-type'] = $out['body-type'] ?? 'sedan';

        /* Condition ------------------------------------------------------- */
        if ( preg_match( '/\bdemo\b/i', $full ) ) {
            $out['condition'] = 'demo';
        } elseif ( preg_match( '/\bnew\b/i', $title ) || $price > 650000 ) {
            $out['condition'] = 'new';
        } else {
            $out['condition'] = 'used';
        }

        /* Mileage --------------------------------------------------------- */
        if ( $out['condition'] === 'new' ) {
            $out['mileage-km'] = '0';
        } elseif ( $out['condition'] === 'demo' ) {
            $out['mileage-km'] = '5000';
        } else {
            // Estimate from age: 15,000 km/year average.
            $year = isset( $out['year'] ) ? (int) $out['year'] : (int) date( 'Y' ) - 4;
            $age  = max( 0, (int) date( 'Y' ) - $year );
            $out['mileage-km'] = (string) ( $age * 15000 );
        }

        /* Doors from body type ------------------------------------------- */
        $out['doors'] = self::DOORS_FOR_BODY[ $out['body-type'] ] ?? '4';

        /* Safety and NCAP — estimate from year --------------------------- */
        $year = isset( $out['year'] ) ? (int) $out['year'] : 2019;
        if ( $year >= 2023 ) {
            $out['safety-rating'] = '4.5';
            $out['ncap-stars']    = '5';
        } elseif ( $year >= 2020 ) {
            $out['safety-rating'] = '4.0';
            $out['ncap-stars']    = '4';
        } elseif ( $year >= 2017 ) {
            $out['safety-rating'] = '3.5';
            $out['ncap-stars']    = '4';
        } else {
            $out['safety-rating'] = '3.0';
            $out['ncap-stars']    = '3';
        }

        /* Finance estimate — price ÷ 60, rounded to nearest R100 --------- */
        if ( $price > 0 ) {
            $out['finance-from-zar'] = (string) ( (int) round( $price / 60 / 100 ) * 100 );
        }

        /* Certified used — conservative default, let admin set manually --- */
        $out['certified-used'] = 'no';

        return $out;
    }

    /* ── Apply inferred attributes to one product ─────────────────────────── */

    public static function migrate_one( WC_Product $product, bool $force = false ): array {
        $inferred = self::infer( $product );
        $existing = $product->get_attributes();
        $attrs    = $existing; // keep existing attributes intact
        $set      = [];
        $skipped  = [];

        foreach ( $inferred as $slug => $value ) {
            $tax = 'pa_' . $slug;

            // Skip if this attribute already has a value — unless force mode is on.
            if ( ! $force && isset( $existing[ $tax ] ) && ! empty( $existing[ $tax ]->get_options() ) ) {
                $skipped[] = $slug;
                continue;
            }

            // Store as a local attribute with the string value directly.
            // This avoids term-ID/taxonomy-registration ordering issues at sync time.
            $attr = new WC_Product_Attribute();
            $attr->set_id( 0 );
            $attr->set_name( $tax );
            $attr->set_options( [ (string) $value ] );
            $attr->set_visible( true );
            $attr->set_variation( false );

            $attrs[ $tax ] = $attr;
            $set[]         = $slug;
        }

        $product->set_attributes( $attrs );
        $product->save();

        return [ 'set' => $set, 'skipped' => $skipped ];
    }

    /* ── AJAX handler ─────────────────────────────────────────────────────── */

    public static function ajax_run(): void {
        check_ajax_referer( 'eshopeo_run_migration', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $force = ! empty( $_POST['force'] );

        self::ensure_attributes();

        $products = wc_get_products( [
            'limit'  => -1,
            'status' => 'publish',
            'return' => 'objects',
        ] );

        $rows = [];
        foreach ( $products as $p ) {
            $r      = self::migrate_one( $p, $force );
            $rows[] = [
                'id'      => $p->get_id(),
                'title'   => $p->get_name(),
                'set'     => $r['set'],
                'skipped' => $r['skipped'],
            ];
        }

        wp_send_json_success( [
            'processed' => count( $rows ),
            'results'   => $rows,
        ] );
    }

    /* ── Admin page ──────────────────────────────────────────────────────── */

    public static function render_page(): void {
        ?>
        <style>
        /* ── eShopeo Migrator Page ── */
        .eshopeo-migrator-page *,
        .eshopeo-migrator-page *::before,
        .eshopeo-migrator-page *::after { box-sizing: border-box; }

        .eshopeo-migrator-page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px 20px 60px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #374151;
            background: #f9fafb;
        }

        /* ── Header ── */
        .hmp-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 28px;
        }
        .hmp-header h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
            color: #1e1b4b;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .hmp-badge {
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
        .hmp-subtitle {
            font-size: 13px;
            color: #6b7280;
            margin: 4px 0 0;
            line-height: 1.5;
        }

        /* ── Info card ── */
        .hmp-info {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }
        .hmp-info-icon { flex-shrink: 0; margin-top: 1px; }
        .hmp-info-body { font-size: 13px; color: #1e40af; line-height: 1.6; }
        .hmp-info-body strong { color: #1e3a8a; }

        /* ── Attributes grid ── */
        .hmp-attrs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 8px;
            margin-top: 12px;
        }
        .hmp-attr-chip {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            background: #f5f3ff;
            border: 1px solid #ede9fe;
            border-radius: 6px;
            font-size: 12px;
            color: #4f46e5;
        }
        .hmp-attr-chip svg { flex-shrink: 0; }

        /* ── Section card ── */
        .hmp-section {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .hmp-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            border-bottom: 1px solid #e5e7eb;
            background: #fafafa;
        }
        .hmp-section-head h2 {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            color: #1e1b4b;
            border-left: 3px solid #6366f1;
            padding-left: 10px;
        }
        .hmp-section-body { padding: 24px; }

        /* ── Run controls ── */
        .hmp-controls {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .hmp-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            min-height: 40px;
            border: none;
            cursor: pointer;
            transition: background .15s, opacity .15s;
            font-family: inherit;
        }
        .hmp-btn-primary { background: #6366f1; color: #fff; }
        .hmp-btn-primary:hover { background: #4f46e5; }
        .hmp-btn:disabled { opacity: .55; cursor: not-allowed; }

        /* ── Toggle switch ── */
        .hmp-toggle-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            user-select: none;
        }
        .hmp-toggle {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 22px;
            flex-shrink: 0;
        }
        .hmp-toggle input { opacity: 0; width: 0; height: 0; }
        .hmp-toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: #d1d5db;
            border-radius: 22px;
            transition: background .2s;
        }
        .hmp-toggle-slider::before {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            left: 3px;
            top: 3px;
            background: #fff;
            border-radius: 50%;
            transition: transform .2s;
            box-shadow: 0 1px 3px rgba(0,0,0,.2);
        }
        .hmp-toggle input:checked + .hmp-toggle-slider { background: #ef4444; }
        .hmp-toggle input:checked + .hmp-toggle-slider::before { transform: translateX(18px); }
        .hmp-toggle-label {
            font-size: 13px;
            color: #374151;
        }
        .hmp-toggle-label strong { color: #ef4444; }

        /* ── Spinner ── */
        .hmp-spinner {
            width: 20px;
            height: 20px;
            border: 2px solid #e5e7eb;
            border-top-color: #6366f1;
            border-radius: 50%;
            animation: hmp-spin .7s linear infinite;
            display: none;
            flex-shrink: 0;
        }
        @keyframes hmp-spin { to { transform: rotate(360deg); } }

        .hmp-status-text {
            font-size: 13px;
            color: #6b7280;
            display: none;
        }

        /* ── Result notices ── */
        .hmp-result { margin-top: 0; }
        .hmp-result-notice {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 16px;
        }
        .hmp-result-notice.success { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; }
        .hmp-result-notice.error   { background: #fef2f2; border: 1px solid #fca5a5; color: #7f1d1d; }

        /* ── Results table ── */
        .hmp-table-wrap { overflow-x: auto; }
        .hmp-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .hmp-table thead th {
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
        }
        .hmp-table thead th:first-child { width: 50px; }
        .hmp-table tbody td {
            padding: 10px 14px;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
            vertical-align: top;
        }
        .hmp-table tbody tr:last-child td { border-bottom: none; }
        .hmp-table tbody tr:nth-child(even) td { background: #f9fafb; }
        .hmp-table tbody tr:hover td { background: #f5f3ff; }
        .hmp-id-cell { color: #9ca3af; font-size: 12px; font-variant-numeric: tabular-nums; }
        .hmp-attr-set {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }
        .hmp-attr-tag {
            display: inline-block;
            padding: 1px 7px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 500;
        }
        .hmp-attr-tag.set     { background: #dcfce7; color: #15803d; }
        .hmp-attr-tag.none    { background: #f3f4f6; color: #9ca3af; font-style: italic; }
        .hmp-attr-tag.skipped { background: #f3f4f6; color: #6b7280; }

        .hmp-footer-note {
            margin-top: 16px;
            padding: 12px 16px;
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            font-size: 12px;
            color: #78350f;
            line-height: 1.6;
        }

        /* ── Responsive ── */
        @media (max-width: 782px) {
            .eshopeo-migrator-page { padding: 16px 12px 40px; }
            .hmp-header { flex-direction: column; }
            .hmp-controls { flex-direction: column; align-items: flex-start; }
            .hmp-btn { width: 100%; justify-content: center; }
            .hmp-attrs-grid { grid-template-columns: repeat(2, 1fr); }
            .hmp-section-body { padding: 16px; }
        }
        @media (max-width: 480px) {
            .hmp-attrs-grid { grid-template-columns: 1fr; }
        }
        </style>

        <div class="eshopeo-migrator-page">

            <!-- Page header -->
            <div class="hmp-header">
                <div>
                    <h1>
                        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" aria-hidden="true"><path stroke="#6366f1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Automotive Data Migration
                        <span class="hmp-badge">eShopeo</span>
                    </h1>
                    <p class="hmp-subtitle">Auto-fill missing automotive attributes from product titles, categories, and descriptions.</p>
                </div>
            </div>

            <!-- Info banner -->
            <div class="hmp-info">
                <div class="hmp-info-icon">
                    <svg width="20" height="20" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="#3b82f6" stroke-width="2"/><path stroke="#3b82f6" stroke-width="2" stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>
                </div>
                <div class="hmp-info-body">
                    This tool inspects every published WooCommerce product and auto-fills missing automotive attributes (make, model, year, body type, fuel, transmission, mileage estimate, finance estimate, safety rating, doors, etc.) based on product data.
                    <br><strong>Existing attribute values are never overwritten</strong> unless you enable force mode.
                    Run once after importing dummy data, then review and correct any misdetections in the WC product editor before running the eShopeo catalog sync.
                </div>
            </div>

            <!-- Attributes being populated -->
            <div class="hmp-section">
                <div class="hmp-section-head">
                    <h2>Attributes That Will Be Populated</h2>
                </div>
                <div class="hmp-section-body">
                    <div class="hmp-attrs-grid">
                        <?php foreach ( self::ATTRS as $slug => $label ) : ?>
                        <div class="hmp-attr-chip">
                            <svg width="12" height="12" fill="none" viewBox="0 0 24 24"><path stroke="#6366f1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/></svg>
                            <?php echo esc_html( $label ); ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Run section -->
            <div class="hmp-section">
                <div class="hmp-section-head">
                    <h2>Run Migration</h2>
                </div>
                <div class="hmp-section-body">
                    <div class="hmp-controls">
                        <button id="hx-migrate-btn" class="hmp-btn hmp-btn-primary">
                            <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Run Migration
                        </button>

                        <label class="hmp-toggle-wrap">
                            <div class="hmp-toggle">
                                <input type="checkbox" id="hx-migrate-force">
                                <span class="hmp-toggle-slider"></span>
                            </div>
                            <span class="hmp-toggle-label">Force overwrite — <strong>overwrites existing attributes</strong></span>
                        </label>

                        <div class="hmp-spinner" id="hx-migrate-spinner"></div>
                        <span class="hmp-status-text" id="hx-migrate-status">Running&hellip; this may take a moment for large catalogs.</span>
                    </div>
                </div>
            </div>

            <!-- Results area -->
            <div id="hx-migrate-result"></div>

        </div><!-- .eshopeo-migrator-page -->

        <script>
        document.getElementById('hx-migrate-btn').addEventListener('click', function () {
            var btn     = this;
            var spinner = document.getElementById('hx-migrate-spinner');
            var status  = document.getElementById('hx-migrate-status');
            var result  = document.getElementById('hx-migrate-result');

            btn.disabled          = true;
            spinner.style.display = 'block';
            status.style.display  = 'inline';
            result.innerHTML      = '';

            var fd = new FormData();
            fd.append('action', 'eshopeo_run_migration');
            fd.append('nonce', '<?php echo esc_js( wp_create_nonce( 'eshopeo_run_migration' ) ); ?>');
            if (document.getElementById('hx-migrate-force').checked) {
                fd.append('force', '1');
            }

            fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                spinner.style.display = 'none';
                status.style.display  = 'none';
                btn.disabled          = false;

                if (!res.success) {
                    result.innerHTML =
                        '<div class="hmp-section"><div class="hmp-section-body">' +
                        '<div class="hmp-result-notice error"><strong>Error:</strong> ' +
                        ((res.data && res.data.message) ? res.data.message.replace(/</g,'&lt;') : 'Unknown error') +
                        '</div></div></div>';
                    return;
                }

                var d    = res.data;
                var setCount  = d.results.filter(function(r){ return r.set.length > 0; }).length;
                var skipCount = d.results.filter(function(r){ return r.skipped.length > 0; }).length;

                var html =
                    '<div class="hmp-section">' +
                    '<div class="hmp-section-head"><h2>Migration Results</h2></div>' +
                    '<div class="hmp-section-body">' +
                    '<div class="hmp-result-notice success">' +
                    '<strong>Migration complete.</strong> Processed ' + d.processed + ' product(s). ' +
                    setCount + ' had attributes updated, ' + skipCount + ' had existing values preserved.' +
                    '</div>' +
                    '<div class="hmp-table-wrap">' +
                    '<table class="hmp-table">' +
                    '<thead><tr>' +
                    '<th>ID</th>' +
                    '<th>Product</th>' +
                    '<th>Attributes set</th>' +
                    '<th>Already had value</th>' +
                    '</tr></thead>' +
                    '<tbody>';

                d.results.forEach(function (row) {
                    var setHtml;
                    if (row.set.length > 0) {
                        setHtml = '<div class="hmp-attr-set">';
                        row.set.forEach(function(a) {
                            setHtml += '<span class="hmp-attr-tag set">' + a + '</span>';
                        });
                        setHtml += '</div>';
                    } else {
                        setHtml = '<span class="hmp-attr-tag none">none set</span>';
                    }

                    var skipHtml;
                    if (row.skipped.length > 0) {
                        skipHtml = '<div class="hmp-attr-set">';
                        row.skipped.forEach(function(a) {
                            skipHtml += '<span class="hmp-attr-tag skipped">' + a + '</span>';
                        });
                        skipHtml += '</div>';
                    } else {
                        skipHtml = '<span style="color:#9ca3af;font-size:12px;">—</span>';
                    }

                    html +=
                        '<tr>' +
                        '<td class="hmp-id-cell">#' + row.id + '</td>' +
                        '<td style="font-weight:500;">' + row.title.replace(/</g, '&lt;') + '</td>' +
                        '<td>' + setHtml + '</td>' +
                        '<td>' + skipHtml + '</td>' +
                        '</tr>';
                });

                html +=
                    '</tbody></table></div>' +
                    '<div class="hmp-footer-note">' +
                    'Review the results above, then go to <strong>WooCommerce &rarr; Products</strong> and correct any misdetections ' +
                    'before running the eShopeo catalog sync.' +
                    '</div>' +
                    '</div></div>';

                result.innerHTML = html;
            })
            .catch(function (err) {
                spinner.style.display = 'none';
                status.style.display  = 'none';
                btn.disabled          = false;
                result.innerHTML =
                    '<div class="hmp-section"><div class="hmp-section-body">' +
                    '<div class="hmp-result-notice error"><strong>Request failed:</strong> ' + err.message + '</div>' +
                    '</div></div>';
            });
        });
        </script>
        <?php
    }
}
