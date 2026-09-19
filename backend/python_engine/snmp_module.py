"""
SmartOLT SNMP Module — ZTE C300/C320 ONU table read + write.
Uses net-snmp CLI tools (snmpwalk/snmpget/snmpset) — no pysnmp dependency.
"""
import subprocess
import re
import logging
from concurrent.futures import ThreadPoolExecutor

log = logging.getLogger(__name__)

# ── ZTE Enterprise OIDs (C300/C320 branch .1012) ──
ZTE_ROOT = '1.3.6.1.4.1.3902'
ONU_TABLE_BASE = f'{ZTE_ROOT}.1012.3.28'

# ONU identity (index = ifIndex.onuId)
OID_ONU_TYPE       = f'{ONU_TABLE_BASE}.1.1.1'
OID_ONU_NAME       = f'{ONU_TABLE_BASE}.1.1.2'
OID_ONU_DESC       = f'{ONU_TABLE_BASE}.1.1.3'
OID_ONU_SN         = f'{ONU_TABLE_BASE}.1.1.5'
OID_ONU_ADMIN      = f'{ONU_TABLE_BASE}.1.1.17'

# ONU state
OID_ONU_PHASE      = f'{ONU_TABLE_BASE}.2.1.4'
OID_ONU_DOWN_CAUSE = f'{ONU_TABLE_BASE}.2.1.7'

# ONU Rx power (per-port)
OID_ONU_RX = f'{ZTE_ROOT}.1012.3.50.12.1.1.10'

# ONU traffic counters — OID resmi ZTE MIB (ZTE-AN-PON-PERF-MIB.mib,
# zxAnPonOnuIfCurrPerfTable), DIVERIFIKASI dari dokumen MIB resmi +
# test live (delta counter naik konsisten). Index = ifIndex Type-1
# (rack.shelf.slot.port, lihat _encode_ifindex_type1) diikuti onu_id.
# GANTI dari formula Type-3 composite hasil reverse-engineering awal
# (OID_ONU_RX_OCTETS/OID_ONU_TX_OCTETS lama) — sekarang sumber resmi.
ONU_PERF_BASE = f'{ZTE_ROOT}.1082.500.4.2.2.2.1'
OID_ONU_RX_OCTETS = f'{ONU_PERF_BASE}.1'
OID_ONU_RX_PACKETS = f'{ONU_PERF_BASE}.2'
OID_ONU_RX_DISCARD_PACKETS = f'{ONU_PERF_BASE}.11'
OID_ONU_RX_ERR_PACKETS = f'{ONU_PERF_BASE}.12'
OID_ONU_TX_OCTETS = f'{ONU_PERF_BASE}.44'
OID_ONU_TX_PACKETS = f'{ONU_PERF_BASE}.45'

# ── CDATA Enterprise OIDs (FD1602SB1, enterprise 34592) ──
CDATA_ROOT = '1.3.6.1.4.1.34592'
# onuCurStatsTable — OID resmi (user-provided MIB sheet), DIVERIFIKASI live
# (delta counter naik konsisten, index formula cross-checked terhadap
# beberapa ONU dikenal). Index = 0x480000 | ((port-1)<<12) | onu_id.
CDATA_ONU_STATS_BASE = f'{CDATA_ROOT}.1.3.100.12.7.1'
OID_CDATA_ONU_TX_PACKETS = f'{CDATA_ONU_STATS_BASE}.2'
OID_CDATA_ONU_RX_PACKETS = f'{CDATA_ONU_STATS_BASE}.3'
OID_CDATA_ONU_TX_OCTETS = f'{CDATA_ONU_STATS_BASE}.4'
OID_CDATA_ONU_RX_OCTETS = f'{CDATA_ONU_STATS_BASE}.5'

