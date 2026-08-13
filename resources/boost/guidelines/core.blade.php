## all1web/nativephp-wifi-scan ("WiFi Radar")

WiFi visibility for NativePHP Mobile: scan visible access points (SSID/BSSID/RSSI)
and read the connected AP, from PHP. Android only, foreground only.

Always call `checkPermission()` / `requestPermission()` before `scan()` — without
the grant the platform returns an empty list rather than an error. On Android 9+
location services must ALSO be switched on; that is a separate condition from the
permission and is the most common cause of an unexpectedly empty scan.

### PHP Usage (Livewire/Blade)

@verbatim
<code-snippet name="Scanning nearby networks" lang="php">
use WifiScan\Mobile\Facades\Wifi;
use WifiScan\Mobile\Enums\PermissionStatus;

if (Wifi::checkPermission() !== PermissionStatus::Granted) {
    Wifi::requestPermission();
}

// Cached last-known list, returned immediately.
$networks = Wifi::scan(); // array<AccessPoint> {ssid, bssid, rssi, frequency}
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Reading the connected AP" lang="php">
use WifiScan\Mobile\Facades\Wifi;

$ap = Wifi::current(); // ?AccessPoint
if ($ap) {
    // $ap->ssid, $ap->bssid, $ap->rssi
}
</code-snippet>
@endverbatim

### Fresh results arrive via an event

@verbatim
<code-snippet name="Listening for a completed scan" lang="php">
use Native\Mobile\Attributes\OnNative;
use WifiScan\Mobile\Events\NetworksScanned;

#[OnNative(NetworksScanned::class)]
public function handleScan(array $networks, int $count)
{
    // $networks is the raw rows; hydrate if you like:
    // (new NetworksScanned($networks, $count))->accessPoints();
}
</code-snippet>
@endverbatim

### Location fingerprint (pairs with any Laravel backend)

@verbatim
<code-snippet name="BSSID fingerprint" lang="php">
use WifiScan\Mobile\Facades\Wifi;
use WifiScan\Mobile\Support\BssidFingerprint;

$hash = BssidFingerprint::hash(Wifi::scan()); // stable, order-independent
// POST $hash to your own signals endpoint for location detection.
</code-snippet>
@endverbatim

### Events

- `WifiScan\Mobile\Events\NetworksScanned` — fresh scan done (payload: `array $networks`, `int $count`)
- `WifiScan\Mobile\Events\ScanFailed` — scan refused (payload: `string $reason`)
- `WifiScan\Mobile\Events\PermissionGranted` / `PermissionDenied` — permission outcome

### Constraints

- Android only; foreground only (platform scan-throttle + permission model).
- There is NO iOS support and none is possible for `scan()` — never suggest an iOS path.
- `scan()` returns the *cached* list synchronously; a fresh scan is delivered by `NetworksScanned`.
- Requires `NEARBY_WIFI_DEVICES` (API 33+) or `ACCESS_FINE_LOCATION` (older), and location services on.
- Throttling is not a failure: `scan()` still returns cached results and no `NetworksScanned` follows.
- Off-device (browser, CI, `artisan test`) every call is a safe no-op: `[]`, `null`, `PermissionStatus::Unknown`.
- For place detection, set `include_hidden => true` and leave `max_results` at 0 — weak and hidden APs make a fingerprint distinctive.
- Match places with `BssidFingerprint::similarity()` and a threshold (~0.6), not with hash equality; one rebooted neighbouring router changes the hash.
