from typing import Annotated
from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db, get_tenant
from helix.db.crud.content import (
    approve_content_draft,
    count_content_drafts,
    get_content_draft,
    list_content_drafts,
    list_products_without_draft,
)
from helix.db.crud.products import get_product_by_id
from helix.db.models import Tenant
from helix.workers.tasks.content import generate_description

router = APIRouter(prefix="/v1/content", tags=["content"])


class GenerateResponse(BaseModel):
    product_id: str
    queued: bool


class ContentDraftOut(BaseModel):
    product_id: str
    field: str
    draft_text: str
    status: str
    created_at: str
    approved_at: str | None


class BulkGenerateResponse(BaseModel):
    queued: int


def _draft_out(draft) -> ContentDraftOut:
    return ContentDraftOut(
        product_id=str(draft.product_id),
        field=draft.field,
        draft_text=draft.draft_text,
        status=draft.status,
        created_at=draft.created_at.isoformat(),
        approved_at=draft.approved_at.isoformat() if draft.approved_at else None,
    )


class ContentDraftListResponse(BaseModel):
    items: list[ContentDraftOut]
    total: int
    limit: int
    offset: int


@router.get("/drafts", response_model=ContentDraftListResponse)
async def list_drafts(
    status: str | None = Query(default=None),
    limit: Annotated[int, Query(ge=1, le=100)] = 20,
    offset: Annotated[int, Query(ge=0)] = 0,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ContentDraftListResponse:
    drafts = await list_content_drafts(db, tenant.id, status=status, limit=limit, offset=offset)
    total = await count_content_drafts(db, tenant.id, status=status)
    return ContentDraftListResponse(
        items=[_draft_out(d) for d in drafts],
        total=total,
        limit=limit,
        offset=offset,
    )


@router.post("/products/{product_id}/generate", response_model=GenerateResponse, status_code=202)
async def generate_product_description(
    product_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> GenerateResponse:
    product = await get_product_by_id(db, tenant.id, product_id)
    if product is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Product not found")
    generate_description.delay(str(tenant.id), str(product_id))
    return GenerateResponse(product_id=str(product_id), queued=True)


@router.get("/products/{product_id}/draft", response_model=ContentDraftOut)
async def get_product_draft(
    product_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ContentDraftOut:
    draft = await get_content_draft(db, tenant.id, product_id)
    if draft is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="No draft found for this product")
    return _draft_out(draft)


@router.post("/products/{product_id}/draft/approve", response_model=ContentDraftOut)
async def approve_product_draft(
    product_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ContentDraftOut:
    draft = await get_content_draft(db, tenant.id, product_id)
    if draft is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="No draft found for this product")
    if draft.status == "approved":
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Draft already approved")
    product = await get_product_by_id(db, tenant.id, product_id)
    product.description_html = draft.draft_text
    db.add(product)
    draft = await approve_content_draft(db, draft)
    await db.commit()
    return _draft_out(draft)


@router.post("/bulk-generate", response_model=BulkGenerateResponse)
async def bulk_generate_endpoint(
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> BulkGenerateResponse:
    products = await list_products_without_draft(db, tenant.id)
    for product in products:
        generate_description.delay(str(tenant.id), str(product.id))
    return BulkGenerateResponse(queued=len(products))