# ONU optical distance (meter). ZTE: OID publik dari proyek nms-ztec320
# (github.com/s4lfanet/nms-ztec320, olt_adapters/snmp_oids.py), index =
# ifIndex Type-1 (sama _encode_ifindex_type1) + onu_id. DIVERIFIKASI live:
# nilai SNMP 4099 cocok PERSIS dgn CLI resmi "show gpon onu detail-info"
# -> "ONU Distance: 4099m". CDATA: OID resmi dari MIB NSCRTV-FTTX-GPON-MIB
# (enterprise 17409, root sysObjectID device TMY asli -- BUKAN vendor lain,
# device TMY yg terdaftar 'CDATA FD1602SB1' sebenarnya expose tree 17409),
# UNITS "Meter" tertulis eksplisit di MIB, index = _encode_cdata_onu_stats_index
# (sama dgn traffic CDATA). Live-verified: onu pon 0/0/1 onu_id=1 -> 443m.
OID_ONU_DISTANCE_ZTE = f'{ZTE_ROOT}.1082.500.10.2.3.10.1.2'
OID_ONU_DISTANCE_CDATA = '1.3.6.1.4.1.17409.2.8.4.1.1.9'

# Unconfigured ONU (C320 V1.2.x)
OID_UNCFG_SN = f'{ZTE_ROOT}.1012.3.13.3.1.2'

# System
OID_SYS_DESCR = '1.3.6.1.2.1.1.1.0'
OID_SYS_UPTIME = '1.3.6.1.2.1.1.3.0'
OID_SYS_CONTACT = '1.3.6.1.2.1.1.4.0'
OID_SYS_NAME = '1.3.6.1.2.1.1.5.0'
OID_SYS_LOCATION = '1.3.6.1.2.1.1.6.0'
OID_BOARD_TEMP_ZTE = f'{ZTE_ROOT}.1015.2.1.3.2.0'
OID_BOARD_TEMP_CDATA = '1.3.6.1.4.1.34592.1.3.100.1.8.6.0'

# Board (index = rack.shelf.slot)
OID_BOARD_NAME   = f'{ZTE_ROOT}.1015.2.1.1.3.1.4'
OID_BOARD_STATUS = f'{ZTE_ROOT}.1015.2.1.1.3.1.5'

# Phase state enum
PHASE_STATE = {
    0: 'logging', 1: 'los', 2: 'sync_mib', 3: 'working',
    4: 'dying_gasp', 5: 'auth_failed', 6: 'offline',
}

# Last-down-cause enum
DOWN_CAUSE = {
    0: 'Normal', 1: 'LOS', 2: 'LOSi', 3: 'LOFi', 4: 'SFi',
    5: 'LOAi', 6: 'LOAMi', 7: 'Deactivated', 8: 'Manual', 9: 'Power Down',
}


def _run(cmd: list, timeout: int = 30) -> str:
    """Run snmp* command, return stdout or '' on error."""
    try:
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
        return r.stdout.strip()
    except Exception as e:
        log.warning(f"SNMP command failed: {e}")
        return ''


def _snmp_host(ip: str, community: str, port: int = 161) -> list:
    """Base args for snmp CLI."""
    host = f'{ip}:{port}' if port != 161 else ip
    return ['-v2c', '-c', community, host]


def snmpget(ip: str, community: str, oid: str, port: int = 161) -> str:
    """Single SNMP GET, return value string or ''."""
    out = _run(['snmpget', '-Oqv', '-t', '5', '-r', '1'] + _snmp_host(ip, community, port) + [oid])
    return out.strip().strip('"')


def snmpwalk(ip: str, community: str, oid: str, port: int = 161, timeout: int = 60) -> list:
    """SNMP WALK (uses bulkwalk for speed), return list of (index, value) tuples."""
    # Try snmpbulkwalk first (15x faster for large tables), fallback to snmpwalk
    out = _run(
        ['snmpbulkwalk', '-Oqn', '-t', '5', '-r', '1', '-Cc'] + _snmp_host(ip, community, port) + [oid],
        timeout=timeout
    )
    if not out:
        out = _run(
            ['snmpwalk', '-Oqn', '-t', '5', '-r', '1', '-Cc'] + _snmp_host(ip, community, port) + [oid],
            timeout=timeout
        )
    results = []
    for line in out.splitlines():
        line = line.strip()
        if not line:
            continue
        parts = line.split(' ', 1)
        if len(parts) == 2:
            full_oid, val = parts
            # Normalize: strip leading dot from full_oid
            full_oid = full_oid.lstrip('.')
            idx = full_oid[len(oid):].lstrip('.')
            results.append((idx, val.strip().strip('"')))
    return results


