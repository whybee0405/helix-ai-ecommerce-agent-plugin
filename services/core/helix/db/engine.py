from collections.abc import AsyncGenerator, Generator
from contextlib import contextmanager
from typing import Optional

from sqlalchemy import create_engine
from sqlalchemy.ext.asyncio import (
    AsyncSession,
    async_sessionmaker,
    create_async_engine,
)
from sqlalchemy.orm import Session, sessionmaker

from helix.config import get_settings

_async_engine = None
_async_session_factory = None
_sync_engine = None
_sync_session_factory_obj = None


def _get_async_session_factory():
    global _async_engine, _async_session_factory
    if _async_session_factory is None:
        settings = get_settings()
        _async_engine = create_async_engine(
            settings.database_url_async,
            echo=settings.environment == "development",
            pool_pre_ping=True,
        )
        _async_session_factory = async_sessionmaker(_async_engine, expire_on_commit=False)
    return _async_session_factory


def _get_sync_session_factory():
    global _sync_engine, _sync_session_factory_obj
    if _sync_session_factory_obj is None:
        settings = get_settings()
        _sync_engine = create_engine(
            settings.database_url_sync,
            pool_pre_ping=True,
        )
        _sync_session_factory_obj = sessionmaker(_sync_engine, expire_on_commit=False)
    return _sync_session_factory_obj


# Backwards-compatible module-level references (lazy proxies)
class _LazyAsyncSessionFactory:
    def __call__(self):
        return _get_async_session_factory()()

    def __getattr__(self, item):
        return getattr(_get_async_session_factory(), item)


async_session_factory = _LazyAsyncSessionFactory()


async def get_async_session() -> AsyncGenerator[AsyncSession, None]:
    factory = _get_async_session_factory()
    async with factory() as session:
        yield session


@contextmanager
def get_sync_session() -> Generator[Session, None, None]:
    session = _get_sync_session_factory()()
    try:
        yield session
    finally:
        session.close()
