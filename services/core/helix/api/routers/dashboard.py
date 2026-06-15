from datetime import datetime, timezone
from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db, get_tenant
from helix.config import get_settings
from helix.db.crud.dashboard import get_dashboard_summary
from helix.db.crud.products import get_embedding_coverage
from helix.db.crud.tenants import get_tenant_by_public_key
from helix.db.models import Tenant

router = APIRouter(prefix="/v1/dashboard", tags=["dashboard"])


class DashboardOut(BaseModel):
    product_count: int
    customer_count: int
    conversations_this_month: int
    pending_drafts: int
    queries_this_month: int
    cost_this_month_usd: float
    quota_limit: int
    quota_used: int


@router.get("", response_model=DashboardOut)
async def get_dashboard(
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> DashboardOut:
    settings = get_settings()
    now = datetime.now(timezone.utc)
    year, mon = now.year, now.month
    month_start = datetime(year, mon, 1, tzinfo=timezone.utc)
    next_mon, next_year = (mon + 1, year) if mon < 12 else (1, year + 1)
    month_end = datetime(next_year, next_mon, 1, tzinfo=timezone.utc)

    summary = await get_dashboard_summary(db, tenant.id, month_start, month_end)
    return DashboardOut(
        **summary,
        quota_limit=settings.default_monthly_query_limit,
        quota_used=summary["queries_this_month"],
    )


class EmbeddingCoverageOut(BaseModel):
    total: int
    embedded: int
    missing: int
    coverage_rate: float


@router.get("/embedding-coverage", response_model=EmbeddingCoverageOut)
async def get_embedding_coverage_endpoint(
    tenant_key: str | None = Query(default=None),
    x_helix_tenant_key: str | None = None,
    db: AsyncSession = Depends(get_db),
) -> EmbeddingCoverageOut:
    raw_key = tenant_key or x_helix_tenant_key
    if not raw_key:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Missing tenant key")
    try:
        tenant = await get_tenant_by_public_key(db, UUID(raw_key))
    except ValueError:
        tenant = None
    if not tenant:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid tenant key")
    return EmbeddingCoverageOut(**await get_embedding_coverage(db, tenant.id))


class WidgetEventSummaryItem(BaseModel):
    event_type: str
    count: int


class WidgetEventSummaryOut(BaseModel):
    events: list[WidgetEventSummaryItem]
    period_start: str
    period_end: str


@router.get("/widget-events", response_model=WidgetEventSummaryOut)
async def get_widget_event_summary_endpoint(
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> WidgetEventSummaryOut:
    from helix.db.crud.widget_events import get_widget_event_summary
    now = datetime.now(timezone.utc)
    year, mon = now.year, now.month
    month_start = datetime(year, mon, 1, tzinfo=timezone.utc)
    next_mon, next_year = (mon + 1, year) if mon < 12 else (1, year + 1)
    month_end = datetime(next_year, next_mon, 1, tzinfo=timezone.utc)
    rows = await get_widget_event_summary(db, tenant.id, month_start, month_end)
    return WidgetEventSummaryOut(
        events=[WidgetEventSummaryItem(**r) for r in rows],
        period_start=month_start.isoformat(),
        period_end=month_end.isoformat(),
    )


class ConversationSummary(BaseModel):
    id: str
    created_at: str
    message_count: int
    last_message_at: str | None
    first_message: str
    add_to_cart_count: int
    whatsapp_click_count: int


class ConversationListOut(BaseModel):
    items: list[ConversationSummary]
    total: int
    page: int
    limit: int


@router.get("/conversations", response_model=ConversationListOut)
async def list_dashboard_conversations(
    page: int = Query(default=1, ge=1),
    limit: int = Query(default=20, ge=1, le=100),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ConversationListOut:
    from helix.db.crud.conversations import list_conversations_with_stats
    items, total = await list_conversations_with_stats(
        db, tenant.id, limit=limit, offset=(page - 1) * limit
    )
    return ConversationListOut(
        items=[ConversationSummary(**i) for i in items],
        total=total,
        page=page,
        limit=limit,
    )


class MessageOut(BaseModel):
    id: str
    role: str
    content: str
    source: str | None
    products_referenced: list[str]
    feedback: str | None
    created_at: str


class ConversationDetailOut(BaseModel):
    id: str
    created_at: str
    messages: list[MessageOut]


@router.get("/conversations/{conversation_id}/messages", response_model=ConversationDetailOut)
async def get_conversation_detail(
    conversation_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ConversationDetailOut:
    from helix.db.crud.conversations import get_conversation, get_messages
    conv = await get_conversation(db, conversation_id, tenant.id)
    if conv is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Conversation not found")
    msgs = await get_messages(db, conversation_id, tenant.id)
    return ConversationDetailOut(
        id=str(conv.id),
        created_at=conv.created_at.isoformat(),
        messages=[
            MessageOut(
                id=str(m.id),
                role=m.role,
                content=m.content,
                source=m.source,
                products_referenced=m.products_referenced or [],
                feedback=m.feedback,
                created_at=m.created_at.isoformat(),
            )
            for m in msgs
        ],
    )


class DailyEventRow(BaseModel):
    day: str
    event_type: str
    count: int


class DailyEventsOut(BaseModel):
    rows: list[DailyEventRow]
    days: int


@router.get("/events/daily", response_model=DailyEventsOut)
async def get_daily_events(
    days: int = Query(default=30, ge=1, le=90),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> DailyEventsOut:
    from helix.db.crud.widget_events import get_daily_event_counts
    rows = await get_daily_event_counts(db, tenant.id, days=days)
    return DailyEventsOut(
        rows=[DailyEventRow(**r) for r in rows],
        days=days,
    )