def snmpset_int(ip: str, community: str, oid: str, value: int, port: int = 161) -> bool:
    """SNMP SET integer value. Returns True on success."""
    out = _run(['snmpset', '-Oqv', '-t', '5', '-r', '1'] + _snmp_host(ip, community, port) + [oid, 'i', str(value)])
    return bool(out) and 'Error' not in out


def snmpset_str(ip: str, community: str, oid: str, value: str, port: int = 161) -> bool:
    """SNMP SET string value. Returns True on success."""
    out = _run(['snmpset', '-Oqv', '-t', '5', '-r', '1'] + _snmp_host(ip, community, port) + [oid, 's', value])
    return bool(out) and 'Error' not in out


def _encode_ifindex_c300(slot: int, port: int) -> int:
    """C300/C320 composite ifIndex: 0x10000000 | (slot<<16) | (port<<8)."""
    return 0x10000000 | (slot << 16) | (port << 8)


def _encode_ifindex_type1(slot: int, pon_no: int, rack: int = 1, shelf: int = 1) -> int:
    """ifIndex Type-1 RESMI (dokumen MIB ZTE, bab 2.1.1): 32-bit penuh
    type(4)|rack(4)|shelf(8)|slot(8)|port(8). BEDA dari _encode_ifindex_c300
    (skema 24-bit dipakai tabel identitas ONU) — jangan disamakan, sudah
    diverifikasi live: slot=2,pon_no=4 -> ifIndex 285278724 (0x11010204),
    cocok data pon 1/2/4 sungguhan. Dipakai tabel performance resmi
    (zxAnPonOnuIfCurrPerfTable, root .1082.500.4.2.2.2.1).
    """
    return (1 << 28) | (rack << 24) | (shelf << 16) | (slot << 8) | pon_no


def get_onu_signal_snmp(ip: str, community: str, pon_port: str, onu_id: int,
                        port: int = 161, vendor: str = 'zte') -> dict:
    """Fast single-ONU signal + traffic via SNMP only (no CLI, no VTY).

    Returns: {'success': bool, 'rx_onu': float|None, 'status': str,
              'traffic_rx_octets': int|None, 'traffic_tx_octets': int|None,
              'traffic_rx_packets': int|None, 'traffic_tx_packets': int|None}
    Note: rx_olt (OLT-side Rx) not available via SNMP — returns None.
    """
    # Parse pon_port "1/2/6" -> slot=2, port=6
    parts = pon_port.split('/')
    if len(parts) == 3:
        slot, pon_no = int(parts[1]), int(parts[2])
    elif len(parts) == 2:
        slot, pon_no = int(parts[0]), int(parts[1])
    else:
        return {'success': False, 'rx_onu': None, 'rx_olt': None, 'status': 'unknown',
                'traffic_rx_octets': None, 'traffic_tx_octets': None,
                'traffic_rx_packets': None, 'traffic_tx_packets': None}

    if vendor == 'cdata':
        # CDATA: Rx power not readily available as single-OID get; return traffic only
        traffic = get_onu_traffic_cdata(ip, community, pon_no, onu_id, port)
        distance_m = get_onu_distance_cdata(ip, community, pon_no, onu_id, port)
        return {
            'success': True, 'rx_onu': None, 'rx_olt': None, 'status': 'unknown',
            'traffic_rx_octets': traffic.get('rx_octets'),
            'traffic_tx_octets': traffic.get('tx_octets'),
            'traffic_rx_packets': traffic.get('rx_packets'),
            'traffic_tx_packets': traffic.get('tx_packets'),
            'distance_m': distance_m,
        }

    # ZTE: identity table ifIndex
    if_idx = _encode_ifindex_c300(slot, pon_no)
    # Rx power OID index = ifIndex.onuId.1 (first UNI port)
    rx_oid = f'{OID_ONU_RX}.{if_idx}.{onu_id}.1'
    admin_oid = f'{OID_ONU_ADMIN}.{if_idx}.{onu_id}'
    phase_oid = f'{OID_ONU_PHASE}.{if_idx}.{onu_id}'

    rx_raw = snmpget(ip, community, rx_oid, port)
    admin_raw = snmpget(ip, community, admin_oid, port)
    phase_raw = snmpget(ip, community, phase_oid, port)

    rx_dbm = convert_rx_raw(rx_raw)

    # Determine status
    try:
        phase = int(phase_raw) if phase_raw else 0
    except (ValueError, TypeError):
        phase = 0
    try:
        admin = int(admin_raw) if admin_raw else 1
    except (ValueError, TypeError):
        admin = 1

    if phase == 3:
        status = 'online'
    elif admin == 2:
        status = 'disabled'
    else:
        status = 'offline'

    # Traffic counters via performance table (separate ifIndex encoding)
    traffic = get_onu_traffic(ip, community, slot, pon_no, onu_id, port)

    # Distance via SNMP (cheap single-OID get)
    distance_m = get_onu_distance_zte(ip, community, slot, pon_no, onu_id, port)

    return {
        'success': True,
        'rx_onu': rx_dbm,
        'rx_olt': None,  # not available via SNMP
        'status': status,
        'traffic_rx_octets': traffic.get('rx_octets'),
        'traffic_tx_octets': traffic.get('tx_octets'),
        'traffic_rx_packets': traffic.get('rx_packets'),
        'traffic_tx_packets': traffic.get('tx_packets'),
        'distance_m': distance_m,
    }


