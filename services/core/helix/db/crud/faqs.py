from uuid import UUID, uuid4

import structlog
from sqlalchemy import select, delete
from sqlalchemy.dialects.postgresql import insert
from sqlalchemy.ext.asyncio import AsyncSession

from helix.db.models import Faq

logger = structlog.get_logger(__name__)


async def upsert_faq(
    session: AsyncSession,
    tenant_id: UUID,
    faq_id: UUID | None,
    question: str,
    answer: str,
) -> Faq:
    """Create or update a FAQ record. Returns the saved Faq."""
    if faq_id is None:
        faq_id = uuid4()

    stmt = (
        insert(Faq)
        .values(
            id=faq_id,
            tenant_id=tenant_id,
            question=question,
            answer=answer,
        )
        .on_conflict_do_update(
            index_elements=["id"],
            set_=dict(question=question, answer=answer),
        )
        .returning(Faq)
    )
    result = await session.execute(stmt)
    return result.scalar_one()


async def list_faqs(session: AsyncSession, tenant_id: UUID) -> list[Faq]:
    result = await session.execute(
        select(Faq)
        .where(Faq.tenant_id == tenant_id)
        .order_by(Faq.created_at.asc())
    )
    return list(result.scalars().all())


async def get_faq(session: AsyncSession, faq_id: UUID, tenant_id: UUID) -> Faq | None:
    result = await session.execute(
        select(Faq).where(Faq.id == faq_id, Faq.tenant_id == tenant_id)
    )
    return result.scalar_one_or_none()


async def delete_faq(session: AsyncSession, faq_id: UUID, tenant_id: UUID) -> bool:
    result = await session.execute(
        delete(Faq).where(Faq.id == faq_id, Faq.tenant_id == tenant_id)
    )
    return result.rowcount > 0


async def vector_search_faqs(
    session: AsyncSession,
    tenant_id: UUID,
    query_embedding: list[float],
    limit: int = 3,
) -> list[tuple[Faq, float]]:
    """Return FAQs ranked by cosine similarity to query_embedding."""
    from pgvector.sqlalchemy import Vector
    from sqlalchemy import func, cast

    embedding_col = Faq.embedding
    distance = embedding_col.cosine_distance(cast(query_embedding, Vector(1536)))

    result = await session.execute(
        select(Faq, distance.label("distance"))
        .where(Faq.tenant_id == tenant_id)
        .where(Faq.embedding.is_not(None))
        .order_by(distance)
        .limit(limit)
    )
    return [(row.Faq, row.distance) for row in result.all()]
