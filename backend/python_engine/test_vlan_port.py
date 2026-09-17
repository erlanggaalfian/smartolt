"""Self-check parser VLAN & interface hasil port dari PHP (#231).

Jalankan: python3 test_vlan_port.py
Tanpa framework, tanpa perangkat nyata: transport di-stub, yang diuji hanya parser
dan validasi — bagian yang paling mudah rusak saat dipindah dari PHP.

Sampel output sengaja memakai CRLF dan penanda sintetis "OLT# show ..." dari
helper.execute_ssh_commands(), karena dua hal itulah yang paling sering
menghancurkan regex hasil salinan.
"""
import os
import sys

sys.path.append(os.path.dirname(os.path.abspath(__file__)))

import helper
from drivers.cdata_fd1602sb1 import OltCdataFd1602sb1Driver
from drivers.zte_c320 import OltZteC320Driver

OLT = {'ip': '10.0.0.1', 'type': 'CDATA FD1602SB1(GPON)', 'protocol': 'TELNET'}
ZTE = {'ip': '10.0.0.2', 'type': 'ZTE C320', 'protocol': 'TELNET'}

# --- 1. Penolong bersama -----------------------------------------------------
assert helper.strip_command_markers("OLT# show vlan all\nVLAN ID : 1\n") == "\nVLAN ID : 1\n"
assert helper.detect_transport_error("") is not None
assert helper.detect_transport_error("Error connecting to OLT: timed out") is not None
assert helper.detect_transport_error("VLAN ID : 1") is None

# --- 2. getVlans CData -------------------------------------------------------
VLAN_RAW = (
    "\r\nOLT# show vlan all\r\n"
    "VLAN ID : 1\r\n"
    "VLAN Description : default\r\n"
    "VLAN Type : Normal vlan\r\n"
    "Tagged Ports : ge 0/0/1\r\n"
    "Untagged Ports : none\r\n"
    "VLAN ID : 25\r\n"
    "VLAN Description : PPPOE\r\n"
    "VLAN Type : Normal vlan\r\n"
    "Tagged Ports : ge 0/0/1 ge 0/0/2\r\n"
    "Untagged Ports : none\r\n"
    "VLAN ID : 24\r\n"
    "VLAN Description : Management\r\n"
    "VLAN Type : L3intf vlan\r\n"
    "Tagged Ports : none\r\n"
    "Untagged Ports : none\r\n"
    "\r\nOLT# show interface vlanif\r\n"
    " Vlanif24 current state : UP\r\n"
    " inet 192.168.1.1/24\r\n"
)

cdata = OltCdataFd1602sb1Driver()
helper_orig = helper.execute_ssh_commands


def stub(output):
    """Ganti transport dengan keluaran tetap; kembalikan daftar perintah terkirim."""
    sent = []

    def fake(olt, commands):
        sent.append(list(commands))
        return output(commands) if callable(output) else output

    helper.execute_ssh_commands = fake
    import drivers.cdata_fd1602sb1 as cd
    import drivers.zte_c320 as zt
    cd.execute_ssh_commands = fake
    zt.execute_ssh_commands = fake
    return sent


stub(VLAN_RAW)
res = cdata.get_vlans(OLT)
assert res['success'], res
ids = [v['id'] for v in res['vlans']]
assert ids == [1, 24, 25], f"urutan/ID salah: {ids}"

by_id = {v['id']: v for v in res['vlans']}
assert by_id[1]['description'] == 'default', by_id[1]
assert by_id[25]['tagged'] == 'ge 0/0/1 ge 0/0/2', by_id[25]
# "none" harus jadi string kosong, dan penanda "OLT# show ..." TIDAK boleh
# ikut tersedot ke untagged (regex-nya mengambil sampai akhir blok).
assert by_id[25]['untagged'] == '', repr(by_id[25]['untagged'])
assert 'OLT#' not in by_id[24]['untagged'], repr(by_id[24]['untagged'])
# VLAN 24 punya vlanif -> layer 3 -> terproteksi + IP terbaca.
assert by_id[24]['is_l3'] is True
assert by_id[24]['ip'] == '192.168.1.1/24', by_id[24]
assert by_id[24]['protected'] is True
assert by_id[1]['protected'] is True   # VLAN 1 selalu terproteksi
assert by_id[25]['protected'] is False

