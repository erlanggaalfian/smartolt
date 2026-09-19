"""Uji parser panel ZTE dengan keluaran ASLI dari OLT C320 V2.1.0.

Semua sampel di bawah disalin apa adanya dari sesi telnet ke
OLT_C320_GBB_Boyolali (103.210.52.64) — lihat zte_olt_manual_book.md.
Jalankan: python test_zte_panels.py
"""
import os
import re
import sys

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from drivers.zte_c320 import OltZteC320Driver  # noqa: E402

d = OltZteC320Driver()
gagal = 0


def cek(nama, syarat):
    global gagal
    if syarat:
        print(f'  OK   {nama}')
    else:
        gagal += 1
        print(f'  GAGAL {nama}')


# --- 1. show gpon onu state: OnuIndex TANPA awalan 'gpon-onu_' ---------------
ONU_STATE = """OnuIndex   Admin State  OMCC State  Phase State  Channel
--------------------------------------------------------------
1/1/1:1     enable       enable      working      1(GPON)
1/1/1:4     enable       disable     OffLine      1(GPON)
1/1/1:9     enable       disable     OffLine      1(GPON)
1/1/1:21    enable       enable      working      1(GPON)
ONU Number: 2/4
"""

print('[1] Parser status ONU per port PON')
baris = list(re.finditer(r'^\s*(\d+/\d+/\d+):(\d+)\s+\S+\s+\S+\s+(\S+)\s',
                         ONU_STATE, re.M))
cek('4 ONU terbaca', len(baris) == 4)
cek('2 ONU working', sum(1 for m in baris if m.group(3).lower() == 'working') == 2)
cek('baris header "OnuIndex" tidak ikut terhitung',
    all(m.group(1)[0].isdigit() for m in baris))
cek('baris "ONU Number" tidak ikut terhitung',
    all('Number' not in m.group(0) for m in baris))

# --- 2. get_next_free_onu_id: regresi awalan 'gpon-onu_' ---------------------
print('\n[2] ID ONU bebas berikutnya (regresi: regex menuntut awalan gpon-onu_)')
used = {int(m.group(1)) for m in
        re.finditer(r'(?:gpon-onu_)?' + re.escape('1/1/1') + r':(\d+)\b',
                    ONU_STATE, re.I)}
cek('ID terpakai terdeteksi {1,4,9,21}', used == {1, 4, 9, 21})
cek('ID bebas berikutnya = 2', next(i for i in range(1, 129) if i not in used) == 2)
cek('regex lama memang tidak cocok (bukti bug)',
    re.findall(r'gpon-onu_[0-9\/]+:(\d+)', ONU_STATE, re.I) == [])

# --- 3. show interface: gei & xgei, blok terpisah ---------------------------
IFACE = """gei_1/3/1 is down,  line protocol is down,  detect status is OK
  Description is none
  The port is optical
   20 seconds input rate :                  0 Bps,                0 pps
   20 seconds output rate:                  0 Bps,                0 pps
xgei_1/3/2 is up,  line protocol is up,  detect status is OK
  Description is Up_Link
  The port is optical
   20 seconds input rate :           46645652 Bps,            36941 pps
   20 seconds output rate:            2859636 Bps,            15217 pps
"""

print('\n[3] Parser interface uplink')
blok = [b for b in re.split(r'^(?=(?:x?gei)_\S+\s+is\s+)', IFACE, flags=re.M | re.I)
        if re.match(r'x?gei_\S+\s+is\s+', b, re.I)]
cek('2 blok interface (gei + xgei)', len(blok) == 2)
m = re.match(r'(x?gei_\S+)\s+is\s+(\S+?),\s+line protocol is\s+(\S+?),', blok[1], re.I)
cek('nama xgei_1/3/2 terbaca', m and m.group(1) == 'xgei_1/3/2')
cek('admin & line = up', m and m.group(2) == 'up' and m.group(3) == 'up')
cek('deskripsi Up_Link tidak tertukar dengan blok gei',
    re.search(r'Description is\s+([^\r\n]+)', blok[1], re.I).group(1).strip() == 'Up_Link')
