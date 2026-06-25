"""add billing columns to tenant

Revision ID: 0011
Revises: 0010
Create Date: 2026-06-23
"""
from alembic import op
import sqlalchemy as sa

revision = "0011"
down_revision = "0010"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.add_column("tenant", sa.Column("billing_email", sa.String(320), nullable=True))
    op.add_column("tenant", sa.Column(
        "subscription_status", sa.String(32), nullable=False,
        server_default="trialing",
    ))
    op.add_column("tenant", sa.Column(
        "trial_ends_at", sa.TIMESTAMP(timezone=True), nullable=True,
    ))
    op.add_column("tenant", sa.Column("paddle_customer_id", sa.String(64), nullable=True))
    op.add_column("tenant", sa.Column("paddle_subscription_id", sa.String(64), nullable=True))
    op.add_column("tenant", sa.Column("plan_query_limit", sa.Integer(), nullable=True))


def downgrade() -> None:
    op.drop_column("tenant", "plan_query_limit")
    op.drop_column("tenant", "paddle_subscription_id")
    op.drop_column("tenant", "paddle_customer_id")
    op.drop_column("tenant", "trial_ends_at")
    op.drop_column("tenant", "subscription_status")
    op.drop_column("tenant", "billing_email")
