import asyncio
from unittest.mock import AsyncMock, MagicMock
from uuid import uuid4

from helix.db.crud.conversations import (
    append_messages,
    create_conversation,
    get_conversation,
    set_message_feedback,
)
from helix.db.models import ConversationMessage


def _make_session():
    session = AsyncMock()
    session.flush = AsyncMock()
    session.refresh = AsyncMock(side_effect=lambda obj: None)
    added = []
    session.add = lambda obj: added.append(obj)
    session._added = added
    return session


def test_create_conversation_sets_tenant_id():
    session = _make_session()
    tenant_id = uuid4()

    asyncio.run(create_conversation(session, tenant_id))

    assert len(session._added) == 1
    assert session._added[0].tenant_id == tenant_id
    assert session._added[0].customer_id is None


def test_create_conversation_with_customer_id():
    session = _make_session()
    tenant_id = uuid4()
    customer_id = uuid4()

    asyncio.run(create_conversation(session, tenant_id, customer_id))

    assert session._added[0].customer_id == customer_id


def test_append_messages_creates_two_rows():
    session = _make_session()
    conversation_id = uuid4()
    tenant_id = uuid4()

    user_msg, assistant_msg = asyncio.run(append_messages(
        session, conversation_id, tenant_id,
        user_content="What moisturizer?",
        assistant_content="Try Ceramide Moisturizer",
        source="llm",
        products_referenced=["prod-1"],
    ))

    assert len(session._added) == 2
    roles = {m.role for m in session._added}
    assert roles == {"user", "assistant"}

    user_obj = next(m for m in session._added if m.role == "user")
    assert user_obj.content == "What moisturizer?"
    assert user_obj.source is None

    asst_obj = next(m for m in session._added if m.role == "assistant")
    assert asst_obj.content == "Try Ceramide Moisturizer"
    assert asst_obj.source == "llm"
    assert asst_obj.products_referenced == ["prod-1"]


def test_get_conversation_tenant_scoped():
    session = AsyncMock()
    mock_result = MagicMock()
    mock_result.scalar_one_or_none.return_value = None
    session.execute = AsyncMock(return_value=mock_result)

    result = asyncio.run(get_conversation(session, uuid4(), uuid4()))

    assert result is None
    call_stmt = session.execute.call_args[0][0]
    whereclause = str(call_stmt.whereclause)
    assert "tenant_id" in whereclause


def test_set_message_feedback_updates_field():
    session = AsyncMock()
    message_id = uuid4()
    tenant_id = uuid4()

    mock_msg = MagicMock(spec=ConversationMessage)
    mock_msg.feedback = None
    mock_result = MagicMock()
    mock_result.scalar_one_or_none.return_value = mock_msg
    session.execute = AsyncMock(return_value=mock_result)
    session.flush = AsyncMock()
    session.refresh = AsyncMock()
    added = []
    session.add = lambda obj: added.append(obj)

    result = asyncio.run(set_message_feedback(session, message_id, tenant_id, "thumbs_up"))

    assert mock_msg.feedback == "thumbs_up"
    assert result is mock_msg
