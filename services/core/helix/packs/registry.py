from pathlib import Path

from helix.packs.loader import LoadedPack, PackLoader
from helix.db.models import Tenant as _Tenant

_registry: dict[str, LoadedPack] = {}


def load_all_packs(packs_dir: str) -> None:
    base = Path(packs_dir)
    if not base.exists():
        return
    for pack_path in base.iterdir():
        if pack_path.is_dir() and (pack_path / "pack.yaml").exists():
            pack = PackLoader.load(pack_path)
            _registry[pack.id] = pack


def get_pack(pack_id: str) -> LoadedPack:
    if pack_id not in _registry:
        raise KeyError(f"Pack '{pack_id}' not loaded. Available: {list(_registry)}")
    return _registry[pack_id]


def default_pack() -> LoadedPack:
    if not _registry:
        raise RuntimeError("No packs loaded")
    return next(iter(_registry.values()))


def get_pack_for_tenant(tenant: _Tenant) -> LoadedPack:
    pack_id = tenant.pack_id or "kbeauty"
    if pack_id in _registry:
        return _registry[pack_id]
    return default_pack()
