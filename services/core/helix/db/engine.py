from collections.abc import AsyncGenerator
from contextlib import contextmanager
from collections.abc import Generator

from sqlalchemy import create_engine
from sqlalchemy.ext.asyncio import (
    AsyncSession,
    async_sessionmaker,
    create_async_engine,
)
from sqlalchemy.orm import Session, sessionmaker

from helix.config import get_settings

_settings = get_settings()

async_engine = create_async_engine(
    _settings.database_url_async,
    echo=_settings.environment == "development",
    pool_pre_ping=True,
)
async_session_factory = async_sessionmaker(async_engine, expire_on_commit=False)


async def get_async_session() -> AsyncGenerator[AsyncSession, None]:
    async with async_session_factory() as session:
        yield session


sync_engine = create_engine(
    _settings.database_url_sync,
    pool_pre_ping=True,
)
sync_session_factory = sessionmaker(sync_engine, expire_on_commit=False)


@contextmanager
def get_sync_session() -> Generator[Session, None, None]:
    session = sync_session_factory()
    try:
        yield session
    finally:
        session.close()