# --- 3. Validasi addVlan / deleteVlan ---------------------------------------
assert cdata.add_vlan(OLT, 0)['success'] is False
assert cdata.add_vlan(OLT, 4095)['success'] is False
assert cdata.add_vlan(OLT, 25)['success'] is False          # sudah ada
assert cdata.delete_vlan(OLT, 1)['success'] is False        # VLAN default
assert cdata.delete_vlan(OLT, 24)['success'] is False       # VLAN L3
assert cdata.delete_vlan(OLT, 999)['success'] is False      # tidak ada

# Sanitasi deskripsi: blokir baris baru (command injection).
assert cdata._sanitize_vlan_description("abc\nreboot") == "abcreboot"
assert cdata._sanitize_vlan_description("a" * 50) == "a" * 32
assert cdata._sanitize_vlan_description("hi  there") == "hi there"

# Uraian daftar VLAN.
assert cdata._parse_vlan_list("1,11-13,100") == {1, 11, 12, 13, 100}
assert cdata._parse_vlan_list("") == set()

# --- 4. deleteVlan: wajib konfirmasi bila dipakai pelanggan -----------------
CFG_IN_USE = (
    "interface gpon 0/0\r\n"
    "ont ipconfig 1 1 ip-index 0 pppoe username u password p vlan 25 priority 0\r\n"
    "exit\r\n"
)
stub(CFG_IN_USE)
res = cdata.delete_vlan(dict(OLT), 25)
# get_vlans dipanggil ulang di dalam delete_vlan dan kini membaca CFG_IN_USE
# (bukan VLAN_RAW), jadi VLAN 25 tak ditemukan -> ditolak lebih awal. Itu sah:
# yang penting TIDAK ada perintah 'no vlan' yang lolos tanpa verifikasi.
assert res['success'] is False


# Uji needs_confirm dengan urutan balasan yang realistis:
# panggilan 1 = show vlan (VLAN 25 ada), panggilan 2 = show current-config (dipakai ONT).
class Seq:
    def __init__(self, *outs):
        self.outs = list(outs)
        self.i = 0

    def __call__(self, commands):
        out = self.outs[min(self.i, len(self.outs) - 1)]
        self.i += 1
        return out


stub(Seq(VLAN_RAW, CFG_IN_USE))
res = cdata.delete_vlan(dict(OLT), 25, confirmed=False)
assert res['success'] is False, res
assert res.get('needs_confirm') is True, res

# --- 5. getInterfaces CData -------------------------------------------------
PORT_RAW = (
    "\r\nOLT# show port state all\r\n"
    "ge 0/0/1   absence  1     enable   1000        1000   full  on     enable   enable   on    1526   Copper \r\n"
    "ge 0/0/2   absence  25    disable  1000        1000   full  on     enable   enable   down  1526   Copper \r\n"
    "\r\nOLT# show this\r\n"
    "show ge current-config\r\n"
    " vlan mode 1 trunk\r\n"
    " vlan trunk 1 1,24-25,100\r\n"
    " vlan mode 2 access\r\n"
    " vlan access 2 25\r\n"
)
stub(PORT_RAW)
res = cdata.get_interfaces(OLT)
assert res['success'], res
ports = {p['name']: p for p in res['ports']}
assert set(ports) == {'ge 0/0/1', 'ge 0/0/2'}, list(ports)
# Baris berakhir spasi + CRLF: pola akhir wajib [ \t\r]*$ (pelajaran bug CRLF #3).
assert ports['ge 0/0/1']['media'] == 'Copper', ports['ge 0/0/1']
assert ports['ge 0/0/1']['pvid'] == 1
assert ports['ge 0/0/1']['frame_max'] == 1526
assert ports['ge 0/0/1']['link'] == 'on'
assert ports['ge 0/0/2']['link'] == 'down'
assert ports['ge 0/0/1']['vlan_mode'] == 'trunk', ports['ge 0/0/1']
assert ports['ge 0/0/1']['vlan_list'] == '1,24-25,100', ports['ge 0/0/1']
assert ports['ge 0/0/2']['vlan_mode'] == 'access'

# --- 6. Validasi configPort / configPortVlan --------------------------------
assert cdata.config_port(OLT, 'ge 9/9/9', 'enable', '', '')['success'] is False
assert cdata.config_port(OLT, 'ge 0/0/99', 'enable', '', '')['success'] is False