cek('deskripsi blok pertama = none',
    re.search(r'Description is\s+([^\r\n]+)', blok[0], re.I).group(1).strip() == 'none')
rx = int(re.search(r'20 seconds input rate\s*:\s*(\d+)\s*Bps', blok[1], re.I).group(1))
cek('laju RX 46645652 Bps = 373.17 Mbps', abs(rx * 8 / 1e6 - 373.165216) < 0.001)

# --- 4. show vlan summary ---------------------------------------------------
VLAN_SUM = """All created vlan num: 4
Details are following:
    1,15,100,438

"""

print('\n[4] Parser daftar VLAN')
ids = []
for b in re.findall(r'^\s*([\d,\-\s]+?)\s*$', VLAN_SUM, re.M):
    for bag in b.replace(' ', '').split(','):
        if bag.isdigit():
            ids.append(int(bag))
        elif re.fullmatch(r'\d+-\d+', bag):
            a, z = bag.split('-')
            ids.extend(range(int(a), int(z) + 1))
ids = sorted({v for v in ids if 1 <= v <= 4094})
cek('VLAN 1,15,100,438 terbaca', ids == [1, 15, 100, 438])
cek('angka "4" dari "All created vlan num: 4" tidak ikut',
    4 not in ids)

RENTANG = "    10-12,20\n"
ids2 = []
for b in re.findall(r'^\s*([\d,\-\s]+?)\s*$', RENTANG, re.M):
    for bag in b.replace(' ', '').split(','):
        if bag.isdigit():
            ids2.append(int(bag))
        elif re.fullmatch(r'\d+-\d+', bag):
            a, z = bag.split('-')
            ids2.extend(range(int(a), int(z) + 1))
cek('rentang "10-12" dimekarkan', sorted(set(ids2)) == [10, 11, 12, 20])

# --- 5. show vlan <id>: nama & deskripsi ------------------------------------
VLAN_DET = """vlanid          :1
name            :VLAN0001
description     :N/A
tpid            :0x8100
port(untagged):
  gpon-onu_1/1/1:1,4,9
vlanid          :100
name            :VLAN0100
description     :MGMT
tpid            :0x8100
"""

print('\n[5] Parser detail VLAN')
info = {}
for b in re.split(r'(?=^vlanid\s*:)', VLAN_DET, flags=re.M | re.I):
    mv = re.search(r'^vlanid\s*:\s*(\d+)', b, re.I | re.M)
    if not mv:
        continue
    mn = re.search(r'^name\s*:\s*(\S+)', b, re.I | re.M)
    md = re.search(r'^description\s*:\s*([^\r\n]+)', b, re.I | re.M)
    desc = md.group(1).strip() if md else ''
    if desc.upper() in ('N/A', 'N/A.'):
        desc = ''
    info[int(mv.group(1))] = desc or (mn.group(1).strip() if mn else '')
cek('description "N/A" jatuh ke name', info.get(1) == 'VLAN0001')
cek('description asli dipakai bila ada', info.get(100) == 'MGMT')
cek('baris port(untagged) tidak merusak pemisahan blok', len(info) == 2)

# --- 5b. show vlan port: jebakan "UntaggedVlan" memuat "TaggedVlan" ---------
VLAN_PORT = (
    "PortMode      Pvid  CPvid Tpid/mode   TLSStatus TLSVlan  ProtEn   PrioEn\r\n"
    "-------------------------------------------------------------------------\r\n"
    "trunk>0       1     0     0x8100/PORT disable   0        disable  disable   \r\n"
    "\r\x00UntaggedVlan:\r\nTaggedVlan:\r\n1,15,100\r\n"
)
VLAN_PORT_HYBRID = (
    "PortMode      Pvid  CPvid Tpid/mode   TLSStatus TLSVlan  ProtEn   PrioEn\r\n"
    "-------------------------------------------------------------------------\r\n"
    "hybrid>=0     1     0     0x8100/PORT disable   0        disable  disable   \r\n"
    "\r\x00UntaggedVlan:\r\n1\r\nTaggedVlan:\r\n"
)


