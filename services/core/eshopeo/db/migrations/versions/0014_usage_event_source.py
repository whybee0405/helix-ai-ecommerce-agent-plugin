"""add source column to usage_event

Revision ID: 0014
Revises: 0013
Create Date: 2026-07-03
"""
from alembic import op
import sqlalchemy as sa

revision = "0014"
down_revision = "0013"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.add_column(
        "usage_event",
        sa.Column("source", sa.String(), nullable=True),
    )


def downgrade() -> None:
    op.drop_column("usage_event", "source")
