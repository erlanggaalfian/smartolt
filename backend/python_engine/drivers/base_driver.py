from abc import ABC, abstractmethod

class BaseDriver(ABC):
    @abstractmethod
    def check_connection(self, olt: dict) -> dict:
        """
        Check SSH / SNMP connection to OLT.
        Returns: {'success': bool, 'message': str}
        """
        pass

    @abstractmethod
    def get_onu_signal(self, olt: dict, onu: dict, include_ip: bool = True) -> dict:
        """
        Get Rx optical power and WAN IP address for an ONU.
        Returns: {'success': bool, 'rx_onu': float/str, 'rx_olt': float/str, 'status': str, 'pppoe_ip': str, 'log': str}
        """
        pass

    @abstractmethod
    def get_onu_full_status(self, olt: dict, onu: dict) -> dict:
        """
        Get complete ONU status including optical power, details, WAN, LAN, VoIP, etc.
        Returns: {'success': bool, 'message': str, 'optical_status': str, 'onu_catv_port': str, ...}
        """
        pass

    @abstractmethod
    def reboot_onu(self, olt: dict, onu: dict) -> dict:
        """
        Reboot ONU.
        Returns: {'success': bool, 'message': str}
        """
        pass

    @abstractmethod
    def delete_onu(self, olt: dict, onu: dict) -> dict:
        """
        Delete/de-authorize ONU.
        Returns: {'success': bool, 'message': str}
        """
        pass

    @abstractmethod
    def authorize_onu(self, olt: dict, pon_port: str, serial: str, name: str, vlan: int, desc: str, onu_id: int = None) -> dict:
        """
        Authorize/register a new ONU.
        Returns: {'success': bool, 'message': str, 'onu_id': int, 'pon_port': str}
        """
        pass

    @abstractmethod
    def configure_onu_full(self, olt: dict, onu: dict, wan: dict) -> dict:
        """
        Apply configuration to an ONU.
        Returns: {'success': bool, 'message': str}
        """
        pass

    @abstractmethod
    def sync_onu_config(self, olt: dict, onu: dict) -> dict:
        """
        Sync ONU config from OLT.
        Returns: {'success': bool, 'vlan': int, 'pppoe_username': str, ...}
        """
        pass

    @abstractmethod
    def get_onu_running_config(self, olt: dict, onu: dict) -> dict:
        """
        Get running-config for a specific ONU.
        Returns: {'success': bool, 'config': str}
        """
        pass

    @abstractmethod
    def get_onu_traffic_stats(self, olt: dict, onu: dict) -> dict:
        """
        Get traffic packet and byte stats.
        Returns: {'success': bool, 'rx_bytes': int, 'tx_bytes': int, ...}
        """
        pass

    @abstractmethod
    def pull_configured_onus(self, olt: dict) -> dict:
        """
        Pull all registered ONUs from OLT.
        Returns: {'success': bool, 'onus': list}
        """
        pass

    @abstractmethod
    def pull_onus_from_current_config(self, olt: dict) -> dict:
        """
        Pull ONUs by reading running-config.
        Returns: {'success': bool, 'onus': list}
        """
        pass

    @abstractmethod
    def get_onu_config_maps(self, olt: dict) -> dict:
        """
        Retrieve configuration maps for all ONUs.
        Returns: {'success': bool, 'ipconfig_map': dict, 'name_map': dict, 'desc_map': dict}
        """
        pass

    @abstractmethod
    def disable_onu(self, olt: dict, onu: dict) -> dict:
        """
        Disable ONU port on OLT.
        Returns: {'success': bool, 'message': str}
        """
        pass

    @abstractmethod
    def enable_onu(self, olt: dict, onu: dict) -> dict:
        """
        Enable ONU port on OLT.
        Returns: {'success': bool, 'message': str}
        """
        pass

    @abstractmethod
    def get_supported_panels(self) -> dict:
        """
        Peta panel yang didukung driver.
        Returns: {'<kunci-panel>': {'label': str, 'icon': str}, ...}
        Kunci dipakai frontend untuk memvalidasi panel yang diminta.
        """
        pass

    def get_onu_signals_bulk(self, olt: dict, onus: list) -> dict:
        """
        Optional bulk fetch of optical signals.
        Returns: dict mapping "pon_port_onu_id" to {'rx_onu': val, 'rx_olt': val, 'status': val}
        """
        return {}

    # ------------------------------------------------------------------
    # VLAN & interface uplink — OPSIONAL per vendor.
    #
    # Sengaja method konkret (bukan @abstractmethod): driver yang belum
    # mendukungnya tetap bisa diinstansiasi, dan pemanggil menerima
    # success=False dengan pesan jelas, bukan AttributeError.
    # ------------------------------------------------------------------

    def _unsupported(self, what: str) -> dict:
        vendor = self.__class__.__name__
        return {'success': False, 'message': f'{what} belum didukung driver {vendor}.'}

    def get_vlans(self, olt: dict) -> dict:
        return dict(self._unsupported('Pembacaan VLAN'), vlans=[])

    def add_vlan(self, olt: dict, vlan_id: int, description: str = '') -> dict:
        return self._unsupported('Pembuatan VLAN')

    def delete_vlan(self, olt: dict, vlan_id: int, confirmed: bool = False) -> dict:
        return self._unsupported('Penghapusan VLAN')

    def get_interfaces(self, olt: dict) -> dict:
        return dict(self._unsupported('Pembacaan interface uplink'), ports=[])

    def config_port(self, olt: dict, port: str, auto_nego: str, speed: str, duplex: str) -> dict:
        return self._unsupported('Konfigurasi port uplink')

    def config_port_vlan(self, olt: dict, port: str, mode: str,
                         tagged=None, untagged=None, pvid=None) -> dict:
        return self._unsupported('Konfigurasi VLAN port')

    def setup_snmp(self, olt: dict, community_ro: str, community_rw: str) -> dict:
        return self._unsupported('Konfigurasi SNMP')

    def get_panel_data(self, olt: dict, panel: str) -> dict:
        """Default untuk vendor tanpa panel. Aman dipakai karena panel CDATA sudah
        di-port penuh ke Python dan ZTE memang tidak punya panel di PHP maupun
        Python, jadi tidak ada lagi yang bergantung pada fallback panel ke PHP.
        Balikan WAJIB menyertakan 'sections' agar frontend tidak perlu guard."""
        return dict(self._unsupported(f"Panel '{panel}'"), sections=[])
