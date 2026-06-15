import re


_FORBIDDEN_PATTERNS = [
    re.compile(r"@import", re.IGNORECASE),
    re.compile(r"behavior\s*:", re.IGNORECASE),
    re.compile(r"expression\s*\(", re.IGNORECASE),
    re.compile(r"javascript\s*:", re.IGNORECASE),
    re.compile(r"vbscript\s*:", re.IGNORECASE),
    re.compile(r"data\s*:\s*text/html", re.IGNORECASE),
    re.compile(r"position\s*:\s*fixed", re.IGNORECASE),
    re.compile(r"<\s*script", re.IGNORECASE),
    re.compile(r"<\s*/\s*style", re.IGNORECASE),
]

_ALLOWED_SELECTOR_PREFIXES = (
    "#hx-",
    ".hx-",
    "@media",
    "@keyframes",
    "@supports",
    "@font-face",
    ":root",
)


def sanitize_custom_css(css: str) -> str:
    """Strip forbidden constructs and refuse rules that don't target Helix widget selectors.

    Returns sanitized CSS. Silently drops disallowed rules rather than raising —
    the admin UI displays a warning if the saved value differs from the submitted value.
    """
    if not css or not css.strip():
        return ""

    for pat in _FORBIDDEN_PATTERNS:
        if pat.search(css):
            css = pat.sub("/* removed */", css)

    out: list[str] = []
    depth = 0
    buf = ""
    rule_start_idx = 0
    keep_current_rule = True

    i = 0
    n = len(css)
    rule_buf = ""

    while i < n:
        ch = css[i]
        if ch == "{":
            if depth == 0:
                selector = rule_buf.strip()
                keep_current_rule = _selector_allowed(selector)
                if keep_current_rule:
                    out.append(rule_buf)
                rule_buf = ""
            else:
                if keep_current_rule:
                    out.append(ch)
            depth += 1
            if keep_current_rule:
                if depth == 1:
                    out.append("{")
        elif ch == "}":
            depth -= 1
            if keep_current_rule:
                out.append("}")
            if depth == 0:
                rule_buf = ""
                keep_current_rule = True
        else:
            if depth == 0:
                rule_buf += ch
            elif keep_current_rule:
                out.append(ch)
        i += 1

    return "".join(out).strip()


def _selector_allowed(selector: str) -> bool:
    if not selector:
        return False
    selector_l = selector.lower().lstrip()
    for prefix in _ALLOWED_SELECTOR_PREFIXES:
        if selector_l.startswith(prefix.lower()):
            return True
    parts = [p.strip() for p in selector_l.split(",") if p.strip()]
    if parts and all(
        any(p.startswith(prefix.lower()) for prefix in _ALLOWED_SELECTOR_PREFIXES)
        for p in parts
    ):
        return True
    return False
