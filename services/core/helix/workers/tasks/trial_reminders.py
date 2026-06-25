"""
Celery beat task: scan for tenants whose trial ends in ~7 or ~1 day and send reminders.
Runs daily — safe to re-run (idempotent via Redis dedup key).
"""
from __future__ import annotations

from datetime import datetime, timedelta, timezone

import structlog

from helix.workers.celery_app import celery_app

logger = structlog.get_logger(__name__)


@celery_app.task(name="helix.workers.tasks.trial_reminders.send_trial_reminders")
def send_trial_reminders() -> dict:
    from helix.db.engine import get_sync_session
    from helix.db.models import Tenant
    from sqlalchemy import select, and_

    now = datetime.now(timezone.utc)
    # Find tenants whose trial ends in 6–8 days (day-7 window) or 0–2 days (day-1 window).
    windows = [
        (timedelta(days=6), timedelta(days=8), 7),
        (timedelta(days=0), timedelta(days=2), 1),
    ]

    sent = 0
    try:
        import redis as sync_redis
        from helix.config import get_settings
        r = sync_redis.from_url(str(get_settings().redis_url))
    except Exception:
        r = None

    with get_sync_session() as session:
        for lo, hi, days_label in windows:
            rows = session.execute(
                select(Tenant).where(
                    and_(
                        Tenant.subscription_status == "trialing",
                        Tenant.trial_ends_at >= now + lo,
                        Tenant.trial_ends_at <= now + hi,
                        Tenant.billing_email.isnot(None),
                    )
                )
            ).scalars().all()

            for tenant in rows:
                dedup_key = f"trial_reminder_sent:{tenant.id}:day{days_label}"
                if r and r.exists(dedup_key):
                    continue

                try:
                    from helix.notifications.email import send_trial_reminder
                    send_trial_reminder.delay(
                        email=tenant.billing_email,
                        store_name=tenant.name,
                        days_remaining=days_label,
                    )
                    sent += 1
                    if r:
                        r.setex(dedup_key, 3 * 24 * 3600, "1")  # dedup for 3 days
                except Exception as exc:
                    logger.error("trial_reminder_failed", tenant_id=str(tenant.id), error=str(exc))

    logger.info("trial_reminders_sent", count=sent)
    return {"sent": sent}
