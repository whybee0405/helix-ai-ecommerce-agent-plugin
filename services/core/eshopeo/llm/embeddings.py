"""Local sentence-transformer embeddings for cheap semantic similarity work
(cache lookups, query routing, FAQ matching). Distinct from voyage which is
used for the high-quality product index — these vectors are 384-dim, fast on CPU,
and free."""

from __future__ import annotations

import asyncio
import hashlib
from functools import lru_cache
from typing import Optional

import structlog

logger = structlog.get_logger(__name__)

_MODEL_NAME = "BAAI/bge-small-en-v1.5"
_DIM = 384


_model = None
_model_lock = asyncio.Lock()


async def _get_model():
    global _model
    if _model is not None:
        return _model
    async with _model_lock:
        if _model is not None:
            return _model
        try:
            from sentence_transformers import SentenceTransformer
        except ImportError:
            logger.warning("sentence_transformers_not_installed")
            return None
        _model = await asyncio.to_thread(SentenceTransformer, _MODEL_NAME)
        logger.info("local_embedding_model_loaded", model=_MODEL_NAME, dim=_DIM)
        return _model


async def embed_text(text: str) -> Optional[list[float]]:
    """Return a normalised 384-dim embedding for `text`, or None if the model
    couldn't be loaded. Vectors are L2-normalised so cosine similarity reduces
    to a dot product."""
    if not text:
        return None
    model = await _get_model()
    if model is None:
        return None
    cached = _cached_embed(text)
    if cached is not None:
        return cached
    vec = await asyncio.to_thread(
        model.encode, text, normalize_embeddings=True, show_progress_bar=False
    )
    out = vec.tolist() if hasattr(vec, "tolist") else list(vec)
    _put_cached(text, out)
    return out


# in-process LRU cache for repeat queries (helps when the same query hits many handlers)
_INPROC_CACHE: dict[str, list[float]] = {}
_INPROC_KEYS: list[str] = []
_INPROC_MAX = 512


def _hash(text: str) -> str:
    return hashlib.sha1(text.encode("utf-8")).hexdigest()


def _cached_embed(text: str) -> Optional[list[float]]:
    return _INPROC_CACHE.get(_hash(text))


def _put_cached(text: str, vec: list[float]) -> None:
    k = _hash(text)
    if k in _INPROC_CACHE:
        return
    _INPROC_KEYS.append(k)
    _INPROC_CACHE[k] = vec
    while len(_INPROC_KEYS) > _INPROC_MAX:
        old = _INPROC_KEYS.pop(0)
        _INPROC_CACHE.pop(old, None)


@lru_cache(maxsize=1)
def embedding_dim() -> int:
    return _DIM
