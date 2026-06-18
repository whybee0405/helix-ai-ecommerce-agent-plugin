import json
from collections.abc import AsyncGenerator
from dataclasses import dataclass, field
from typing import Literal
from uuid import UUID

import structlog
from fastapi import APIRouter, Depends, HTTPException, Request, Response, status
from fastapi.responses import StreamingResponse
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.auth.tokens import issue_widget_token
from helix.api.deps import get_db, get_tenant, get_widget_tenant
from helix.config import get_settings
from helix.db.models import Lead
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
  var _ctaType   = 'cart'; /* overridden to 'enquire' for automotive pack */

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
    '<div class="hx-ht"><strong id="hx-brand-name">Helix AI</strong><span id="hx-brand-tagline">Your AI shopping assistant</span></div>',
    '<div class="hx-dot-live"></div>',
    '<button id="hx-newchat" aria-label="New chat" title="New chat"><svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.5"/></svg></button>',
    '<button id="hx-close" aria-label="Close">\xd7</button>',
    '</div>',
    '<div id="hx-msgs">',
    '<div class="hx-welcome">',
    '<div class="hx-wi"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg></div>',
    '<h3 id="hx-greeting-h">Hi!</h3>',
    '<p id="hx-greeting-p">Tell me what you\'re looking for.</p>',
    '</div>',
    '</div>',
    '<div id="hx-typing"><div class="hx-tb"><div class="hx-d"></div><div class="hx-d"></div><div class="hx-d"></div></div></div>',
    '<div id="hx-footer">',
    '<form id="hx-form">',
    '<textarea id="hx-inp" placeholder="Ask anything…" rows="1"></textarea>',
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

  /* ── Branding application ───────────────────────────────────────────── */
  function applyEmbedBranding(B) {
    if (!B) return;
    var nm = panel.querySelector('#hx-brand-name');
    var tg = panel.querySelector('#hx-brand-tagline');
    var gh = panel.querySelector('#hx-greeting-h');
    var gp = panel.querySelector('#hx-greeting-p');
    if (nm && B.brand_name) nm.textContent = B.brand_name;
    if (tg && B.tagline) tg.textContent = B.tagline;
    if (B.greeting && (gh || gp)) {
      var sub = window.HelixBranding.substitute(B.greeting, B);
      var parts = sub.split(/\n+/);
      if (gh) gh.textContent = parts[0] || 'Hi!';
      if (gp) gp.textContent = parts.slice(1).join(' ') || sub;
    }
    if (inp && B.chat_placeholder) inp.placeholder = B.chat_placeholder;
  }
  window.HelixBranding.load(BASE, KEY).then(function (B) {
    applyEmbedBranding(B);
    if (B && B.pack_cta_type) _ctaType = B.pack_cta_type;
  });

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

    var ctaLabel = _ctaType === 'enquire' ? 'Enquire Now' : 'Add to Cart';
    card.innerHTML = imgHtml +
      '<div class="hx-cb">' +
        '<div class="hx-ct">' + product.title + '</div>' +
        '<div class="hx-cp">' + fmt(product.price_minor, product.currency) + '</div>' +
        '<button class="hx-catc">' + ctaLabel + '</button>' +
      '</div>';

    card.querySelector('.hx-catc').addEventListener('click', function () {
      if (_ctaType === 'enquire') {
        openEnquiryForm(product);
      } else {
        addToCart(product.platform_id, this);
      }
    });
    return card;
  }

  function openEnquiryForm(product) {
    var formId = 'hx-eq-' + product.platform_id;
    if (document.getElementById(formId)) return; /* already open */
    var wrap = document.createElement('div');
    wrap.id = formId;
    wrap.className = 'hx-msg hx-bot';
    wrap.innerHTML =
      '<div class="hx-eq-title">Enquire about <strong>' + esc(product.title) + '</strong></div>' +
      '<div class="hx-eq-fields">' +
        '<input class="hx-eq-inp" name="name"  placeholder="Your name *" required>' +
        '<input class="hx-eq-inp" name="phone" placeholder="Phone number *" type="tel">' +
        '<input class="hx-eq-inp" name="email" placeholder="Email address" type="email">' +
        '<input class="hx-eq-inp" name="time"  placeholder="Best time to call">' +
      '</div>' +
      '<button class="hx-eq-send">Send Enquiry</button>' +
      '<div class="hx-eq-ok" style="display:none">Thanks! We\'ll be in touch shortly.</div>';
    msgs.appendChild(wrap);
    msgs.scrollTop = msgs.scrollHeight;

    wrap.querySelector('.hx-eq-send').addEventListener('click', function () {
      var name  = wrap.querySelector('[name=name]').value.trim();
      var phone = wrap.querySelector('[name=phone]').value.trim();
      var email = wrap.querySelector('[name=email]').value.trim();
      var time  = wrap.querySelector('[name=time]').value.trim();
      if (!name) { wrap.querySelector('[name=name]').focus(); return; }
      if (!phone && !email) { wrap.querySelector('[name=phone]').focus(); return; }
      var btn = this; btn.disabled = true; btn.textContent = 'Sending…';
      getToken().then(function (tok) {
        return fetch(BASE + '/v1/widget/capture-lead', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + tok },
          body: JSON.stringify({
            product_platform_id: product.platform_id,
            name: name, phone: phone || null,
            email: email || null, preferred_contact_time: time || null,
          }),
        });
      }).then(function () {
        wrap.querySelector('.hx-eq-fields').style.display = 'none';
        btn.style.display = 'none';
        wrap.querySelector('.hx-eq-ok').style.display = 'block';
      }).catch(function () {
        btn.disabled = false; btn.textContent = 'Send Enquiry';
      });
    });
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
        appendMsg('Could not reach ' + window.HelixBranding.substitute('{{brand_short_name}}') + '. Please try again.', 'bot', []);
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
    from helix.branding import get_effective_branding
    branding = await get_effective_branding(tenant)

    # ── Budget / rate-limit guard ─────────────────────────────────────────
    import redis.asyncio as _aioredis
    from helix.llm.budget import BudgetMode, acquire_token, check_budget
    _redis = _aioredis.from_url(str(settings.redis_url), decode_responses=True)
    try:
        if not await acquire_token(_redis, tenant.id):
            raise HTTPException(status_code=429, detail="Rate limit exceeded; please slow down.")
        budget = await check_budget(
            db, _redis, tenant.id, tenant.tier, tenant.daily_budget_usd
        )
    finally:
        await _redis.aclose()

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
    raw_history = [
        {"role": msg.role, "content": msg.content}
        for msg in prior_messages
    ]
    from helix.llm.history import HistoryCompressor
    compressor = HistoryCompressor(settings)
    try:
        conversation_history = await compressor.compress(conversation.id, raw_history)
    finally:
        await compressor.aclose()

    result = await handle_query(
        query=body.query,
        customer_profile=merged_profile,
        context_products=context_products,
        tenant_id=tenant.id,
        pack=pack,
        settings=settings,
        db_session=db,
        conversation_history=conversation_history,
        branding=branding,
        branding_version=tenant.branding_version,
        budget_mode=budget.mode.value,
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
    request: Request,
    tenant: Tenant = Depends(get_widget_tenant),
    db: AsyncSession = Depends(get_db),
) -> StreamingResponse:
    pipeline = await _run_chat_pipeline(body, tenant, db, "/v1/widget/chat/stream")

    async def _events() -> AsyncGenerator[str, None]:
        text = pipeline.route.response
        # naive chunking on word boundaries — replace with real Anthropic stream when
        # the gateway returns mid-token deltas (see LLMGateway.stream_text).
        idx = 0
        chunk = 40
        while idx < len(text):
            if await request.is_disconnected():
                return
            piece = text[idx : idx + chunk]
            yield f"data: {json.dumps({'type': 'token', 'content': piece})}\n\n"
            idx += chunk
        yield "data: " + json.dumps({
            "type": "products",
            "items": pipeline.product_cards,
            "referenced": pipeline.route.products_referenced,
        }) + "\n\n"
        yield "data: " + json.dumps({
            "type": "done",
            "source": pipeline.route.source,
            "conversation_id": str(pipeline.conversation_id),
            "assistant_message_id": str(pipeline.assistant_message_id),
        }) + "\n\n"

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


class LeadCaptureRequest(BaseModel):
    session_id: str | None = None
    product_platform_id: str | None = None
    name: str | None = None
    email: str | None = None
    phone: str | None = None
    preferred_contact_time: str | None = None
    source: str = "car_enquiry"


class LeadCaptureResponse(BaseModel):
    lead_id: str
    message: str


@router.post("/capture-lead", response_model=LeadCaptureResponse)
async def capture_lead(
    body: LeadCaptureRequest,
    tenant: Tenant = Depends(get_widget_tenant),
    db: AsyncSession = Depends(get_db),
) -> LeadCaptureResponse:
    if not body.email and not body.phone:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail="At least one of email or phone is required.",
        )
    from uuid import uuid4 as _uuid4

    lead = Lead(
        tenant_id=tenant.id,
        session_id=body.session_id,
        product_platform_id=body.product_platform_id,
        name=body.name,
        email=body.email,
        phone=body.phone,
        preferred_contact_time=body.preferred_contact_time,
        source=body.source,
    )
    db.add(lead)
    await db.commit()
    await db.refresh(lead)

    webhook_url = (tenant.branding or {}).get("lead_webhook_url") or None
    if webhook_url:
        import httpx as _httpx

        payload = {
            "lead_id": str(lead.id),
            "tenant_id": str(tenant.id),
            "source": lead.source,
            "name": lead.name,
            "email": lead.email,
            "phone": lead.phone,
            "preferred_contact_time": lead.preferred_contact_time,
            "product_platform_id": lead.product_platform_id,
            "session_id": lead.session_id,
        }
        try:
            async with _httpx.AsyncClient(timeout=5.0) as client:
                await client.post(webhook_url, json=payload)
        except Exception:
            pass

    return LeadCaptureResponse(
        lead_id=str(lead.id),
        message="Enquiry received.",
    )


