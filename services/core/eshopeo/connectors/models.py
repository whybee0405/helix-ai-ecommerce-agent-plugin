from datetime import datetime
from typing import Literal
from uuid import UUID

from pydantic import BaseModel


class CanonicalProduct(BaseModel):
    tenant_id: UUID
    platform: Literal["woocommerce", "shopify"]
    platform_id: str
    title: str
    description_html: str | None = None
    price_minor: int
    currency: str
    images: list[str] = []
    categories: list[str] = []
    in_stock: bool
    domain_attributes: dict = {}
    deleted: bool = False


class CanonicalCustomer(BaseModel):
    tenant_id: UUID
    platform: Literal["woocommerce", "shopify"]
    platform_id: str
    email_hash: str
    profile: dict = {}


class CanonicalOrder(BaseModel):
    tenant_id: UUID
    platform: Literal["woocommerce", "shopify"]
    platform_id: str
    customer_platform_id: str | None = None
    total_minor: int
    currency: str
    status: str
    line_items: list[dict] = []
    placed_at: datetime
