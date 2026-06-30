import pytest
from pathlib import Path
from eshopeo.packs.loader import PackLoader, PackValidationError

KBEAUTY_PATH = Path(__file__).parent.parent.parent.parent / "packs" / "kbeauty"


def test_loads_kbeauty_pack():
    pack = PackLoader.load(KBEAUTY_PATH)
    assert pack.id == "kbeauty"
    assert pack.version == "0.1.0"


def test_kbeauty_has_profile_schema():
    pack = PackLoader.load(KBEAUTY_PATH)
    assert "skin_type" in pack.profile_schema["properties"]


def test_kbeauty_has_product_schema():
    pack = PackLoader.load(KBEAUTY_PATH)
    assert "skin_types" in pack.product_schema["properties"]
    assert "concerns_targeted" in pack.product_schema["properties"]


def test_kbeauty_has_prompts():
    pack = PackLoader.load(KBEAUTY_PATH)
    assert "system" in pack.prompts


def test_kbeauty_has_compatibility_rules():
    pack = PackLoader.load(KBEAUTY_PATH)
    assert len(pack.compatibility_rules) >= 3


def test_invalid_schema_raises(tmp_path):
    (tmp_path / "pack.yaml").write_text("id: bad\nversion: 0.1\ndisplay_name: Bad\n")
    (tmp_path / "profile_schema.json").write_text('{"type": "not-a-valid-type"}')
    (tmp_path / "product_schema.json").write_text('{"type": "object"}')
    (tmp_path / "taxonomy.yaml").write_text("concerns: []\n")
    (tmp_path / "compatibility_rules.yaml").write_text("[]\n")
    (tmp_path / "prompts").mkdir()
    (tmp_path / "prompts" / "system.md").write_text("hello")
    (tmp_path / "copy").mkdir()
    with pytest.raises(PackValidationError):
        PackLoader.load(tmp_path)
