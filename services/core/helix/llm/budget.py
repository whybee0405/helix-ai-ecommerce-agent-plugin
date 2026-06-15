"""Per-tenant cost budgets + circuit breaker.

Reads the tenant's daily LLM spend (rolling 24h) and enforces a soft cap that
downgrades to Haiku and a hard cap that switches to cache-only mode."""

from __future__ import annotations

from datetime import datetime, timedelta, timezone
from decimal import Decimal
from enum import Enum
from typing import Optional
from uuid import UUID

import redis.asyncio as aioredis
import structlog
from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from helix.config import Settings
from helix.db.models import UsageEvent

logger = structlog.get_logger(__name__)


_TIER_DEFAULTS = {
    "free":       Decimal("0.50"),
    "starter":    Decimal("5.00"),
    "pro":        Decimal("50.00"),
    "enterprise": Decimal("500.00"),
}

_SOFT_PCT = Decimal("0.80")  # downgrade to Haiku at 80%
_HARD_PCT = Decimal("1.00")  # cache-only above 100%


class BudgetMode(str, Enum):
    NORMAL = "normal"          # full quality (Sonnet for GENERATE)
    DEGRADED = "degraded"      # force Haiku
    BLOCKED = "blocked"        # no LLM calls; cache-only responses


class BudgetCheck:
    def __init__(self, mode: BudgetMode, daily_spend: Decimal, daily_limit: Decimal) -> None:
        self.mode = mode
        self.daily_spend = daily_spend
        self.daily_limit = daily_limit

    def remaining(self) -> Decimal:
        return self.daily_limit - self.daily_spend


async def get_budget_limit(tenant_id: UUID, tier: str, override: Optional[Decimal]) -> Decimal:
    if override is not None:
        return Decimal(str(override))
    return _TIER_DEFAULTS.get(tier, _TIER_DEFAULTS["free"])


async def check_budget(
    db: AsyncSession,
    redis: aioredis.Redis,
    tenant_id: UUID,
    tier: str,
    daily_budget_override: Optional[Decimal],
) -> BudgetCheck:
    """Compute today's spend (rolling 24h) from usage_event, classify against tier limit."""
    cache_key = f"hxbudget:spend:{tenant_id}"
    cached = await redis.get(cache_key)
    if cached:
        spend = Decimal(cached)
    else:
        since = datetime.now(timezone.utc) - timedelta(hours=24)
        result = await db.execute(
            select(func.coalesce(func.sum(UsageEvent.cost_usd), 0))
            .where(UsageEvent.tenant_id == tenant_id)
            .where(UsageEvent.created_at >= since)
        )
        spend = Decimal(str(result.scalar_one()))
        await redis.setex(cache_key, 60, str(spend))

    limit = await get_budget_limit(tenant_id, tier, daily_budget_override)
    if spend >= limit * _HARD_PCT:
        mode = BudgetMode.BLOCKED
    elif spend >= limit * _SOFT_PCT:
        mode = BudgetMode.DEGRADED
    else:
        mode = BudgetMode.NORMAL
    return BudgetCheck(mode=mode, daily_spend=spend, daily_limit=limit)


# ─── Token-bucket rate limit (per-tenant) ────────────────────────────────────


_BUCKET_CAPACITY = 30        # max burst
_BUCKET_REFILL_PER_SEC = 0.5  # ~30 calls / minute steady-state


async def acquire_token(redis: aioredis.Redis, tenant_id: UUID) -> bool:
    """Token bucket. Returns True if a token was available."""
    now_ms = int(datetime.now(timezone.utc).timestamp() * 1000)
    key = f"hxbucket:{tenant_id}"
    pipe = redis.pipeline()
    pipe.hgetall(key)
    state, = await pipe.execute()
    last_ms = int(state.get("ts", now_ms)) if state else now_ms
    tokens = float(state.get("tk", _BUCKET_CAPACITY)) if state else float(_BUCKET_CAPACITY)
    elapsed_s = max(0, (now_ms - last_ms) / 1000.0)
    tokens = min(_BUCKET_CAPACITY, tokens + elapsed_s * _BUCKET_REFILL_PER_SEC)
    allowed = tokens >= 1.0
    if allowed:
        tokens -= 1.0
    await redis.hset(key, mapping={"ts": now_ms, "tk": tokens})
    await redis.expire(key, 600)
    return allowed
