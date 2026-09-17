"""Self-check driver ZTE C320: parsing CPU/RAM/uptime/suhu dari check_connection().

Suhu ZTE diambil lewat 'show card-temperature' (ditemukan dari 'show ?' pada
perangkat nyata; 'show temperature' dan 'show environment' TIDAK ada di C320).

Semua keluaran di bawah disalin apa adanya dari OLT ZTE C320 V2.1.0
([REDACTED_OLT_IDENTITY]), diverifikasi live 2026-08-28.
"""
import os
import sys

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
import drivers.zte_c320 as zte

# --- Keluaran nyata dari OLT ---
PROCESSOR = """
OLT# show show processor

 Rack Shelf Slot  Cpu(5s) Cpu(1m) Cpu(5m) MemSize MemUsage
 1     1     1    12%     11%     10%     512     28%
 1     1     3    45%     40%     38%     1024    31%
"""

SYSTEM_GROUP = """
OLT# show show system-group

System Description: C320 Version V2.1.0 Software, Copyright (c) by ZTE Corporation Compiled
System ObjectId: .1.3.6.1.4.1.3902.1082.1001.320.2.1
Started before: 44 days, 7 hours, 26 minutes
Contact with: [REDACTED_PHONE]
System name:  [REDACTED_OLT_NAME]
Location: [REDACTED_LOCATION]
"""

CARD_TEMP = """
OLT# show show card-temperature

All cards temperature(deg c):
----------------------------------------------------------------------------
Rack Shelf Slot Temperature Temperature(5m) Temperature(1h) Optical-Temp
----------------------------------------------------------------------------
1    1     1    54          54              54              N/A.
1    1     2    47          47              47              N/A.
1    1     3    53          53              53              N/A.
1    1     4    N/A.        N/A.            N/A.            N/A.
[REDACTED_OLT_NAME]#
"""

RAW = PROCESSOR + SYSTEM_GROUP + CARD_TEMP

OLT = {'ip': '[REDACTED]', 'username': 'u', 'password': 'p',
       'protocol': 'TELNET', 'ssh_port': 2336, 'type': 'ZTE C320'}

_calls = []
zte.execute_ssh_commands = lambda olt, c: (_calls.append(list(c)), RAW)[1]
d = zte.OltZteC320Driver()
msg = d.check_connection(OLT)['message']

# 1. Perintah suhu ikut dikirim, dalam satu sesi yang sama (hemat VTY)
assert len(_calls) == 1, f'harus 1 sesi, dapat {len(_calls)}: {_calls}'
assert 'show card-temperature' in _calls[0], _calls[0]

# 2. Suhu = nilai TERTINGGI antar board, satuan derajat Celsius
assert '• Temperature: 54 °C' in msg, msg

# 3. CPU/RAM/uptime tidak rusak oleh penambahan ini
assert '• CPU Load: 45%' in msg, msg
assert '• RAM Usage: 31%' in msg, msg
assert '• Uptime: 44 days, 7 hours, 26 minutes' in msg, msg

# 4. Regex suhu TIDAK boleh ikut menangkap baris 'show processor'
#    (formatnya mirip: rack, shelf, slot, lalu angka). Kalau tertukar,
#    hasilnya jadi 12/45, bukan 54.
assert zte.OltZteC320Driver._parse_card_temperature(PROCESSOR) == 'N/A', \
    'baris show processor salah dibaca sebagai suhu'

# 5. Slot kosong ('N/A.') dilewati, tidak dianggap 0
only_empty = """
Rack Shelf Slot Temperature Temperature(5m) Temperature(1h) Optical-Temp
1    1     4    N/A.        N/A.            N/A.            N/A.
"""
assert zte.OltZteC320Driver._parse_card_temperature(only_empty) == 'N/A'

# 6. Keluaran kosong / perintah ditolak -> N/A, bukan error
assert zte.OltZteC320Driver._parse_card_temperature('') == 'N/A'
assert zte.OltZteC320Driver._parse_card_temperature(
    "%Error 20200: Invalid input detected at '^' marker.") == 'N/A'

# 7. Satu board saja tetap terbaca
satu = "1    1     2    47          47              47              N/A.\n"
assert zte.OltZteC320Driver._parse_card_temperature(satu) == '47 °C'

# 8. Driver tetap tidak mengklaim status SNMP (aturan #238)
assert 'SNMP Status' not in msg, msg

# 9. Pola yang dibaca get-olt-health.php harus cocok
import re
m = re.search(r'Temperature\s*:\s*([^\n\r]+)', msg, re.I)
assert m and m.group(1).strip() == '54 °C', msg

# 10. Mode demo tetap utuh
demo = d.check_connection(dict(OLT, ip='127.0.0.1'))
assert demo['success'] and 'Temperature' in demo['message']

print('test_zte_health.py: semua pemeriksaan LULUS')
