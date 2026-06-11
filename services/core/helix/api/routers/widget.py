from fastapi import APIRouter, Depends, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.auth.tokens import issue_widget_token
from helix.api.deps import get_db, get_tenant
from helix.config import get_settings
from helix.db.models import Tenant

router = APIRouter(prefix="/v1/widget", tags=["widget"])


class SessionResponse(BaseModel):
    token: str
    expires_in: int = 900


@router.post("/session", response_model=SessionResponse)
async def issue_session(
    tenant: Tenant = Depends(get_tenant),
) -> SessionResponse:
    settings = get_settings()
    token = issue_widget_token(tenant.id, settings.session_secret.get_secret_value())
    return SessionResponse(token=token)
