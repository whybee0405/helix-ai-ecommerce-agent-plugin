"""Initial schema with pgvector

Revision ID: 0001
Revises:
Create Date: 2026-06-11
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from pgvector.sqlalchemy import Vector
from sqlalchemy.dialects import postgresql

revision: str = "0001"
down_revision: Union[str, None] = None
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.execute("CREATE EXTENSION IF NOT EXISTS vector")

    op.create_table(
        "tenant",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column("name", sa.String, nullable=False),
        sa.Column("platform", sa.String, nullable=False),
        sa.Column("store_url", sa.String, nullable=False),
        sa.Column("credentials_enc", sa.LargeBinary, nullable=False),
        sa.Column("public_key", postgresql.UUID(as_uuid=True), nullable=False, unique=True),
        sa.Column(
            "created_at",
            sa.TIMESTAMP(timezone=True),
            nullable=False,
            server_default=sa.text("now()"),
        ),
    )

    op.create_table(
        "product",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column(
            "tenant_id",
            postgresql.UUID(as_uuid=True),
            sa.ForeignKey("tenant.id", ondelete="CASCADE"),
            nullable=False,
            index=True,
        ),
        sa.Column("platform_id", sa.String, nullable=False),
        sa.Column("title", sa.String, nullable=False),
        sa.Column("description_html", sa.Text, nullable=True),
        sa.Column("price_minor", sa.Integer, nullable=False),
        sa.Column("currency", sa.String(3), nullable=False),
        sa.Column("images", postgresql.JSONB, nullable=False, server_default="'[]'"),
        sa.Column("categories", postgresql.JSONB, nullable=False, server_default="'[]'"),
        sa.Column("in_stock", sa.Boolean, nullable=False),
        sa.Column(
            "domain_attributes", postgresql.JSONB, nullable=False, server_default="'{}'"
        ),
        sa.Column("embedding", Vector(1024), nullable=True),
        sa.Column(
            "updated_at",
            sa.TIMESTAMP(timezone=True),
            nullable=False,
            server_default=sa.text("now()"),
        ),
        sa.UniqueConstraint("tenant_id", "platform_id", name="uq_product_tenant_platform"),
    )

    op.create_table(
        "customer",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column(
            "tenant_id",
            postgresql.UUID(as_uuid=True),
            sa.ForeignKey("tenant.id", ondelete="CASCADE"),
            nullable=False,
            index=True,
        ),
        sa.Column("platform_id", sa.String, nullable=False),
        sa.Column("email_hash", sa.String, nullable=False),
        sa.Column("profile", postgresql.JSONB, nullable=False, server_default="'{}'"),
        sa.Column(
            "created_at",
            sa.TIMESTAMP(timezone=True),
            nullable=False,
            server_default=sa.text("now()"),
        ),
        sa.UniqueConstraint("tenant_id", "platform_id", name="uq_customer_tenant_platform"),
    )

    op.create_table(
        "order",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column(
            "tenant_id",
            postgresql.UUID(as_uuid=True),
            sa.ForeignKey("tenant.id", ondelete="CASCADE"),
            nullable=False,
            index=True,
        ),
        sa.Column("platform_id", sa.String, nullable=False),
        sa.Column(
            "customer_id",
            postgresql.UUID(as_uuid=True),
            sa.ForeignKey("customer.id"),
            nullable=True,
        ),
        sa.Column("total_minor", sa.Integer, nullable=False),
        sa.Column("currency", sa.String(3), nullable=False),
        sa.Column("status", sa.String, nullable=False),
        sa.Column("line_items", postgresql.JSONB, nullable=False, server_default="'[]'"),
        sa.Column("placed_at", sa.TIMESTAMP(timezone=True), nullable=False),
        sa.UniqueConstraint("tenant_id", "platform_id", name="uq_order_tenant_platform"),
    )

    op.create_table(
        "job",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column(
            "tenant_id",
            postgresql.UUID(as_uuid=True),
            sa.ForeignKey("tenant.id", ondelete="CASCADE"),
            nullable=False,
            index=True,
        ),
        sa.Column("type", sa.String, nullable=False),
        sa.Column("status", sa.String, nullable=False, server_default="'pending'"),
        sa.Column("progress", sa.Integer, nullable=False, server_default="0"),
        sa.Column("total", sa.Integer, nullable=True),
        sa.Column("error", sa.Text, nullable=True),
        sa.Column("started_at", sa.TIMESTAMP(timezone=True), nullable=True),
        sa.Column("finished_at", sa.TIMESTAMP(timezone=True), nullable=True),
        sa.Column(
            "created_at",
            sa.TIMESTAMP(timezone=True),
            nullable=False,
            server_default=sa.text("now()"),
        ),
    )

    op.create_table(
        "usage_event",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column(
            "tenant_id",
            postgresql.UUID(as_uuid=True),
            sa.ForeignKey("tenant.id", ondelete="CASCADE"),
            nullable=False,
            index=True,
        ),
        sa.Column("model", sa.String, nullable=False),
        sa.Column("tokens_in", sa.Integer, nullable=False),
        sa.Column("tokens_out", sa.Integer, nullable=False),
        sa.Column("cost_usd", sa.Numeric(10, 6), nullable=False),
        sa.Column("endpoint", sa.String, nullable=False),
        sa.Column(
            "created_at",
            sa.TIMESTAMP(timezone=True),
            nullable=False,
            server_default=sa.text("now()"),
        ),
    )

    op.create_index(
        "ix_product_embedding_hnsw",
        "product",
        ["embedding"],
        postgresql_using="hnsw",
        postgresql_ops={"embedding": "vector_cosine_ops"},
    )


def downgrade() -> None:
    op.drop_table("usage_event")
    op.drop_table("job")
    op.drop_table("order")
    op.drop_table("customer")
    op.drop_table("product")
    op.drop_table("tenant")
    op.execute("DROP EXTENSION IF EXISTS vector")
