import pytest
from eshopeo.domain.rules import (
    check_compatibility,
    order_routine,
    missing_steps,
)

RULES = [
    {
        "id": "retinol_aha",
        "description": "Do not layer retinol with AHA/BHA",
        "type": "conflict",
        "ingredients": ["retinol", "glycolic acid"],
    },
    {
        "id": "vitamin_c_niacinamide",
        "description": "Vitamin C and niacinamide caution",
        "type": "caution",
        "ingredients": ["ascorbic acid", "niacinamide"],
    },
]


def test_no_conflict_when_ingredients_dont_overlap():
    result = check_compatibility(
        [{"key_ingredients": ["hyaluronic acid"]}, {"key_ingredients": ["ceramides"]}],
        RULES,
    )
    assert not result.has_issues


def test_conflict_detected_when_ingredients_overlap():
    result = check_compatibility(
        [{"key_ingredients": ["retinol"]}, {"key_ingredients": ["glycolic acid"]}],
        RULES,
    )
    assert len(result.conflicts) == 1
    assert result.conflicts[0]["rule_id"] == "retinol_aha"


def test_caution_detected():
    result = check_compatibility(
        [{"key_ingredients": ["ascorbic acid", "niacinamide"]}],
        RULES,
    )
    assert len(result.cautions) == 1
    assert result.cautions[0]["rule_id"] == "vitamin_c_niacinamide"


def test_order_routine_by_step():
    products = [
        {"title": "Moisturizer", "domain_attributes": {"step": "moisturize"}},
        {"title": "Cleanser", "domain_attributes": {"step": "cleanse"}},
        {"title": "Serum", "domain_attributes": {"step": "treat"}},
    ]
    ordered = order_routine(products)
    assert ordered[0]["title"] == "Cleanser"
    assert ordered[1]["title"] == "Serum"
    assert ordered[2]["title"] == "Moisturizer"


def test_missing_steps_identifies_gaps():
    products = [
        {"domain_attributes": {"step": "cleanse"}},
        {"domain_attributes": {"step": "moisturize"}},
    ]
    gaps = missing_steps(products)
    assert "tone" in gaps
    assert "treat" in gaps
    assert "cleanse" not in gaps
    assert "moisturize" not in gaps