def get_onu_traffic(ip: str, community: str, slot: int, pon_no: int, onu_no: int,
                     port: int = 161) -> dict:
    """Ambil counter kumulatif Rx/Tx octets+packets satu ONU via SNMP,
    dari tabel resmi ZTE zxAnPonOnuIfCurrPerfTable (index: ifIndex Type-1
    resmi + onu_no).

    Nilai adalah counter kumulatif sejak boot OLT (mirip ifHCInOctets), BUKAN
    kecepatan instan — untuk grafik Mbps, ambil 2 sample + delta waktu lalu
    hitung (delta_bytes * 8) / delta_detik / 1_000_000, sama seperti cara kerja
    MRTG/SNMP graphing pada umumnya.
    """
    idx = f'{_encode_ifindex_type1(slot, pon_no)}.{onu_no}'
    rx = snmpget(ip, community, f'{OID_ONU_RX_OCTETS}.{idx}', port)
    tx = snmpget(ip, community, f'{OID_ONU_TX_OCTETS}.{idx}', port)
    rx_pkt = snmpget(ip, community, f'{OID_ONU_RX_PACKETS}.{idx}', port)
    tx_pkt = snmpget(ip, community, f'{OID_ONU_TX_PACKETS}.{idx}', port)
    try:
        rx_val = int(rx) if rx else None
    except ValueError:
        rx_val = None
    try:
        tx_val = int(tx) if tx else None
    except ValueError:
        tx_val = None
    try:
        rx_pkt_val = int(rx_pkt) if rx_pkt else None
    except ValueError:
        rx_pkt_val = None
    try:
        tx_pkt_val = int(tx_pkt) if tx_pkt else None
    except ValueError:
        tx_pkt_val = None
    return {
        'rx_octets': rx_val, 'tx_octets': tx_val,
        'rx_packets': rx_pkt_val, 'tx_packets': tx_pkt_val,
    }


def _encode_cdata_onu_stats_index(port_no: int, onu_id: int) -> int:
    """CDATA onuCurStatsTable index: 0x480000 | ((port_no-1)<<12) | onu_id.
    DITEMUKAN reverse-engineering dari data live (bukan dokumen resmi
    eksplisit index format), lalu DIVERIFIKASI: enc(1,48)=4718640 cocok
    persis dengan baris yang benar-benar terdaftar di walk tabel index
    OLT TMY, dan delta counter naik konsisten pada test 2-sample 30s.
    port_no = nomor PON port fisik (1-based, dari 'x/y/PORT' pon_port DB).
    """
    return 0x480000 | ((port_no - 1) << 12) | onu_id


