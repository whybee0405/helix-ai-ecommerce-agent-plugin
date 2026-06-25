"""add ai_ops and byok columns to tenant

Revision ID: 0012
Revises: 0011
Create Date: 2026-06-24
"""
from alembic import op
import sqlalchemy as sa

revision = "0012"
down_revision = "0011"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.add_column("tenant", sa.Column("plan_ai_ops_limit", sa.Integer(), nullable=True))
    op.add_column("tenant", sa.Column("anthropic_api_key_enc", sa.LargeBinary(), nullable=True))


def downgrade() -> None:
    op.drop_column("tenant", "anthropic_api_key_enc")
    op.drop_column("tenant", "plan_ai_ops_limit")
