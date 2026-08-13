/**
 * WiFi Radar plugin for NativePHP Mobile (web-view / Livewire / Inertia apps).
 *
 * SuperNative apps should use the PHP facade (WifiScan\Mobile\Facades\Wifi)
 * directly. This wrapper exists for JS-driven front-ends that call the native
 * bridge over HTTP.
 *
 * Off-device (browser dev, tests, CI) every call degrades gracefully instead
 * of throwing: scan() -> empty result, current() -> null, permissions ->
 * 'unknown' — mirroring the PHP facade.
 *
 * NOTE — PHP-side config does not apply here: `include_hidden` and
 * `max_results` filter Wifi::scan() in PHP only. This wrapper returns the raw
 * native list. Filter in your own JS if you need the same behaviour.
 *
 * @example
 * // vite.config.js -> resolve.alias:
 * //   '@wifi': '/vendor/all1web/nativephp-wifi-scan/resources/js/wifi.js'
 * import wifi from '@wifi';
 *
 * const { networks, scanRequested } = await wifi.scan();
 * // networks: [{ ssid, bssid, rssi, frequency }, ...] strongest-first
 *
 * // Fresh results arrive as a DOM CustomEvent when the platform completes
 * // the scan (only if scanRequested was true — see docs/REFERENCE.md):
 * document.addEventListener('native-event', (e) => {
 *   if (e.detail.event === 'WifiScan\\Mobile\\Events\\NetworksScanned') {
 *     // e.detail.payload.networks is the raw row array
 *   }
 * });
 */

const baseUrl = '/_native/api/call';

async function bridgeCall(method, params = {}) {
    let response;
    try {
        response = await fetch(baseUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ method, params }),
        });
    } catch {
        return null; // network-level failure — treat as bridge-absent
    }

    let result;
    try {
        result = await response.json();
    } catch {
        return null; // non-JSON (404 page, proxy error) — treat as bridge-absent
    }

    if (result.status === 'error' || result.code === 'FUNCTION_NOT_AVAILABLE' || response.status === 503) {
        // Bridge absent (browser dev / CI) or call failed: degrade gracefully
        // like the PHP facade instead of throwing in the app's face.
        return null;
    }

    const nativeResponse = result.data;
    if (nativeResponse && nativeResponse.data !== undefined) {
        return nativeResponse.data;
    }

    return nativeResponse;
}

/** Decode the `networks` field, which crosses the bridge as a JSON string. */
function decodeNetworks(raw) {
    if (Array.isArray(raw)) return raw;
    if (typeof raw !== 'string' || raw === '') return [];
    try {
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

/**
 * Return the platform's last CACHED list of visible access points and trigger
 * a fresh foreground scan. Fresh results arrive via the NetworksScanned
 * native-event; when `scanRequested` is false the platform throttled the
 * refresh and no event will follow (the cached list is still valid).
 *
 * @returns {Promise<{networks: Array<{ssid: string, bssid: string, rssi: number, frequency: number}>, count: number, fromCache: boolean, scanRequested: boolean}>}
 */
export async function scan() {
    const data = await bridgeCall('Wifi.Scan');
    if (!data) {
        return { networks: [], count: 0, fromCache: false, scanRequested: false };
    }
    const networks = decodeNetworks(data.networks);
    return {
        networks,
        count: typeof data.count === 'number' ? data.count : networks.length,
        fromCache: !!data.fromCache,
        scanRequested: !!data.scanRequested,
    };
}

/**
 * The currently connected access point, or null when not associated (and
 * off-device). SSID reads as "<unknown ssid>" without a location grant —
 * the BSSID is the reliable field.
 *
 * @returns {Promise<{ssid: string, bssid: string, rssi: number, frequency: number}|null>}
 */
export async function current() {
    const data = await bridgeCall('Wifi.Current');
    if (!data || data.connected !== true) return null;
    return {
        ssid: data.ssid ?? '',
        bssid: data.bssid ?? '',
        rssi: data.rssi ?? null,
        frequency: data.frequency ?? null,
    };
}

/**
 * Whether the app currently holds a permission that allows reading scan
 * results, plus whether device location services are ON — a separate switch
 * that also gates results on Android 9+ and is the most common cause of an
 * empty list.
 *
 * @returns {Promise<{status: string, requiredPermission: string|null, locationServicesEnabled: boolean|null}>}
 */
export async function checkPermission() {
    const data = await bridgeCall('Wifi.CheckPermission');
    if (!data) {
        return { status: 'unknown', requiredPermission: null, locationServicesEnabled: null };
    }
    return {
        status: data.status ?? 'unknown',
        requiredPermission: data.requiredPermission ?? null,
        locationServicesEnabled: typeof data.locationServicesEnabled === 'boolean' ? data.locationServicesEnabled : null,
    };
}

/**
 * Request the scan permission. Resolves immediately: granted=true when the
 * permission was already held, otherwise status 'pending' while the system
 * dialog is up. The definitive outcome arrives via the PermissionGranted /
 * PermissionDenied native-events.
 *
 * @returns {Promise<{granted: boolean, status: string}>}
 */
export async function requestPermission() {
    const data = await bridgeCall('Wifi.RequestPermission');
    if (!data) return { granted: false, status: 'unknown' };
    return { granted: !!data.granted, status: data.status ?? 'unknown' };
}

export const wifi = { scan, current, checkPermission, requestPermission };

export default wifi;
