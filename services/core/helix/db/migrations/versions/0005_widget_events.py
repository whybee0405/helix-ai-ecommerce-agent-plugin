"""add widget_event table

Revision ID: 0005
Revises: 0004
Create Date: 2026-06-15
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql

revision: str = "0005"
down_revision: Union[str, None] = "0004"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "widget_event",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column("tenant_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False),
        sa.Column("conversation_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("conversation.id", ondelete="SET NULL"), nullable=True),
        sa.Column("event_type", sa.String(64), nullable=False),
        sa.Column("event_data", postgresql.JSONB, nullable=False, server_default="{}"),
        sa.Column("created_at", sa.TIMESTAMP(timezone=True), nullable=False),
    )
    op.create_index("ix_widget_event_tenant_id", "widget_event", ["tenant_id"])
    op.create_index("ix_widget_event_conversation_id", "widget_event", ["conversation_id"])
    op.create_index("ix_widget_event_event_type", "widget_event", ["event_type"])


def downgrade() -> None:
    op.drop_index("ix_widget_event_event_type", table_name="widget_event")
    op.drop_index("ix_widget_event_conversation_id", table_name="widget_event")
    op.drop_index("ix_widget_event_tenant_id", table_name="widget_event")
    op.drop_table("widget_event")
