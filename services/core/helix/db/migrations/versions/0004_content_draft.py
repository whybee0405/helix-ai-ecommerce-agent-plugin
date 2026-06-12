"""create content_draft table

Revision ID: 0004
Revises: 0003
Create Date: 2026-06-12
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql

revision: str = "0004"
down_revision: Union[str, None] = "0003"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "content_draft",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column("tenant_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False),
        sa.Column("product_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("product.id", ondelete="CASCADE"), nullable=False),
        sa.Column("field", sa.String(64), nullable=False),
        sa.Column("draft_text", sa.Text, nullable=False),
        sa.Column("status", sa.String(32), nullable=False, server_default="pending"),
        sa.Column("created_at", sa.TIMESTAMP(timezone=True), nullable=False),
        sa.Column("approved_at", sa.TIMESTAMP(timezone=True), nullable=True),
        sa.UniqueConstraint("tenant_id", "product_id", "field", name="uq_content_draft_tenant_product_field"),
    )
    op.create_index("ix_content_draft_tenant_id", "content_draft", ["tenant_id"])
    op.create_index("ix_content_draft_product_id", "content_draft", ["product_id"])


def downgrade() -> None:
    op.drop_index("ix_content_draft_product_id", table_name="content_draft")
    op.drop_index("ix_content_draft_tenant_id", table_name="content_draft")
    op.drop_table("content_draft")