def get_onu_traffic_cdata(ip: str, community: str, port_no: int, onu_id: int,
                           port: int = 161) -> dict:
    """Ambil counter kumulatif Rx/Tx octets+packets satu ONU via SNMP,
    dari tabel resmi CDATA onuCurStatsTable (root .34592.1.3.100.12.7).

    Nilai adalah counter kumulatif (Counter64) — sama seperti versi ZTE,
    pakai 2 sample + delta waktu untuk grafik Mbps.
    """
    idx = _encode_cdata_onu_stats_index(port_no, onu_id)
    rx = snmpget(ip, community, f'{OID_CDATA_ONU_RX_OCTETS}.{idx}', port)
    tx = snmpget(ip, community, f'{OID_CDATA_ONU_TX_OCTETS}.{idx}', port)
    rx_pkt = snmpget(ip, community, f'{OID_CDATA_ONU_RX_PACKETS}.{idx}', port)
    tx_pkt = snmpget(ip, community, f'{OID_CDATA_ONU_TX_PACKETS}.{idx}', port)
    try:
        rx_val = int(rx) if rx else None
    except ValueError:
        rx_val = None
    try:
        tx_val = int(tx) if tx else None
    except ValueError:
        tx_val = None
    try:
        rx_pkt_val = int(rx_pkt) if rx_pkt else None
    except ValueError:
        rx_pkt_val = None
    try:
        tx_pkt_val = int(tx_pkt) if tx_pkt else None
    except ValueError:
        tx_pkt_val = None
    return {
        'rx_octets': rx_val, 'tx_octets': tx_val,
        'rx_packets': rx_pkt_val, 'tx_packets': tx_pkt_val,
    }


def get_onu_distance_zte(ip: str, community: str, slot: int, pon_no: int, onu_no: int,
                          port: int = 161) -> int | None:
    """Jarak optik ONU (meter) via SNMP resmi ZTE. Index = ifIndex Type-1 + onu_id."""
    idx = f'{_encode_ifindex_type1(slot, pon_no)}.{onu_no}'
    raw = snmpget(ip, community, f'{OID_ONU_DISTANCE_ZTE}.{idx}', port)
    try:
        return int(raw) if raw else None
    except ValueError:
        return None


def get_onu_distance_cdata(ip: str, community: str, port_no: int, onu_id: int,
                            port: int = 161) -> int | None:
    """Jarak optik ONU (meter) via SNMP resmi CDATA. Index = sama traffic CDATA."""
    idx = _encode_cdata_onu_stats_index(port_no, onu_id)
    raw = snmpget(ip, community, f'{OID_ONU_DISTANCE_CDATA}.{idx}', port)
    try:
        return int(raw) if raw else None
    except ValueError:
        return None


def _decode_ifindex_c300(ifindex: int) -> tuple:
    """Decode C300/C320 ifIndex → (rack, slot, port). Rack is always 1."""
    slot = (ifindex >> 16) & 0xFF
    port = (ifindex >> 8) & 0xFF
    return (1, slot, port)


def _parse_index(idx: str) -> tuple:
    """Parse 'ifIndex.onuId' or 'ifIndex.onuId.port' → (ifIndex_int, onu_id_int).
    The 3rd component (onuPort) is ignored for ONU identity."""
    parts = idx.split('.')
    if len(parts) >= 2:
        return (int(parts[0]), int(parts[1]))
    return (0, 0)


def _parse_sn(raw: str) -> str:
    """Convert hex-byte SN to readable string.
    Input: '5A 54 45 47 CC 9A DE 10 ' or 'ZTEGCC9ADE10'
    Output: 'ZTEGCC9ADE10'"""
    raw = raw.strip().strip('"')
    # If already ASCII string (no hex spaces)
    if ' ' not in raw and len(raw) <= 16:
        return raw
    # Hex bytes: first 4 = vendor ASCII, rest = hex serial
    hex_bytes = raw.split()
    try:
        vendor = ''.join(chr(int(b, 16)) for b in hex_bytes[:4])
        serial = ''.join(f'{int(b, 16):02X}' for b in hex_bytes[4:])
        return vendor + serial
    except (ValueError, IndexError):
        return raw.replace(' ', '')


