import pytest
from fastapi.testclient import TestClient
from eshopeo.api.app import create_app
from tests.conftest import make_test_settings
import eshopeo.config as config_module


def test_embed_js_returns_javascript():
    settings = make_test_settings()
    app = create_app(settings)
    c = TestClient(app)
    r = c.get("/v1/widget/embed.js?key=00000000-0000-0000-0000-000000000001")
    assert r.status_code == 200
    assert "javascript" in r.headers["content-type"]
    assert "fetch" in r.text


def test_embed_js_contains_api_calls():
    settings = make_test_settings()
    app = create_app(settings)
    c = TestClient(app)
    r = c.get("/v1/widget/embed.js?key=00000000-0000-0000-0000-000000000001")
    assert "/v1/widget/session" in r.text
    assert "/v1/widget/chat" in r.text


def test_demo_html_available_in_development():
    settings = make_test_settings(environment="development")
    app = create_app(settings)
    c = TestClient(app)
    r = c.get("/v1/widget/demo.html")
    assert r.status_code == 200
    assert "text/html" in r.headers["content-type"]
    assert "embed.js" in r.text


def test_demo_html_404_in_production(monkeypatch):
    from eshopeo.api.routers import widget as widget_module

    settings = make_test_settings(environment="production")
    # Monkeypatch get_settings in the widget module where it's used
    monkeypatch.setattr(widget_module, "get_settings", lambda: settings)
    app = create_app(settings)
    c = TestClient(app)
    r = c.get("/v1/widget/demo.html")
    assert r.status_code == 404
