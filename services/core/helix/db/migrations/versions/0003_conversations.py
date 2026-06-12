"""create conversation tables

Revision ID: 0003
Revises: 0002
Create Date: 2026-06-12
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql

revision: str = "0003"
down_revision: Union[str, None] = "0002"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "conversation",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column("tenant_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False),
        sa.Column("customer_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("customer.id", ondelete="SET NULL"), nullable=True),
        sa.Column("created_at", sa.TIMESTAMP(timezone=True), nullable=False),
        sa.Column("updated_at", sa.TIMESTAMP(timezone=True), nullable=False),
    )
    op.create_index("ix_conversation_tenant_id", "conversation", ["tenant_id"])

    op.create_table(
        "conversation_message",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column("conversation_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("conversation.id", ondelete="CASCADE"), nullable=False),
        sa.Column("tenant_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False),
        sa.Column("role", sa.String(16), nullable=False),
        sa.Column("content", sa.Text, nullable=False),
        sa.Column("source", sa.String(16), nullable=True),
        sa.Column("products_referenced", postgresql.JSONB, nullable=False, server_default="[]"),
        sa.Column("feedback", sa.String(16), nullable=True),
        sa.Column("created_at", sa.TIMESTAMP(timezone=True), nullable=False),
    )
    op.create_index("ix_conversation_message_conversation_id", "conversation_message", ["conversation_id"])
    op.create_index("ix_conversation_message_tenant_id", "conversation_message", ["tenant_id"])


def downgrade() -> None:
    op.drop_table("conversation_message")
    op.drop_table("conversation")
