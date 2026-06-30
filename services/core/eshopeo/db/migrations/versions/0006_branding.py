"""add branding, tier columns to tenant

Revision ID: 0006
Revises: 0005
Create Date: 2026-06-15
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql

revision: str = "0006"
down_revision: Union[str, None] = "0005"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.add_column(
        "tenant",
        sa.Column("branding", postgresql.JSONB, nullable=False, server_default="{}"),
    )
    op.add_column(
        "tenant",
        sa.Column("branding_version", sa.Integer, nullable=False, server_default="1"),
    )
    op.add_column(
        "tenant",
        sa.Column("tier", sa.String(32), nullable=False, server_default="free"),
    )
    op.add_column(
        "tenant",
        sa.Column("daily_budget_usd", sa.Numeric(10, 4), nullable=True),
    )


def downgrade() -> None:
    op.drop_column("tenant", "daily_budget_usd")
    op.drop_column("tenant", "tier")
    op.drop_column("tenant", "branding_version")
    op.drop_column("tenant", "branding")
