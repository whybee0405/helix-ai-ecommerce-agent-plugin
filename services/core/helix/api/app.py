import logging

import structlog
from fastapi import FastAPI

from helix.config import Settings


def configure_logging(log_level: str) -> None:
    structlog.configure(
        processors=[
            structlog.processors.TimeStamper(fmt="iso"),
            structlog.stdlib.add_log_level,
            structlog.stdlib.add_logger_name,
            structlog.processors.JSONRenderer(),
        ],
        wrapper_class=structlog.make_filtering_bound_logger(
            getattr(logging, log_level.upper(), logging.INFO)
        ),
        logger_factory=structlog.PrintLoggerFactory(),
    )


def create_app(settings: Settings | None = None) -> FastAPI:
    from helix.config import get_settings

    s = settings or get_settings()
    configure_logging(s.log_level)

    app = FastAPI(title=s.brand_name, version="0.1.0", docs_url="/docs")

    from helix.api.routers import health

    app.include_router(health.router)

    return app


try:
    app = create_app()
except Exception:
    # Module-level app creation may fail in test environments without .env
    app = None  # type: ignore[assignment]