def convert_rx_raw(raw_val: str) -> float | None:
    """Convert raw Rx power to dBm. Auto-detect scale by magnitude.
    
    Sentinel/special: 0, 65535, -80000 → None (no signal).
    Milli-dBm: -50000..-3000 → /1000
    0.1 dBm: -500..-5 → /10
    Legacy positive: raw>0 → raw*0.002-30
    """
    try:
        raw = int(raw_val)
    except (ValueError, TypeError):
        return None
    
    if raw == 0 or raw == 65535 or raw == -80000:
        return None
    
    if -50000 <= raw <= -3000:
        return round(raw / 1000.0, 2)
    elif -500 <= raw <= -5:
        return round(raw / 10.0, 2)
    elif raw > 0:
        return round(raw * 0.002 - 30, 2)
    
    return None


def get_system_info(ip: str, community: str, port: int = 161, vendor: str = 'zte') -> dict:
    """Get OLT system info via SNMP.

    vendor: 'zte' (default, C300/C320) atau 'cdata' (FD1602SB1) — tiap vendor
    pakai OID temperature proprietary berbeda; sysDescr/uptime/name/location
    tetap OID standar MIB-2, sama untuk semua vendor.
    """
    temp_oid = OID_BOARD_TEMP_CDATA if vendor == 'cdata' else OID_BOARD_TEMP_ZTE
    temp_raw = snmpget(ip, community, temp_oid, port)
    try:
        temp_val = int(temp_raw) if temp_raw else None
    except ValueError:
        temp_val = None
    return {
        'sys_descr': snmpget(ip, community, OID_SYS_DESCR, port),
        'uptime': snmpget(ip, community, OID_SYS_UPTIME, port),
        'sys_name': snmpget(ip, community, OID_SYS_NAME, port),
        'sys_location': snmpget(ip, community, OID_SYS_LOCATION, port),
        'sys_contact': snmpget(ip, community, OID_SYS_CONTACT, port),
        'temperature_c': temp_val,
    }


def get_onu_table(ip: str, community: str, port: int = 161) -> list:
    """Walk ONU table, return list of dicts with SN, name, type, admin, phase, rx.
    
    Each entry: {
        'if_index': int, 'onu_id': int, 'slot': int, 'port': int,
        'sn': str, 'name': str, 'type': str,
        'admin_state': int (1=active, 2=disabled),
        'phase_state': int, 'phase_label': str,
        'rx_raw': str, 'rx_dbm': float|None,
        'down_cause': int, 'down_cause_label': str,
    }
    """
    # 8 walk independen (kolom beda), jalankan paralel via thread pool --
    # tiap walk itu I/O-bound (subprocess snmpbulkwalk nunggu jawaban network),
    # jadi GIL bukan penghalang. Sebelumnya serial ~8x lipat lebih lambat utk
    # OLT besar (mis. TGR 2061 ONU: >5 menit -> ~1 menit).
    oids = [OID_ONU_SN, OID_ONU_NAME, OID_ONU_TYPE, OID_ONU_DESC,
            OID_ONU_ADMIN, OID_ONU_PHASE, OID_ONU_DOWN_CAUSE, OID_ONU_RX]
    with ThreadPoolExecutor(max_workers=8) as pool:
        results = list(pool.map(
            lambda oid: snmpwalk(ip, community, oid, port, timeout=120), oids
        ))
    (sn_data, name_data, type_data, desc_data,
     admin_data, phase_data, cause_data, rx_data) = results
    
    # Build lookup dicts by index
    def to_dict(data):
        return {idx: val for idx, val in data}
    
    sn_map = to_dict(sn_data)
    name_map = to_dict(name_data)
    type_map = to_dict(type_data)
    desc_map = to_dict(desc_data)
    admin_map = to_dict(admin_data)
    phase_map = to_dict(phase_data)
    cause_map = to_dict(cause_data)
    rx_map = to_dict(rx_data)
    
    # Rx index is ifIndex.onuId.onuPort (3 parts), not ifIndex.onuId (2 parts)
    # Build rx_map keyed by (ifIndex, onuId)
    rx_map = {}
    for idx, val in rx_data:
        parts = idx.split('.')
        if len(parts) >= 2:
            key = f"{parts[0]}.{parts[1]}"
            rx_map[key] = val  # take first port's Rx
    
    # Collect all unique ifIndex values from SN walk
    onus = []
    seen = set()
    
    for idx, sn in sn_data:
        if_idx, onu_id = _parse_index(idx)
        if (if_idx, onu_id) in seen:
            continue
        seen.add((if_idx, onu_id))
        
        rack, slot, pon_port = _decode_ifindex_c300(if_idx)
        # ponytail: idx hilang dari phase_map berarti walk SNMP itu
        # timeout/parsial utk ONU ini — phase_state=None (bukan tebak 0)
        # supaya caller SKIP overwrite status, bukan salah tulis offline.
        phase_raw = phase_map.get(idx)
        phase = int(phase_raw) if phase_raw is not None else None
        admin = int(admin_map.get(idx, '1'))
        cause = int(cause_map.get(idx, '0'))
        rx_raw = rx_map.get(idx, '')
        
        onus.append({
            'if_index': if_idx,
            'onu_id': onu_id,
            'rack': rack,
            'slot': slot,
            'port': pon_port,
            'pon_port': f'gpon-olt_{rack}/{slot}/{pon_port}',
            'sn': _parse_sn(sn),
            'name': name_map.get(idx, ''),
            'type': type_map.get(idx, ''),
            'desc': desc_map.get(idx, ''),
            'admin_state': admin,
            'phase_state': phase,
            'phase_label': PHASE_STATE.get(phase, f'unknown({phase})'),
            'down_cause': cause,
            'down_cause_label': DOWN_CAUSE.get(cause, f'unknown({cause})'),
            'rx_raw': rx_raw,
            'rx_dbm': convert_rx_raw(rx_raw),
        })
    
    return onus


