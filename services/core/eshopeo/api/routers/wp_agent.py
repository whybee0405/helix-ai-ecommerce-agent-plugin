"""
eShopeo WP Agent router — Claude tool_use AI assistant for WordPress site management.

Provides:
  GET  /v1/wp-agent/actions  — list all available quick actions
  POST /v1/wp-agent/chat     — agentic Claude conversation with WordPress tools
"""

from __future__ import annotations

import json
from typing import Any

import anthropic
import httpx
import structlog
from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel

from eshopeo.api.deps import get_tenant, check_ai_op_quota, get_tenant_anthropic_key
from eshopeo.config import get_settings
from eshopeo.db.models import Tenant

logger = structlog.get_logger(__name__)

router = APIRouter(prefix="/v1/wp-agent", tags=["wp-agent"])

# ─── Claude model ─────────────────────────────────────────────────────────────
_AGENT_MODEL = "claude-sonnet-4-6"
_MAX_TOKENS = 4096
_MAX_ITERATIONS = 10

_SYSTEM_PROMPT = """You are the eShopeo WP Agent — an expert WordPress site manager embedded
in the eShopeo admin panel.  You have access to a set of tools that let you
inspect and optimise the connected WordPress site.

Guidelines:
- Always run a dry_run first before executing any destructive action; explain
  what will happen and ask the user to confirm.
- Present data in a clear, structured format.
- When listing many items, show the top 10 and summarise the rest.
- If a tool call returns an error, explain it in plain language.
- Be concise but thorough.
"""

# ─── Tool definitions ──────────────────────────────────────────────────────────
WP_TOOLS: list[dict[str, Any]] = [
    {
        "name": "wp_api_call",
        "description": (
            "Call any WordPress REST API endpoint on the connected site. "
            "Use the WP REST API path, e.g. /wp/v2/posts. "
            "Authentication is handled server-side."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "method": {
                    "type": "string",
                    "enum": ["GET", "POST", "PUT", "PATCH", "DELETE"],
                    "description": "HTTP method",
                },
                "endpoint": {
                    "type": "string",
                    "description": "WP REST API path, e.g. /wp/v2/posts",
                },
                "params": {
                    "type": "object",
                    "description": "Query string parameters (for GET requests)",
                },
                "body": {
                    "type": "object",
                    "description": "Request body (for POST/PUT/PATCH)",
                },
            },
            "required": ["method", "endpoint"],
        },
    },
    {
        "name": "run_quick_action",
        "description": (
            "Run a eShopeo quick action on the WordPress site. "
            "Always dry_run=true first to preview what will change, then ask "
            "the user for confirmation before executing with dry_run=false."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "action_id": {
                    "type": "string",
                    "description": (
                        "The action identifier. One of: clear_transients, optimize_tables, "
                        "delete_revisions, delete_orphaned_meta, delete_spam_trash, "
                        "fix_autoload, set_heartbeat, flush_rewrite_rules, "
                        "flush_object_cache, disable_heartbeat_frontend, "
                        "find_orphaned_media, find_missing_alt, clean_image_sizes, "
                        "audit_admin_users, check_username_admin, list_inactive_plugins, "
                        "check_file_permissions, find_no_meta, find_thin_content, find_draft_old"
                    ),
                },
                "dry_run": {
                    "type": "boolean",
                    "description": "If true, only preview; if false, execute the action.",
                    "default": True,
                },
            },
            "required": ["action_id"],
        },
    },
    {
        "name": "get_performance_metrics",
        "description": "Retrieve live WordPress performance metrics including autoload size, table overhead, revision count, PHP version, memory usage, and an overall grade (A–F).",
        "input_schema": {
            "type": "object",
            "properties": {},
        },
    },
    {
        "name": "get_action_log",
        "description": "Retrieve the recent eShopeo action history (quick actions that have been run, including dry-runs).",
        "input_schema": {
            "type": "object",
            "properties": {
                "limit": {
                    "type": "integer",
                    "description": "Number of log entries to return (default 20, max 100).",
                    "default": 20,
                },
            },
        },
    },
    {
        "name": "bulk_update_posts",
        "description": "Update multiple posts or pages in bulk (title, content, status, or meta fields).",
        "input_schema": {
            "type": "object",
            "properties": {
                "post_ids": {
                    "type": "array",
                    "items": {"type": "integer"},
                    "description": "List of post IDs to update.",
                },
                "updates": {
                    "type": "object",
                    "description": (
                        "Fields to update. Supported keys: title (string), "
                        "content (string), status (string), meta_input (object)."
                    ),
                },
            },
            "required": ["post_ids", "updates"],
        },
    },
]

# ─── Pydantic models ───────────────────────────────────────────────────────────


class ChatMessage(BaseModel):
    role: str
    content: str


class ChatRequest(BaseModel):
    message: str
    history: list[ChatMessage] = []
    site_url: str
    admin_secret: str
    wp_username: str = ""
    wp_app_password: str = ""


