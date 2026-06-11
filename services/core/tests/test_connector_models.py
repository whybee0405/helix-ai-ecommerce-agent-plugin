import pytest
from uuid import uuid4
from helix.connectors.models import CanonicalProduct, CanonicalCustomer, CanonicalOrder
from datetime import datetime, timezone


def make_product(**overrides) -> dict:
    base = dict(
        tenant_id=str(uuid4()),
        platform="woocommerce",
        platform_id="42",
        title="Snail Mucin Essence",
        description_html="<p>Great for skin</p>",
        price_minor=34900,
        currency="ZAR",
        images=["https://example.com/img.jpg"],
        categories=["Essence"],
        in_stock=True,
        domain_attributes={"skin_types": ["dry"], "concerns_targeted": ["hydration"]},
    )
    base.update(overrides)
    return base


def test_canonical_product_valid():
    p = CanonicalProduct(**make_product())
    assert p.price_minor == 34900
    assert p.deleted is False


def test_canonical_product_delete_flag():
    p = CanonicalProduct(**make_product(deleted=True))
    assert p.deleted is True


def test_canonical_product_invalid_platform():
    with pytest.raises(Exception):
        CanonicalProduct(**make_product(platform="magento"))


def test_canonical_product_missing_title():
    data = make_product()
    del data["title"]
    with pytest.raises(Exception):
        CanonicalProduct(**data)
