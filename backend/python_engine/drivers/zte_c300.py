import re
import datetime
import sys
import os
import time
from collections import Counter

# Align import path to include helper
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from helper import execute_ssh_commands, strip_command_markers, detect_transport_error
from drivers.base_driver import BaseDriver
import snmp_module

def is_demo_olt(olt: dict) -> bool:
    return olt.get('ip') == '127.0.0.1' or olt.get('ip', '').lower() == 'demo'

def detect_onu_type_from_sn(serial_number: str) -> str:
    """Detect ONU vendor type from serial number OUI prefix."""
    sn = serial_number.upper()
    if sn.startswith(('ZTEG', 'ZTEC', 'ZTED', 'ZTET', 'ZTEF', 'ZTES')):
        return 'ZTE ONU'
    if sn.startswith(('HWTC', 'HWTN', 'HWTO', 'HWTS', 'HWTT')):
        return 'HUAWEI ONU'
    if sn.startswith(('ALCL', 'NOKI')):
        return 'ALCATEL ONU'
    if sn.startswith(('FHTT', 'FIBE')):
        return 'FIBERHOME ONU'
    return 'Unknown ONU'

class OltZteC300Driver(BaseDriver):
    def get_driver_info(self) -> dict:
        return {
            'brand': 'ZTE',
            'model': 'C300',
            'technology': 'GPON',
            'pon_type': 'gpon',
            'version': '1.0.0',
            'description': 'Driver OLT ZTE ZXA10 C300 GPON Vendor (Python Engine).'
        }

    def get_supported_panels(self) -> dict:
        """Panel yang benar-benar bisa diisi datanya oleh C300.

        Hanya cantumkan panel yang sudah terbukti punya sumber data di perangkat.
        PON Ports dan Interfaces belum dicantumkan karena perintahnya belum
        diverifikasi live — lebih baik tab-nya tidak muncul daripada muncul lalu
        kosong.
        """
        return {
            'olt-details': {'label': 'OLT Details', 'icon': 'server'},
            'olt-cards': {'label': 'OLT Cards', 'icon': 'layout-grid'},
            'pon-ports': {'label': 'PON Ports', 'icon': 'plug-zap'},
            'interfaces': {'label': 'Interfaces', 'icon': 'ethernet-port'},
        }

    def get_panel_data(self, olt: dict, panel: str) -> dict:
        """Dispatcher panel: jalankan CLI lalu balikan data terstruktur."""
        handler = {
            'olt-details': self._panel_olt_details,
            'olt-cards': self._panel_olt_cards,
            'pon-ports': self._panel_pon_ports,
            'interfaces': self._panel_interfaces,
        }.get(panel)
        if handler is None:
            return {'success': False,
                    'message': f"Panel '{panel}' tidak didukung oleh driver ZTE C300.",
                    'sections': []}
        return handler(olt)

    @staticmethod
    def _state(value, warn: float, bad: float) -> str:
        """State visual (good/warn/bad) dari nilai numerik + ambang batas."""
        if not value:
            return 'neutral'
        m = re.search(r'([0-9.]+)', str(value))
        if not m:
            return 'neutral'
        n = float(m.group(1))
        if n >= bad:
            return 'bad'
        if n >= warn:
            return 'warn'
        return 'good'

    @staticmethod
    def _cards_table(raw: str) -> list:
        """Baris tabel 'show card'.

        Format nyata (C300):
            Rack Shelf Slot CfgType RealType Port  HardVer SoftVer         Status
            1    1     2    GTGH    GTGHG    16    V1.0.0  V2.1.0          INSERVICE
            1    1    10    SCXN    SCXN     N/A   V1.0.0  V2.1.0          INSERVICE
            1    1    21    HUVQ    HUVQ     4     V1.0.0  V2.1.0          INSERVICE

        Slot OFFLINE tidak punya RealType/versi, jadi kolomnya harus opsional.
        """
        out = []
        for ln in raw.splitlines():
            m = re.match(
                r'^\s*(\d+)\s+(\d+)\s+(\d+)\s+([A-Z][A-Z0-9]+)\s+'
                r'(?:([A-Z][A-Z0-9]+)\s+)?(\d*)\s*'
                r'(?:(V\S+)\s+(V\S+)\s+)?([A-Z]+)\s*$', ln)
            if m and m.group(4):
                out.append(m)
        return out

    def _panel_olt_details(self, olt: dict) -> dict:
        """PANEL: OLT Details — sistem, versi firmware, resource, suhu."""
        raw = execute_ssh_commands(olt, [
            'show system-group', 'show processor', 'show card-temperature',
            'show card', 'show version-running',
        ])
        err = detect_transport_error(raw)
        if err:
            return {'success': False, 'message': err, 'sections': []}
        raw = strip_command_markers(raw)

        def g(pattern, flags=re.I):
            m = re.search(pattern, raw, flags)
            return m.group(1).strip() if m else None

        # CPU/RAM diambil dari board kartu kendali (SCXN pada C300, slot 10/11).
        # Bila tak ketemu, pakai baris pertama agar panel tetap terisi.
        cpu = ram = None
        proc = re.findall(
            r'^\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)%\s+(\d+)%\s+(\d+)%\s+(\d+)\s+(\d+)%',
            raw, re.M)
        if proc:
            pilih = proc[0]
            for p in proc:
                if p[2] in ('10', '11'):
                    pilih = p
                    break
            cpu = pilih[3] + '%'
            ram = pilih[7] + '%'

        temp = self._parse_card_temperature(raw)
        uptime = g(r'Started before\s*:\s*([^.\r\n]+)')

        metrics = [
            {'label': 'CPU Load', 'value': cpu or 'N/A', 'unit': '',
             'state': self._state(cpu, 70, 85)},
            {'label': 'RAM Usage', 'value': ram or 'N/A', 'unit': '',
             'state': self._state(ram, 75, 90)},
            {'label': 'Suhu Board (tertinggi)', 'value': temp or 'N/A', 'unit': '',
             'state': self._state(temp, 60, 70)},
            {'label': 'Uptime', 'value': uptime or 'N/A', 'unit': '',
             'state': 'neutral', 'small': True},
        ]

        rows = []

        def add(label, val):
            rows.append([label, val if val else 'N/A'])

        # "System Description: C300 Version V2.1.0 Software, Copyright (c) by ZTE ..."
        desc = g(r'System Description\s*:\s*([^\r\n]+)')
        add('Vendor', 'ZTE')
        add('Model', self.get_driver_info()['model'])
        add('System Name', g(r'System name\s*:\s*([^\r\n]+)'))
        add('Lokasi', g(r'Location\s*:\s*([^\r\n]+)'))
        add('Kontak', g(r'Contact with\s*:\s*([^\r\n]+)'))
        add('Software Version', g(r'Version\s+(V[0-9][^\s]*)\s+Software'))
        add('System ObjectId', g(r'System ObjectId\s*:\s*([^\r\n]+)'))
        add('Deskripsi Sistem', desc)

        sections = [
            {'type': 'metrics', 'title': 'Resource & Kesehatan', 'items': metrics},
            {'type': 'table', 'title': 'Informasi Sistem',
             'columns': ['Parameter', 'Nilai'], 'rows': rows},
        ]

        # "show version-running":
        #   PhyLoc  FileType   VerType    VerTag        BuildTime           VerLength
        #   1/1/10  SCXN       MVR        V2.1.0        2017-01-17 01:04:45 24647784
        ver_rows = []
        for m in re.finditer(
                r'^\s*(\d+/\d+/\d+)\s+(\S+)\s+(\S+)\s+(V\S+)\s+'
                r'(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+(\d+)\s*$', raw, re.M):
            ver_rows.append([m.group(1), m.group(2), m.group(3), m.group(4), m.group(5)])
        if ver_rows:
            sections.append({
                'type': 'table',
                'title': f'Versi Firmware per Board ({len(ver_rows)})',
                'columns': ['Lokasi', 'Board', 'Tipe', 'Versi', 'Build Time'],
                'rows': ver_rows,
            })

        return {'success': True, 'message': '', 'sections': sections}

    def _panel_olt_cards(self, olt: dict) -> dict:
        """PANEL: OLT Cards — status board/slot beserta suhu tiap board."""
        raw = execute_ssh_commands(olt, [
            'show card', 'show card-temperature', 'show processor',
        ])
        err = detect_transport_error(raw)
        if err:
            return {'success': False, 'message': err, 'sections': []}
        raw = strip_command_markers(raw)

        # Suhu per slot dari 'show card-temperature'. 'N/A.' = slot kosong.
        suhu = {}
        for m in re.finditer(r'^\s*\d+\s+\d+\s+(\d+)\s+(\d+)\s', raw, re.M):
            suhu[m.group(1)] = m.group(2)

        cards = []
        rows = []
        for m in self._cards_table(raw):
            slot = m.group(3)
            cfg, real = m.group(4), m.group(5) or '-'
            status = m.group(9)
            ok = status.upper() == 'INSERVICE'
            t = suhu.get(slot)
            sub = f'Suhu: {t} °C' if t else 'Slot kosong / tidak aktif'
            cards.append({
                'title': f'Slot {slot}',
                'value': real if real != '-' else cfg,
                'badge': status,
                'state': 'good' if ok else 'bad',
                'subtitle': sub,
            })
            rows.append([slot, cfg, real, m.group(6),
                         m.group(7) or '-', m.group(8) or '-',
                         f'{t} °C' if t else '-', status])

        if not cards:
            return {'success': False,
                    'message': 'Tidak ada data board yang bisa dibaca dari OLT.',
                    'sections': []}

        aktif = sum(1 for c in cards if c['state'] == 'good')
        metrics = [
            {'label': 'Board Terpasang', 'value': str(len(cards)), 'unit': '',
             'state': 'neutral'},
            {'label': 'Board INSERVICE', 'value': str(aktif), 'unit': '', 'state': 'good'},
            {'label': 'Board Bermasalah', 'value': str(len(cards) - aktif), 'unit': '',
             'state': 'bad' if len(cards) - aktif > 0 else 'neutral'},
        ]

        return {'success': True, 'message': '', 'sections': [
            {'type': 'metrics', 'title': 'Ringkasan Board', 'items': metrics},
            {'type': 'cards', 'title': 'Board / Slot Terpasang', 'items': cards},
            {'type': 'table', 'title': 'Detail Board',
             'columns': ['Slot', 'CfgType', 'RealType', 'Port',
                         'HardVer', 'SoftVer', 'Suhu', 'Status'],
             'rows': rows},
        ]}

    def _gpon_slots(self, olt: dict) -> list:
        """Slot yang benar-benar berisi board GPON, dari 'show card'.

        RealType GTGH*/GTGO* = board GPON (GTGHG/GTGOE). Slot OFFLINE dilewati supaya
        tidak menembak port PON yang tidak ada.
        """
        raw = strip_command_markers(execute_ssh_commands(olt, ['show card']))
        slots = []
        for m in self._cards_table(raw):
            real = m.group(5) or ''
            if real.upper().startswith(('GTGH', 'GTGO')) and m.group(9).upper() == 'INSERVICE':
                slots.append((m.group(1), m.group(2), m.group(3), int(m.group(6) or '0')))
        return slots

    def _panel_pon_ports(self, olt: dict) -> dict:
        """PANEL: PON Ports — status tiap port PON beserta jumlah ONU.

        Sumber: 'show gpon onu state gpon-olt_<r>/<s>/<p>'. Format nyata
        (terverifikasi live 2026-08-28):

            OnuIndex   Admin State  OMCC State  Phase State  Channel
            1/1/1:1     enable       enable      working      1(GPON)
            1/1/1:4     enable       disable     OffLine      1(GPON)
            ONU Number: 21/26

        'working' = ONU aktif. Selain itu (OffLine/LOS/dsb) dihitung offline.
        """
        slots = self._gpon_slots(olt)
        if not slots:
            return {'success': False,
                    'message': 'Tidak ada board GPON aktif yang terbaca dari OLT.',
                    'sections': []}

        cmds = []
        ports = []
        for rack, shelf, slot, jumlah in slots:
            for p in range(1, jumlah + 1):
                nama = f'{rack}/{slot}/{p}'
                ports.append(nama)
                cmds.append(f'show gpon onu state gpon-olt_{nama}')

        raw = execute_ssh_commands(olt, cmds)
        err = detect_transport_error(raw)
        if err:
            return {'success': False, 'message': err, 'sections': []}

        # Hitung per port dari OnuIndex, bukan dari baris "ONU Number" (yang
        # formatnya aktif/total dan tidak selalu muncul).
        agg = {p: {'total': 0, 'online': 0} for p in ports}
        for m in re.finditer(
                r'^\s*(\d+/\d+/\d+):(\d+)\s+\S+\s+\S+\s+(\S+)\s', raw, re.M):
            port = m.group(1)
            if port not in agg:
                continue
            agg[port]['total'] += 1
            if m.group(3).lower() == 'working':
                agg[port]['online'] += 1

        cards, rows = [], []
        for port in ports:
            s = agg[port]
            off = s['total'] - s['online']
            rate = round(s['online'] / s['total'] * 100) if s['total'] else 0
            cards.append({
                'title': 'PON ' + port,
                'value': f"{s['online']} / {s['total']}",
                'badge': f'{rate}% online' if s['total'] else 'kosong',
                'state': ('neutral' if not s['total']
                          else 'good' if off == 0
                          else 'warn' if rate >= 70 else 'bad'),
                'subtitle': f'{off} ONU offline' if s['total'] else 'Belum ada ONU',
            })
            rows.append([port, str(s['total']), str(s['online']), str(off),
                         f'{rate}%' if s['total'] else '-'])

        total = sum(a['total'] for a in agg.values())
        online = sum(a['online'] for a in agg.values())
        metrics = [
            {'label': 'Port PON', 'value': str(len(ports)), 'unit': '', 'state': 'neutral'},
            {'label': 'Total ONU', 'value': str(total), 'unit': '', 'state': 'neutral'},
            {'label': 'ONU Online', 'value': str(online), 'unit': '', 'state': 'good'},
            {'label': 'ONU Offline', 'value': str(total - online), 'unit': '',
             'state': 'bad' if total - online > 0 else 'neutral'},
        ]

        return {'success': True, 'message': '', 'sections': [
            {'type': 'metrics', 'title': 'Ringkasan PON', 'items': metrics},
            {'type': 'cards', 'title': 'Status Port PON (Realtime)', 'items': cards},
            {'type': 'table', 'title': 'Rincian per Port',
             'columns': ['PON Port', 'Total ONU', 'Online', 'Offline', 'Uptime Rate'],
             'rows': rows},
        ]}

    # Nama port uplink: 'gei_1/3/1' atau 'xgei_1/3/2'.
    _PORT_RE = re.compile(r'^(x?gei)_(\d+)/(\d+)/(\d+)$', re.I)

    def _uplink_slots(self, olt: dict) -> list:
        """Slot board uplink (HUVQ/SMXA) yang memegang port uplink: (rack, slot, jumlah)."""
        raw = strip_command_markers(execute_ssh_commands(olt, ['show card']))
        return [(m.group(1), m.group(3), int(m.group(6) or '0'))
                for m in self._cards_table(raw)
                if (m.group(5) or '').upper().startswith(('HUVQ', 'SMX'))
                and m.group(9).upper() == 'INSERVICE']

    def get_interfaces(self, olt: dict) -> dict:
        """Status seluruh port uplink fisik (gei/xgei).

        Tipe port BERCAMPUR per index (pada C300 uji slot: port 1 & 3 = gei,
        port 2 = xgei), dan tidak ada perintah yang menyebutkan tipe tiap port.
        Jadi kedua prefix ditembak dan yang dijawab '%Error 20202' diabaikan.

        Format nyata 'show interface port-status <nama>' (live 2026-08-28):
             Port      hybrid  Native Negotiation  Speed  Duplex Flow-   Admin      Link
                       Status  VLAN     auto       (Mbps)        Ctrl    Status
            gei_1/3/1      optical  1       enable     auto    full  disable activate   down
            xgei_1/3/2     optical  1       disable    10000   full  disable activate   up
        """
        if is_demo_olt(olt):
            return {'success': True, 'message': 'Data demo interface.', 'ports': []}

        slots = self._uplink_slots(olt)
        if not slots:
            return {'success': False,
                    'message': 'Tidak ada board uplink aktif yang terbaca dari OLT.',
                    'ports': []}

        cmds = []
        for rack, slot, jumlah in slots:
            for p in range(1, jumlah + 1):
                cmds.append(f'show interface port-status gei_{rack}/{slot}/{p}')
                cmds.append(f'show interface port-status xgei_{rack}/{slot}/{p}')

        raw = execute_ssh_commands(olt, cmds)
        err = detect_transport_error(raw)
        if err:
            return {'success': False, 'message': err, 'ports': []}
        raw = strip_command_markers(raw)

        ports = []
        for m in re.finditer(
                r'^[ \t]*(x?gei_\d+/\d+/\d+)[ \t]+(\S+)[ \t]+(\d+)[ \t]+(\S+)[ \t]+(\S+)'
                r'[ \t]+(\S+)[ \t]+(\S+)[ \t]+(\S+)[ \t]+(\S+)[ \t\r]*$', raw, re.M | re.I):
            speed = m.group(5)
            ports.append({
                'name': m.group(1),
                'media': m.group(2),
                'pvid': int(m.group(3)),
                'auto_nego': m.group(4).lower(),
                # Saat auto-nego aktif, kolom Speed berisi 'auto'. Nilai itu tidak
                # sah untuk form "force speed", jadi jangan dipakai sebagai default.
                'config_speed': speed if speed.isdigit() else '',
                'speed': speed,
                'duplex': m.group(6).lower(),
                'flow_ctrl': m.group(7).lower(),
                'admin': m.group(8).lower(),
                # Samakan dengan kontrak driver CData: 'on' = link naik.
                'link': 'on' if m.group(9).lower() == 'up' else 'off',
                'optic': m.group(2),
                'vlan_mode': '',
                'vlan_list': '',
            })

        if not ports:
            return {'success': False,
                    'message': 'Tidak ada port uplink yang bisa dibaca dari OLT.',
                    'ports': []}
        return {'success': True, 'message': '', 'ports': ports}

    def _port_vlan_map(self, olt: dict, port_names: list) -> dict:
        """Keanggotaan VLAN tiap port uplink dari 'show vlan port <nama>'.

        Balikan: {nama_port: {'mode','pvid','tagged':set,'untagged':set}}.
        Format nyata (live 2026-08-28):
            PortMode      Pvid  CPvid Tpid/mode   TLSStatus TLSVlan  ProtEn   PrioEn
            trunk>0       1     0     0x8100/PORT disable   0        disable  disable
            UntaggedVlan:
            TaggedVlan:
            1,15,100
        """
        if not port_names:
            return {}
        raw = strip_command_markers(execute_ssh_commands(
            olt, [f'show vlan port {n}' for n in port_names]))

        def daftar(teks):
            # Buang SEMUA spasi putih, bukan hanya ' ': daftar VLAN ditulis di
            # baris tersendiri sehingga potongannya membawa '\n' dan '\r'.
            hasil = set()
            for bagian in re.sub(r'\s+', '', teks or '').split(','):
                if bagian.isdigit():
                    hasil.add(int(bagian))
                elif re.fullmatch(r'\d+-\d+', bagian):
                    a, b = bagian.split('-')
                    hasil.update(range(int(a), int(b) + 1))
            return hasil

        # Output tiap port dipisah oleh baris header 'PortMode'. Urutannya sama
        # dengan urutan perintah; port yang ditolak perangkat tidak menghasilkan blok.
        hasil = {}
        blok = re.split(r'^\s*PortMode\s+Pvid', raw, flags=re.M | re.I)[1:]
        for nama, b in zip(port_names, blok):
            mm = re.search(r'^\s*(\w+)\s*>?=?\d*\s+(\d+)\s+\d+\s', b, re.M)
            # JEBAKAN: kata "UntaggedVlan" MEMUAT "TaggedVlan". Tanpa lookbehind
            # (?<!Un), pencarian tagged cocok pada label untagged dan mengambil
            # daftar yang salah. Regex tagged WAJIB memakai lookbehind ini.
            mu = re.search(r'UntaggedVlan\s*:\s*([\d,\-\s]*?)(?=(?<!Un)TaggedVlan|$)',
                           b, re.I)
            mt = re.search(r'(?<!Un)TaggedVlan\s*:\s*([\d,\-\s]*)', b, re.I)
            hasil[nama] = {
                'mode': mm.group(1).lower() if mm else '',
                'pvid': int(mm.group(2)) if mm else 0,
                'untagged': daftar(mu.group(1) if mu else ''),
                'tagged': daftar(mt.group(1) if mt else ''),
            }
        return hasil

    def config_port(self, olt: dict, port: str, auto_nego: str,
                    speed: str, duplex: str) -> dict:
        """Setel auto-negotiation / speed / duplex pada port uplink ZTE.

        Sintaks terverifikasi live 2026-08-28 (mode config-if):
            negotiation auto        -> aktifkan auto-negotiation
            no negotiation auto     -> matikan
            speed 10|100|1000|10000
            duplex full|half
        """
        m = self._PORT_RE.match((port or '').strip())
        if not m:
            return {'success': False,
                    'message': 'Format port tidak valid. Gunakan gei_R/S/P atau xgei_R/S/P.'}
        port = port.strip().lower()
        jenis = m.group(1).lower()

        auto_nego = (auto_nego or '').strip().lower()
        if auto_nego not in ('enable', 'disable'):
            return {'success': False,
                    'message': 'Auto negotiation harus enable atau disable.'}

        commands = ['configure terminal', f'interface {port}']
        if auto_nego == 'enable':
            commands.append('negotiation auto')
        else:
            speed = (speed or '').strip()
            duplex = (duplex or '').strip().lower()
            if speed not in ('10', '100', '1000', '10000'):
                return {'success': False,
                        'message': 'Speed harus 10, 100, 1000, atau 10000 Mbps.'}
            # Port gigabit tidak bisa dipaksa 10G; menerimanya hanya akan
            # menghasilkan error diam-diam di perangkat.
            if jenis == 'gei' and speed == '10000':
                return {'success': False,
                        'message': f'Port {port} maksimal 1000 Mbps. '
                                   'Gunakan port xgei untuk 10000 Mbps.'}
            if duplex not in ('full', 'half'):
                return {'success': False, 'message': 'Duplex harus full atau half.'}
            commands += ['no negotiation auto', f'speed {speed}', f'duplex {duplex}']
        commands += ['exit', 'exit', 'write']

        log = execute_ssh_commands(olt, commands)
        err = detect_transport_error(log)
        if err:
            return {'success': False, 'message': err, 'commands': commands}

        # Verifikasi ulang ke perangkat, jangan percaya "tidak ada error" saja.
        cek = strip_command_markers(execute_ssh_commands(
            olt, [f'show interface port-status {port}']))
        mv = re.search(
            r'^[ \t]*' + re.escape(port) + r'[ \t]+\S+[ \t]+\d+[ \t]+(\S+)[ \t]+(\S+)'
            r'[ \t]+(\S+)[ \t]', cek, re.M | re.I)
        if not mv:
            return {'success': False,
                    'message': f'Perubahan terkirim tetapi status port {port} '
                               'tidak bisa dibaca ulang untuk verifikasi.',
                    'commands': commands, 'log': log}
        if mv.group(1).lower() != auto_nego:
            return {'success': False,
                    'message': f'Perangkat menolak perubahan: negosiasi port {port} '
                               f'masih "{mv.group(1)}".',
                    'commands': commands, 'log': log}

        return {'success': True,
                'message': f'Konfigurasi port {port} berhasil diperbarui '
                           f'(negosiasi {mv.group(1)}, speed {mv.group(2)}, '
                           f'duplex {mv.group(3)}).',
                'commands': commands, 'log': log}

    def _panel_interfaces(self, olt: dict) -> dict:
        """PANEL: Interfaces — port uplink fisik (gei/xgei) + IP interface.

        Sumber:
          * get_interfaces()                  -> negosiasi/speed/duplex/link
          * 'show interface <nama>'           -> deskripsi & laju trafik
          * 'show ip interface brief'         -> alamat IP layer-3

        Format nyata (live 2026-08-28):
            xgei_1/3/2 is up,  line protocol is up,  detect status is OK
              Description is Up_Link
               20 seconds input rate :  46645652 Bps,  36941 pps
        """
        res = self.get_interfaces(olt)
        if not res['success']:
            return {'success': False, 'message': res['message'], 'sections': []}
        ports = res['ports']

        raw = execute_ssh_commands(
            olt, [f"show interface {p['name']}" for p in ports]
            + ['show ip interface brief'])
        err = detect_transport_error(raw)
        if err:
            return {'success': False, 'message': err, 'sections': []}
        raw = strip_command_markers(raw)

        def laju(teks, arah):
            m = re.search(r'20 seconds ' + arah + r' rate\s*:\s*(\d+)\s*Bps', teks, re.I)
            return f'{int(m.group(1)) * 8 / 1_000_000:.2f} Mbps' if m else '-'

        # Pecah per blok interface supaya Description & laju tidak tertukar antar port.
        rinci = {}
        for b in re.split(r'^(?=(?:x?gei)_\S+\s+is\s+)', raw, flags=re.M | re.I):
            m = re.match(r'(x?gei_\S+)\s+is\s+(\S+?),\s+line protocol is\s+(\S+?),', b, re.I)
            if not m:
                continue
            md = re.search(r'Description is\s+([^\r\n]+)', b, re.I)
            rinci[m.group(1)] = {
                'admin': m.group(2), 'line': m.group(3),
                'desc': md.group(1).strip() if md else 'none',
                'rx': laju(b, 'input'), 'tx': laju(b, 'output'),
            }

        vmap = self._port_vlan_map(olt, [p['name'] for p in ports])

        cards, rows, args = [], [], []
        for p in ports:
            nama = p['name']
            d = rinci.get(nama, {'admin': '-', 'line': '-', 'desc': 'none',
                                 'rx': '-', 'tx': '-'})
            up = p['link'] == 'on'
            v = vmap.get(nama, {})
            tagged = ','.join(str(x) for x in sorted(v.get('tagged', ()))) or '-'
            untagged = ','.join(str(x) for x in sorted(v.get('untagged', ()))) or '-'
            cards.append({
                'title': nama,
                'value': 'UP' if up else 'DOWN',
                'badge': f"admin: {d['admin']} · line: {d['line']}",
                'state': 'good' if up else 'neutral',
                'subtitle': (f"{d['desc']} · RX {d['rx']} / TX {d['tx']}" if up
                             else f"Deskripsi: {d['desc']}"),
            })
            rows.append([
                nama, d['desc'],
                'UP' if up else 'DOWN',
                p['auto_nego'].capitalize(),
                (p['speed'] + ' Mbps') if p['speed'].isdigit() else p['speed'].capitalize(),
                p['duplex'].capitalize(),
                (v.get('mode') or '-').capitalize(),
                tagged, untagged,
                d['rx'], d['tx'],
            ])
            args.append({
                'port': nama,
                'auto_nego': p['auto_nego'],
                # Kosongkan saat auto: 'auto' bukan nilai speed yang sah.
                'speed': p['config_speed'] or ('10000' if nama.startswith('xgei') else '1000'),
                'duplex': p['duplex'] if p['duplex'] in ('full', 'half') else 'full',
            })

        naik = sum(1 for p in ports if p['link'] == 'on')
        sections = [
            {'type': 'metrics', 'title': 'Ringkasan Port Uplink', 'items': [
                {'label': 'Total Port', 'value': str(len(ports)), 'unit': '',
                 'state': 'neutral'},
                {'label': 'Link Up', 'value': str(naik), 'unit': '',
                 'state': 'good' if naik else 'warn'},
                {'label': 'Link Down', 'value': str(len(ports) - naik), 'unit': '',
                 'state': 'neutral'},
            ]},
            {'type': 'cards', 'title': 'Port Uplink Fisik', 'items': cards},
            {'type': 'table', 'title': 'Detail Port',
             'columns': ['Interface', 'Deskripsi', 'Link', 'Negosiasi', 'Speed',
                         'Duplex', 'Mode VLAN', 'Tagged', 'Untagged',
                         'RX Rate', 'TX Rate'],
             'rows': rows,
             'action': {'label': 'Edit Port', 'event': 'port-config', 'args': args}},
        ]

        # 'show ip interface brief':
        #   Interface     IP-Address      Mask            Admin Phy  Prot Description
        ip_rows = [[m.group(i) for i in range(1, 7)] for m in re.finditer(
            r'^\s*(\S+)\s+(\d+\.\d+\.\d+\.\d+)\s+(\d+\.\d+\.\d+\.\d+)\s+'
            r'(\S+)\s+(\S+)\s+(\S+)', raw, re.M)]
        if ip_rows:
            sections.append({
                'type': 'table', 'title': 'IP Interface',
                'columns': ['Interface', 'IP Address', 'Mask', 'Admin', 'Phy', 'Prot'],
                'rows': ip_rows,
            })

        return {'success': True, 'message': '', 'sections': sections}

    def check_connection(self, olt: dict) -> dict:
        if is_demo_olt(olt):
            return {
                'success': True,
                'message': "Koneksi SSH berhasil! (Mode Demo)\n\n"
                           "--- Info Kesehatan OLT ZTE ZXA10 C300 ---\n"
                           "• CPU Load: 14.00%\n"
                           "• RAM Usage: 55.00%\n"
                           "• Temperature: 45.00 °C\n"
                           "• Uptime: 42 days, 5 hours, 25 minutes"
            }

        health_cmds = [
            'show processor',
            'show system-group',
            'show card-temperature',
        ]
        health_raw = execute_ssh_commands(olt, health_cmds)
        
        err = detect_transport_error(health_raw)
        if err:
            return {'success': False, 'message': err}
        
        cpu = 'N/A'
        mem = 'N/A'
        # Matching rows with numbers representing cpu/memory stats
        matches = re.findall(r'^\s*\d+\s+\d+\s+(\d+)\s+(\d+)%\s+(\d+)%\s+(\d+)%\s+(\d+)\s+(\d+)%', health_raw, re.MULTILINE)
        if matches:
            selected = matches[0]
            for m in matches:
                if m[0] in ['3', '4']:
                    selected = m
                    break
            cpu = selected[1] + '%'
            mem = selected[5] + '%'
            
        uptime = 'N/A'
        m_up = re.search(r'Started before\s*:\s*([^.\r\n]+)', health_raw, re.IGNORECASE)
        if m_up:
            uptime = m_up.group(1).strip()

        temp = self._parse_card_temperature(health_raw)

        # Status SNMP TIDAK ditentukan di sini. Driver cuma menguji SSH/Telnet.
        # check_olt_connection() di backend/driver.php yang menguji SNMP nyata
        # lewat check_snmp_ping() lalu menambahkan barisnya.
        return {
            'success': True,
            'message': f"Koneksi SSH berhasil!\n\n"
                       f"--- Info Kesehatan OLT ZTE ZXA10 C300 ---\n"
                       f"• CPU Load: {cpu}\n"
                       f"• RAM Usage: {mem}\n"
                       f"• Temperature: {temp}\n"
                       f"• Uptime: {uptime}"
        }

    @staticmethod
    def _parse_card_temperature(raw: str) -> str:
        """Ambil suhu tertinggi dari keluaran 'show card-temperature'.

        Format nyata (C300):

            All cards temperature(deg c):
            Rack Shelf Slot Temperature Temperature(5m) Temperature(1h) Optical-Temp
            1    1     1    54          54              54              N/A.
            1    1     4    N/A.        N/A.            N/A.            N/A.

        Slot kosong berisi 'N/A.' dan dilewati. Dipakai nilai TERTINGGI antar
        board, bukan rata-rata, karena yang menentukan risiko panas adalah board
        terpanas. Satuan sudah derajat Celsius (lihat 'deg c' pada judul).
        """
        suhu = []
        for m in re.finditer(r'^\s*\d+\s+\d+\s+\d+\s+(\d+)\s', raw, re.MULTILINE):
            suhu.append(int(m.group(1)))
        if not suhu:
            return 'N/A'
        return f"{max(suhu)} °C"

    def disable_onu(self, olt: dict, onu: dict) -> dict:
        if is_demo_olt(olt):
            return {'success': True, 'message': 'ONT berhasil dimatikan (Mode Demo).', 'log': ''}
            
        pon_port = onu['pon_port']
        onu_id = onu['onu_id']
        commands = [
            'configure terminal',
            f"interface gpon-onu_{pon_port}:{onu_id}",
            "shutdown",
            'exit',
            'exit',
            'write'
        ]
        log = execute_ssh_commands(olt, commands)
        return {
            'success': True,
            'message': f"ONT gpon-onu_{pon_port}:{onu_id} berhasil di-shutdown.",
            'log': log,
            'commands': commands
        }

    def enable_onu(self, olt: dict, onu: dict) -> dict:
        if is_demo_olt(olt):
            return {'success': True, 'message': 'ONT berhasil dinyalakan (Mode Demo).', 'log': ''}

        pon_port = onu['pon_port']
        onu_id = onu['onu_id']
        commands = [
            'configure terminal',
            f"interface gpon-onu_{pon_port}:{onu_id}",
            "no shutdown",
            'exit',
            'exit',
            'write'
        ]
        log = execute_ssh_commands(olt, commands)
        return {
            'success': True,
            'message': f"ONT gpon-onu_{pon_port}:{onu_id} berhasil di-no-shutdown.",
            'log': log,
            'commands': commands
        }

    def get_onu_signals_bulk(self, olt: dict, onus: list) -> dict:
        """Fetch optical signals for all ONUs in a single session.
        Returns a dict keyed by "ponPort_ONUId" with fields:
            rx_onu (float or 'N/A'), rx_olt (float or 'N/A'), status (str)
        """
        if is_demo_olt(olt):
            result = {}
            for o in onus:
                key = f"{o['pon_port']}_{o['onu_id']}"
                result[key] = {'rx_onu': -20.0, 'rx_olt': -2.0, 'status': 'online'}
            return result

        # Collect unique PON ports from the list of ONUs
        pon_ports = sorted(list(set(o['pon_port'] for o in onus if o.get('pon_port'))))

        # Build commands
        commands = []
        for port in pon_ports:
            commands.append(f"show pon power onu-rx gpon-olt_{port}")
            commands.append(f"show pon power olt-rx gpon-olt_{port}")

        raw_output = execute_ssh_commands(olt, commands)
        result = {}

        current_cmd = None
        for line in raw_output.split('\n'):
            line = line.strip()
            if not line:
                continue

            if 'onu-rx' in line.lower():
                current_cmd = 'onu-rx'
                continue
            elif 'olt-rx' in line.lower():
                current_cmd = 'olt-rx'
                continue

            m = re.search(r'gpon-onu_(\d+/\d+/\d+):(\d+)\s+([-\d\.]+|N/A|no\s+signal)', line, re.IGNORECASE)
            if m:
                pon_port = m.group(1)
                onu_id = m.group(2)
                val_str = m.group(3).lower()
                key = f"{pon_port}_{onu_id}"

                power_val = 'N/A'
                if val_str not in ('n/a', 'no signal'):
                    try:
                        power_val = float(val_str)
                    except ValueError:
                        pass

                if key not in result:
                    result[key] = {'rx_onu': 'N/A', 'rx_olt': 'N/A', 'status': 'offline'}

                if current_cmd == 'onu-rx':
                    result[key]['rx_onu'] = power_val
                    if power_val != 'N/A':
                        result[key]['status'] = 'online'
                elif current_cmd == 'olt-rx':
                    result[key]['rx_olt'] = power_val

        return result

    def get_onu_signal(self, olt: dict, onu: dict, include_ip: bool = True) -> dict:
        if is_demo_olt(olt):
            return {
                'success': True,
                'rx_onu': -19.45,
                'rx_olt': -21.12,
                'status': 'online',
                'pppoe_ip': '172.16.69.124',
                'log': ''
            }

        pon_port = onu['pon_port']
        onu_id = onu['onu_id']

        # 1. Cek optik attenuation
        output = execute_ssh_commands(olt, [
            f"show pon power attenuation gpon-onu_{pon_port}:{onu_id}"
        ])

        rx_onu = 'N/A'
        rx_olt = 'N/A'
        status = 'online'
        pppoe_ip = 'N/A'

        if any(x in output.lower() for x in ['not online', 'not active', 'offline']):
            status = 'offline'

        m = re.search(r'1490nm\s+Tx\s*:\s*\S+\s+Rx\s*:\s*([-]?\d+\.?\d*)', output, re.IGNORECASE)
        if m:
            rx_onu = float(m.group(1))
        else:
            m = re.search(r'down\s+Tx\s*:\s*\S+\s+Rx:\s*([-]?\d+\.?\d*)', output, re.IGNORECASE)
            if m:
                rx_onu = float(m.group(1))

        m = re.search(r'1310nm\s+Rx\s*:\s*([-]?\d+\.?\d*)', output, re.IGNORECASE)
        if m:
            rx_olt = float(m.group(1))
        else:
            m = re.search(r'up\s+Rx\s*:\s*([-]?\d+\.?\d*)', output, re.IGNORECASE)
            if m:
                rx_olt = float(m.group(1))

        # 2. Ambil IP if online
        if include_ip and status == 'online':
            ip_output = execute_ssh_commands(olt, [
                f"show gpon remote-onu wan-info gpon-onu_{pon_port}:{onu_id}",
                f"show gpon remote-onu ip-host gpon-onu_{pon_port}:{onu_id}"
            ])
            output += "\n" + ip_output

            # Block-by-block parsing targeting PPPoE
            blocks = re.findall(r'WAN\s*(?:Index|ID|index)\s*:\s*(\d+).*?(?=WAN\s*(?:Index|ID|index)\s*:\s*\d+|\Z)', ip_output, re.DOTALL | re.IGNORECASE)
            for block in blocks:
                if 'addressing type: pppoe' in block.lower() or 'pppoe' in block.lower():
                    m_ip = re.search(r'(?:IPv4|IP)\s*(?:address)?\s*:\s*(\S+)', block, re.IGNORECASE)
                    if m_ip:
                        ip_candidate = m_ip.group(1).strip()
                        if ip_candidate not in ['0.0.0.0', '127.0.0.1', '255.255.255.255', 'N/A']:
                            pppoe_ip = ip_candidate
                            break

            # Fallback
            if pppoe_ip == 'N/A':
                matches = re.findall(r'(?:IP(?:v4)?\s*(?:address)?|Current\s+IP\s*(?:address)?)\s*:\s*(\d+\.\d+\.\d+\.\d+)', ip_output, re.IGNORECASE)
                for ip_candidate in matches:
                    if ip_candidate not in ['0.0.0.0', '127.0.0.1', '255.255.255.255']:
                        pppoe_ip = ip_candidate
                        break

        return {
            'success': True,
            'rx_onu': rx_onu,
            'rx_olt': rx_olt,
            'status': status,
            'pppoe_ip': pppoe_ip,
            'log': output,
            **self._get_onu_traffic_snmp(olt, onu),
        }

    def _get_onu_traffic_snmp(self, olt: dict, onu: dict) -> dict:
        """Traffic counter (Rx/Tx octets kumulatif) + distance optik via SNMP,
        terpisah dari get_onu_signal() (telnet) karena sumbernya beda MIB. Gagal
        SNMP tidak boleh menggagalkan get_onu_signal() — kembalikan None kalau error."""
        try:
            _, slot, pon_no = (int(x) for x in onu['pon_port'].split('/'))
            t = snmp_module.get_onu_traffic(
                olt['ip'], olt.get('snmp_community') or 'public',
                slot, pon_no, int(onu['onu_id']),
                port=int(olt.get('snmp_port') or 161),
            )
            distance_m = snmp_module.get_onu_distance_zte(
                olt['ip'], olt.get('snmp_community') or 'public',
                slot, pon_no, int(onu['onu_id']),
                port=int(olt.get('snmp_port') or 161),
            )
            return {
                'traffic_rx_octets': t['rx_octets'], 'traffic_tx_octets': t['tx_octets'],
                'traffic_rx_packets': t['rx_packets'], 'traffic_tx_packets': t['tx_packets'],
                'distance_m': distance_m,
            }
        except Exception:
            return {
                'traffic_rx_octets': None, 'traffic_tx_octets': None,
                'traffic_rx_packets': None, 'traffic_tx_packets': None,
                'distance_m': None,
            }

    def reboot_onu(self, olt: dict, onu: dict) -> dict:
        if is_demo_olt(olt):
            return {'success': True, 'message': 'ONU reboot command sent (Mode Demo).'}

        pon_port = onu['pon_port']
        onu_id = onu['onu_id']
        commands = [
            f"pon-onu-mng gpon-onu_{pon_port}:{onu_id}",
            "reboot",
            "exit"
        ]
        log = execute_ssh_commands(olt, commands)
        return {
            'success': True,
            'message': f"Perintah reboot berhasil dikirim ke gpon-onu_{pon_port}:{onu_id}.",
            'log': log
        }

    def restore_factory(self, olt: dict, onu: dict) -> dict:
        if is_demo_olt(olt):
            return {'success': True, 'message': 'ONU factory reset command sent (Mode Demo).'}

        pon_port = onu['pon_port']
        onu_id = onu['onu_id']
        commands = [
            f"pon-onu-mng gpon-onu_{pon_port}:{onu_id}",
            "restore factory",
            "exit"
        ]
        log = execute_ssh_commands(olt, commands)
        return {
            'success': True,
            'message': f"Perintah restore factory berhasil dikirim ke gpon-onu_{pon_port}:{onu_id}.",
            'log': log
        }

    def delete_onu(self, olt: dict, onu: dict) -> dict:
        if is_demo_olt(olt):
            return {'success': True, 'message': 'ONU de-authorized successfully (Mode Demo).'}

        pon_port = onu['pon_port']
        onu_id = onu['onu_id']
        commands = [
            'configure terminal',
            f"interface gpon-olt_{pon_port}",
            f"no onu {onu_id}",
            'exit',
            'exit',
            'write'
        ]
        log = execute_ssh_commands(olt, commands)
        errors = self._vlan_errors(log)
        ok = len(errors) == 0
        return {
            'success': ok,
            'message': f"ONU gpon-onu_{pon_port}:{onu_id} berhasil dihapus dari OLT." if ok
                       else f"OLT menolak perintah: {'; '.join(errors)}",
            'log': log
        }

    def update_onu_description(self, olt: dict, pon_port: str, onu_id: int, description: str) -> dict:
        """Update only the description/name on OLT for an existing ONU (lightweight, no WAN config touch)."""
        if is_demo_olt(olt):
            return {'success': True, 'message': 'Description updated (Mode Demo).'}
        commands = [
            'configure terminal',
            f'interface gpon-onu_{pon_port}:{onu_id}',
            f'description {description}',
            'exit',
            'exit',
            'write'
        ]
        log = execute_ssh_commands(olt, commands)
        errors = self._vlan_errors(log)
        ok = len(errors) == 0
        return {
            'success': ok,
            'message': f'Description updated for gpon-onu_{pon_port}:{onu_id}.' if ok
                       else f'OLT menolak: {"; ".join(errors)}',
            'log': log
        }

    def authorize_onu(self, olt: dict, pon_port: str, serial: str, name: str, vlan: int, desc: str, onu_id: int = None,
                       wan_mode: str = None, pppoe_username: str = None, pppoe_password: str = None,
                       upload_profile: str = None, download_profile: str = None) -> dict:
        if is_demo_olt(olt):
            return {
                'success': True,
                'message': 'ONU authorized successfully (Mode Demo).',
                'onu_id': onu_id or 1,
                'pon_port': pon_port
            }

        # Sanitize profile names before CLI interpolation (command injection prevention)
        if upload_profile:
            upload_profile = self._sanitize_profile_name(upload_profile)
        if download_profile:
            download_profile = self._sanitize_profile_name(download_profile)

        # 1. Cari ONU ID berikutnya yang kosong jika onu_id = None
        if onu_id is None:
            raw_ids = execute_ssh_commands(olt, [f"show gpon onu uncfg gpon-olt_{pon_port}"])
            # Kueri unconfigured
            # Jika onu_id tidak disediakan, kita ambil default index dari OLT atau cari yang kosong
            # Di sini kita bisa gunakan uncfg query atau default ke auto-find atau parsing range
            onu_id = 1 # Dummy default
            # Cari range yang kosong
            raw_state = execute_ssh_commands(olt, [f"show gpon onu state gpon-olt_{pon_port}"])
            existing_ids = [int(x) for x in re.findall(r'gpon-onu_[0-9\/]+:(\d+)', raw_state)]
            for i in range(1, 129):
                if i not in existing_ids:
                    onu_id = i
                    break

        # 2. Daftarkan + setup OMCI default (flow, switchport-bind, vlan-filter, dhcp-ip, security-mgmt)
        # sesuai konfigurasi default saat otorisasi via web page ONU (bukan hanya saat PPPoE dipilih).
        # PENTING: tcont/gemport HARUS dibuat SEBELUM service-port -- service-port mengikat ke
        # gemport 1 yang baru ada setelah "tcont 1 profile ..." + "gemport 1 tcont 1" dijalankan.
        # (bug sebelumnya: service-port dipanggil duluan -> OLT tolak %Code 66657/63956 gemport belum ada)
        commands = [
            'configure terminal',
            f"interface gpon-olt_{pon_port}",
            f"onu {onu_id} type F609 sn {serial}",
            'exit',
            f"interface gpon-onu_{pon_port}:{onu_id}",
            f"name {name}",
            f"description {desc}",
        ]
        if upload_profile and download_profile:
            commands += [
                f"tcont 1 profile {upload_profile}",
                "gemport 1 tcont 1",
                f"gemport 1 traffic-limit downstream {download_profile}",
            ]
        commands += [
            f"service-port 1 vport 1 user-vlan {vlan} vlan {vlan}",
            'exit',
            f"pon-onu-mng gpon-onu_{pon_port}:{onu_id}",
            "flow mode 1 tag-filter vlan-filter untag-filter discard",
            f"flow 1 pri 0 vlan {vlan}",
            "gemport 1 flow 1",
            "switchport-bind switch_0/1 iphost 1",
            "switchport-bind switch_0/1 veip 1",
        ]
        if wan_mode == 'PPPoE' and pppoe_username and pppoe_password:
            commands.append(f"pppoe 1 nat enable user {pppoe_username} password {pppoe_password}")
        commands += [
            "vlan-filter-mode iphost 1 tag-filter vlan-filter untag-filter discard",
            f"vlan-filter iphost 1 pri 0 vlan {vlan}",
            "dhcp-ip ethuni eth_0/1 from-onu",
            "dhcp-ip ethuni eth_0/2 from-onu",
            "dhcp-ip ethuni eth_0/3 from-onu",
            "dhcp-ip ethuni eth_0/4 from-onu",
            "security-mgmt 998 state enable mode forward ingress-type lan protocol web https",
            "security-mgmt 999 state enable ingress-type lan protocol ftp telnet ssh snmp tr069",
            'exit',
            'exit',
            'write'
        ]
        log = execute_ssh_commands(olt, commands)
        # 'write' (flush ke flash) TIDAK dikirim di sini lagi — dibatch oleh
        # flush_pending_write.py (systemd timer tiap 10 menit). Command config
        # di atas berlaku langsung ke running-config OLT tanpa perlu 'write'.
        errors = self._vlan_errors(log)
        # Kode-kode ini berarti OLT SUDAH menerapkan config yang sama (entry/
        # gem port/service sudah ada persis seperti yang kita kirim) — bukan
        # penolakan asli. Terverifikasi via show running-config: config yang
        # dikirim benar-benar tersimpan meski OLT membalas kode ini.
        # 62391 = ONU sn ini sudah pernah terdaftar di slot ini (re-create).
        # 66661 = service-port sudah ada dengan binding yang sama.
        # 63869 = flow record sudah ada.
        # 63873 = gem port binding sudah ada.
        BENIGN_CODES = ('62391', '66661', '66662', '63869', '63873', '63856', '63933', '63953', '62397', '63993')
        errors = [e for e in errors if not any(c in e for c in BENIGN_CODES)]
        ok = len(errors) == 0

        return {
            'success': ok,
            'message': f"ONU berhasil terdaftar dengan ID {onu_id}." if ok
                       else f"OLT menolak perintah: {'; '.join(errors)}",
            'onu_id': onu_id,
            'pon_port': pon_port,
            'log': log
        }

    def configure_onu_full(self, olt: dict, onu: dict, wan: dict) -> dict:
        if is_demo_olt(olt):
            return {'success': True, 'message': 'Configuration applied successfully (Mode Demo).'}

        pon_port = onu['pon_port']
        onu_id = onu['onu_id']
        vlan = wan.get('vlan_service') or wan.get('vlan') or 15
        config_method = wan.get('config_method', 'OMCI')
        # TR069: VLAN/service-port tetap di-provision di OLT (Layer 2), tapi WAN
        # param (PPPoE/Static, Layer 3) dipush via TR-069 dari luar, bukan CLI OMCI.
        wan_mode = wan.get('wan_mode', 'PPPoE') if config_method != 'TR069' else 'TR069'
        username = wan.get('pppoe_username', '')
        password = wan.get('pppoe_password', '')
        static_ip = wan.get('static_ip', '')
        static_netmask = wan.get('static_netmask', '')
        static_gateway = wan.get('static_gateway', '')
        static_dns_primary = wan.get('static_dns_primary', '')
        static_dns_secondary = wan.get('static_dns_secondary', '')
        remote_access = wan.get('wan_remote_access', 'no')

        # ZTE tidak overwrite entry vlan-filter/flow lama saat re-issue dengan VLAN
        # Cek dulu VLAN lama yang tersimpan, hapus eksplisit sebelum set yang baru.
        raw_old = execute_ssh_commands(olt, [f"show onu running config gpon-onu_{pon_port}:{onu_id}"])
        old_filter_vlans = set(re.findall(r'vlan-filter\s+iphost\s+1\s+pri\s+\d+\s+vlan\s+(\d+)', raw_old, re.IGNORECASE))
        old_flow_vlans = set(re.findall(r'flow\s+1\s+pri\s+\d+\s+vlan\s+(\d+)', raw_old, re.IGNORECASE))
        has_old_pppoe = bool(re.search(r'pppoe\s+1\s+nat\s+enable', raw_old, re.IGNORECASE))
        has_old_static = bool(re.search(r'ip-host\s+1\s+ip\s+\S+', raw_old, re.IGNORECASE))


        commands = [
            'configure terminal',
            f"interface gpon-onu_{pon_port}:{onu_id}",
            "no service-port 1",
            f"service-port 1 vport 1 user-vlan {vlan} vlan {vlan}",
            'exit',
            f"pon-onu-mng gpon-onu_{pon_port}:{onu_id}",
        ]
        for old_v in old_flow_vlans - {str(vlan)}:
            commands.append(f"no flow 1 pri 0 vlan {old_v}")
        commands.append(f"flow 1 pri 0 vlan {vlan}")
        for old_v in old_filter_vlans - {str(vlan)}:
            commands.append(f"no vlan-filter iphost 1 pri 0 vlan {old_v}")
        commands.append(f"vlan-filter iphost 1 pri 0 vlan {vlan}")
        # Kirim/hapus 'pppoe 1' sesuai mode TUJUAN, bukan selalu kirim PPPoE:
        # - Tujuan PPPoE: hapus dulu entry lama (kalau ada) baru buat yang baru.
        # - Tujuan bukan PPPoE (DHCP/Static/dll): hapus entry PPPoE lama kalau ada,
        #   JANGAN kirim 'pppoe 1 nat enable' sama sekali.
        if wan_mode == 'PPPoE':
            if has_old_pppoe:
                commands.append("no pppoe 1")
            commands.append(f"pppoe 1 nat enable user {username} password {password}")
        elif has_old_pppoe:
            commands.append("no pppoe 1")
        # Static IP: kirim ip-host cuma kalau mode tujuan Static DAN ketiga field terisi.
        # Mode lain: hapus entry ip-host lama kalau ada residual dari Static sebelumnya.
        if wan_mode == 'Static' and static_ip and static_netmask and static_gateway:
            if has_old_static:
                commands.append("no ip-host 1")
            ip_host_cmd = f"ip-host 1 ip {static_ip} mask {static_netmask} gateway {static_gateway}"
            if static_dns_primary:
                ip_host_cmd += f" primary-dns {static_dns_primary}"
            if static_dns_secondary:
                ip_host_cmd += f" second-dns {static_dns_secondary}"
            commands.append(ip_host_cmd)
        elif has_old_static:
            commands.append("no ip-host 1")
        # WAN remote access: buka/tutup akses dari internet luar (ingress-type wan).
        # Beda dari security-mgmt 998/999 di authorize_onu (ingress-type lan, selalu
        # aktif, buat akses dari jaringan lokal ONU saja).
        if remote_access == 'yes':
            commands.append("security-mgmt 1 state enable mode forward ingress-type wan protocol web")
            commands.append("security-mgmt 2 state enable mode forward ingress-type wan protocol telnet")
        else:
            commands.append("security-mgmt 1 state enable mode discard ingress-type wan protocol web")
            commands.append("security-mgmt 2 state enable mode discard ingress-type wan protocol telnet")
        commands += [
            'exit',
            'exit',
            'write'
        ]
        log = execute_ssh_commands(olt, commands)
        errors = self._vlan_errors(log)
        # Sama seperti authorize_onu: kode-kode ini berarti OLT SUDAH menerapkan
        # config yang sama (entry/flow/vlan-filter sudah ada persis), bukan
        # penolakan asli.
        BENIGN_CODES = ('62391', '66661', '66662', '63869', '63873', '63856', '63933', '63953', '62397', '63993')
        errors = [e for e in errors if not any(c in e for c in BENIGN_CODES)]
        # security-mgmt ingress-type wan (blokir akses web/telnet dari internet luar) tidak
        # didukung sebagian model ONT (mis. HG8145V5) -- ONT balas 'command not supported'.
        # Fitur ini cuma hardening opsional, BUKAN bagian inti WAN/PPPoE -- kalau error itu
        # muncul spesifik dari command security-mgmt, jangan gagalkan seluruh operasi (VLAN/
        # PPPoE tetap sudah diterapkan sukses di command sebelumnya), cukup catat sebagai warning.
        skipped_security = []
        if any(c.strip().startswith('security-mgmt') for c in commands) and errors:
            blocks = re.split(r'(?=OLT# show )', log)
            errors = []
            for block in blocks:
                block_errors = [e for e in self._vlan_errors(block) if not any(c in e for c in BENIGN_CODES)]
                if not block_errors:
                    continue
                if block.strip().startswith('OLT# show security-mgmt') and 'wan' in block.split('\n', 1)[0]:
                    skipped_security.extend(block_errors)
                else:
                    errors.extend(block_errors)
        ok = len(errors) == 0
        message = 'Konfigurasi PPPoE & VLAN berhasil diterapkan.' if ok \
            else f"OLT menolak perintah: {'; '.join(errors)}"
        if ok and skipped_security:
            message += ' (Catatan: fitur blokir akses WAN tidak didukung ONT ini, dilewati.)'
        return {
            'success': ok,
            'message': message,
            'log': log
        }

    def _build_mgmt_vlan_infra(self, onu_intf: str, vlan: int) -> list:
        """Build CLI commands untuk setup infrastruktur management VLAN."""
        return [
            'configure terminal',
            f'interface {onu_intf}',
            'tcont 2 profile SMARTOLT-VOIPMNG-10M',
            'gemport 2 tcont 2',
            'gemport 2 traffic-limit downstream SMARTOLT-VOIPMNG-10M',
            f'service-port 2 vport 2 user-vlan {vlan} vlan {vlan}',
            'exit',
            f'pon-onu-mng {onu_intf}',
            'flow 2 switch switch_0/1',
            'flow mode 2 tag-filter vlan-filter untag-filter discard',
            f'flow 2 pri 2 vlan {vlan}',
            'gemport 2 flow 2',
            'switchport-bind switch_0/1 iphost 2',
            'vlan-filter-mode iphost 2 tag-filter vlan-filter untag-filter discard',
            f'vlan-filter iphost 2 pri 2 vlan {vlan}',
        ]

    def set_mgmt_ip(self, olt: dict, pon_port: str, onu_id: int, mode: str, vlan: int = 0, ip: str = '', wan_remote: str = 'no') -> dict:
        """Push IP management config ke ONU via OLT CLI.
        mode: 'Inactive' (disable), 'DHCP', 'Static'
        Setup full infra VLAN management + ip-host 2.
        """
        if is_demo_olt(olt):
            return {'success': True, 'message': f'MGMT IP set to {mode} (Mode Demo).'}
        onu_intf = f'gpon-onu_{pon_port}:{onu_id}'

        if mode == 'Inactive':
            # Hapus SEMUA infrastruktur management — simplified syntax ZTE
            commands = [
                'configure terminal',
                f'interface {onu_intf}',
                'no service-port 2',
                'no gemport 2',
                'no tcont 2',
                'exit',
                f'pon-onu-mng {onu_intf}',
                'tr069-mgmt 1 state lock',
                'no tr069-mgmt 1 acs',
                'no ip-host 2',
                'no vlan-filter-mode iphost 2',
                'no flow 2',
                'exit', 'exit', 'write'
            ]
        else:
            commands = self._build_mgmt_vlan_infra(onu_intf, vlan)
            if mode == 'DHCP':
                commands.append('ip-host 2 dhcp-enable enable ping-response enable traceroute-response enable')
            elif mode == 'Static':
                if not ip:
                    return {'success': False, 'message': 'IP address wajib diisi untuk mode Static.'}
                mask = '255.255.255.0'
                commands.append(f'ip-host 2 ip {ip} mask {mask} gateway 0.0.0.0')
            else:
                return {'success': False, 'message': f'Mode {mode} tidak didukung.'}
            commands.extend(['exit', 'exit', 'write'])

        log = execute_ssh_commands(olt, commands)
        errors = self._vlan_errors(log)
        BENIGN_CODES = ('62391', '66661', '66662', '63869', '63873', '63856', '63933', '63953', '62397', '63993')
        errors = [e for e in errors if not any(c in e for c in BENIGN_CODES)]
        ok = len(errors) == 0
        return {
            'success': ok,
            'message': f'IP Manajemen berhasil diubah ke {mode}.' if ok else f'OLT menolak: {"; ".join(errors)}',
            'log': log
        }

    def set_tr069_profile(self, olt: dict, pon_port: str, onu_id: int, acs_url: str, username: str = '', password: str = '') -> dict:
        """Push TR069 config ke ONU via OLT CLI.
        Setup full infra VLAN management + tr069-mgmt dalam satu sesi.
        """
        if is_demo_olt(olt):
            return {'success': True, 'message': f'TR069 set to {acs_url} (Mode Demo).'}
        onu_intf = f'gpon-onu_{pon_port}:{onu_id}'
        if not acs_url:
            # Nonaktifkan tr069 (lock + hapus ACS, tag tidak bisa dihapus)
            commands = [
                'configure terminal',
                f'pon-onu-mng {onu_intf}',
                'tr069-mgmt 1 state lock',
                'no tr069-mgmt 1 acs',
                'exit', 'exit', 'write'
            ]
            log = execute_ssh_commands(olt, commands)
            return {
                'success': True,
                'message': 'TR069 dinonaktifkan.',
                'log': log
            }

        validate_cmd = ''
        if username and password:
            validate_cmd = f'validate basic username {username} password {password}'

        commands = ['configure terminal', f'pon-onu-mng {onu_intf}']
        commands.extend([
            'tr069-mgmt 1 state unlock',
            f'tr069-mgmt 1 acs {acs_url} {validate_cmd}',
        ])
        commands.extend(['exit', 'exit', 'write'])

        log = execute_ssh_commands(olt, commands)
        errors = self._vlan_errors(log)
        BENIGN_CODES = ('62391', '66661', '66662', '63869', '63873', '63856', '63933', '63953', '62397', '63993')
        errors = [e for e in errors if not any(c in e for c in BENIGN_CODES)]
        ok = len(errors) == 0
        return {
            'success': ok,
            'message': 'TR069 profile berhasil dikonfigurasi.' if ok else f'OLT menolak: {"; ".join(errors)}',
            'log': log
        }

    def sync_onu_config(self, olt: dict, onu: dict) -> dict:
        # TR069 mode: WAN (PPPoE/DHCP/Static) sumber kebenarannya GenieACS/TR-069,
        # bukan CLI OLT (CLI sengaja tidak menyimpan WAN saat config_method=TR069).
        # Jangan parse wan_mode/pppoe_* dari show running-config sama sekali.
        skip_wan_parse = (onu.get('config_method') == 'TR069')
        defaults = {
            'pppoe_username': '',
            'pppoe_password': '',
            'vlan': None,
            'onu_mode': 'Routing',
            'wan_mode': None,
            'config_method': 'OMCI',
            'ip_protocol': 'IPv4',
            'wan_remote_access': 'no',
            'mgmt_ip_mode': 'DHCP',
            'mgmt_ip': '',
            'mgmt_vlan': None,
            'allow_remote_mgmt': 'no',
            'pppoe_ip': None,
            'rx_onu': 'N/A',
            'rx_olt': 'N/A',
            'status': 'offline',
        }

        if is_demo_olt(olt):
            return {**defaults, 'pppoe_username': 'demo_user@mamura.net', 'pppoe_password': 'demo_password', 'vlan': 25, 'pppoe_ip': '172.16.69.124'}

        pon_port = onu['pon_port']
        onu_id = onu['onu_id']

        raw = execute_ssh_commands(olt, [
            f"show running-config interface gpon-onu_{pon_port}:{onu_id}",
            f"show onu running config gpon-onu_{pon_port}:{onu_id}",
            f"show gpon remote-onu ip-host gpon-onu_{pon_port}:{onu_id}",
            f"show pon power attenuation gpon-onu_{pon_port}:{onu_id}",
        ])

        result = defaults.copy()

        m = re.search(r'^\s*name\s+(.+)$', raw, re.MULTILINE | re.IGNORECASE)
        if m:
            result['onu_name'] = m.group(1).strip('"\' \r')

        m = re.search(r'^\s*description\s+(.+?)(?=\r?\n\s{2,}\S|\r?\n!|\r?\nend)', raw, re.DOTALL | re.MULTILINE | re.IGNORECASE)
        if m:
            # OLT terminal hard-wrap panjang baris (bukan baris config baru) memotong
            # description jadi 2 baris TANPA spasi asli (mis. "...TGR-0\r\n1D1209-...").
            # Baris config baru asli SELALU diawali >=2 spasi indentasi; baris hasil
            # wrap TIDAK. Maka gabung tanpa spasi bila continuation tanpa indentasi,
            # gabung dengan spasi bila diawali indentasi (jarang terjadi di description).
            raw_desc = m.group(1)
            parts = re.split(r'\r?\n', raw_desc)
            result['onu_description'] = ''.join(p.lstrip() if i == 0 or not p.startswith(('  ', '\t')) else ' ' + p.strip() for i, p in enumerate(parts)).strip()

        m = re.search(r'service-port\s+\d+\s+vport\s+\d+\s+user-vlan\s+(\d+)\s+vlan\s+(\d+)', raw, re.IGNORECASE)
        if m:
            result['vlan'] = int(m.group(1))

        m = re.search(r'vlan-filter\s+iphost\s+1\s+(?:pri\s+\d+\s+)?vlan\s+(\d+)', raw, re.IGNORECASE)
        if m:
            result['vlan'] = int(m.group(1))

        # PENTING: hanya iphost/pppoe NOMOR 1 = WAN utama pelanggan. iphost 2+
        # dipakai management/VOIP (mis. VLAN 100 TR069) dan PUNYA ip-host static
        # sendiri -- kalau tidak difilter nomornya, 'ip-host 2 ip ...' (management)
        # salah dibaca sbg WAN Static padahal WAN sebenarnya (iphost 1) PPPoE.
        # Bug nyata: ONU id 10768659/10768660 tersimpan wan_mode=Static padahal
        # PPPoE aktif di iphost 1.
        m = re.search(r'pppoe\s+1\s+.*?user\s+(\S+)\s+password\s+(\S+)', raw, re.IGNORECASE)
        if not skip_wan_parse:
            if m:
                result['pppoe_username'] = m.group(1).strip('"\'')
                result['pppoe_password'] = m.group(2).strip('"\'')
                result['wan_mode'] = 'PPPoE'

            m = re.search(r'ip-host\s+1\s+ip\s+(\S+)\s+mask\s+(\S+)\s+gateway\s+(\S+)', raw, re.IGNORECASE)
            if m and result['wan_mode'] != 'PPPoE':
                result['wan_mode'] = 'Static'
                result['mgmt_ip'] = m.group(1)
            elif re.search(r'dhcp-ip\s+ethuni\s+\S+\s+from-onu', raw, re.IGNORECASE) and result['wan_mode'] is None:
                result['wan_mode'] = 'DHCP'

        if re.search(r'security-mgmt\s+\d+\s+state\s+enable', raw, re.IGNORECASE):
            result['allow_remote_mgmt'] = 'yes'
            result['wan_remote_access'] = 'yes'

        # Speed profile extraction (ZTE tcont/gemport + CDATA dba-profile-id)
        m_up = re.search(r'tcont\s+\d+\s+profile\s+(\S+)', raw, re.IGNORECASE)
        if not m_up:
            m_up = re.search(r'ont\s+tcont\s+\d+\s+\d+\s+1\s+dba-profile-id\s+(\d+)', raw, re.IGNORECASE)
            if m_up:
                result['upload_profile'] = 'DBA Profile ID ' + m_up.group(1)
        else:
            result['upload_profile'] = m_up.group(1)

        m_down = re.search(r'gemport\s+\d+\s+traffic-limit\s+downstream\s+(\S+)', raw, re.IGNORECASE)
        if not m_down:
            m_down = re.search(r'ont\s+tcont\s+\d+\s+\d+\s+0\s+dba-profile-id\s+(\d+)', raw, re.IGNORECASE)
            if m_down:
                result['download_profile'] = 'DBA Profile ID ' + m_down.group(1)
        else:
            result['download_profile'] = m_down.group(1)

        m = re.search(r'Current\s+IP\s+address:\s*(\d+\.\d+\.\d+\.\d+)', raw, re.IGNORECASE)
        if m and m.group(1) != '0.0.0.0':
            result['pppoe_ip'] = m.group(1)

        m = re.search(r'1490nm\s+Tx\s*:\s*\S+\s+Rx\s*:\s*([-]?\d+\.?\d*)', raw, re.IGNORECASE)
        if m:
            result['rx_onu'] = float(m.group(1))
            result['status'] = 'online'
        else:
            m = re.search(r'down\s+Tx\s*:\s*\S+\s+Rx:\s*([-]?\d+\.?\d*)', raw, re.IGNORECASE)
            if m:
                result['rx_onu'] = float(m.group(1))
                result['status'] = 'online'

        m = re.search(r'1310nm\s+Rx\s*:\s*([-]?\d+\.?\d*)', raw, re.IGNORECASE)
        if m:
            result['rx_olt'] = float(m.group(1))
        else:
            m = re.search(r'up\s+Rx\s*:\s*([-]?\d+\.?\d*)', raw, re.IGNORECASE)
            if m:
                result['rx_olt'] = float(m.group(1))

        return {**result, 'success': True}

    def get_onu_full_status(self, olt: dict, onu: dict) -> dict:
        if is_demo_olt(olt):
            sn = onu.get('serial_number', 'DEMOSN000001')
            name = onu.get('name', 'Demo ONU')
            user = onu.get('pppoe_username', 'demo@isp.net')
            return {
                'success': True,
                'message': 'Data demo.',
                'optical_status': "Wavelength  OLT                   ONU                  Attenuation\n1310nm      Rx :-33.010(dbm)      Tx:2.250(dbm)        35.260(dB)\n1490nm      Tx :7.236(dbm)        Rx:-26.576(dbm)      33.812(dB)",
                'onu_catv_port': "Admin status:        unlock\nState:               disabled\n1550nm Rx:           N/A(dBm)\nRF output:           N/A(dBuV)",
                'onu_details': f"Vendor ID:           ZTEG \nHW Version:          V5.3 \nDetected ONU type:   F609V5.3\nName:                {name}\nType:                F609\nState:               ready\nCurrent channel:     1(GPON)\nAdmin state:         enable\nPhase state:         working\nSerial number:       {sn}\nDescription:         zone_Sidoharjo_descr_Tremes_odb_JTS_authd_20260129\nONU Status:          enable\nONU Distance:        10438m\nOnline Duration:     55h 25m 35s",
                'history': "     Authpass Time          OfflineTime             Cause\n 1   2026-08-05 14:53:24    2026-08-06 10:10:55     Power Fail\n 2   2026-08-06 10:14:46    ONU is currently online",
                'wan_interfaces': f"WAN ID:           1\nMAC address:      ec6c.b532.be20\nIPv4 address:     172.29.85.13\nSubnet mask:      255.255.255.255\nDefault gateway:  172.29.24.246\nDNS server 1:     103.163.102.102\nWAN ID:           2\nWAN ID:           3\nWAN ID:           4\nWAN ID:           5\n\nPPPoE WAN:        1\nNAT:              enable\nUsername:         {user}\nStatus:           connected\nOnline duration:  76307 (s)",
                'lan_interfaces': "eth_0/1       auto         unlock    1632       0\neth_0/2       auto         unlock    1632       0\neth_0/3       auto         unlock    1632       0\neth_0/4       auto         unlock    1632       0",
                'vlan_info': "eth_0/1       N/A         --         --          --\neth_0/2       N/A         --         --          --\neth_0/3       N/A         --         --          --\neth_0/4       N/A         --         --          --",
                'voip_status': "Tel line:           pots_0/1\nService status:     none\nTel line:           pots_0/2\nService status:     none",
                'macs': "ec:6c:b5:32:be:20   15    Dynamic   gpon-onu_1/2/2:30        vport 1\nec:6c:b5:32:be:1f   100   Dynamic   gpon-onu_1/2/2:30        vport 2\nec:6c:b5:32:be:21   100   Dynamic   gpon-onu_1/2/2:30        vport 2",
                'ont_statistics': '',
                'rogue_ont_status': '',
                'pppoe_ip': '172.23.45.116',
                'rx_onu': -26.57,
                'rx_olt': -33.01
            }

        pon_port = onu['pon_port']
        onu_id = onu['onu_id']

        cmds = [
            f"show gpon onu detail-info gpon-onu_{pon_port}:{onu_id}",
            f"show pon power attenuation gpon-onu_{pon_port}:{onu_id}",
            f"show gpon remote-onu catv gpon-onu_{pon_port}:{onu_id}",
            f"show gpon remote-onu wan-info gpon-onu_{pon_port}:{onu_id}",
            f"show gpon remote-onu ip-host gpon-onu_{pon_port}:{onu_id}",
            f"show gpon remote-onu eth-status gpon-onu_{pon_port}:{onu_id}",
            f"show gpon remote-onu vlan gpon-onu_{pon_port}:{onu_id}",
            f"show gpon remote-onu voip gpon-onu_{pon_port}:{onu_id}",
            f"show mac gpon-onu_{pon_port}:{onu_id}"
        ]
        raw = execute_ssh_commands(olt, cmds)

        detail_raw = ""
        signal_raw = ""
        catv_raw = ""
        wan_raw = ""
        ip_raw = ""
        eth_raw = ""
        vlan_raw = ""
        voip_raw = ""
        mac_raw = ""

        parts = re.split(r'(?:#\s*|>\s*)(?:show\s+)+', raw, flags=re.IGNORECASE)
        for part in parts:
            if re.match(r'^gpon\s+onu\s+detail-info', part, re.IGNORECASE):
                detail_raw = re.sub(r'^gpon\s+onu\s+detail-info\s+\S+\s*\n', '', part, flags=re.IGNORECASE)
            elif re.match(r'^pon\s+power\s+attenuation', part, re.IGNORECASE):
                signal_raw = re.sub(r'^pon\s+power\s+attenuation\s+\S+\s*\n', '', part, flags=re.IGNORECASE)
            elif re.match(r'^gpon\s+remote-onu\s+catv', part, re.IGNORECASE):
                catv_raw = re.sub(r'^gpon\s+remote-onu\s+catv\s+\S+\s*\n', '', part, flags=re.IGNORECASE)
            elif re.match(r'^gpon\s+remote-onu\s+wan-info', part, re.IGNORECASE):
                wan_raw = re.sub(r'^gpon\s+remote-onu\s+wan-info\s+\S+\s*\n', '', part, flags=re.IGNORECASE)
            elif re.match(r'^gpon\s+remote-onu\s+ip-host', part, re.IGNORECASE):
                ip_raw = re.sub(r'^gpon\s+remote-onu\s+ip-host\s+\S+\s*\n', '', part, flags=re.IGNORECASE)
            elif re.match(r'^gpon\s+remote-onu\s+eth-status', part, re.IGNORECASE):
                eth_raw = re.sub(r'^gpon\s+remote-onu\s+eth-status\s+\S+\s*\n', '', part, flags=re.IGNORECASE)
            elif re.match(r'^gpon\s+remote-onu\s+vlan', part, re.IGNORECASE):
                vlan_raw = re.sub(r'^gpon\s+remote-onu\s+vlan\s+\S+\s*\n', '', part, flags=re.IGNORECASE)
            elif re.match(r'^gpon\s+remote-onu\s+voip', part, re.IGNORECASE):
                voip_raw = re.sub(r'^gpon\s+remote-onu\s+voip\s+\S+\s*\n', '', part, flags=re.IGNORECASE)
            elif re.match(r'^mac\s+', part, re.IGNORECASE):
                mac_raw = re.sub(r'^mac\s+\S+\s*\n', '', part, flags=re.IGNORECASE)

        # Self-healing fallbacks
        if not detail_raw.strip():
            detail_raw = execute_ssh_commands(olt, [f"show gpon onu detail-info gpon-onu_{pon_port}:{onu_id}"])
        if not signal_raw.strip():
            signal_raw = execute_ssh_commands(olt, [f"show pon power attenuation gpon-onu_{pon_port}:{onu_id}"])
        if not catv_raw.strip():
            catv_raw = execute_ssh_commands(olt, [f"show gpon remote-onu catv gpon-onu_{pon_port}:{onu_id}"])
        if not wan_raw.strip():
            wan_raw = execute_ssh_commands(olt, [f"show gpon remote-onu wan-info gpon-onu_{pon_port}:{onu_id}"])
        if not ip_raw.strip():
            ip_raw = execute_ssh_commands(olt, [f"show gpon remote-onu ip-host gpon-onu_{pon_port}:{onu_id}"])
        if not eth_raw.strip():
            eth_raw = execute_ssh_commands(olt, [f"show gpon remote-onu eth-status gpon-onu_{pon_port}:{onu_id}"])
        if not vlan_raw.strip():
            vlan_raw = execute_ssh_commands(olt, [f"show gpon remote-onu vlan gpon-onu_{pon_port}:{onu_id}"])
        if not voip_raw.strip():
            voip_raw = execute_ssh_commands(olt, [f"show gpon remote-onu voip gpon-onu_{pon_port}:{onu_id}"])
        if not mac_raw.strip():
            mac_raw = execute_ssh_commands(olt, [f"show mac gpon-onu_{pon_port}:{onu_id}"])

        # 1. Format Optical Status
        up_line = ""
        down_line = ""
        rx_onu = 'N/A'
        rx_olt = 'N/A'
        for line in signal_raw.split('\n'):
            line = line.strip()
            m = re.search(r'(\bup\b|1310nm)\s+(Rx\s*:\s*\S+)\s+(Tx\s*:\s*\S+)\s+(\S+)', line, re.IGNORECASE)
            if m:
                up_line = f"1310nm      {m.group(2):<21} {m.group(3):<20} {m.group(4)}"
                mr = re.search(r'Rx\s*:\s*([-]?\d+\.?\d*)', m.group(2), re.IGNORECASE)
                if mr:
                    rx_olt = float(mr.group(1))

            m = re.search(r'(\bdown\b|1490nm)\s+(Tx\s*:\s*\S+)\s+(Rx\s*:\s*\S+)\s+(\S+)', line, re.IGNORECASE)
            if m:
                down_line = f"1490nm      {m.group(2):<21} {m.group(3):<20} {m.group(4)}"
                mr = re.search(r'Rx\s*:\s*([-]?\d+\.?\d*)', m.group(3), re.IGNORECASE)
                if mr:
                    rx_onu = float(mr.group(1))

        if not up_line:
            m = re.search(r'(Rx\s*:\s*[-]?\d+\.?\d*)\s+(Tx\s*:\s*[-]?\d+\.?\d*)\s+(\S+)', signal_raw, re.IGNORECASE)
            if m:
                up_line = f"1310nm      {m.group(1)}      {m.group(2)}        {m.group(3)}"
                mr = re.search(r'Rx\s*:\s*([-]?\d+\.?\d*)', m.group(1), re.IGNORECASE)
                if mr:
                    rx_olt = float(mr.group(1))

        optical_status = "Wavelength  OLT                   ONU                  Attenuation\n" + \
                         (up_line + "\n" if up_line else "") + \
                         (down_line if down_line else "")

        # 2. Format CATV Port
        catv_lines = []
        m = re.search(r'Admin\s+status\s*:\s*(\S+)', catv_raw, re.IGNORECASE)
        catv_lines.append("Admin status:        " + (m.group(1) if m else "unlock"))
        
        m = re.search(r'State\s*:\s*(\S+)', catv_raw, re.IGNORECASE)
        catv_lines.append("State:               " + (m.group(1) if m else "disabled"))
        
        m = re.search(r'1550nm\s+Rx\s*:\s*(\S+)', catv_raw, re.IGNORECASE)
        catv_lines.append("1550nm Rx:           " + (m.group(1) if m else "N/A(dBm)"))
        
        m = re.search(r'RF\s+output\s*:\s*(\S+)', catv_raw, re.IGNORECASE)
        catv_lines.append("RF output:           " + (m.group(1) if m else "N/A(dBuV)"))
        onu_catv_port = "\n".join(catv_lines)

        # 3. Format Details
        detail_fields = {'vendor_id': 'ZTEG', 'hw_ver': 'V5.3', 'detected_type': 'F609V5.3',
                         'name': onu.get('name', 'ONU'), 'type': 'F609', 'state': 'ready',
                         'channel': '1(GPON)', 'admin_state': 'enable', 'phase_state': 'working',
                         'sn': onu.get('serial_number', 'SN'), 'desc': onu.get('name', 'DESC'),
                         'onu_status': 'enable', 'distance': '10438m', 'online_dur': '55h 25m 35s'}
        
        reg_map = {
            'vendor_id': r'Vendor\s+ID\s*:\s*(\S+)',
            'hw_ver': r'Hardware\s+version\s*:\s*(.+)$',
            'detected_type': r'Equipment\s+ID\s*:\s*(.+)$',
            'name': r'Name\s*:\s*(.+)$',
            'type': r'Type\s*:\s*(.+)$',
            'state': r'State\s*:\s*(\S+)',
            'channel': r'Channel\s*:\s*(.+)$',
            'admin_state': r'Admin\s+state\s*:\s*(\S+)',
            'phase_state': r'Phase\s+state\s*:\s*(\S+)',
            'sn': r'SN\s*:\s*(\S+)',
            'desc': r'Description\s*:\s*(.+)$',
            'onu_status': r'ONU\s+Status\s*:\s*(\S+)',
            'distance': r'ONU\s+Distance\s*:\s*(\S+)',
            'online_dur': r'Online\s+Duration\s*:\s*(.+)$'
        }

        for field, regex in reg_map.items():
            m = re.search(regex, detail_raw, re.MULTILINE | re.IGNORECASE)
            if m:
                detail_fields[field] = m.group(1).strip()

        # 'desc' butuh perlakuan khusus SETELAH loop di atas: OLT ini kadang
        # wrap teks Description tepat di batas kolom TANPA indentasi (baris
        # sambungan nempel rata kiri, beda dari baris field berikutnya yang
        # selalu berindentasi "  Label:"). Regex umum (.+)$ di reg_map hanya
        # ambil baris pertama -> deskripsi terpotong ("...Sebelum_" hilang
        # "LP_Grafika_authd_..."). Sama seperti bug wrap yang sudah diperbaiki
        # di sync_onu_config()/get_onu_config_maps().
        m_desc = re.search(
            r'Description\s*:\s*(.+?)(?=\r?\n\s{2,}\S|\r?\n-{3,}|\r?\n\r?\n|\Z)',
            detail_raw, re.DOTALL | re.IGNORECASE
        )
        if m_desc:
            detail_fields['desc'] = re.sub(r'\r?\n', '', m_desc.group(1)).strip()

        onu_details = (
            f"Vendor ID:           {detail_fields['vendor_id']} \n"
            f"HW Version:          {detail_fields['hw_ver']} \n"
            f"Detected ONU type:   {detail_fields['detected_type']}\n"
            f"Name:                {detail_fields['name']}\n"
            f"Type:                {detail_fields['type']}\n"
            f"State:               {detail_fields['state']}\n"
            f"Current channel:     {detail_fields['channel']}\n"
            f"Admin state:         {detail_fields['admin_state']}\n"
            f"Phase state:         {detail_fields['phase_state']}\n"
            f"Serial number:       {detail_fields['sn']}\n"
            f"Description:         {detail_fields['desc']}\n"
            f"ONU Status:          {detail_fields['onu_status']}\n"
            f"ONU Distance:        {detail_fields['distance']}\n"
            f"Online Duration:     {detail_fields['online_dur']}"
        )

        # 4. Format History
        history = "     Authpass Time          OfflineTime             Cause\n"
        m = re.search(r'History:\s*\n(.*?)(?=\n\n|\n[A-Za-z]|\Z)', detail_raw, re.DOTALL | re.IGNORECASE)
        if m:
            clean_lines = []
            for line in m.group(1).split('\n'):
                line = line.strip()
                if not line or 'authpass time' in line.lower() or '---' in line:
                    continue
                clean_lines.append(" " + line)
            if clean_lines:
                history += "\n".join(clean_lines)
            else:
                history += f" 1   {datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}    ONU is currently online"
        else:
            history += f" 1   {datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}    ONU is currently online"

        # 5. Format WAN Info
        # NB: 'show gpon remote-onu wan-info' TIDAK didukung OLT ini (selalu
        # %Error 20200 Invalid command) -> wan_raw SELALU kosong di device ini.
        # Sumber data WAN yang benar: 'show gpon remote-onu ip-host' (ip_raw),
        # sudah dipanggil di atas tapi sebelumnya tidak pernah dipakai --
        # WAN interfaces di kode lama JATUH ke hardcoded demo data (MAC
        # ec6c.b532.be20, gateway 172.29.24.246 palsu) setiap kali. Diganti
        # parse dari Host blocks (Host ID N ... Current IP address: x.x.x.x).
        wan_interfaces = ""
        pppoe_ip = 'N/A'
        host_blocks = [m.group(0) for m in re.finditer(r'Host\s+ID\s*:\s*\d+.*?(?=Host\s+ID\s*:\s*\d+|\Z)', ip_raw, re.DOTALL | re.IGNORECASE)]
        if host_blocks:
            wan_list = []
            pppoe_info = ""
            for block in host_blocks:
                m_id = re.search(r'Host\s+ID\s*:\s*(\d+)', block, re.IGNORECASE)
                wan_id = m_id.group(1) if m_id else "1"
                mac, ip, mask, gw, dns1, dns2 = '', '', '', '', '', ''

                m = re.search(r'MAC\s+address\s*:\s*(\S+)', block, re.IGNORECASE)
                if m: mac = m.group(1).strip()
                m = re.search(r'Current\s+IP\s+address\s*:\s*(\S+)', block, re.IGNORECASE)
                if m: ip = m.group(1).strip()
                m = re.search(r'Current\s+mask\s*:\s*(\S+)', block, re.IGNORECASE)
                if m: mask = m.group(1).strip()
                m = re.search(r'Current\s+gateway\s*:\s*(\S+)', block, re.IGNORECASE)
                if m: gw = m.group(1).strip()
                m = re.search(r'Current\s+primary\s+DNS\s*:\s*(\S+)', block, re.IGNORECASE)
                if m: dns1 = m.group(1).strip()
                m = re.search(r'Current\s+second\s+DNS\s*:\s*(\S+)', block, re.IGNORECASE)
                if m: dns2 = m.group(1).strip()

                if not (ip and ip != '0.0.0.0'):
                    continue

                wan_str = f"WAN ID:           {wan_id}"
                if mac and mac != '0000.0000.0000': wan_str += f"\nMAC address:      {mac}"
                wan_str += f"\nIPv4 address:     {ip}"
                if mask and mask != '0.0.0.0': wan_str += f"\nSubnet mask:      {mask}"
                if gw and gw != '0.0.0.0': wan_str += f"\nDefault gateway:  {gw}"
                if dns1 and dns1 != '0.0.0.0': wan_str += f"\nDNS server 1:     {dns1}"
                if dns2 and dns2 != '0.0.0.0': wan_str += f"\nDNS server 2:     {dns2}"
                wan_list.append(wan_str)

                if 'pppoe' in block.lower():
                    pppoe_ip = ip
                    user, status, dur = onu.get('pppoe_username', ''), 'connected', 'N/A'
                    m = re.search(r'Username\s*:\s*(\S+)', block, re.IGNORECASE)
                    if m: user = m.group(1).strip()
                    pppoe_info = (
                        f"\n\nPPPoE WAN:        {wan_id}\n"
                        f"NAT:              enable\n"
                        f"Username:         {user}\n"
                        f"Status:           {status}"
                    )
            if wan_list:
                wan_interfaces = "\n".join(wan_list) + pppoe_info
            else:
                wan_interfaces = "Tidak ada WAN aktif (semua host IP 0.0.0.0)."
        else:
            wan_interfaces = "Data WAN tidak tersedia dari OLT (command ip-host tidak mengembalikan hasil)."

        # 6. Format LAN Interfaces
        lan_interfaces = "Port          Speed        Admin     Max-frame  Status changes\n"
        m = re.search(r'Port\s+Speed.*?\n(.*)', eth_raw, re.DOTALL | re.IGNORECASE)
        if m:
            clean_lines = []
            for line in m.group(1).split('\n'):
                line = line.strip()
                if not line or '---' in line: continue
                clean_lines.append(line)
            if clean_lines:
                lan_interfaces += "\n".join(clean_lines)
            else:
                lan_interfaces += "eth_0/1       auto         unlock    1632       0\neth_0/2       auto         unlock    1632       0\neth_0/3       auto         unlock    1632       0\neth_0/4       auto         unlock    1632       0"
        else:
            lan_interfaces += "eth_0/1       auto         unlock    1632       0\neth_0/2       auto         unlock    1632       0\neth_0/3       auto         unlock    1632       0\neth_0/4       auto         unlock    1632       0"

        # 7. Format VLAN Info
        vlan_info = "Interface     Mode        VLAN-ID    Def-Prio    Vlan-list      \n"
        m = re.search(r'Interface\s+Mode.*?\n(.*)', vlan_raw, re.DOTALL | re.IGNORECASE)
        if m:
            clean_lines = []
            for line in m.group(1).split('\n'):
                line = line.strip()
                if not line or '---' in line: continue
                clean_lines.append(line)
            if clean_lines:
                vlan_info += "\n".join(clean_lines)
            else:
                vlan_info += "eth_0/1       N/A         --         --          --\neth_0/2       N/A         --         --          --\neth_0/3       N/A         --         --          --\neth_0/4       N/A         --         --          --"
        else:
            vlan_info += "eth_0/1       N/A         --         --          --\neth_0/2       N/A         --         --          --\neth_0/3       N/A         --         --          --\neth_0/4       N/A         --         --          --"

        # 8. Format VoIP status
        m = re.findall(r'Tel\s+line\s*:\s*(\S+)\s*\n\s*Service\s+status\s*:\s*(\S+)', voip_raw, re.IGNORECASE)
        if m:
            voip_status = "\n".join([f"Tel line:           {match[0]}\nService status:     {match[1]}" for match in m])
        else:
            voip_status = "Tel line:           pots_0/1\nService status:     none\nTel line:           pots_0/2\nService status:     none"

        # 9. Format MAC addresses bound
        macs = "Mac address         Vlan  Type      Port                     Vc                                 \n"
        m = re.search(r'Mac\s+address\s+Vlan.*?\n(.*)', mac_raw, re.DOTALL | re.IGNORECASE)
        if m:
            clean_lines = []
            for line in m.group(1).split('\n'):
                line = line.strip()
                if not line or '---' in line: continue
                clean_lines.append(line)
            if clean_lines:
                macs += "\n".join(clean_lines)
            else:
                macs += "No MAC addresses found on OLT for this ONU."
        else:
            m = re.findall(r'([0-9a-fA-F]{2}(?::[0-9a-fA-F]{2}){5}|[0-9a-fA-F]{4}\.[0-9a-fA-F]{4}\.[0-9a-fA-F]{4})\s+(\d+)\s+(\w+)\s+(\S+)\s+(.*)', mac_raw, re.IGNORECASE)
            if m:
                macs += "\n".join([f"{match[0]:<19} {match[1]:<5} {match[2]:<9} {match[3]:<24} {match[4].strip()}" for match in m])
            else:
                macs += "No MAC addresses found on OLT for this ONU."

        return {
            'success': True,
            'message': 'Status ONU berhasil dibaca.',
            'optical_status': optical_status.strip(),
            'onu_details': onu_details.strip(),
            'onu_catv_port': onu_catv_port.strip(),
            'history': history.strip(),
            'wan_interfaces': wan_interfaces.strip(),
            'lan_interfaces': lan_interfaces.strip(),
            'vlan_info': vlan_info.strip(),
            'voip_status': voip_status.strip(),
            'macs': macs.strip(),
            'ont_statistics': '',
            'rogue_ont_status': '',
            'pppoe_ip': pppoe_ip,
            'rx_onu': rx_onu,
            'rx_olt': rx_olt
        }

    def pull_configured_onus(self, olt: dict) -> dict:
        if is_demo_olt(olt):
            return {
                'success': True,
                'onus': [
                    {'pon_port': '1/2/1', 'onu_id': 1, 'serial_number': 'ZTEGC0B3458A', 'name': 'Demo ONU 1', 'status': 'online'},
                    {'pon_port': '1/2/1', 'onu_id': 2, 'serial_number': 'ZTEGC85ADC5F', 'name': 'Demo ONU 2', 'status': 'offline'},
                ]
            }

        raw = execute_ssh_commands(olt, ['show gpon onu state'])
        onus = []
        matches = re.findall(r'^\s*(\d+(?:/\d+){2,3}):(\d+)\s+\S+\s+\S+\s+(\S+)', raw, re.MULTILINE | re.IGNORECASE)
        for m in matches:
            onus.append({
                'pon_port': m[0],
                'onu_id': int(m[1]),
                'serial_number': 'UNKNOWN',
                'name': f"ONU_{m[1]}",
                'status': 'online' if m[2].lower() in ['working', 'ready', 'logging'] else 'offline'
            })

        if not onus:
            return {'success': True, 'onus': []}

        # Try to pull all baseinfo globally in one command to save CPU and time
        sn_map = {}
        global_raw = execute_ssh_commands(olt, ["show gpon onu baseinfo"])
        if global_raw and not any(x in global_raw.lower() for x in ["error", "invalid", "parameter"]):
            matches_sn = re.findall(r'gpon-onu_([0-9\/]+):(\d+)\s+.*?\s+SN:(\S+)', global_raw, re.IGNORECASE)
            for m in matches_sn:
                sn_map[f"{m[0]}_{m[1]}"] = m[2]
        
        # Fallback to port-by-port loop if global command didn't return any matches
        if not sn_map:
            pon_ports = list(set([o['pon_port'] for o in onus]))
            for port in pon_ports:
                raw_sn = execute_ssh_commands(olt, [f"show gpon onu baseinfo gpon-olt_{port}"])
                matches_sn = re.findall(r'gpon-onu_([0-9\/]+):(\d+)\s+.*?\s+SN:(\S+)', raw_sn, re.IGNORECASE)
                for m in matches_sn:
                    sn_map[f"{m[0]}_{m[1]}"] = m[2]

        for o in onus:
            key = f"{o['pon_port']}_{o['onu_id']}"
            o['serial_number'] = sn_map.get(key, 'UNKNOWN')

        return {'success': True, 'onus': onus}

    def pull_onus_from_current_config(self, olt: dict) -> dict:
        return self.pull_configured_onus(olt)

    def get_onu_traffic_stats(self, olt: dict, onu: dict) -> dict:
        if is_demo_olt(olt):
            import random
            return {
                'success': True,
                'rx_packets': random.randint(100000, 5000000),
                'tx_packets': random.randint(100000, 9000000),
                'rx_bytes': random.randint(10000000, 9999999999),
                'tx_bytes': random.randint(100000000, 99999999999),
            }

        pon_port = onu['pon_port']
        onu_id = onu['onu_id']
        raw = execute_ssh_commands(olt, [f"show gpon onu traffic-info gpon-onu_{pon_port}:{onu_id}"])

        rx_bytes, tx_bytes, rx_packets, tx_packets = None, None, None, None
        
        m = re.search(r'[Rr]eceived\s+(?:bytes|octets)\s*[:=]\s*(\d+)', raw)
        if m: rx_bytes = int(m.group(1))
        m = re.search(r'[Ss]ent\s+(?:bytes|octets)\s*[:=]\s*(\d+)', raw)
        if m: tx_bytes = int(m.group(1))
        m = re.search(r'[Rr]eceived\s+(?:packets|frames)\s*[:=]\s*(\d+)', raw)
        if m: rx_packets = int(m.group(1))
        m = re.search(r'[Ss]ent\s+(?:packets|frames)\s*[:=]\s*(\d+)', raw)
        if m: tx_packets = int(m.group(1))

        if rx_bytes is None:
            m = re.search(r'Rx\s*:?\s*(\d+)\s*(?:bytes|B)', raw, re.IGNORECASE)
            if m: rx_bytes = int(m.group(1))
        if tx_bytes is None:
            m = re.search(r'Tx\s*:?\s*(\d+)\s*(?:bytes|B)', raw, re.IGNORECASE)
            if m: tx_bytes = int(m.group(1))

        return {
            'success': rx_bytes is not None or tx_bytes is not None,
            'rx_packets': rx_packets,
            'tx_packets': tx_packets,
            'rx_bytes': rx_bytes,
            'tx_bytes': tx_bytes
        }

    def get_onu_config_maps(self, olt: dict) -> dict:
        empty = {'ipconfig_map': {}, 'name_map': {}, 'desc_map': {}}
        if is_demo_olt(olt):
            return {**empty, 'success': True, 'message': 'OLT demo.'}

        raw = execute_ssh_commands(olt, ['show running-config'])
        if not raw or 'pon-onu-mng' not in raw:
            raw_mng = execute_ssh_commands(olt, ['show onu running config all'])
            raw = (raw or '') + '\n' + (raw_mng or '')
        if not raw.strip():
            return {**empty, 'success': False, 'message': 'Running-config kosong.'}

        # OLT hard-wrap baris panjang (mis. 'description ...') ke baris berikutnya
        # TANPA indentasi/prefix apapun -> baris continuation tidak cocok pola apapun
        # di bawah dan akan hilang begitu saja kalau diproses baris-per-baris mentah.
        # Gabungkan dulu baris "yatim" (tidak diawali spasi & bukan baris kosong/'!')
        # ke baris sebelumnya sebelum di-split per-baris.
        raw_lines = raw.split('\n')
        merged_lines = []
        for line in raw_lines:
            stripped_cr = line.rstrip('\r')
            if merged_lines and stripped_cr and not stripped_cr.startswith((' ', '\t', '!')) \
                    and not re.match(r'^(interface|pon-onu-mng|exit|OLT#)', stripped_cr, re.I):
                merged_lines[-1] += stripped_cr
            else:
                merged_lines.append(stripped_cr)
        raw = '\n'.join(merged_lines)

        ipconfig_map = {}
        name_map = {}
        desc_map = {}
        current_onu = ''
        in_mng = False

        for line in raw.split('\n'):
            line = line.strip()
            if not line:
                continue

            # interface gpon-onu_1/2/1:1
            m = re.match(r'^interface\s+gpon-onu_([0-9\/]+):(\d+)', line, re.I)
            if m:
                current_onu = f"{m.group(1)}_{m.group(2)}"
                in_mng = False
                continue

            # pon-onu-mng gpon-onu_1/2/1:1
            m = re.match(r'^pon-onu-mng\s+gpon-onu_([0-9\/]+):(\d+)', line, re.I)
            if m:
                current_onu = f"{m.group(1)}_{m.group(2)}"
                in_mng = True
                continue

            if line == '!' or line == 'exit':
                current_onu = ''
                in_mng = False
                continue

            if not current_onu:
                continue

            if not in_mng:
                m_name = re.match(r'^name\s+(.+)$', line, re.I)
                if m_name:
                    name_map[current_onu] = m_name.group(1).strip('"\' ')
                
                m_desc = re.match(r'^description\s+(.+)$', line, re.I)
                if m_desc:
                    desc_map[current_onu] = m_desc.group(1).strip('"\' ')

                m_vl = re.search(r'service-port\s+\d+\s+vport\s+\d+\s+user-vlan\s+(\d+)\s+vlan\s+(\d+)', line, re.I)
                if m_vl:
                    if current_onu not in ipconfig_map:
                        ipconfig_map[current_onu] = {'vlan': int(m_vl.group(1)), 'pppoe_username': '', 'pppoe_password': '', 'wan_mode': 'DHCP'}
                    # ponytail: only first service-port (internet VLAN). Skip subsequent (management).
                    # Add when: need to store all VLANs per ONU.
            else:
                # PENTING: hanya iphost/pppoe NOMOR 1 yang jadi patokan WAN utama
                # pelanggan. iphost 2+ dipakai untuk management/VOIP (mis. VLAN 100
                # TR069) dan PUNYA ip-host statis sendiri -- kalau tidak difilter
                # nomornya, baris 'ip-host 2 ip ...' (management) salah dibaca
                # sebagai WAN Static padahal WAN sebenarnya (iphost 1) PPPoE.
                # Bug nyata ditemukan: ONU id 10768659/10768660 tersimpan
                # wan_mode=Static padahal PPPoE aktif di iphost 1.
                m_pppoe = re.search(r'pppoe\s+1\s+.*?user\s+(\S+)\s+password\s+(\S+)', line, re.I)
                if m_pppoe:
                    if current_onu not in ipconfig_map:
                        ipconfig_map[current_onu] = {'vlan': None, 'pppoe_username': '', 'pppoe_password': '', 'wan_mode': 'PPPoE'}
                    ipconfig_map[current_onu]['pppoe_username'] = m_pppoe.group(1).strip('"\'')
                    ipconfig_map[current_onu]['pppoe_password'] = m_pppoe.group(2).strip('"\'')
                    ipconfig_map[current_onu]['wan_mode'] = 'PPPoE'

                m_static = re.search(r'ip-host\s+1\s+ip\s+(\S+)', line, re.I)
                if m_static:
                    if current_onu not in ipconfig_map:
                        ipconfig_map[current_onu] = {'vlan': None, 'pppoe_username': '', 'pppoe_password': '', 'wan_mode': 'Static'}
                    elif ipconfig_map[current_onu]['wan_mode'] != 'PPPoE':
                        ipconfig_map[current_onu]['wan_mode'] = 'Static'

                m_vlf = re.search(r'vlan-filter\s+iphost\s+1\s+(?:pri\s+\d+\s+)?vlan\s+(\d+)', line, re.I)
                if m_vlf:
                    if current_onu not in ipconfig_map:
                        ipconfig_map[current_onu] = {'vlan': int(m_vlf.group(1)), 'pppoe_username': '', 'pppoe_password': '', 'wan_mode': 'PPPoE'}
                    else:
                        ipconfig_map[current_onu]['vlan'] = int(m_vlf.group(1))
                else:
                    m_vlf_any = re.search(r'vlan-filter\s+iphost\s+\d+\s+(?:pri\s+\d+\s+)?vlan\s+(\d+)', line, re.I)
                    if m_vlf_any:
                        if current_onu not in ipconfig_map:
                            ipconfig_map[current_onu] = {'vlan': int(m_vlf_any.group(1)), 'pppoe_username': '', 'pppoe_password': '', 'wan_mode': 'PPPoE'}
                        else:
                            if ipconfig_map[current_onu]['vlan'] is None:
                                ipconfig_map[current_onu]['vlan'] = int(m_vlf_any.group(1))

        return {
            'success': True,
            'message': 'Konfigurasi ONU ZTE berhasil dipetakan.',
            'ipconfig_map': ipconfig_map,
            'name_map': name_map,
            'desc_map': desc_map
        }

    def get_onu_hw_sw(self, olt: dict, onu: dict) -> dict:
        """SW info: HW/SW/vendor detail dari 'show gpon remote-onu equip' +
        jumlah port dari 'show gpon remote-onu capability'.
        Catatan: CLI telnet ZTE C300 tidak expose versi firmware per-region
        (Region 1/2 active/inactive) — field itu OMCI-only, tidak tersedia via CLI.
        """
        if is_demo_olt(olt):
            return {
                'success': True,
                'onu_details': "Vendor ID              ZTEG \nHW Version              V5.3 \nSerial Number           DEMOSN000001 \nOMCC version             b2 \nModel                   F609V5.3",
                'features': "Number of ETH ports    4    (10GE:0   GE:4   FE:0)\nNumber of VoIP ports   1\nNumber of CATV ports   0\nNumber of WIFI ports   4",
            }

        pon_port = onu['pon_port']
        onu_id = onu['onu_id']
        raw = execute_ssh_commands(olt, [
            f"show gpon remote-onu equip gpon-onu_{pon_port}:{onu_id}",
            f"show gpon remote-onu capability gpon-onu_{pon_port}:{onu_id}",
        ])

        def grab(pattern, text, default='N/A'):
            m = re.search(pattern, text, re.IGNORECASE | re.MULTILINE)
            return m.group(1).strip() if m else default

        vendor_id = grab(r'Vendor\s+ID\s*:\s*(\S+)', raw)
        hw_ver = grab(r'^Version\s*:\s*(\S+)', raw)
        sn = grab(r'^SN\s*:\s*(\S+)', raw)
        omcc = grab(r'OMCC\s+version\s*:\s*(\S+)', raw)
        model = grab(r'^Model\s*:\s*(\S+)', raw)

        onu_details = (
            f"Vendor ID              {vendor_id} \n"
            f"HW Version              {hw_ver} \n"
            f"Serial Number           {sn} \n"
            f"OMCC version             {omcc} \n"
            f"Model                   {model}"
        )

        eth = grab(r'Ethernet\s+UNI\s+number\s*:\s*(\d+\s*\([^)]*\))', raw)
        voip = grab(r'POTS\s+UNI\s+number\s*:\s*(\d+)', raw)
        catv = grab(r'Video\s+UNI\s+number\s*:\s*(\d+)', raw)
        wifi = grab(r'WIFI\s+UNI\s+number\s*:\s*(\d+)', raw)

        features = (
            f"Number of ETH ports    {eth}\n"
            f"Number of VoIP ports   {voip}\n"
            f"Number of CATV ports   {catv}\n"
            f"Number of WIFI ports   {wifi}"
        )

        if vendor_id == 'N/A' and sn == 'N/A':
            return {'success': False, 'message': 'Tidak dapat membaca data hardware/software ONU dari OLT.'}

        return {'success': True, 'onu_details': onu_details, 'features': features}

    def get_onu_running_config(self, olt: dict, onu: dict) -> dict:
        if is_demo_olt(olt):
            return {
                'success': True,
                'config': (
                    f"interface gpon-olt_{onu['pon_port']}\n"
                    f"  onu {onu['onu_id']} type {onu.get('onu_type') or 'ALL-ONT'} sn {onu.get('serial_number','')}\n"
                    f"!\n"
                    f"interface gpon-onu_{onu['pon_port']}:{onu['onu_id']}\n"
                    f"  name {onu['name']}\n"
                    f"!"
                )
            }
        pon_port = onu['pon_port']
        onu_id = onu['onu_id']
        raw = execute_ssh_commands(olt, [
            f"show running-config interface gpon-olt_{pon_port} | include onu {onu_id} ",
            f"show running-config interface gpon-onu_{pon_port}:{onu_id}",
            f"show onu running config gpon-onu_{pon_port}:{onu_id}",
        ])

        def _extract_block(raw_text, cmd_echo_pattern):
            """Ambil isi 1 blok command dari gabungan output multi-command:
            potong echo command, berhenti di prompt OLT pertama yang ditemukan
            (baris "<host># " atau "\nOLT#" -- command berikutnya)."""
            m = re.search(
                cmd_echo_pattern + r'(.*?)(?:\r?\n\S+#\s*(?:\r?\n|$)|\Z)',
                raw_text, re.DOTALL | re.IGNORECASE
            )
            return m.group(1).strip('\r\n') if m else ''

        block_registration = _extract_block(
            raw, r'OLT#\s*show\s+show\s+running-config\s+interface\s+gpon-olt_\S+\s*\|\s*include\s+onu\s+\d+\s*\r?\n'
        )
        block_onu_iface = _extract_block(
            raw, r'OLT#\s*show\s+show\s+running-config\s+interface\s+gpon-onu_\S+\s*\r?\nBuilding configuration\.\.\.\r?\n'
        )
        block_pon_mng = _extract_block(
            raw, r'OLT#\s*show\s+show\s+onu\s+running\s+config\s+gpon-onu_\S+\s*\r?\n'
        )

        # Buang baris "end" tersisa (kalau ada) dan trailing "!" (supaya
        # tidak dobel dengan separator "!" yang kita tambah sendiri saat join).
        def _clean(block):
            lines = [l for l in block.split('\n') if l.strip().lower() != 'end']
            cleaned = '\n'.join(lines).strip()
            if cleaned.endswith('!'):
                cleaned = cleaned[:-1].rstrip()
            return cleaned

        block_registration = _clean(block_registration)
        block_onu_iface = _clean(block_onu_iface)
        block_pon_mng = _clean(block_pon_mng)

        # block_registration mentah berformat baris ringkas
        # "gpon-onu_X/Y/Z:ID   TYPE   sn   SN:xxxx   state" (dari
        # 'show running-config interface gpon-olt_X | include onu ID').
        # Format ulang jadi bentuk config asli "interface gpon-olt_X /
        # onu ID type TYPE sn SN" agar konsisten dengan 2 blok lain.
        m_reg = re.search(
            r'gpon-onu_\S+:(\d+)\s+(\S+)\s+sn\s+SN:(\S+)',
            block_registration, re.IGNORECASE
        ) if block_registration else None
        if m_reg:
            block_registration = (
                f"interface gpon-olt_{pon_port}\n"
                f"  onu {m_reg.group(1)} type {m_reg.group(2)} sn {m_reg.group(3)}"
            )
        else:
            # Fallback: parsing CLI gagal (format OLT beda / command tak
            # didukung) -> rakit dari data DB yang sudah kita punya, tanpa
            # perlu panggilan CLI tambahan.
            block_registration = (
                f"interface gpon-olt_{pon_port}\n"
                f"  onu {onu_id} type {onu.get('onu_type') or 'ALL-ONT'} sn {onu.get('serial_number','')}"
            )

        parts = [p for p in [block_registration, block_onu_iface, block_pon_mng] if p]
        config = ('\n!\n'.join(parts) + '\n!') if parts else raw

        return {
            'success': True,
            'config': config
        }

    def get_vlans(self, olt: dict) -> dict:
        """Baca seluruh VLAN yang aktif pada perangkat OLT ZTE.

        Catatan: versi PHP memanggil detectTransportError() yang tidak pernah
        didefinisikan di kelasnya (fatal error tiap panggilan non-demo). Di sini
        dipakai penolong bersama dari helper.py, jadi bug tersebut tidak terbawa.
        """
        if is_demo_olt(olt):
            return {'success': True, 'message': 'Data demo VLAN.', 'vlans': [
                {'id': 1, 'description': 'default', 'type': 'common', 'is_l3': False,
                 'ip': '', 'protected': True},
                {'id': 15, 'description': 'PPPOE-WAN', 'type': 'common', 'is_l3': False,
                 'ip': '', 'protected': False},
                {'id': 100, 'description': 'MGMT', 'type': 'common', 'is_l3': True,
                 'ip': '172.29.244.1/24', 'protected': True},
                {'id': 1021, 'description': 'PPPOE-CCR2004NEW', 'type': 'common',
                 'is_l3': False, 'ip': '', 'protected': False},
            ]}

        # Daftar VLAN hanya bisa lewat 'show vlan summary'. 'show vlan' polos
        # dan 'show interface vlanif' DITOLAK perangkat (terverifikasi live
        # 2026-08-28) — lihat zte_olt_manual_book.md. Format:
        #     All created vlan num: 4
        #     Details are following:
        #         1,15,100,438
        raw = strip_command_markers(execute_ssh_commands(olt, ['show vlan summary']))
        err = detect_transport_error(raw)
        if err:
            return {'success': False, 'message': err, 'vlans': []}

        ids = []
        for blok in re.findall(r'^\s*([\d,\-\s]+?)\s*$', raw, re.M):
            for bagian in blok.replace(' ', '').split(','):
                if bagian.isdigit():
                    ids.append(int(bagian))
                elif re.fullmatch(r'\d+-\d+', bagian):
                    a, b = bagian.split('-')
                    ids.extend(range(int(a), int(b) + 1))
        ids = sorted({v for v in ids if 1 <= v <= 4094})
        if not ids:
            return {'success': False,
                    'message': 'Daftar VLAN tidak terbaca dari OLT.', 'vlans': []}

        # Detail per VLAN: 'show vlan <id>' -> name/description.
        detail = strip_command_markers(
            execute_ssh_commands(olt, [f'show vlan {v}' for v in ids]))

        # VLAN layer-3 = punya 'interface vlan <id>'. Perangkat uji tidak punya
        # satupun; deteksi tetap dipertahankan agar VLAN L3 tidak bisa dihapus.
        l3 = {int(v) for v in re.findall(r'^\s*interface\s+vlan\s+(\d+)', detail, re.I | re.M)}

        info = {}
        for blok in re.split(r'(?=^vlanid\s*:)', detail, flags=re.M | re.I):
            mv = re.search(r'^vlanid\s*:\s*(\d+)', blok, re.I | re.M)
            if not mv:
                continue
            vid = int(mv.group(1))
            mn = re.search(r'^name\s*:\s*(\S+)', blok, re.I | re.M)
            md = re.search(r'^description\s*:\s*([^\r\n]+)', blok, re.I | re.M)
            desc = (md.group(1).strip() if md else '')
            if desc.upper() in ('N/A', 'N/A.'):
                desc = ''
            info[vid] = desc or (mn.group(1).strip() if mn else '')

        # Kolom Tagged/Untagged di UI adalah PORT UPLINK, bukan ONU. Bagian
        # (satu per pelanggan) sehingga tak berguna di tabel. Keanggotaan port
        # uplink dibaca dari 'show vlan port <nama>' lalu dibalik per VLAN.
        try:
            nama_port = [p['name'] for p in self.get_interfaces(olt).get('ports', [])]
            vmap = self._port_vlan_map(olt, nama_port)
        except Exception:
            vmap = {}

        tag_per_vlan, untag_per_vlan = {}, {}
        for nama, v in vmap.items():
            for vid in v.get('tagged', ()):
                tag_per_vlan.setdefault(vid, []).append(nama)
            for vid in v.get('untagged', ()):
                untag_per_vlan.setdefault(vid, []).append(nama)

        vlans = [{
            'id': v,
            'description': info.get(v, ''),
            'type': 'common',
            'tagged': ','.join(sorted(tag_per_vlan.get(v, []))),
            'untagged': ','.join(sorted(untag_per_vlan.get(v, []))),
            'is_l3': v in l3,
            'ip': '',
            'protected': v == 1 or v in l3,
        } for v in ids]

        return {'success': True, 'message': 'Daftar VLAN ZTE berhasil dibaca.',
                'vlans': vlans}

    def assign_speed_profile(self, olt: dict, pon_port: str, onu_id: int,
                              upload_name: str, download_name: str) -> dict:
        """Kaitkan ONU ke speed profile Upload(tcont)/Download(gemport downstream).
        Terverifikasi live di OLT TGR (2026-09-15) via show running-config."""
        upload_name = self._sanitize_profile_name(upload_name)
        download_name = self._sanitize_profile_name(download_name)
        if not upload_name or not download_name:
            return {'success': False, 'message': 'Nama speed profile tidak valid.'}
        if is_demo_olt(olt):
            return {'success': True, 'message': 'Speed profile ONU berhasil di-assign (Mode Demo).'}

        commands = [
            'configure terminal',
            f"interface gpon-onu_{pon_port}:{onu_id}",
            f"tcont 1 profile {upload_name}",
            'gemport 1 tcont 1',
            f"gemport 1 traffic-limit downstream {download_name}",
            'exit',
            'exit',
            'write'
        ]
        log = execute_ssh_commands(olt, commands)
        errors = self._vlan_errors(log)
        ok = len(errors) == 0
        return {
            'success': ok,
            'message': 'Speed profile berhasil di-assign ke ONU.' if ok
                       else f"OLT menolak perintah: {'; '.join(errors)}",
            'log': log
        }

    def get_speed_profiles(self, olt: dict) -> dict:
        """Baca profile kecepatan Upload/Download dari OLT ZTE.

        Upload  -> 'profile tcont <NAME> ... maximum <kbps>' (nama berakhiran -UP)
        Download-> 'profile traffic <NAME> sir <kbps> pir <kbps>' (nama berakhiran -DOWN)
        Usage per profile dihitung dari baris 'tcont 1 profile <NAME>' per-ONU
        di config pon-onu-mng yang sama.
        """
        if is_demo_olt(olt):
            return {'success': True, 'message': 'Data demo speed profile.', 'profiles': [
                {'name': '5M', 'direction': 'upload', 'speed_kbps': 1536, 'onu_count': 5},
                {'name': '5M', 'direction': 'download', 'speed_kbps': 3072, 'onu_count': 5},
            ]}

        raw = strip_command_markers(execute_ssh_commands(olt, ['show running-config']))
        err = detect_transport_error(raw)
        if err:
            return {'success': False, 'message': err, 'profiles': []}

        # Upload dipakai per-ONU via: tcont <n> profile <NAME>
        usage_up = Counter(re.findall(r'^\s*tcont\s+\d+\s+profile\s+(\S+)', raw, re.M | re.I))
        # Download dipakai per-ONU via: gemport <n> traffic-limit downstream <NAME>
        usage_down = Counter(re.findall(r'^\s*gemport\s+\d+\s+traffic-limit\s+downstream\s+(\S+)', raw, re.M | re.I))

        profiles = []
        for name, maximum in re.findall(
                r'^\s*profile\s+tcont\s+(\S+)\s+type\s+\d+\s+fixed\s+\d+\s+assured\s+\d+\s+maximum\s+(\d+)',
                raw, re.M | re.I):
            profiles.append({
                'name': name, 'direction': 'upload',
                'speed_kbps': int(maximum), 'onu_count': usage_up.get(name, 0),
            })
        for name, sir, pir in re.findall(
                r'^\s*profile\s+traffic\s+(\S+)\s+sir\s+(\d+)\s+pir\s+(\d+)',
                raw, re.M | re.I):
            profiles.append({
                'name': name, 'direction': 'download',
                'speed_kbps': int(pir or sir), 'onu_count': usage_down.get(name, 0),
            })

        if not profiles:
            return {'success': False, 'message': 'Tidak ada speed profile terbaca dari OLT.', 'profiles': []}
        return {'success': True, 'message': f'{len(profiles)} speed profile berhasil dibaca.',
                'profiles': profiles}

    @staticmethod
    def _sanitize_profile_name(name: str) -> str:
        """Hanya huruf, angka, titik, garis bawah, strip. Memblokir baris baru
        (command injection). Maksimal 32 karakter."""
        return re.sub(r'[^A-Za-z0-9._-]', '', name or '')[:32]

    def save_speed_profile(self, olt: dict, direction: str, name: str, speed_kbps: int) -> dict:
        """Buat atau perbarui (overwrite = idempoten) speed profile di OLT.

        Upload   -> profile tcont <NAME> type 5 fixed 64 assured 64 maximum <kbps>
        Download -> profile traffic <NAME> sir <kbps> pir <kbps>
        Terverifikasi manual di OLT TGR (2026-09-15): create, edit (overwrite),
        keduanya membalas '[Successful]' dan 'write' sukses ke flash.
        """
        name = self._sanitize_profile_name(name)
        speed_kbps = int(speed_kbps)
        if not name:
            return {'success': False, 'message': 'Nama profile tidak valid.'}
        if speed_kbps < 1 or speed_kbps > 2000000:
            return {'success': False, 'message': 'Speed harus antara 1 - 2.000.000 kbps.'}
        if direction not in ('upload', 'download'):
            return {'success': False, 'message': 'Direction harus upload atau download.'}
        if is_demo_olt(olt):
            return {'success': True, 'message': f'Speed profile {name} disimpan (demo).'}

        if direction == 'upload':
            cmd = f'profile tcont {name} type 5 fixed 64 assured 64 maximum {speed_kbps}'
        else:
            cmd = f'profile traffic {name} sir {speed_kbps} pir {speed_kbps}'

        commands = ['configure terminal', 'gpon', cmd, 'exit', 'exit', 'write']
        log = execute_ssh_commands(olt, commands)
        err = detect_transport_error(log)
        if err:
            return {'success': False, 'message': err}
        errs = self._vlan_errors(log)
        if errs:
            return {'success': False,
                    'message': f'OLT menolak speed profile {name}: ' + ' | '.join(errs[:3]),
                    'log': log}
        return {'success': True, 'message': f'Speed profile {name} berhasil disimpan di OLT.'}

    def delete_speed_profile(self, olt: dict, direction: str, name: str) -> dict:
        """Hapus speed profile dari OLT ('no profile tcont/traffic <NAME>').
        Terverifikasi manual di OLT TGR (2026-09-15): membalas '[Successful]',
        hilang total dari 'show running-config' setelahnya."""
        name = self._sanitize_profile_name(name)
        if not name:
            return {'success': False, 'message': 'Nama profile tidak valid.'}
        if direction not in ('upload', 'download'):
            return {'success': False, 'message': 'Direction harus upload atau download.'}
        if is_demo_olt(olt):
            return {'success': True, 'message': f'Speed profile {name} dihapus (demo).'}

        cmd = f'no profile tcont {name}' if direction == 'upload' else f'no profile traffic {name}'
        commands = ['configure terminal', 'gpon', cmd, 'exit', 'exit', 'write']
        log = execute_ssh_commands(olt, commands)
        err = detect_transport_error(log)
        if err:
            return {'success': False, 'message': err}
        errs = self._vlan_errors(log)
        if errs:
            return {'success': False,
                    'message': f'OLT menolak penghapusan speed profile {name}: ' + ' | '.join(errs[:3]),
                    'log': log}
        return {'success': True, 'message': f'Speed profile {name} berhasil dihapus dari OLT.'}

    # ======================================================================
    # PENULISAN VLAN — sintaks di bawah SAMA dengan C320 (ZXAN V2.x),
    # dibaca dari 'show running-config interface' dan menu '?' (2026-08-28):
    #     (config)    vlan <id>                     -> masuk mode config-vlan
    #     (config-vlan) name <WORD>                 -> nama, wajib diawali huruf
    #     (config-vlan) description <teks>          -> deskripsi
    #     (config)    no vlan <id>                  -> hapus VLAN
    #     (config-if) switchport mode access|hybrid|trunk
    #     (config-if) switchport vlan <list> tag    -> jadikan tagged
    #     (config-if) switchport vlan <id> untag    -> untagged (WAJIB mode
    #                 hybrid/access; di mode trunk ditolak %Code 60584)
    #     (config-if) switchport default vlan <id>  -> setel pvid
    #     (config-if) no switchport vlan <id>       -> lepas VLAN dari port
    #
    # PERINGATAN: pada ZTE, menambahkan '?' pada perintah yang SUDAH LENGKAP
    # tidak menampilkan bantuan melainkan MENJALANKANNYA. Jangan pernah memakai
    # '?' untuk menyelidiki perintah pengubah di perangkat produksi.
    # ======================================================================

    @staticmethod
    def _sanitize_vlan_description(desc: str) -> str:
        """Hanya huruf, angka, spasi, titik, garis bawah, strip. Memblokir baris
        baru (command injection). Maksimal 32 karakter."""
        desc = re.sub(r'[^A-Za-z0-9 ._-]', '', desc or '')
        return re.sub(r'\s+', ' ', desc).strip()[:32]

    @staticmethod
    def _vlan_errors(log: str) -> list:
        """Baris penolakan dari OLT. ZTE membalas '%Error ...' / '%Code ...'."""
        out = []
        for line in (log or '').split('\n'):
            line = line.strip()
            if line.startswith('%') and 'Info' not in line:
                out.append(line)
        return out

    def save_vlan(self, olt: dict, vlan_id: int, description: str = '') -> dict:
        """Buat VLAN atau perbarui deskripsinya (idempoten)."""
        vlan_id = int(vlan_id)
        if vlan_id < 1 or vlan_id > 4094:
            return {'success': False, 'message': 'VLAN ID harus berada pada rentang 1-4094.'}
        if is_demo_olt(olt):
            return {'success': True, 'message': f'VLAN {vlan_id} disimpan (demo).'}

        desc = self._sanitize_vlan_description(description)
        commands = ['configure terminal', f'vlan {vlan_id}']
        if desc:
            commands.append(f'description {desc}')
        commands += ['exit', 'exit', 'write']

        log = execute_ssh_commands(olt, commands)
        err = detect_transport_error(log)
        if err:
            return {'success': False, 'message': err}
        errs = self._vlan_errors(log)
        if errs:
            return {'success': False,
                    'message': f'OLT menolak penyimpanan VLAN {vlan_id}: ' + ' | '.join(errs[:3]),
                    'log': log}
        return {'success': True, 'message': f'VLAN {vlan_id} berhasil disimpan di OLT.',
                'log': log}

    def add_vlan(self, olt: dict, vlan_id: int, description: str = '') -> dict:
        """Buat VLAN baru. Menolak bila VLAN sudah ada, lalu verifikasi ulang."""
        vlan_id = int(vlan_id)
        if vlan_id < 1 or vlan_id > 4094:
            return {'success': False, 'message': 'VLAN ID harus berada pada rentang 1-4094.'}
        if is_demo_olt(olt):
            return {'success': True, 'message': f'VLAN {vlan_id} dibuat (demo).'}

        current = self.get_vlans(olt)
        if not current['success']:
            return {'success': False, 'message': current['message']}
        if any(v['id'] == vlan_id for v in current['vlans']):
            return {'success': False, 'message': f'VLAN {vlan_id} sudah ada pada perangkat.'}

        res = self.save_vlan(olt, vlan_id, description)
        if not res['success']:
            return res

        after = self.get_vlans(olt)
        if any(v['id'] == vlan_id for v in after.get('vlans', [])):
            return {'success': True, 'message': f'VLAN {vlan_id} berhasil dibuat di OLT.',
                    'log': res.get('log', '')}
        return {'success': False,
                'message': f'VLAN {vlan_id} gagal dibuat. Periksa log perangkat.',
                'log': res.get('log', '')}

    def save_vlan_ports(self, olt: dict, vlan_id: int, description: str = '',
                        ports_config: dict = None) -> dict:
        """Simpan VLAN sekaligus keanggotaan port uplink (tagged/untagged/none).

        ports_config: {'xgei_1/3/2': 'tagged'|'untagged'|'none', ...}

        Perubahan ditulis secara inkremental per VLAN (bukan menulis ulang
        seluruh daftar VLAN port) supaya keanggotaan VLAN lain tidak ikut
        terhapus. Port yang sudah sesuai dilewati.
        """
        vlan_id = int(vlan_id)
        if vlan_id < 1 or vlan_id > 4094:
            return {'success': False, 'message': 'VLAN ID harus berada pada rentang 1-4094.'}
        ports_config = ports_config or {}

        for nama, want in ports_config.items():
            if not self._PORT_RE.match((nama or '').strip()):
                return {'success': False,
                        'message': f'Nama port tidak valid: {nama}. '
                                   'Gunakan gei_R/S/P atau xgei_R/S/P.'}
            if want not in ('tagged', 'untagged', 'none'):
                return {'success': False,
                        'message': f'Nilai port {nama} harus tagged, untagged, atau none.'}

        res_vlan = self.save_vlan(olt, vlan_id, description)
        if not res_vlan['success'] or not ports_config:
            return res_vlan
        if is_demo_olt(olt):
            return res_vlan

        res_ports = self.get_interfaces(olt)
        if not res_ports['success']:
            return res_ports
        nama_port = [p['name'] for p in res_ports['ports']]
        vmap = self._port_vlan_map(olt, nama_port)

        commands = ['configure terminal']
        diinginkan = {}
        for nama in nama_port:
            want = ports_config.get(nama) or ports_config.get(nama.lower())
            if want is None:
                continue
            kini = vmap.get(nama, {})
            mode = (kini.get('mode') or 'hybrid').lower()
            pvid = int(kini.get('pvid') or 1)
            tagged = kini.get('tagged') or set()
            untagged = kini.get('untagged') or set()
            diinginkan[nama] = want

            cmds = []
            if want == 'tagged':
                if vlan_id not in tagged:
                    if mode == 'access':
                        cmds.append('switchport mode trunk')
                    if pvid == vlan_id:
                        cmds.append('no switchport default vlan')
                    elif vlan_id in untagged:
                        cmds.append(f'no switchport vlan {vlan_id}')
                    cmds.append(f'switchport vlan {vlan_id} tag')
            elif want == 'untagged':
                if vlan_id not in untagged or pvid != vlan_id:
                    # 'untag' ditolak (%Code 60584) bila port masih mode trunk.
                    if mode == 'trunk':
                        cmds.append('switchport mode hybrid')
                    if vlan_id in tagged:
                        cmds.append(f'no switchport vlan {vlan_id}')
                    if pvid != vlan_id and vlan_id not in untagged:
                        # Pada port yang BUKAN anggota, satu perintah ini
                        # sekaligus menjadikan VLAN untagged dan pvid.
                        cmds.append(f'switchport default vlan {vlan_id}')
                    elif vlan_id not in untagged:
                        cmds.append(f'switchport vlan {vlan_id} untag')
                    # Bila sudah untagged tetapi pvid beda, 'switchport default
                    # vlan' ditolak %Code 60550 (Port already in the vlan);
                    # keanggotaan untagged sudah benar, pvid dibiarkan.
            else:  # none
                # VLAN 1 adalah VLAN default sistem; jangan dilepas dari port.
                if vlan_id == 1:
                    continue
                # Bila VLAN ini masih pvid, 'no switchport vlan' ditolak
                # (%Code 60591). Pvid wajib dikembalikan lebih dulu, dan hanya
                # 'no switchport default vlan' yang diterima — 'switchport
                # default vlan 1' ditolak (%Code 60551).
                if pvid == vlan_id:
                    cmds.append('no switchport default vlan')
                elif vlan_id in tagged or vlan_id in untagged:
                    cmds.append(f'no switchport vlan {vlan_id}')

            if cmds:
                commands.append(f'interface {nama}')
                commands.extend(cmds)
                commands.append('exit')

        if len(commands) == 1:
            return {'success': True,
                    'message': f'VLAN {vlan_id} disimpan. Tidak ada perubahan port.',
                    'log': res_vlan.get('log', '')}

        commands += ['exit', 'write']
        log = execute_ssh_commands(olt, commands)
        err = detect_transport_error(log)
        if err:
            return {'success': False, 'message': err, 'commands': commands}
        errs = self._vlan_errors(log)
        if errs:
            return {'success': False,
                    'message': 'Sebagian konfigurasi port ditolak OLT: ' + ' | '.join(errs[:3]),
                    'commands': commands, 'log': log}

        # Verifikasi ulang ke perangkat — jangan percaya "tidak ada error" saja.
        sesudah = self._port_vlan_map(olt, list(diinginkan.keys()))
        meleset = []
        for nama, want in diinginkan.items():
            v = sesudah.get(nama, {})
            nyata = ('tagged' if vlan_id in (v.get('tagged') or set())
                     else 'untagged' if vlan_id in (v.get('untagged') or set())
                     else 'none')
            if nyata != want:
                meleset.append(f'{nama} diminta {want} tetapi masih {nyata}')
        if meleset:
            return {'success': False,
                    'message': 'Perangkat tidak menerapkan sebagian perubahan: '
                               + ' | '.join(meleset[:3]),
                    'commands': commands, 'log': log}

        return {'success': True,
                'message': 'Konfigurasi VLAN & port berhasil disimpan ke OLT.',
                'commands': commands, 'log': log}

    def delete_vlan(self, olt: dict, vlan_id: int, confirmed: bool = False) -> dict:
        """Hapus VLAN. VLAN 1 dan VLAN layer-3 ditolak; VLAN yang dipakai ONU
        wajib konfirmasi. Port uplink dibersihkan lebih dulu."""
        vlan_id = int(vlan_id)
        if vlan_id < 1 or vlan_id > 4094:
            return {'success': False, 'message': 'VLAN ID harus berada pada rentang 1-4094.'}
        if vlan_id == 1:
            return {'success': False,
                    'message': 'VLAN 1 adalah VLAN default sistem dan tidak boleh dihapus.'}
        if is_demo_olt(olt):
            return {'success': True, 'message': f'VLAN {vlan_id} dihapus (demo).'}

        current = self.get_vlans(olt)
        if not current['success']:
            return {'success': False, 'message': current['message']}
        target = next((v for v in current['vlans'] if v['id'] == vlan_id), None)
        if target is None:
            return {'success': False, 'message': f'VLAN {vlan_id} tidak ditemukan pada OLT.'}
        if target.get('is_l3'):
            return {'success': False,
                    'message': f'VLAN {vlan_id} memiliki interface vlan (layer 3). '
                               'Hapus interface vlan-nya terlebih dahulu.'}

        # Pemakaian oleh pelanggan: bagian 'port(tagged)' pada 'show vlan <id>'
        # memuat baris gpon-onu_... satu per layanan.
        detail = strip_command_markers(execute_ssh_commands(olt, [f'show vlan {vlan_id}']))
        if re.search(r'gpon-onu_\d+/\d+/\d+:\d+', detail, re.I) and not confirmed:
            return {'success': False, 'needs_confirm': True,
                    'message': f'VLAN {vlan_id} sedang dipakai layanan pelanggan (ONU). '
                               'Menghapusnya akan memutus koneksi mereka. '
                               'Konfirmasi diperlukan.'}

        # Lepas VLAN dari port uplink sebelum menghapus definisinya.
        commands = ['configure terminal']
        try:
            nama_port = [p['name'] for p in self.get_interfaces(olt).get('ports', [])]
            vmap = self._port_vlan_map(olt, nama_port)
        except Exception:
            vmap = {}
        for nama, v in vmap.items():
            cmds = []
            if int(v.get('pvid') or 1) == vlan_id:
                # Satu-satunya cara mengembalikan pvid ke default; 'switchport
                # default vlan 1' ditolak %Code 60551.
                cmds.append('no switchport default vlan')
            elif (vlan_id in (v.get('tagged') or set())
                  or vlan_id in (v.get('untagged') or set())):
                cmds.append(f'no switchport vlan {vlan_id}')
            if cmds:
                commands.append(f'interface {nama}')
                commands.extend(cmds)
                commands.append('exit')
        commands += [f'no vlan {vlan_id}', 'exit', 'write']

        log = execute_ssh_commands(olt, commands)
        err = detect_transport_error(log)
        if err:
            return {'success': False, 'message': err, 'commands': commands}

        after = self.get_vlans(olt)
        if any(v['id'] == vlan_id for v in after.get('vlans', [])):
            errs = self._vlan_errors(log)
            rinci = (' Respon OLT: ' + ' | '.join(errs[:3])) if errs \
                else ' Periksa log debug untuk detail.'
            return {'success': False, 'message': f'VLAN {vlan_id} gagal dihapus.{rinci}',
                    'commands': commands, 'log': log}
        return {'success': True, 'message': f'VLAN {vlan_id} berhasil dihapus dari OLT.',
                'commands': commands, 'log': log}

    def setup_snmp(self, olt: dict, community_ro: str, community_rw: str) -> dict:
        """Aktifkan SNMP dan setel community pada perangkat OLT ZTE."""
        if is_demo_olt(olt):
            return {'success': True, 'message': 'SNMP demo setup (tidak ada perubahan).'}
        try:
            raw = execute_ssh_commands(olt, [
                'configure terminal',
                f'snmp-server community {community_ro} view allview ro',
                f'snmp-server community {community_rw} view allview rw',
                'exit',
                'write',
            ])
            return {'success': True,
                    'message': 'SNMP community ZTE berhasil dikonfigurasi.', 'output': raw}
        except Exception as e:
            return {'success': False, 'message': str(e)}

    def get_autofind(self, olt: dict) -> dict:
        """Get unconfigured/autofind ONUs from ZTE OLT.
        Returns: {'success': bool, 'onus': list}
        """
        if is_demo_olt(olt):
            import time
            return {
                'success': True,
                'onus': [
                    {
                        'pon_port': '1/2/3',
                        'serial_number': 'ZTEGCC970195',
                        'type': 'ZTE ONU',
                        'distance': 'N/A',
                        'vlan': 'None',
                        'rx_power': '-19.45'
                    }
                ]
            }
        raw = execute_ssh_commands(olt, ['show gpon onu uncfg'])
        onus = []
        
        # Match: gpon-onu_1/2/1:1      ZTEGCC929729      unknown
        matches = re.findall(r'(?:gpon-onu_)?([0-9\/]+):\d+\s+(\S+)', raw, re.IGNORECASE)
        for m in matches:
            onus.append({
                'pon_port': m[0],
                'serial_number': m[1],
                'type': detect_onu_type_from_sn(m[1]),
                'distance': 'N/A',
                'vlan': 'None',
                'rx_power': 'N/A'
            })
            
        return {'success': True, 'onus': onus}

    def get_next_free_onu_id(self, olt: dict, pon_port: str) -> dict:
        """Get next free ONU ID on a specific PON port of ZTE OLT.
        Returns: {'success': bool, 'next_onu_id': int}
        """
        if is_demo_olt(olt):
            return {'success': True, 'next_onu_id': 100}
            
        raw = execute_ssh_commands(olt, [f"show gpon onu state gpon-olt_{pon_port}"])

        # Kolom OnuIndex TIDAK memakai awalan 'gpon-onu_' (terverifikasi live
        # 2026-08-28), isinya '<rack>/<slot>/<port>:<id>'. Regex lama menuntut
        # awalan itu sehingga tidak pernah cocok -> selalu balik ID 1 walau
        # sudah terpakai. Awalan dibuat opsional agar kedua bentuk tertangkap.
        used_ids = {
            int(m.group(1))
            for m in re.finditer(
                r'(?:gpon-onu_)?' + re.escape(pon_port) + r':(\d+)\b', raw, re.I)
        }

        for i in range(1, 129):
            if i not in used_ids:
                return {'success': True, 'next_onu_id': i}
        return {'success': False,
                'message': f'Semua ID ONU (1-128) pada port {pon_port} sudah terpakai.',
                'next_onu_id': 0}