class ToolCallRecord(BaseModel):
    name: str
    input: dict[str, Any]
    result: Any


class ChatResponse(BaseModel):
    response: str
    tool_calls: list[ToolCallRecord] = []


class ActionsResponse(BaseModel):
    actions: list[dict[str, Any]]


# ─── Endpoints ─────────────────────────────────────────────────────────────────


@router.get("/actions", response_model=ActionsResponse)
async def list_actions() -> ActionsResponse:
    """Return the list of available quick actions for dynamic discovery."""
    actions = [
        {
            "id": t["name"],
            "label": t["name"].replace("_", " ").title(),
            "description": t["description"],
            "input_schema": t["input_schema"],
        }
        for t in WP_TOOLS
    ]
    return ActionsResponse(actions=actions)


@router.post("/chat", response_model=ChatResponse)
async def wp_agent_chat(
    body: ChatRequest,
    tenant: Tenant = Depends(get_tenant),
    _: Tenant = Depends(check_ai_op_quota),
) -> ChatResponse:
    """
    Run an agentic Claude conversation with WordPress management tools.

    Executes an agentic loop: send to Claude → if tool_use blocks are returned,
    execute the tools against the WordPress site → feed results back → repeat
    until a final text response is produced.
    """
    settings = get_settings()
    client = anthropic.AsyncAnthropic(
        api_key=get_tenant_anthropic_key(tenant) or settings.anthropic_api_key.get_secret_value()
    )

    # Build conversation history for Claude.
    messages: list[dict[str, Any]] = []
    for msg in body.history:
        messages.append({"role": msg.role, "content": msg.content})
    messages.append({"role": "user", "content": body.message})

    tool_call_records: list[ToolCallRecord] = []

    log = logger.bind(site_url=body.site_url)

    for iteration in range(_MAX_ITERATIONS):
        log.info("wp_agent.iteration", iteration=iteration, message_count=len(messages))

        response = await client.messages.create(
            model=_AGENT_MODEL,
            max_tokens=_MAX_TOKENS,
            system=_SYSTEM_PROMPT,
            tools=WP_TOOLS,  # type: ignore[arg-type]
            messages=messages,
        )

        # Collect text content and tool use blocks.
        text_parts: list[str] = []
        tool_use_blocks: list[Any] = []

        for block in response.content:
            if block.type == "text":
                text_parts.append(block.text)
            elif block.type == "tool_use":
                tool_use_blocks.append(block)

        if not tool_use_blocks:
            # Final text response — no more tool calls.
            final_text = "\n".join(text_parts).strip()
            log.info("wp_agent.final_response", tool_call_count=len(tool_call_records))
            return ChatResponse(
                response=final_text,
                tool_calls=tool_call_records,
            )

        # Append assistant message with all content blocks.
        messages.append({"role": "assistant", "content": response.content})

        # Execute each tool call and collect results.
        tool_results: list[dict[str, Any]] = []

        for block in tool_use_blocks:
            tool_name = block.name
            tool_input = block.input if hasattr(block, "input") else {}

            log.info("wp_agent.tool_call", tool=tool_name, input=tool_input)

            try:
                result = await _execute_tool(
                    tool_name,
                    tool_input,
                    body.site_url,
                    body.admin_secret,
                    body.wp_username,
                    body.wp_app_password,
                )
            except Exception as exc:
                log.warning("wp_agent.tool_error", tool=tool_name, error=str(exc))
                result = {"error": str(exc)}

            tool_call_records.append(
                ToolCallRecord(name=tool_name, input=tool_input, result=result)
            )

            tool_results.append(
                {
                    "type": "tool_result",
                    "tool_use_id": block.id,
                    "content": json.dumps(result),
                }
            )

        # Append tool results as a user turn.
        messages.append({"role": "user", "content": tool_results})

    # Safety exit — return whatever text we have after max iterations.
    log.warning("wp_agent.max_iterations_reached")
    return ChatResponse(
        response="I've reached the maximum number of steps for this request. Please try a more specific question.",
        tool_calls=tool_call_records,
    )


# ─── Tool execution ─────────────────────────────────────────────────────────────


async def _execute_tool(
    name: str,
    tool_input: dict[str, Any],
    site_url: str,
    admin_secret: str,
    wp_username: str = "",
    wp_app_password: str = "",
) -> Any:
    """Dispatch a tool call to the appropriate handler."""
    if name == "wp_api_call":
        return await _tool_wp_api_call(tool_input, site_url, wp_username, wp_app_password)
    if name == "run_quick_action":
        return await _tool_run_quick_action(tool_input, site_url, admin_secret)
    if name == "get_performance_metrics":
        return await _tool_get_performance_metrics(site_url, admin_secret)
    if name == "get_action_log":
        return await _tool_get_action_log(tool_input, site_url, admin_secret)
    if name == "bulk_update_posts":
        return await _tool_bulk_update_posts(tool_input, site_url, wp_username, wp_app_password)
    raise ValueError(f"Unknown tool: {name}")


