import sys
import os
import re
from typing import List, Dict, Any, Optional
from fastapi import FastAPI, HTTPException, Header
from pydantic import BaseModel

# Add engine directory to path before local imports
sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from registry import get_driver, get_supported_olt_types
from auth import verify_token


app = FastAPI(
    title="SmartOLT Python Network Automation Engine",
    description="Microservice to run real-time OLT CLI commands via SSH/Telnet.",
    version="1.0.0"
)


def require_auth(token: Optional[str], superadmin_only: bool = False) -> dict:
    """Verifikasi token HMAC dari PHP. Lempar 401/403 bila tidak sah.

    TIDAK ADA nilai default peran di sini — tanpa token sah, permintaan ditolak.
    """
    ident = verify_token(token or '')
    if not ident:
        raise HTTPException(status_code=401, detail="Token tidak sah atau kedaluwarsa.")
    if superadmin_only and ident['role'] != 'superadmin':
        raise HTTPException(status_code=403, detail="Akses ditolak. Hanya superadmin.")
    return ident


class CallRequest(BaseModel):
    olt: Dict[str, Any]
    method: str
    args: List[Any] = []

def camel_to_snake(name: str) -> str:
    """
    Convert camelCase (PHP) to snake_case (Python).
    e.g., getOnuSignal -> get_onu_signal
    """
    s1 = re.sub('(.)([A-Z][a-z]+)', r'\1_\2', name)
    return re.sub('([a-z0-9])([A-Z])', r'\1_\2', s1).lower()

@app.get("/health")
def health_check():
    return {"status": "healthy", "engine": "Python REST API"}


@app.get("/olt/types")
def olt_types(x_smartolt_token: Optional[str] = Header(None)):
    """Daftar tipe OLT yang didukung engine (sumber tunggal dropdown frontend)."""
    require_auth(x_smartolt_token)
    return {'success': True, 'types': get_supported_olt_types()}


@app.post("/olt/call")
def call_olt(payload: CallRequest, x_smartolt_token: Optional[str] = Header(None)):
    require_auth(x_smartolt_token)

    olt = payload.olt
    php_method = payload.method
    args = payload.args

    olt_type = olt.get('type', '')
    driver = get_driver(olt)
    if not driver:
        raise HTTPException(status_code=400, detail=f"Driver untuk tipe OLT '{olt_type}' tidak ditemukan.")

    # Map PHP camelCase method to Python snake_case
    py_method_name = camel_to_snake(php_method)

    # Hanya method publik driver yang boleh dipanggil — cegah pemanggilan
    # atribut internal / dunder lewat nama method dari pemanggil.
    if py_method_name.startswith('_') or not hasattr(driver, py_method_name):
        raise HTTPException(
            status_code=404,
            detail=f"Method '{py_method_name}' (PHP: {php_method}) tidak didukung oleh driver {olt_type}."
        )

    method = getattr(driver, py_method_name)
    if not callable(method):
        raise HTTPException(status_code=404, detail=f"Method '{py_method_name}' tidak dapat dipanggil.")

    try:
        # Run OLT method
        # The first argument is always the OLT dict
        result = method(olt, *args)
        return result
    except Exception as e:
        return {
            'success': False,
            'message': f"Engine Error: {str(e)}"
        }


# Endpoint /action/get-signals-db, /action/get-autofind, dan /action/get-next-onu-id
# DIHAPUS (#235). Ketiganya nol pemanggil: frontend memakai versi PHP-nya
# (configured.php:203, unconfigured.php:230 dan :325) karena uvicorn hanya bind ke
# 127.0.0.1 sehingga browser tak bisa menjangkaunya. Menyimpannya berarti dua
# salinan logika RBAC yang bisa menyimpang diam-diam.


if __name__ == "__main__":
    import uvicorn
    uvicorn.run("app:app", host="127.0.0.1", port=8000, reload=True)
