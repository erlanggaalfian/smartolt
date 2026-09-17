"""Self-check driver ZTE C320: get_onu_signal harus balik dict, bukan None.

Bug #237: badan get_onu_signal pernah terpotong jadi `...` sehingga fungsi
mengembalikan None. PHP lalu membaca $d['status'] ?? 'offline' dan rx jadi
'N/A' -> ONU selalu tampil Offline padahal perangkat hidup.

Keluaran OLT di bawah disalin apa adanya dari hasildebug.txt (OLT ZTE asli,
gpon-onu_1/1/2:31), jadi regex diuji terhadap format sebenarnya.
"""
import os
import sys

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
import drivers.zte_c320 as zte

# --- Keluaran nyata dari OLT (hasildebug.txt) ---
ATTENUATION = """
           OLT                  ONU              Attenuation
--------------------------------------------------------------------------
 up      Rx :-30.969(dbm)      Tx:2.469(dbm)        33.438(dB)

 down    Tx :7.046(dbm)        Rx:-22.832(dbm)      29.878(dB)
"""

IP_HOST = """
Host ID:            1
Host name:          omci_ipv4_pppoe_1
IP addres:          0.0.0.0
MAC address:        e466.abe1.16d2
Current IP address: 172.23.45.64
Current mask:       255.255.255.255
"""

OFFLINE_OUT = "gpon-onu_1/1/2:31 is not online\n"

OLT = {'ip': '10.0.0.1', 'username': 'u', 'password': 'p',
       'protocol': 'TELNET', 'ssh_port': 23, 'type': 'ZTE C320'}
ONU = {'pon_port': '1/1/2', 'onu_id': 31}

_calls = []


def fake_ssh(olt, commands):
    _calls.append(list(commands))
    joined = ' '.join(commands)
    if 'attenuation' in joined:
        return ATTENUATION
    return IP_HOST


zte.execute_ssh_commands = fake_ssh
d = zte.OltZteC320Driver()

# 1. Tidak boleh None (inti bug)
r = d.get_onu_signal(OLT, ONU)
assert r is not None, 'get_onu_signal balik None -> ONU selalu Offline'
assert isinstance(r, dict), f'harus dict, dapat {type(r)}'

# 2. Nilai sesuai keluaran OLT nyata
assert r['status'] == 'online', r['status']
assert r['rx_onu'] == -22.832, r['rx_onu']
assert r['rx_olt'] == -30.969, r['rx_olt']
assert r['pppoe_ip'] == '172.23.45.64', r['pppoe_ip']
assert r['success'] is True

# 3. Kunci yang dibaca onu-data.php harus lengkap
for k in ('success', 'rx_onu', 'rx_olt', 'status', 'pppoe_ip', 'log'):
    assert k in r, f'kunci {k} hilang'

# 4. ONU mati -> offline, dan IP tidak ditarik (hemat sesi VTY)
_calls.clear()
zte.execute_ssh_commands = lambda olt, c: (_calls.append(list(c)), OFFLINE_OUT)[1]
r_off = d.get_onu_signal(OLT, ONU)
assert r_off['status'] == 'offline', r_off['status']
assert len(_calls) == 1, f'ONU offline tidak boleh query IP: {_calls}'

# 5. include_ip=False -> cukup satu perintah
_calls.clear()
zte.execute_ssh_commands = fake_ssh
r_noip = d.get_onu_signal(OLT, ONU, include_ip=False)
assert r_noip['status'] == 'online'
assert len(_calls) == 1, f'include_ip=False harus 1 perintah: {_calls}'

# 6. Tidak ada stub terpotong tersisa di driver
src = open(os.path.join(os.path.dirname(os.path.abspath(__file__)),
                        'drivers', 'zte_c320.py'), encoding='utf-8').read()
assert 'omitted for brevity' not in src, 'masih ada badan fungsi terpotong'

# 7. Semua method publik driver balik non-None saat mode demo
demo = dict(OLT, ip='127.0.0.1')
for name in ('get_onu_signal', 'check_connection'):
    assert getattr(d, name)(demo, ONU) if name != 'check_connection' \
        else d.check_connection(demo), f'{name} balik falsy di mode demo'

print('test_zte_signal.py: semua pemeriksaan LULUS')