def ambil(b):
    def daftar(t):
        h = set()
        for bag in re.sub(r'\s+', '', t or '').split(','):
            if bag.isdigit():
                h.add(int(bag))
            elif re.fullmatch(r'\d+-\d+', bag):
                a, z = bag.split('-')
                h.update(range(int(a), int(z) + 1))
        return h
    mm = re.search(r'^\s*(\w+)\s*>?=?\d*\s+(\d+)\s+\d+\s', b, re.M)
    mu = re.search(r'UntaggedVlan\s*:\s*([\d,\-\s]*?)(?=(?<!Un)TaggedVlan|$)', b, re.I)
    mt = re.search(r'(?<!Un)TaggedVlan\s*:\s*([\d,\-\s]*)', b, re.I)
    return (mm.group(1).lower() if mm else '',
            daftar(mu.group(1) if mu else ''), daftar(mt.group(1) if mt else ''))


print('\n[5b] Parser keanggotaan VLAN per port uplink')
mode, untag, tag = ambil(VLAN_PORT)
cek('mode trunk terbaca', mode == 'trunk')
cek('trunk: tagged = {1,15,100}', tag == {1, 15, 100})
cek('trunk: untagged kosong (bukan ikut ambil daftar tagged)', untag == set())
mode, untag, tag = ambil(VLAN_PORT_HYBRID)
cek('mode hybrid terbaca (">=0" tidak ikut)', mode == 'hybrid')
cek('hybrid: untagged = {1}', untag == {1})
cek('hybrid: tagged kosong', tag == set())
cek('daftar VLAN lintas baris CRLF terbaca (bukan gagal isdigit)',
    ambil(VLAN_PORT)[2] == {1, 15, 100})
cek('lookbehind (?<!Un) memang perlu (bukti bug)',
    re.search(r'TaggedVlan\s*:\s*([\d,\-\s]*)', VLAN_PORT_HYBRID, re.I).group(1).strip() == '1')

# --- 5c. show interface port-status -----------------------------------------
PORT_STATUS = """--------------------------------------------------------------------------------
     Port      hybrid  Native Negotiation  Speed  Duplex Flow-   Admin      Link
               Status  VLAN     auto       (Mbps)        Ctrl    Status
--------------------------------------------------------------------------------
gei_1/3/1      optical  1       enable     auto    full  disable activate   down
xgei_1/3/2     optical  1       disable    10000   full  disable activate   up
gei_1/3/3      electric 1       enable     auto    auto  disable activate   down
"""

print('\n[5c] Parser status port uplink')
rows = list(re.finditer(
    r'^[ \t]*(x?gei_\d+/\d+/\d+)[ \t]+(\S+)[ \t]+(\d+)[ \t]+(\S+)[ \t]+(\S+)'
    r'[ \t]+(\S+)[ \t]+(\S+)[ \t]+(\S+)[ \t]+(\S+)[ \t\r]*$', PORT_STATUS, re.M | re.I))
cek('3 port terbaca, baris header tidak ikut', len(rows) == 3)
cek('xgei_1/3/2 link up', rows[1].group(1) == 'xgei_1/3/2' and rows[1].group(9) == 'up')
cek('negosiasi disable pada port yang di-force', rows[1].group(4) == 'disable')
cek('speed "auto" TIDAK dipakai sebagai speed konfigurasi',
    (rows[0].group(5) if rows[0].group(5).isdigit() else '') == '')
cek('speed numerik dipakai apa adanya', rows[1].group(5) == '10000')

