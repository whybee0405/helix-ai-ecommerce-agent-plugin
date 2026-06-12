from datetime import datetime, timezone
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

import pytest

from helix.db.crud.content import (
    approve_content_draft,
    get_content_draft,
    list_products_without_draft,
    upsert_content_draft,
)
from helix.db.models import ContentDraft, Product


async def test_upsert_content_draft_creates_new():
    session = AsyncMock()
    session.execute = AsyncMock()
    session.flush = AsyncMock()
    session.refresh = AsyncMock()

    tenant_id = uuid4()
    product_id = uuid4()

    draft = await upsert_content_draft(session, tenant_id, product_id, "description_html", "<p>Draft</p>")

    session.add.assert_called_once()
    session.flush.assert_called_once()
    session.refresh.assert_called_once()


async def test_approve_content_draft_sets_status():
    session = AsyncMock()
    session.flush = AsyncMock()
    session.refresh = AsyncMock()

    draft = MagicMock(spec=ContentDraft)
    draft.status = "pending"
    draft.approved_at = None

    await approve_content_draft(session, draft)

    assert draft.status == "approved"
    assert draft.approved_at is not None
    session.add.assert_called_once_with(draft)


async def test_get_content_draft_returns_none_when_missing():
    mock_result = MagicMock()
    mock_result.scalar_one_or_none.return_value = None

    session = AsyncMock()
    session.execute = AsyncMock(return_value=mock_result)

    result = await get_content_draft(session, uuid4(), uuid4())
    assert result is None
