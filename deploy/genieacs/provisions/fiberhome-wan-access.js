// Provision: fiberhome-wan-access
// Tujuan: ONU FiberHome yang BARU pertama kali konek ke TR-069 langsung bisa
// diakses GUI web-nya dari sisi WAN, tanpa perlu dibuka manual per-ONU.
//
// Tiga gerbang berlapis yang harus hidup bersamaan (kalau salah satu mati, GUI
// tetap tidak bisa diakses dari WAN — ini sudah terbukti live pada FHTTC239D1C6
// yang ACL-nya sudah benar tapi RemoteAccess=0, port 80/443 tampak closed):
//   1. X_FH_ACL.Enable = 1                  -> master switch ACL
//   2. X_FH_ACL.Rule.1 (WAN/ALL/aktif)      -> aturan izin akses dari WAN
//   3. X_FH_WebUserInfo.RemoteAccess = 1    -> switch web-server sisi WAN
//
// CATATAN addObject: TIDAK memakai addObject untuk Rule. Firmware FiberHome
// HG6045F3 punya bug — addObject di tabel X_FH_ACL.Rule me-RESET semua rule
// lama jadi kosong, bukan sekadar menambah instance. Device fresh sudah punya
// Rule.1 bawaan (NumberOfRule=1, terkonfirmasi pada 2 unit live), jadi cukup
// set field-nya saja. Kalau suatu saat ada firmware yang tidak menyertakan
// Rule.1, barulah perlu addObject + re-push snapshot (lihat skill smartolt-php).
//
// Source IP sengaja dikosongkan = izinkan semua IP, sama seperti kondisi unit
// yang sudah bisa diakses (SBI FHTTC19C68CD).

const now = Date.now();
const B = "InternetGatewayDevice.";

// Gerbang 1 — master switch ACL. Tipe xsd:string pada firmware ini, BUKAN boolean.
declare(B + "X_FH_ACL.Enable", null, {value: "1"});

// Gerbang 2 — rule izin akses dari WAN. AclMode 0 = mode daftar-izin standar.
declare(B + "X_FH_ACL.AclMode", null, {value: "0"});
declare(B + "X_FH_ACL.Rule.1.Enable", null, {value: "1"});
declare(B + "X_FH_ACL.Rule.1.Protocol", null, {value: "ALL"});
declare(B + "X_FH_ACL.Rule.1.Interface", null, {value: "WAN"});
declare(B + "X_FH_ACL.Rule.1.Direction", null, {value: "1"});
declare(B + "X_FH_ACL.Rule.1.StartIp", null, {value: ""});
declare(B + "X_FH_ACL.Rule.1.EndIp", null, {value: ""});

// Gerbang 3 — switch web-server sisi WAN. Ini yang membedakan unit bisa/tidak
// bisa diakses, dan tidak terlihat di halaman ACL mana pun.
declare(B + "UserInterface.X_FH_WebUserInfo.RemoteAccess", null, {value: "1"});

// Refresh nilainya supaya hasil set langsung terbaca di SmartOLT, bukan nunggu
// inform berikutnya.
declare(B + "X_FH_ACL.Enable", {value: now});
declare(B + "UserInterface.X_FH_WebUserInfo.RemoteAccess", {value: now});
declare(B + "X_FH_ACL.Rule.1.Enable", {value: now});
