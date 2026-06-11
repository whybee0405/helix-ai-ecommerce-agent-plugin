from dataclasses import dataclass, field
from pathlib import Path
import json

import jsonschema
import yaml


class PackValidationError(Exception):
    pass


@dataclass
class LoadedPack:
    id: str
    version: str
    display_name: str
    profile_schema: dict
    product_schema: dict
    taxonomy: dict
    compatibility_rules: list[dict]
    prompts: dict[str, str]
    copy: dict[str, dict]


class PackLoader:
    @staticmethod
    def load(path: Path) -> LoadedPack:
        try:
            meta = yaml.safe_load((path / "pack.yaml").read_text())
            profile_schema = json.loads((path / "profile_schema.json").read_text())
            product_schema = json.loads((path / "product_schema.json").read_text())
            taxonomy = yaml.safe_load((path / "taxonomy.yaml").read_text())
            compat_rules = yaml.safe_load((path / "compatibility_rules.yaml").read_text()) or []
        except (FileNotFoundError, json.JSONDecodeError, yaml.YAMLError) as exc:
            raise PackValidationError(f"Failed to read pack at {path}: {exc}") from exc

        try:
            jsonschema.Draft7Validator.check_schema(profile_schema)
            jsonschema.Draft7Validator.check_schema(product_schema)
        except jsonschema.SchemaError as exc:
            raise PackValidationError(f"Invalid JSON Schema in pack {path}: {exc.message}") from exc

        prompts: dict[str, str] = {}
        prompts_dir = path / "prompts"
        if prompts_dir.exists():
            for f in prompts_dir.glob("*.md"):
                prompts[f.stem] = f.read_text()

        copy: dict[str, dict] = {}
        copy_dir = path / "copy"
        if copy_dir.exists():
            for f in copy_dir.glob("*.json"):
                copy[f.stem] = json.loads(f.read_text())

        return LoadedPack(
            id=meta["id"],
            version=str(meta["version"]),
            display_name=meta["display_name"],
            profile_schema=profile_schema,
            product_schema=product_schema,
            taxonomy=taxonomy,
            compatibility_rules=compat_rules if isinstance(compat_rules, list) else [],
            prompts=prompts,
            copy=copy,
        )
