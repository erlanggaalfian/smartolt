import atexit
import threading
import time
import re
import socket
import os
import json
from netmiko import ConnectHandler

# ==============================================================================
# SKIP-WRITE / BATCH FLUSH
# ==============================================================================
# 'write' menyimpan running-config OLT ke flash fisik dan BUTUH ~30-40 detik
# (terukur, 2026-09-16) -- 79% dari total waktu authorize ONU. Command config
# lain berlaku LANGSUNG ke running-config tanpa 'write' (ONU aktif seketika),
# 'write' cuma soal persistensi kalau OLT reboot/mati listrik.
#
# Strategi: buang 'write' dari daftar command per-aksi, tandai OLT itu masih
# "kotor" (ada config belum ke-flash) di file JSON kecil. Proses terpisah
# (flush_pending_write.py, systemd timer tiap 10 menit) baca file ini dan
# kirim 'write' sekali per OLT yang kotor.
#
# Trade-off yang disetujui user (2026-09-16): kalau OLT reboot/mati listrik
# SEBELUM flush berikutnya jalan, config dalam window itu (maks ~10 menit)
# hilang -- ONU yang baru diauth di window itu perlu authorize ulang. Config
# yang sudah lama aktif (sudah ke-flush di siklus sebelumnya) TIDAK terdampak.
_PENDING_WRITE_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'pending_writes.json')
_pending_write_lock = threading.Lock()


def _strip_write_commands(commands: list) -> list:
    """Buang semua variasi 'write' dari daftar command (case-insensitive, strip)."""
    return [c for c in commands if c.strip().lower() != 'write']


def _mark_pending_write(olt: dict):
    """Tandai OLT ini punya config yang belum di-flush ke flash."""
    olt_id = olt.get('id')
    if olt_id is None:
        return
    with _pending_write_lock:
        try:
            data = {}
            if os.path.exists(_PENDING_WRITE_FILE):
                with open(_PENDING_WRITE_FILE, 'r') as f:
                    data = json.load(f)
        except Exception:
            data = {}
        data[str(olt_id)] = time.time()
        try:
            with open(_PENDING_WRITE_FILE, 'w') as f:
                json.dump(data, f)
        except Exception:
            pass


# ==============================================================================
# KOLAM KONEKSI SSH (connection pool)
# ==============================================================================
# Tiap perintah ke OLT dulu membuka handshake SSH baru (1-2 detik). Kolam ini
# menahan sesi tetap terbuka sehingga panggilan berikutnya langsung memakai sesi
# yang sama. Ini keuntungan utama arsitektur daemon di hybrid_architecture_plan.txt.
#
# Hanya berguna pada proses yang hidup lama (uvicorn/FastAPI, cron_sync.py).
# Pada cli.py proses langsung mati, jadi kolam tidak merugikan, hanya tak terpakai.
#
# DUA BAHAYA yang ditangani di sini:
#  1. Netmiko TIDAK aman dipakai banyak thread. Uvicorn menjalankan endpoint
#     sinkron di threadpool, jadi dua permintaan bisa menyentuh OLT yang sama
#     bersamaan dan outputnya saling tertukar. -> satu kunci per OLT.
#  2. Sesi bisa tertinggal di mode config bila daftar perintah driver tidak
#     ditutup 'exit'. Dengan koneksi baru tiap kali, hal itu sembuh sendiri;
#     dengan kolam, mode itu bocor ke panggilan berikutnya. -> prompt diperiksa
#     setelah eksekusi, dan sesi yang menyimpang DIBUANG, bukan dipakai ulang.
_POOL_IDLE_TIMEOUT = 120  # detik; OLT umumnya memutus sesi menganggur sendiri
_pool = {}                # kunci -> {'conn', 'lock', 'last_used', 'prompt'}
_pool_guard = threading.Lock()


def _pool_key(olt: dict) -> tuple:
    return (olt.get('ip'), int(olt.get('ssh_port') or 22), olt.get('username'))


def _get_entry(key: tuple) -> dict:
    """Ambil (atau buat) slot kolam. Kunci per-OLT dibuat sekali di sini."""
    with _pool_guard:
        entry = _pool.get(key)
        if entry is None:
            entry = {'conn': None, 'lock': threading.Lock(), 'last_used': 0.0, 'prompt': ''}
            _pool[key] = entry
        return entry


def _drop(entry: dict) -> None:
    """Tutup dan lupakan koneksi. Dipakai saat sesi rusak, menyimpang, atau kedaluwarsa."""
    conn = entry.get('conn')
    entry['conn'] = None
    entry['prompt'] = ''
    if conn is not None:
        try:
            conn.disconnect()
        except Exception:
            pass