_SEARCH_BAR_JS = r"""
(function () {
  var script = document.currentScript;
  var src    = (script && script.src) ? new URL(script.src) : null;
  var KEY    = src ? (src.searchParams.get('key') || src.searchParams.get('tenant_key')) : null;
  if (!KEY) { console.warn('[Helix] Missing key param'); return; }

  var BASE       = src ? src.origin : '';
  var STORE_BASE = window.location.origin;
  var LS_TOK     = 'hx_tok_' + KEY;
  var LS_EXP     = 'hx_exp_' + KEY;
  var LS_CONV    = 'hx_sb_conv_' + KEY;
  var convId     = localStorage.getItem(LS_CONV) || null;
  var wcNonce    = null;
  var nonceProm  = null;
  var aiMode     = true;
  var isLoading  = false;
  var angle      = 0;
  var rafId      = null;
  var pulseVal   = 0.5;
  var pulseDir   = 1;
  var _lsTimer   = null;
  var _lsCtrl    = null;
  var AJAX_URL   = (window.helixConfig && window.helixConfig.ajaxUrl) || null;
  var _sbAnim    = !(window.helixConfig && window.helixConfig.sbAnim    === false);
  var _flyCart   = !(window.helixConfig && window.helixConfig.flyCart   === false);
  var _cardModal = !(window.helixConfig && window.helixConfig.cardModal === false);
  var _ctaType   = 'cart'; /* overridden to 'enquire' for automotive pack */

  var _wcp = window.wc_add_to_cart_params || window.woocommerce_params || window.wc_cart_fragments_params || {};
  var _wcAjaxBase = _wcp.wc_ajax_url || (STORE_BASE + '/?wc-ajax=%%endpoint%%');
  function _wcAjaxUrl(ep) { return _wcAjaxBase.replace('%%endpoint%%', ep); }

  var _apiLink = document.head.querySelector('link[rel="https://api.w.org/"]');
  var _restRoot = (_apiLink && _apiLink.href)
    || (window.wpApiSettings && window.wpApiSettings.root)
    || (STORE_BASE + '/wp-json/');
  _restRoot = _restRoot.replace(/\/?$/, '/');

  /* ── Styles ──────────────────────────────────────────────────────────── */
  var S = document.createElement('style');
  S.textContent = [
    /* Section wrapper — no z-index so nav menus and sticky header layer above it */
    '#hx-sb-section{width:100%;box-sizing:border-box;padding:32px 20px 24px;',
    'background:linear-gradient(180deg,transparent 0%,rgba(124,58,237,.05) 50%,transparent 100%);',
    'position:relative;}',

    /* Bar container — no z-index, avoids creating a stacking context */
    '#hx-sb{position:relative;width:100%;max-width:860px;margin:0 auto;',
    'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}',

    '#hx-sb-headline{text-align:center;margin:0 0 18px;',
    'font:600 15px/1 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;',
    'color:#6B6B6F;letter-spacing:-.01em;}',

    '#hx-sb-outer{position:relative;border-radius:50px;}',

    '#hx-sb-ring{position:absolute;inset:-3px;border-radius:53px;',
    'opacity:0;transition:opacity .6s ease;z-index:0;pointer-events:none;}',

    '#hx-sb-glow{position:absolute;inset:-16px;border-radius:66px;',
    'opacity:0;transition:opacity .6s ease;z-index:0;filter:blur(20px);pointer-events:none;}',

    '#hx-sb-inner{position:relative;z-index:1;display:flex;align-items:center;',
    'background:#fff;border-radius:50px;height:60px;',
    'border:1.5px solid rgba(0,0,0,.1);',
    'box-shadow:0 2px 20px rgba(0,0,0,.08);',
    'transition:border-color .3s,box-shadow .3s;}',
    '#hx-sb-inner.ai{border-color:transparent;box-shadow:0 4px 24px rgba(0,0,0,.1);}',

    '#hx-sb-toggle{display:flex;align-items:center;height:100%;',
    'padding:0 4px 0 8px;gap:1px;flex-shrink:0;',
    'border-right:1px solid rgba(0,0,0,.08);margin-right:2px;}',

    '#hx-sb-ai{all:unset;cursor:pointer;padding:7px 14px;border-radius:40px;',
    'font-size:13px;font-weight:700;letter-spacing:.01em;',
    'display:flex;align-items:center;gap:5px;',
    'transition:all .25s;white-space:nowrap;color:#6B6B6F;}',
    '#hx-sb-ai.on{background:linear-gradient(135deg,#7C3AED,#4F46E5);color:#fff;',
    'box-shadow:0 2px 10px rgba(124,58,237,.4);}',
    '#hx-sb-ai:not(.on):hover{background:rgba(0,0,0,.05);color:#1C1C1E;}',

    '.hx-sb-spark{font-size:15px;}',
    '#hx-sb-ai.on .hx-sb-spark{animation:hx-sb-twirl 2.5s linear infinite;}',
    '@keyframes hx-sb-twirl{0%{transform:rotate(0deg)scale(1);}',
    '50%{transform:rotate(180deg)scale(1.2);}100%{transform:rotate(360deg)scale(1);}}',

    '#hx-sb-norm{all:unset;cursor:pointer;padding:7px 12px;border-radius:40px;',
    'color:#6B6B6F;transition:all .2s;display:flex;align-items:center;}',
    '#hx-sb-norm.on{color:#1C1C1E;background:rgba(0,0,0,.06);}',
    '#hx-sb-norm:hover{background:rgba(0,0,0,.05);color:#1C1C1E;}',
    '#hx-sb-norm svg{width:17px;height:17px;fill:currentColor;}',

    '#hx-sb-inp{flex:1;border:none;outline:none;font-size:16px;',
    'color:#1C1C1E;background:transparent;padding:0 14px;min-width:0;',
    'caret-color:#7C3AED;}',
    '#hx-sb-inp::placeholder{color:#AEAEB2;}',

    '#hx-sb-go{all:unset;cursor:pointer;margin:8px;width:44px;height:44px;',
    'border-radius:50%;display:flex;align-items:center;justify-content:center;',
    'flex-shrink:0;transition:all .22s cubic-bezier(.175,.885,.32,1.275);',
    'background:linear-gradient(135deg,#7C3AED,#4F46E5);',
    'box-shadow:0 2px 10px rgba(124,58,237,.4);}',
    '#hx-sb-go:hover{transform:scale(1.1);box-shadow:0 5px 22px rgba(124,58,237,.58);}',
    '#hx-sb-go:active{transform:scale(.92);}',
    '#hx-sb-go svg{width:20px;height:20px;fill:#fff;pointer-events:none;}',

    /* Panel at high z-index so it overlaps content, but no z-index on ancestors
       so the WP sticky nav and mobile menu overlays still sit above the section */
    '#hx-sb-panel{position:absolute;top:calc(100% + 14px);left:0;right:0;',
    'background:rgba(255,255,255,.97);backdrop-filter:blur(24px) saturate(180%);',
    '-webkit-backdrop-filter:blur(24px) saturate(180%);',
    'border-radius:20px;border:1px solid rgba(0,0,0,.08);',
    'box-shadow:0 24px 64px rgba(0,0,0,.15),0 0 0 1px rgba(0,0,0,.04);',
    'display:none;z-index:9999;overflow:hidden;',
    'animation:hx-sb-din .32s cubic-bezier(.175,.885,.32,1.275) both;}',
    '@keyframes hx-sb-din{from{opacity:0;transform:translateY(-10px)scale(.98);}',
    'to{opacity:1;transform:translateY(0)scale(1);}}',

    '#hx-sb-ph{padding:13px 16px 11px;display:flex;align-items:center;gap:8px;',
    'border-bottom:1px solid rgba(0,0,0,.06);}',
    '.hx-sb-pav{width:28px;height:28px;border-radius:50%;flex-shrink:0;',
    'background:linear-gradient(135deg,#7C3AED,#4F46E5);',
    'display:flex;align-items:center;justify-content:center;}',
    '.hx-sb-pav svg{width:14px;height:14px;fill:#fff;}',
    '#hx-sb-ph strong{flex:1;font:600 13.5px/1 -apple-system,sans-serif;color:#1C1C1E;}',
    '.hx-sb-live{width:7px;height:7px;border-radius:50%;background:#34C759;flex-shrink:0;',
    'box-shadow:0 0 0 2px rgba(52,199,89,.22);animation:hx-sb-dot 2s ease infinite;}',
    '@keyframes hx-sb-dot{0%,100%{opacity:1;}50%{opacity:.3;}}',
    '#hx-sb-pclose{all:unset;cursor:pointer;width:26px;height:26px;border-radius:50%;',
    'background:rgba(0,0,0,.06);display:flex;align-items:center;justify-content:center;',
    'font-size:17px;color:#6B6B6F;line-height:1;transition:background .15s;}',
    '#hx-sb-pclose:hover{background:rgba(0,0,0,.12);}',

    '#hx-sb-body{padding:14px 16px 10px;max-height:400px;overflow-y:auto;',
    'scrollbar-width:thin;scrollbar-color:rgba(0,0,0,.1) transparent;}',
    '#hx-sb-body::-webkit-scrollbar{width:3px;}',
    '#hx-sb-body::-webkit-scrollbar-thumb{background:rgba(0,0,0,.1);border-radius:2px;}',

    '#hx-sb-typing{display:flex;gap:5px;align-items:center;padding:10px 2px;}',
    '.hx-sbd{width:7px;height:7px;border-radius:50%;background:#C7B8F5;',
    'animation:hx-sbdb 1.2s ease infinite;}',
    '.hx-sbd:nth-child(2){animation-delay:.18s;}.hx-sbd:nth-child(3){animation-delay:.36s;}',
    '@keyframes hx-sbdb{0%,60%,100%{transform:translateY(0);opacity:.4;}',
    '30%{transform:translateY(-5px);opacity:1;}}',

    '.hx-sb-blk{background:rgba(242,242,247,.9);padding:10px 14px;border-radius:14px;',
    'margin-bottom:8px;font:400 13.5px/1.65 -apple-system,sans-serif;color:#1C1C1E;}',
    '.hx-sb-blk strong{font-weight:600;}.hx-sb-blk em{font-style:italic;}',

    '.hx-sb-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));',
    'gap:8px;margin-top:10px;padding-bottom:2px;}',
    '.hx-sb-card{background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:14px;',
    'overflow:hidden;transition:transform .22s cubic-bezier(.175,.885,.32,1.275),box-shadow .22s;',
    'animation:hx-sb-cin .38s cubic-bezier(.175,.885,.32,1.275) both;}',
    '@keyframes hx-sb-cin{from{opacity:0;transform:scale(.95);}to{opacity:1;transform:scale(1);}}',
    '.hx-sb-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.1);}',
    '.hx-sb-cimg{width:100%;aspect-ratio:1/1;object-fit:cover;display:block;',
    'background:linear-gradient(135deg,#f0edff,#ede9fe);}',
    '.hx-sb-cph{width:100%;aspect-ratio:1/1;background:linear-gradient(135deg,#f0edff,#ede9fe);',
    'display:flex;align-items:center;justify-content:center;}',
    '.hx-sb-cph svg{width:36px;height:36px;opacity:.18;}',
    '.hx-sb-ci{padding:9px;}',
    '.hx-sb-ct{font:500 12px/1.35 -apple-system,sans-serif;color:#1C1C1E;',
    'display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:4px;}',
    '.hx-sb-cp{font:700 12.5px/1 -apple-system,sans-serif;margin-bottom:7px;',
    'background:linear-gradient(135deg,#7C3AED,#4F46E5);',
    '-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}',
    '.hx-sb-atc{display:block;border:none;width:100%;box-sizing:border-box;',
    'padding:7px 0;border-radius:9px;cursor:pointer;',
    'background:linear-gradient(135deg,#7C3AED,#4F46E5);color:#fff;',
    'font:600 11px/1 -apple-system,sans-serif;text-align:center;',
    'transition:all .2s;box-shadow:0 2px 6px rgba(124,58,237,.3);}',
    '.hx-sb-atc:hover{transform:scale(1.03);box-shadow:0 4px 14px rgba(124,58,237,.45);}',
    '.hx-sb-atc.adding{opacity:.6;pointer-events:none;}',
    '.hx-sb-atc.added{background:linear-gradient(135deg,#34C759,#30D158)!important;',
    'box-shadow:0 2px 8px rgba(52,199,89,.4)!important;}',

    '#hx-sb-pf{padding:10px 16px 12px;border-top:1px solid rgba(0,0,0,.06);',
    'display:flex;justify-content:flex-end;}',
    '#hx-sb-fc{all:unset;cursor:pointer;font:500 12px/1 -apple-system,sans-serif;',
    'color:#7C3AED;padding:6px 12px;border-radius:8px;',
    'display:flex;align-items:center;gap:5px;transition:background .15s;}',
    '#hx-sb-fc:hover{background:rgba(124,58,237,.08);}',
    '#hx-sb-fc svg{width:13px;height:13px;stroke:#7C3AED;fill:none;',
    'stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round;}',

    /* Suggestion chips */
    '#hx-sb-chips{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-top:16px;}',
    '.hx-sb-chip{all:unset;cursor:pointer;padding:7px 16px;border-radius:20px;',
    'font:500 12.5px/1 -apple-system,sans-serif;color:#6B6B6F;',
    'background:rgba(124,58,237,.06);border:1px solid rgba(124,58,237,.15);',
    'transition:all .2s;white-space:nowrap;}',
    '.hx-sb-chip:hover{background:rgba(124,58,237,.12);color:#7C3AED;',
    'border-color:rgba(124,58,237,.3);}',

    /* Normal-mode live search result rows */
    '.hx-sb-live-row{display:flex;align-items:center;gap:10px;padding:10px 14px;',
    'text-decoration:none;color:inherit;border-bottom:1px solid rgba(0,0,0,.05);',
    'transition:background .12s;outline:none;}',
    '.hx-sb-live-row:hover,.hx-sb-live-row:focus{background:rgba(124,58,237,.04);}',
    '.hx-sb-limg{width:40px;height:40px;object-fit:cover;border-radius:6px;flex-shrink:0;background:#f0edff;}',
    '.hx-sb-linfo{display:flex;flex-direction:column;gap:2px;flex:1;min-width:0;}',
    '.hx-sb-lname{font:500 13px/1.3 -apple-system,sans-serif;color:#1C1C1E;',
    'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
    '.hx-sb-lcat{font:400 11px/1 -apple-system,sans-serif;color:#8E8E93;}',
    '.hx-sb-lprice{font:700 12px/1 -apple-system,sans-serif;color:#7C3AED;margin-top:2px;}',
    '.hx-sb-lall{display:block;padding:8px 14px;font:500 12px/1 -apple-system,sans-serif;',
    'color:#7C3AED;text-align:center;text-decoration:none;',
    'border-top:1px solid rgba(0,0,0,.06);background:rgba(0,0,0,.02);',
    'transition:background .12s;}',
    '.hx-sb-lall:hover{background:rgba(124,58,237,.06);}',

    /* ── Inline AI results — rendered in page flow, not in dropdown ───── */
    '#hx-sb-results{width:100%;max-width:860px;margin:0 auto;',
    'padding:0 20px 32px;display:none;box-sizing:border-box;}',
    '#hx-sb-results.hx-sr-show{display:block;}',
    '.hx-sr-blk{background:rgba(242,242,247,.9);padding:10px 14px;border-radius:14px;',
    'margin-bottom:10px;font:400 13.5px/1.65 -apple-system,sans-serif;color:#1C1C1E;}',
    '.hx-sr-blk strong{font-weight:600;}.hx-sr-blk em{font-style:italic;}',
    '.hx-sr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));',
    'gap:16px;margin-top:10px;}',
    '.hx-sr-card{background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;',
    'overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.07);',
    'transition:transform .22s cubic-bezier(.175,.885,.32,1.275),box-shadow .22s;',
    'animation:hx-sr-fly .58s cubic-bezier(.175,.885,.32,1.275) both,',
    'hx-sr-rainbow-ring 1.8s ease-out both;}',
    '.hx-sr-card:hover{transform:translateY(-4px);box-shadow:0 12px 32px rgba(0,0,0,.12);}',
    '.hx-sr-cimg{width:100%;aspect-ratio:1/1;object-fit:cover;display:block;',
    'background:linear-gradient(135deg,#f0edff,#ede9fe);}',
    '.hx-sr-cph{width:100%;aspect-ratio:1/1;background:linear-gradient(135deg,#f0edff,#ede9fe);',
    'display:flex;align-items:center;justify-content:center;}',
    '.hx-sr-cph svg{width:36px;height:36px;opacity:.18;}',
    '.hx-sr-ci{padding:12px;}',
    '.hx-sr-ct{font:500 13px/1.35 -apple-system,sans-serif;color:#1C1C1E;',
    'display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:6px;}',
    '.hx-sr-cp{font:700 13.5px/1 -apple-system,sans-serif;margin-bottom:10px;',
    'background:linear-gradient(135deg,#7C3AED,#4F46E5);',
    '-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}',
    '.hx-sr-atc{display:block;border:none;width:100%;box-sizing:border-box;',
    'padding:9px 0;border-radius:10px;cursor:pointer;',
    'background:linear-gradient(135deg,#7C3AED,#4F46E5);color:#fff;',
    'font:600 12px/1 -apple-system,sans-serif;text-align:center;',
    'transition:all .2s;box-shadow:0 2px 8px rgba(124,58,237,.3);}',
    '.hx-sr-atc:hover{transform:scale(1.03);box-shadow:0 4px 16px rgba(124,58,237,.48);}',
    '.hx-sr-atc.adding{opacity:.6;pointer-events:none;}',
    '.hx-sr-atc.added{background:linear-gradient(135deg,#34C759,#30D158)!important;',
    '-webkit-text-fill-color:#fff!important;}',
    '.hx-sr-footer{display:flex;justify-content:flex-end;margin-top:10px;}',
    '.hx-sr-oc{all:unset;cursor:pointer;font:500 12px/1 -apple-system,sans-serif;',
    'color:#7C3AED;padding:6px 12px;border-radius:8px;',
    'display:inline-flex;align-items:center;gap:5px;transition:background .15s;}',
    '.hx-sr-oc:hover{background:rgba(124,58,237,.08);}',
    '.hx-sr-oc svg{width:13px;height:13px;stroke:#7C3AED;fill:none;',
    'stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round;}',
    /* The magic: cards start at search-bar position (above, tiny, blurred) */
    /* and fly down into their grid slots one by one */
    '@keyframes hx-sr-fly{',
    '0%{opacity:0;transform:translateY(-140px) scale(0.04) rotate(22deg);filter:blur(18px);}',
    '42%{opacity:1;filter:blur(0);}',
    '64%{transform:translateY(14px) scale(1.12) rotate(-2deg);}',
    '82%{transform:translateY(-5px) scale(0.97) rotate(0.8deg);}',
    '100%{opacity:1;transform:translateY(0) scale(1) rotate(0deg);filter:blur(0);}}',
    /* Rainbow ring sweeps through card border as it lands — matches search-bar ring palette */
    '@keyframes hx-sr-rainbow-ring{',
    '0%  {box-shadow:0 0 0 3px rgba(255,110,180,.95),0 6px 32px rgba(255,110,180,.4);}',
    '16% {box-shadow:0 0 0 3px rgba(255,214,66,.95), 0 6px 32px rgba(255,214,66,.4);}',
    '33% {box-shadow:0 0 0 3px rgba(127,255,212,.95),0 6px 32px rgba(127,255,212,.35);}',
    '50% {box-shadow:0 0 0 3px rgba(135,206,250,.95),0 6px 32px rgba(135,206,250,.4);}',
    '66% {box-shadow:0 0 0 3px rgba(201,177,255,.95),0 6px 32px rgba(201,177,255,.4);}',
    '82% {box-shadow:0 0 0 3px rgba(255,110,180,.95),0 6px 32px rgba(255,110,180,.4);}',
    '100%{box-shadow:0 2px 12px rgba(0,0,0,.07);}}',
    /* Inline row card — horizontal, sits directly below matched recommendation text */
    '.hx-sr-row{display:flex;gap:0;background:#fff;border:1px solid rgba(0,0,0,.08);',
    'border-radius:16px;overflow:hidden;margin-bottom:10px;cursor:pointer;',
    'box-shadow:0 2px 12px rgba(0,0,0,.07);',
    'transition:transform .22s cubic-bezier(.175,.885,.32,1.275),box-shadow .22s;',
    'animation:hx-sr-fly .58s cubic-bezier(.175,.885,.32,1.275) both,',
    'hx-sr-rainbow-ring 1.8s ease-out both;}',
    '.hx-sr-row:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.1);}',
    '.hx-sr-row-img{width:88px;height:88px;object-fit:cover;flex-shrink:0;display:block;',
    'background:linear-gradient(135deg,#f0edff,#ede9fe);}',
    '.hx-sr-row-iph{width:88px;height:88px;flex-shrink:0;',
    'background:linear-gradient(135deg,#f0edff,#ede9fe);',
    'display:flex;align-items:center;justify-content:center;}',
    '.hx-sr-row-iph svg{width:28px;height:28px;opacity:.18;}',
    '.hx-sr-row-ci{flex:1;min-width:0;display:flex;align-items:center;gap:12px;padding:12px 14px;}',
    '.hx-sr-row-info{flex:1;min-width:0;}',
    '.hx-sr-row-ct{font:500 13.5px/1.3 -apple-system,sans-serif;color:#1C1C1E;',
    'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:4px;}',
    '.hx-sr-row-cp{font:700 13px/1 -apple-system,sans-serif;',
    'background:linear-gradient(135deg,#7C3AED,#4F46E5);',
    '-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}',
    '.hx-sr-row-atc{all:unset;cursor:pointer;flex-shrink:0;padding:9px 14px;border-radius:10px;',
    'background:linear-gradient(135deg,#7C3AED,#4F46E5);color:#fff;',
    'font:600 12px/1 -apple-system,sans-serif;text-align:center;',
    'transition:all .2s;box-shadow:0 2px 8px rgba(124,58,237,.3);}',
    '.hx-sr-row-atc:hover{transform:scale(1.05);box-shadow:0 4px 16px rgba(124,58,237,.48);}',
    '.hx-sr-row-atc.adding{opacity:.6;pointer-events:none;}',
    '.hx-sr-row-atc.added{background:linear-gradient(135deg,#34C759,#30D158)!important;',
    '-webkit-text-fill-color:#fff!important;}',
    /* Label above remaining grid */
    '.hx-sr-label{font:600 11px/1 -apple-system,sans-serif;color:#AEAEB2;',
    'letter-spacing:.08em;text-transform:uppercase;margin:16px 0 10px;}',
    /* Loading dots inside inline results */
    '.hx-sr-dots{display:flex;gap:6px;align-items:center;padding:24px;justify-content:center;}',
    /* Flying cart dot — contains a spinning rainbow child */
    '.hx-fly-dot{position:fixed;z-index:1000002;pointer-events:none;border-radius:50%;',
    'overflow:hidden;will-change:transform;}',
    '@keyframes hx-fly-spin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}',
    '.hx-fly-inner{width:100%;height:100%;',
    'background:conic-gradient(from 0deg,#ff6eb4,#ffb347,#ffe066,#7fffd4,#87cefa,#c9b1ff,#ff6eb4);',
    'animation:hx-fly-spin .5s linear infinite;}',
    /* Cart icon bounce when item lands */
    '@keyframes hx-cart-bounce{',
    '0%,100%{transform:scale(1)rotate(0deg);}',
    '20%{transform:scale(1.4)rotate(-14deg);}',
    '45%{transform:scale(1.2)rotate(10deg);}',
    '65%{transform:scale(1.08)rotate(-5deg);}',
    '82%{transform:scale(1.03)rotate(2deg);}}',
    '.hx-cart-pulse{animation:hx-cart-bounce .58s cubic-bezier(.175,.885,.32,1.275)!important;',
    'display:inline-block!important;}',
    /* ── Product detail modal ──────────────────────────────────────────── */
    '#hx-pm-ov{position:fixed;inset:0;z-index:1000001;display:flex;',
    'align-items:center;justify-content:center;padding:16px;cursor:default;',
    'background:rgba(0,0,0,.6);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);',
    'animation:hx-pm-bg .22s ease both;}',
    '@keyframes hx-pm-bg{from{opacity:0;}to{opacity:1;}}',
    '#hx-pm{position:relative;background:#fff;border-radius:22px;cursor:default;',
    'width:100%;max-width:500px;max-height:88vh;overflow-y:auto;',
    'box-shadow:0 40px 100px rgba(0,0,0,.28),0 0 0 1px rgba(0,0,0,.06);',
    'animation:hx-pm-up .35s cubic-bezier(.175,.885,.32,1.275) both;',
    'scrollbar-width:thin;scrollbar-color:rgba(0,0,0,.1) transparent;}',
    '#hx-pm *{cursor:default;}',
    '#hx-pm button,#hx-pm a{cursor:pointer !important;}',
    '@keyframes hx-pm-up{from{transform:translateY(40px) scale(.93);}to{transform:translateY(0) scale(1);}}',
    '#hx-pm::-webkit-scrollbar{width:4px;}',
    '#hx-pm::-webkit-scrollbar-thumb{background:rgba(0,0,0,.1);border-radius:2px;}',
    '#hx-pm-img{width:100%;aspect-ratio:4/3;object-fit:cover;display:block;',
    'background:linear-gradient(135deg,#f0edff,#ede9fe);}',
    '#hx-pm-iph{width:100%;aspect-ratio:4/3;background:linear-gradient(135deg,#f0edff,#ede9fe);',
    'display:flex;align-items:center;justify-content:center;}',
    '#hx-pm-iph svg{width:56px;height:56px;opacity:.18;}',
    '#hx-pm-body{padding:22px 24px 28px;}',
    '#hx-pm-close{position:absolute;top:14px;right:14px;all:unset;cursor:pointer;',
    'width:34px;height:34px;border-radius:50%;background:rgba(0,0,0,.38);',
    'backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;',
    'font-size:19px;color:#fff;line-height:1;transition:background .15s;}',
    '#hx-pm-close:hover{background:rgba(0,0,0,.58);}',
    '#hx-pm-cat{font:500 11px/1 -apple-system,sans-serif;color:#8E8E93;',
    'letter-spacing:.05em;text-transform:uppercase;margin:0 0 6px;}',
    '#hx-pm-name{font:700 19px/1.25 -apple-system,BlinkMacSystemFont,sans-serif;',
    'color:#1C1C1E;margin:0 0 10px;}',
    '#hx-pm-price{font:800 22px/1 -apple-system,sans-serif;',
    'background:linear-gradient(135deg,#7C3AED,#4F46E5);',
    '-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;',
    'margin:0 0 16px;}',
    '#hx-pm-desc{font:400 14px/1.65 -apple-system,sans-serif;color:#3C3C43;',
    'margin:0 0 22px;}',
    '#hx-pm-desc p{margin:0 0 8px;}.#hx-pm-desc p:last-child{margin:0;}',
    '#hx-pm-atc{all:unset;cursor:pointer;display:block;width:100%;box-sizing:border-box;',
    'padding:15px;border-radius:13px;',
    'background:linear-gradient(135deg,#7C3AED,#4F46E5);color:#fff;',
    'font:700 15px/1 -apple-system,sans-serif;text-align:center;',
    'box-shadow:0 4px 20px rgba(124,58,237,.42);',
    'transition:all .22s cubic-bezier(.175,.885,.32,1.275);margin-bottom:12px;}',
    '#hx-pm-atc:hover{transform:scale(1.02);box-shadow:0 8px 28px rgba(124,58,237,.6);}',
    '#hx-pm-atc.adding{opacity:.65;pointer-events:none;}',
    '#hx-pm-atc.added{background:linear-gradient(135deg,#34C759,#30D158)!important;',
    '-webkit-text-fill-color:#fff!important;',
    'box-shadow:0 4px 20px rgba(52,199,89,.4)!important;}',
    '#hx-pm-link{display:block;text-align:center;',
    'font:500 13px/1 -apple-system,sans-serif;color:#7C3AED;',
    'text-decoration:none;padding:7px;transition:opacity .15s;}',
    '#hx-pm-link:hover{opacity:.65;}',
    '#hx-pm-spin{display:flex;gap:5px;justify-content:center;padding:32px;}',
    /* Enquiry form (automotive / enquire CTA mode) */
    '.hx-eq-title{font:600 14px/1.3 -apple-system,sans-serif;color:#3C3C43;margin:0 0 14px;',
    'padding-top:8px;border-top:1px solid rgba(0,0,0,.06);}',
    '.hx-eq-title strong{color:#1C1C1E;}',
    '.hx-eq-inp{display:block;width:100%;box-sizing:border-box;margin-bottom:10px;',
    'padding:11px 14px;border:1.5px solid rgba(0,0,0,.12);border-radius:10px;',
    'font:400 14px/1 -apple-system,sans-serif;color:#1C1C1E;background:#fff;',
    'outline:none;transition:border-color .15s;cursor:text !important;}',
    '.hx-eq-inp:focus{border-color:#7C3AED;}',
    '.hx-eq-inp::placeholder{color:#AEAEB2;}',
    '.hx-eq-send{all:unset;cursor:pointer;display:block;width:100%;box-sizing:border-box;',
    'padding:14px;border-radius:12px;margin-top:4px;',
    'background:linear-gradient(135deg,#7C3AED,#4F46E5);color:#fff;',
    'font:700 14px/1 -apple-system,sans-serif;text-align:center;',
    'box-shadow:0 4px 16px rgba(124,58,237,.4);',
    'transition:all .22s cubic-bezier(.175,.885,.32,1.275);}',
    '.hx-eq-send:hover{transform:scale(1.02);box-shadow:0 6px 22px rgba(124,58,237,.58);}',
    '.hx-eq-send:disabled{opacity:.55;pointer-events:none;}',
    '.hx-eq-msg{font:400 12px/1.4 -apple-system,sans-serif;color:#FF3B30;',
    'margin-top:8px;min-height:16px;}',
    '.hx-eq-ok{background:rgba(52,199,89,.08);border:1px solid rgba(52,199,89,.25);',
    'border-radius:12px;padding:16px;text-align:center;',
    'font:500 14px/1.4 -apple-system,sans-serif;color:#1C7A3A;}',
    /* Responsive inline grid */
    '@media(max-width:480px){',
    '#hx-sb-results{padding:0 12px 20px;}',
    '.hx-sr-grid{grid-template-columns:repeat(2,1fr);gap:10px;}',
    '.hx-sr-ci{padding:9px;}',
    '.hx-sr-ct{font-size:11.5px;}',
    '.hx-sr-atc{padding:8px 0;font-size:11px;}',
    /* Mobile modal: bottom sheet */
    '#hx-pm-ov{align-items:flex-end;padding:0;}',
    '#hx-pm{border-radius:22px 22px 0 0;max-width:100%;max-height:88vh;',
    'animation:hx-pm-sheet .35s cubic-bezier(.175,.885,.32,1.275) both;}',
    '@keyframes hx-pm-sheet{from{transform:translateY(100%);}to{transform:translateY(0);}}}',
    '@media(min-width:481px) and (max-width:768px){',
    '.hx-sr-grid{grid-template-columns:repeat(3,1fr);gap:12px;}}',

    /* Dark mode — bar stays white on dark sites; only chips + panel adapt */
    '@media(prefers-color-scheme:dark){',
    '#hx-sb-section{background:linear-gradient(180deg,transparent 0%,rgba(124,58,237,.08) 50%,transparent 100%);}',
    '#hx-sb-headline{color:#8E8E93;}',
    '.hx-sb-chip{background:rgba(124,58,237,.12);border-color:rgba(124,58,237,.25);color:#8E8E93;}',
    '.hx-sb-chip:hover{color:#C9B1FF;background:rgba(124,58,237,.2);}',
    '#hx-sb-panel{background:rgba(28,28,30,.97);border-color:rgba(255,255,255,.1);}',
    '#hx-sb-ph{border-bottom-color:rgba(255,255,255,.06);}',
    '#hx-sb-ph strong{color:#F2F2F7;}',
    '#hx-sb-pclose{background:rgba(255,255,255,.1);color:#8E8E93;}',
    '#hx-sb-pclose:hover{background:rgba(255,255,255,.18);}',
    '.hx-sb-blk{background:rgba(44,44,46,.9);color:#F2F2F7;}',
    '.hx-sb-card{background:#2C2C2E;border-color:rgba(255,255,255,.08);}',
    '.hx-sb-ct{color:#F2F2F7;}',
    '#hx-sb-pf{border-top-color:rgba(255,255,255,.06);}',
    '.hx-sb-live-row:hover,.hx-sb-live-row:focus{background:rgba(124,58,237,.08);}',
    '.hx-sb-lname{color:#F2F2F7;}',
    '}',

    /* Mobile (<480px) */
    '@media(max-width:480px){',
    '#hx-sb-section{padding:20px 12px 16px;}',
    '#hx-sb{max-width:100%;}',
    '#hx-sb-inner{height:50px;}',
    '#hx-sb-inp{font-size:14px;}',
    '#hx-sb-go{width:38px;height:38px;margin:6px;}',
    '#hx-sb-go svg{width:17px;height:17px;}',
    '#hx-sb-ai{padding:7px 10px;}',
    '#hx-sb-ai .hx-sb-label{display:none;}',
    '#hx-sb-headline{font-size:13px;margin-bottom:14px;}',
    '.hx-sb-chip{font-size:11.5px;padding:6px 11px;}',
    '.hx-sb-grid{grid-template-columns:repeat(2,1fr);}',
    '#hx-sb-panel{left:0;right:0;}',
    '}',

    /* Tablet (481–900px) */
    '@media(min-width:481px) and (max-width:900px){',
    '#hx-sb-section{padding:24px 24px 18px;}',
    '#hx-sb{max-width:600px;}',
    '#hx-sb-inner{height:56px;}',
    '#hx-sb-inp{font-size:15px;}',
    '}',
  ].join('');
  document.head.appendChild(S);

  /* ── DOM ─────────────────────────────────────────────────────────────── */
  var root = document.createElement('div');
  root.id = 'hx-sb-section';
  root.innerHTML = [
    '<p id="hx-sb-headline">Ask Helix AI anything about our products</p>',
    '<div id="hx-sb">',
      '<div id="hx-sb-outer">',
        '<div id="hx-sb-ring"></div>',
        '<div id="hx-sb-glow"></div>',
        '<div id="hx-sb-inner" class="ai">',
          '<div id="hx-sb-toggle">',
            '<button id="hx-sb-ai" class="on" aria-label="AI mode">',
              '<span class="hx-sb-spark">&#10022;</span>',
              '<span class="hx-sb-label">AI</span>',
            '</button>',
            '<button id="hx-sb-norm" aria-label="Normal search">',
              '<svg viewBox="0 0 24 24">',
                '<path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16',
                'c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 5L20.49 19l-5-5z',
                'm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>',
              '</svg>',
            '</button>',
          '</div>',
          '<input id="hx-sb-inp" type="search" autocomplete="off" autocorrect="off"',
          ' placeholder="Ask AI about any product…">',
          '<button id="hx-sb-go" aria-label="Send">',
            '<svg id="hx-sb-go-icon" viewBox="0 0 24 24">',
              '<path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>',
            '</svg>',
          '</button>',
        '</div>',
      '</div>',
      '<div id="hx-sb-panel">',
        '<div id="hx-sb-ph">',
          '<div class="hx-sb-pav">',
            '<svg viewBox="0 0 24 24">',
              '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z',
              'm-2 14.5v-9l6 4.5-6 4.5z"/>',
            '</svg>',
          '</div>',
          '<strong>Helix AI</strong>',
          '<div class="hx-sb-live"></div>',
          '<button id="hx-sb-pclose" aria-label="Close">\xd7</button>',
        '</div>',
        '<div id="hx-sb-body"></div>',
        '<div id="hx-sb-pf">',
          '<button id="hx-sb-fc">',
            'Open in chat',
            '<svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>',
          '</button>',
        '</div>',
      '</div>',
    '</div>',
    '<div id="hx-sb-chips"></div>',
    '<div id="hx-sb-results"></div>',
  ].join('');

  /* ── Refs ─────────────────────────────────────────────────────────────── */
  var ring  = root.querySelector('#hx-sb-ring');
  var glow  = root.querySelector('#hx-sb-glow');
  var inner = root.querySelector('#hx-sb-inner');
  var aiBt  = root.querySelector('#hx-sb-ai');
  var nrmBt = root.querySelector('#hx-sb-norm');
  var inp   = root.querySelector('#hx-sb-inp');
  var goBtn = root.querySelector('#hx-sb-go');
  var goIco = root.querySelector('#hx-sb-go-icon');
  var panel = root.querySelector('#hx-sb-panel');
  var body  = root.querySelector('#hx-sb-body');
  var pcls  = root.querySelector('#hx-sb-pclose');
  var fcBtn = root.querySelector('#hx-sb-fc');
  var chipsBox = root.querySelector('#hx-sb-chips');
  var inlineResults = root.querySelector('#hx-sb-results');
  var headline = root.querySelector('#hx-sb-headline');
  var panelTitle = root.querySelector('#hx-sb-ph strong');

  /* ── Branding application ─────────────────────────────────────────────── */
  function applyBranding(B) {
    if (!B) return;
    if (headline) headline.textContent = window.HelixBranding.substitute(B.headline_text || '', B);
    if (B.search_placeholder) inp.placeholder = B.search_placeholder;
    if (panelTitle && B.brand_name) panelTitle.textContent = B.brand_name;
    if (fcBtn && B.footer_cta_label) {
      var icon = fcBtn.querySelector('svg');
      fcBtn.textContent = B.footer_cta_label + ' ';
      if (icon) fcBtn.appendChild(icon);
    }
    chipsBox.innerHTML = '';
    (B.suggestion_chips || []).forEach(function (chip) {
      var btn = document.createElement('button');
      btn.className = 'hx-sb-chip';
      btn.textContent = chip.label;
      btn.dataset.query = chip.query || chip.label;
      btn.addEventListener('click', function () {
        inp.value = btn.dataset.query;
        setMode(true);
        inp.focus();
        doSearch();
      });
      chipsBox.appendChild(btn);
    });
  }
  window.HelixBranding.load(BASE, KEY).then(function (B) {
    applyBranding(B);
    if (B && B.pack_cta_type) _ctaType = B.pack_cta_type;
  });

  /* ── Inject into page ─────────────────────────────────────────────────── */
  function inject() {
    var target = document.getElementById('hx-sb-target');
    if (!target) return;
    target.parentNode.replaceChild(root, target);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', inject);
  } else {
    inject();
  }

  /* ── Pastel rainbow animation ─────────────────────────────────────────── */
  function tick() {
    angle = (angle + 0.5) % 360;
    pulseVal += 0.0025 * pulseDir;
    if (pulseVal > 0.65 || pulseVal < 0.28) pulseDir *= -1;
    var g = 'conic-gradient(from ' + angle + 'deg,#ff6eb4,#ffb347,#ffe066,#7fffd4,#87cefa,#c9b1ff,#ff6eb4)';
    ring.style.background = g;
    glow.style.background = g;
    glow.style.opacity = String(pulseVal);
    rafId = requestAnimationFrame(tick);
  }

  function startRainbow() {
    ring.style.opacity = '1';
    if (!rafId) tick();
  }

  function stopRainbow() {
    ring.style.opacity = '0';
    glow.style.opacity = '0';
    if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
  }

  startRainbow();

  /* ── Mode toggle ──────────────────────────────────────────────────────── */
  function setMode(ai) {
    aiMode = ai;
    aiBt.classList.toggle('on', ai);
    nrmBt.classList.toggle('on', !ai);
    inner.classList.toggle('ai', ai);
    inp.placeholder = ai ? 'Ask AI about any product…' : 'Search products…';
    if (ai) {
      goIco.innerHTML = '<path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>';
      startRainbow();
      if (panelTitle) panelTitle.textContent = window.HelixBranding.substitute('{{brand_name}}');
    } else {
      goIco.innerHTML = '<path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 5L20.49 19l-5-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>';
      stopRainbow();
      closePanel();
      if (panelTitle) panelTitle.textContent = 'Products';
    }
  }

  /* ── Normal-mode live search ──────────────────────────────────────────── */
  function renderLiveResults(products) {
    body.innerHTML = '';
    if (!products || !products.length) {
      body.innerHTML = '<p style="padding:18px;text-align:center;font:13px -apple-system,sans-serif;color:#8E8E93;">No products found.</p>';
      return;
    }
    products.forEach(function (p) {
      var a = document.createElement('a');
      a.className = 'hx-sb-live-row';
      a.href = p.url;
      var img = document.createElement('img');
      img.className = 'hx-sb-limg'; img.src = p.img; img.alt = ''; img.loading = 'lazy';
      a.appendChild(img);
      var info = document.createElement('span');
      info.className = 'hx-sb-linfo';
      var nm = document.createElement('span'); nm.className = 'hx-sb-lname'; nm.textContent = p.name;
      info.appendChild(nm);
      if (p.cat) {
        var ct = document.createElement('span'); ct.className = 'hx-sb-lcat'; ct.textContent = p.cat;
        info.appendChild(ct);
      }
      var pr = document.createElement('span'); pr.className = 'hx-sb-lprice'; pr.textContent = p.price;
      info.appendChild(pr);
      a.appendChild(info);
      body.appendChild(a);
    });
    var allA = document.createElement('a');
    allA.className = 'hx-sb-lall';
    allA.href = STORE_BASE + '/?s=' + encodeURIComponent(inp.value.trim()) + '&post_type=product';
    allA.textContent = 'See all results →';
    body.appendChild(allA);
  }

  function doLiveSearch() {
    if (!AJAX_URL) return;
    var q = inp.value.trim();
    if (q.length < 2) { closePanel(); return; }
    if (_lsCtrl) { try { _lsCtrl.abort(); } catch (e) {} }
    _lsCtrl = new AbortController();
    body.innerHTML = '<div id="hx-sb-typing"><div class="hx-sbd"></div><div class="hx-sbd"></div><div class="hx-sbd"></div></div>';
    openPanel();
    fetch(AJAX_URL + '?action=helix_live_search&q=' + encodeURIComponent(q), { signal: _lsCtrl.signal })
      .then(function (r) { return r.json(); })
      .then(function (data) { renderLiveResults(data); })
      .catch(function (e) { if (e && e.name !== 'AbortError') closePanel(); });
  }

  aiBt.addEventListener('click', function () { setMode(true); inp.focus(); });
  nrmBt.addEventListener('click', function () { setMode(false); inp.focus(); });

  /* ── Panel ────────────────────────────────────────────────────────────── */
  function openPanel() { panel.style.display = 'block'; }
  function closePanel() { panel.style.display = 'none'; if (typeof abortInFlight === 'function') abortInFlight(); }

  pcls.addEventListener('click', closePanel);
  document.addEventListener('click', function (e) {
    if (!root.contains(e.target)) closePanel();
  });

  /* ── Token ────────────────────────────────────────────────────────────── */
  function getToken() {
    var exp = parseInt(localStorage.getItem(LS_EXP) || '0', 10);
    if (exp > Date.now()) return Promise.resolve(localStorage.getItem(LS_TOK));
    return fetch(BASE + '/v1/widget/session', {
      method: 'POST',
      headers: { 'X-Helix-Tenant-Key': KEY, 'Content-Type': 'application/json' },
      body: '{}',
    }).then(function (r) { return r.json(); })
      .then(function (d) {
        localStorage.setItem(LS_TOK, d.token);
        localStorage.setItem(LS_EXP, String(Date.now() + (d.expires_in - 60) * 1000));
        return d.token;
      });
  }

  /* ── WooCommerce nonce ────────────────────────────────────────────────── */
  function ensureNonce() {
    if (wcNonce) return Promise.resolve(wcNonce);
    if (nonceProm) return nonceProm;
    nonceProm = fetch(_restRoot + 'wc/store/v1/cart', { credentials: 'include' })
      .then(function (r) {
        wcNonce = r.headers.get('Nonce') || r.headers.get('X-WC-Store-API-Nonce') || r.headers.get('nonce');
        nonceProm = null; return wcNonce;
      })
      .catch(function () { nonceProm = null; return null; });
    return nonceProm;
  }
  ensureNonce();

  /* ── Helpers ──────────────────────────────────────────────────────────── */
  function fmt(minor, currency) {
    try {
      return new Intl.NumberFormat('en-ZA', {
        style: 'currency', currency: currency,
        minimumFractionDigits: 0, maximumFractionDigits: 0,
      }).format(minor / 100);
    } catch (e) { return currency + '\xa0' + (minor / 100).toFixed(0); }
  }

  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function md(s) {
    return esc(s)
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.+?)\*/g, '<em>$1</em>');
  }

  /* ── Add to cart ──────────────────────────────────────────────────────── */
  function _refreshFragments() {
    fetch(_wcAjaxUrl('get_refreshed_fragments'), { method: 'POST', credentials: 'include' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.fragments && window.jQuery) {
          window.jQuery.each(d.fragments, function (s, h) { window.jQuery(s).replaceWith(h); });
        }
      }).catch(function () {});
  }

  function _classicAtc(pid, btn) {
    return fetch(_wcAjaxUrl('add_to_cart'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'include',
      body: 'add-to-cart=' + pid + '&quantity=1&product_id=' + pid,
    }).then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.fragments) {
          btn.classList.remove('adding'); btn.classList.add('added');
          btn.textContent = '✓ Added';
          if (window.jQuery) window.jQuery.each(d.fragments, function (s, h) { window.jQuery(s).replaceWith(h); });
          return true;
        }
        return false;
      });
  }

  /* Track which product IDs have been added so all buttons stay in sync */
  var _addedPids = {};

  function markAllAdded(pid) {
    _addedPids[pid] = true;
    document.querySelectorAll('[data-hx-pid="' + pid + '"]').forEach(function (b) {
      b.classList.remove('adding');
      b.classList.add('added');
      b.textContent = '✓ Added';
    });
  }

  function addToCart(pid, btn, imgEl) {
    btn.classList.add('adding'); btn.textContent = 'Adding…';
    var imgSrc = (imgEl && imgEl.tagName === 'IMG') ? imgEl.src : null;
    function onAdded() {
      markAllAdded(pid);
      flyToCart(imgEl || btn, imgSrc);
    }
    ensureNonce().then(function (n) {
      if (!n) return null;
      return fetch(_restRoot + 'wc/store/v1/cart/add-item', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Nonce': n },
        credentials: 'include',
        body: JSON.stringify({ id: parseInt(pid, 10), quantity: 1 }),
      });
    }).then(function (r) {
      if (r && r.ok) {
        var n2 = r.headers.get('Nonce') || r.headers.get('nonce'); if (n2) wcNonce = n2;
        onAdded(); _refreshFragments(); return;
      }
      return _classicAtc(pid, btn).then(function (ok) {
        if (ok) { onAdded(); } else { btn.classList.remove('adding'); btn.textContent = 'Add to Cart'; }
      });
    }).catch(function () {
      _classicAtc(pid, btn).then(function (ok) {
        if (ok) { onAdded(); } else { btn.classList.remove('adding'); btn.textContent = 'Add to Cart'; }
      }).catch(function () {
        btn.classList.remove('adding'); btn.textContent = 'Add to Cart';
      });
    });
  }

  /* ── Cart fly animation ───────────────────────────────────────────────── */
  function findCartEl() {
    var sels = [
      'a.cart-contents','a[class*="cart-icon"]','.site-header-cart a',
      '.header-cart a','.nav-cart-link','a[data-cart-fragments]',
      '.woocommerce-mini-cart__total','[class*="header"][class*="cart"] a',
      'a[href*="/cart"]:not([href*="remove_item"])',
    ];
    for (var i = 0; i < sels.length; i++) {
      var el = document.querySelector(sels[i]);
      if (el) return el;
    }
    return null;
  }

  function flyToCart(fromEl, imgSrc) {
    var cartEl = findCartEl();
    if (!cartEl) return;
    var fR = fromEl.getBoundingClientRect();
    var tR = cartEl.getBoundingClientRect();
    var dot = document.createElement('div');
    dot.className = 'hx-fly-dot';
    var sz = 48;
    dot.style.cssText = 'width:'+sz+'px;height:'+sz+'px;border-radius:50%;overflow:hidden;'
      + 'left:'+(fR.left+fR.width/2-sz/2)+'px;'
      + 'top:'+(fR.top+fR.height/2-sz/2)+'px;'
      + 'box-shadow:0 4px 20px rgba(124,58,237,.6);';
    /* Rainbow spinning inner — outer element moves via JS transform, inner spins freely */
    dot.innerHTML = imgSrc
      ? '<img src="'+imgSrc+'" style="width:100%;height:100%;object-fit:cover;position:absolute;inset:0;z-index:1;">'
        + '<div class="hx-fly-inner" style="position:absolute;inset:0;opacity:.5;"></div>'
      : '<div class="hx-fly-inner"></div>';
    document.body.appendChild(dot);
    var dx = (tR.left + tR.width/2 - sz/2) - (fR.left + fR.width/2 - sz/2);
    var dy = (tR.top  + tR.height/2 - sz/2) - (fR.top  + fR.height/2 - sz/2);
    requestAnimationFrame(function () {
      dot.style.transition = 'transform .72s cubic-bezier(.55,.06,.68,.19),opacity .72s ease';
      dot.style.transform  = 'translate('+dx+'px,'+dy+'px) scale(0.08)';
      dot.style.opacity    = '0';
      setTimeout(function () {
        dot.remove();
        cartEl.classList.add('hx-cart-pulse');
        setTimeout(function () { cartEl.classList.remove('hx-cart-pulse'); }, 600);
      }, 730);
    });
  }

  /* ── Product detail modal ─────────────────────────────────────────────── */
  function closeProductModal() {
    var ov = document.getElementById('hx-pm-ov');
    if (ov) {
      ov.style.animation = 'hx-pm-bg .18s ease reverse both';
      setTimeout(function () { if (ov.parentNode) ov.parentNode.removeChild(ov); }, 180);
    }
    document.body.style.overflow = '';
  }

  function openProductModal(p) {
    closeProductModal();
    document.body.style.overflow = 'hidden';
    var PHsvgMod = '<svg viewBox="0 0 24 24" fill="#7C3AED"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>';
    var bottomSection = _ctaType === 'enquire'
      ? [
          '<div class="hx-eq-title">Enquire about <strong>'+esc(p.title)+'</strong></div>',
          '<input class="hx-eq-inp" id="hx-eq-name" type="text" placeholder="Your name" autocomplete="name">',
          '<input class="hx-eq-inp" id="hx-eq-phone" type="tel" placeholder="Phone number" autocomplete="tel">',
          '<input class="hx-eq-inp" id="hx-eq-email" type="email" placeholder="Email address" autocomplete="email">',
          '<input class="hx-eq-inp" id="hx-eq-time" type="text" placeholder="Best time to call (optional)">',
          '<button id="hx-pm-atc" class="hx-eq-send">Send Enquiry</button>',
          '<div class="hx-eq-msg" id="hx-eq-msg"></div>',
        ].join('')
      : [
          '<button id="hx-pm-atc">Add to Cart</button>',
          '<a id="hx-pm-link" href="'+esc(p.permalink||'#')+'" target="_blank" rel="noopener">View full product page ↗</a>',
        ].join('');
    var ov = document.createElement('div');
    ov.id = 'hx-pm-ov';
    ov.innerHTML = [
      '<div id="hx-pm">',
        p.image_url
          ? '<img id="hx-pm-img" src="'+esc(p.image_url)+'" alt="">'
          : '<div id="hx-pm-iph">'+PHsvgMod+'</div>',
        '<button id="hx-pm-close" aria-label="Close">\xd7</button>',
        '<div id="hx-pm-body">',
          '<div id="hx-pm-name">'+esc(p.title)+'</div>',
          '<div id="hx-pm-price">'+fmt(p.price_minor, p.currency)+'</div>',
          '<div id="hx-pm-desc"><div id="hx-pm-spin"><div class="hx-sbd"></div><div class="hx-sbd"></div><div class="hx-sbd"></div></div></div>',
          bottomSection,
        '</div>',
      '</div>',
    ].join('');
    document.body.appendChild(ov);
    var pmAtc = ov.querySelector('#hx-pm-atc');
    pmAtc.dataset.hxPid = p.platform_id;
    if (_ctaType === 'enquire') {
      pmAtc.addEventListener('click', function () {
        var nameVal = (ov.querySelector('#hx-eq-name').value || '').trim();
        var phoneVal = (ov.querySelector('#hx-eq-phone').value || '').trim();
        var emailVal = (ov.querySelector('#hx-eq-email').value || '').trim();
        var timeVal = (ov.querySelector('#hx-eq-time').value || '').trim();
        var msgEl = ov.querySelector('#hx-eq-msg');
        if (!nameVal || (!phoneVal && !emailVal)) {
          msgEl.textContent = 'Please enter your name and at least a phone number or email.';
          return;
        }
        pmAtc.disabled = true; pmAtc.textContent = 'Sending…';
        getToken().then(function (tok) {
          return fetch(BASE + '/v1/widget/capture-lead', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + tok, 'Content-Type': 'application/json' },
            body: JSON.stringify({
              session_id: convId,
              product_platform_id: p.platform_id,
              name: nameVal || null,
              phone: phoneVal || null,
              email: emailVal || null,
              preferred_contact_time: timeVal || null,
              source: 'car_enquiry',
            }),
          });
        }).then(function (r) { return r.json(); }).then(function () {
          var body = ov.querySelector('#hx-pm-body');
          if (body) {
            var inner = ov.querySelector('#hx-pm-img') || ov.querySelector('#hx-pm-iph');
            body.innerHTML = '<div class="hx-eq-ok">Thanks'+(nameVal ? ', '+esc(nameVal) : '')+'! One of our consultants will be in touch shortly.</div>';
          }
        }).catch(function () {
          pmAtc.disabled = false; pmAtc.textContent = 'Send Enquiry';
          if (msgEl) msgEl.textContent = 'Something went wrong. Please try again.';
        });
      });
    } else {
      /* Reflect already-added state immediately */
      if (_addedPids[p.platform_id]) {
        pmAtc.classList.add('added'); pmAtc.textContent = '✓ Added';
      }
      pmAtc.addEventListener('click', function () {
        var imgEl = ov.querySelector('#hx-pm-img') || pmAtc;
        addToCart(p.platform_id, pmAtc, imgEl);
      });
    }
    ov.querySelector('#hx-pm-close').addEventListener('click', closeProductModal);
    ov.addEventListener('click', function (e) { if (e.target === ov) closeProductModal(); });
    /* Fetch short description from WC Store API */
    fetch(_restRoot + 'wc/store/v1/products/' + p.platform_id, { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        var descEl = document.getElementById('hx-pm-desc');
        if (!descEl) return;
        var text = (d && (d.short_description || d.description)) || '';
        /* strip tags to plain text for safety */
        var tmp = document.createElement('div');
        tmp.innerHTML = text;
        var plain = tmp.textContent || tmp.innerText || '';
        descEl.textContent = plain.trim() || '';
        /* fill in permalink if we didn't have it */
        if (d && d.permalink) {
          var lk = document.getElementById('hx-pm-link');
          if (lk) lk.href = d.permalink;
        }
        /* fill in category */
        if (d && d.categories && d.categories.length) {
          var catEl = document.getElementById('hx-pm-cat');
          if (catEl) catEl.textContent = d.categories[0].name;
        }
      })
      .catch(function () {
        var descEl = document.getElementById('hx-pm-desc');
        if (descEl) descEl.textContent = '';
      });
  }

  /* ── Card factory ─────────────────────────────────────────────────────── */
  function makeCard(p, idx, isRow) {
    var card = document.createElement('div');
    card.className = isRow ? 'hx-sr-row' : 'hx-sr-card';
    card.style.cursor = 'pointer';
    if (_sbAnim) {
      card.style.animationDelay = (idx * 100) + 'ms';
    } else {
      card.style.animation = 'none';
    }
    var PHsvg = '<svg viewBox="0 0 24 24" fill="#7C3AED"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>';
    var ctaLabel = _ctaType === 'enquire' ? 'Enquire Now' : 'Add to Cart';
    if (isRow) {
      card.innerHTML = (p.image_url
        ? '<img class="hx-sr-row-img" src="' + esc(p.image_url) + '" alt="" loading="lazy">'
        : '<div class="hx-sr-row-iph">' + PHsvg + '</div>') +
        '<div class="hx-sr-row-ci">' +
          '<div class="hx-sr-row-info">' +
            '<div class="hx-sr-row-ct">' + esc(p.title) + '</div>' +
            '<div class="hx-sr-row-cp">' + fmt(p.price_minor, p.currency) + '</div>' +
          '</div>' +
          '<button class="hx-sr-row-atc">' + ctaLabel + '</button>' +
        '</div>';
    } else {
      card.innerHTML = (p.image_url
        ? '<img class="hx-sr-cimg" src="' + esc(p.image_url) + '" alt="" loading="lazy">'
        : '<div class="hx-sr-cph">' + PHsvg + '</div>') +
        '<div class="hx-sr-ci">' +
          '<div class="hx-sr-ct">' + esc(p.title) + '</div>' +
          '<div class="hx-sr-cp">' + fmt(p.price_minor, p.currency) + '</div>' +
          '<button class="hx-sr-atc">' + ctaLabel + '</button>' +
        '</div>';
    }
    var atcSel = isRow ? '.hx-sr-row-atc' : '.hx-sr-atc';
    var atcBtn = card.querySelector(atcSel);
    var imgEl  = card.querySelector('img');
    atcBtn.dataset.hxPid = p.platform_id;
    if (_ctaType === 'enquire') {
      atcBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        openProductModal(p);
      });
      card.addEventListener('click', function () { openProductModal(p); });
    } else {
      /* Pre-fill Added state if this product was already added this session */
      if (_addedPids[p.platform_id]) {
        atcBtn.classList.add('added'); atcBtn.textContent = '✓ Added';
      }
      atcBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        addToCart(p.platform_id, atcBtn, _flyCart ? imgEl : null);
      });
      if (_cardModal) {
        card.addEventListener('click', function (e) {
          if (!e.target.closest(atcSel)) openProductModal(p);
        });
      }
    }
    return card;
  }

  /* ── Render results (inline, interleaved with recommendation text) ─────── */
  function renderResults(text, products) {
    inlineResults.innerHTML = '';
    inlineResults.classList.add('hx-sr-show');
    var paragraphs = text ? text.split(/\n+/).filter(function (s) { return s.trim(); }) : [];
    var usedIdx = [];
    var cardCounter = 0;
    /* Render each paragraph; insert matched product card immediately after it */
    paragraphs.forEach(function (para) {
      var d = document.createElement('div');
      d.className = 'hx-sr-blk';
      d.innerHTML = md(para.trim());
      inlineResults.appendChild(d);
      if (products && products.length) {
        var paraLow = para.toLowerCase();
        products.forEach(function (p, pi) {
          if (usedIdx.indexOf(pi) !== -1) return;
          /* Match by first 3 words of title (minimum 5 chars) */
          var words = p.title.trim().toLowerCase().split(/\s+/);
          var key = words.slice(0, Math.min(3, words.length)).join(' ');
          if (key.length >= 5 && paraLow.indexOf(key) !== -1) {
            usedIdx.push(pi);
            inlineResults.appendChild(makeCard(p, cardCounter++, true));
          }
        });
      }
    });
    /* Any unmatched products fall into a grid below */
    var unmatched = (products || []).filter(function (_, i) { return usedIdx.indexOf(i) === -1; });
    if (unmatched.length) {
      var lbl = document.createElement('div');
      lbl.className = 'hx-sr-label';
      lbl.textContent = usedIdx.length ? 'More recommendations' : 'Recommended Products';
      inlineResults.appendChild(lbl);
      var grid = document.createElement('div');
      grid.className = 'hx-sr-grid';
      unmatched.forEach(function (p) {
        grid.appendChild(makeCard(p, cardCounter++, false));
      });
      inlineResults.appendChild(grid);
    }
    /* "Open in full chat" link */
    var footer = document.createElement('div');
    footer.className = 'hx-sr-footer';
    footer.innerHTML = '<button class="hx-sr-oc">Open in chat<svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></button>';
    footer.querySelector('.hx-sr-oc').addEventListener('click', function () {
      var hxBtn = document.getElementById('hx-btn');
      if (hxBtn) {
        hxBtn.click();
        setTimeout(function () {
          var ci = document.getElementById('hx-inp');
          if (ci && inp.value.trim()) { ci.value = inp.value.trim(); ci.focus(); }
        }, 400);
      }
    });
    inlineResults.appendChild(footer);
  }

  /* ── Search / Send ────────────────────────────────────────────────────── */
  var _ac = null;
  function abortInFlight() {
    if (_ac) { try { _ac.abort(); } catch (e) {} _ac = null; isLoading = false; }
  }
  function doSearch() {
    var q = inp.value.trim();
    if (!q) { inp.focus(); return; }
    if (!aiMode) {
      window.location.href = STORE_BASE + '/?s=' + encodeURIComponent(q) + '&post_type=product';
      return;
    }
    abortInFlight();
    isLoading = true;
    _ac = new AbortController();
    var signal = _ac.signal;
    /* Show loading spinner in the inline results area */
    inlineResults.innerHTML = '<div class="hx-sr-dots"><div class="hx-sbd"></div><div class="hx-sbd"></div><div class="hx-sbd"></div></div>';
    inlineResults.classList.add('hx-sr-show');
    getToken()
      .then(function (tok) {
        return fetch(BASE + '/v1/widget/chat', {
          method: 'POST',
          headers: { 'Authorization': 'Bearer ' + tok, 'Content-Type': 'application/json' },
          body: JSON.stringify({ query: q, customer_profile: {}, conversation_id: convId || undefined }),
          signal: signal,
        });
      })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        isLoading = false; _ac = null;
        if (d.response) {
          convId = d.conversation_id;
          localStorage.setItem(LS_CONV, convId);
          renderResults(d.response, d.products || []);
        } else {
          renderResults(d.detail || 'Something went wrong. Please try again.', []);
        }
      })
      .catch(function (err) {
        isLoading = false; _ac = null;
        if (err && err.name === 'AbortError') return;  // user moved on; no error UI
        renderResults('Could not reach ' + window.HelixBranding.substitute('{{brand_short_name}}') + '. Please try again.', []);
      });
  }

  goBtn.addEventListener('click', doSearch);
  inp.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
    if (e.key === 'Escape') { abortInFlight(); closePanel(); inlineResults.classList.remove('hx-sr-show'); inlineResults.innerHTML = ''; inp.blur(); }
  });
  inp.addEventListener('input', function () {
    if (aiMode) return;
    clearTimeout(_lsTimer);
    _lsTimer = setTimeout(doLiveSearch, 300);
  });

  /* ── "Open in chat" — forwards query to the main Helix chat panel ─────── */
  fcBtn.addEventListener('click', function () {
    var hxBtn = document.getElementById('hx-btn');
    if (hxBtn) {
      hxBtn.click();
      setTimeout(function () {
        var ci = document.getElementById('hx-inp');
        if (ci && inp.value.trim()) { ci.value = inp.value.trim(); ci.focus(); }
      }, 400);
    }
    closePanel();
  });

})();
""".strip()