def get_unconfigured(ip: str, community: str, port: int = 161) -> list:
    """Walk unconfigured ONU SNs."""
    data = snmpwalk(ip, community, OID_UNCFG_SN, port)
    results = []
    for idx, sn in data:
        if_idx, onu_id = _parse_index(idx)
        rack, slot, pon_port = _decode_ifindex_c300(if_idx)
        results.append({
            'if_index': if_idx,
            'onu_id': onu_id,
            'rack': rack,
            'slot': slot,
            'port': pon_port,
            'sn': _parse_sn(sn),
        })
    return results


def get_boards(ip: str, community: str, port: int = 161) -> list:
    """Walk board table."""
    name_data = snmpwalk(ip, community, OID_BOARD_NAME, port)
    status_data = snmpwalk(ip, community, OID_BOARD_STATUS, port)
    status_map = {idx: val for idx, val in status_data}
    
    boards = []
    for idx, name in name_data:
        boards.append({
            'index': idx,
            'name': name,
            'status': int(status_map.get(idx, '0')),
        })
    return boards


# ── SNMP Write ──

def set_onu_admin(ip: str, rw_community: str, if_index: int, onu_id: int, enable: bool, port: int = 161) -> bool:
    """Enable (1) or disable (2) ONU via SNMP SET."""
    oid = f'{OID_ONU_ADMIN}.{if_index}.{onu_id}'
    val = 1 if enable else 2
    return snmpset_int(ip, rw_community, oid, val, port)


def set_onu_name(ip: str, rw_community: str, if_index: int, onu_id: int, name: str, port: int = 161) -> bool:
    """Set ONU name via SNMP SET."""
    oid = f'{OID_ONU_NAME}.{if_index}.{onu_id}'
    return snmpset_str(ip, rw_community, oid, name, port)


def set_onu_desc(ip: str, rw_community: str, if_index: int, onu_id: int, desc: str, port: int = 161) -> bool:
    """Set ONU description via SNMP SET."""
    oid = f'{OID_ONU_DESC}.{if_index}.{onu_id}'
    return snmpset_str(ip, rw_community, oid, desc, port)
