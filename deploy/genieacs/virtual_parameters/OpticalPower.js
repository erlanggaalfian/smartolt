// Virtual Parameter: OpticalPower (RX, dBm) — normalized dari path vendor manapun
// ZTE (F609/F660/F670L): InternetGatewayDevice.WANDevice.*.X_ZTE-COM_WANPONInterfaceConfig.RXPower
// Huawei (HG8245/HG8145/H5/V5): InternetGatewayDevice.WANDevice.*.X_GponInterafceConfig.RXPower
// FiberHome (HG6045F3): InternetGatewayDevice.WANDevice.*.X_FH_GponInterfaceConfig.RXPower

const zte = declare("InternetGatewayDevice.WANDevice.*.X_ZTE-COM_WANPONInterfaceConfig.RXPower", {value: 1});
const huawei = declare("InternetGatewayDevice.WANDevice.*.X_GponInterafceConfig.RXPower", {value: 1});
const fiberhome = declare("InternetGatewayDevice.WANDevice.*.X_FH_GponInterfaceConfig.RXPower", {value: 1});

let value = null;
if (zte.size && zte.value[0] != null) value = zte.value[0];
else if (huawei.size && huawei.value[0] != null) value = huawei.value[0];
else if (fiberhome.size && fiberhome.value[0] != null) value = fiberhome.value[0];

// WAJIB string kosong, BUKAN null, kalau tidak ada path vendor yang cocok.
// GenieACS menolak value null dengan fault "Invalid virtual parameter value
// attribute", dan fault itu MEMBLOKIR SELURUH channel provisioning device
// tersebut — preset/provision lain ikut tidak jalan. Terbukti pada ONU
// FiberHome HG6045F3 (tidak punya path ZTE/Huawei): 9 fault menumpuk per
// device, preset fiberhome-wan-access tidak pernah dieksekusi sampai ini
// diperbaiki. Root cause 2026-10-04.
if (value == null) value = "";

return {writable: false, value: [value, "xsd:string"]};
