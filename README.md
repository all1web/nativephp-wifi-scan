# WiFi Scan for NativePHP Mobile

Read WiFi visibility from PHP in a NativePHP Mobile app: scan the visible access
points (SSID / BSSID / RSSI) and read the currently connected AP. Pairs with any
Laravel backend — a small helper turns a scan into a stable location fingerprint
you can POST to your own signals endpoint.

> **Platform:** Android only. Foreground only. See
> [docs/DESIGN.md](docs/DESIGN.md) for exactly why, and what iOS can and cannot do.

---

## Install

```shell
composer require wifiscan/nativephp-wifi-scan
```

NativePHP Mobile plugins are **fail-closed**: a plugin is only compiled into your
app after you explicitly allow-list it. Two steps:

```shell
# 1. Register the plugin (adds its provider to app/Providers/NativeServiceProvider::plugins())
php artisan native:plugin:register wifiscan/nativephp-wifi-scan
```

Confirm your `app/Providers/NativeServiceProvider.php` `plugins()` array lists it:

```php
public function plugins(): array
{
    return [
        // ...existing plugins...
        \WifiScan\Mobile\WifiScanServiceProvider::class,
    ];
}
```

```shell
# 2. Rebuild the native project and run
php artisan native:install --force
php artisan native:run
```

Validate the manifest any time (a malformed `nativephp.json` makes a plugin
silently vanish at build):

```shell
php artisan native:plugin:validate
```

### Config (optional)

```shell
php artisan vendor:publish --tag=wifi-scan-config
```

```php
// config/wifi-scan.php
'include_hidden' => false, // keep SSID-less APs in scan() results
'max_results'    => 0,     // cap scan() results (0 = no cap), strongest first
```

---

## Usage

```php
use WifiScan\Mobile\Facades\Wifi;
use WifiScan\Mobile\Enums\PermissionStatus;

// 1. Make sure you can scan.
if (Wifi::checkPermission() !== PermissionStatus::Granted) {
    Wifi::requestPermission(); // shows the system dialog
}

// 2. Scan. Returns the last CACHED list immediately; a fresh scan is triggered
//    in the background and delivered via the NetworksScanned event.
$networks = Wifi::scan();      // array<AccessPoint>

foreach ($networks as $ap) {
    // $ap->ssid, $ap->bssid, $ap->rssi (dBm), $ap->frequency (MHz)
}

// 3. Read the connected AP.
$ap = Wifi::current();         // ?AccessPoint
if ($ap) {
    echo "{$ap->ssid} @ {$ap->bssid} ({$ap->rssi} dBm)";
}
```

### `scan()` is cached-now, fresh-later

Android never returns scan results synchronously and throttles `startScan()`
(4 calls / 2 min in the foreground). So `scan()` returns the platform's **last
cached results** right away, and a fresh scan arrives as an event:

```php
use Native\Mobile\Attributes\OnNative;
use WifiScan\Mobile\Events\NetworksScanned;

#[OnNative(NetworksScanned::class)]
public function onScan(array $networks, int $count)
{
    $fresh = (new NetworksScanned($networks, $count))->accessPoints();
    // ...update your UI...
}
```

### Events

| Event | When | Payload |
|-------|------|---------|
| `WifiScan\Mobile\Events\NetworksScanned` | a fresh scan completed | `array $networks`, `int $count` |
| `WifiScan\Mobile\Events\ScanFailed` | scan refused (throttled / WiFi off / no permission) | `string $reason` |
| `WifiScan\Mobile\Events\PermissionGranted` | user granted the scan permission | `?string $permission` |
| `WifiScan\Mobile\Events\PermissionDenied` | user denied it | `?string $permission` |

### Delivering the permission result

`requestPermission()` returns immediately (`granted` if already held, else
`pending`). The **definitive** grant/deny is delivered by Android to the host
Activity's `onRequestPermissionsResult`. NativePHP apps typically already route
this; if yours does not surface it, re-check with `Wifi::checkPermission()` when
the app resumes — the plugin dispatches `PermissionGranted` on the next `scan()`
if the grant has landed.

---

## Pairing with your Laravel backend: location fingerprints

The set of BSSIDs (AP MAC addresses) a device can see is a strong, cheap
signature of *where it is* — far more stable than GPS indoors and than SSIDs
(which collide and change). This package ships a pure helper for it:

```php
use WifiScan\Mobile\Facades\Wifi;
use WifiScan\Mobile\Support\BssidFingerprint;

$networks = Wifi::scan();

$fingerprint = BssidFingerprint::hash($networks); // stable, order-independent sha256

// Send it to YOUR endpoint — nothing in this package talks to a server for you.
Http::withToken($apiToken)->post('https://your-app.example/api/signals/location', [
    'fingerprint' => $fingerprint,
    'bssids'      => BssidFingerprint::set($networks), // if you want server-side matching
    'observed_at' => now()->toIso8601String(),
]);
```

On the backend you can compare observations with a similarity score instead of
requiring an exact match (people move, APs come and go):

```php
$score = BssidFingerprint::similarity($seenNow, $knownPlace); // 0.0 – 1.0
if ($score >= 0.6) {
    // treat as "at the same place"
}
```

This is the generic shape of a location-detection integration. The transport,
auth, storage, and matching thresholds are entirely yours — the plugin only
provides the on-device signal and the fingerprint primitive.

---

## JavaScript (Livewire / Inertia web-view apps)

```js
import wifi from '../../vendor/wifiscan/nativephp-wifi-scan/resources/js/wifi.js';

const { networks } = await wifi.scan();
const current = await wifi.current();
```

SuperNative apps use the PHP facade directly and do not need the JS wrapper.

---

## Requirements

- NativePHP Mobile `^3.0 || ^4.0` (v4 RC installs via the documented
  `4.0.0-rc.1 as 3.99.99` alias — see [docs/DESIGN.md](docs/DESIGN.md)).
- Android `minSdk 23`. `NEARBY_WIFI_DEVICES` (API 33+) or `ACCESS_FINE_LOCATION`
  (older), plus location services enabled, are required to read results.

## License

Proprietary placeholder — see [LICENSE](LICENSE). The owner selects the license
before publishing.
