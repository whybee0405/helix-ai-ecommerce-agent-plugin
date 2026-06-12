from uuid import UUID

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from helix.db.models import Conversation, ConversationMessage


async def create_conversation(
    session: AsyncSession,
    tenant_id: UUID,
    customer_id: UUID | None = None,
) -> Conversation:
    conv = Conversation(tenant_id=tenant_id, customer_id=customer_id)
    session.add(conv)
    await session.flush()
    await session.refresh(conv)
    return conv


async def append_messages(
    session: AsyncSession,
    conversation_id: UUID,
    tenant_id: UUID,
    user_content: str,
    assistant_content: str,
    source: str | None,
    products_referenced: list[str],
) -> tuple[ConversationMessage, ConversationMessage]:
    user_msg = ConversationMessage(
        conversation_id=conversation_id,
        tenant_id=tenant_id,
        role="user",
        content=user_content,
        source=None,
        products_referenced=[],
    )
    assistant_msg = ConversationMessage(
        conversation_id=conversation_id,
        tenant_id=tenant_id,
        role="assistant",
        content=assistant_content,
        source=source,
        products_referenced=products_referenced,
    )
    session.add(user_msg)
    session.add(assistant_msg)
    await session.flush()
    await session.refresh(user_msg)
    await session.refresh(assistant_msg)
    return user_msg, assistant_msg


async def get_conversation(
    session: AsyncSession,
    conversation_id: UUID,
    tenant_id: UUID,
) -> Conversation | None:
    result = await session.execute(
        select(Conversation).where(
            Conversation.id == conversation_id,
            Conversation.tenant_id == tenant_id,
        )
    )
    return result.scalar_one_or_none()


async def list_conversations(
    session: AsyncSession,
    tenant_id: UUID,
    limit: int = 20,
    offset: int = 0,
) -> list[Conversation]:
    result = await session.execute(
        select(Conversation)
        .where(Conversation.tenant_id == tenant_id)
        .order_by(Conversation.created_at.desc())
        .limit(limit)
        .offset(offset)
    )
    return list(result.scalars())


async def get_messages(
    session: AsyncSession,
    conversation_id: UUID,
    tenant_id: UUID,
) -> list[ConversationMessage]:
    result = await session.execute(
        select(ConversationMessage)
        .where(
            ConversationMessage.conversation_id == conversation_id,
            ConversationMessage.tenant_id == tenant_id,
        )
        .order_by(ConversationMessage.created_at)
    )
    return list(result.scalars())


async def get_message(
    session: AsyncSession,
    message_id: UUID,
    tenant_id: UUID,
) -> ConversationMessage | None:
    result = await session.execute(
        select(ConversationMessage).where(
            ConversationMessage.id == message_id,
            ConversationMessage.tenant_id == tenant_id,
        )
    )
    return result.scalar_one_or_none()


async def set_message_feedback(
    session: AsyncSession,
    message_id: UUID,
    tenant_id: UUID,
    feedback: str,
) -> ConversationMessage | None:
    msg = await get_message(session, message_id, tenant_id)
    if msg is None:
        return None
    msg.feedback = feedback
    session.add(msg)
    await session.flush()
    await session.refresh(msg)
    return msg
