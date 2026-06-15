import json
from collections.abc import AsyncGenerator
from dataclasses import dataclass, field
from typing import Literal
from uuid import UUID

import structlog
from fastapi import APIRouter, Depends, HTTPException, Response, status
from fastapi.responses import StreamingResponse
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.auth.tokens import issue_widget_token
from helix.api.deps import get_db, get_tenant, get_widget_tenant
from helix.config import get_settings
from helix.db.crud.conversations import (
    append_messages,
    create_conversation,
    get_conversation,
    get_messages,
    set_message_feedback,
)
from helix.db.crud.customers import get_customer_by_id
from helix.db.crud.products import vector_search_products
from helix.db.crud.usage_events import create_usage_event
from helix.db.models import Tenant
from helix.domain.consultant import handle_query
from helix.domain.routine import build_routine
from helix.domain.search import embed_query
from helix.llm.gateway import RouteResult
from helix.packs.registry import get_pack_for_tenant
from helix.db.crud.widget_events import create_widget_event

logger = structlog.get_logger(__name__)

router = APIRouter(prefix="/v1/widget", tags=["widget"])


_EMBED_JS = r"""
(function () {
  var script = document.currentScript;
  var src    = (script && script.src) ? new URL(script.src) : null;
  var KEY    = src ? (src.searchParams.get('key') || src.searchParams.get('tenant_key')) : null;
  if (!KEY) { console.warn('[Helix] Missing key param'); return; }

  var BASE       = src ? src.origin : '';
  var STORE_BASE = window.location.origin;
  var LS_TOK     = 'hx_tok_' + KEY;
  var LS_EXP     = 'hx_exp_' + KEY;
  var LS_CONV    = 'hx_conv_' + KEY;
  var LS_MSGS    = 'hx_msgs_' + KEY;
  var open       = false;
  var convId     = localStorage.getItem(LS_CONV) || null;
  var _msgLog    = [];
  var wcNonce    = null;
  var nonceProm  = null;

  /* Detect WordPress REST root — works even when WP is in a subdirectory */
  var _apiLink = document.head.querySelector('link[rel="https://api.w.org/"]');
  var _restRoot = (_apiLink && _apiLink.href)
    || (window.wpApiSettings && window.wpApiSettings.root)
    || (STORE_BASE + '/wp-json/');
  _restRoot = _restRoot.replace(/\/?$/, '/');

  /* Detect WooCommerce AJAX URL from page globals */
  var _wcp = window.wc_add_to_cart_params || window.woocommerce_params || window.wc_cart_fragments_params || {};
  var _wcAjaxBase = _wcp.wc_ajax_url || (STORE_BASE + '/?wc-ajax=%%endpoint%%');
  function _wcAjaxUrl(ep) { return _wcAjaxBase.replace('%%endpoint%%', ep); }

  /* WhatsApp config from page global set by WP plugin */
  var _cfg = window.helixConfig || {};
  var _waEnabled = !!((_cfg.wa) && (_cfg.waNum));
  var _waNum  = (_cfg.waNum  || '').replace(/\D/g, '');
  var _waMsg  = _cfg.waMsg  || 'Hi! I’d like some skincare advice.';

  /* ── Styles ─────────────────────────────────────────────────────────── */
  var style = document.createElement('style');
  style.textContent = [
    '#hx-btn{all:unset;position:fixed;bottom:28px;right:28px;z-index:999998;width:56px;height:56px;',
    'border-radius:50%;background:linear-gradient(135deg,#7C3AED,#4F46E5);cursor:pointer;',
    'box-shadow:0 4px 24px rgba(124,58,237,.45);display:flex;align-items:center;justify-content:center;',
    'animation:hx-glow 3s ease-in-out infinite;transition:transform .2s cubic-bezier(.175,.885,.32,1.275);}',
    '#hx-btn:hover{transform:scale(1.08);}#hx-btn:active{transform:scale(.94);}',
    '#hx-btn svg{width:24px;height:24px;fill:#fff;pointer-events:none;}',
    '@keyframes hx-glow{0%,100%{box-shadow:0 4px 24px rgba(124,58,237,.45);}',
    '50%{box-shadow:0 4px 36px rgba(124,58,237,.75),0 0 0 10px rgba(124,58,237,.1);}}'  ,
    '.hx-star{position:absolute;top:-5px;right:-5px;width:20px;height:20px;border-radius:50%;',
    'background:linear-gradient(135deg,#FFD60A,#FF9F0A);',
    'display:flex;align-items:center;justify-content:center;font-size:10px;color:#fff;font-weight:700;',
    'box-shadow:0 2px 8px rgba(255,159,10,.65);border:2px solid #fff;pointer-events:none;',
    'animation:hx-star 4s ease-in-out infinite;}',
    '@keyframes hx-star{0%,100%{transform:scale(1) rotate(0deg);}',
    '25%{transform:scale(1.25) rotate(22deg);}75%{transform:scale(1.1) rotate(-12deg);}}',

    '#hx-panel{position:fixed;bottom:96px;right:28px;z-index:999999;width:360px;',
    'height:580px;max-height:calc(100vh - 120px);',
    'background:rgba(255,255,255,.94);backdrop-filter:blur(24px) saturate(180%);',
    '-webkit-backdrop-filter:blur(24px) saturate(180%);border-radius:20px;',
    'border:1px solid rgba(255,255,255,.7);',
    'box-shadow:0 24px 64px rgba(0,0,0,.12),0 0 0 1px rgba(0,0,0,.04),inset 0 1px 0 rgba(255,255,255,.8);',
    'display:flex;flex-direction:column;overflow:hidden;cursor:auto !important;',
    'transform:translateY(16px) scale(.96);opacity:0;pointer-events:none;',
    'transition:transform .38s cubic-bezier(.175,.885,.32,1.275),opacity .25s cubic-bezier(.4,0,.2,1);}',
    '#hx-panel.hx-open{transform:translateY(0) scale(1);opacity:1;pointer-events:all;}',
    '#hx-panel *{cursor:auto !important;}',
    '#hx-panel button,#hx-panel .hx-catc,#hx-panel a{cursor:pointer !important;}',

    '#hx-header{padding:14px 16px;display:flex;align-items:center;gap:10px;',
    'border-bottom:1px solid rgba(0,0,0,.06);background:rgba(255,255,255,.6);',
    'backdrop-filter:blur(8px);flex-shrink:0;}',
    '.hx-av{width:34px;height:34px;border-radius:50%;flex-shrink:0;',
    'background:linear-gradient(135deg,#7C3AED,#4F46E5);',
    'display:flex;align-items:center;justify-content:center;}',
    '.hx-av svg{width:17px;height:17px;fill:#fff;}',
    '.hx-ht{flex:1;}.hx-ht strong{display:block;font:600 13.5px/1.2 -apple-system,sans-serif;color:#1C1C1E;}',
    '.hx-ht span{font:400 11px/1 -apple-system,sans-serif;color:#6B6B6F;margin-top:2px;display:block;}',
    '.hx-dot-live{width:7px;height:7px;border-radius:50%;background:#34C759;',
    'box-shadow:0 0 0 2px rgba(52,199,89,.25);animation:hx-pdot 2s ease infinite;}',
    '@keyframes hx-pdot{0%,100%{opacity:1;}50%{opacity:.4;}}',
    '#hx-close,#hx-newchat{all:unset;cursor:pointer;width:28px;height:28px;border-radius:50%;',
    'background:rgba(0,0,0,.06);display:flex;align-items:center;justify-content:center;',
    'color:#6B6B6F;font-size:18px;line-height:1;transition:background .15s;flex-shrink:0;}',
    '#hx-close:hover,#hx-newchat:hover{background:rgba(0,0,0,.12);}',
    '#hx-newchat svg{width:14px;height:14px;stroke:#6B6B6F;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;}',

    '#hx-msgs{flex:1;overflow-y:auto;padding:14px;scroll-behavior:smooth;',
    'scrollbar-width:thin;scrollbar-color:rgba(0,0,0,.1) transparent;}',
    '#hx-msgs::-webkit-scrollbar{width:3px;}',
    '#hx-msgs::-webkit-scrollbar-thumb{background:rgba(0,0,0,.1);border-radius:2px;}',

    '.hx-welcome{text-align:center;padding:28px 16px 16px;}',
    '.hx-wi{width:52px;height:52px;border-radius:50%;margin:0 auto 14px;',
    'background:linear-gradient(135deg,#7C3AED,#4F46E5);',
    'display:flex;align-items:center;justify-content:center;}',
    '.hx-wi svg{width:26px;height:26px;fill:#fff;}',
    '.hx-welcome h3{font:600 15px/1.3 -apple-system,sans-serif;color:#1C1C1E;margin:0 0 7px;}',
    '.hx-welcome p{font:400 13px/1.55 -apple-system,sans-serif;color:#6B6B6F;margin:0;}',

    '.hx-msg{display:flex;margin-bottom:10px;',
    'animation:hx-min .32s cubic-bezier(.175,.885,.32,1.275) both;}',
    '@keyframes hx-min{from{opacity:0;transform:translateY(10px) scale(.97);}to{opacity:1;transform:translateY(0) scale(1);}}',
    '.hx-msg.hx-user{justify-content:flex-end;}.hx-msg.hx-bot{justify-content:flex-start;}',
    '.hx-bubble{max-width:88%;font:400 13.5px/1.6 -apple-system,BlinkMacSystemFont,sans-serif;}',
    '.hx-user .hx-bubble{background:linear-gradient(135deg,#7C3AED,#4F46E5);color:#fff;',
    'padding:10px 14px;border-radius:18px 18px 4px 18px;box-shadow:0 2px 12px rgba(124,58,237,.3);}',
    '.hx-bot .hx-bubble{color:#1C1C1E;}',
    '.hx-block{background:rgba(242,242,247,.95);padding:10px 14px;margin-bottom:6px;',
    'border-radius:18px 18px 18px 4px;box-shadow:0 1px 4px rgba(0,0,0,.07);}',
    '.hx-block p{margin:0 0 5px;line-height:1.6;}.hx-block p:last-child{margin:0;}',
    '.hx-block strong{font-weight:600;color:#1C1C1E;}.hx-block em{font-style:italic;}',

    '.hx-products{margin-top:10px;display:flex;flex-direction:column;gap:8px;}',
    '.hx-card{background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:14px;',
    'display:flex;gap:10px;padding:10px;',
    'box-shadow:0 1px 6px rgba(0,0,0,.06);',
    'transition:transform .22s cubic-bezier(.175,.885,.32,1.275),box-shadow .22s ease;',
    'animation:hx-cin .38s cubic-bezier(.175,.885,.32,1.275) both;}',
    '.hx-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.1);}',
    '@keyframes hx-cin{from{opacity:0;transform:translateY(8px) scale(.97);}to{opacity:1;transform:translateY(0) scale(1);}}',
    '.hx-cimg{width:66px;height:66px;border-radius:10px;object-fit:cover;flex-shrink:0;',
    'background:linear-gradient(135deg,#f0edff,#ede9fe);}',
    '.hx-cph{width:66px;height:66px;border-radius:10px;flex-shrink:0;',
    'background:linear-gradient(135deg,#f0edff,#ede9fe);',
    'display:flex;align-items:center;justify-content:center;}',
    '.hx-cph svg{width:30px;height:30px;opacity:.25;}',
    '.hx-cb{flex:1;min-width:0;display:flex;flex-direction:column;gap:5px;}',
    '.hx-ct{font:500 12.5px/1.3 -apple-system,sans-serif;color:#1C1C1E;',
    'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
    '.hx-cp{font:700 13px/1 -apple-system,sans-serif;',
    'background:linear-gradient(135deg,#7C3AED,#4F46E5);',
    '-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}',
    '.hx-catc{all:unset;cursor:pointer;margin-top:auto;padding:5px 10px;border-radius:8px;',
    'background:linear-gradient(135deg,#7C3AED,#4F46E5);color:#fff;',
    'font:500 11px/1 -apple-system,sans-serif;text-align:center;',
    'transition:all .2s cubic-bezier(.175,.885,.32,1.275);display:block;}',
    '.hx-catc:hover{transform:scale(1.05);box-shadow:0 3px 10px rgba(124,58,237,.4);}',
    '.hx-catc:active{transform:scale(.95);}',
    '.hx-catc.hx-adding{opacity:.65;pointer-events:none;}',
    '.hx-catc.hx-added{background:linear-gradient(135deg,#34C759,#30D158)!important;',
    '-webkit-text-fill-color:#fff!important;}',

    '#hx-typing{display:none;padding:0 14px 10px;align-items:center;}',
    '#hx-typing.hx-show{display:flex;}',
    '.hx-tb{background:rgba(242,242,247,.95);border-radius:18px 18px 18px 4px;',
    'padding:10px 14px;display:flex;gap:5px;align-items:center;}',
    '.hx-d{width:6px;height:6px;border-radius:50%;background:#AEAEB2;',
    'animation:hx-db 1.2s ease-in-out infinite;}',
    '.hx-d:nth-child(2){animation-delay:.15s;}.hx-d:nth-child(3){animation-delay:.3s;}',
    '@keyframes hx-db{0%,60%,100%{transform:translateY(0);opacity:.4;}30%{transform:translateY(-5px);opacity:1;}}',

    '#hx-footer{padding:10px 14px;border-top:1px solid rgba(0,0,0,.06);',
    'background:rgba(255,255,255,.6);backdrop-filter:blur(8px);flex-shrink:0;}',
    '#hx-form{display:flex;gap:8px;align-items:flex-end;}',
    '#hx-inp{flex:1;border:1.5px solid rgba(0,0,0,.1);border-radius:12px;',
    'padding:9px 12px;font:400 13.5px/1.4 -apple-system,sans-serif;color:#1C1C1E;',
    'caret-color:#7C3AED;background:rgba(255,255,255,.9);outline:none;resize:none;cursor:text !important;',
    'min-height:38px;max-height:96px;overflow-y:auto;',
    'transition:border-color .15s;}',
    '#hx-inp:focus{border-color:#7C3AED;}',
    '#hx-inp::placeholder{color:#AEAEB2;}',
    '#hx-send{all:unset;cursor:pointer;width:36px;height:36px;border-radius:50%;flex-shrink:0;',
    'background:linear-gradient(135deg,#7C3AED,#4F46E5);',
    'display:flex;align-items:center;justify-content:center;',
    'box-shadow:0 2px 8px rgba(124,58,237,.35);',
    'transition:transform .2s cubic-bezier(.175,.885,.32,1.275);}',
    '#hx-send:hover{transform:scale(1.1);}#hx-send:active{transform:scale(.9);}',
    '#hx-send svg{width:16px;height:16px;fill:#fff;}',
    '#hx-wa-wrap{padding:0 0 6px;}',
    '.hx-or{display:flex;align-items:center;gap:8px;margin:8px 0 7px;',
    'font:400 10px/1 -apple-system,sans-serif;color:#AEAEB2;text-transform:uppercase;letter-spacing:.06em;}',
    '.hx-or::before,.hx-or::after{content:"";flex:1;height:1px;background:rgba(0,0,0,.08);}',
    '#hx-wa-btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;',
    'padding:9px 14px;border-radius:10px;background:#25D366;color:#fff;text-decoration:none;',
    'font:500 13px/1 -apple-system,sans-serif;border:none;cursor:pointer;',
    'transition:all .2s cubic-bezier(.175,.885,.32,1.275);}',
    '#hx-wa-btn:hover{background:#22c55e;transform:translateY(-1px);box-shadow:0 4px 14px rgba(37,211,102,.4);}',
    '#hx-wa-btn:active{transform:scale(.97);}',
    '#hx-wa-btn svg{width:18px;height:18px;fill:#fff;flex-shrink:0;}',
    '@media(max-width:420px){#hx-panel{right:12px;left:12px;width:auto;bottom:90px;}}',
  ].join('');
  document.head.appendChild(style);

  /* ── DOM ────────────────────────────────────────────────────────────── */
  var root = document.createElement('div');
  document.body.appendChild(root);

  /* Button */
  var btn = document.createElement('button');
  btn.id = 'hx-btn';
  btn.setAttribute('aria-label', 'Open AI advisor');
  btn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg><div class="hx-star">✶</div>';
  root.appendChild(btn);

  /* Panel */
  var panel = document.createElement('div');
  panel.id = 'hx-panel';
  panel.innerHTML = [
    '<div id="hx-header">',
    '<div class="hx-av"><svg viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.86 0-7-3.14-7-7s3.14-7 7-7 7 3.14 7 7-3.14 7-7 7zm1-11h-2v3H8v2h3v3h2v-3h3v-2h-3z"/></svg></div>',
    '<div class="hx-ht"><strong>Helix AI Advisor</strong><span>K-Beauty specialist</span></div>',
    '<div class="hx-dot-live"></div>',
    '<button id="hx-newchat" aria-label="New chat" title="New chat"><svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.5"/></svg></button>',
    '<button id="hx-close" aria-label="Close">\xd7</button>',
    '</div>',
    '<div id="hx-msgs">',
    '<div class="hx-welcome">',
    '<div class="hx-wi"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg></div>',
    '<h3>Hi! I\'m your K-Beauty advisor</h3>',
    '<p>Tell me your skin type and concerns — I\'ll find your perfect products.</p>',
    '</div>',
    '</div>',
    '<div id="hx-typing"><div class="hx-tb"><div class="hx-d"></div><div class="hx-d"></div><div class="hx-d"></div></div></div>',
    '<div id="hx-footer">',
    '<form id="hx-form">',
    '<textarea id="hx-inp" placeholder="Ask about your skin..." rows="1"></textarea>',
    '<button type="submit" id="hx-send" aria-label="Send"><svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg></button>',
    '</form>',
    '<div id="hx-wa-wrap" style="display:none">',
    '<div class="hx-or">or</div>',
    '<a id="hx-wa-btn" href="#" target="_blank" rel="noopener noreferrer">',
    '<svg viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z"/></svg>',
    'Chat on WhatsApp',
    '</a>',
    '</div>',
    '</div>',
  ].join('');
  root.appendChild(panel);

  var msgs   = panel.querySelector('#hx-msgs');
  var typing = panel.querySelector('#hx-typing');
  var form   = panel.querySelector('#hx-form');
  var inp    = panel.querySelector('#hx-inp');

  /* ── WooCommerce nonce ──────────────────────────────────────────────── */
  function ensureNonce() {
    if (wcNonce) return Promise.resolve(wcNonce);
    if (nonceProm) return nonceProm;
    nonceProm = fetch(_restRoot + 'wc/store/v1/cart', { credentials: 'include' })
      .then(function (r) {
        wcNonce = r.headers.get('Nonce') || r.headers.get('X-WC-Store-API-Nonce') || r.headers.get('nonce');
        nonceProm = null;
        return wcNonce;
      })
      .catch(function () { nonceProm = null; return null; });
    return nonceProm;
  }
  ensureNonce();

  /* ── Token ──────────────────────────────────────────────────────────── */
  function getToken() {
    var exp = parseInt(localStorage.getItem(LS_EXP) || '0', 10);
    if (exp > Date.now()) return Promise.resolve(localStorage.getItem(LS_TOK));
    return fetch(BASE + '/v1/widget/session', {
      method: 'POST',
      headers: { 'X-Helix-Tenant-Key': KEY, 'Content-Type': 'application/json' },
      body: '{}',
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        localStorage.setItem(LS_TOK, d.token);
        localStorage.setItem(LS_EXP, String(Date.now() + (d.expires_in - 60) * 1000));
        return d.token;
      });
  }

  /* ── Event tracking ─────────────────────────────────────────────────── */
  function track(eventType, meta) {
    try {
      fetch(BASE + '/v1/widget/track', {
        method: 'POST',
        headers: { 'X-Helix-Tenant-Key': KEY, 'Content-Type': 'application/json' },
        body: JSON.stringify({ event_type: eventType, conversation_id: convId || null, metadata: meta || {} }),
      });
    } catch (e) {}
  }

  /* ── Add to cart ────────────────────────────────────────────────────── */
  function _refreshCartCounter(knownFragments, knownHash) {
    var jq = window.jQuery;
    /* Apply any already-known fragments immediately for instant feedback */
    if (jq && knownFragments) {
      try {
        jq.each(knownFragments, function (sel, html) { jq(sel).replaceWith(html); });
        jq(document.body).trigger('added_to_cart', [knownFragments, knownHash || '']);
      } catch (e) {}
    }
    /* Always fetch fresh fragments from WooCommerce — this catches every
       theme-registered counter (header badge, mini-cart widget, etc.) */
    fetch(_wcAjaxUrl('get_refreshed_fragments'), { method: 'POST', credentials: 'include' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.fragments) return;
        var jq2 = window.jQuery;
        if (jq2) {
          try {
            jq2.each(d.fragments, function (sel, html) { jq2(sel).replaceWith(html); });
            jq2(document.body).trigger('updated_cart_totals');
          } catch (e) {}
        }
      })
      .catch(function () {});
  }

  function markAdded(cartBtn, platformId, fragments, cartHash) {
    cartBtn.classList.remove('hx-adding');
    cartBtn.classList.add('hx-added');
    cartBtn.textContent = '✓ Added to Cart';
    track('add_to_cart', { platform_id: String(platformId) });
    _refreshCartCounter(fragments || null, cartHash || null);
  }
  function markFailed(cartBtn, platformId) {
    cartBtn.classList.remove('hx-adding');
    cartBtn.textContent = 'View Product';
    cartBtn.onclick = function () { window.location.href = _wcAjaxUrl('add_to_cart').split('?')[0] + '?add-to-cart=' + platformId; };
  }

  function _ajaxAddToCart(platformId, cartBtn) {
    return fetch(_wcAjaxUrl('add_to_cart'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'include',
      body: 'add-to-cart=' + platformId + '&quantity=1&product_id=' + platformId,
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.fragments) {
          markAdded(cartBtn, platformId, d.fragments, d.cart_hash);
          return true;
        }
        return false;
      });
  }

  function addToCart(platformId, cartBtn) {
    cartBtn.classList.add('hx-adding');
    cartBtn.textContent = 'Adding…';

    ensureNonce()
      .then(function (nonce) {
        if (!nonce) return null;
        return fetch(_restRoot + 'wc/store/v1/cart/add-item', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Nonce': nonce },
          credentials: 'include',
          body: JSON.stringify({ id: parseInt(platformId, 10), quantity: 1 }),
        });
      })
      .then(function (r) {
        if (r && r.ok) {
          var n = r.headers.get('Nonce') || r.headers.get('nonce');
          if (n) wcNonce = n;
          /* Store API succeeded — trigger WC fragment refresh for cart counter */
          markAdded(cartBtn, platformId, null, null);
          return;
        }
        /* Store API unavailable or failed — use classic WC AJAX */
        return _ajaxAddToCart(platformId, cartBtn).then(function (ok) {
          if (!ok) markFailed(cartBtn, platformId);
        });
      })
      .catch(function () {
        _ajaxAddToCart(platformId, cartBtn)
          .then(function (ok) { if (!ok) markFailed(cartBtn, platformId); })
          .catch(function () { markFailed(cartBtn, platformId); });
      });
  }

  /* ── Helpers ────────────────────────────────────────────────────────── */
  function fmt(minor, currency) {
    try {
      return new Intl.NumberFormat('en-ZA', {
        style: 'currency', currency: currency,
        minimumFractionDigits: 0, maximumFractionDigits: 0,
      }).format(minor / 100);
    } catch (e) { return currency + '\xa0' + (minor / 100).toFixed(0); }
  }

  function makeCard(product, delay) {
    var card = document.createElement('div');
    card.className = 'hx-card';
    card.style.animationDelay = (delay * 70) + 'ms';

    var imgHtml = product.image_url
      ? '<img class="hx-cimg" src="' + product.image_url + '" alt="" loading="lazy">'
      : '<div class="hx-cph"><svg viewBox="0 0 24 24" fill="#7C3AED"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg></div>';

    card.innerHTML = imgHtml +
      '<div class="hx-cb">' +
        '<div class="hx-ct">' + product.title + '</div>' +
        '<div class="hx-cp">' + fmt(product.price_minor, product.currency) + '</div>' +
        '<button class="hx-catc">Add to Cart</button>' +
      '</div>';

    card.querySelector('.hx-catc').addEventListener('click', function () {
      addToCart(product.platform_id, this);
    });
    return card;
  }

  /* ── Text rendering ─────────────────────────────────────────────────── */
  function renderInline(raw) {
    return raw
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.+?)\*/g, '<em>$1</em>');
  }

  function bestMatch(line, products, used) {
    var best = -1, bestScore = 0;
    products.forEach(function (p, i) {
      if (used[i]) return;
      var words = p.title.toLowerCase().split(/\s+/).filter(function (w) { return w.length > 3; });
      var lline = line.toLowerCase();
      var score = words.reduce(function (n, w) { return n + (lline.indexOf(w) >= 0 ? 1 : 0); }, 0);
      if (score >= Math.min(2, words.length) && score > bestScore) { bestScore = score; best = i; }
    });
    return best;
  }

  function appendMsg(text, role, products, _silent) {
    var welcome = msgs.querySelector('.hx-welcome');
    if (welcome) welcome.style.display = 'none';
    if (!_silent) {
      _msgLog.push({ role: role, text: text, products: products || [] });
      try { localStorage.setItem(LS_MSGS, JSON.stringify(_msgLog)); } catch (e) {}
    }

    var row = document.createElement('div');
    row.className = 'hx-msg hx-' + role;

    var bubble = document.createElement('div');
    bubble.className = 'hx-bubble';

    if (role === 'user') {
      bubble.textContent = text;
    } else {
      var used = (products || []).map(function () { return false; });
      var cardDelay = 0;
      /* Split response into paragraphs */
      var paras = text.split(/\n+/).filter(function (s) { return s.trim(); });
      paras.forEach(function (para) {
        var block = document.createElement('div');
        block.className = 'hx-block';
        block.innerHTML = '<p>' + renderInline(para.trim()) + '</p>';
        bubble.appendChild(block);
        /* Inject matching product card immediately after this paragraph */
        if (products && products.length) {
          var idx = bestMatch(para, products, used);
          if (idx >= 0) {
            used[idx] = true;
            bubble.appendChild(makeCard(products[idx], cardDelay++));
          }
        }
      });
      /* Any unmatched products go at the end */
      if (products) {
        products.forEach(function (p, i) {
          if (!used[i]) bubble.appendChild(makeCard(p, cardDelay++));
        });
      }
    }

    row.appendChild(bubble);
    msgs.appendChild(row);
    msgs.scrollTop = msgs.scrollHeight;
  }

  function showTyping() { typing.classList.add('hx-show'); msgs.scrollTop = msgs.scrollHeight; }
  function hideTyping() { typing.classList.remove('hx-show'); }

  /* ── Send ───────────────────────────────────────────────────────────── */
  function send(query) {
    if (!query.trim()) return;
    appendMsg(query, 'user', null);
    inp.value = '';
    inp.style.height = 'auto';
    showTyping();

    getToken()
      .then(function (tok) {
        return fetch(BASE + '/v1/widget/chat', {
          method: 'POST',
          headers: { 'Authorization': 'Bearer ' + tok, 'Content-Type': 'application/json' },
          body: JSON.stringify({ query: query, customer_profile: {}, conversation_id: convId || undefined }),
        });
      })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        hideTyping();
        if (d.response) {
          convId = d.conversation_id;
          localStorage.setItem(LS_CONV, convId);
          track('message_sent', {});
          appendMsg(d.response, 'bot', d.products || []);
        } else {
          appendMsg(d.detail || 'Something went wrong. Please try again.', 'bot', []);
        }
      })
      .catch(function () {
        hideTyping();
        appendMsg('Could not reach Helix. Please try again.', 'bot', []);
      });
  }

  /* ── Events ─────────────────────────────────────────────────────────── */
  btn.addEventListener('click', function () {
    open = !open;
    panel.classList.toggle('hx-open', open);
    if (open) setTimeout(function () { inp.focus(); }, 360);
  });

  panel.querySelector('#hx-close').addEventListener('click', function () {
    open = false;
    panel.classList.remove('hx-open');
  });

  panel.querySelector('#hx-newchat').addEventListener('click', function () {
    convId = null;
    _msgLog = [];
    localStorage.removeItem(LS_CONV);
    localStorage.removeItem(LS_MSGS);
    msgs.innerHTML = [
      '<div class="hx-welcome">',
      '<div class="hx-wi"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg></div>',
      '<h3>Hi! I\'m your K-Beauty advisor</h3>',
      '<p>Tell me your skin type and concerns — I\'ll find your perfect products.</p>',
      '</div>',
    ].join('');
    inp.focus();
  });

  /* ── WhatsApp button ────────────────────────────────────────────────── */
  if (_waEnabled) {
    var waWrap = panel.querySelector('#hx-wa-wrap');
    var waBtn  = panel.querySelector('#hx-wa-btn');
    waWrap.style.display = 'block';
    waBtn.href = 'https://wa.me/' + _waNum + '?text=' + encodeURIComponent(_waMsg);
    waBtn.addEventListener('click', function () {
      track('whatsapp_click', { number: _waNum });
    });
  }

  /* ── Restore previous conversation ─────────────────────────────────── */
  try {
    var _stored = JSON.parse(localStorage.getItem(LS_MSGS) || '[]');
    if (_stored.length) {
      _stored.forEach(function (m) { appendMsg(m.text, m.role, m.products || [], true); });
      _msgLog = _stored;
    }
  } catch (e) {}

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    send(inp.value.trim());
  });

  inp.addEventListener('input', function () {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 96) + 'px';
  });

  inp.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      e.stopPropagation();
      send(inp.value.trim());
    }
  });
})();
""".strip()


