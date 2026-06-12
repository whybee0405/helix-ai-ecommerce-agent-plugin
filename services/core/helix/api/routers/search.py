from typing import Annotated
from uuid import UUID

import structlog
from fastapi import APIRouter, Depends, HTTPException, Query
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db, get_tenant
from helix.config import get_settings
from helix.db.crud.products import browse_products, get_similar_products, suggest_product_titles, vector_search_products
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


class SuggestResponse(BaseModel):
    suggestions: list[str]
    prefix: str


class ProductOut(BaseModel):
    id: str
    platform_id: str
    title: str
    price_minor: int
    currency: str
    in_stock: bool
    categories: list[str]
    domain_attributes: dict


class BrowseResponse(BaseModel):
    products: list[ProductOut]
    total: int
    limit: int
    offset: int


@router.get("/browse", response_model=BrowseResponse)
async def browse_products_endpoint(
    in_stock_only: bool = False,
    category: str | None = Query(default=None),
    min_price: int | None = Query(default=None, ge=0),
    max_price: int | None = Query(default=None, ge=0),
    limit: Annotated[int, Query(ge=1, le=100)] = 20,
    offset: Annotated[int, Query(ge=0)] = 0,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> BrowseResponse:
    products, total = await browse_products(
        db, tenant.id,
        in_stock_only=in_stock_only,
        category=category,
        min_price=min_price,
        max_price=max_price,
        limit=limit,
        offset=offset,
    )
    return BrowseResponse(
        products=[
            ProductOut(
                id=str(p.id),
                platform_id=p.platform_id,
                title=p.title,
                price_minor=p.price_minor,
                currency=p.currency,
                in_stock=p.in_stock,
                categories=p.categories or [],
                domain_attributes=p.domain_attributes or {},
            )
            for p in products
        ],
        total=total,
        limit=limit,
        offset=offset,
    )


@router.get("/suggest", response_model=SuggestResponse)
async def suggest_products(
    q: Annotated[str, Query(min_length=1)],
    limit: Annotated[int, Query(ge=1, le=20)] = 5,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> SuggestResponse:
    suggestions = await suggest_product_titles(db, tenant.id, q, limit)
    return SuggestResponse(suggestions=suggestions, prefix=q)


@router.get("/products", response_model=SearchResponse)
async def search_products(
    q: Annotated[str, Query(min_length=1)],
    limit: Annotated[int, Query(ge=1, le=50)] = 10,
    in_stock_only: bool = False,
    category: str | None = Query(default=None),
    min_price: int | None = Query(default=None, ge=0),
    max_price: int | None = Query(default=None, ge=0),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> SearchResponse:
    settings = get_settings()
    query_vector = await embed_query(q, settings)
    rows = await vector_search_products(
        db, tenant.id, query_vector, limit, in_stock_only,
        category=category, min_price=min_price, max_price=max_price,
    )
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


@router.get("/similar/{product_id}", response_model=SearchResponse)
async def get_similar_products_endpoint(
    product_id: UUID,
    limit: Annotated[int, Query(ge=1, le=20)] = 5,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> SearchResponse:
    rows = await get_similar_products(db, tenant.id, product_id, limit)
    if not rows:
        raise HTTPException(status_code=404, detail="Product not found or has no embedding")
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
