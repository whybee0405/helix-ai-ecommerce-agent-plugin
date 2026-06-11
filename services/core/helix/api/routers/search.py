from typing import Annotated

import structlog
from fastapi import APIRouter, Depends, Query
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db, get_tenant
from helix.config import get_settings
from helix.db.crud.products import vector_search_products
from helix.db.models import Tenant
from helix.domain.search import embed_query

logger = structlog.get_logger(__name__)
router = APIRouter(prefix="/v1/search", tags=["search"])


class ProductResult(BaseModel):
    id: str
    platform_id: str
    title: str
    price_minor: int
    currency: str
    in_stock: bool
    categories: list[str]
    domain_attributes: dict
    score: float


class SearchResponse(BaseModel):
    results: list[ProductResult]
    total: int


@router.get("/products", response_model=SearchResponse)
async def search_products(
    q: Annotated[str, Query(min_length=1)],
    limit: Annotated[int, Query(ge=1, le=50)] = 10,
    in_stock_only: bool = False,
    category: str | None = Query(default=None),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> SearchResponse:
    settings = get_settings()
    query_vector = await embed_query(q, settings)
    rows = await vector_search_products(db, tenant.id, query_vector, limit, in_stock_only, category=category)
    results = [
        ProductResult(
            id=str(p.id),
            platform_id=p.platform_id,
            title=p.title,
            price_minor=p.price_minor,
            currency=p.currency,
            in_stock=p.in_stock,
            categories=p.categories or [],
            domain_attributes=p.domain_attributes or {},
            score=round(score, 4),
        )
        for p, score in rows
    ]
    return SearchResponse(results=results, total=len(results))
