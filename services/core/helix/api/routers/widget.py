from fastapi import APIRouter, Depends, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.auth.tokens import issue_widget_token
from helix.api.deps import get_db, get_tenant, get_widget_tenant
from helix.config import get_settings
from helix.db.crud.products import vector_search_products
from helix.db.models import Tenant
from helix.domain.consultant import handle_query
from helix.domain.routine import build_routine
from helix.domain.search import embed_query
from helix.packs.registry import get_pack_for_tenant

router = APIRouter(prefix="/v1/widget", tags=["widget"])


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

    result = await handle_query(
        query=body.query,
        customer_profile=body.customer_profile,
        context_products=context_products,
        tenant_id=tenant.id,
        pack=pack,
        settings=settings,
        db_session=db,
    )

    return ChatResponse(
        response=result.response,
        source=result.source,
        products_referenced=result.products_referenced,
    )


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

    return RoutineResponse(
        routine=[RoutineStepOut(**s) for s in result.steps],
        conflicts=result.conflicts,
        cautions=result.cautions,
        missing_steps=result.missing_steps,
        llm_augmented=result.llm_augmented,
    )
