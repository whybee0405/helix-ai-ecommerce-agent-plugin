from typing import Annotated
from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.api.deps import get_db, get_tenant
from eshopeo.config import get_settings
from eshopeo.connectors.writeback import write_back_to_platform
from eshopeo.db.crud.content import (
    approve_content_draft,
    count_content_drafts,
    get_content_draft,
    list_content_drafts,
    list_products_without_draft,
)
from eshopeo.db.crud.products import get_product_by_id
from eshopeo.db.models import Tenant
from eshopeo.workers.tasks.content import generate_description
from eshopeo.workers.tasks.seo import generate_seo_metadata

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


class SeoGenerateResponse(BaseModel):
    product_id: str
    queued: bool


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


class ApproveDraftOut(BaseModel):
    product_id: str
    field: str
    draft_text: str
    status: str
    created_at: str
    approved_at: str | None
    platform_synced: bool


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
    field: str = Query(default="description_html"),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ContentDraftOut:
    draft = await get_content_draft(db, tenant.id, product_id, field=field)
    if draft is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="No draft found for this product")
    return _draft_out(draft)


@router.post("/products/{product_id}/draft/approve", response_model=ApproveDraftOut)
async def approve_product_draft(
    product_id: UUID,
    field: str = Query(default="description_html"),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ApproveDraftOut:
    draft = await get_content_draft(db, tenant.id, product_id, field=field)
    if draft is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="No draft found for this product")
    if draft.status == "approved":
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Draft already approved")

    if field == "description_html":
        product = await get_product_by_id(db, tenant.id, product_id)
        product.description_html = draft.draft_text
        db.add(product)

    draft = await approve_content_draft(db, draft)
    await db.commit()

    platform_synced = False
    if field == "description_html":
        settings = get_settings()
        product_row = await get_product_by_id(db, tenant.id, product_id)
        platform_synced = await write_back_to_platform(
            tenant, product_row.platform_id, field, draft.draft_text, settings
        )

    return ApproveDraftOut(
        product_id=str(draft.product_id),
        field=draft.field,
        draft_text=draft.draft_text,
        status=draft.status,
        created_at=draft.created_at.isoformat(),
        approved_at=draft.approved_at.isoformat() if draft.approved_at else None,
        platform_synced=platform_synced,
    )


@router.post("/bulk-generate", response_model=BulkGenerateResponse)
async def bulk_generate_endpoint(
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> BulkGenerateResponse:
    products = await list_products_without_draft(db, tenant.id)
    for product in products:
        generate_description.delay(str(tenant.id), str(product.id))
    return BulkGenerateResponse(queued=len(products))


@router.post("/products/{product_id}/generate-seo",
             response_model=SeoGenerateResponse, status_code=202)
async def generate_product_seo(
    product_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> SeoGenerateResponse:
    product = await get_product_by_id(db, tenant.id, product_id)
    if product is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Product not found")
    generate_seo_metadata.delay(str(tenant.id), str(product_id))
    return SeoGenerateResponse(product_id=str(product_id), queued=True)


@router.post("/bulk-generate-seo", response_model=BulkGenerateResponse)
async def bulk_generate_seo_endpoint(
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> BulkGenerateResponse:
    products = await list_products_without_draft(db, tenant.id, field="meta_title")
    for product in products:
        generate_seo_metadata.delay(str(tenant.id), str(product.id))
    return BulkGenerateResponse(queued=len(products))
