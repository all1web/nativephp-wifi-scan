// JS bridge wrapper for web-view NativePHP apps (Livewire/Inertia).
// SuperNative apps call the PHP facade directly and do not need this file.
//
// Each method posts to the native bridge and resolves with the decoded map.
// Fresh scan results and permission outcomes arrive as `native-event`
// CustomEvents / Livewire dispatches (NetworksScanned, ScanFailed,
// PermissionGranted, PermissionDenied) — listen for those separately.

const wifi = {
    /**
     * Returns the last cached list of visible access points and triggers a
     * fresh foreground scan. Fresh results arrive via the NetworksScanned event.
     * @returns {Promise<{networks: string, count: number, fromCache: boolean, scanRequested: boolean}>}
     */
    scan: async () => window.nativephp.call('Wifi.Scan', {}),

    /**
     * Reads the currently connected access point.
     * @returns {Promise<{connected: boolean, ssid?: string, bssid?: string, rssi?: number, frequency?: number}>}
     */
    current: async () => window.nativephp.call('Wifi.Current', {}),

    /**
     * Reports whether the scan permission is currently held.
     * @returns {Promise<{status: string, requiredPermission: string, locationServicesEnabled: boolean}>}
     */
    checkPermission: async () => window.nativephp.call('Wifi.CheckPermission', {}),

    /**
     * Requests the scan permission. Resolves immediately with granted/pending;
     * the definitive outcome arrives via PermissionGranted / PermissionDenied.
     * @returns {Promise<{granted: boolean, status: string}>}
     */
    requestPermission: async () => window.nativephp.call('Wifi.RequestPermission', {}),
};

export default wifi;
