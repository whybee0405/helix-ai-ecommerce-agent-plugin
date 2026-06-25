"""
Transactional email via Resend.

All send_* functions are Celery tasks so they run async and never block
the request path. Templates are plain HTML strings — no templating engine
dependency needed at this scale.
"""
from __future__ import annotations

import httpx
import structlog

from helix.workers.celery_app import celery_app

logger = structlog.get_logger(__name__)

_RESEND_URL = "https://api.resend.com/emails"
_FROM = "Helix AI <hello@helix.cloudia.co.za>"


def _send(api_key: str, to: str, subject: str, html: str) -> None:
    if not api_key or not to:
        return
    try:
        resp = httpx.post(
            _RESEND_URL,
            headers={"Authorization": f"Bearer {api_key}", "Content-Type": "application/json"},
            json={"from": _FROM, "to": [to], "subject": subject, "html": html},
            timeout=15,
        )
        resp.raise_for_status()
    except Exception as exc:
        logger.error("email_send_failed", to=to, subject=subject, error=str(exc))


def _api_key() -> str:
    from helix.config import get_settings
    s = get_settings()
    key = getattr(s, "resend_api_key", None)
    return key.get_secret_value() if key else ""


# ── Email templates ────────────────────────────────────────────────────────────

def _base(content: str) -> str:
    return f"""
    <div style="font-family:Inter,sans-serif;max-width:560px;margin:0 auto;color:#111;">
      <div style="background:#18181b;padding:24px 32px;border-radius:12px 12px 0 0;">
        <span style="color:#a78bfa;font-weight:700;font-size:18px;">⬡ Helix</span>
      </div>
      <div style="background:#fafafa;padding:32px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 12px 12px;">
        {content}
      </div>
      <p style="font-size:11px;color:#9ca3af;text-align:center;margin-top:16px;">
        Helix AI · helix.cloudia.co.za · You're receiving this because you signed up for Helix.
      </p>
    </div>"""


# ── Celery tasks ───────────────────────────────────────────────────────────────

@celery_app.task(name="helix.notifications.email.send_welcome_email")
def send_welcome_email(
    email: str,
    store_name: str,
    trial_ends_at: str,
    admin_secret: str,
    public_key: str,
) -> None:
    html = _base(f"""
      <h2 style="margin:0 0 8px;">Welcome to Helix, {store_name}! 🎉</h2>
      <p style="color:#6b7280;">Your 14-day free trial is now active. Here's everything you need to get started.</p>
      <div style="background:#f3f4f6;border-radius:8px;padding:16px;margin:20px 0;font-family:monospace;font-size:13px;">
        <strong>Your credentials</strong><br>
        Public Key: <code>{public_key}</code><br>
        Admin Secret: <code style="color:#7c3aed;">{admin_secret}</code>
      </div>
      <p style="color:#dc2626;font-size:13px;">⚠️ Save your Admin Secret now — it won't be shown again.</p>
      <a href="https://helix.cloudia.co.za/v1/plugin/download"
         style="display:inline-block;background:#7c3aed;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;margin:12px 0;">
        Download Plugin
      </a>
      <p style="color:#6b7280;font-size:13px;margin-top:16px;">
        Trial ends: <strong>{trial_ends_at[:10]}</strong> — upgrade any time to keep access.
      </p>
    """)
    _send(_api_key(), email, "Welcome to Helix — your trial is active", html)


@celery_app.task(name="helix.notifications.email.send_magic_link_email")
def send_magic_link_email(email: str, store_name: str, magic_link: str) -> None:
    html = _base(f"""
      <h2 style="margin:0 0 8px;">Your Helix login link</h2>
      <p>Hi {store_name}, click the button below to sign in to your Helix dashboard.
         This link expires in <strong>10 minutes</strong> and can only be used once.</p>
      <a href="{magic_link}"
         style="display:inline-block;background:#7c3aed;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;margin:12px 0;">
        Sign in to Helix
      </a>
      <p style="color:#6b7280;font-size:13px;">If you didn't request this, you can safely ignore it.</p>
    """)
    _send(_api_key(), email, "Your Helix login link", html)


