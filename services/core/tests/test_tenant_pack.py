from helix.db.models import Tenant
from uuid import uuid4


def test_tenant_pack_id_defaults_none():
    t = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
               credentials_enc=b"enc", pack_id=None)
    assert t.pack_id is None


def test_tenant_pack_id_can_be_set():
    t = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
               credentials_enc=b"enc", pack_id="haircare")
    assert t.pack_id == "haircare"
