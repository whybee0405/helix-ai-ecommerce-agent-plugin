from __future__ import annotations

from dataclasses import dataclass
from typing import Optional


@dataclass(frozen=True)
class Plan:
    tier: str
    display_name: str
    query_limit: int          # monthly AI queries
    product_limit: Optional[int]  # None = unlimited
    content_gen: bool         # blog, descriptions, repurpose
    industry_packs: bool      # kbeauty / automotive etc.
    wp_agent: bool
    priority_support: bool
    price_usd_cents: int
    price_zar_cents: int
    ai_ops_limit: int = 0     # 0=blocked, -1=unlimited, positive=monthly cap
    trial_days: int = 0


PLANS: dict[str, Plan] = {
    "free": Plan(
        tier="free",
        display_name="Free",
        query_limit=150,
        product_limit=50,
        content_gen=False,
        industry_packs=False,
        wp_agent=False,
        priority_support=False,
        price_usd_cents=0,
        price_zar_cents=0,
        ai_ops_limit=0,
    ),
    "starter": Plan(
        tier="starter",
        display_name="Starter",
        query_limit=2_500,
        product_limit=2_000,
        content_gen=False,
        industry_packs=False,
        wp_agent=False,
        priority_support=False,
        price_usd_cents=2_500,
        price_zar_cents=49_900,
        ai_ops_limit=0,
        trial_days=14,
    ),
    "growth": Plan(
        tier="growth",
        display_name="Growth",
        query_limit=10_000,
        product_limit=10_000,
        content_gen=True,
        industry_packs=True,
        wp_agent=True,
        priority_support=False,
        price_usd_cents=3_900,
        price_zar_cents=69_900,
        ai_ops_limit=100,
        trial_days=14,
    ),
    "pro": Plan(
        tier="pro",
        display_name="Pro",
        query_limit=25_000,
        product_limit=None,
        content_gen=True,
        industry_packs=True,
        wp_agent=True,
        priority_support=True,
        price_usd_cents=7_900,
        price_zar_cents=149_900,
        ai_ops_limit=-1,
        trial_days=14,
    ),
}

# Map Paddle price IDs → tier — populated at runtime from Settings.
# Call init_paddle_price_map() once at startup.
_paddle_price_to_tier: dict[str, str] = {}


def init_paddle_price_map(price_map: dict[str, str]) -> None:
    """price_map = {paddle_price_id: tier_name, ...}"""
    _paddle_price_to_tier.update(price_map)


def plan_from_paddle_price(price_id: str) -> Plan | None:
    tier = _paddle_price_to_tier.get(price_id)
    return PLANS.get(tier) if tier else None


def get_plan(tier: str) -> Plan:
    return PLANS.get(tier, PLANS["free"])


def get_query_limit(tier: str, plan_query_limit: int | None = None) -> int:
    """Return the effective monthly query limit for a tenant."""
    if plan_query_limit is not None:
        return plan_query_limit
    return get_plan(tier).query_limit


def get_ai_ops_limit(tier: str, plan_ai_ops_limit: int | None = None) -> int:
    """Return effective monthly AI ops limit. 0=blocked, -1=unlimited."""
    if plan_ai_ops_limit is not None:
        return plan_ai_ops_limit
    return get_plan(tier).ai_ops_limit
