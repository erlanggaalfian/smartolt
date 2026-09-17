"""
Jembatan autentikasi PHP -> Python Engine.

PHP memegang sesi login. Python tidak bisa membaca sesi PHP, jadi PHP mencetak
token HMAC berumur pendek ke halaman; browser/PHP mengirimnya di header
`X-SmartOLT-Token`, dan Python memverifikasinya dengan APP_KEY yang sama.

Hanya memakai pustaka standar (hmac/hashlib/base64/json) — tanpa dependensi baru.
Format token: base64url(payload_json) + "." + base64url(hmac_sha256)
Payload: {"uid": <int>, "role": "<superadmin|biasa>", "exp": <unix_ts>}
"""
import base64
import hashlib
import hmac
import json
import os
import time

DEFAULT_KEY = 'some-default-long-secret-key-change-me'
TOKEN_TTL = 3600  # 1 jam


def _key() -> bytes:
    return (os.getenv('APP_KEY') or DEFAULT_KEY).encode('utf-8')


def _b64e(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).decode('ascii').rstrip('=')


def _b64d(txt: str) -> bytes:
    return base64.urlsafe_b64decode(txt + '=' * (-len(txt) % 4))


def mint_token(user_id: int, role: str, ttl: int = TOKEN_TTL) -> str:
    """Membuat token. Dipakai oleh pengujian & skrip Python; PHP punya kembarannya."""
    payload = _b64e(json.dumps(
        {'uid': int(user_id), 'role': role, 'exp': int(time.time()) + ttl},
        separators=(',', ':'), sort_keys=True,
    ).encode('utf-8'))
    sig = hmac.new(_key(), payload.encode('ascii'), hashlib.sha256).digest()
    return f'{payload}.{_b64e(sig)}'


def verify_token(token: str):
    """Verifikasi token. Balikan dict {'uid','role'} bila sah, None bila tidak.

    Menolak: format salah, tanda tangan tidak cocok, dan token kedaluwarsa.
    """
    if not token or '.' not in token:
        return None

    payload_b64, _, sig_b64 = token.partition('.')
    expected = hmac.new(_key(), payload_b64.encode('ascii'), hashlib.sha256).digest()

    try:
        given = _b64d(sig_b64)
    except Exception:
        return None

    # compare_digest: cegah kebocoran lewat perbedaan waktu perbandingan.
    if not hmac.compare_digest(expected, given):
        return None

    try:
        data = json.loads(_b64d(payload_b64))
    except Exception:
        return None

    if not isinstance(data, dict) or int(data.get('exp', 0)) < int(time.time()):
        return None

    role = data.get('role')
    if role not in ('superadmin', 'biasa'):
        return None

    return {'uid': int(data.get('uid', 0)), 'role': role}


if __name__ == '__main__':
    # Self-check: jalankan `python3 auth.py`.
    os.environ['APP_KEY'] = 'kunci-uji-coba'

    good = mint_token(7, 'superadmin')
    assert verify_token(good) == {'uid': 7, 'role': 'superadmin'}

    # Tanda tangan dirusak -> ditolak.
    assert verify_token(good[:-2] + ('aa' if not good.endswith('aa') else 'bb')) is None
    # Payload diganti tanpa tanda tangan baru -> ditolak.
    forged = _b64e(json.dumps({'uid': 1, 'role': 'superadmin', 'exp': 9999999999}).encode())
    assert verify_token(f'{forged}.{good.split(".")[1]}') is None
    # Kedaluwarsa -> ditolak.
    assert verify_token(mint_token(7, 'superadmin', ttl=-1)) is None
    # Peran tak dikenal -> ditolak.
    assert verify_token(mint_token(7, 'root')) is None
    # Kunci beda -> ditolak.
    os.environ['APP_KEY'] = 'kunci-lain'
    assert verify_token(good) is None

    print('auth.py: semua pemeriksaan LULUS')