stub(VLAN_RAW)
assert cdata.config_port_vlan(OLT, 'ge 0/0/1', 'bogus')['success'] is False
# VLAN 1 tidak boleh dikeluarkan dari trunk.
r = cdata.config_port_vlan(OLT, 'ge 0/0/1', 'trunk', tagged='25')
assert r['success'] is False and 'VLAN 1' in r['message'], r
# VLAN yang belum ada di perangkat harus ditolak.
r = cdata.config_port_vlan(OLT, 'ge 0/0/1', 'trunk', tagged='1,999')
assert r['success'] is False and '999' in r['message'], r
# Mode access wajib punya VLAN untagged.
r = cdata.config_port_vlan(OLT, 'ge 0/0/1', 'access')
assert r['success'] is False, r
# Mode trunk wajib punya minimal 1 VLAN tagged.
r = cdata.config_port_vlan(OLT, 'ge 0/0/1', 'trunk')
assert r['success'] is False, r

# Perintah yang dibentuk harus benar, dan gagal-verifikasi harus dilaporkan gagal.
sent = stub(Seq(VLAN_RAW, 'ok', PORT_RAW))
r = cdata.config_port_vlan(OLT, 'ge 0/0/1', 'trunk', tagged='1,24-25')
assert r['success'] is True, r          # PORT_RAW memang menunjukkan mode trunk
write_cmds = sent[1]
assert 'vlan mode 1 trunk' in write_cmds, write_cmds
assert 'vlan trunk 1 1,24,25' in write_cmds, write_cmds
assert write_cmds[-1] == 'write', write_cmds

sent = stub(Seq(VLAN_RAW, 'ok', 'show ge current-config\r\n vlan mode 1 access\r\n'))
r = cdata.config_port_vlan(OLT, 'ge 0/0/1', 'trunk', tagged='1,24')
assert r['success'] is False, r         # mode terbaca 'access', bukan 'trunk'

# --- 7. ZTE -----------------------------------------------------------------
# Keluaran ASLI perangkat (#239). 'show vlan' polos & 'show interface vlanif'
# DITOLAK C320, jadi daftar VLAN hanya bisa lewat 'show vlan summary'.
ZTE_SUMMARY = (
    "\r\nOLT# show vlan summary\r\n"
    "All created vlan num: 2\r\n"
    "Details are following:\r\n"
    "    1,100\r\n"
)
ZTE_DETAIL = (
    "\r\nOLT# show vlan 1\r\n"
    "vlanid          :1\r\nname            :VLAN0001\r\ndescription     :N/A\r\n"
    "\r\nOLT# show vlan 100\r\n"
    "vlanid          :100\r\nname            :VLAN0100\r\ndescription     :MGMT\r\n"
    "interface vlan 100\r\n"
)
ZTE_PORTSTAT = (
    "\r\nOLT# show interface port-status gei_1/3/1\r\n"
    "     Port      hybrid  Native Negotiation  Speed  Duplex Flow-   Admin      Link\r\n"
    "gei_1/3/1      optical  1       enable     auto    full  disable activate   down\r\n"
)
ZTE_CARD = (
    "\r\nOLT# show card\r\n"
    "Rack Shelf Slot CfgType     RealType    Port HardVer   SoftVer   Status\r\n"
    "1    1     3    SMXA        SMXA        3    V1.0.0    V2.1.0    INSERVICE\r\n"
)
ZTE_VLANPORT = (
    "\r\nOLT# show vlan port gei_1/3/1\r\n"
    "PortMode      Pvid  CPvid Tpid/mode   TLSStatus TLSVlan  ProtEn   PrioEn\r\n"
    "hybrid>=0     1     0     0x8100/PORT disable   0        disable  disable\r\n"
    "UntaggedVlan:\r\n1\r\nTaggedVlan:\r\n"
)

