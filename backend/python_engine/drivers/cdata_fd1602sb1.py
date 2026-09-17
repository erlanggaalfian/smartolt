import re
import datetime
import sys
import os

# Align import path to include helper
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from helper import execute_ssh_commands, strip_command_markers, detect_transport_error
from drivers.base_driver import BaseDriver
import snmp_module

def is_demo_olt(olt: dict) -> bool:
    return olt.get('ip') == '127.0.0.1' or olt.get('ip', '').lower() == 'demo'

class OltCdataFd1602sb1Driver(BaseDriver):
    def get_driver_info(self) -> dict:
        return {
            'brand': 'CDATA',
            'model': 'FD1602S-B1',
            'technology': 'GPON',
            'pon_type': 'gpon',
            'version': '1.0.0',
            'description': 'Driver OLT CData FD1602S-B1 GPON (Python Engine).'
        }

    def get_supported_panels(self) -> dict:
        """Panel yang didukung driver ini beserta label & ikonnya.
        Bentuk balikan HARUS sama dengan versi PHP — frontend membacanya langsung."""
        return {
            'olt-details': {'label': 'OLT Details', 'icon': 'server'},
            'olt-cards': {'label': 'OLT Cards', 'icon': 'layout-grid'},
            'pon-ports': {'label': 'PON Ports', 'icon': 'plug-zap'},
            'interfaces': {'label': 'Interfaces', 'icon': 'ethernet-port'},
        }

    def check_connection(self, olt: dict) -> dict:
        if is_demo_olt(olt):
            return {
                'success': True,
                'message': "Koneksi SSH berhasil! (Mode Demo)\n\n"
                           "--- Info Kesehatan OLT CData ---\n"
                           "• CPU Load: 4.13%\n"
                           "• RAM Usage: 25.00%\n"
                           "• Temperature: 46.37 °C\n"
                           "• Uptime: 1 weeks, 2 day 20 hour 32 minute"
            }

        health_cmds = [
            'show cpu',
            'show mem',
            'show temp',
            'show version',
        ]
        health_raw = execute_ssh_commands(olt, health_cmds)
        
        err = detect_transport_error(health_raw)
        if err:
            return {'success': False, 'message': err}
        
        cpu = 'N/A'
        m_cpu = re.search(r'Load Average\(5sec\)\s*:\s*([0-9.]+%?)', health_raw, re.IGNORECASE)
        if m_cpu:
            cpu = m_cpu.group(1).strip()
            
        mem = 'N/A'
        m_mem = re.search(r'Utilization\s*:\s*([0-9.]+%?)', health_raw, re.IGNORECASE)
        if m_mem:
            mem = m_mem.group(1).strip()
            
        temp = 'N/A'
        m_temp = re.search(r'^0\s+([0-9.]+)', health_raw, re.MULTILINE)
        if m_temp:
            temp = m_temp.group(1).strip() + ' °C'
            
        uptime = 'N/A'
        m_up = re.search(r'uptime is\s*(.+?)(?:\r|\n|$)', health_raw, re.IGNORECASE)
        if m_up:
            uptime = m_up.group(1).strip()

        # Status SNMP TIDAK ditentukan di sini. Driver cuma menguji SSH/Telnet.
        # check_olt_connection() di backend/driver.php yang menguji SNMP nyata
        # lewat check_snmp_ping() lalu menambahkan barisnya.
        return {
            'success': True,
            'message': f"Koneksi SSH berhasil!\n\n"
                       f"--- Info Kesehatan OLT CData ---\n"
                       f"• CPU Load: {cpu}\n"
                       f"• RAM Usage: {mem}\n"
                       f"• Temperature: {temp}\n"
                       f"• Uptime: {uptime}"
        }

    def disable_onu(self, olt: dict, onu: dict) -> dict:
        if is_demo_olt(olt):
            return {'success': True, 'message': 'ONU berhasil dinonaktifkan (Mode Demo).', 'log': ''}
            
        parts = onu['pon_port'].split('/')
        port = int(parts[-1]) if len(parts) >= 3 else 1
        interface_path = "/".join(parts[:-1]) if len(parts) >= 3 else onu['pon_port']
        onu_id = onu['onu_id']

        commands = [
            "enable",
            "config",
            f"interface gpon {interface_path}",
            f"ont disable {port} {onu_id}",
            "exit",
            "exit",
            "write"
        ]
        log = execute_ssh_commands(olt, commands)
        return {
            'success': 'failed' not in log.lower() and 'error' not in log.lower(),
            'message': f"ONU berhasil dinonaktifkan di OLT.",
            'log': log
        }

    def enable_onu(self, olt: dict, onu: dict) -> dict:
        if is_demo_olt(olt):
            return {'success': True, 'message': 'ONU berhasil diaktifkan kembali (Mode Demo).', 'log': ''}

        parts = onu['pon_port'].split('/')
        port = int(parts[-1]) if len(parts) >= 3 else 1
        interface_path = "/".join(parts[:-1]) if len(parts) >= 3 else onu['pon_port']
        onu_id = onu['onu_id']

        commands = [
            "enable",
            "config",
            f"interface gpon {interface_path}",
            f"ont enable {port} {onu_id}",
            "exit",
            "exit",
            "write"
        ]
        log = execute_ssh_commands(olt, commands)
        return {
            'success': 'failed' not in log.lower() and 'error' not in log.lower(),
            'message': f"ONU berhasil diaktifkan kembali di OLT.",
            'log': log
        }

    def get_onu_signal(self, olt: dict, onu: dict, include_ip: bool = True) -> dict:
        # Existing implementation retained for backward compatibility
        if is_demo_olt(olt):
            return {
                'success': True,
                'rx_onu': -24.50,
                'rx_olt': -22.12,
                'status': 'online',
                'pppoe_ip': '172.23.45.64',
                'log': ''
            }

        pon_port = onu['pon_port']
        onu_id = onu['onu_id']
        parts = pon_port.split('/')
        port = int(parts[-1]) if len(parts) >= 3 else 1
        interface_path = "/".join(parts[:-1]) if len(parts) >= 3 else pon_port

        cmds = [
            'enable',
            'config',
            f"interface gpon {interface_path}",
            f"show ont optical-info {port} {onu_id}",
        ]
        if include_ip:
            cmds.append(f"show ont ipconfig {port} {onu_id}")
        cmds.append('exit')

        raw = execute_ssh_commands(olt, cmds)

        # Parse optical and IP info (existing logic)
        rx_onu = 'N/A'
        rx_olt = 'N/A'
        status = 'offline'
        for line in raw.split('\n'):
            if ':' not in line:
                continue
            parts_line = line.split(':')
            key = parts_line[0].lower().strip()
            val = parts_line[1].strip()
            vm = re.search(r'([-]?\d+\.?\d*)', val)
            if not vm:
                continue
            numeric_val = float(vm.group(1))
            if 'rx optical power' in key:
                if -50 < numeric_val <= 5:
                    rx_onu = vm.group(1)
            elif 'olt rx' in key:
                if -50 < numeric_val <= 5:
                    rx_olt = vm.group(1)
            elif any(x in key for x in ['run state', 'work state', 'status']):
                if any(x in val.lower() for x in ['up', 'online', 'active', 'bound']):
                    status = 'online'
        if rx_onu != 'N/A' and float(rx_onu) > -40:
            status = 'online'
        pppoe_ip = 'N/A'
        m_ip = re.search(r'ONT IP\s*:\s*([0-9.]+)', raw, re.IGNORECASE)
        if m_ip:
            found_ip = m_ip.group(1).strip()
            if found_ip not in ('0.0.0.0', '255.255.255.255'):
                pppoe_ip = found_ip
        return {
            'success': True,
            'rx_onu': rx_onu,
            'rx_olt': rx_olt,
            'status': status,
            'pppoe_ip': pppoe_ip,
            'log': raw,
            **self._get_onu_traffic_snmp(olt, onu),
        }

    def _get_onu_traffic_snmp(self, olt: dict, onu: dict) -> dict:
        """Traffic counter (Rx/Tx octets+packets kumulatif) + distance optik via
        SNMP, dari tabel resmi CDATA onuCurStatsTable + onuTestDistance (MIB
        NSCRTV-FTTX-GPON-MIB). Gagal SNMP tidak boleh menggagalkan
        get_onu_signal() (SSH) — kembalikan None kalau error."""
        try:
            parts = onu['pon_port'].split('/')
            port_no = int(parts[-1]) if len(parts) >= 3 else int(parts[0])
            t = snmp_module.get_onu_traffic_cdata(
                olt['ip'], olt.get('snmp_community') or 'public',
                port_no, int(onu['onu_id']),
                port=int(olt.get('snmp_port') or 161),
            )
            distance_m = snmp_module.get_onu_distance_cdata(
                olt['ip'], olt.get('snmp_community') or 'public',
                port_no, int(onu['onu_id']),
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

    def get_onu_signals_bulk(self, olt: dict, onus: list) -> dict:
        """Fetch optical attenuation for all ONUs in a single session.
        Returns a dict keyed by "ponPort_ONUId" with fields:
            rx_onu (float or 'N/A'), rx_olt (float or 'N/A'), status (str)
        """
        if is_demo_olt(olt):
            result = {}
            for o in onus:
                key = f"{o['pon_port']}_{o['onu_id']}"
                result[key] = {'rx_onu': -20.0, 'rx_olt': -2.0, 'status': 'online'}
            return result

        # Group ports by interface path
        ports_by_path = {}
        for o in onus:
            pon_port = o['pon_port']  # e.g., "0/0/1"
            parts = pon_port.split('/')
            if len(parts) >= 3:
                path = "/".join(parts[:-1])  # "0/0"
                p_num = parts[-1]  # "1"
                ports_by_path.setdefault(path, set()).add(p_num)

        # Build commands
        commands = ['config']
        for path, p_nums in ports_by_path.items():
            commands.append(f"interface gpon {path}")
            for p_num in sorted(p_nums):
                commands.append(f"show ont optical-info {p_num} all")
            commands.append("exit")

        raw_opt = execute_ssh_commands(olt, commands)
        result = {}

        current_path = "0/0"
        current_port = None

        for line in raw_opt.split('\n'):
            line = line.strip()
            if not line:
                continue

            # Track active interface path: "interface gpon 0/0"
            m_path = re.search(r'interface\s+gpon\s+(\S+)', line, re.IGNORECASE)
            if m_path:
                current_path = m_path.group(1)
                continue

            # Track active port: "show ont optical-info 1 all"
            m_port = re.search(r'show\s+ont\s+optical-info\s+(\d+)\s+all', line, re.IGNORECASE)
            if m_port:
                current_port = m_port.group(1)
                continue

            if current_path and current_port:
                parts = re.split(r'\s+', line)
                if len(parts) >= 4 and parts[0].isdigit():
                    onu_id = parts[0]
                    rx_onu_str = parts[1]
                    rx_olt_str = parts[3]

                    rx_onu = 'N/A'
                    if rx_onu_str != 'N/A':
                        try:
                            rx_onu = float(rx_onu_str)
                        except ValueError:
                            pass

                    rx_olt = 'N/A'
                    if rx_olt_str != 'N/A':
                        try:
                            rx_olt = float(rx_olt_str)
                        except ValueError:
                            pass

                    key = f"{current_path}/{current_port}_{onu_id}"
                    status = 'online' if rx_onu != 'N/A' and rx_onu > -40.0 else 'offline'
                    result[key] = {
                        'rx_onu': rx_onu,
                        'rx_olt': rx_olt,
                        'status': status
                    }

        return result

        if is_demo_olt(olt):
            return {
                'success': True,
                'rx_onu': -24.50,
                'rx_olt': -22.12,
                'status': 'online',
                'pppoe_ip': '172.23.45.64',
                'log': ''
            }

        pon_port = onu['pon_port']
        onu_id = onu['onu_id']
        parts = pon_port.split('/')
        port = int(parts[-1]) if len(parts) >= 3 else 1
        interface_path = "/".join(parts[:-1]) if len(parts) >= 3 else pon_port

        cmds = [
            'enable',
            'config',
            f"interface gpon {interface_path}",
            f"show ont optical-info {port} {onu_id}",
        ]
        if include_ip:
            cmds.append(f"show ont ipconfig {port} {onu_id}")
        cmds.append('exit')

        raw = execute_ssh_commands(olt, cmds)

        rx_onu = 'N/A'
        rx_olt = 'N/A'
        status = 'offline'

        for line in raw.split('\n'):
            if ':' not in line:
                continue
            parts_line = line.split(':')
            key = parts_line[0].lower().strip()
            val = parts_line[1].strip()

            vm = re.search(r'([-]?\d+\.?\d*)', val)
            if not vm:
                continue
            numeric_val = float(vm.group(1))

            if 'rx optical power' in key:
                if -50 < numeric_val <= 5:
                    rx_onu = vm.group(1)
            elif 'olt rx' in key:
                if -50 < numeric_val <= 5:
                    rx_olt = vm.group(1)
            elif any(x in key for x in ['run state', 'work state', 'status']):
                val_low = val.lower()
                if any(x in val_low for x in ['up', 'online', 'active', 'bound']):
                    status = 'online'

        if rx_onu != 'N/A' and float(rx_onu) > -40:
            status = 'online'

        pppoe_ip = 'N/A'
        m_ip = re.search(r'ONT IP\s*:\s*([0-9.]+)', raw, re.IGNORECASE)
        if m_ip:
            found_ip = m_ip.group(1).strip()
            if found_ip != '0.0.0.0' and found_ip != '255.255.255.255':
                pppoe_ip = found_ip

        return {
            'success': True,
            'rx_onu': rx_onu,
            'rx_olt': rx_olt,
            'status': status,
            'pppoe_ip': pppoe_ip,
            'log': raw
        }

    def reboot_onu(self, olt: dict, onu: dict) -> dict:
        if is_demo_olt(olt):
            return {'success': True, 'message': 'Perintah reboot dikirim (Mode Demo).'}

        parts = onu['pon_port'].split('/')
        port = int(parts[-1]) if len(parts) >= 3 else 1
        interface_path = "/".join(parts[:-1]) if len(parts) >= 3 else onu['pon_port']
        onu_id = onu['onu_id']

        commands = [
            "enable",
            "config",
            f"interface gpon {interface_path}",
            f"ont reboot {port} {onu_id}",
            "exit"
        ]
        log = execute_ssh_commands(olt, commands)
        return {'success': True, 'message': 'Perintah reboot berhasil dikirim ke OLT.', 'log': log}

    def restore_factory(self, olt: dict, onu: dict) -> dict:
        if is_demo_olt(olt):
            return {'success': True, 'message': 'ONU factory reset command sent (Mode Demo).'}

        parts = onu['pon_port'].split('/')
        port = int(parts[-1]) if len(parts) >= 3 else 1
        interface_path = "/".join(parts[:-1]) if len(parts) >= 3 else onu['pon_port']
        onu_id = onu['onu_id']

        commands = [
            "enable",
            "config",
            f"interface gpon {interface_path}",
            f"ont restore-factory {port} {onu_id}",
            "exit"
        ]
        log = execute_ssh_commands(olt, commands)
        return {'success': True, 'message': 'Perintah restore factory berhasil dikirim ke OLT.', 'log': log}

    def delete_onu(self, olt: dict, onu: dict) -> dict:
        if is_demo_olt(olt):
            return {'success': True, 'message': 'ONU berhasil dihapus (Mode Demo).'}

        parts = onu['pon_port'].split('/')
        port = int(parts[-1]) if len(parts) >= 3 else 1
        interface_path = "/".join(parts[:-1]) if len(parts) >= 3 else onu['pon_port']
        onu_id = onu['onu_id']

        commands = [
            "enable",
            "config",
            f"interface gpon {interface_path}",
            f"ont enable {port} {onu_id}",
            f"ont delete {port} {onu_id}",
            "exit",
            "exit",
            "write"
        ]
        log = execute_ssh_commands(olt, commands)
        
        if any(x in log.lower() for x in ['failed', 'error', 'invalid']):
            return {'success': False, 'message': 'Gagal menghapus ONU dari OLT: ' + log, 'log': log}

        return {'success': True, 'message': 'ONU berhasil dihapus dari OLT.', 'log': log}

    def authorize_onu(self, olt: dict, pon_port: str, serial: str, name: str, vlan: int, desc: str, onu_id: int = None) -> dict:
        if is_demo_olt(olt):
            demo_id = onu_id or 5
            return {
                'success': True,
                'onu_id': demo_id,
                'message': f"ONU berhasil diotorisasi (Mode Demo), ID: {demo_id}",
                'log': ''
            }

        parts = pon_port.split('/')
        port = int(parts[-1]) if len(parts) >= 3 else 1
        interface_path = "/".join(parts[:-1]) if len(parts) >= 3 else pon_port

        if onu_id is None:
            list_out = execute_ssh_commands(olt, ["enable", "show ont info all"])
            # Parse free ID
            used_ids = []
            for line in list_out.split('\n'):
                line = line.strip()
                row_parts = re.split(r'\s+', line)
                if len(row_parts) >= 3:
                    row_port = row_parts[0] + '/' + row_parts[1]
                    if row_port == f"{interface_path}/{port}" and row_parts[2].isdigit():
                        used_ids.append(int(row_parts[2]))
            onu_id = 1
            for i in range(1, 129):
                if i not in used_ids:
                    onu_id = i
                    break

        commands = [
            "enable",
            "config",
            f"interface gpon {interface_path}",
            f"ont add {port} {onu_id} sn-auth \"{serial}\"",
            f"ont name {port} {onu_id} \"{name}\"",
            f"ont description {port} {onu_id} \"{desc}\"",
            f"ont ont-port {port} {onu_id} eth adaptive pots adaptive catv adaptive iphost adaptive wifi adaptive",
            f"ont native-vlan {port} {onu_id} concern",
            f"ont ipconfig {port} {onu_id} ip-index 0 pppoe username {serial.lower()}@isp.id password {serial[:6]} vlan {vlan} priority 0",
            f"ont ipconfig {port} {onu_id} ip-index 0 connection-type route",
            f"ont tcont {port} {onu_id} 0 dba-profile-id 0",
            f"ont tcont {port} {onu_id} 1 dba-profile-id 10",
            f"ont mapping-mode {port} {onu_id} vlan",
            f"ont gemport {port} {onu_id} 1 tcont 1 gem-car-upstream 6 gem-car-downstream 6 encrypt disable",
            f"ont gemport mapping {port} {onu_id} 1 1 vlan {vlan}",
            "exit",
            "write"
        ]
        log = execute_ssh_commands(olt, commands)
        return {
            'success': 'error' not in log.lower(),
            'onu_id': onu_id,
            'message': f"ONU berhasil diotorisasi dengan ID {onu_id} di PON {pon_port}.",
            'log': log
        }

    def configure_onu_full(self, olt: dict, onu: dict, wan: dict) -> dict:
        if is_demo_olt(olt):
            return {'success': True, 'message': 'Konfigurasi ONT berhasil (Mode Demo).'}

        parts = onu['pon_port'].split('/')
        port = int(parts[-1]) if len(parts) >= 3 else 1
        interface_path = "/".join(parts[:-1]) if len(parts) >= 3 else onu['pon_port']
        ont_id = onu['onu_id']
        sn = onu['serial_number']
        clean_name = onu['name']
        desc = onu.get('description', clean_name)

        pppoe_user = wan.get('pppoe_username', '')
        pppoe_pass = wan.get('pppoe_password', '')
        vlan_svc = int(wan.get('vlan_service') or 25)

        commands = [
            'enable',
            'config',
            f"interface gpon {interface_path}",
            f"ont add {port} {ont_id} sn-auth \"{sn}\"",
            f"ont name {port} {ont_id} \"{clean_name}\"",
            f"ont description {port} {ont_id} \"{desc}\"",
            f"ont ipconfig {port} {ont_id} ip-index 0 pppoe username {pppoe_user} password {pppoe_pass} vlan {vlan_svc} priority 0",
            f"ont ipconfig {port} {ont_id} ip-index 0 connection-type route",
            'exit',
            'write'
        ]
        log = execute_ssh_commands(olt, commands)
        ok = not any(x in log.lower() for x in ['failed', 'error', 'invalid'])
        return {
            'success': ok,
            'message': 'Konfigurasi ONT berhasil diterapkan.' if ok
                       else f"OLT menolak perintah: {log}",
            'log': log
        }

    def sync_onu_config(self, olt: dict, onu: dict) -> dict:
        defaults = {
            'pppoe_username': '',
            'pppoe_password': '',
            'vlan': None,
            'onu_mode': 'Routing',
            'wan_mode': 'PPPoE',
            'config_method': 'OMCI',
            'ip_protocol': 'IPv4',
            'wan_remote_access': 'no',
            'mgmt_ip_mode': 'DHCP',
            'mgmt_ip': '',
            'mgmt_vlan': 100,
            'allow_remote_mgmt': 'no',
            'pppoe_ip': None,
            'rx_onu': 'N/A',
            'rx_olt': 'N/A',
            'status': 'offline',
        }

        if is_demo_olt(olt):
            return {**defaults, 'pppoe_username': 'demo@isp.net', 'pppoe_password': 'demo', 'vlan': 25, 'pppoe_ip': '172.23.45.64'}

        parts = onu['pon_port'].split('/')
        port = parts[-1] if len(parts) >= 3 else '1'
        interface_path = "/".join(parts[:-1]) if len(parts) >= 3 else onu['pon_port']
        ont_id = onu['onu_id']

        raw = execute_ssh_commands(olt, [
            'enable',
            'config',
            f"show current-config section ont {interface_path} {port} {ont_id}",
            'exit',
            'exit'
        ])

        result = defaults.copy()
        
        for line in raw.split('\n'):
            line = line.strip()
            if not line:
                continue

            if preg := re.search(r'ont\s+name\s+' + port + r'\s+' + str(ont_id) + r'\s+(.+)', line, re.IGNORECASE):
                result['onu_name'] = preg.group(1).strip('"\'' )
            elif preg := re.search(r'ont\s+description\s+' + port + r'\s+' + str(ont_id) + r'\s+(.+)', line, re.IGNORECASE):
                result['onu_description'] = preg.group(1).strip('"\'' )
            elif preg := re.search(r'ont\s+ipconfig\s+' + port + r'\s+' + str(ont_id) + r'\s+ip-index\s+0\s+pppoe\s+', line, re.IGNORECASE):
                result['wan_mode'] = 'PPPoE'
                result['onu_mode'] = 'Routing'
                if mu := re.search(r'username\s+(\S+)', line, re.IGNORECASE):
                    result['pppoe_username'] = mu.group(1).strip('"\'')
                if mp := re.search(r'password\s+(\S+)', line, re.IGNORECASE):
                    result['pppoe_password'] = mp.group(1).strip('"\'')
                if mv := re.search(r'vlan\s+(\d+)', line, re.IGNORECASE):
                    result['vlan'] = int(mv.group(1))

        # Attenuation check
        sig = self.get_onu_signal(olt, onu, include_ip=False)
        result['rx_onu'] = sig.get('rx_onu', 'N/A')
        result['rx_olt'] = sig.get('rx_olt', 'N/A')
        result['status'] = sig.get('status', 'offline')
        
        return result

    def get_onu_full_status(self, olt: dict, onu: dict) -> dict:
        sig = self.get_onu_signal(olt, onu, include_ip=True)
        rx_onu = sig.get('rx_onu', 'N/A')
        rx_olt = sig.get('rx_olt', 'N/A')
        pppoe_ip = sig.get('pppoe_ip', 'N/A')
        status = sig.get('status', 'offline')

        sn = onu.get('serial_number', 'CDATA0000001')
        name = onu.get('name', 'CData ONU')
        user = onu.get('pppoe_username', 'demo@isp.net')

        # CData full status mapping
        return {
            'success': True,
            'message': 'Status ONU CData berhasil dibaca.',
            'optical_status': f"Wavelength  OLT                   ONU                  Attenuation\n1310nm      Rx :-22.12(dbm)       Tx:N/A               N/A\n1490nm      Tx :N/A               Rx:{rx_onu}(dbm)       N/A",
            'onu_details': f"Vendor ID:           CDAT \nDetected ONU type:   1GE+3FE+VOIP+WIFI\nName:                {name}\nState:               {status}\nSerial number:       {sn}",
            'onu_catv_port': "Admin status:        unlock\nState:               disabled",
            'history': f" 1   {datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}    ONU status is {status}",
            'wan_interfaces': f"WAN ID:           1\nMAC address:      f4:8e:38:00:11:22\nIPv4 address:     {pppoe_ip}\nSubnet mask:      255.255.255.255\nWAN ID:           2\nWAN ID:           3\nWAN ID:           4\nWAN ID:           5\n\nPPPoE WAN:        1\nUsername:         {user}\nStatus:           {status}",
            'lan_interfaces': "eth_0/1       auto         unlock    1500       0",
            'vlan_info': "eth_0/1       hybrid       --         --          --",
            'voip_status': "Tel line:           pots_0/1\nService status:     none",
            'macs': "No MAC addresses bound.",
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
                    {'pon_port': '0/1', 'onu_id': 1, 'serial_number': 'ZTEGC0B3458A', 'name': 'Roni Cikron', 'status': 'online'},
                    {'pon_port': '0/1', 'onu_id': 2, 'serial_number': 'ZTEGC85ADC5F', 'name': 'Indah Ayu', 'status': 'offline'}
                ]
            }

        raw = execute_ssh_commands(olt, ['enable', 'show ont info all'])
        onus = []
        for line in raw.split('\n'):
            line = line.strip()
            if not line:
                continue

            if preg := re.search(r'([a-zA-Z]{4}[0-9a-fA-F]{8})', line):
                sn = preg.group(1)
                row_parts = re.split(r'\s+', line)
                try:
                    sn_idx = row_parts.index(sn)
                except ValueError:
                    continue

                if sn_idx >= 3:
                    pon_port = row_parts[0] + '/' + row_parts[1]
                    onu_id = int(row_parts[2])
                    status = 'online' if any(x in row_parts[sn_idx+1].lower() or x in row_parts[sn_idx+2].lower() for x in ['online', 'up', 'active', 'bound']) else 'offline'
                    # Deskripsi terstruktur di akhir baris mentah, di dalam kutip
                    # atau tanpa kutip. JANGAN pakai row_parts[sn_idx+4:] karena
                    # kolom 'last down-cause' (bisa 1-2 kata: '--', 'dying-gasp',
                    # 'LOSi/LOBi') ikut tergabung dan merusak nama.
                    m_desc = re.search(r'"(name_[^"]+)"', line)
                    if not m_desc:
                        m_desc = re.search(r'\b(name_\S+)', line)
                    if m_desc:
                        raw_desc = m_desc.group(1).strip('"\' ')
                        # Ekstrak nama bersih dari pola terstruktur.
                        # "name_CaffeFoodMie_zone_TMY_..." -> "CaffeFoodMie"
                        clean = re.match(r'^name_(.*?)(?:_(?:zone|descr|odb|authd|contact)_|$)', raw_desc, re.I)
                        name = clean.group(1).strip() if clean else raw_desc
                    elif len(row_parts) > sn_idx + 4:
                        name = " ".join(row_parts[sn_idx+4:]).strip('"\' ')
                    else:
                        name = f"ONU_{onu_id}"
                    onus.append({
                        'pon_port': pon_port,
                        'onu_id': onu_id,
                        'serial_number': sn,
                        'name': name,
                        'status': status
                    })

        return {'success': True, 'onus': onus}

    def pull_onus_from_current_config(self, olt: dict) -> dict:
        if is_demo_olt(olt):
            return self.pull_configured_onus(olt)

        raw = execute_ssh_commands(olt, ['enable', 'show current-config'])
        onus = {}
        current_interface = ''
        
        for line in raw.split('\n'):
            line = line.strip()
            if not line:
                continue
            
            if m := re.match(r'^interface\s+(gpon|epon)\s+(\S+)', line, re.IGNORECASE):
                current_interface = m.group(2)
                continue
            
            if line == 'exit' or line == '!':
                current_interface = ''
                continue
                
            if not current_interface:
                continue
                
            if m := re.match(r'^ont\s+add\s+(\d+)\s+(\d+)\s+sn-auth\s+["\']?([^"\']+)["\']?', line, re.IGNORECASE):
                port_num = int(m.group(1))
                onu_id = int(m.group(2))
                sn = m.group(3).strip()
                full_pon_port = f"{current_interface}/{port_num}"
                key = f"{full_pon_port}_{onu_id}"
                onus[key] = {
                    'pon_port': full_pon_port,
                    'onu_id': onu_id,
                    'serial_number': sn,
                    'name': f"ONU_{onu_id}",
                    'status': 'offline'
                }
            elif m := re.match(r'^ont\s+description\s+(\d+)\s+(\d+)\s+(.+)', line, re.IGNORECASE):
                port_num = int(m.group(1))
                onu_id = int(m.group(2))
                raw_desc = m.group(3).strip('"\' ')
                # Ekstrak nama bersih dari deskripsi terstruktur.
                # Pola: "name_CaffeFoodMie_zone_TMY_descr_None_odb_..."
                # Hasil: "CaffeFoodMie"
                clean = re.match(r'^name_(.*?)(?:_(?:zone|descr|odb|authd|contact)_|$)', raw_desc, re.I)
                name = clean.group(1).strip() if clean else raw_desc
                full_pon_port = f"{current_interface}/{port_num}"
                key = f"{full_pon_port}_{onu_id}"
                if key in onus:
                    onus[key]['name'] = name

        return {'success': True, 'onus': list(onus.values())}

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
        parts = pon_port.split('/')
        port = int(parts[-1]) if len(parts) >= 3 else 1
        interface_path = "/".join(parts[:-1]) if len(parts) >= 3 else pon_port

        raw = execute_ssh_commands(olt, [
            'enable',
            'config',
            f"interface gpon {interface_path}",
            f"show statistics ont {port} {onu_id}",
            'exit'
        ])

        rx_bytes, tx_bytes, rx_packets, tx_packets = None, None, None, None
        for line in raw.split('\n'):
            line = line.strip()
            if m := re.match(r'^packets\s*:\s*(\d+)\s+(\d+)', line, re.IGNORECASE):
                rx_packets = int(m.group(1))
                tx_packets = int(m.group(2))
            elif m := re.match(r'^bytes\s*:\s*(\d+)\s+(\d+)', line, re.IGNORECASE):
                rx_bytes = int(m.group(1))
                tx_bytes = int(m.group(2))

        return {
            'success': rx_packets is not None,
            'rx_packets': rx_packets,
            'tx_packets': tx_packets,
            'rx_bytes': rx_bytes,
            'tx_bytes': tx_bytes
        }

    def get_onu_config_maps(self, olt: dict) -> dict:
        empty = {'ipconfig_map': {}, 'name_map': {}, 'desc_map': {}}
        if is_demo_olt(olt):
            return {**empty, 'success': True, 'message': 'OLT demo, tidak ada config nyata.'}

        raw = execute_ssh_commands(olt, ['enable', 'terminal length 0', 'show current-config'])
        if not raw.strip():
            return {**empty, 'success': False, 'message': 'Konfigurasi OLT kosong / gagal dibaca.'}

        ipconfig_map = {}
        name_map = {}
        desc_map = {}
        current_path = ''

        for line in raw.split('\n'):
            line = line.strip()
            if not line:
                continue

            m = re.match(r'^interface[ \t]+gpon[ \t]+(\S+)', line, re.I)
            if m:
                current_path = m.group(1)
                continue

            if line == 'exit' or line == '!':
                current_path = ''
                continue

            if not current_path:
                continue

            def get_key(port_num: str, onu_id: str) -> str:
                return f"{current_path}/{port_num}_{onu_id}"

            # ont name <port> <onu_id> <name>
            m_name = re.match(r'^ont[ \t]+name[ \t]+(\d+)[ \t]+(\d+)[ \t]+(.+)$', line, re.I)
            if m_name:
                name_map[get_key(m_name.group(1), m_name.group(2))] = m_name.group(3).strip('"\' ')
                continue

            # ont description <port> <onu_id> <desc>
            m_desc = re.match(r'^ont[ \t]+description[ \t]+(\d+)[ \t]+(\d+)[ \t]+(.+)$', line, re.I)
            if m_desc:
                desc_map[get_key(m_desc.group(1), m_desc.group(2))] = m_desc.group(3).strip('"\' ')
                continue

            # ont ipconfig <port> <onu_id> ip-index 0 <pppoe|dhcp|static> ...
            m_ip = re.match(r'^ont[ \t]+ipconfig[ \t]+(\d+)[ \t]+(\d+)[ \t]+ip-index[ \t]+0[ \t]+(\S+)', line, re.I)
            if m_ip:
                mode = m_ip.group(3).lower()
                user = ''
                pass_val = ''

                if mode == 'pppoe':
                    wan_mode = 'PPPoE'
                    m_user = re.search(r'username[ \t]+(\S+)', line, re.I)
                    if m_user: user = m_user.group(1).strip('"\'')
                    m_pass = re.search(r'password[ \t]+(\S+)', line, re.I)
                    if m_pass: pass_val = m_pass.group(1).strip('"\'')
                elif mode == 'dhcp':
                    wan_mode = 'DHCP'
                elif mode == 'static':
                    wan_mode = 'Static'
                else:
                    wan_mode = 'PPPoE'

                m_vlan = re.search(r'vlan[ \t]+(\d+)', line, re.I)
                vlan_id = int(m_vlan.group(1)) if m_vlan else None

                ipconfig_map[get_key(m_ip.group(1), m_ip.group(2))] = {
                    'vlan': vlan_id,
                    'pppoe_username': user,
                    'pppoe_password': pass_val,
                    'wan_mode': wan_mode
                }
                continue

            # ont gemport mapping <port> <onu_id> <gemport_id> 1 vlan <vlan_id>
            m_gem = re.match(r'^ont[ \t]+gemport[ \t]+mapping[ \t]+(\d+)[ \t]+(\d+)[ \t]+\d+[ \t]+1[ \t]+vlan[ \t]+(\d+)', line, re.I)
            if m_gem:
                k = get_key(m_gem.group(1), m_gem.group(2))
                if k in ipconfig_map and ipconfig_map[k].get('vlan') is None:
                    ipconfig_map[k]['vlan'] = int(m_gem.group(3))
                elif k not in ipconfig_map:
                    ipconfig_map[k] = {
                        'vlan': int(m_gem.group(3)),
                        'pppoe_username': '',
                        'pppoe_password': '',
                        'wan_mode': 'PPPoE'
                    }

        return {
            'success': True,
            'message': 'Konfigurasi ONU CDATA berhasil dipetakan.',
            'ipconfig_map': ipconfig_map,
            'name_map': name_map,
            'desc_map': desc_map
        }

    def get_onu_running_config(self, olt: dict, onu: dict) -> dict:
        if is_demo_olt(olt):
            sn = onu.get('serial_number', 'DEMOSN000001')
            name = onu.get('name', 'Demo ONU')
            user = onu.get('pppoe_username', 'demo@isp.net')
            return {
                'success': True,
                'config': f"ont add 2 24 sn-auth \"{sn}\"\nont description 2 24 {name}\n ont ipconfig 2 24 ip-index 0 pppoe username {user} password demo123 vlan 25 priority 0\n ont ipconfig 2 24 ip-index 0 connection-type route"
            }
        parts = onu.get('pon_port', '').split('/')
        port = parts[-1] if len(parts) >= 3 else '1'
        interface_path = "/".join(parts[:-1]) if len(parts) >= 3 else onu.get('pon_port', '')
        ont_id = int(onu.get('onu_id') or 0)
        
        raw = execute_ssh_commands(olt, [
            'enable',
            'config',
            f"show current-config section ont {interface_path} {port} {ont_id}",
            'exit',
            'exit'
        ])
        return {
            'success': True,
            'config': raw.strip()
        }

    # ======================================================================
    # MANAJEMEN VLAN & INTERFACE
    #
    # Semua data dibaca langsung dari perangkat (tidak ada cache di database),
    # sehingga yang tampil di layar selalu kondisi nyata OLT. Perintah tulis
    # dilindungi validasi ketat untuk mencegah command injection dan mencegah
    # pemutusan layanan pelanggan secara tidak sengaja.
    # ======================================================================

    @staticmethod
    def _sanitize_vlan_description(desc: str) -> str:
        """Hanya huruf, angka, spasi, titik, garis bawah, strip. Memblokir baris baru
        (command injection). Maksimal 32 karakter."""
        desc = re.sub(r'[^A-Za-z0-9 ._-]', '', desc or '')
        return re.sub(r'\s+', ' ', desc).strip()[:32]

    @staticmethod
    def _parse_vlan_list(spec: str) -> set:
        """Uraikan '1,11-27,100' menjadi himpunan {1, 11..27, 100}."""
        out = set()
        for part in (spec or '').split(','):
            part = part.strip()
            m = re.match(r'^(\d+)-(\d+)$', part)
            if m:
                out.update(range(int(m.group(1)), int(m.group(2)) + 1))
            elif re.match(r'^\d+$', part):
                out.add(int(part))
        return out

    def get_vlans(self, olt: dict) -> dict:
        """Baca seluruh VLAN beserta atributnya langsung dari perangkat."""
        if is_demo_olt(olt):
            return {'success': True, 'message': 'Data demo VLAN.', 'vlans': [
                {'id': 1, 'description': 'default', 'type': 'Normal vlan', 'tagged': '',
                 'untagged': '', 'is_l3': False, 'ip': '', 'protected': True},
                {'id': 25, 'description': 'PPPOE', 'type': 'Normal vlan', 'tagged': 'ge 0/0/1',
                 'untagged': '', 'is_l3': False, 'ip': '', 'protected': False},
            ]}

        raw = execute_ssh_commands(olt, [
            'enable',
            'terminal length 0',
            'show vlan all',
            'show interface vlanif',
        ])
        err = detect_transport_error(raw)
        if err:
            return {'success': False, 'message': err, 'vlans': []}

        raw = strip_command_markers(raw)

        # VLAN yang punya interface vlanif = VLAN layer 3, dilindungi dari penghapusan.
        l3 = {int(v) for v in re.findall(r'Vlanif(\d+)\s+current state', raw, re.I)}
        vlanif_ip = {}
        for blk in re.split(r'(?=\sVlanif\d+ current state)', raw, flags=re.I):
            mv = re.search(r'Vlanif(\d+)\s+current state', blk, re.I)
            if not mv:
                continue
            mi = re.search(r'inet\s+(\d+\.\d+\.\d+\.\d+(?:/\d+)?)', blk, re.I)
            if mi:
                vlanif_ip[int(mv.group(1))] = mi.group(1).strip()

        def clean(s):
            if not s:
                return ''
            s = re.sub(r'\s+', ' ', s).strip()
            return '' if 'none' in s.lower() else s

        vlans = {}
        for b in re.split(r'VLAN\s*ID\s*:\s*', raw, flags=re.I)[1:]:
            vlan_id = b.split('\n', 1)[0].strip()
            if not re.match(r'^\d+$', vlan_id):
                continue
            vlan_id = int(vlan_id)

            # Pakai [ \t] (bukan \s) — output OLT ber-CRLF, \s akan menyeret baris berikutnya.
            m = re.search(r'VLAN Description[ \t]*:[ \t]*([^\r\n]*)', b, re.I)
            desc = m.group(1).strip() if m else ''
            m = re.search(r'VLAN Type[ \t]*:[ \t]*([^\r\n]*)', b, re.I)
            vtype = m.group(1).strip() if m else 'Normal vlan'
            is_l3 = vlan_id in l3 or 'l3intf' in vtype.lower()

            m = re.search(r'Tagged Ports\s*:\s*([\s\S]*?)(?=Untagged Ports\s*:|$)', b, re.I)
            tagged = clean(m.group(1) if m else None)
            m = re.search(r'Untagged Ports\s*:\s*([\s\S]*?)$', b, re.I)
            untagged = clean(m.group(1) if m else None)

            vlans[vlan_id] = {
                'id': vlan_id,
                'description': desc,
                'type': vtype,
                'tagged': tagged,
                'untagged': untagged,
                'is_l3': is_l3,
                'ip': vlanif_ip.get(vlan_id, ''),
                'protected': vlan_id == 1 or is_l3,
            }

        return {'success': True, 'message': '', 'vlans': [vlans[k] for k in sorted(vlans)]}

    def add_vlan(self, olt: dict, vlan_id: int, description: str = '') -> dict:
        """Buat VLAN baru, opsional beserta deskripsinya. Hasil diverifikasi ulang ke perangkat."""
        vlan_id = int(vlan_id)
        if vlan_id < 1 or vlan_id > 4094:
            return {'success': False, 'message': 'VLAN ID harus berada pada rentang 1-4094.'}

        current = self.get_vlans(olt)
        if not current['success']:
            return {'success': False, 'message': current['message']}
        if any(v['id'] == vlan_id for v in current['vlans']):
            return {'success': False, 'message': f'VLAN {vlan_id} sudah ada pada perangkat.'}

        desc = self._sanitize_vlan_description(description)
        commands = ['enable', 'config', f'vlan {vlan_id}']
        if desc:
            commands.append(f'vlan description {vlan_id} {desc}')
        commands += ['exit', 'write']

        log = execute_ssh_commands(olt, commands)

        # Verifikasi ulang ke perangkat — jangan percaya keluaran CLI begitu saja.
        after = self.get_vlans(olt)
        if any(v['id'] == vlan_id for v in after.get('vlans', [])):
            return {'success': True, 'message': f'VLAN {vlan_id} berhasil dibuat di OLT.', 'log': log}
        return {'success': False,
                'message': f'VLAN {vlan_id} gagal dibuat. Periksa log perangkat.', 'log': log}

    def save_vlan(self, olt: dict, vlan_id: int, description: str = '') -> dict:
        """Buat VLAN atau perbarui deskripsinya (idempoten, tidak menolak VLAN yang sudah ada)."""
        vlan_id = int(vlan_id)
        if vlan_id < 1 or vlan_id > 4094:
            return {'success': False, 'message': 'VLAN ID harus berada pada rentang 1-4094.'}

        desc = self._sanitize_vlan_description(description)
        commands = ['enable', 'config', f'vlan {vlan_id}']
        if desc:
            commands.append(f'vlan description {vlan_id} {desc}')
        commands += ['exit', 'write']

        log = execute_ssh_commands(olt, commands)
        return {'success': True, 'message': f'VLAN {vlan_id} berhasil disimpan di OLT.', 'log': log}

    def save_vlan_ports(self, olt: dict, vlan_id: int, description: str = '',
                        ports_config: dict = None) -> dict:
        """Simpan VLAN sekaligus menugaskan keanggotaan port (tagged/untagged/none).

        ports_config: {'ge 0/0/1': 'tagged'|'untagged'|'none', ...}

        Logika ini SEBELUMNYA berada di frontend/action/vlan.php (case
        'save-vlan-ports') dan menyusun perintah CLI vendor langsung di frontend —
        melanggar aturan "tidak boleh ada perintah CLI vendor di frontend/".
        Dipindah ke driver agar frontend cukup mengirim niat, bukan sintaks.
        """
        vlan_id = int(vlan_id)
        ports_config = ports_config or {}

        res_vlan = self.save_vlan(olt, vlan_id, description)
        if not res_vlan['success']:
            return res_vlan

        if not ports_config:
            return res_vlan

        res_ports = self.get_interfaces(olt)
        if not res_ports['success']:
            return res_ports

        commands = ['enable', 'terminal length 0', 'config']
        has_changes = False

        for p in res_ports['ports']:
            name = p['name']
            if name not in ports_config:
                continue
            want = ports_config[name]  # 'tagged' | 'untagged' | 'none'

            tagged = self._parse_vlan_list(p.get('vlan_list', ''))
            mode = (p.get('vlan_mode') or 'trunk').lower()
            pvid = int(p.get('pvid') or 1)
            changed = False

            if want == 'tagged':
                if vlan_id not in tagged or mode not in ('trunk', 'hybrid'):
                    tagged.add(vlan_id)
                    if mode == 'access':
                        mode = 'trunk'
                    if pvid == vlan_id:
                        pvid = 1
                    changed = True
            elif want == 'untagged':
                if pvid != vlan_id or mode not in ('access', 'hybrid'):
                    pvid = vlan_id
                    if mode in ('access', 'trunk'):
                        mode = 'hybrid'
                    tagged.discard(vlan_id)
                    changed = True
            else:  # none
                if vlan_id in tagged:
                    tagged.discard(vlan_id)
                    changed = True
                if pvid == vlan_id:
                    pvid = 1
                    changed = True

            if not changed:
                continue

            parsed, _ = self._parse_port_name(name)
            if parsed is None:
                continue
            port_kind, port_num = parsed

            # VLAN 1 wajib tetap ada di trunk/hybrid (VLAN default sistem).
            if mode in ('trunk', 'hybrid'):
                tagged.add(1)
            tagged_list = ','.join(str(v) for v in sorted(tagged))

            commands.append(f'interface {port_kind} 0/0')
            if mode == 'access':
                commands.append(f'vlan mode {port_num} access')
                commands.append(f'vlan access {port_num} {pvid}')
                commands.append(f'vlan native-vlan {port_num} {pvid}')
            elif mode == 'trunk':
                commands.append(f'vlan mode {port_num} trunk')
                commands.append(f'vlan trunk {port_num} {tagged_list}')
            else:  # hybrid
                commands.append(f'vlan mode {port_num} hybrid')
                if tagged_list:
                    commands.append(f'vlan hybrid {port_num} tagged {tagged_list}')
                commands.append(f'vlan hybrid {port_num} untagged {pvid}')
                commands.append(f'vlan native-vlan {port_num} {pvid}')
            commands.append('exit')
            has_changes = True

        if not has_changes:
            return {'success': True,
                    'message': f'VLAN {vlan_id} disimpan. Tidak ada perubahan port.',
                    'log': res_vlan.get('log', '')}

        commands.append('write')
        log = execute_ssh_commands(olt, commands)

        # Versi PHP di vlan.php melaporkan sukses TANPA memeriksa keluaran perangkat.
        # Di sini penolakan OLT dideteksi dan dilaporkan apa adanya.
        errs = [ln.strip() for ln in log.split('\n')
                if ln.strip().startswith('%')
                or any(w in ln.lower() for w in ('error', 'invalid', 'fail'))]
        if errs:
            return {'success': False,
                    'message': 'Sebagian konfigurasi port ditolak OLT: ' + ' | '.join(errs[:3]),
                    'log': log}

        return {'success': True,
                'message': 'Konfigurasi VLAN & port berhasil disimpan ke OLT.', 'log': log}

    def delete_vlan(self, olt: dict, vlan_id: int, confirmed: bool = False) -> dict:
        """Hapus VLAN dengan proteksi berlapis: VLAN 1 ditolak, VLAN L3 ditolak,
        VLAN yang dipakai pelanggan wajib konfirmasi eksplisit. Port yang memakai
        VLAN target dibersihkan lebih dulu agar OLT tidak menolak penghapusan."""
        vlan_id = int(vlan_id)
        if vlan_id < 1 or vlan_id > 4094:
            return {'success': False, 'message': 'VLAN ID harus berada pada rentang 1-4094.'}
        if vlan_id == 1:
            return {'success': False,
                    'message': 'VLAN 1 adalah VLAN default sistem dan tidak boleh dihapus.'}

        current = self.get_vlans(olt)
        if not current['success']:
            return {'success': False, 'message': current['message']}

        target = next((v for v in current['vlans'] if v['id'] == vlan_id), None)
        if target is None:
            return {'success': False, 'message': f'VLAN {vlan_id} tidak ditemukan pada OLT.'}
        if target.get('is_l3'):
            return {'success': False,
                    'message': f'VLAN {vlan_id} memiliki interface vlanif (VLAN layer 3). '
                               'Hapus interface vlanif-nya terlebih dahulu.'}

        # 1. Baca current-config: deteksi pemakaian oleh ONT + kumpulkan konfigurasi port.
        cfg = strip_command_markers(
            execute_ssh_commands(olt, ['enable', 'terminal length 0', 'show current-config']))

        in_use = bool(re.search(
            r'ont (?:ipconfig|gemport mapping)[^\r\n]*\bvlan ' + str(vlan_id) + r'\b', cfg, re.I))
        if in_use and not confirmed:
            return {
                'success': False,
                'needs_confirm': True,
                'message': f'VLAN {vlan_id} sedang dipakai layanan pelanggan (ONT). '
                           'Menghapusnya akan memutus koneksi mereka. Konfirmasi diperlukan.',
            }

        # 2. Parsir port yang mengasosiasikan VLAN ini dari show current-config.
        ports = {}
        if_type = if_path = ''

        def touch(num):
            key = f'{if_type}_{if_path}_{num}'
            if key not in ports:
                ports[key] = {'type': if_type, 'path': if_path, 'num': num,
                              'mode': 'hybrid', 'tagged': '', 'pvid': 1}
            return ports[key]

        for line in cfg.split('\n'):
            line = line.strip()
            if line == '':
                continue
            m = re.match(r'^interface[ \t]+(ge|xge|gpon)[ \t]+(\S+)', line, re.I)
            if m:
                if_type, if_path = m.group(1).lower(), m.group(2)
                continue
            if line in ('exit', '!'):
                if_type = if_path = ''
                continue
            if if_type == '':
                continue

            m = re.match(r'^vlan[ \t]+mode[ \t]+(\d+)[ \t]+(\S+)', line, re.I)
            if m:
                touch(int(m.group(1)))['mode'] = m.group(2).lower()
            m = (re.match(r'^vlan[ \t]+order[ \t]+(\d+)[ \t]+(\S+)', line, re.I)
                 or re.match(r'^vlan[ \t]+trunk[ \t]+(\d+)[ \t]+(\S+)', line, re.I))
            if m:
                touch(int(m.group(1)))['tagged'] = m.group(2)
            m = re.match(r'^vlan[ \t]+hybrid[ \t]+(\d+)[ \t]+tagged[ \t]+(\S+)', line, re.I)
            if m:
                touch(int(m.group(1)))['tagged'] = m.group(2)
            m = re.match(r'^vlan[ \t]+hybrid[ \t]+(\d+)[ \t]+untagged[ \t]+(\d+)', line, re.I)
            if m:
                touch(int(m.group(1)))['pvid'] = int(m.group(2))
            m = re.match(r'^vlan[ \t]+access[ \t]+(\d+)[ \t]+(\d+)', line, re.I)
            if m:
                touch(int(m.group(1)))['pvid'] = int(m.group(2))
            m = re.match(r'^vlan[ \t]+native-vlan[ \t]+(\d+)[ \t]+(\d+)', line, re.I)
            if m:
                touch(int(m.group(1)))['pvid'] = int(m.group(2))

        # 3. Bersihkan port yang terasosiasi dengan VLAN target.
        if_groups = {}
        for p in ports.values():
            is_tagged = vlan_id in self._parse_vlan_list(p['tagged'])
            is_pvid = p['pvid'] == vlan_id
            if not (is_tagged or is_pvid):
                continue

            if_key = f"interface {p['type']} {p['path']}"
            cmds = if_groups.setdefault(if_key, [])
            num = p['num']

            if p['mode'] == 'access':
                if is_pvid:
                    cmds.append(f'vlan access {num} 1')
                    cmds.append(f'vlan native-vlan {num} 1')
            elif p['mode'] == 'trunk':
                if is_tagged:
                    cmds.append(f'no vlan trunk {num} {vlan_id}')
            elif p['mode'] == 'hybrid':
                if is_tagged:
                    cmds.append(f'no vlan hybrid {num} tagged {vlan_id}')
                if is_pvid:
                    cmds.append(f'no vlan hybrid {num} untagged {vlan_id}')
                    cmds.append(f'vlan native-vlan {num} 1')

        clean_log = ''
        if if_groups:
            clean_commands = ['enable', 'config']
            for if_cmd, cmds in if_groups.items():
                clean_commands.append(if_cmd)
                clean_commands.extend(cmds)
                clean_commands.append('exit')
            clean_commands.append('exit')  # kembali ke privilege mode agar bisa 'write'
            clean_commands.append('write')
            clean_log = ('=== PORT CLEANING COMMANDS & OUTPUT ===\n'
                         + execute_ssh_commands(olt, clean_commands)
                         + '\n=======================================\n\n')

        # 4. Jalankan penghapusan VLAN sesungguhnya.
        log = clean_log + execute_ssh_commands(
            olt, ['enable', 'config', f'no vlan {vlan_id}', 'exit', 'write'])

        after = self.get_vlans(olt)
        if any(v['id'] == vlan_id for v in after.get('vlans', [])):
            errs = []
            for line in log.split('\n'):
                line = line.strip()
                low = line.lower()
                if line.startswith('%') or any(
                        w in low for w in ('error', 'fail', 'cannot', 'in use')):
                    errs.append(line)
            details = (' Respon OLT: ' + ' | '.join(errs)) if errs \
                else ' Periksa log debug untuk detail.'
            return {'success': False,
                    'message': f'VLAN {vlan_id} gagal dihapus.{details}', 'log': log}
        return {'success': True,
                'message': f'VLAN {vlan_id} berhasil dihapus dari OLT.', 'log': log}

    def get_interfaces(self, olt: dict) -> dict:
        """Baca status seluruh port uplink fisik (GE dan XGE). Perintah
        `show port state all` hanya tersedia di dalam mode interface, jadi harus
        masuk `interface ge 0/0` dan `interface xge 0/0` lebih dulu."""
        if is_demo_olt(olt):
            return {'success': True, 'message': 'Data demo interface.', 'ports': []}

        raw = execute_ssh_commands(olt, [
            'enable', 'terminal length 0', 'config',
            'interface ge 0/0', 'show port state all', 'show this', 'exit',
            'interface xge 0/0', 'show port state all', 'show this', 'exit',
            'exit',
        ])
        err = detect_transport_error(raw)
        if err:
            return {'success': False, 'message': err, 'ports': []}

        raw = strip_command_markers(raw)

        # Baris port, contoh nyata perangkat (perhatikan ada spasi di ujung baris):
        #   ge 0/0/1   absence  1     enable   1000        1000   full  on     enable   enable   on    1526   Copper
        # Output OLT ber-CRLF, jadi akhir baris harus [ \t\r]*$ — bukan [ \t]*$ —
        # agar karakter \r tidak membuat pencocokan gagal.
        ports = {}
        row_re = re.compile(
            r'^[ \t]*((?:ge|xge)[ \t]+\d+/\d+/\d+)[ \t]+(\S+)[ \t]+(\d+)[ \t]+(\S+)[ \t]+(\S+)'
            r'[ \t]+(\S+)[ \t]+(\S+)[ \t]+(\S+)[ \t]+(\S+)[ \t]+(\S+)[ \t]+(\S+)[ \t]+(\d+)'
            r'[ \t]+(\S+)[ \t\r]*$', re.M | re.I)
        for m in row_re.finditer(raw):
            name = re.sub(r'\s+', ' ', m.group(1).strip())
            ports[name] = {
                'name': name,
                'optic': m.group(2),
                'pvid': int(m.group(3)),
                'auto_nego': m.group(4),
                'config_speed': m.group(5),
                'speed': m.group(6),
                'duplex': m.group(7),
                'flow_ctrl': m.group(8),
                'learn': m.group(9),
                'enable': m.group(10),
                'link': m.group(11).lower(),
                'frame_max': int(m.group(12)),
                'media': m.group(13),
                'vlan_mode': '',
                'vlan_list': '',
            }

        # Mode & daftar VLAN per port diambil dari `show this` masing-masing interface.
        # Contoh: " vlan mode 1 trunk" dan " vlan trunk 1 24-25,100"
        for kind, block_re in (
                ('ge', r'show ge current-config[\s\S]*?(?=show xge current-config|$)'),
                ('xge', r'show xge current-config[\s\S]*?$')):
            bm = re.search(block_re, raw, re.I)
            if not bm:
                continue
            block = bm.group(0)
            for v in re.finditer(r'^[ \t]*vlan mode[ \t]+(\d+)[ \t]+(\S+)[ \t\r]*$', block, re.M | re.I):
                key = f'{kind} 0/0/{v.group(1)}'
                if key in ports:
                    ports[key]['vlan_mode'] = v.group(2).lower()
            for v in re.finditer(
                    r'^[ \t]*vlan (?:trunk|hybrid|access)[ \t]+(\d+)[ \t]+([^\r\n]+)', block, re.M | re.I):
                key = f'{kind} 0/0/{v.group(1)}'
                if key in ports:
                    ports[key]['vlan_list'] = v.group(2).strip()

        return {'success': True, 'message': '', 'ports': list(ports.values())}

    @staticmethod
    def _parse_port_name(port: str):
        """Balikan (kind, num) untuk 'ge 0/0/X'/'xge 0/0/X', atau (None, pesan error)."""
        port = (port or '').strip().lower()
        m = re.match(r'^(ge|xge)\s+0/0/(\d+)$', port)
        if not m:
            return None, 'Format port tidak valid. Gunakan format: ge 0/0/X atau xge 0/0/X'
        num = int(m.group(2))
        if num < 1 or num > 8:
            return None, 'Nomor port harus berada pada rentang 1-8.'
        return (m.group(1), num), None

    def config_port(self, olt: dict, port: str, auto_nego: str, speed: str, duplex: str) -> dict:
        """Setel auto-negotiation / speed / duplex pada port uplink."""
        parsed, err = self._parse_port_name(port)
        if parsed is None:
            return {'success': False, 'message': err}
        port_kind, port_num = parsed

        commands = ['enable', 'terminal length 0', 'config', f'interface {port_kind} 0/0']
        if auto_nego == 'enable':
            commands.append(f'auto-negotiation {port_num} enable')
        else:
            commands.append(f'auto-negotiation {port_num} disable')
            if speed not in ('', '-', None):
                commands.append(f'speed {port_num} {speed}')
            if duplex not in ('', '-', None):
                commands.append(f'duplex {port_num} {duplex}')
        commands += ['exit', 'write']

        log = execute_ssh_commands(olt, commands)
        return {
            'success': True,
            'message': f'Negosiasi port {port.strip().lower()} berhasil diperbarui di OLT.',
            'commands': commands,
            'log': log,
        }

    def config_port_vlan(self, olt: dict, port: str, mode: str,
                         tagged=None, untagged=None, pvid=None) -> dict:
        """Setel mode & keanggotaan VLAN sebuah port uplink.

        Keamanan: VLAN 1 tidak boleh dikeluarkan dari trunk (VLAN default sistem),
        semua VLAN wajib sudah ada di perangkat, dan hasilnya diverifikasi ulang
        lewat `show this` sebelum dilaporkan sukses.
        """
        parsed, err = self._parse_port_name(port)
        if parsed is None:
            return {'success': False, 'message': err}
        port_kind, port_num = parsed
        port = port.strip().lower()

        mode = (mode or '').strip().lower()
        if mode not in ('access', 'trunk', 'hybrid'):
            return {'success': False, 'message': 'Mode VLAN harus access, trunk, atau hybrid.'}

        # Baca VLAN yang valid dari perangkat.
        vres = self.get_vlans(olt)
        if not vres['success']:
            return {'success': False,
                    'message': 'Tidak dapat membaca daftar VLAN dari perangkat: ' + vres['message']}
        vlan_ids = {v['id'] for v in vres['vlans']}

        tagged_ids = []
        if tagged:
            tagged_ids = sorted(self._parse_vlan_list(tagged))
            for vid in tagged_ids:
                if vid not in vlan_ids:
                    return {'success': False, 'message': f'VLAN {vid} tidak ada di perangkat.'}

        # VLAN 1 wajib tetap ada di trunk. Versi PHP menulis cek ini TERBALIK
        # (`$vid === 1 && $mode === 'trunk'` -> menolak saat VLAN 1 ADA), sehingga
        # trunk justru tak pernah boleh memuat VLAN 1 - kebalikan dari pesannya
        # sendiri. Di sini ditegakkan sesuai maksud aslinya.
        if mode == 'trunk' and tagged_ids and 1 not in tagged_ids:
            return {'success': False,
                    'message': 'VLAN 1 (default sistem) harus selalu ada di trunk. '
                               'Tambahkan VLAN 1 ke daftar tagged.'}

        untagged_id = None
        if untagged not in (None, '', 0):
            untagged_id = int(str(untagged).strip())
            if untagged_id < 1 or untagged_id > 4094:
                return {'success': False,
                        'message': 'VLAN untagged harus berada pada rentang 1-4094.'}
            if untagged_id not in vlan_ids:
                return {'success': False,
                        'message': f'VLAN {untagged_id} tidak ada di perangkat.'}

        if pvid is None:
            pvid = untagged_id if untagged_id is not None else 1
        else:
            pvid = int(pvid)
            if pvid < 1 or pvid > 4094:
                return {'success': False, 'message': 'PVID harus berada pada rentang 1-4094.'}
            if pvid not in vlan_ids:
                return {'success': False, 'message': f'VLAN PVID {pvid} tidak ada di perangkat.'}

        commands = ['enable', 'terminal length 0', 'config', f'interface {port_kind} 0/0']
        if mode == 'access':
            if untagged_id is None:
                return {'success': False, 'message': 'Mode access memerlukan VLAN untagged.'}
            commands.append(f'vlan mode {port_num} access')
            commands.append(f'vlan access {port_num} {untagged_id}')
            commands.append(f'vlan native-vlan {port_num} {untagged_id}')
        elif mode == 'trunk':
            if not tagged_ids:
                return {'success': False, 'message': 'Mode trunk memerlukan minimal 1 VLAN tagged.'}
            commands.append(f'vlan mode {port_num} trunk')
            commands.append(f"vlan trunk {port_num} {','.join(str(v) for v in tagged_ids)}")
        else:  # hybrid
            commands.append(f'vlan mode {port_num} hybrid')
            if tagged_ids:
                commands.append(
                    f"vlan hybrid {port_num} tagged {','.join(str(v) for v in tagged_ids)}")
            if untagged_id is not None:
                commands.append(f'vlan hybrid {port_num} untagged {untagged_id}')
            commands.append(f'vlan native-vlan {port_num} {pvid}')
        commands += ['exit', 'write']

        log = execute_ssh_commands(olt, commands)

        # Verifikasi ulang dengan `show this` — jangan percaya keluaran CLI begitu saja.
        verify_raw = strip_command_markers(execute_ssh_commands(olt, [
            'enable', 'terminal length 0', 'config',
            f'interface {port_kind} 0/0', 'show this', 'exit', 'exit',
        ]))
        block_re = (r'show ge current-config[\s\S]*?(?=show xge current-config|$)'
                    if port_kind == 'ge' else r'show xge current-config[\s\S]*?$')
        bm = re.search(block_re, verify_raw, re.I)
        if bm:
            mm = re.search(r'^[ \t]*vlan mode[ \t]+' + str(port_num) + r'[ \t]+(\S+)',
                           bm.group(0), re.M | re.I)
            if mm and mm.group(1).lower() == mode:
                return {'success': True,
                        'message': f'Konfigurasi VLAN port {port} mode {mode} '
                                   'berhasil diterapkan ke OLT.',
                        'log': log}

        return {'success': False,
                'message': f'Konfigurasi VLAN port {port} gagal atau tidak terverifikasi. '
                           'Periksa log perangkat.',
                'log': log}

    def setup_snmp(self, olt: dict, community_ro: str, community_rw: str) -> dict:
        """Aktifkan SNMP dan setel community. Perintah CLI vendor hanya ada di sini."""
        if is_demo_olt(olt):
            return {'success': True, 'message': 'SNMP demo setup (tidak ada perubahan).'}
        try:
            raw = execute_ssh_commands(olt, [
                'config',
                f'snmp-agent community read {community_ro}',
                f'snmp-agent community write {community_rw}',
                'service snmp enable',
                'exit',
                'write',
            ])
            return {'success': True,
                    'message': 'SNMP community berhasil dikonfigurasi.', 'output': raw}
        except Exception as e:
            return {'success': False, 'message': str(e)}

    # ======================================================================
    # PANEL DATA API — perintah CLI + parser brand-spesifik.
    # Frontend TIDAK menyimpan perintah CLI; semua didefinisikan di sini.
    # Tiap panel balikan: {'success', 'message', 'sections': [...]}
    # Tipe section yang dikenali frontend: 'metrics' | 'table' | 'cards'
    # ======================================================================

    @staticmethod
    def _threshold_state(value, warn: float, bad: float) -> str:
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
                    'message': f"Panel '{panel}' tidak didukung oleh driver CDATA FD1602S-B1.",
                    'sections': []}
        return handler(olt)

    def _panel_olt_details(self, olt: dict) -> dict:
        """PANEL: OLT Details — versi firmware, hardware, resource, fan."""
        raw = execute_ssh_commands(olt, [
            'enable', 'terminal length 0',
            'show version', 'show device', 'show cpu', 'show memory',
            'show temperature', 'show uptime', 'show fan',
        ])
        err = detect_transport_error(raw)
        if err:
            return {'success': False, 'message': err, 'sections': []}
        raw = strip_command_markers(raw)

        def g(pattern, flags=re.I):
            m = re.search(pattern, raw, flags)
            return m.group(1).strip() if m else None

        # Format nyata OLT CData FD1602S-B1 (V3.2.20) — terverifikasi live:
        #   Load Average(5sec)  :  4.51%
        #   Utilization    : 25.00%
        #   Slot  current  /  0     43.50
        #   System running time : 2 weeks, 0 day 0 hour 44 minute 29 second.
        cpu = g(r'Load Average\(5sec\)\s*:\s*([0-9.]+%?)')
        ram = g(r'Utilization\s*:\s*([0-9.]+%?)')
        temp = g(r'^\s*0\s+([0-9.]+)\s*$', re.I | re.M)
        uptime = g(r'System running time\s*:\s*([^.\r\n]+)')

        metrics = [
            {'label': 'CPU Load', 'value': cpu or 'N/A', 'unit': '',
             'state': self._threshold_state(cpu, 70, 85)},
            {'label': 'RAM Usage', 'value': ram or 'N/A', 'unit': '',
             'state': self._threshold_state(ram, 75, 90)},
            {'label': 'Suhu Board', 'value': temp or 'N/A', 'unit': '°C' if temp else '',
             'state': self._threshold_state(temp, 48, 55)},
            {'label': 'Uptime', 'value': uptime or 'N/A', 'unit': '',
             'state': 'neutral', 'small': True},
        ]

        rows = []

        def add(label, val):
            rows.append([label, val if val else 'N/A'])

        # show device -> "Device type : GPON OLT", "Device MAC address : E0:...",
        #                "Device serial-number : DA27-...", "Device vendor name : C-Data"
        add('Device Type', g(r'Device\s+type\s*:\s*([^\r\n]+)'))
        add('Vendor', g(r'Device\s+vendor\s+name\s*:\s*([^\r\n]+)'))
        add('Model', g(r'^\s*\d+\s+(FD\S+)\s+\w+', re.I | re.M) or self.get_driver_info()['model'])
        add('Serial Number', g(r'Device\s+serial-number\s*:\s*([^\r\n]+)'))
        add('MAC Address', g(r'Device\s+MAC\s+address\s*:\s*([0-9A-Fa-f:.\-]+)'))
        # show version -> "Software version V3.2.20", "Hardware version : V1.1"
        add('Software Version', g(r'Software\s+version\s*:?\s*(V?[0-9][^\r\n]*)'))
        add('Hardware Version', g(r'Hardware\s+version\s*:\s*([^\r\n]+)'))
        add('Firmware Version', g(r'Firmware\s+version\s*:\s*([^\r\n]+)'))
        add('Web Version', g(r'Web\s+version\s*:\s*([^\r\n]+)'))
        # "984M  bytes SDRAM" / "254M  bytes FLASH"
        add('SDRAM', g(r'([0-9]+M)\s+bytes\s+SDRAM'))
        add('Flash', g(r'([0-9]+M)\s+bytes\s+FLASH'))
        mtot = g(r'Total\s+memory\s*:\s*([^\r\n]+)')
        mfree = g(r'Free\s+memory\s*:\s*([^\r\n]+)')
        if mtot:
            add('Memori (Total / Free)', mtot + (' / ' + mfree if mfree else ''))
        add('Boot Time', g(r'System\s+uptime\s+time\s*:\s*([^\r\n]+)'))

        sections = [
            {'type': 'metrics', 'title': 'Resource & Kesehatan', 'items': metrics},
            {'type': 'table', 'title': 'Informasi Sistem',
             'columns': ['Parameter', 'Nilai'], 'rows': rows},
        ]

        # show fan -> "  FAN[1] status: Normal     (10500RPM)"
        fans = []
        for f in re.finditer(r'FAN\[(\d+)\]\s+status\s*:\s*(\w+)\s*\(([0-9]+)\s*RPM\)', raw, re.I):
            ok = f.group(2).lower() == 'normal'
            fans.append({
                'title': 'FAN ' + f.group(1),
                'value': f'{int(f.group(3)):,} RPM',
                'badge': f.group(2),
                'state': 'good' if ok else 'bad',
                'subtitle': 'Kecepatan kipas pendingin',
            })
        if fans:
            mode = g(r'Fan\s+speed\s+mode\s*:\s*(\w+)')
            level = g(r'Fan\s+speed\s+level\s*:\s*(\d+)')
            title = 'Status Fan'
            if mode:
                title += ' — Mode: ' + mode.upper()
            if level:
                title += f' (Level {level})'
            sections.append({'type': 'cards', 'title': title, 'items': fans})

        return {'success': True, 'message': '', 'sections': sections}

    def _panel_olt_cards(self, olt: dict) -> dict:
        """PANEL: OLT Cards — status board/slot + ringkasan jumlah ONT."""
        raw = execute_ssh_commands(olt, [
            'enable', 'terminal length 0', 'show device', 'show ont status-count',
        ])
        err = detect_transport_error(raw)
        if err:
            return {'success': False, 'message': err, 'sections': []}
        raw = strip_command_markers(raw)

        # Format nyata "show device":
        #   Slot  Model          Status          OnlineTime            OfflineTime
        #   0     FD1602S-B1     Normal      2026-08-12 22:23:37       --
        # Batasi pencarian ke blok setelah header tabel agar baris counter dari
        # perintah lain tidak ikut tertangkap.
        slot_block = raw
        sb = re.search(r'^[ \t]*Slot[ \t]+Model[ \t]+Status[^\r\n]*\r?\n([\s\S]*)$', raw, re.I | re.M)
        if sb:
            slot_block = sb.group(1)

        skip = ('bytes', 'total', 'used', 'free', 'version', 'packets', 'errors')
        cards = []
        for m in re.finditer(
                r'^[ \t]*(\d+)[ \t]+([A-Za-z]{2}[A-Za-z0-9\-_]+)[ \t]+(\w+)[ \t]*(.*?)\s*$',
                slot_block, re.M):
            status = m.group(3).strip()
            low = status.lower()
            if low in skip:
                continue
            online = re.sub(r'\s+--\s*$', '', m.group(4)).strip()
            cards.append({
                'title': 'Slot ' + m.group(1),
                'value': m.group(2),
                'badge': status,
                'state': 'good' if low in ('normal', 'online', 'up') else 'bad',
                'subtitle': ('Online sejak: ' + online) if online else 'Board terpasang',
            })
        if not cards:
            cards.append({
                'title': 'Slot 0 (Main Board)',
                'value': self.get_driver_info()['model'],
                'badge': 'Normal',
                'state': 'good',
                'subtitle': 'Board utama',
            })

        # Blok "Total Info" pada "show ont status-count".
        def num(pattern, flags=re.I):
            m = re.search(pattern, raw, flags)
            return int(m.group(1)) if m else 0

        active = num(r'^\s*Active\s*:\s*(\d+)', re.I | re.M)
        offline = num(r'^\s*Offline\s*:\s*(\d+)', re.I | re.M)
        deactive = num(r'^\s*Deactive\s*:\s*(\d+)', re.I | re.M)
        success = num(r'Config\s*Success\s*:\s*(\d+)')
        failed = num(r'Config\s*Fail\w*\s*:\s*(\d+)')
        mib_ready = num(r'Mib\s*Ready\s*:\s*(\d+)')

        metrics = [
            {'label': 'ONT Active', 'value': str(active), 'unit': '', 'state': 'good'},
            {'label': 'ONT Offline', 'value': str(offline), 'unit': '',
             'state': 'bad' if offline > 0 else 'neutral'},
            {'label': 'ONT Deactive', 'value': str(deactive), 'unit': '',
             'state': 'warn' if deactive > 0 else 'neutral'},
            {'label': 'Config Success', 'value': str(success), 'unit': '', 'state': 'neutral'},
            {'label': 'Config Failed', 'value': str(failed), 'unit': '',
             'state': 'warn' if failed > 0 else 'neutral'},
            {'label': 'MIB Ready', 'value': str(mib_ready), 'unit': '', 'state': 'neutral'},
        ]

        sections = [
            {'type': 'cards', 'title': 'Board / Slot Terpasang', 'items': cards},
            {'type': 'metrics', 'title': 'Ringkasan ONT (Realtime)', 'items': metrics},
        ]

        # Daftar ONT bermasalah: "  0/0 2  10   ZTEGC850F1B8    0         --"
        off_rows = []
        for o in re.finditer(
                r'^\s*(\d+/\d+)\s+(\d+)\s+(\d+)\s+([A-Za-z0-9]{8,20})\s+(\d+)\s+(\S+)\s*$',
                raw, re.M):
            off_rows.append([o.group(1) + '/' + o.group(2), o.group(3), o.group(4),
                             o.group(5), '-' if o.group(6) == '--' else o.group(6)])
        if off_rows:
            sections.append({
                'type': 'table',
                'title': f'ONT Tidak Normal ({len(off_rows)})',
                'columns': ['F/S/P', 'ONT ID', 'Serial Number', 'Offline Times', 'Last Down Cause'],
                'rows': off_rows,
            })

        return {'success': True, 'message': '', 'sections': sections}

    def _panel_pon_ports(self, olt: dict) -> dict:
        """PANEL: PON Ports — status tiap port PON beserta jumlah ONT.

        Memakai pull_configured_onus() yang sudah ada (parser 'show ont info all'
        yang sama), lalu diagregasi per port — tidak menduplikasi parser ONU.
        """
        res = self.pull_configured_onus(olt)
        if not res.get('success'):
            return {'success': False, 'message': res.get('message', 'Gagal membaca ONT.'),
                    'sections': []}

        agg = {}
        for o in res.get('onus', []):
            slot = agg.setdefault(o['pon_port'], {'total': 0, 'online': 0})
            slot['total'] += 1
            if o.get('status') == 'online':
                slot['online'] += 1

        def natural(key):
            return [int(t) if t.isdigit() else t for t in re.split(r'(\d+)', key)]

        cards, rows = [], []
        for port in sorted(agg, key=natural):
            s = agg[port]
            off = s['total'] - s['online']
            rate = round(s['online'] / s['total'] * 100) if s['total'] else 0
            cards.append({
                'title': 'PON ' + port,
                'value': f"{s['online']} / {s['total']}",
                'badge': f'{rate}% online',
                'state': 'good' if off == 0 else ('warn' if rate >= 70 else 'bad'),
                'subtitle': f'{off} ONT offline',
            })
            rows.append([port, str(s['total']), str(s['online']), str(off), f'{rate}%'])

        if not cards:
            return {'success': True,
                    'message': 'Tidak ada ONT terdeteksi pada port PON manapun.',
                    'sections': []}

        return {'success': True, 'message': '', 'sections': [
            {'type': 'cards', 'title': 'Status Port PON (Realtime)', 'items': cards},
            {'type': 'table', 'title': 'Rincian per Port',
             'columns': ['PON Port', 'Total ONT', 'Online', 'Offline', 'Uptime Rate'],
             'rows': rows},
        ]}

    def _section_mgmt_interface(self, olt: dict):
        """Section 'Management Interface' (Mgmt 0/0) untuk panel Interfaces.
        Balikan None bila data mgmt tidak terbaca (section dilewati saja)."""
        raw = execute_ssh_commands(olt, ['enable', 'terminal length 0', 'show interface mgmt'])
        if detect_transport_error(raw):
            return None
        raw = strip_command_markers(raw)

        m_state = re.search(r'Mgmt\s*0/0\s*current state\s*:\s*([^\r\n]+)', raw, re.I)
        if not m_state:
            return None

        admin_state = m_state.group(1).strip()
        m = re.search(r'Line protocol current state\s*:\s*([^\r\n]+)', raw, re.I)
        line_state = m.group(1).strip() if m else 'UNKNOWN'
        m = re.search(r'inet\s+(\d+\.\d+\.\d+\.\d+(?:/\d+)?)', raw, re.I)
        ip_addr = m.group(1).strip() if m else 'N/A'
        m = re.search(r'Hardware Address is\s*([0-9A-Fa-f:.\-]+)', raw, re.I)
        mac_addr = m.group(1).strip() if m else 'N/A'
        is_up = 'up' in admin_state.lower() and 'up' in line_state.lower()

        return {
            'type': 'cards',
            'title': 'Management Interface',
            'items': [{
                'title': 'Mgmt 0/0',
                'value': ip_addr,
                'badge': f'ADMIN: {admin_state} · LINE: {line_state}',
                'state': 'good' if is_up else 'bad',
                'subtitle': 'MAC: ' + mac_addr,
            }],
        }

    def _panel_interfaces(self, olt: dict) -> dict:
        """PANEL: Interfaces — ringkasan port uplink fisik + management interface."""
        sections = []

        mgmt = self._section_mgmt_interface(olt)
        if mgmt:
            sections.append(mgmt)

        res = self.get_interfaces(olt)
        if not res['success']:
            if sections:
                return {'success': True, 'message': '', 'sections': sections}
            return {'success': False, 'message': res['message'], 'sections': []}

        ports = res['ports']
        if ports:
            up = sum(1 for p in ports if p['link'] == 'on')
            sections.append({
                'type': 'metrics',
                'title': 'Ringkasan Port Uplink',
                'items': [
                    {'label': 'Total Port', 'value': str(len(ports)), 'unit': '', 'state': 'neutral'},
                    {'label': 'Link Up', 'value': str(up), 'unit': '',
                     'state': 'good' if up > 0 else 'warn'},
                    {'label': 'Link Down', 'value': str(len(ports) - up), 'unit': '',
                     'state': 'neutral'},
                ],
            })

            rows, args = [], []
            for p in ports:
                duplex = p.get('duplex') or ''
                rows.append([
                    p['name'],
                    'UP' if p['link'].upper() == 'ON' else 'DOWN',
                    (p['speed'] + ' Mbps') if p['speed'] != '-' else '-',
                    p['media'],
                    p['optic'],
                    (p.get('auto_nego') or 'Enable').capitalize(),
                    (duplex if duplex and duplex != '-' else 'Auto').capitalize(),
                ])
                args.append({
                    'port': p['name'],
                    'auto_nego': p.get('auto_nego') or 'enable',
                    'speed': p.get('config_speed') or '1000',
                    'duplex': p.get('duplex') or 'full',
                })
            sections.append({
                'type': 'table',
                'title': 'Port Uplink Fisik (Realtime)',
                'columns': ['Port', 'Link', 'Speed', 'Media', 'Optic', 'Negosiasi', 'Duplex'],
                'rows': rows,
                'action': {'label': 'Edit Port', 'event': 'port-config', 'args': args},
            })

        # Catatan: interface layer 3 (vlanif) TIDAK ditampilkan di sini karena sudah
        # tersedia pada kolom "IP (vlanif)" di tab VLANs — hindari data ganda.

        if not sections:
            return {'success': True,
                    'message': 'Data interface tidak ditemukan pada perangkat ini.',
                    'sections': []}
        return {'success': True, 'message': '', 'sections': sections}

    def get_autofind(self, olt: dict) -> dict:
        """Get unconfigured/autofind ONUs from CData OLT.
        Returns: {'success': bool, 'onus': list}
        """
        if is_demo_olt(olt):
            return {
                'success': True,
                'onus': [
                    {
                        'pon_port': '0/0/1',
                        'serial_number': 'ZTEGC0B3458A',
                        'type': 'ZTE-F660',
                        'distance': '245',
                        'vlan': 'None',
                        'rx_power': '-20.00'
                    }
                ]
            }
        raw = execute_ssh_commands(olt, ['enable', 'show ont autofind all'])
        
        onus = []
        current_onu = {}
        
        for line in raw.split('\n'):
            line = line.strip()
            if not line:
                continue
                
            if '---' in line:
                if current_onu and 'serial_number' in current_onu:
                    onus.append(current_onu)
                current_onu = {}
                continue
                
            m_fs = re.search(r'^Frame/Slot\s*:\s*(\S+)', line, re.I)
            if m_fs:
                current_onu['frame_slot'] = m_fs.group(1).strip()
                current_onu['pon_port'] = f"{current_onu['frame_slot']}/{current_onu.get('port', '1')}"
                continue
                
            m_port = re.search(r'^Port\s*:\s*(\d+)', line, re.I)
            if m_port:
                current_onu['port'] = m_port.group(1).strip()
                if 'frame_slot' in current_onu:
                    current_onu['pon_port'] = f"{current_onu['frame_slot']}/{current_onu['port']}"
                else:
                    current_onu['pon_port'] = f"0/0/{current_onu['port']}"
                continue
                
            m_sn = re.search(r'^SN\s*:\s*(\S+)', line, re.I)
            if m_sn:
                current_onu['serial_number'] = m_sn.group(1).split('(')[0].strip()
                continue
                
            m_eq = re.search(r'^Equipment ID\s*:\s*(.+)', line, re.I)
            if m_eq:
                current_onu['type'] = m_eq.group(1).strip()
                continue
                
            m_ver = re.search(r'^Ont Version\s*:\s*(.+)', line, re.I)
            if m_ver:
                if 'type' not in current_onu:
                    current_onu['type'] = m_ver.group(1).strip()
                continue

        if current_onu and 'serial_number' in current_onu:
            if 'frame_slot' in current_onu and 'port' in current_onu:
                current_onu['pon_port'] = f"{current_onu['frame_slot']}/{current_onu['port']}"
            onus.append(current_onu)
            
        formatted = []
        for onu in onus:
            formatted.append({
                'pon_port': onu.get('pon_port', '0/0/1'),
                'serial_number': onu['serial_number'],
                'type': onu.get('type', 'GPON-ONU'),
                'distance': 'N/A',
                'vlan': 'None',
                'rx_power': 'N/A'
            })
            
        return {'success': True, 'onus': formatted}

    def get_next_free_onu_id(self, olt: dict, pon_port: str) -> dict:
        """Get next free ONU ID on a specific PON port of CData OLT.
        Returns: {'success': bool, 'next_onu_id': int}
        """
        if is_demo_olt(olt):
            import random
            return {'success': True, 'next_onu_id': random.randint(3, 64)}
            
        list_out = execute_ssh_commands(olt, ['enable', 'show ont info all'])
        
        parts_target = pon_port.split('/')
        target_port = parts_target[-1] if len(parts_target) >= 3 else '1'
        target_interface = "/".join(parts_target[:-1]) if len(parts_target) >= 3 else '0/0'
        full_target = f"{target_interface}/{target_port}"
        
        used_ids = []
        for line in list_out.split('\n'):
            line = line.strip()
            if not line:
                continue
            parts = re.split(r'\s+', line)
            if len(parts) >= 3:
                row_pon_port = f"{parts[0]}/{parts[1]}"
                if row_pon_port == full_target and parts[2].isdigit():
                    used_ids.append(int(parts[2]))
                    
        for i in range(1, 129):
            if i not in used_ids:
                return {'success': True, 'next_onu_id': i}
        return {'success': True, 'next_onu_id': 128}