_BRANDING_BOOTSTRAP_JS = r"""
(function () {
  if (window.HelixBranding) return;
  window.HelixBranding = {
    _p: null,
    _applied: null,
    load: function (BASE, KEY) {
      if (this._p) return this._p;
      var lsKey = 'hx_brand_' + KEY;
      var stored = null;
      try { stored = JSON.parse(localStorage.getItem(lsKey) || 'null'); } catch (e) {}
      if (stored) {
        this._applied = stored;
        this._applyCSS(stored);
      }
      var headers = stored && stored.version
        ? { 'If-None-Match': 'W/"branding-' + stored.version + '"' }
        : {};
      var self = this;
      this._p = fetch(BASE + '/v1/widget/branding?key=' + encodeURIComponent(KEY), { headers: headers, cache: 'no-cache' })
        .then(function (r) {
          if (r.status === 304 && stored) return stored;
          if (!r.ok) return stored;
          return r.json();
        })
        .then(function (b) {
          if (b && b.version) {
            try { localStorage.setItem(lsKey, JSON.stringify(b)); } catch (e) {}
            self._applied = b;
            self._applyCSS(b);
          }
          return b || stored;
        })
        .catch(function () { return stored; });
      return this._p;
    },
    substitute: function (s, b) {
      b = b || this._applied || {};
      return String(s == null ? '' : s)
        .replace(/\{\{brand_name\}\}/g, b.brand_name || 'Helix AI')
        .replace(/\{\{brand_short_name\}\}/g, b.brand_short_name || 'Helix');
    },
    _applyCSS: function (b) {
      if (!b) return;
      var r = document.documentElement;
      if (b.primary_color)   r.style.setProperty('--hx-primary',   b.primary_color);
      if (b.secondary_color) r.style.setProperty('--hx-secondary', b.secondary_color);
      if (b.accent_color)    r.style.setProperty('--hx-accent',    b.accent_color);
      if (b.primary_color && b.secondary_color) {
        r.style.setProperty('--hx-gradient',
          'linear-gradient(135deg,' + b.primary_color + ',' + b.secondary_color + ')');
      }
      if (b.custom_css) {
        var ex = document.getElementById('hx-custom-css');
        if (!ex) {
          ex = document.createElement('style');
          ex.id = 'hx-custom-css';
          document.head.appendChild(ex);
        }
        ex.textContent = b.custom_css;
      }
    }
  };
})();
""".strip()


@router.get("/embed.js", include_in_schema=False)
async def widget_embed_js() -> Response:
    return Response(
        content=_BRANDING_BOOTSTRAP_JS + "\n" + _EMBED_JS,
        media_type="application/javascript",
    )


@router.get("/searchbar.js", include_in_schema=False)
async def widget_searchbar_js() -> Response:
    return Response(
        content=_BRANDING_BOOTSTRAP_JS + "\n" + _SEARCH_BAR_JS,
        media_type="application/javascript",
    )


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
