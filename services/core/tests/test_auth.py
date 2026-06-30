import pytest
from cryptography.fernet import Fernet
from uuid import uuid4

from eshopeo.api.auth.crypto import decrypt_credentials, encrypt_credentials
from eshopeo.api.auth.tokens import InvalidTokenError, issue_widget_token, validate_widget_token


def test_encrypt_decrypt_roundtrip():
    key = Fernet.generate_key().decode()
    data = {"consumer_key": "ck_abc", "consumer_secret": "cs_xyz"}
    enc = encrypt_credentials(data, key)
    assert isinstance(enc, bytes)
    result = decrypt_credentials(enc, key)
    assert result == data


def test_decrypt_with_wrong_key_raises():
    key1 = Fernet.generate_key().decode()
    key2 = Fernet.generate_key().decode()
    enc = encrypt_credentials({"x": 1}, key1)
    with pytest.raises(Exception):
        decrypt_credentials(enc, key2)


def test_issue_and_validate_widget_token():
    secret = "a" * 32
    tenant_id = uuid4()
    token = issue_widget_token(tenant_id, secret)
    assert isinstance(token, str)
    result = validate_widget_token(token, secret)
    assert result == tenant_id


def test_validate_expired_token_raises():
    from datetime import datetime, timezone, timedelta
    from jose import jwt

    secret = "a" * 32
    tenant_id = uuid4()
    payload = {
        "tenant_id": str(tenant_id),
        "exp": datetime.now(timezone.utc) - timedelta(seconds=1),
    }
    expired_token = jwt.encode(payload, secret, algorithm="HS256")
    with pytest.raises(InvalidTokenError):
        validate_widget_token(expired_token, secret)


def test_validate_garbage_token_raises():
    with pytest.raises(InvalidTokenError):
        validate_widget_token("not.a.token", "secret")
