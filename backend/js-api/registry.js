// OLT Driver Registry — single source of truth for supported OLT types
// Mirrors backend/driver.php olt_driver_registry()
module.exports = {
  'CDATA FD1602SB1(GPON)': {
    label: 'CDATA FD1602SB1 (GPON)',
    class: 'OltCdataFd1602sb1Driver',
    aliases: ['GPON', 'CDATA', 'FD1602S', 'FD1602SB1', 'CDATA FD1602S-B1'],
    info: { brand: 'CDATA', model: 'FD1602S-B1', tech: 'GPON', pon_type: 'gpon' },
  },
  'ZTE C320': {
    label: 'ZTE C320 (GPON)',
    class: 'OltZteC320Driver',
    aliases: ['ZTE', 'C320', 'ZTE C320'],
    info: { brand: 'ZTE', model: 'C320', tech: 'GPON', pon_type: 'gpon' },
  },
  'ZTE C300': {
    label: 'ZTE C300 (GPON)',
    class: 'OltZteC300Driver',
    aliases: ['C300', 'ZTE C300'],
    info: { brand: 'ZTE', model: 'C300', tech: 'GPON', pon_type: 'gpon' },
  },
};
