<?php
defined( 'ABSPATH' ) || exit;

class Helix_Widget {
    private static bool $has_search_bar = false;

    public static function init(): void {
        add_action( 'wp_footer', [ self::class, 'inject' ] );
        add_action( 'wp_footer', [ self::class, 'inject_live_search' ] );
        add_shortcode( 'helix_search', [ self::class, 'shortcode' ] );
        add_action( 'wp_ajax_nopriv_helix_live_search', [ self::class, 'ajax_live_search' ] );
        add_action( 'wp_ajax_helix_live_search', [ self::class, 'ajax_live_search' ] );
    }

    public static function shortcode(): string {
        self::$has_search_bar = true;
        return '<div id="hx-sb-target"></div>';
    }

    public static function inject(): void {
        if ( is_admin() ) return;
        if ( ! get_option( 'helix_widget_enabled', 0 ) ) return;

        $public_key = get_option( 'helix_public_key', '' );
        $api_url    = rtrim( get_option( 'helix_api_url', '' ), '/' );
        if ( ! $public_key || ! $api_url ) return;

        $wa_enabled = get_option( 'helix_wa_enabled', 0 ) && get_option( 'helix_wa_number', '' );
        $wa_number  = get_option( 'helix_wa_number', '' );
        $wa_message = get_option( 'helix_wa_message', "Hi! I'd like some skincare advice." );

        $config = wp_json_encode( [
            'wa'        => (bool) $wa_enabled,
            'waNum'     => $wa_number,
            'waMsg'     => $wa_message,
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'sbAnim'    => (bool) get_option( 'helix_sb_animations', 1 ),
            'flyCart'   => (bool) get_option( 'helix_sb_fly_to_cart', 1 ),
            'cardModal' => (bool) get_option( 'helix_sb_card_modal', 1 ),
        ] );
        ?>
        <script>window.helixConfig = <?php echo $config; ?>;</script>
        <?php if ( self::$has_search_bar ) : ?>
        <script src="<?php echo esc_url( $api_url . '/v1/widget/searchbar.js' ); ?>?key=<?php echo esc_attr( $public_key ); ?>" defer></script>
        <?php endif; ?>
        <script src="<?php echo esc_url( $api_url . '/v1/widget/embed.js' ); ?>?key=<?php echo esc_attr( $public_key ); ?>" defer></script>
        <?php
    }