zte = OltZteC320Driver()
# Urutan panggilan get_vlans: summary -> detail -> (get_interfaces: card,
# port-status) -> vlan port.
stub(Seq(ZTE_SUMMARY, ZTE_DETAIL, ZTE_CARD, ZTE_PORTSTAT, ZTE_VLANPORT))
res = zte.get_vlans(ZTE)
assert res['success'], res
zids = {v['id']: v for v in res['vlans']}
assert set(zids) == {1, 100}, list(zids)
assert zids[1]['description'] == 'VLAN0001', zids[1]   # 'N/A' jatuh ke name
assert zids[100]['description'] == 'MGMT', zids[100]
assert zids[100]['is_l3'] is True, zids[100]
assert zids[1]['protected'] is True
# Kolom Tagged/Untagged berisi PORT UPLINK, bukan ONU.
assert zids[1]['untagged'] == 'gei_1/3/1', zids[1]
assert zids[1]['tagged'] == '', zids[1]

# get_interfaces ZTE membaca status port uplink dari 'show interface port-status'.
stub(Seq(ZTE_CARD, ZTE_PORTSTAT))
res = zte.get_interfaces(ZTE)
assert res['success'], res
p = res['ports'][0]
assert p['name'] == 'gei_1/3/1' and p['link'] == 'off', p
# Speed 'auto' TIDAK boleh jadi nilai default form force-speed.
assert p['config_speed'] == '', p

# config_port ZTE menolak masukan tak sah tanpa menyentuh perangkat.
assert zte.config_port(ZTE, 'ge 0/0/1', 'enable', '', '')['success'] is False
assert zte.config_port(ZTE, 'gei_1/3/1', 'disable', '10000', 'full')['success'] is False

# config_port_vlan belum didukung ZTE: harus success=False dengan pesan jelas,
# BUKAN AttributeError.
out = zte.config_port_vlan(ZTE, 'ge 0/0/1', 'trunk')
assert out['success'] is False and 'belum didukung' in out['message'], out

# --- 7b. Penulisan VLAN ZTE (#241) ------------------------------------------
# Validasi masukan harus menolak SEBELUM menyentuh perangkat.
assert zte.save_vlan(ZTE, 0)['success'] is False
assert zte.save_vlan(ZTE, 4095)['success'] is False
assert zte.delete_vlan(ZTE, 1)['success'] is False          # VLAN default sistem
assert zte.save_vlan_ports(ZTE, 15, '', {'ge 0/0/1': 'tagged'})['success'] is False
assert zte.save_vlan_ports(ZTE, 15, '', {'gei_1/3/1': 'trunk'})['success'] is False

# Respons wajar OLT untuk blok konfigurasi + write (bukan string kosong: string
# kosong ditafsirkan detect_transport_error sebagai koneksi putus).
ZTE_OK = "\r\nOLT# configure terminal\r\nWriting configuration into flash...[OK]\r\n"

# Port yang sudah sesuai tidak boleh menghasilkan perintah apa pun.
# gei_1/3/1 sudah untagged VLAN 1 dengan pvid 1.
stub(Seq(ZTE_OK, ZTE_CARD, ZTE_PORTSTAT, ZTE_VLANPORT))
r = zte.save_vlan_ports(ZTE, 1, '', {'gei_1/3/1': 'untagged'})
assert r['success'] and 'Tidak ada perubahan port' in r['message'], r

# VLAN 1 tidak boleh dilepas dari port (VLAN default sistem).
stub(Seq(ZTE_OK, ZTE_CARD, ZTE_PORTSTAT, ZTE_VLANPORT))
r = zte.save_vlan_ports(ZTE, 1, '', {'gei_1/3/1': 'none'})
assert r['success'] and 'Tidak ada perubahan port' in r['message'], r

# Menjadikan tagged pada port hybrid: hasilnya diverifikasi ulang ke perangkat.
ZTE_VP_TAG15 = ZTE_VLANPORT.replace('TaggedVlan:\r\n', 'TaggedVlan:\r\n15\r\n')
stub(Seq(ZTE_OK, ZTE_CARD, ZTE_PORTSTAT, ZTE_VLANPORT, ZTE_OK, ZTE_VP_TAG15))
r = zte.save_vlan_ports(ZTE, 15, '', {'gei_1/3/1': 'tagged'})
assert r['success'], r
assert 'interface gei_1/3/1' in r['commands'], r['commands']
assert 'switchport vlan 15 tag' in r['commands'], r['commands']
assert 'write' in r['commands'], r['commands']

