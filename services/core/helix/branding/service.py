from uuid import UUID

from sqlalchemy.ext.asyncio import AsyncSession

from helix.branding.css_sanitizer import sanitize_custom_css
from helix.branding.presets import get_preset
from helix.branding.schemas import Branding, BrandingUpdate
from helix.db.crud.tenants import update_tenant
from helix.db.models import Tenant


async def get_effective_branding(tenant: Tenant) -> Branding:
    """Merge stored partial branding over the preset defaults.

    Stored JSONB may be {} for legacy rows or partial after individual field edits;
    presets fill the gaps so downstream code always sees a complete Branding object.
    """
    stored = tenant.branding or {}
    preset_id = stored.get("preset_id") or "general"
    base = get_preset(preset_id).model_dump(mode="json")
    base.update({k: v for k, v in stored.items() if v is not None})
    return Branding.model_validate(base)


async def update_tenant_branding(
    db: AsyncSession,
    tenant: Tenant,
    patch: BrandingUpdate,
) -> Branding:
    """Apply a partial update to the tenant's branding JSONB and bump the version."""
    current = await get_effective_branding(tenant)
    merged = current.model_dump(mode="json")

    patch_data = patch.model_dump(exclude_unset=True, mode="json")
    if "custom_css" in patch_data and patch_data["custom_css"] is not None:
        patch_data["custom_css"] = sanitize_custom_css(patch_data["custom_css"])
    if patch_data.get("avatar_url") == "":
        patch_data["avatar_url"] = None

    merged.update(patch_data)
    new_branding = Branding.model_validate(merged)

    await update_tenant(
        db,
        tenant,
        branding=new_branding.model_dump(mode="json"),
        branding_version=tenant.branding_version + 1,
    )
    return new_branding


async def apply_preset_to_tenant(
    db: AsyncSession,
    tenant: Tenant,
    preset_id: str,
) -> Branding:
    """Replace the tenant's branding with a fresh preset payload (overwrites all fields).
    Operational settings (lead_webhook_url) are preserved across preset changes."""
    stored = tenant.branding or {}
    _PRESERVE = ("lead_webhook_url",)
    preserved = {k: stored[k] for k in _PRESERVE if stored.get(k)}
    preset = get_preset(preset_id)
    new_data = preset.model_dump(mode="json")
    new_data.update(preserved)
    new = Branding.model_validate(new_data)
    await update_tenant(
        db,
        tenant,
        branding=new.model_dump(mode="json"),
        branding_version=tenant.branding_version + 1,
    )
    return new
