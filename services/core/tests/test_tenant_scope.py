import pytest
from unittest.mock import AsyncMock, MagicMock
from uuid import uuid4

from eshopeo.db.tenant_scope import TenantScope


@pytest.fixture
def mock_session():
    session = AsyncMock()
    result = MagicMock()
    result.scalars.return_value.all.return_value = []
    session.execute.return_value = result
    return session


@pytest.mark.asyncio
async def test_tenant_scope_requires_tenant_id():
    with pytest.raises(TypeError):
        TenantScope(session=AsyncMock())


@pytest.mark.asyncio
async def test_get_products_scopes_by_tenant(mock_session):
    tid = uuid4()
    scope = TenantScope(session=mock_session, tenant_id=tid)
    products = await scope.get_products()
    assert products == []
    mock_session.execute.assert_called_once()
    call_args = mock_session.execute.call_args[0][0]
    compiled = str(call_args.compile(compile_kwargs={"literal_binds": True}))
    # SQLAlchemy may render UUID with or without dashes depending on dialect
    assert str(tid) in compiled or str(tid).replace("-", "") in compiled
