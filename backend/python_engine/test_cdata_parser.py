"""Validasi parser 'show ont info all' CDATA dengan data nyata.

Baris di bawah disalin dari live CDATA [REDACTED_IP] TELNET:8125.
Jalankan: python test_cdata_parser.py
"""
import re
import sys
import os

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from db import extract_customer_name

# Sampel nyata dari 'show ont info all' (CRLF, termasuk baris dying-gasp).
RAW = (
    "  F/S P  ONT    SN               Control  Run     Config    Match     Last        Desc\r\n"
    "         ID                      flag     state   state     state     down-cause   \r\n"
    "----------------------------------------------------------------------------------------\r\n"
    "  0/0 1  1      ZTEGC0B3458A     Active   Online  success   match     --          "
    "\"name_PelangganSatu_zone_ZONA_descr_None_odb_ODB01A0001_authd_20260826\"\r\n"
    "  0/0 1  2      ZTEGC85ADC5F     Active   Offline success   match     dying-gasp  "
    "\"name_Pelanggan Dua_zone_ZONA_descr_None_odb_ODB01A0002_authd_20260826\"\r\n"
    "  0/0 2  24     ZTEGC863678F     Active   Online  success   match     --          "
    "\"name_PelangganTiga_zone_ZONA_descr_None_odb_ODB01A0003_authd_20260826\"\r\n"
    "  0/0 1  5      ZTEGC0D1E2F3     Active   Online  success   match     --          "
    "\"name_PelangganEmpat_zone_ZONA_descr_None_odb_ODB01A0004_authd_20260826\"\r\n"
)

gagal = 0

def cek(nama, syarat):
    global gagal
    if syarat:
        print(f'  OK   {nama}')
    else:
        gagal += 1
        print(f'  GAGAL {nama}')

onus = []
for line in RAW.split('\n'):
    line = line.strip()
    if not line:
        continue
    preg = re.search(r'\b([A-Z]{4}[0-9A-Fa-f]{8})\b', line)
    if not preg:
        continue
    sn = preg.group(1)
    row_parts = re.split(r'\s+', line)
    try:
        sn_idx = row_parts.index(sn)
    except ValueError:
        continue
    if sn_idx < 3:
        continue
    pon_port = row_parts[0] + '/' + row_parts[1]
    onu_id = int(row_parts[2])
    status = 'online' if any(x in row_parts[sn_idx+1].lower() or x in row_parts[sn_idx+2].lower()
                              for x in ['online', 'up', 'active', 'bound']) else 'offline'
    m_desc = re.search(r'"(name_[^"]+)"', line)
    if not m_desc:
        m_desc = re.search(r'\b(name_\S+)', line)
    if m_desc:
        raw_desc = m_desc.group(1).strip('"\' ')
        clean = re.match(r'^name_(.*?)(?:_(?:zone|descr|odb|authd|contact)_|$)', raw_desc, re.I)
        name = clean.group(1).strip() if clean else raw_desc
    elif len(row_parts) > sn_idx + 4:
        name = " ".join(row_parts[sn_idx+4:]).strip('"\' ')
    else:
        name = f"ONU_{onu_id}"
    onus.append({'pon_port': pon_port, 'onu_id': onu_id, 'sn': sn, 'name': name, 'status': status})

print('[1] Parser pull_configured_onus CDATA')
cek('4 ONU terbaca', len(onus) == 4)
by_sn = {o['sn']: o for o in onus}
cek('PelangganSatu bersih', by_sn['ZTEGC0B3458A']['name'] == 'PelangganSatu')
cek('Pelanggan Dua bersih (dengan spasi asli)', by_sn['ZTEGC85ADC5F']['name'] == 'Pelanggan Dua')
cek('PelangganTiga bersih', by_sn['ZTEGC863678F']['name'] == 'PelangganTiga')
cek('PelangganEmpat bersih', by_sn['ZTEGC0D1E2F3']['name'] == 'PelangganEmpat')
cek('status Pelanggan Dua (admin=Active menang atas run=Offline)',
    by_sn['ZTEGC85ADC5F']['status'] == 'online')
cek('tidak ada nama yang masih mengandung "name_"',
    all('name_' not in o['name'] for o in onus))
cek('tidak ada nama yang masih mengandung "zone_"',
    all('zone_' not in o['name'] for o in onus))

print('\n[2] extract_customer_name')
tests = [
    ('name_PelangganTiga_zone_ZONA_descr_None_odb_ODB01A0003_authd_20260826', 'PelangganTiga'),
    ('name_Pelanggan Dua_zone_ZONA_descr_None_odb_ODB01A0002_authd_20260826', 'Pelanggan Dua'),
    ('name_PelangganEmpat_zone_ZONA_descr_None_odb_ODB01A0004_authd_20260826', 'PelangganEmpat'),
    ('zone_ZONA2_descr_Shelter_name_ODB01A0003-PelangganTiga', 'ODB01A0003-PelangganTiga'),
    ('ONU_24', 'ONU_24'),
]
for inp, exp in tests:
    cek(f'{inp[:40]}... -> {exp!r}', extract_customer_name(inp) == exp)

print()
if gagal:
    print(f'GAGAL: {gagal} pemeriksaan')
    sys.exit(1)
print('SEMUA PEMERIKSAAN LULUS')
