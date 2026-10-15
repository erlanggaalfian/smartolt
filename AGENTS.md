# Instruksi untuk AI Agent

Sebelum mengerjakan tugas apapun di repo ini, baca **[docs/codebase-map.json](docs/codebase-map.json) dulu**.

File itu berisi pemetaan semua file `frontend/*.php`, `frontend/action/*.php`, dan `backend/*.php`:
tujuan tiap file, fungsi penting, tabel DB yang dipakai, dependensi antar file, dan pola/konvensi yang
harus diikuti (`shared_conventions`) serta catatan skema DB (`db_schema_notes`).

Gunakan itu untuk tahu file mana yang relevan dan pola apa yang harus diikuti — **baru** baca source code
file yang benar-benar perlu diubah. Jangan scan semua file dari awal tiap kali mulai tugas baru.

## Wajib update setelah perubahan kode

Setiap kali ada perubahan kode (tambah fitur, fix bug, refactor, dst), update entry file yang relevan di
`docs/codebase-map.json`:
- Perbarui `purpose` / `notable_patterns` kalau berubah.
- Tambahkan entri baru ke array `changelog` pada file tersebut: `{"date":"YYYY-MM-DD","change":"ringkasan singkat"}`.
  Array, tambah baris baru, jangan hapus histori lama.

File ini bukan dokumen sekali-buat — harus tetap akurat mengikuti kode.

## Catatan git repo ini

Workspace lokal **tidak punya folder `.git`**. Untuk commit/push: clone ke folder temp, copy file yang
diubah, commit & push dari situ. Jangan asumsikan `git status` di workspace lokal akan menampilkan apa pun.
