import json
from uuid import UUID

import httpx
import structlog

from helix.workers.celery_app import celery_app

logger = structlog.get_logger(__name__)

_VOYAGE_URL = "https://api.voyageai.com/v1/embeddings"
_VOYAGE_MODEL = "voyage-3"
_VOYAGE_BATCH_SIZE = 128


def _build_embed_text(title: str, categories: list, domain_attributes: dict) -> str:
    cats = ", ".join(categories) if categories else ""
    attrs = json.dumps(domain_attributes)
    return f"{title} | {cats} | {attrs}"


def _embed_and_store(tenant_id: str, product_id: str) -> None:
    # Lazy imports to avoid module-level settings/engine initialisation in test environments
    from helix.config import get_settings
    from helix.db.engine import get_sync_session
    from helix.db.models import Product

    settings = get_settings()
    with get_sync_session() as session:
        product = session.get(Product, UUID(product_id))
        if product is None or str(product.tenant_id) != tenant_id:
            logger.warning("embed_product_not_found", product_id=product_id)
            return

        text = _build_embed_text(product.title, product.categories or [], product.domain_attributes or {})

        resp = httpx.post(
            _VOYAGE_URL,
            json={"input": [text], "model": _VOYAGE_MODEL},
            headers={"Authorization": f"Bearer {settings.voyage_api_key.get_secret_value()}"},
            timeout=30.0,
        )
        resp.raise_for_status()
        embedding = resp.json()["data"][0]["embedding"]

        product.embedding = embedding
        session.commit()
        logger.info("embed_product_done", product_id=product_id, dims=len(embedding))


@celery_app.task(bind=True, max_retries=3, default_retry_delay=60, name="helix.workers.tasks.embedding.embed_product")
def embed_product(self, tenant_id: str, product_id: str) -> None:
    try:
        _embed_and_store(tenant_id, product_id)
    except httpx.HTTPError as exc:
        raise self.retry(exc=exc)


@celery_app.task(name="helix.workers.tasks.embedding.embed_product_batch")
def embed_product_batch(tenant_id: str, product_ids: list[str]) -> dict:
    # Lazy imports to avoid module-level settings/engine initialisation in test environments
    from helix.config import get_settings
    from helix.db.engine import get_sync_session
    from helix.db.models import Product

    settings = get_settings()
    results = {"ok": 0, "failed": 0}

    for i in range(0, len(product_ids), _VOYAGE_BATCH_SIZE):
        batch_ids = product_ids[i : i + _VOYAGE_BATCH_SIZE]
        with get_sync_session() as session:
            products = [
                session.get(Product, UUID(pid))
                for pid in batch_ids
            ]
            products = [p for p in products if p and str(p.tenant_id) == tenant_id]
            if not products:
                continue

            texts = [
                _build_embed_text(p.title, p.categories or [], p.domain_attributes or {})
                for p in products
            ]
            try:
                resp = httpx.post(
                    _VOYAGE_URL,
                    json={"input": texts, "model": _VOYAGE_MODEL},
                    headers={"Authorization": f"Bearer {settings.voyage_api_key.get_secret_value()}"},
                    timeout=60.0,
                )
                resp.raise_for_status()
                embeddings = [item["embedding"] for item in resp.json()["data"]]
                for product, emb in zip(products, embeddings):
                    product.embedding = emb
                session.commit()
                results["ok"] += len(products)
            except httpx.HTTPError as exc:
                logger.error("embed_batch_error", error=str(exc), batch_size=len(products))
                results["failed"] += len(products)

    return results