# Perangkat diam-diam tidak menerapkan perubahan -> WAJIB dilaporkan gagal.
stub(Seq(ZTE_OK, ZTE_CARD, ZTE_PORTSTAT, ZTE_VLANPORT, ZTE_OK, ZTE_VLANPORT))
r = zte.save_vlan_ports(ZTE, 15, '', {'gei_1/3/1': 'tagged'})
assert r['success'] is False and 'tidak menerapkan' in r['message'], r

# Penolakan '%Error' dari OLT tidak boleh dilaporkan sebagai sukses.
stub(Seq(ZTE_OK, ZTE_CARD, ZTE_PORTSTAT, ZTE_VLANPORT,
         '%Error 20202: Invalid parameter\r\n'))
r = zte.save_vlan_ports(ZTE, 15, '', {'gei_1/3/1': 'tagged'})
assert r['success'] is False and 'ditolak OLT' in r['message'], r

# --- 8. Panel (#232) --------------------------------------------------------
# Peta panel harus dict {kunci: {label, icon}} — frontend memvalidasi lewat
# isset($supported[$panel]) di get-olt-panel.php, jadi list akan selalu gagal.
panels = cdata.get_supported_panels()
assert isinstance(panels, dict), panels
assert set(panels) == {'olt-details', 'olt-cards', 'pon-ports', 'interfaces'}, list(panels)
assert all({'label', 'icon'} <= set(v) for v in panels.values()), panels
# ZTE kini punya 4 panel (#239). Panel yang TIDAK terdaftar harus dijawab
# success=False + sections kosong (bukan exception, bukan fallback ke PHP).
zpanels = zte.get_supported_panels()
assert isinstance(zpanels, dict), zpanels
assert set(zpanels) == {'olt-details', 'olt-cards', 'pon-ports', 'interfaces'}, list(zpanels)
rz = zte.get_panel_data(ZTE, 'panel-halu')
assert rz['success'] is False and rz['sections'] == [], rz

# Panel tak dikenal harus ditolak, bukan melempar exception.
stub('')
r = cdata.get_panel_data(OLT, 'panel-halu')
assert r['success'] is False and r['sections'] == [], r

DETAILS_RAW = (
    "\r\nOLT# show version\r\n"
    "Software version V3.2.20\r\n"
    "Hardware version : V1.1\r\n"
    "984M  bytes SDRAM\r\n"
    "254M  bytes FLASH\r\n"
    "\r\nOLT# show device\r\n"
    "Device type : GPON OLT\r\n"
    "Device vendor name : C-Data\r\n"
    "Device serial-number : DA27-0001\r\n"
    "Device MAC address : E0:67:B3:11:22:33\r\n"
    "Slot  Model          Status          OnlineTime            OfflineTime\r\n"
    "0     FD1602S-B1     Normal      2026-08-12 22:23:37       --\r\n"
    "\r\nOLT# show cpu\r\n"
    "Load Average(5sec)  :  4.51%\r\n"
    "\r\nOLT# show memory\r\n"
    "Utilization    : 25.00%\r\n"
    "\r\nOLT# show temperature\r\n"
    "  0     43.50\r\n"
    "\r\nOLT# show uptime\r\n"
    "System running time : 2 weeks, 0 day 0 hour 44 minute 29 second.\r\n"
    "\r\nOLT# show fan\r\n"
    "  FAN[1] status: Normal     (10500RPM)\r\n"
    "  FAN[2] status: Abnormal     (0RPM)\r\n"
    "Fan speed mode : auto\r\n"
)
stub(DETAILS_RAW)
r = cdata.get_panel_data(OLT, 'olt-details')
assert r['success'], r
kinds = [s['type'] for s in r['sections']]
assert kinds == ['metrics', 'table', 'cards'], kinds

met = {i['label']: i for i in r['sections'][0]['items']}
assert met['CPU Load']['value'] == '4.51%', met['CPU Load']
assert met['CPU Load']['state'] == 'good', met['CPU Load']       # 4.51 < 70
assert met['RAM Usage']['value'] == '25.00%', met['RAM Usage']
assert met['Suhu Board']['value'] == '43.50', met['Suhu Board']
assert met['Suhu Board']['unit'] == '°C'
assert met['Uptime']['value'].startswith('2 weeks'), met['Uptime']

