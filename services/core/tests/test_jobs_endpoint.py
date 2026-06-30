from datetime import datetime, timezone
from unittest.mock import AsyncMock, patch
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_db, get_tenant
from eshopeo.db.models import Job, Tenant
from tests.conftest import make_test_settings


@pytest.fixture
def tenant():
    t = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
               credentials_enc=b"enc")
    t.id = uuid4()
    t.public_key = uuid4()
    return t


@pytest.fixture
def job(tenant):
    j = Job(tenant_id=tenant.id, type="product_sync", status="running",
            progress=50, total=200)
    j.id = uuid4()
    j.error = None
    j.started_at = datetime.now(timezone.utc)
    j.finished_at = None
    j.created_at = datetime.now(timezone.utc)
    return j


@pytest.fixture
def client(tenant, job):
    settings = make_test_settings()
    app = create_app(settings)
    mock_db = AsyncMock()
    mock_db.__aenter__ = AsyncMock(return_value=mock_db)
    mock_db.__aexit__ = AsyncMock(return_value=False)

    app.dependency_overrides[get_db] = lambda: mock_db
    app.dependency_overrides[get_tenant] = lambda: tenant

    with (
        patch("eshopeo.api.routers.jobs.jobs_crud.get_job", AsyncMock(return_value=job)),
        patch("eshopeo.api.routers.jobs.jobs_crud.list_jobs", AsyncMock(return_value=[job])),
    ):
        yield TestClient(app), tenant, job, settings


def test_get_job_returns_details(client):
    c, tenant, job, _ = client
    r = c.get(f"/v1/jobs/{job.id}",
              headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)})
    assert r.status_code == 200
    data = r.json()
    assert data["id"] == str(job.id)
    assert data["status"] == "running"
    assert data["progress"] == 50
    assert data["total"] == 200


def test_get_job_404_when_not_found(client):
    c, tenant, _, _ = client
    with patch("eshopeo.api.routers.jobs.jobs_crud.get_job", AsyncMock(return_value=None)):
        r = c.get(f"/v1/jobs/{uuid4()}",
                  headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)})
    assert r.status_code == 404


def test_list_jobs_returns_list(client):
    c, tenant, job, _ = client
    r = c.get("/v1/jobs",
              headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)})
    assert r.status_code == 200
    data = r.json()
    assert isinstance(data, list)
    assert len(data) == 1
    assert data[0]["type"] == "product_sync"


def test_list_jobs_filter_by_type(client):
    c, tenant, job, _ = client
    r = c.get("/v1/jobs?type=product_sync",
              headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)})
    assert r.status_code == 200
    assert len(r.json()) == 1
