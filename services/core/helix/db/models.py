from datetime import datetime, timezone
from typing import Optional
from uuid import UUID, uuid4

from pgvector.sqlalchemy import Vector
from sqlalchemy import (
    Boolean,
    ForeignKey,
    Integer,
    LargeBinary,
    Numeric,
    String,
    Text,
    UniqueConstraint,
)
from sqlalchemy.dialects.postgresql import JSONB, TIMESTAMP
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column


def _now() -> datetime:
    return datetime.now(timezone.utc)


class Base(DeclarativeBase):
    pass


class Tenant(Base):
    __tablename__ = "tenant"

    id: Mapped[UUID] = mapped_column(primary_key=True, default=uuid4)
    name: Mapped[str] = mapped_column(String, nullable=False)
    platform: Mapped[str] = mapped_column(String, nullable=False)
    store_url: Mapped[str] = mapped_column(String, nullable=False)
    credentials_enc: Mapped[bytes] = mapped_column(LargeBinary, nullable=False)
    public_key: Mapped[UUID] = mapped_column(unique=True, default=uuid4)
    created_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True), nullable=False, default=_now
    )
    pack_id: Mapped[Optional[str]] = mapped_column(String, nullable=True)


class Product(Base):
    __tablename__ = "product"
    __table_args__ = (
        UniqueConstraint("tenant_id", "platform_id", name="uq_product_tenant_platform"),
    )

    id: Mapped[UUID] = mapped_column(primary_key=True, default=uuid4)
    tenant_id: Mapped[UUID] = mapped_column(
        ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False, index=True
    )
    platform_id: Mapped[str] = mapped_column(String, nullable=False)
    title: Mapped[str] = mapped_column(String, nullable=False)
    description_html: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    price_minor: Mapped[int] = mapped_column(Integer, nullable=False)
    currency: Mapped[str] = mapped_column(String(3), nullable=False)
    images: Mapped[list] = mapped_column(JSONB, nullable=False, default=list)
    categories: Mapped[list] = mapped_column(JSONB, nullable=False, default=list)
    in_stock: Mapped[bool] = mapped_column(Boolean, nullable=False)
    domain_attributes: Mapped[dict] = mapped_column(JSONB, nullable=False, default=dict)
    embedding: Mapped[Optional[list[float]]] = mapped_column(Vector(1024), nullable=True)
    updated_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True), nullable=False, default=_now, onupdate=_now
    )


class Customer(Base):
    __tablename__ = "customer"
    __table_args__ = (
        UniqueConstraint("tenant_id", "platform_id", name="uq_customer_tenant_platform"),
    )

    id: Mapped[UUID] = mapped_column(primary_key=True, default=uuid4)
    tenant_id: Mapped[UUID] = mapped_column(
        ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False, index=True
    )
    platform_id: Mapped[str] = mapped_column(String, nullable=False)
    email_hash: Mapped[str] = mapped_column(String, nullable=False)
    profile: Mapped[dict] = mapped_column(JSONB, nullable=False, default=dict)
    created_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True), nullable=False, default=_now
    )


class Order(Base):
    __tablename__ = "order"
    __table_args__ = (
        UniqueConstraint("tenant_id", "platform_id", name="uq_order_tenant_platform"),
    )

    id: Mapped[UUID] = mapped_column(primary_key=True, default=uuid4)
    tenant_id: Mapped[UUID] = mapped_column(
        ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False, index=True
    )
    platform_id: Mapped[str] = mapped_column(String, nullable=False)
    customer_id: Mapped[Optional[UUID]] = mapped_column(
        ForeignKey("customer.id"), nullable=True
    )
    total_minor: Mapped[int] = mapped_column(Integer, nullable=False)
    currency: Mapped[str] = mapped_column(String(3), nullable=False)
    status: Mapped[str] = mapped_column(String, nullable=False)
    line_items: Mapped[list] = mapped_column(JSONB, nullable=False, default=list)
    placed_at: Mapped[datetime] = mapped_column(TIMESTAMP(timezone=True), nullable=False)


class Job(Base):
    __tablename__ = "job"

    id: Mapped[UUID] = mapped_column(primary_key=True, default=uuid4)
    tenant_id: Mapped[UUID] = mapped_column(
        ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False, index=True
    )
    type: Mapped[str] = mapped_column(String, nullable=False)
    status: Mapped[str] = mapped_column(String, nullable=False, default="pending")
    progress: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    total: Mapped[Optional[int]] = mapped_column(Integer, nullable=True)
    error: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    started_at: Mapped[Optional[datetime]] = mapped_column(
        TIMESTAMP(timezone=True), nullable=True
    )
    finished_at: Mapped[Optional[datetime]] = mapped_column(
        TIMESTAMP(timezone=True), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True), nullable=False, default=_now
    )


class UsageEvent(Base):
    __tablename__ = "usage_event"

    id: Mapped[UUID] = mapped_column(primary_key=True, default=uuid4)
    tenant_id: Mapped[UUID] = mapped_column(
        ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False, index=True
    )
    model: Mapped[str] = mapped_column(String, nullable=False)
    tokens_in: Mapped[int] = mapped_column(Integer, nullable=False)
    tokens_out: Mapped[int] = mapped_column(Integer, nullable=False)
    cost_usd: Mapped[float] = mapped_column(Numeric(10, 6), nullable=False)
    endpoint: Mapped[str] = mapped_column(String, nullable=False)
    created_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True), nullable=False, default=_now
    )