async def _tool_wp_api_call(
    inp: dict[str, Any],
    site_url: str,
    wp_username: str = "",
    wp_app_password: str = "",
) -> Any:
    """Proxy a WordPress REST API call using Application Password auth."""
    method: str = inp["method"].upper()
    endpoint: str = inp.get("endpoint", "")
    params: dict | None = inp.get("params")
    body: dict | None = inp.get("body")

    if not endpoint.startswith("/"):
        endpoint = "/" + endpoint

    rest_root = site_url.rstrip("/") + "/wp-json"
    url = rest_root + endpoint

    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
    }
    # Use WordPress Application Password (Basic auth) for write operations.
    auth = (wp_username, wp_app_password) if wp_username and wp_app_password else None

    async with httpx.AsyncClient(timeout=30) as http:
        req_kwargs: dict[str, Any] = {"headers": headers}
        if auth:
            req_kwargs["auth"] = auth
        if params:
            req_kwargs["params"] = params
        if body and method in ("POST", "PUT", "PATCH"):
            req_kwargs["json"] = body

        resp = await http.request(method, url, **req_kwargs)

    try:
        return resp.json()
    except Exception:
        return {"status_code": resp.status_code, "text": resp.text[:500]}


async def _tool_run_quick_action(
    inp: dict[str, Any],
    site_url: str,
    admin_secret: str,
) -> Any:
    """Call the eShopeo quick action AJAX endpoint on the WordPress site."""
    action_id: str = inp.get("action_id", "")
    dry_run: bool = inp.get("dry_run", True)

    url = site_url.rstrip("/") + "/wp-admin/admin-ajax.php"
    # We use the eShopeo internal endpoint; the admin_secret is passed for auth.
    # In production the WordPress site validates the nonce; here we use
    # the admin_secret as a server-to-server credential.
    data = {
        "action": f"eshopeo_action_{action_id}",
        "dry_run": "1" if dry_run else "0",
        "eshopeo_agent_secret": admin_secret,
    }

    async with httpx.AsyncClient(timeout=30) as http:
        resp = await http.post(url, data=data)

    try:
        return resp.json()
    except Exception:
        return {"status_code": resp.status_code, "text": resp.text[:500]}


async def _tool_get_performance_metrics(site_url: str, admin_secret: str) -> Any:
    """Fetch live performance metrics from the WordPress site."""
    url = site_url.rstrip("/") + "/wp-admin/admin-ajax.php"
    data = {
        "action": "eshopeo_performance_metrics",
        "eshopeo_agent_secret": admin_secret,
    }

    async with httpx.AsyncClient(timeout=30) as http:
        resp = await http.post(url, data=data)

    try:
        return resp.json()
    except Exception:
        return {"status_code": resp.status_code, "text": resp.text[:500]}


async def _tool_get_action_log(
    inp: dict[str, Any],
    site_url: str,
    admin_secret: str,
) -> Any:
    """Fetch the eShopeo action log from the WordPress site."""
    limit: int = min(int(inp.get("limit", 20)), 100)
    url = site_url.rstrip("/") + "/wp-admin/admin-ajax.php"
    data = {
        "action": "eshopeo_get_action_log",
        "limit": str(limit),
        "eshopeo_agent_secret": admin_secret,
    }

    async with httpx.AsyncClient(timeout=30) as http:
        resp = await http.post(url, data=data)

    try:
        return resp.json()
    except Exception:
        return {"status_code": resp.status_code, "text": resp.text[:500]}


async def _tool_bulk_update_posts(
    inp: dict[str, Any],
    site_url: str,
    wp_username: str = "",
    wp_app_password: str = "",
) -> Any:
    """Bulk-update multiple posts via the WordPress REST API."""
    post_ids: list[int] = inp.get("post_ids", [])
    updates: dict[str, Any] = inp.get("updates", {})

    if not post_ids:
        return {"error": "No post_ids provided"}
    if not updates:
        return {"error": "No updates provided"}

    auth = (wp_username, wp_app_password) if wp_username and wp_app_password else None
    results: list[dict[str, Any]] = []

    async with httpx.AsyncClient(timeout=30) as http:
        for post_id in post_ids:
            url = site_url.rstrip("/") + f"/wp-json/wp/v2/posts/{post_id}"
            headers = {"Content-Type": "application/json"}
            req_kwargs: dict[str, Any] = {"headers": headers, "json": updates}
            if auth:
                req_kwargs["auth"] = auth
            try:
                resp = await http.post(url, **req_kwargs)
                results.append({
                    "post_id": post_id,
                    "status": resp.status_code,
                    "ok": resp.status_code < 300,
                })
            except Exception as exc:
                results.append({"post_id": post_id, "error": str(exc)})

    success_count = sum(1 for r in results if r.get("ok"))
    return {
        "updated": success_count,
        "total": len(post_ids),
        "results": results,
    }
