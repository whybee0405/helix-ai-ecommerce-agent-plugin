<?php
defined( 'ABSPATH' ) || exit;

/**
 * One-shot automotive attribute migration tool.
 * Reads existing WC products, infers automotive attributes from titles /
 * categories / descriptions, creates global WC attribute taxonomies if
 * missing, and writes values — skipping any attribute already populated.
 */
class Helix_Migrator {

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
        add_action( 'admin_menu', [ self::class, 'add_menu' ] );
        add_action( 'wp_ajax_helix_run_migration', [ self::class, 'ajax_run' ] );
    }

    public static function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            'Helix — Data Migration',
            'Helix Migrate',
            'manage_woocommerce',
            'helix-migrator',
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

    public static function migrate_one( WC_Product $product ): array {
        $inferred = self::infer( $product );
        $existing = $product->get_attributes();
        $attrs    = $existing; // keep existing attributes intact
        $set      = [];
        $skipped  = [];

        foreach ( $inferred as $slug => $value ) {
            $tax = 'pa_' . $slug;

            // Skip if this attribute already has at least one term assigned.
            if ( isset( $existing[ $tax ] ) && ! empty( $existing[ $tax ]->get_options() ) ) {
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
        check_ajax_referer( 'helix_run_migration', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        self::ensure_attributes();

        $products = wc_get_products( [
            'limit'  => -1,
            'status' => 'publish',
            'return' => 'objects',
        ] );

        $rows = [];
        foreach ( $products as $p ) {
            $r      = self::migrate_one( $p );
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
        <div class="wrap">
            <h1>Helix — Automotive Data Migration</h1>
            <p>
                This tool inspects every published WC product and auto-fills missing
                automotive attributes (make, model, year, body type, fuel, transmission,
                mileage estimate, finance estimate, safety rating, doors, etc.) based on
                titles, categories, and descriptions.
                <strong>Existing attribute values are never overwritten.</strong>
            </p>
            <p>Run once after importing dummy data, then review the results and correct any
               misdetections directly in the WC product editor.</p>

            <button id="hx-migrate-btn" class="button button-primary button-large">
                Run Migration
            </button>
            <span id="hx-migrate-spinner" class="spinner" style="float:none;margin:0 8px;vertical-align:middle;display:none;"></span>

            <div id="hx-migrate-result" style="margin-top:24px;"></div>

            <script>
            document.getElementById('hx-migrate-btn').addEventListener('click', function () {
                var btn     = this;
                var spinner = document.getElementById('hx-migrate-spinner');
                var result  = document.getElementById('hx-migrate-result');

                btn.disabled             = true;
                spinner.style.display    = 'inline-block';
                result.innerHTML         = '<p>Running… this may take a moment for large catalogs.</p>';

                var fd = new FormData();
                fd.append('action', 'helix_run_migration');
                fd.append('nonce', '<?php echo esc_js( wp_create_nonce( 'helix_run_migration' ) ); ?>');

                fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: fd,
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    spinner.style.display = 'none';
                    btn.disabled          = false;

                    if (!res.success) {
                        result.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p><strong>Error:</strong> ' + (res.data && res.data.message ? res.data.message : 'Unknown error') + '</p></div>';
                        return;
                    }

                    var d    = res.data;
                    var html = '<div class="notice notice-success inline" style="margin:0 0 16px;"><p>'
                             + '<strong>Migration complete.</strong> Processed ' + d.processed + ' product(s).'
                             + '</p></div>';

                    html += '<table class="wp-list-table widefat fixed striped">'
                         +  '<thead><tr><th style="width:40px">ID</th><th>Product</th><th>Attributes set</th><th>Already had value</th></tr></thead>'
                         +  '<tbody>';

                    d.results.forEach(function (row) {
                        var setList  = row.set.length  ? row.set.join(', ')     : '<em style="color:#8E8E93">none</em>';
                        var skipList = row.skipped.length ? row.skipped.join(', ') : '—';
                        html += '<tr>'
                             +  '<td>' + row.id + '</td>'
                             +  '<td>' + row.title.replace(/</g, '&lt;') + '</td>'
                             +  '<td>' + setList  + '</td>'
                             +  '<td style="color:#6B6B6F;font-size:12px;">' + skipList + '</td>'
                             +  '</tr>';
                    });

                    html += '</tbody></table>';
                    html += '<p style="margin-top:12px;">Review the results above, then go to '
                         +  '<strong>WooCommerce → Products</strong> and correct any misdetections '
                         +  'before running the Helix catalog sync.</p>';

                    result.innerHTML = html;
                })
                .catch(function (err) {
                    spinner.style.display = 'none';
                    btn.disabled          = false;
                    result.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p><strong>Request failed:</strong> ' + err.message + '</p></div>';
                });
            });
            </script>
        </div>
        <?php
    }
}
