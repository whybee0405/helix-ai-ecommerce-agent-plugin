from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_tenant
from eshopeo.db.models import Customer, Tenant
from tests.conftest import make_test_settings


def _make_mock_customer():
    from datetime import datetime, timezone
    c = MagicMock(spec=Customer)
    c.id = uuid4()
    c.platform_id = "cust-001"
    c.email_hash = "abc123"
    c.profile = {"skin_type": "oily"}
    c.created_at = datetime(2026, 6, 1, tzinfo=timezone.utc)
    return c


def test_customer_list_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_customers = [_make_mock_customer(), _make_mock_customer()]

    with (
        patch(
            "eshopeo.api.routers.customers.list_customers",
            new_callable=AsyncMock,
            return_value=mock_customers,
        ),
        patch(
            "eshopeo.api.routers.customers.count_customers",
            new_callable=AsyncMock,
            return_value=2,
        ),
    ):
        r = client.get("/v1/customers")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert len(data["customers"]) == 2
    assert data["total"] == 2


def test_customer_detail_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_customer = _make_mock_customer()
    customer_id = mock_customer.id

    with patch(
        "eshopeo.api.routers.customers.get_customer_by_id",
        new_callable=AsyncMock,
        return_value=mock_customer,
    ):
        r = client.get(f"/v1/customers/{customer_id}")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["id"] == str(customer_id)


def test_customer_detail_404_on_unknown():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    customer_id = uuid4()

    with patch(
        "eshopeo.api.routers.customers.get_customer_by_id",
        new_callable=AsyncMock,
        return_value=None,
    ):
        r = client.get(f"/v1/customers/{customer_id}")

    app.dependency_overrides.clear()

    assert r.status_code == 404
