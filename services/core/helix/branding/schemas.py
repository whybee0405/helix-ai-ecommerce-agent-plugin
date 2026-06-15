from typing import Literal, Optional

from pydantic import BaseModel, Field, HttpUrl, field_validator


_HEX = r"^#[0-9A-Fa-f]{6}$"


class SuggestionChip(BaseModel):
    label: str = Field(min_length=1, max_length=40)
    query: str = Field(min_length=1, max_length=200)


class Branding(BaseModel):
    """Complete branding payload — every field has a default so partial JSONB rows
    still validate. Fields with `{{brand_name}}` are token-substituted at render time."""

    preset_id: Literal["general", "skincare", "electronics", "fashion", "automotive"] = "general"

    brand_name: str = Field(default="Helix AI", min_length=1, max_length=60)
    brand_short_name: str = Field(default="Helix", min_length=1, max_length=30)
    tagline: str = Field(default="Your AI shopping assistant", max_length=120)

    avatar_url: Optional[HttpUrl] = None

    primary_color: str = Field(default="#7C3AED", pattern=_HEX)
    secondary_color: str = Field(default="#4F46E5", pattern=_HEX)
    accent_color: str = Field(default="#C7B8F5", pattern=_HEX)

    headline_text: str = Field(default="Ask {{brand_name}} anything about our products", max_length=200)
    search_placeholder: str = Field(default="Ask AI about any product…", max_length=120)
    chat_placeholder: str = Field(default="Ask me anything…", max_length=120)
    footer_cta_label: str = Field(default="Open in chat", max_length=40)
    greeting: str = Field(
        default="Hi! I'm {{brand_name}}, your shopping assistant. What can I help you find today?",
        max_length=400,
    )

    suggestion_chips: list[SuggestionChip] = Field(default_factory=list, max_length=8)
    faq_seed_queries: list[str] = Field(default_factory=list, max_length=20)

    tone: str = Field(default="warm, knowledgeable, and concise", max_length=400)
    locale: str = Field(default="en-ZA", max_length=12)
    currency: str = Field(default="ZAR", min_length=3, max_length=3)

    custom_css: str = Field(default="", max_length=8000)

    @field_validator("avatar_url", mode="before")
    @classmethod
    def _normalize_avatar(cls, v):  # noqa: ANN001
        if v in ("", None):
            return None
        return v


class BrandingUpdate(BaseModel):
    """Partial update — all fields optional, validated against same constraints as Branding."""

    brand_name: Optional[str] = Field(default=None, min_length=1, max_length=60)
    brand_short_name: Optional[str] = Field(default=None, min_length=1, max_length=30)
    tagline: Optional[str] = Field(default=None, max_length=120)
    avatar_url: Optional[HttpUrl | Literal[""]] = None
    primary_color: Optional[str] = Field(default=None, pattern=_HEX)
    secondary_color: Optional[str] = Field(default=None, pattern=_HEX)
    accent_color: Optional[str] = Field(default=None, pattern=_HEX)
    headline_text: Optional[str] = Field(default=None, max_length=200)
    search_placeholder: Optional[str] = Field(default=None, max_length=120)
    chat_placeholder: Optional[str] = Field(default=None, max_length=120)
    footer_cta_label: Optional[str] = Field(default=None, max_length=40)
    greeting: Optional[str] = Field(default=None, max_length=400)
    suggestion_chips: Optional[list[SuggestionChip]] = Field(default=None, max_length=8)
    faq_seed_queries: Optional[list[str]] = Field(default=None, max_length=20)
    tone: Optional[str] = Field(default=None, max_length=400)
    locale: Optional[str] = Field(default=None, max_length=12)
    currency: Optional[str] = Field(default=None, min_length=3, max_length=3)
    custom_css: Optional[str] = Field(default=None, max_length=8000)