def close_all_connections() -> None:
    """Tutup seluruh sesi. Dipanggil otomatis saat proses berakhir."""
    with _pool_guard:
        entries = list(_pool.values())
    for entry in entries:
        with entry['lock']:
            _drop(entry)


atexit.register(close_all_connections)

# Penanda sintetis yang disisipkan execute_ssh_commands() di antara output tiap perintah.
# Penanda ini TIDAK berasal dari OLT. Parser yang mengambil blok "sampai akhir string"
# (mis. "Untagged Ports:" pada getVlans CData) akan ikut menelannya, sehingga wajib
# dibuang lebih dulu lewat strip_command_markers().
_CMD_MARKER_RE = re.compile(r'^OLT# show .*$', re.MULTILINE)

# Frasa yang menandakan kegagalan transport (koneksi/login), bukan jawaban OLT.
# Cerminan detectTransportError() di driver PHP.
_TRANSPORT_ERRORS = (
    'Error connecting to OLT',
    'Connection timed out',
    'Connection refused',
    'No route to host',
    'Permission denied',
    'Login failed',
    'Timeout waiting for',
)


def strip_command_markers(raw: str) -> str:
    """Buang penanda 'OLT# show <cmd>' buatan helper agar parser hanya melihat output asli."""
    return _CMD_MARKER_RE.sub('', raw or '')


def detect_transport_error(raw: str):
    """Balikan pesan error bila respons OLT menandakan gagal koneksi/login, selain itu None."""
    text = (raw or '').strip()
    if text == '':
        return 'OLT tidak memberikan respons (timeout atau koneksi terputus).'
    low = text.lower()
    for phrase in _TRANSPORT_ERRORS:
        if phrase.lower() in low:
            return text
    return None


def log_debug_cli(ip: str, protocol: str, commands: list, raw_output: str, error: str = None):
    # Disabled by default; set SMARTOLT_DEBUG=1 to enable CLI debug logging.
    # Previous unbounded logging grew to 12 GB in production.
    if not os.environ.get('SMARTOLT_DEBUG'):
        return
    try:
        log_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'debug_cli.log')
        # Cap at 10 MB to prevent disk exhaustion
        try:
            if os.path.exists(log_path) and os.path.getsize(log_path) > 10 * 1024 * 1024:
                with open(log_path, 'w') as f:
                    f.write(f"[Truncated at {time.strftime('%Y-%m-%d %H:%M:%S')} — exceeded 10 MB]\n")
        except OSError:
            pass
        timestamp = time.strftime('%Y-%m-%d %H:%M:%S')
        with open(log_path, 'a', encoding='utf-8') as f:
            f.write(f"\n========================================\n")
            f.write(f"TIMESTAMP: {timestamp}\n")
            f.write(f"OLT IP   : {ip} ({protocol})\n")
            f.write(f"COMMANDS : {', '.join(commands)}\n")
            if error:
                f.write(f"ERROR    : {error}\n")
            f.write(f"--- RAW OUTPUT ---\n")
            f.write(raw_output)
            f.write(f"\n========================================\n")
    except Exception:
        pass