info = dict(r['sections'][1]['rows'])
assert info['Device Type'] == 'GPON OLT', info
assert info['Vendor'] == 'C-Data', info
assert info['Model'] == 'FD1602S-B1', info      # dari baris slot, bukan default driver
assert info['Software Version'].startswith('V3.2.20'), info
assert info['SDRAM'] == '984M' and info['Flash'] == '254M', info
assert info['Firmware Version'] == 'N/A', info  # tidak ada di output -> N/A, bukan hilang

fans = r['sections'][2]
assert 'Mode: AUTO' in fans['title'], fans['title']
assert fans['items'][0]['state'] == 'good' and fans['items'][0]['value'] == '10,500 RPM'
assert fans['items'][1]['state'] == 'bad', fans['items'][1]

# Ambang batas suhu/CPU.
assert cdata._threshold_state('90%', 70, 85) == 'bad'
assert cdata._threshold_state('75%', 70, 85) == 'warn'
assert cdata._threshold_state(None, 70, 85) == 'neutral'
assert cdata._threshold_state('N/A', 70, 85) == 'neutral'

CARDS_RAW = (
    "\r\nOLT# show device\r\n"
    "Slot  Model          Status          OnlineTime            OfflineTime\r\n"
    "0     FD1602S-B1     Normal      2026-08-12 22:23:37       --\r\n"
    "\r\nOLT# show ont status-count\r\n"
    "Active   : 12\r\n"
    "Offline  : 3\r\n"
    "Deactive : 0\r\n"
    "Config Success : 12\r\n"
    "Mib Ready : 12\r\n"
)
stub(CARDS_RAW)
r = cdata.get_panel_data(OLT, 'olt-cards')
assert r['success'], r
card = r['sections'][0]['items'][0]
assert card['title'] == 'Slot 0' and card['value'] == 'FD1602S-B1', card
assert card['state'] == 'good' and 'Online sejak' in card['subtitle'], card
m2 = {i['label']: i for i in r['sections'][1]['items']}
assert m2['ONT Active']['value'] == '12', m2
assert m2['ONT Offline']['value'] == '3' and m2['ONT Offline']['state'] == 'bad', m2
assert m2['ONT Deactive']['state'] == 'neutral', m2

# Tanpa data slot, harus tetap ada kartu fallback (bukan panel kosong).
stub("\r\nOLT# show ont status-count\r\nActive : 0\r\n")
r = cdata.get_panel_data(OLT, 'olt-cards')
assert r['sections'][0]['items'][0]['title'] == 'Slot 0 (Main Board)', r['sections'][0]

# pon-ports memakai pull_configured_onus (parser 'show ont info all' yang sudah ada).
PON_RAW = (
    "\r\nOLT# show ont info all\r\n"
    "0  1  1   ZTEGC0B3458A  online   ok  x  Budi\r\n"
    "0  1  2   ZTEGC85ADC5F  offline  ok  x  Ani\r\n"
    "0  2  1   ZTEGC1234567  online   ok  x  Cici\r\n"
)
stub(PON_RAW)
r = cdata.get_panel_data(OLT, 'pon-ports')
assert r['success'], r
pon_rows = {row[0]: row for row in r['sections'][1]['rows']}
assert pon_rows['0/1'][1:] == ['2', '1', '1', '50%'], pon_rows['0/1']
assert pon_rows['0/2'][1:] == ['1', '1', '0', '100%'], pon_rows['0/2']
cards_by_title = {c['title']: c for c in r['sections'][0]['items']}
assert cards_by_title['PON 0/2']['state'] == 'good', cards_by_title['PON 0/2']
assert cards_by_title['PON 0/1']['state'] == 'bad', cards_by_title['PON 0/1']  # 50% < 70

# Tanpa ONT sama sekali -> sukses dengan pesan, bukan error.
stub("\r\nOLT# show ont info all\r\n")
r = cdata.get_panel_data(OLT, 'pon-ports')
assert r['success'] is True and r['sections'] == [], r

