"""Self-check kolam koneksi SSH di helper.py (#235). Jalankan: python test_pool.py

Tanpa framework. ConnectHandler diganti tiruan agar tak butuh OLT sungguhan.
"""
import os
import sys
import threading
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import helper

OLT = {'ip': '10.0.0.1', 'username': 'admin', 'password': 'x', 'protocol': 'SSH', 'ssh_port': 22}
OLT2 = dict(OLT, ip='10.0.0.2')

opened = []          # tiap FakeConn yang pernah dibuat
fail_next = []       # bila berisi, send_command berikutnya melempar


class FakeConn:
    def __init__(self, **kw):
        self.kw = kw
        self.sent = []
        self.closed = False
        self.prompt = 'OLT#'
        opened.append(self)

    def find_prompt(self):
        return self.prompt

    def check_enable_mode(self):
        return True

    def enable(self):
        pass

    def send_command(self, cmd, *args, **kwargs):
        if fail_next:
            fail_next.pop()
            raise OSError('socket closed')
        self.sent.append(cmd)
        return f'out:{cmd}'

    def disconnect(self):
        self.closed = True


def reset():
    helper.close_all_connections()
    helper._pool.clear()
    opened.clear()
    fail_next.clear()


helper.ConnectHandler = FakeConn

# --- 1. Sesi dipakai ulang -------------------------------------------------
reset()
a = helper.execute_ssh_commands(OLT, ['show a'])
b = helper.execute_ssh_commands(OLT, ['show b'])
assert 'out:show a' in a and 'out:show b' in b, (a, b)
assert len(opened) == 1, f'harus 1 koneksi, dapat {len(opened)}'

# Penanda sintetis tetap ada — parser driver bergantung padanya.
assert 'OLT# show show a' in a, a

# Inti penghematan: 'terminal length 0' dikirim SEKALI saat sesi dibuat, tidak
# diulang tiap panggilan seperti pada versi tanpa kolam.
assert opened[0].sent.count('terminal length 0') == 1, opened[0].sent
assert opened[0].sent == ['terminal length 0', 'show a', 'show b'], opened[0].sent
assert opened[0].kw['host'] == '10.0.0.1' and opened[0].kw['port'] == 22

# --- 2. OLT berbeda = kolam terpisah ---------------------------------------
helper.execute_ssh_commands(OLT2, ['show c'])
assert len(opened) == 2, len(opened)

# --- 3. Sesi menganggur terlalu lama dibuang -------------------------------
reset()
helper.execute_ssh_commands(OLT, ['show a'])
entry = helper._pool[helper._pool_key(OLT)]
entry['last_used'] = time.time() - helper._POOL_IDLE_TIMEOUT - 1
helper.execute_ssh_commands(OLT, ['show b'])
assert len(opened) == 2, 'sesi kedaluwarsa harus diganti baru'
assert opened[0].closed is True, 'sesi lama harus ditutup'

# --- 4. Sesi tertinggal di mode config DIBUANG -----------------------------
# Kalau tidak, mode config bocor ke pemanggil berikutnya.
reset()
helper.execute_ssh_commands(OLT, ['show a'])
opened[0].prompt = 'OLT(config)#'          # driver lupa 'exit'
helper.execute_ssh_commands(OLT, ['show b'])
assert opened[0].closed is True, 'sesi menyimpang harus ditutup'
assert helper._pool[helper._pool_key(OLT)]['conn'] is None or len(opened) >= 2

# --- 5. Sesi mati diam-diam -> dicoba ulang sekali dengan koneksi baru ------
reset()
helper.execute_ssh_commands(OLT, ['show a'])
assert len(opened) == 1
fail_next.append(True)                      # kegagalan pada sesi pakai-ulang
r = helper.execute_ssh_commands(OLT, ['show b'])
assert 'out:show b' in r, f'harus pulih lewat koneksi baru, dapat: {r}'
assert len(opened) == 2, len(opened)
assert opened[0].closed is True

# --- 6. Koneksi BARU yang gagal TIDAK dicoba ulang --------------------------
# Mencoba ulang koneksi baru hanya menggandakan waktu tunggu saat OLT mati.
reset()
fail_next.append(True)
r = helper.execute_ssh_commands(OLT, ['show a'])
assert r.startswith('Error connecting to OLT:'), r
assert len(opened) == 1, f'tak boleh ada percobaan kedua, dapat {len(opened)}'

# --- 7. Kegagalan mengembalikan pesan yang dikenali detect_transport_error --
assert helper.detect_transport_error(r) is not None, r

# --- 8. Akses banyak thread ke OLT sama tidak saling menimpa ---------------
# netmiko tidak aman untuk banyak thread; uvicorn menjalankan endpoint sinkron
# di threadpool, jadi kunci per-OLT wajib bekerja.
reset()
hasil = []


def kerja(n):
    hasil.append(helper.execute_ssh_commands(OLT, [f'show {n}']))


ts = [threading.Thread(target=kerja, args=(i,)) for i in range(8)]
[t.start() for t in ts]
[t.join() for t in ts]
assert len(hasil) == 8
for i, h in enumerate(hasil):
    assert h.count('OLT# show') == 1, f'output tercampur: {h!r}'
assert len(opened) == 1, f'8 thread harus berbagi 1 sesi, dapat {len(opened)}'

# --- 9. close_all_connections() menutup semuanya ----------------------------
reset()
helper.execute_ssh_commands(OLT, ['show a'])
helper.execute_ssh_commands(OLT2, ['show b'])
assert len(opened) == 2
helper.close_all_connections()
assert all(c.closed for c in opened), 'semua sesi harus tertutup'

# --- 10. TELNET tidak lewat kolam ------------------------------------------
# execute_telnet_commands pakai socket mentah, bukan netmiko.
reset()
helper.execute_ssh_commands(dict(OLT, protocol='TELNET'), ['show a'])
assert len(opened) == 0, 'TELNET tidak boleh memakai ConnectHandler'

print('test_pool.py: semua pemeriksaan LULUS')
