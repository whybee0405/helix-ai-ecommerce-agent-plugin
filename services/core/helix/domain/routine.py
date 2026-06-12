from dataclasses import dataclass

from helix.domain.rules import (
    CompatibilityResult,
    check_compatibility,
    missing_steps,
    order_routine,
)
from helix.packs.loader import LoadedPack


@dataclass
class RoutineResult:
    steps: list[dict]
    conflicts: list[dict]
    cautions: list[dict]
    missing_steps: list[str]
    llm_augmented: bool = False


def build_routine(
    products: list[dict],
    pack: LoadedPack,
) -> RoutineResult:
    """Build a step-ordered routine from a list of product dicts with domain_attributes."""
    compat = check_compatibility(
        [p.get("domain_attributes", {}) for p in products],
        pack.compatibility_rules,
    )
    routine_steps = pack.taxonomy.get("routine_steps", [])
    ordered = order_routine(products, routine_steps)
    gaps = missing_steps(ordered, routine_steps)

    steps = [
        {
            "step": p.get("domain_attributes", {}).get("step", "unknown"),
            "product": {k: v for k, v in p.items() if k != "domain_attributes"},
        }
        for p in ordered
        if p.get("domain_attributes", {}).get("step")
    ]

    return RoutineResult(
        steps=steps,
        conflicts=compat.conflicts,
        cautions=compat.cautions,
        missing_steps=gaps,
    )
