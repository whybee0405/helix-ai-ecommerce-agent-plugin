"""
Tenant provisioning router — used by the provisioning key holder (not tenants themselves).

POST  /v1/tenants                    — provision a new tenant
GET   /v1/tenants/{tenant_id}        — tenant detail
PATCH /v1/tenants/{tenant_id}        — update a tenant
"""

import secrets
from typing import Annotated, Any
from uuid import UUID

from fastapi import APIRouter, Depends, Header, HTTPException, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.api.auth.crypto import encrypt_credentials
from eshopeo.api.deps import get_db
from eshopeo.branding.presets import get_preset
from eshopeo.config import get_settings
from eshopeo.db.crud.tenants import create_tenant, get_tenant_by_id, update_tenant
from eshopeo.db.models import Tenant

router = APIRouter(prefix="/v1/tenants", tags=["tenants"])


class ProvisionRequest(BaseModel):
    name: str
    platform: str
    store_url: str
    credentials: dict[str, Any]
    pack_id: str = "kbeauty"
    preset_id: str = "general"


class ProvisionResponse(BaseModel):
    tenant_id: str
    public_key: str
    admin_secret: str


class TenantDetail(BaseModel):
    tenant_id: str
    name: str
    platform: str
    store_url: str
    pack_id: str | None
    public_key: str
    created_at: str


class TenantPatchRequest(BaseModel):
    name: str | None = None
    pack_id: str | None = None


def _auth_provision_key(
    x_eshopeo_provision_key: Annotated[str | None, Header()] = None,
) -> str:
    settings = get_settings()
    if x_eshopeo_provision_key != settings.provision_key.get_secret_value():
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid provision key")
    return x_eshopeo_provision_key


@router.post("", status_code=status.HTTP_201_CREATED, response_model=ProvisionResponse)
async def provision_tenant(
    body: ProvisionRequest,
    _: str = Depends(_auth_provision_key),
    db: AsyncSession = Depends(get_db),
) -> ProvisionResponse:
    """POST /v1/tenants."""
    settings = get_settings()

    admin_secret = secrets.token_hex(32)
    creds_with_admin = {**body.credentials, "admin_secret": admin_secret}
    enc = encrypt_credentials(
        creds_with_admin, settings.credential_encryption_key.get_secret_value()
    )

    preset = get_preset(body.preset_id)
    branding_payload = preset.model_dump(mode="json")

    tenant = Tenant(
        name=body.name,
        platform=body.platform,
        store_url=body.store_url,
        credentials_enc=enc,
        pack_id=body.pack_id,
        branding=branding_payload,
        branding_version=1,
    )
    tenant = await create_tenant(db, tenant)
    await db.commit()

    try:
        from eshopeo.workers.tasks.faq_warm import warm_tenant_faq
        warm_tenant_faq.delay(str(tenant.id))
    except Exception:  # noqa: BLE001
        pass

    return ProvisionResponse(
        tenant_id=str(tenant.id),
        public_key=str(tenant.public_key),
        admin_secret=admin_secret,
    )


@router.get("/{tenant_id}", response_model=TenantDetail)
async def get_tenant_endpoint(
    tenant_id: UUID,
    _: str = Depends(_auth_provision_key),
    db: AsyncSession = Depends(get_db),
) -> TenantDetail:
    """GET /v1/tenants/{tenant_id}."""
    tenant = await get_tenant_by_id(db, tenant_id)
    if not tenant:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Tenant not found")
    return TenantDetail(
        tenant_id=str(tenant.id),
        name=tenant.name,
        platform=tenant.platform,
        store_url=tenant.store_url,
        pack_id=tenant.pack_id,
        public_key=str(tenant.public_key),
        created_at=tenant.created_at.isoformat(),
    )


@router.patch("/{tenant_id}", response_model=TenantDetail)
async def patch_tenant_endpoint(
    tenant_id: UUID,
    body: TenantPatchRequest,
    _: str = Depends(_auth_provision_key),
    db: AsyncSession = Depends(get_db),
) -> TenantDetail:
    """PATCH /v1/tenants/{tenant_id}."""
    tenant = await get_tenant_by_id(db, tenant_id)
    if not tenant:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Tenant not found")
    updates = {k: v for k, v in body.model_dump(exclude_unset=True).items() if v is not None}
    if updates:
        tenant = await update_tenant(db, tenant, **updates)
        await db.commit()
    return TenantDetail(
        tenant_id=str(tenant.id),
        name=tenant.name,
        platform=tenant.platform,
        store_url=tenant.store_url,
        pack_id=tenant.pack_id,
        public_key=str(tenant.public_key),
        created_at=tenant.created_at.isoformat(),
    )
