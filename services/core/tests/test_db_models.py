from helix.db.models import Base, Customer, Job, Order, Product, Tenant, UsageEvent


def test_all_tables_defined():
    tables = Base.metadata.tables
    assert {"tenant", "product", "customer", "order", "job", "usage_event"}.issubset(set(tables.keys()))


def test_product_has_embedding_column():
    cols = {c.name for c in Product.__table__.columns}
    assert "embedding" in cols
    assert "tenant_id" in cols
    assert "domain_attributes" in cols


def test_tenant_has_public_key():
    cols = {c.name for c in Tenant.__table__.columns}
    assert "public_key" in cols
    assert "credentials_enc" in cols


def test_usage_event_has_cost():
    cols = {c.name for c in UsageEvent.__table__.columns}
    assert "cost_usd" in cols
    assert "tokens_in" in cols
    assert "tokens_out" in cols
