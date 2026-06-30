from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel

from eshopeo.api.deps import get_tenant
from eshopeo.db.models import Tenant
from eshopeo.packs.registry import _registry

router = APIRouter(prefix="/v1/packs", tags=["packs"])


class PackSummary(BaseModel):
    id: str
    display_name: str
    version: str


class PackDetail(BaseModel):
    id: str
    display_name: str
    version: str
    compatibility_rules_count: int
    taxonomy_categories: list[str]
    copy_locales: list[str]


@router.get("", response_model=list[PackSummary])
async def list_packs(_tenant: Tenant = Depends(get_tenant)) -> list[PackSummary]:
    return [
        PackSummary(id=p.id, display_name=p.display_name, version=p.version)
        for p in _registry.values()
    ]


@router.get("/{pack_id}", response_model=PackDetail)
async def get_pack_detail(
    pack_id: str, _tenant: Tenant = Depends(get_tenant)
) -> PackDetail:
    if pack_id not in _registry:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND, detail="Pack not found"
        )
    p = _registry[pack_id]
    return PackDetail(
        id=p.id,
        display_name=p.display_name,
        version=p.version,
        compatibility_rules_count=(
            len(p.compatibility_rules)
            if isinstance(p.compatibility_rules, list)
            else 0
        ),
        taxonomy_categories=(
            list(p.taxonomy.keys()) if isinstance(p.taxonomy, dict) else []
        ),
        copy_locales=list(p.copy.keys()),
    )