# --- 5d. config_port: validasi masukan --------------------------------------
print('\n[5d] Validasi config_port ZTE')
olt_palsu = {'ip': '0.0.0.0', 'type': 'ZTE C320'}
cek('port asing ditolak',
    d.config_port(olt_palsu, 'ge 0/0/1', 'enable', '', '')['success'] is False)
cek('speed di luar daftar ditolak',
    d.config_port(olt_palsu, 'gei_1/3/1', 'disable', '2500', 'full')['success'] is False)
cek('port gei tidak boleh dipaksa 10000 Mbps',
    d.config_port(olt_palsu, 'gei_1/3/1', 'disable', '10000', 'full')['success'] is False)
cek('duplex asing ditolak',
    d.config_port(olt_palsu, 'gei_1/3/1', 'disable', '1000', 'auto')['success'] is False)
cek('auto_nego asing ditolak',
    d.config_port(olt_palsu, 'gei_1/3/1', 'mungkin', '', '')['success'] is False)

# --- 5e. Penulisan VLAN: validasi & susunan perintah (tanpa perangkat) ------
print('\n[5e] Validasi penulisan VLAN ZTE')
cek('VLAN ID di luar 1-4094 ditolak',
    d.save_vlan(olt_palsu, 5000)['success'] is False)
cek('VLAN 1 tidak boleh dihapus',
    d.delete_vlan(olt_palsu, 1)['success'] is False)
cek('nama port asing ditolak save_vlan_ports',
    d.save_vlan_ports(olt_palsu, 15, '', {'ge 0/0/1': 'tagged'})['success'] is False)
cek('nilai port asing ditolak save_vlan_ports',
    d.save_vlan_ports(olt_palsu, 15, '', {'gei_1/3/1': 'trunk'})['success'] is False)
cek('deskripsi dibersihkan dari baris baru (anti command injection)',
    '\n' not in d._sanitize_vlan_description('UPLINK\nno vlan 1'))
cek('deskripsi dipotong 32 karakter',
    len(d._sanitize_vlan_description('x' * 80)) == 32)
cek('%Error terdeteksi sebagai penolakan',
    d._vlan_errors('%Error 20202: Invalid parameter') != [])
cek('%Info bukan penolakan',
    d._vlan_errors('%Info 20272: Enter configuration commands') == [])

# --- 6. Panel yang diklaim didukung ------------------------------------------
print('\n[6] Registrasi panel')
panels = d.get_supported_panels()
cek('4 panel: olt-details, olt-cards, pon-ports, interfaces',
    set(panels) == {'olt-details', 'olt-cards', 'pon-ports', 'interfaces'})
for p in panels:
    cek(f'panel "{p}" punya handler', callable(
        {'olt-details': d._panel_olt_details, 'olt-cards': d._panel_olt_cards,
         'pon-ports': d._panel_pon_ports, 'interfaces': d._panel_interfaces}.get(p)))
r = d.get_panel_data({'ip': '0.0.0.0'}, 'tidak-ada')
cek('panel asing ditolak, tidak fallback', r['success'] is False)

# --- 7. Tidak ada badan fungsi terpotong (regresi #237) ---------------------
print('\n[7] Integritas berkas driver')
src = open(os.path.join(os.path.dirname(os.path.abspath(__file__)),
                        'drivers', 'zte_c320.py'), encoding='utf-8').read()
cek('tidak ada "omitted for brevity"', 'omitted for brevity' not in src)
cek('tidak ada literal "SNMP Status"', 'SNMP Status' not in src)
kode = '\n'.join(b for b in src.split('\n') if not b.lstrip().startswith('#'))
cek('tidak ada perintah yang ditolak perangkat',
    not re.search(r"'show vlan'|show interface vlanif|show interface mng", kode))

print()
if gagal:
    print(f'GAGAL: {gagal} pemeriksaan')
    sys.exit(1)
print('SEMUA PEMERIKSAAN LULUS')
