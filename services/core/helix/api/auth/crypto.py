import json

from cryptography.fernet import Fernet


def encrypt_credentials(data: dict, key: str) -> bytes:
    return Fernet(key.encode()).encrypt(json.dumps(data).encode())


def decrypt_credentials(enc: bytes, key: str) -> dict:
    raw = Fernet(key.encode()).decrypt(enc)
    return json.loads(raw.decode())