def execute_telnet_commands(ip: str, port: int, username: str, password: str, commands: list) -> str:
    """
    Connect to OLT via raw TCP socket and execute commands.
    This replicates the exact behavior of PHP fsockopen, avoiding Telnet Option Negotiation issues.
    """
    login_log = ""
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.settimeout(10)
        s.connect((ip, port))
        login_log += f"[CONNECTED TO {ip}:{port}]\n"

        def _handle_iac(sock, data: bytes) -> bytes:
            """Balas Telnet IAC negotiation (WILL/WONT/DO/DONT) supaya OLT lanjut kirim prompt."""
            resp = b""
            i = 0
            while i < len(data):
                if data[i] == 0xff and i + 2 < len(data):  # IAC
                    cmd = data[i + 1]
                    opt = data[i + 2]
                    if cmd == 0xfb:      # WILL -> balas DONT
                        resp += b"\xff\xfe" + bytes([opt])
                    elif cmd == 0xfc:    # WONT -> balas DONT
                        resp += b"\xff\xfe" + bytes([opt])
                    elif cmd == 0xfd:    # DO   -> balas WONT
                        resp += b"\xff\xfc" + bytes([opt])
                    elif cmd == 0xfe:    # DONT -> balas WONT
                        resp += b"\xff\xfc" + bytes([opt])
                    i += 3
                else:
                    i += 1
            if resp:
                try:
                    sock.sendall(resp)
                except Exception:
                    pass
            # Kembalikan data tanpa IAC bytes untuk pattern matching
            clean = re.sub(b'\xff[\xfb-\xfe].', b'', data)
            clean = re.sub(b'\xff[\xf0-\xff]', b'', clean)
            return clean

        def read_until(sock, patterns, timeout=10):
            nonlocal login_log
            sock.settimeout(timeout)
            buf = b""
            start_time = time.time()
            while True:
                if time.time() - start_time > timeout:
                    break
                try:
                    chunk = sock.recv(1024)
                    if not chunk:
                        break
                    # Handle Telnet negotiation & dapatkan data bersih
                    clean = _handle_iac(sock, chunk)
                    buf += clean
                    text = buf.decode('ascii', errors='ignore')
                    
                    # Stop if we hit OLT prompt
                    if re.search(r'[#>]\s*$', text):
                        login_log += text
                        return text
                        
                    for pat in patterns:
                        if re.search(pat, text, re.IGNORECASE):
                            login_log += text
                            return text
                except socket.timeout:
                    break
            decoded = buf.decode('ascii', errors='ignore')
            login_log += decoded
            return decoded

        # 1. Read until username/login prompt
        login_pats = [r'login', r'username', r'user', r'name']
        read_until(s, login_pats, timeout=10)
        login_log += f"[SEND USERNAME: {username}]\n"
        s.sendall(username.encode('ascii') + b"\n")
        
        # 2. Read until password prompt
        pass_pats = [r'password', r'pass']
        read_until(s, pass_pats, timeout=10)
        login_log += "[SEND PASSWORD: *****]\n"
        s.sendall(password.encode('ascii') + b"\n")
        
        # 3. Read until prompt
        text = read_until(s, [r'[#>]\s*$'], timeout=10)

        # If user mode (>), escalate to enable mode (#)
        if text.rstrip().endswith('>'):
            login_log += "[SEND: enable]\n"
            s.sendall(b"enable\n")
            res = read_until(s, [r'password', r'pass', r'#\s*$'], timeout=5)
            if re.search(r'password|pass', res, re.IGNORECASE):
                login_log += "[SEND ENABLE PASSWORD: *****]\n"
                s.sendall(password.encode('ascii') + b"\n")
                read_until(s, [r'#\s*$'], timeout=5)

        # Disable paging
        login_log += "[SEND: terminal length 0]\n"
        s.sendall(b"terminal length 0\n")
        read_until(s, [r'#\s*$'], timeout=5)

        def _drain(sock):
            """Buang sisa byte di socket agar tidak terbaca sebagai output perintah berikutnya."""
            sock.settimeout(0.05)
            try:
                while True:
                    if not sock.recv(4096):
                        break
            except Exception:
                pass

        output = ""
        for cmd in commands:
            cmd_clean = cmd.strip()
            if not cmd_clean or cmd_clean == 'terminal length 0':
                continue
            _drain(s)
            s.sendall(cmd_clean.encode('ascii') + b"\n")
            res_text = read_until(s, [r'[#>]\s*$'], timeout=120)
            output += f"\nOLT# show {cmd_clean}\n"
            output += res_text + "\n"

        s.close()
        log_debug_cli(ip, 'TELNET', commands, login_log + "\n" + output)
        return output
    except Exception as e:
        err_msg = f"Error connecting to OLT: {str(e)}"
        log_debug_cli(ip, 'TELNET', commands, login_log, err_msg)
        return err_msg

