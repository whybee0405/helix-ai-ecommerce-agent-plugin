"""Brave Search integration for web-augmented AI responses."""
import httpx
import structlog

logger = structlog.get_logger(__name__)

_BRAVE_URL = "https://api.search.brave.com/res/v1/web/search"


async def brave_search(query: str, api_key: str, count: int = 3) -> list[dict]:
    """Call the Brave Web Search API and return top results.

    Returns a list of dicts with keys: title, url, snippet.
    On any error returns an empty list so callers can degrade gracefully.
    """
    try:
        async with httpx.AsyncClient(timeout=10.0) as client:
            resp = await client.get(
                _BRAVE_URL,
                params={"q": query, "count": count},
                headers={
                    "Accept": "application/json",
                    "X-Subscription-Token": api_key,
                },
            )
            resp.raise_for_status()
            data = resp.json()
    except httpx.HTTPError as exc:
        logger.warning("brave_search_http_error", error=str(exc), query=query)
        return []
    except Exception as exc:  # noqa: BLE001
        logger.warning("brave_search_error", error=str(exc), query=query)
        return []

    results = []
    for item in (data.get("web", {}).get("results") or [])[:count]:
        results.append(
            {
                "title": item.get("title", ""),
                "url": item.get("url", ""),
                "snippet": item.get("description", ""),
            }
        )
    return results