    public static function inject_live_search(): void {
        if ( is_admin() ) return;
        if ( ! get_option( 'helix_widget_enabled', 0 ) ) return;
        if ( ! class_exists( 'WooCommerce' ) ) return;
        $ajax_url = esc_js( admin_url( 'admin-ajax.php' ) );
        ?>
<style>
#hx-ls-drop{position:absolute;top:100%;left:0;right:0;margin-top:4px;background:#fff;
border:1px solid rgba(0,0,0,.1);border-radius:12px;
box-shadow:0 8px 32px rgba(0,0,0,.12),0 0 0 1px rgba(0,0,0,.04);
z-index:99999;overflow:hidden;
font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
a.hx-ls-item{display:flex;align-items:center;gap:10px;padding:10px 14px;
text-decoration:none;color:inherit;border-bottom:1px solid rgba(0,0,0,.05);
transition:background .12s;outline:none;}
a.hx-ls-item:last-of-type{border-bottom:none;}
a.hx-ls-item:hover,a.hx-ls-item:focus{background:rgba(124,58,237,.04);}
.hx-ls-img{width:44px;height:44px;object-fit:cover;border-radius:6px;flex-shrink:0;background:#f0edff;}
.hx-ls-info{display:flex;flex-direction:column;gap:2px;flex:1;min-width:0;}
.hx-ls-name{font:500 13px/1.3 -apple-system,sans-serif;color:#1C1C1E;
white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.hx-ls-cat{font:400 11px/1 -apple-system,sans-serif;color:#8E8E93;}
.hx-ls-price{font:700 12px/1 -apple-system,sans-serif;color:#7C3AED;margin-top:2px;}
.hx-ls-hint{margin:0;padding:7px 14px;font:400 11px/1 -apple-system,sans-serif;
color:#AEAEB2;text-align:center;background:rgba(0,0,0,.02);border-top:1px solid rgba(0,0,0,.04);}
</style>
<script>
(function(){
  'use strict';
  var AJAX='<?php echo $ajax_url; ?>';
  var _t,_ctrl;
  function close(){var d=document.getElementById('hx-ls-drop');if(d)d.remove();}
  function render(inp,res){
    close();
    if(!res||!res.length)return;
    var d=document.createElement('div');
    d.id='hx-ls-drop';
    d.innerHTML=res.map(function(r){
      return '<a class="hx-ls-item" href="'+r.url+'">' +
        '<img class="hx-ls-img" src="'+r.img+'" alt="" loading="lazy">' +
        '<span class="hx-ls-info">' +
          '<span class="hx-ls-name">'+r.name+'</span>' +
          (r.cat?'<span class="hx-ls-cat">'+r.cat+'</span>':'') +
          '<span class="hx-ls-price">'+r.price+'</span>' +
        '</span></a>';
    }).join('')+'<p class="hx-ls-hint">Press ↵ to see all results</p>';
    var ref=inp.closest('form')||inp.parentElement;
    if(getComputedStyle(ref).position==='static')ref.style.position='relative';
    ref.appendChild(d);
  }
  function doSearch(inp){
    var q=inp.value.trim();
    if(q.length<2){close();return;}
    if(_ctrl)_ctrl.abort();
    _ctrl=new AbortController();
    fetch(AJAX+'?action=helix_live_search&q='+encodeURIComponent(q),{signal:_ctrl.signal})
      .then(function(r){return r.json();})
      .then(function(data){render(inp,data);})
      .catch(function(){});
  }
  function attach(inp){
    inp.setAttribute('autocomplete','off');
    inp.addEventListener('input',function(){
      clearTimeout(_t);
      _t=setTimeout(function(){doSearch(inp);},300);
    });
    inp.addEventListener('keydown',function(e){
      var d=document.getElementById('hx-ls-drop');
      if(e.key==='Escape'){close();return;}
      if(!d)return;
      var items=Array.from(d.querySelectorAll('a.hx-ls-item'));
      var fi=d.querySelector('a.hx-ls-item:focus');
      var idx=fi?items.indexOf(fi):-1;
      if(e.key==='ArrowDown'){e.preventDefault();var n=items[idx+1];if(n)n.focus();}
      if(e.key==='ArrowUp'){e.preventDefault();if(idx>0)items[idx-1].focus();else inp.focus();}
    });
  }
  document.addEventListener('click',function(e){
    if(!e.target.closest('#hx-ls-drop')&&!e.target.closest('input[name="s"]'))close();
  });
  function init(){document.querySelectorAll('input[name="s"]').forEach(attach);}
  document.readyState==='loading'?document.addEventListener('DOMContentLoaded',init):init();
})();
</script>
        <?php
    }

    public static function ajax_live_search(): void {
        if ( ! class_exists( 'WooCommerce' ) ) wp_send_json( [] );

        $q = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
        if ( mb_strlen( $q ) < 2 ) wp_send_json( [] );

        // Title / content / excerpt search
        $ids = get_posts( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 6,
            's'              => $q,
            'orderby'        => 'relevance',
            'fields'         => 'ids',
        ] );

        // SKU search
        if ( count( $ids ) < 6 ) {
            $sku_ids = get_posts( [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => 6 - count( $ids ),
                'meta_query'     => [ [
                    'key'     => '_sku',
                    'value'   => $q,
                    'compare' => 'LIKE',
                ] ],
                'fields' => 'ids',
            ] );
            $ids = array_unique( array_merge( $ids, $sku_ids ) );
        }

        // Category name search
        if ( count( $ids ) < 6 ) {
            $terms = get_terms( [
                'taxonomy'   => 'product_cat',
                'name__like' => $q,
                'fields'     => 'ids',
                'hide_empty' => true,
            ] );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                $cat_ids = get_posts( [
                    'post_type'      => 'product',
                    'post_status'    => 'publish',
                    'posts_per_page' => 6 - count( $ids ),
                    'tax_query'      => [ [
                        'taxonomy' => 'product_cat',
                        'field'    => 'term_id',
                        'terms'    => $terms,
                    ] ],
                    'fields' => 'ids',
                ] );
                $ids = array_unique( array_merge( $ids, $cat_ids ) );
            }
        }

        $ids = array_slice( $ids, 0, 6 );

        $results = [];
        foreach ( $ids as $id ) {
            $p = wc_get_product( $id );
            if ( ! $p ) continue;
            $img_id    = $p->get_image_id();
            $img_url   = $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : wc_placeholder_img_src();
            $cats      = get_the_terms( $id, 'product_cat' );
            $cat_name  = ( $cats && ! is_wp_error( $cats ) ) ? esc_html( $cats[0]->name ) : '';
            $results[] = [
                'name'  => esc_html( $p->get_name() ),
                'price' => strip_tags( $p->get_price_html() ),
                'url'   => esc_url( get_permalink( $id ) ),
                'img'   => esc_url( $img_url ?: wc_placeholder_img_src() ),
                'cat'   => $cat_name,
            ];
        }

        wp_send_json( $results );
    }
}
