"""
Conversations router — dashboard read access to widget chat history.

GET /v1/conversations                    — list conversations
GET /v1/conversations/{conversation_id}  — conversation detail + messages
"""

from typing import Annotated
from uuid import UUID

import structlog
from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.api.deps import get_db, get_tenant
from eshopeo.db.crud.conversations import get_conversation, get_messages, list_conversations
from eshopeo.db.models import Tenant

logger = structlog.get_logger(__name__)
router = APIRouter(prefix="/v1/conversations", tags=["conversations"])


class ConversationSummary(BaseModel):
    id: str
    customer_id: str | None
    created_at: str
    updated_at: str


class MessageOut(BaseModel):
    id: str
    role: str
    content: str
    source: str | None
    feedback: str | None
    created_at: str


class ConversationDetail(BaseModel):
    id: str
    tenant_id: str
    customer_id: str | None
    created_at: str
    messages: list[MessageOut]


class ConversationListResponse(BaseModel):
    conversations: list[ConversationSummary]


@router.get("", response_model=ConversationListResponse)
async def list_conversations_endpoint(
    limit: Annotated[int, Query(ge=1, le=100)] = 20,
    offset: Annotated[int, Query(ge=0)] = 0,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ConversationListResponse:
    """GET /v1/conversations."""
    convs = await list_conversations(db, tenant.id, limit=limit, offset=offset)
    return ConversationListResponse(
        conversations=[
            ConversationSummary(
                id=str(c.id),
                customer_id=str(c.customer_id) if c.customer_id else None,
                created_at=c.created_at.isoformat(),
                updated_at=c.updated_at.isoformat(),
            )
            for c in convs
        ]
    )


@router.get("/{conversation_id}", response_model=ConversationDetail)
async def get_conversation_endpoint(
    conversation_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ConversationDetail:
    """GET /v1/conversations/{conversation_id}."""
    conv = await get_conversation(db, conversation_id, tenant.id)
    if conv is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Conversation not found")
    messages = await get_messages(db, conversation_id, tenant.id)
    return ConversationDetail(
        id=str(conv.id),
        tenant_id=str(conv.tenant_id),
        customer_id=str(conv.customer_id) if conv.customer_id else None,
        created_at=conv.created_at.isoformat(),
        messages=[
            MessageOut(
                id=str(m.id),
                role=m.role,
                content=m.content,
                source=m.source,
                feedback=m.feedback,
                created_at=m.created_at.isoformat(),
            )
            for m in messages
        ],
    )
