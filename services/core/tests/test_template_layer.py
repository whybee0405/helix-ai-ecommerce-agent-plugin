import pytest
from helix.llm.layers import TemplateLayer, LayerResult

TEMPLATES = {
    "return policy": "We accept returns within 30 days.",
    "shipping": "Free shipping on orders over R500.",
    "skin type quiz": "Take our quiz at /quiz.",
}


async def test_template_matches_keyword():
    layer = TemplateLayer()
    result = await layer.query("What is your return policy?", TEMPLATES)
    assert result.answered is True
    assert "30 days" in result.response
    assert result.confidence == 1.0


async def test_template_case_insensitive():
    layer = TemplateLayer()
    result = await layer.query("SHIPPING costs?", TEMPLATES)
    assert result.answered is True


async def test_template_no_match_returns_unanswered():
    layer = TemplateLayer()
    result = await layer.query("What moisturizer is good for oily skin?", TEMPLATES)
    assert result.answered is False


async def test_template_empty_templates_returns_unanswered():
    layer = TemplateLayer()
    result = await layer.query("anything", {})
    assert result.answered is False


async def test_template_first_match_wins():
    layer = TemplateLayer()
    # iteration order is insertion order in Python 3.7+
    multi = {"skin type": "Answer A", "skin type quiz": "Answer B"}
    result = await layer.query("take the skin type quiz", multi)
    assert result.answered is True
    assert result.response == "Answer A"  # "skin type" matches first