class SessionResponse(BaseModel):
    token: str
    expires_in: int = 900


@router.post("/session", response_model=SessionResponse)
async def issue_session(
    tenant: Tenant = Depends(get_tenant),
) -> SessionResponse:
    settings = get_settings()
    token = issue_widget_token(tenant.id, settings.session_secret.get_secret_value())
    return SessionResponse(token=token)


class ChatRequest(BaseModel):
    query: str
    customer_id: str | None = None
    customer_profile: dict = {}
    conversation_id: str | None = None


class ProductCard(BaseModel):
    platform_id: str
    title: str
    price_minor: int
    currency: str
    image_url: str | None = None


class ChatResponse(BaseModel):
    response: str
    source: str
    products: list[ProductCard] = []
    products_referenced: list[str] = []
    conversation_id: str
    assistant_message_id: str


@dataclass
class PipelineResult:
    route: RouteResult
    conversation_id: UUID
    assistant_message_id: UUID
    product_cards: list[dict] = field(default_factory=list)


async def _run_chat_pipeline(
    body: "ChatRequest",
    tenant: Tenant,
    db: AsyncSession,
    endpoint: str,
) -> PipelineResult:
    settings = get_settings()
    pack = get_pack_for_tenant(tenant)

    query_vector = await embed_query(body.query, settings)
    product_rows = await vector_search_products(db, tenant.id, query_vector, limit=5)
    context_products = [
        {
            "platform_id": p.platform_id,
            "title": p.title,
            "price_minor": p.price_minor,
            "currency": p.currency,
            "images": p.images or [],
            "categories": p.categories or [],
            "domain_attributes": p.domain_attributes or {},
        }
        for p, _ in product_rows
    ]

    product_cards = [
        {
            "platform_id": p["platform_id"],
            "title": p["title"],
            "price_minor": p["price_minor"],
            "currency": p["currency"],
            "image_url": p["images"][0] if p["images"] else None,
        }
        for p in context_products
    ]

    merged_profile = body.customer_profile
    customer_uuid = None
    if body.customer_id:
        try:
            cid = UUID(body.customer_id)
            customer_uuid = cid
            customer = await get_customer_by_id(db, cid, tenant.id)
            if customer:
                merged_profile = {**(customer.profile or {}), **body.customer_profile}
        except ValueError:
            logger.warning("widget_chat_invalid_customer_id", customer_id=body.customer_id, endpoint=endpoint)

    conversation = None
    if body.conversation_id:
        try:
            conv_uuid = UUID(body.conversation_id)
            conversation = await get_conversation(db, conv_uuid, tenant.id)
        except ValueError:
            pass

    if conversation is None:
        conversation = await create_conversation(db, tenant.id, customer_uuid)

    prior_messages = await get_messages(db, conversation.id, tenant.id)
    conversation_history = [
        {"role": msg.role, "content": msg.content}
        for msg in prior_messages[-10:]
    ]

    result = await handle_query(
        query=body.query,
        customer_profile=merged_profile,
        context_products=context_products,
        tenant_id=tenant.id,
        pack=pack,
        settings=settings,
        db_session=db,
        conversation_history=conversation_history,
    )

    if result.cost_usd > 0:
        await create_usage_event(
            db, tenant.id, result.model,
            result.tokens_in, result.tokens_out,
            result.cost_usd, endpoint,
        )

    _user_msg, assistant_msg = await append_messages(
        db,
        conversation_id=conversation.id,
        tenant_id=tenant.id,
        user_content=body.query,
        assistant_content=result.response,
        source=result.source,
        products_referenced=result.products_referenced,
    )

    await db.commit()
    return PipelineResult(
        route=result,
        conversation_id=conversation.id,
        assistant_message_id=assistant_msg.id,
        product_cards=product_cards,
    )


