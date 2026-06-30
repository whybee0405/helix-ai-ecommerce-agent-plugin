from dataclasses import dataclass


@dataclass
class CompatibilityResult:
    conflicts: list[dict]
    cautions: list[dict]

    @property
    def has_issues(self) -> bool:
        return bool(self.conflicts or self.cautions)


def check_compatibility(
    products_attrs: list[dict],
    rules: list[dict],
) -> CompatibilityResult:
    """Check ingredient compatibility across a list of product domain_attributes dicts."""
    all_ingredients: set[str] = set()
    for attrs in products_attrs:
        for ing in attrs.get("key_ingredients", []):
            all_ingredients.add(ing.lower())

    conflicts = []
    cautions = []
    for rule in rules:
        rule_type = rule.get("type")
        rule_ingredients = {i.lower() for i in rule.get("ingredients", [])}
        if len(rule_ingredients & all_ingredients) >= 2:
            entry = {"rule_id": rule["id"], "description": rule["description"]}
            if rule_type == "conflict":
                conflicts.append(entry)
            elif rule_type == "caution":
                cautions.append(entry)

    return CompatibilityResult(conflicts=conflicts, cautions=cautions)


ROUTINE_STEP_ORDER = ["cleanse", "tone", "treat", "moisturize", "protect", "mask"]


def order_routine(
    products: list[dict],
    routine_steps: list[str] | None = None,
) -> list[dict]:
    """Sort products by their routine step. Products without a step go to end."""
    order = routine_steps or ROUTINE_STEP_ORDER
    step_rank = {step: i for i, step in enumerate(order)}

    def _rank(p: dict) -> int:
        return step_rank.get(p.get("domain_attributes", {}).get("step", ""), len(order))

    return sorted(products, key=_rank)


def missing_steps(
    products: list[dict],
    routine_steps: list[str] | None = None,
) -> list[str]:
    """Return routine steps not covered by the product list (excluding 'mask')."""
    order = routine_steps or ROUTINE_STEP_ORDER
    covered = {
        p.get("domain_attributes", {}).get("step")
        for p in products
        if p.get("domain_attributes", {}).get("step")
    }
    return [s for s in order if s not in covered and s != "mask"]
