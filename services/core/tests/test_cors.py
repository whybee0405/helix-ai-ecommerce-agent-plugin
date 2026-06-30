from fastapi.testclient import TestClient
from eshopeo.api.app import create_app
from tests.conftest import make_test_settings


def test_cors_preflight_allowed():
    settings = make_test_settings(cors_allowed_origins=["https://mystore.com"])
    app = create_app(settings)
    c = TestClient(app)
    r = c.options(
        "/v1/health",
        headers={
            "Origin": "https://mystore.com",
            "Access-Control-Request-Method": "GET",
        },
    )
    assert r.status_code in (200, 204)
    assert "access-control-allow-origin" in r.headers


def test_cors_wildcard_default():
    settings = make_test_settings()
    app = create_app(settings)
    c = TestClient(app)
    r = c.get("/v1/health", headers={"Origin": "https://somestore.com"})
    assert "access-control-allow-origin" in r.headers


def test_cors_exposes_request_id():
    settings = make_test_settings()
    app = create_app(settings)
    c = TestClient(app)
    r = c.get("/v1/health", headers={"Origin": "https://somestore.com"})
    exposed = r.headers.get("access-control-expose-headers", "")
    assert "X-Request-Id" in exposed