# Panel interfaces: mgmt + ringkasan + tabel port beserta action edit.
MGMT_RAW = (
    "\r\nOLT# show interface mgmt\r\n"
    "Mgmt 0/0 current state : UP\r\n"
    "Line protocol current state : UP\r\n"
    "inet 192.168.100.1/24\r\n"
    "Hardware Address is E0:67:B3:11:22:33\r\n"
)
stub(Seq(MGMT_RAW, PORT_RAW))
r = cdata.get_panel_data(OLT, 'interfaces')
assert r['success'], r
assert r['sections'][0]['title'] == 'Management Interface', r['sections'][0]
mgmt_item = r['sections'][0]['items'][0]
assert mgmt_item['value'] == '192.168.100.1/24' and mgmt_item['state'] == 'good', mgmt_item
m3 = {i['label']: i['value'] for i in r['sections'][1]['items']}
assert m3 == {'Total Port': '2', 'Link Up': '1', 'Link Down': '1'}, m3
tbl = r['sections'][2]
assert tbl['rows'][0][:2] == ['ge 0/0/1', 'UP'], tbl['rows'][0]
assert tbl['rows'][1][1] == 'DOWN', tbl['rows'][1]
assert tbl['action']['event'] == 'port-config', tbl['action']
assert tbl['action']['args'][0]['port'] == 'ge 0/0/1', tbl['action']['args'][0]

# Mgmt gagal terbaca -> section-nya dilewati, panel tetap jalan.
stub(Seq('', PORT_RAW))
r = cdata.get_panel_data(OLT, 'interfaces')
assert r['success'], r
assert all(s['title'] != 'Management Interface' for s in r['sections']), r['sections']

# --- 9. save_vlan_ports (#232) ----------------------------------------------
# Logika ini dipindah dari frontend/action/vlan.php yang dulu menyusun perintah
# CLI vendor langsung di frontend.
assert cdata.save_vlan(OLT, 0)['success'] is False           # di luar rentang
stub('ok')
assert cdata.save_vlan(OLT, 25, 'PPPOE')['success'] is True  # idempoten, VLAN ada pun boleh

# Tanpa ports_config: cukup simpan VLAN, tidak menyentuh port.
sent = stub('ok')
r = cdata.save_vlan_ports(OLT, 25, 'PPPOE')
assert r['success'] is True and len(sent) == 1, (r, sent)

# ge 0/0/2 saat ini access pvid 25 -> minta 'tagged' harus jadi trunk,
# pvid dikembalikan ke 1, dan VLAN 1 wajib ikut masuk daftar trunk.
sent = stub(Seq('ok', PORT_RAW, 'ok'))
r = cdata.save_vlan_ports(OLT, 25, 'PPPOE', {'ge 0/0/2': 'tagged'})
assert r['success'] is True, r
port_cmds = sent[2]
assert 'interface ge 0/0' in port_cmds, port_cmds
assert 'vlan mode 2 trunk' in port_cmds, port_cmds
assert 'vlan trunk 2 1,25' in port_cmds, port_cmds   # VLAN 1 otomatis ditambahkan
assert port_cmds[-1] == 'write', port_cmds

# Minta 'untagged' pada port trunk -> jadi hybrid, pvid = vlan target.
sent = stub(Seq('ok', PORT_RAW, 'ok'))
r = cdata.save_vlan_ports(OLT, 100, '', {'ge 0/0/1': 'untagged'})
assert r['success'] is True, r
port_cmds = sent[2]
assert 'vlan mode 1 hybrid' in port_cmds, port_cmds
assert 'vlan hybrid 1 untagged 100' in port_cmds, port_cmds
assert 'vlan native-vlan 1 100' in port_cmds, port_cmds

# Port yang sudah sesuai -> tanpa perubahan, tidak ada perintah tulis port.
sent = stub(Seq('ok', PORT_RAW))
r = cdata.save_vlan_ports(OLT, 25, '', {'ge 0/0/2': 'untagged'})
assert r['success'] is True and 'Tidak ada perubahan port' in r['message'], r
assert len(sent) == 2, sent

# Penolakan OLT harus dilaporkan gagal. Versi PHP lama di vlan.php selalu
# melaporkan sukses tanpa memeriksa keluaran perangkat.
stub(Seq('ok', PORT_RAW, '% Invalid input detected'))
r = cdata.save_vlan_ports(OLT, 25, '', {'ge 0/0/2': 'tagged'})
assert r['success'] is False and 'ditolak OLT' in r['message'], r

helper.execute_ssh_commands = helper_orig
print("test_vlan_port.py: semua pemeriksaan LULUS")
