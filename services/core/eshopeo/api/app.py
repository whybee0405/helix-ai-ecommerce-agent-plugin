import logging

import structlog
from fastapi import FastAPI
from starlette.middleware.cors import CORSMiddleware

from eshopeo.config import Settings


def configure_logging(log_level: str) -> None:
    structlog.configure(
        processors=[
            structlog.processors.TimeStamper(fmt="iso"),
            structlog.stdlib.add_log_level,
            structlog.processors.JSONRenderer(),
        ],
        wrapper_class=structlog.make_filtering_bound_logger(
            getattr(logging, log_level.upper(), logging.INFO)
        ),
        logger_factory=structlog.PrintLoggerFactory(),
    )


def create_app(settings: Settings | None = None) -> FastAPI:
    from eshopeo.config import get_settings

    s = settings or get_settings()
    configure_logging(s.log_level)

    app = FastAPI(title=s.brand_name, version="0.1.0", docs_url="/docs")

    from eshopeo.api.routers import health

    app.include_router(health.router)

    from eshopeo.api.routers import tenants
    app.include_router(tenants.router)

    from eshopeo.api.routers import sync
    app.include_router(sync.router)

    from eshopeo.api.routers import webhooks, widget
    app.include_router(webhooks.router)
    app.include_router(widget.router)

    from eshopeo.api.routers import shopify_webhooks
    app.include_router(shopify_webhooks.router)

    from eshopeo.api.routers import search
    app.include_router(search.router)

    from eshopeo.api.routers import analytics
    app.include_router(analytics.router)

    from eshopeo.api.routers import jobs
    app.include_router(jobs.router)

    from eshopeo.api.routers import admin
    app.include_router(admin.router)

    from eshopeo.api.routers import packs
    app.include_router(packs.router)

    from eshopeo.api.routers import conversations
    app.include_router(conversations.router)

    from eshopeo.api.routers import customers
    app.include_router(customers.router)

    from eshopeo.api.routers import content
    app.include_router(content.router)

    from eshopeo.api.routers import products
    app.include_router(products.router)

    from eshopeo.api.routers import dashboard
    app.include_router(dashboard.router)

    from eshopeo.api.routers import plugin as plugin_router
    app.include_router(plugin_router.router)

    from eshopeo.api.routers import branding
    app.include_router(branding.router)

    from eshopeo.api.routers import wp_agent
    app.include_router(wp_agent.router)

    from eshopeo.api.routers import alt_text
    app.include_router(alt_text.router)

    from eshopeo.api.routers import seo_meta
    app.include_router(seo_meta.router)

    from eshopeo.api.routers import reviews
    app.include_router(reviews.router)

    from eshopeo.api.routers import internal_links
    app.include_router(internal_links.router)

    from eshopeo.api.routers import digest
    app.include_router(digest.router)

    from eshopeo.api.routers import blog_post
    app.include_router(blog_post.router)

    from eshopeo.api.routers import product_descriptions as product_desc_router
    app.include_router(product_desc_router.router)

    from eshopeo.api.routers import repurpose
    app.include_router(repurpose.router)

    from eshopeo.api.routers import auth as auth_router
    app.include_router(auth_router.router)

    from eshopeo.api.routers import billing as billing_router
    app.include_router(billing_router.router)

    from eshopeo.packs.registry import load_all_packs
    load_all_packs(s.packs_dir)

    # Initialise Paddle price → tier mapping from settings.
    from eshopeo.billing.plans import init_paddle_price_map
    price_map: dict[str, str] = {}
    for tier in ("starter", "growth", "pro"):
        for currency in ("usd", "zar"):
            price_id = getattr(s, f"paddle_price_{tier}_{currency}", "")
            if price_id:
                price_map[price_id] = tier
    if price_map:
        init_paddle_price_map(price_map)

    from eshopeo.api.middleware.rate_limit import RateLimitMiddleware
    from eshopeo.api.middleware.quota import QuotaMiddleware
    from eshopeo.api.middleware.request_id import RequestIdMiddleware

    app.add_middleware(RateLimitMiddleware, settings=s)
    app.add_middleware(QuotaMiddleware, settings=s)
    app.add_middleware(RequestIdMiddleware)
    app.add_middleware(
        CORSMiddleware,
        allow_origins=s.cors_allowed_origins,
        allow_methods=["GET", "POST", "PATCH", "PUT", "DELETE"],
        allow_headers=["Authorization", "Content-Type", "X-eShopeo-Tenant-Key",
                       "X-eShopeo-Provision-Key", "X-eShopeo-Tenant-Id",
                       "X-eShopeo-Timestamp", "X-eShopeo-Signature", "X-Request-Id"],
        expose_headers=["X-Request-Id"],
    )

    return app


try:
    app = create_app()
except Exception:
    # Module-level app creation may fail in test environments without .env
    app = None  # type: ignore[assignment]
