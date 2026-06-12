from datetime import date, datetime, timedelta, timezone
from uuid import UUID

from sqlalchemy import case, func, select
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
    return list(result.scalars().all())


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
    return list(result.scalars().all())


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
    if msg is None or msg.role != "assistant":
        return None
    msg.feedback = feedback
    session.add(msg)
    await session.flush()
    await session.refresh(msg)
    return msg


async def get_conversation_analytics(
    session: AsyncSession,
    tenant_id: UUID,
    start: date,
    end: date,
) -> dict:
    start_dt = datetime(start.year, start.month, start.day, tzinfo=timezone.utc)
    end_dt = datetime(end.year, end.month, end.day, tzinfo=timezone.utc) + timedelta(days=1)

    conv_result = await session.execute(
        select(func.count(Conversation.id)).where(
            Conversation.tenant_id == tenant_id,
            Conversation.created_at >= start_dt,
            Conversation.created_at < end_dt,
        )
    )
    total_conversations = conv_result.scalar_one() or 0

    msg_result = await session.execute(
        select(
            func.count(ConversationMessage.id),
            func.count(ConversationMessage.feedback),
            func.sum(
                case(
                    (ConversationMessage.feedback == "thumbs_up", 1),
                    else_=0,
                )
            ),
        ).where(
            ConversationMessage.tenant_id == tenant_id,
            ConversationMessage.created_at >= start_dt,
            ConversationMessage.created_at < end_dt,
        )
    )
    total_messages, feedback_count, feedback_positive_count = msg_result.one()
    total_messages = total_messages or 0
    feedback_count = feedback_count or 0
    feedback_positive_count = int(feedback_positive_count or 0)

    avg_messages = (
        round(total_messages / total_conversations, 1)
        if total_conversations > 0
        else 0.0
    )
    feedback_positive_rate = (
        round(feedback_positive_count / feedback_count, 2)
        if feedback_count > 0
        else None
    )

    return {
        "total_conversations": total_conversations,
        "total_messages": total_messages,
        "avg_messages_per_conversation": avg_messages,
        "feedback_count": feedback_count,
        "feedback_positive_rate": feedback_positive_rate,
    }


async def get_top_queries(
    session: AsyncSession,
    tenant_id: UUID,
    limit: int = 10,
    start: date | None = None,
    end: date | None = None,
) -> list[dict]:
    stmt = (
        select(
            ConversationMessage.content,
            func.count(ConversationMessage.id).label("cnt"),
        )
        .where(
            ConversationMessage.tenant_id == tenant_id,
            ConversationMessage.role == "user",
        )
    )

    if start:
        start_dt = datetime(start.year, start.month, start.day, tzinfo=timezone.utc)
        stmt = stmt.where(ConversationMessage.created_at >= start_dt)
    if end:
        end_dt = datetime(end.year, end.month, end.day, tzinfo=timezone.utc) + timedelta(days=1)
        stmt = stmt.where(ConversationMessage.created_at < end_dt)

    stmt = (
        stmt
        .group_by(ConversationMessage.content)
        .order_by(func.count(ConversationMessage.id).desc())
        .limit(limit)
    )

    result = await session.execute(stmt)
    return [{"query": row.content, "count": row.cnt} for row in result.all()]


async def list_conversations_by_customer(
    session: AsyncSession,
    tenant_id: UUID,
    customer_id: UUID,
    limit: int = 20,
    offset: int = 0,
) -> list[Conversation]:
    result = await session.execute(
        select(Conversation)
        .where(
            Conversation.tenant_id == tenant_id,
            Conversation.customer_id == customer_id,
        )
        .order_by(Conversation.created_at.desc())
        .limit(limit)
        .offset(offset)
    )
    return result.scalars().all()


async def get_top_referenced_products(
    session: AsyncSession,
    tenant_id: UUID,
    limit: int = 10,
    start: "date | None" = None,
    end: "date | None" = None,
) -> list[dict]:
    pid_col = func.jsonb_array_elements_text(
        ConversationMessage.products_referenced
    ).column_valued("pid")

    stmt = (
        select(pid_col, func.count().label("cnt"))
        .where(
            ConversationMessage.tenant_id == tenant_id,
            ConversationMessage.role == "assistant",
        )
    )

    if start:
        start_dt = datetime(start.year, start.month, start.day, tzinfo=timezone.utc)
        stmt = stmt.where(ConversationMessage.created_at >= start_dt)
    if end:
        end_dt = datetime(end.year, end.month, end.day, tzinfo=timezone.utc) + timedelta(days=1)
        stmt = stmt.where(ConversationMessage.created_at < end_dt)

    stmt = stmt.group_by(pid_col).order_by(func.count().desc()).limit(limit)
    result = await session.execute(stmt)
    return [{"product_id": row.pid, "count": row.cnt} for row in result.all()]