@router.post("/chat", response_model=ChatResponse)
async def widget_chat(
    body: ChatRequest,
    tenant: Tenant = Depends(get_widget_tenant),
    db: AsyncSession = Depends(get_db),
) -> ChatResponse:
    pipeline = await _run_chat_pipeline(body, tenant, db, "/v1/widget/chat")
    return ChatResponse(
        response=pipeline.route.response,
        source=pipeline.route.source,
        products=[ProductCard(**p) for p in pipeline.product_cards],
        products_referenced=pipeline.route.products_referenced,
        conversation_id=str(pipeline.conversation_id),
        assistant_message_id=str(pipeline.assistant_message_id),
    )


@router.post("/chat/stream")
async def widget_chat_stream(
    body: ChatRequest,
    tenant: Tenant = Depends(get_widget_tenant),
    db: AsyncSession = Depends(get_db),
) -> StreamingResponse:
    pipeline = await _run_chat_pipeline(body, tenant, db, "/v1/widget/chat/stream")

    async def _events() -> AsyncGenerator[str, None]:
        yield f"data: {json.dumps({'type': 'token', 'content': pipeline.route.response})}\n\n"
        yield f"data: {json.dumps({'type': 'done', 'source': pipeline.route.source, 'conversation_id': str(pipeline.conversation_id), 'assistant_message_id': str(pipeline.assistant_message_id)})}\n\n"

    return StreamingResponse(_events(), media_type="text/event-stream")


