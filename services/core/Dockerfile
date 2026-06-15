FROM python:3.12-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends gcc libpq-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY pyproject.toml .
# Install CPU-only torch first so pip doesn't pull the ~700 MB CUDA wheel
RUN pip install --no-cache-dir torch --index-url https://download.pytorch.org/whl/cpu
RUN pip install --no-cache-dir --editable ".[dev]"

COPY . .

# Bake the embedding model into the image so the first widget hit isn't slow.
# The hf-cache volume in compose.yaml overlays this at runtime — if the volume
# already has the model the download is skipped; if not, the baked copy is used.
RUN python -c "from sentence_transformers import SentenceTransformer; SentenceTransformer('BAAI/bge-small-en-v1.5')" \
    && echo "bge-small model pre-fetched"

ENV PYTHONUNBUFFERED=1 PYTHONDONTWRITEBYTECODE=1