def execute_ssh_commands(olt: dict, commands: list) -> str:
    """
    Connect to OLT via SSH/Telnet and execute commands.
    """
    if 'write' in [c.strip().lower() for c in commands]:
        commands = _strip_write_commands(commands)
        _mark_pending_write(olt)

    protocol = olt.get('protocol', 'SSH').upper()
    ip = olt.get('ip', '127.0.0.1')
    username = olt.get('username', 'admin')
    password = olt.get('password', '')
    port = int(olt.get('ssh_port')) if olt.get('ssh_port') else (23 if protocol == 'TELNET' else 22)

    # Use custom telnet runner if protocol is TELNET to avoid negotiation issues
    if protocol == 'TELNET':
        return execute_telnet_commands(ip, port, username, password, commands)

    olt_type_upper = str(olt.get('type', '')).upper()
    is_zte = 'ZTE' in olt_type_upper or 'C320' in olt_type_upper or 'C300' in olt_type_upper
    
    device_type = 'generic'
    if is_zte:
        device_type = 'zte_zxros'

    device = {
        'device_type': device_type,
        'host': ip,
        'username': username,
        'password': password,
        'port': port,
        'global_delay_factor': 0.2,
    }
    if is_zte:
        device['secret'] = password

    entry = _get_entry(_pool_key(olt))
    ssh_log = ""

    def _run() -> str:
        """Jalankan perintah pada sesi kolam, membuka sesi baru bila belum ada."""
        nonlocal ssh_log
        if entry['conn'] is None:
            ssh_log += f"[CONNECTING TO {ip}:{port} via Netmiko {device_type}...]\n"
            conn = ConnectHandler(**device)
            prompt = conn.find_prompt()
            ssh_log += f"[PROMPT DETECTED: {prompt}]\n"
            
            if is_zte:
                ssh_log += "[CHECKING/ENTERING ENABLE MODE FOR ZTE...]\n"
                if not conn.check_enable_mode():
                    ssh_log += "[SEND: enable]\n"
                    conn.enable()
                prompt = conn.find_prompt()
                ssh_log += f"[PROMPT DETECTED AFTER ENABLE: {prompt}]\n"
            else:
                if prompt.endswith('>'):
                    ssh_log += "[SEND: enable]\n"
                    conn.send_command('enable')
                    prompt = conn.find_prompt()
                    ssh_log += f"[PROMPT DETECTED AFTER ENABLE: {prompt}]\n"
                    
            ssh_log += "[SEND: terminal length 0]\n"
            conn.send_command('terminal length 0')
            entry['conn'] = conn
            entry['prompt'] = prompt
        else:
            ssh_log += "[REUSING EXISTING SSH SESSION FROM POOL]\n"

        conn = entry['conn']
        output = ""
        for cmd in commands:
            cmd_clean = cmd.strip()
            if not cmd_clean or cmd_clean == 'terminal length 0':
                continue
            ssh_log += f"[SEND SSH CMD: {cmd_clean}]\n"
            cmd_res = conn.send_command(cmd_clean, read_timeout=120, max_loops=5000)
            ssh_log += cmd_res + "\n"
            
            output += f"\nOLT# show {cmd_clean}\n"
            output += cmd_res + "\n"

        # Sesi wajib kembali ke prompt semula. Bila daftar perintah driver
        # meninggalkannya di mode config (kurang 'exit'), sesi dibuang supaya
        # mode itu tidak bocor ke pemanggil berikutnya.
        try:
            if conn.find_prompt() != entry['prompt']:
                ssh_log += "[PROMPT DEVIATED - DROPPING CONNECTION]\n"
                _drop(entry)
            else:
                entry['last_used'] = time.time()
        except Exception as e:
            ssh_log += f"[ERROR DETECTING PROMPT - DROPPING CONNECTION: {str(e)}]\n"
            _drop(entry)

        return output

    # Kunci per-OLT: netmiko tidak aman untuk banyak thread, dan uvicorn
    # menjalankan endpoint sinkron di threadpool. Tanpa ini, dua permintaan ke
    # OLT yang sama bisa saling menimpa output.
    with entry['lock']:
        # Sesi menganggur terlalu lama dianggap sudah diputus OLT.
        if entry['conn'] is not None and time.time() - entry['last_used'] > _POOL_IDLE_TIMEOUT:
            ssh_log += "[IDLE TIMEOUT EXCEEDED - DROPPING POOLED SESSION]\n"
            _drop(entry)

        reused = entry['conn'] is not None
        try:
            res = _run()
            log_debug_cli(ip, 'SSH', commands, ssh_log)
            return res
        except Exception as e:
            ssh_log += f"[ERROR IN RUN: {str(e)}]\n"
            _drop(entry)
            # OLT bisa memutus sesi lama tanpa pemberitahuan. Bila yang gagal
            # adalah sesi pakai-ulang, coba sekali lagi dengan koneksi baru.
            if not reused:
                err_msg = f"Error connecting to OLT: {str(e)}"
                log_debug_cli(ip, 'SSH', commands, ssh_log, err_msg)
                return err_msg
            
            ssh_log += "[RETRYING WITH NEW CONNECTION...]\n"
            try:
                res = _run()
                log_debug_cli(ip, 'SSH', commands, ssh_log)
                return res
            except Exception as retry_err:
                _drop(entry)
                err_msg = f"Error connecting to OLT: {str(retry_err)}"
                log_debug_cli(ip, 'SSH', commands, ssh_log, err_msg)
                return err_msg