class RoutineRequest(BaseModel):
    customer_profile: dict
    budget_minor: int | None = None


class RoutineStepOut(BaseModel):
    step: str
    product: dict


class RoutineResponse(BaseModel):
    routine: list[RoutineStepOut]
    conflicts: list[dict]
    cautions: list[dict]
    missing_steps: list[str]
    llm_augmented: bool


@router.post("/routine", response_model=RoutineResponse)
async def widget_routine(
    body: RoutineRequest,
    tenant: Tenant = Depends(get_widget_tenant),
    db: AsyncSession = Depends(get_db),
) -> RoutineResponse:
    settings = get_settings()
    pack = get_pack_for_tenant(tenant)

    skin_type = body.customer_profile.get("skin_type", "")
    concerns = " ".join(body.customer_profile.get("skin_concerns", []))
    search_query = f"{skin_type} {concerns} routine".strip()

    query_vector = await embed_query(search_query, settings)
    product_rows = await vector_search_products(db, tenant.id, query_vector, limit=20)

    products = []
    for p, _ in product_rows:
        if body.budget_minor and p.price_minor > body.budget_minor:
            continue
        products.append({
            "id": str(p.id),
            "platform_id": p.platform_id,
            "title": p.title,
            "price_minor": p.price_minor,
            "currency": p.currency,
            "domain_attributes": p.domain_attributes or {},
        })

    result = build_routine(products, pack)

    await db.commit()

    return RoutineResponse(
        routine=[RoutineStepOut(**s) for s in result.steps],
        conflicts=result.conflicts,
        cautions=result.cautions,
        missing_steps=result.missing_steps,
        llm_augmented=result.llm_augmented,
    )


