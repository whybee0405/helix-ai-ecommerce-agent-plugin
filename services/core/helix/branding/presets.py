import json
from functools import lru_cache
from pathlib import Path

from helix.branding.schemas import Branding


PRESET_IDS: tuple[str, ...] = ("general", "skincare", "electronics", "fashion", "automotive")

_PRESETS_DIR = Path(__file__).parent / "presets"


@lru_cache(maxsize=16)
def get_preset(preset_id: str) -> Branding:
    """Load a preset by id and validate it as a complete Branding payload."""
    if preset_id not in PRESET_IDS:
        preset_id = "general"
    path = _PRESETS_DIR / f"{preset_id}.json"
    raw = json.loads(path.read_text(encoding="utf-8"))
    return Branding.model_validate(raw)


def list_presets() -> list[dict]:
    """Lightweight summary of presets for the WP admin dropdown."""
    out: list[dict] = []
    for pid in PRESET_IDS:
        b = get_preset(pid)
        out.append({
            "preset_id": pid,
            "brand_name": b.brand_name,
            "tagline": b.tagline,
            "primary_color": b.primary_color,
        })
    return out
