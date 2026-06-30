from fastapi.testclient import TestClient
from eshopeo.api.app import create_app
from tests.conftest import make_test_settings


def test_request_id_generated_when_absent():
    settings = make_test_settings()
    app = create_app(settings)
    c = TestClient(app)
    r = c.get("/v1/health")
    assert "x-request-id" in r.headers
    assert len(r.headers["x-request-id"]) == 36  # UUID format


def test_request_id_echoed_when_provided():
    settings = make_test_settings()
    app = create_app(settings)
    c = TestClient(app)
    r = c.get("/v1/health", headers={"X-Request-Id": "my-trace-id-abc"})
    assert r.headers["x-request-id"] == "my-trace-id-abc"


def test_request_id_unique_per_request():
    settings = make_test_settings()
    app = create_app(settings)
    c = TestClient(app)
    r1 = c.get("/v1/health")
    r2 = c.get("/v1/health")
    assert r1.headers["x-request-id"] != r2.headers["x-request-id"]