class FeedbackRequest(BaseModel):
    feedback: Literal["thumbs_up", "thumbs_down"]


class FeedbackResponse(BaseModel):
    message_id: str
    feedback: str


@router.post("/conversations/{message_id}/feedback", response_model=FeedbackResponse)
async def submit_message_feedback(
    message_id: UUID,
    body: FeedbackRequest,
    tenant: Tenant = Depends(get_widget_tenant),
    db: AsyncSession = Depends(get_db),
) -> FeedbackResponse:
    msg = await set_message_feedback(db, message_id, tenant.id, body.feedback)
    if msg is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Message not found")
    await db.commit()
    return FeedbackResponse(message_id=str(message_id), feedback=body.feedback)


class TrackRequest(BaseModel):
    event_type: str
    conversation_id: str | None = None
    metadata: dict = {}


@router.post("/track", status_code=status.HTTP_204_NO_CONTENT)
async def track_event(
    body: TrackRequest,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> None:
    conv_id = None
    if body.conversation_id:
        try:
            conv_id = UUID(body.conversation_id)
        except ValueError:
            pass
    await create_widget_event(
        db,
        tenant_id=tenant.id,
        event_type=body.event_type,
        conversation_id=conv_id,
        metadata=body.metadata,
    )


@router.get("/embed.js", include_in_schema=False)
async def widget_embed_js() -> Response:
    return Response(content=_EMBED_JS, media_type="application/javascript")


@router.get("/demo.html", include_in_schema=False)
async def widget_demo_html() -> Response:
    settings = get_settings()
    if settings.environment != "development":
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    html = """<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Helix Widget Demo</title></head>
<body style="font-family:sans-serif;padding:40px;background:#f9f9f9;">
<h1>Helix Widget Demo</h1>
<p>Set your tenant public key in the script src below:</p>
<script src="/v1/widget/embed.js?key=YOUR_PUBLIC_KEY"></script>
</body>
</html>"""
    return Response(content=html, media_type="text/html")
