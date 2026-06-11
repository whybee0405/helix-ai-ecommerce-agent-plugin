from celery import Celery

# Use placeholder URLs at module load time; actual URLs resolved lazily from settings at worker start.
# In production the worker is started with the correct env vars already set.
try:
    from helix.config import get_settings
    _settings = get_settings()
    _broker = str(_settings.redis_url)
    _backend = str(_settings.redis_url)
except Exception:
    _broker = "redis://localhost:6379/0"
    _backend = "redis://localhost:6379/0"

celery_app = Celery(
    "helix",
    broker=_broker,
    backend=_backend,
    include=["helix.workers.tasks.embedding"],
)
celery_app.conf.update(
    task_serializer="json",
    accept_content=["json"],
    result_serializer="json",
    timezone="UTC",
    enable_utc=True,
    task_routes={
        "helix.workers.tasks.embedding.*": {"queue": "embedding"},
    },
)
