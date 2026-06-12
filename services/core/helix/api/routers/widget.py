import json
from uuid import UUID

import structlog
from fastapi import APIRouter, Depends, HTTPException, Response, status
from fastapi.responses import StreamingResponse
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.auth.tokens import issue_widget_token
from helix.api.deps import get_db, get_tenant, get_widget_tenant
from helix.config import get_settings
from helix.db.crud.customers import get_customer_by_id
from helix.db.crud.products import vector_search_products
from helix.db.crud.usage_events import create_usage_event
from helix.db.models import Tenant
from helix.domain.consultant import handle_query
from helix.domain.routine import build_routine
from helix.domain.search import embed_query
from helix.packs.registry import get_pack_for_tenant

logger = structlog.get_logger(__name__)

router = APIRouter(prefix="/v1/widget", tags=["widget"])


_EMBED_JS = r"""
(function () {
  var script = document.currentScript;
  var key = (new URLSearchParams(script && script.src ? new URL(script.src).search : '')).get('key')
    || (script && script.getAttribute('data-helix-key'));

  if (!key) { console.warn('[Helix] No key provided'); return; }

  var LS_TOKEN = 'helix_token_' + key;
  var LS_EXP   = 'helix_token_exp_' + key;
  var base = script && script.src ? new URL(script.src).origin : '';

  function getToken() {
    var exp = parseInt(localStorage.getItem(LS_EXP) || '0', 10);
    if (exp > Date.now()) return Promise.resolve(localStorage.getItem(LS_TOKEN));
    return fetch(base + '/v1/widget/session', {
      method: 'POST',
      headers: { 'X-Helix-Tenant-Key': key, 'Content-Type': 'application/json' },
      body: '{}'
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
      localStorage.setItem(LS_TOKEN, d.token);
      localStorage.setItem(LS_EXP, String(Date.now() + (d.expires_in - 60) * 1000));
      return d.token;
    });
  }

  var style = document.createElement('style');
  style.textContent = [
    '#helix-btn{position:fixed;bottom:24px;right:24px;width:52px;height:52px;border-radius:50%;',
    'background:#6c47ff;border:none;cursor:pointer;color:#fff;font-size:22px;box-shadow:0 4px 12px rgba(0,0,0,.25);}',
    '#helix-panel{display:none;position:fixed;bottom:88px;right:24px;width:340px;max-height:480px;',
    'background:#fff;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.15);',
    'display:none;flex-direction:column;overflow:hidden;}',
    '#helix-messages{flex:1;overflow-y:auto;padding:16px;font-family:sans-serif;font-size:14px;}',
    '#helix-msg-row{display:flex;padding:8px;border-top:1px solid #f0f0f0;}',
    '#helix-input{flex:1;border:1px solid #ddd;border-radius:6px;padding:6px 10px;font-size:14px;outline:none;}',
    '#helix-send{margin-left:8px;background:#6c47ff;color:#fff;border:none;border-radius:6px;',
    'padding:6px 12px;cursor:pointer;font-size:14px;}'
  ].join('');
  document.head.appendChild(style);

  var btn = document.createElement('button');
  btn.id = 'helix-btn';
  btn.textContent = '\u{1F4AC}';
  document.body.appendChild(btn);

  var panel = document.createElement('div');
  panel.id = 'helix-panel';
  panel.innerHTML = [
    '<div id="helix-messages"></div>',
    '<div id="helix-msg-row">',
    '<input id="helix-input" placeholder="Ask anything..." />',
    '<button id="helix-send">Send</button>',
    '</div>'
  ].join('');
  document.body.appendChild(panel);

  var open = false;
  btn.addEventListener('click', function(){
    open = !open;
    panel.style.display = open ? 'flex' : 'none';
  });

  function addMsg(text, role) {
    var d = document.getElementById('helix-messages');
    var p = document.createElement('p');
    p.style.margin = '4px 0';
    p.style.color = role === 'user' ? '#333' : '#6c47ff';
    p.textContent = (role === 'user' ? 'You: ' : 'Helix: ') + text;
    d.appendChild(p);
    d.scrollTop = d.scrollHeight;
  }

  function send() {
    var input = document.getElementById('helix-input');
    var q = input.value.trim();
    if (!q) return;
    input.value = '';
    addMsg(q, 'user');
    getToken().then(function(token){
      return fetch(base + '/v1/widget/chat', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
        body: JSON.stringify({ query: q, customer_profile: {} })
      });
    })
    .then(function(r){ return r.json(); })
    .then(function(d){ addMsg(d.response || d.detail || 'Error', 'helix'); })
    .catch(function(){ addMsg('Could not reach Helix. Please try again.', 'helix'); });
  }

  document.getElementById('helix-send').addEventListener('click', send);
  document.getElementById('helix-input').addEventListener('keydown', function(e){
    if (e.key === 'Enter') send();
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


class ChatResponse(BaseModel):
    response: str
    source: str
    products_referenced: list[str] = []


@router.post("/chat", response_model=ChatResponse)
async def widget_chat(
    body: ChatRequest,
    tenant: Tenant = Depends(get_widget_tenant),
    db: AsyncSession = Depends(get_db),
) -> ChatResponse:
    settings = get_settings()
    pack = get_pack_for_tenant(tenant)

    query_vector = await embed_query(body.query, settings)
    product_rows = await vector_search_products(db, tenant.id, query_vector, limit=5)
    context_products = [
        {
            "title": p.title,
            "price_minor": p.price_minor,
            "currency": p.currency,
            "categories": p.categories or [],
            "domain_attributes": p.domain_attributes or {},
        }
        for p, _ in product_rows
    ]

    merged_profile = body.customer_profile
    if body.customer_id:
        try:
            cid = UUID(body.customer_id)
            customer = await get_customer_by_id(db, cid, tenant.id)
            if customer:
                merged_profile = {**(customer.profile or {}), **body.customer_profile}
        except ValueError:
            logger.warning("widget_chat_invalid_customer_id", customer_id=body.customer_id)

    result = await handle_query(
        query=body.query,
        customer_profile=merged_profile,
        context_products=context_products,
        tenant_id=tenant.id,
        pack=pack,
        settings=settings,
        db_session=db,
    )

    if result.cost_usd > 0:
        await create_usage_event(
            db,
            tenant.id,
            result.model,
            result.tokens_in,
            result.tokens_out,
            result.cost_usd,
            "/v1/widget/chat",
        )
    await db.commit()

    return ChatResponse(
        response=result.response,
        source=result.source,
        products_referenced=result.products_referenced,
    )


@router.post("/chat/stream")
async def widget_chat_stream(
    body: ChatRequest,
    tenant: Tenant = Depends(get_widget_tenant),
    db: AsyncSession = Depends(get_db),
) -> StreamingResponse:
    settings = get_settings()
    pack = get_pack_for_tenant(tenant)

    query_vector = await embed_query(body.query, settings)
    product_rows = await vector_search_products(db, tenant.id, query_vector, limit=5)
    context_products = [
        {
            "title": p.title,
            "price_minor": p.price_minor,
            "currency": p.currency,
            "categories": p.categories or [],
            "domain_attributes": p.domain_attributes or {},
        }
        for p, _ in product_rows
    ]

    merged_profile = body.customer_profile
    if body.customer_id:
        try:
            cid = UUID(body.customer_id)
            customer = await get_customer_by_id(db, cid, tenant.id)
            if customer:
                merged_profile = {**(customer.profile or {}), **body.customer_profile}
        except ValueError:
            logger.warning("widget_chat_stream_invalid_customer_id", customer_id=body.customer_id)

    result = await handle_query(
        query=body.query,
        customer_profile=merged_profile,
        context_products=context_products,
        tenant_id=tenant.id,
        pack=pack,
        settings=settings,
        db_session=db,
    )

    if result.cost_usd > 0:
        await create_usage_event(
            db,
            tenant.id,
            result.model,
            result.tokens_in,
            result.tokens_out,
            result.cost_usd,
            "/v1/widget/chat/stream",
        )
    await db.commit()

    # Generator function to emit SSE events
    async def event_generator():
        # Emit token event with response content
        token_event = {"type": "token", "content": result.response}
        yield f"data: {json.dumps(token_event)}\n\n"

        # Emit done event with source
        done_event = {"type": "done", "source": result.source}
        yield f"data: {json.dumps(done_event)}\n\n"

    return StreamingResponse(event_generator(), media_type="text/event-stream")


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