@celery_app.task(name="helix.notifications.email.send_trial_reminder")
def send_trial_reminder(email: str, store_name: str, days_remaining: int) -> None:
    urgency = "🔔" if days_remaining > 3 else "⚠️"
    html = _base(f"""
      <h2 style="margin:0 0 8px;">{urgency} {days_remaining} day{'s' if days_remaining != 1 else ''} left in your Helix trial</h2>
      <p>Hi {store_name}, your free trial ends in <strong>{days_remaining} day{'s' if days_remaining != 1 else ''}</strong>.
         Subscribe now to keep your AI chat, WP Agent, and all content tools running without interruption.</p>
      <a href="https://helix.cloudia.co.za/billing"
         style="display:inline-block;background:#7c3aed;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;margin:12px 0;">
        Choose a Plan
      </a>
      <p style="color:#6b7280;font-size:13px;">Plans start at R699/mo (or $39/mo). Cancel any time.</p>
    """)
    _send(_api_key(), email, f"Your Helix trial ends in {days_remaining} day{'s' if days_remaining != 1 else ''}", html)


@celery_app.task(name="helix.notifications.email.send_trial_expired_email")
def send_trial_expired_email(email: str, store_name: str) -> None:
    html = _base(f"""
      <h2 style="margin:0 0 8px;">Your Helix trial has ended</h2>
      <p>Hi {store_name}, your 14-day trial has expired and your AI chat widget has been paused.</p>
      <p>Subscribe now to restore full access — your products, conversations, and settings are all saved.</p>
      <a href="https://helix.cloudia.co.za/billing"
         style="display:inline-block;background:#7c3aed;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;margin:12px 0;">
        Reactivate Helix
      </a>
    """)
    _send(_api_key(), email, "Your Helix trial has ended — reactivate now", html)


@celery_app.task(name="helix.notifications.email.send_subscription_confirmed")
def send_subscription_confirmed(email: str, store_name: str, tier: str) -> None:
    plan_name = tier.title()
    html = _base(f"""
      <h2 style="margin:0 0 8px;">You're on Helix {plan_name} ✅</h2>
      <p>Hi {store_name}, your <strong>{plan_name}</strong> subscription is now active. Thank you!</p>
      <p>Everything is already running — no changes needed in your WordPress admin.</p>
      <a href="https://helix.cloudia.co.za/billing/portal"
         style="display:inline-block;background:#7c3aed;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;margin:12px 0;">
        Manage Subscription
      </a>
    """)
    _send(_api_key(), email, f"You're subscribed to Helix {plan_name}", html)


@celery_app.task(name="helix.notifications.email.send_payment_failed_email")
def send_payment_failed_email(email: str, store_name: str) -> None:
    html = _base(f"""
      <h2 style="margin:0 0 8px;">⚠️ Payment failed for {store_name}</h2>
      <p>We couldn't process your last Helix payment. Your account has been paused.</p>
      <p>Update your payment method to restore access — it only takes a minute.</p>
      <a href="https://helix.cloudia.co.za/billing/portal"
         style="display:inline-block;background:#dc2626;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;margin:12px 0;">
        Update Payment Method
      </a>
    """)
    _send(_api_key(), email, "Action needed: Helix payment failed", html)


@celery_app.task(name="helix.notifications.email.send_cancellation_email")
def send_cancellation_email(email: str, store_name: str) -> None:
    html = _base(f"""
      <h2 style="margin:0 0 8px;">Your Helix subscription has been cancelled</h2>
      <p>Hi {store_name}, we're sorry to see you go.</p>
      <p>Your data will be kept for <strong>30 days</strong>. If you change your mind, you can resubscribe
         and everything will be exactly as you left it.</p>
      <a href="https://helix.cloudia.co.za/billing"
         style="display:inline-block;background:#7c3aed;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;margin:12px 0;">
        Resubscribe
      </a>
    """)
    _send(_api_key(), email, "Your Helix subscription has been cancelled", html)
