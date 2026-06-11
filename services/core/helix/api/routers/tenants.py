from typing import Annotated, Any
from uuid import uuid4

from fastapi import APIRouter, Depends, Header, HTTPException, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.auth.crypto import encrypt_credentials
from helix.api.deps import get_db
from helix.config import get_settings
from helix.db.crud.tenants import create_tenant
from helix.db.models import Tenant

router = APIRouter(prefix="/v1/tenants", tags=["tenants"])


class ProvisionRequest(BaseModel):
    name: str
    platform: str
    store_url: str
    credentials: dict[str, Any]


class ProvisionResponse(BaseModel):
    tenant_id: str
    public_key: str


@router.post("", status_code=status.HTTP_201_CREATED, response_model=ProvisionResponse)
async def provision_tenant(
    body: ProvisionRequest,
    x_helix_provision_key: Annotated[str | None, Header()] = None,
    db: AsyncSession = Depends(get_db),
) -> ProvisionResponse:
    settings = get_settings()
    if x_helix_provision_key != settings.provision_key.get_secret_value():
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid provision key")

    enc = encrypt_credentials(body.credentials, settings.credential_encryption_key.get_secret_value())
    tenant = Tenant(
        name=body.name,
        platform=body.platform,
        store_url=body.store_url,
        credentials_enc=enc,
    )
    tenant = await create_tenant(db, tenant)
    await db.commit()

    return ProvisionResponse(
        tenant_id=str(tenant.id),
        public_key=str(tenant.public_key),
    )
