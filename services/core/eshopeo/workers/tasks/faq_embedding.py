"""Celery task: embed a single FAQ entry using Voyage AI."""
from uuid import UUID

import httpx
import structlog

from eshopeo.workers.celery_app import celery_app

logger = structlog.get_logger(__name__)

_VOYAGE_URL = "https://api.voyageai.com/v1/embeddings"
_VOYAGE_MODEL = "voyage-3"


def _embed_and_store(tenant_id: str, faq_id: str) -> None:
    from eshopeo.config import get_settings
    from eshopeo.db.engine import get_sync_session
    from eshopeo.db.models import Faq

    settings = get_settings()
    with get_sync_session() as session:
        faq = session.get(Faq, UUID(faq_id))
        if faq is None or str(faq.tenant_id) != tenant_id:
            logger.warning("embed_faq_not_found", faq_id=faq_id)
            return

        text = f"{faq.question}\n{faq.answer}"
        resp = httpx.post(
            _VOYAGE_URL,
            json={"input": [text], "model": _VOYAGE_MODEL},
            headers={"Authorization": f"Bearer {settings.voyage_api_key.get_secret_value()}"},
            timeout=30.0,
        )
        resp.raise_for_status()
        embedding = resp.json()["data"][0]["embedding"]

        faq.embedding = embedding
        session.commit()
        logger.info("embed_faq_done", faq_id=faq_id, dims=len(embedding))


@celery_app.task(
    bind=True,
    max_retries=3,
    default_retry_delay=60,
    name="eshopeo.workers.tasks.faq_embedding.embed_faq",
    queue="embedding",
)
def embed_faq(self, tenant_id: str, faq_id: str) -> None:
    try:
        _embed_and_store(tenant_id, faq_id)
    except httpx.HTTPError as exc:
        raise self.retry(exc=exc)
