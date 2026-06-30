import httpx
import structlog

from eshopeo.config import Settings

logger = structlog.get_logger(__name__)

_VOYAGE_URL = "https://api.voyageai.com/v1/embeddings"
_VOYAGE_MODEL = "voyage-3"


async def embed_query(query: str, settings: Settings) -> list[float]:
    async with httpx.AsyncClient(timeout=15.0) as client:
        resp = await client.post(
            _VOYAGE_URL,
            json={"input": [query], "model": _VOYAGE_MODEL},
            headers={"Authorization": f"Bearer {settings.voyage_api_key.get_secret_value()}"},
        )
        resp.raise_for_status()
    return resp.json()["data"][0]["embedding"]
