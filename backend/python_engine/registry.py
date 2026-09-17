"""
REGISTRY DRIVER OLT (Python Engine) — cerminan olt_driver_registry() di backend/driver.php.

SATU-SATUNYA tempat pendaftaran vendor di sisi Python. Menambah brand baru =
tambah satu entri di sini + satu berkas di drivers/.

ATURAN KERAS: tidak ada fallback diam-diam antar vendor. Tipe yang tidak dikenali
mengembalikan None, bukan driver vendor lain — perintah CLI antar vendor berbeda
dan bisa merusak konfigurasi perangkat.
"""
import re

from drivers.zte_c320 import OltZteC320Driver
from drivers.zte_c300 import OltZteC300Driver
from drivers.cdata_fd1602sb1 import OltCdataFd1602sb1Driver

REGISTRY = {
    'CDATA FD1602SB1(GPON)': {
        'label': 'CDATA FD1602SB1 (GPON)',
        'class': OltCdataFd1602sb1Driver,
        # Nilai `type` lama yang mungkin masih tersimpan di database.
        'aliases': ['GPON', 'CDATA', 'FD1602S', 'FD1602SB1', 'CDATA FD1602S-B1'],
    },
    'ZTE C320': {
        'label': 'ZTE C320 (GPON)',
        'class': OltZteC320Driver,
        'aliases': ['ZTE', 'C320', 'ZTE C320'],
    },
    'ZTE C300': {
        'label': 'ZTE C300 (GPON)',
        'class': OltZteC300Driver,
        'aliases': ['C300', 'ZTE C300'],
    },
}

# Instance dibuat sekali (driver stateless, aman dipakai ulang).
_INSTANCES = {key: meta['class']() for key, meta in REGISTRY.items()}


def normalize_olt_type(olt_type: str) -> str:
    """Menormalkan nama tipe OLT untuk pencocokan longgar (huruf & angka saja)."""
    return re.sub(r'[^A-Z0-9]', '', (olt_type or '').upper())


def find_driver_key(olt_type: str):
    """Cocokkan tipe OLT ke kunci registry. Balikan None bila tak dikenali.

    Bertingkat: 1) sama persis, 2) kunci dinormalkan, 3) alias dinormalkan.
    Pencocokan alias memakai kesamaan PENUH, bukan substring.
    """
    if olt_type in REGISTRY:
        return olt_type

    norm = normalize_olt_type(olt_type)
    if not norm:
        return None

    for key, meta in REGISTRY.items():
        if normalize_olt_type(key) == norm:
            return key
        for alias in meta.get('aliases', []):
            if normalize_olt_type(alias) == norm:
                return key
    return None


def get_driver(olt: dict):
    """Resolusi driver dari baris OLT. Balikan instance driver atau None.

    `driver_class` (dikirim PHP dari registry-nya) dipakai lebih dulu dengan
    pencocokan nama kelas PERSIS — bukan substring.
    """
    driver_class = olt.get('driver_class')
    if driver_class:
        for key, meta in REGISTRY.items():
            if meta['class'].__name__ == driver_class:
                return _INSTANCES[key]

    key = find_driver_key(olt.get('type', ''))
    return _INSTANCES[key] if key else None


def get_supported_olt_types() -> dict:
    """Balikan {'<type>': '<label tampil>'} untuk dropdown frontend."""
    return {key: meta['label'] for key, meta in REGISTRY.items()}


if __name__ == '__main__':
    # Self-check: jalankan `python3 registry.py` untuk memverifikasi resolusi tipe.
    assert find_driver_key('ZTE C320') == 'ZTE C320'
    assert find_driver_key('zte-c320') == 'ZTE C320'
    assert find_driver_key('C320') == 'ZTE C320'
    assert find_driver_key('CDATA FD1602SB1(GPON)') == 'CDATA FD1602SB1(GPON)'
    assert find_driver_key('GPON') == 'CDATA FD1602SB1(GPON)'
    assert find_driver_key('CDATA FD1602S-B1') == 'CDATA FD1602SB1(GPON)'
    # Tidak dikenali -> None, TIDAK jatuh ke vendor lain.
    assert find_driver_key('') is None
    assert find_driver_key('HUAWEI MA5608T') is None
    # Substring tidak boleh cocok: 'XPON' & 'EPON GPON MIX' bukan CDATA.
    assert find_driver_key('EPON GPON MIX') is None
    assert get_driver({'type': 'ZTE C320'}).__class__.__name__ == 'OltZteC320Driver'
    assert get_driver({'driver_class': 'OltZteC320Driver', 'type': 'GPON'}).__class__.__name__ == 'OltZteC320Driver'
    assert get_driver({'type': 'Huawei'}) is None
    print('registry.py: semua pemeriksaan LULUS')
