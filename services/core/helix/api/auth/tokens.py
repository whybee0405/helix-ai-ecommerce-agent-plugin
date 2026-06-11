from datetime import datetime, timedelta, timezone
from uuid import UUID

from jose import JWTError, jwt


class InvalidTokenError(Exception):
    pass


def issue_widget_token(tenant_id: UUID, secret: str) -> str:
    exp = datetime.now(timezone.utc) + timedelta(minutes=15)
    return jwt.encode({"tenant_id": str(tenant_id), "exp": exp}, secret, algorithm="HS256")


def validate_widget_token(token: str, secret: str) -> UUID:
    try:
        payload = jwt.decode(token, secret, algorithms=["HS256"])
        return UUID(payload["tenant_id"])
    except (JWTError, KeyError, ValueError) as exc:
        raise InvalidTokenError(str(exc)) from exc
