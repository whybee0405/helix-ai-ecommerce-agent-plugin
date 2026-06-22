import io
import json
import zipfile
from pathlib import Path

from fastapi import APIRouter
from fastapi.responses import JSONResponse, Response

router = APIRouter(prefix="/v1/plugin", tags=["plugin"])

_PLUGIN_DIR = Path("/plugin")
_VERSION = "0.5.3"


def _build_zip() -> bytes:
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
        for path in sorted(_PLUGIN_DIR.rglob("*")):
            if path.is_file():
                arcname = "helix-connector/" + str(path.relative_to(_PLUGIN_DIR))
                zf.write(path, arcname)
    return buf.getvalue()


@router.get("/manifest", include_in_schema=False)
async def plugin_manifest() -> JSONResponse:
    return JSONResponse({
        "name": "Helix Connector",
        "slug": "helix-connector",
        "version": _VERSION,
        "download_url": "https://helix.cloudia.co.za/v1/plugin/download",
        "requires": "7.0",
        "tested": "6.5",
        "last_updated": "2026-06-22",  # power-features release
    })


@router.get("/download", include_in_schema=False)
async def plugin_download() -> Response:
    if not _PLUGIN_DIR.exists():
        return Response(status_code=404, content="Plugin directory not mounted")
    data = _build_zip()
    return Response(
        content=data,
        media_type="application/zip",
        headers={"Content-Disposition": 'attachment; filename="helix-connector.zip"'},
    )
