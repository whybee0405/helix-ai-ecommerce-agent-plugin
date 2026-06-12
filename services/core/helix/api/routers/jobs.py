from typing import Annotated
from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db, get_tenant
from helix.db.crud import jobs as jobs_crud
from helix.db.crud.products import list_products_without_embedding
from helix.db.models import Tenant
from helix.workers.tasks.embedding import embed_product

router = APIRouter(prefix="/v1/jobs", tags=["jobs"])


class JobOut(BaseModel):
    id: str
    type: str
    status: str
    progress: int
    total: int | None
    error: str | None
    started_at: str | None
    finished_at: str | None
    created_at: str


def _job_out(job) -> JobOut:
    return JobOut(
        id=str(job.id),
        type=job.type,
        status=job.status,
        progress=job.progress,
        total=job.total,
        error=job.error,
        started_at=job.started_at.isoformat() if job.started_at else None,
        finished_at=job.finished_at.isoformat() if job.finished_at else None,
        created_at=job.created_at.isoformat(),
    )


class BulkEmbedResponse(BaseModel):
    queued: int


@router.post("/embed/bulk", response_model=BulkEmbedResponse)
async def bulk_embed_endpoint(
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> BulkEmbedResponse:
    products = await list_products_without_embedding(db, tenant.id)
    for product in products:
        embed_product.delay(str(tenant.id), str(product.id))
    return BulkEmbedResponse(queued=len(products))


@router.get("/{job_id}", response_model=JobOut)
async def get_job_endpoint(
    job_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> JobOut:
    job = await jobs_crud.get_job(db, tenant.id, job_id)
    if not job:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Job not found")
    return _job_out(job)


@router.get("", response_model=list[JobOut])
async def list_jobs_endpoint(
    type: Annotated[str | None, Query()] = None,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> list[JobOut]:
    jobs = await jobs_crud.list_jobs(db, tenant.id, job_type=type)
    return [_job_out(j) for j in jobs]
