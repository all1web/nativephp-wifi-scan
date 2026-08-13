# Reference

The complete API, events, configuration, and integration recipes for WiFi Radar
(`all1web/nativephp-wifi-scan`). For *why* it is shaped this way, see
[DESIGN.md](DESIGN.md); for platform behaviour you will hit in the wild, see
[PLATFORM-NOTES.md](PLATFORM-NOTES.md).

---

## The facade

```php
use WifiScan\Mobile\Facades\Wifi;
```

| Call | Returns | Notes |
|---|---|---|
| `Wifi::scan()` | `array<int, AccessPoint>` | The platform's **cached** list, immediately. Also triggers a fresh scan whose results arrive via `NetworksScanned`. |
| `Wifi::current()` | `?AccessPoint` | The connected AP, or `null` when not associated. |
| `Wifi::checkPermission()` | `PermissionStatus` | Does the app hold a permission that lets it read scan results? |
| `Wifi::permissionDetails()` | `array` | `checkPermission()` **plus** the required-permission name and `locationServicesEnabled` — the second switch that also gates results. |
| `Wifi::requestPermission()` | `PermissionStatus` | Shows the system dialog. Returns `Granted` if already held, otherwise `Pending`. |

`permissionDetails()` is the call to reach for when a scan comes back empty and
you need to know *why* without guessing:

```php
$d = Wifi::permissionDetails();
// [
//   'status'                  => PermissionStatus::Granted,
//   'requiredPermission'      => 'android.permission.NEARBY_WIFI_DEVICES',
//   'locationServicesEnabled' => true,   // null off-device
// ]
```

The facade resolves `WifiScan\Mobile\Contracts\WifiInterface`, bound as a
singleton — inject the interface instead of the facade wherever you prefer
constructor injection. A `Wifi` class alias is auto-registered.

**Off-device behaviour:** every call is a no-op that returns an empty result
when `nativephp_call()` doesn't exist (your dev machine, CI, `php artisan
test`). `scan()` gives `[]`, `current()` gives `null`, the permission calls give
`PermissionStatus::Unknown`. Nothing throws, so the same Blade/Livewire code
renders in a browser and on a phone.

**Error behaviour is identical.** If the native layer returns an error envelope
(permission denied, WiFi off, `WifiManager` unavailable), the PHP layer collapses
it to the same empty values rather than throwing or leaking `"error"` into your
view. This is deliberate: a scan failing is a normal runtime condition on a
mobile device, not an exceptional one. Use the `ScanFailed` event when you need
the reason, and `adb logcat -s WifiScan` when debugging.

## Diagnostics

```bash
php artisan wifi-scan:doctor
```

Walks the whole chain — allowlist registration → generated Android manifest
permissions → runtime bridge → scan permission → location services → config —
and prints the exact fix for each broken link. Exit code `0` when clean, `1`
when anything is wrong, so it works in CI.

It is the first thing to run for any "it doesn't work" report, and the first
thing we'll ask for in an issue.

### `AccessPoint`

`WifiScan\Mobile\Data\AccessPoint` — immutable, `readonly`.

| Property | Type | Meaning |
|---|---|---|
| `ssid` | `string` | Network name. Empty string for hidden networks (and when a location grant is missing). |
| `bssid` | `string` | AP MAC address, **lowercased**. The stable identifier. |
| `rssi` | `?int` | Signal strength in dBm. Roughly: `-30` excellent, `-67` good, `-80` weak, `-90` unusable. |
| `frequency` | `?int` | Channel frequency in MHz (`2412`–`2484` = 2.4 GHz, `5180`+ = 5 GHz). |

Methods: `isHidden()`, `toArray()`, and the static `fromArray()` /
`collection()` used by the decode path. Missing keys degrade to empty/null
rather than throwing, so a malformed native payload can never blow up a
Livewire render.

### `PermissionStatus`

`WifiScan\Mobile\Enums\PermissionStatus` — a backed string enum:
`Granted`, `Denied`, `Pending`, `Unknown`. Has `isGranted()`, and
`fromNative()` which maps anything unrecognised to `Unknown`.

---

## Events

All four are plain Laravel events dispatched from native code. Listen with the
`#[OnNative]` attribute in Livewire/SuperNative components, or register a
listener the normal way.

| Event | Fires when | Payload |
|---|---|---|
| `Events\NetworksScanned` | a fresh scan completed | `array $networks`, `int $count` |
| `Events\ScanFailed` | the scan was refused | `string $reason` |
| `Events\PermissionGranted` | the user granted it | `?string $permission` |
| `Events\PermissionDenied` | the user denied it | `?string $permission` |

```php
use Native\Mobile\Attributes\OnNative;
use WifiScan\Mobile\Events\NetworksScanned;

#[OnNative(NetworksScanned::class)]
public function onScan(array $networks, int $count)
{
    $this->networks = (new NetworksScanned($networks, $count))->accessPoints();
}
```

`NetworksScanned` carries **raw rows**, not hydrated objects — the bridge
delivers plain arrays. `accessPoints()` turns them into `AccessPoint`s.

### `ScanFailed` reasons

| `reason` | What happened | What to do |
|---|---|---|
| `permission` | the scan permission isn't held (or was revoked mid-flight) | call `requestPermission()` |
| `wifi_disabled` | the WiFi radio is switched off | prompt the user to turn WiFi on |
| `results_not_updated` | the platform finished the scan but reported **failure** — `scanResults` still holds the previous batch | keep showing the cached list; it is stale, not wrong. Common on OEMs under battery saver |
| `unknown` | anything else | the cached list from `scan()` is still valid |

**Why `results_not_updated` exists.** Android's scan-complete broadcast carries
an `EXTRA_RESULTS_UPDATED` flag. When it is `false`, the scan failed and the
results list is the *old* one. Presenting that as a fresh scan would be a lie,
so the plugin dispatches `ScanFailed` instead of `NetworksScanned`. If an OEM
omits the flag entirely we treat it as `true` and deliver — dropping real
results would be the worse failure.

Note that platform **throttling** is not a `ScanFailed` — it isn't a failure.
`scan()` still returns the cached list; the JS layer sees `scanRequested:
false`, and no `NetworksScanned` will follow. See
[PLATFORM-NOTES.md](PLATFORM-NOTES.md#1-scan-throttling-is-real-and-silent).

---

## Permissions

The right permission depends on the Android version, and the plugin picks it:

| Android | Permission requested | Also required |
|---|---|---|
| 13+ (API 33+) | `NEARBY_WIFI_DEVICES` **and** `ACCESS_FINE_LOCATION` | location services on |
| 6–12 (API 23–32) | `ACCESS_FINE_LOCATION` | location services on |

Either grant satisfies the platform, so `checkPermission()` reports `Granted` if
**either** is held.

**Location services being switched on is a separate condition from the
permission** on API 28+, and it is the single most common reason a correct
integration returns an empty list. The native `CheckPermission` response
includes `locationServicesEnabled` for exactly this — reachable today from the
JS wrapper (`await wifi.checkPermission()`), where you can prompt the user to
open location settings before blaming your own code.

### Delivering the permission result

`requestPermission()` returns immediately: `Granted` if the permission was
already held, otherwise `Pending` while the system dialog is up. The definitive
answer is delivered by Android to the host Activity's
`onRequestPermissionsResult`. NativePHP apps typically route this already; if
yours doesn't surface it, re-check with `Wifi::checkPermission()` on app resume
— the plugin dispatches `PermissionGranted` on the next `scan()` once the grant
has landed.

---

## Configuration

```bash
php artisan vendor:publish --tag=wifi-scan-config
```

```php
// config/wifi-scan.php
'include_hidden' => env('WIFI_SCAN_INCLUDE_HIDDEN', false),
'max_results'    => env('WIFI_SCAN_MAX_RESULTS', 0),
```

| Key | Default | Effect |
|---|---|---|
| `include_hidden` | `false` | Keep APs that broadcast no SSID in `scan()` results. Their BSSID is still useful for fingerprinting, so turn this **on** if you're doing place detection. |
| `max_results` | `0` (no cap) | Cap `scan()` results. The native layer sorts strongest-first, so a cap keeps the nearest radios. |

Both filters apply to `scan()` only — `NetworksScanned` payloads are the raw
native list.

---

## Place fingerprinting

`WifiScan\Mobile\Support\BssidFingerprint` — pure PHP, no native calls, fully
unit-testable.

| Method | Returns | Purpose |
|---|---|---|
| `normalize(string $bssid)` | `string` | Lowercase, strip separators, hex only. |
| `set(array $points)` | `array<int,string>` | Sorted, unique, normalized BSSIDs. Accepts `AccessPoint`s or raw strings. Drops blank and sentinel MACs (`00:…:00`, `02:…:00`). |
| `hash(array $points)` | `string` | SHA-256 of the set — identical wherever the same radios are visible, regardless of scan order or signal strength. |
| `similarity(array $a, array $b)` | `float` `0.0`–`1.0` | Jaccard index: `|A ∩ B| / |A ∪ B|`. |

### The recipe

**1. Record a place.** When the user tells you where they are, store the *set*,
not just the hash — you need the members to score partial matches later.

```php
use WifiScan\Mobile\Facades\Wifi;
use WifiScan\Mobile\Support\BssidFingerprint;

$scan = Wifi::scan();

Place::create([
    'name'   => 'Office',
    'digest' => BssidFingerprint::hash($scan),   // fast exact-match index
    'bssids' => BssidFingerprint::set($scan),    // cast to array/json
]);
```

**2. Recognise it later.** Try the hash first (one indexed lookup, free), fall
back to scoring:

```php
$scan   = Wifi::scan();
$digest = BssidFingerprint::hash($scan);

$place = Place::where('digest', $digest)->first()
    ?? Place::all()
        ->map(fn ($p) => [$p, BssidFingerprint::similarity($scan, $p->bssids)])
        ->sortByDesc(fn ($pair) => $pair[1])
        ->first(fn ($pair) => $pair[1] >= 0.6)[0] ?? null;
```

**Thresholds.** `0.6` is a sane starting point for a room-sized place. Go higher
(`0.75`+) in dense apartment blocks where neighbouring flats share most radios;
go lower (`0.4`) in sparse areas where two or three APs are all there is. Tune
against real data — this is a product decision, not a library one.

**Exact-hash matching is brittle on its own.** One neighbour rebooting a router
changes the set and therefore the hash. Keep the digest as a fast path, never as
the only path.

**Stability tips**
- Turn on `include_hidden` — hidden APs are excellent fingerprint members.
- Leave `max_results` at `0` for fingerprinting; capping throws away the weak
  distant radios that make a place distinctive.
- Consider storing several observations per place and matching against the best
  of them. Signal environments differ between where you stand in a room.

**Privacy.** Hash on the device and send the digest when you only need
recognition. Send the raw BSSID set only when you actually need server-side
scoring — it is location data, and Google Play's Data safety form treats it as
such. See [STORE-REVIEW.md](STORE-REVIEW.md).

---

## Per-stack usage

### Livewire / SuperNative

Use the PHP facade directly; listen for events with `#[OnNative]`. No JS needed.

### Inertia (Vue / React) and other web-view stacks

Alias the shipped module in `vite.config.js`:

```js
resolve: {
  alias: {
    '@wifi': '/vendor/all1web/nativephp-wifi-scan/resources/js/wifi.js',
  },
},
```

```js
import wifi from '@wifi';

const { networks, count, fromCache, scanRequested } = await wifi.scan();
const rows = JSON.parse(networks);            // networks is a JSON string

const connected  = await wifi.current();       // { connected, ssid, bssid, rssi, frequency }
const permission = await wifi.checkPermission();// { status, requiredPermission, locationServicesEnabled }
await wifi.requestPermission();                 // { granted, status }

document.addEventListener('native-event', (e) => {
  if (e.detail.event.endsWith('NetworksScanned')) { /* refresh */ }
  if (e.detail.event.endsWith('ScanFailed'))      { /* show why */ }
});
```

One JS function per bridge function, same names as the PHP facade. The JS layer
returns the **raw bridge maps** — it does no filtering, so `include_hidden` and
`max_results` do not apply there.

---

## Version compatibility

`nativephp/mobile: ^3.0 || ^4.0`.

The v4 release currently installed in host apps is `4.0.0-rc.1`. As a
pre-release it satisfies neither a plain `^3.0` nor a stable `^4.0`, so host
apps pin it through the documented inline alias:

```json
"nativephp/mobile": "4.0.0-rc.1 as 3.99.99"
```

which presents the package to Composer as stable `3.99.99` — inside `^3.0`. The
plugin's `^3.0 || ^4.0` constraint therefore resolves against the RC today and
against real 4.x later. Constraining to `^4.0` alone would break under the
alias.

Android floor is `minSdk 23`. `NEARBY_WIFI_DEVICES` only exists on API 33+; the
plugin falls back automatically below it.

---

## Bridge contract

For anyone extending the native layer. Function names must match
`nativephp.json` exactly — `WifiScan\Mobile\Enums\BridgeFunction` is the single
source of truth on the PHP side.

| Bridge function | Returns |
|---|---|
| `Wifi.Scan` | `networks` (JSON **string**), `count`, `fromCache`, `scanRequested` |
| `Wifi.Current` | `connected`, and when true: `ssid`, `bssid`, `rssi`, `frequency` |
| `Wifi.CheckPermission` | `status`, `requiredPermission`, `locationServicesEnabled` |
| `Wifi.RequestPermission` | `granted`, `status` |

`networks` crosses as a JSON string on purpose: the bridge marshals
`Map<String, Any>`, and nested arrays survive most reliably as a string. The PHP
decoder accepts either a string or an already-decoded array.

The native layer builds responses with the runtime's `BridgeResponse`: `success`
returns the payload **bare**, `error` wraps it as
`{status, code, message, data}`. The PHP `call()` unwraps `data` defensively,
which is why every error collapses uniformly to an empty result. **Never name a
payload key `data`** in a future bridge function or the unwrap will eat it.

---

## Limits and disclaimers

Stated plainly so you can design around them rather than discover them.

**The OS decides, not this plugin.** Scan availability, timing, throttling, and
broadcast delivery are entirely controlled by Android and the device
manufacturer. No library can guarantee a scan completes, or completes within any
particular time. Build UI that works from the cached list and treats fresh
results as an improvement.

**Events are best-effort.** `NetworksScanned`, `PermissionGranted`, and
`PermissionDenied` depend on a live foreground Activity and, for permission
events, on how the host app routes `onRequestPermissionsResult` — which varies
by NativePHP version and app template. **Always re-check with
`checkPermission()` on resume rather than relying on the event.** The pull path
is the supported one; events are the optimization.

**OEM variance is real.** Battery managers on Xiaomi, Huawei, Samsung and others
can suppress scan broadcasts entirely. Devices in battery-saver mode may report
`results_not_updated` indefinitely. Behaviour verified on one handset does not
generalise to all.

**Release builds are not verified against R8.** If you build with
`minifyEnabled true` and the bridge functions go missing, add
`-keep class com.wifiscan.mobile.** { *; }` to `proguard-rules.pro`. Test a
release APK before publishing.

**No iOS, permanently, for `scan()`.** See
[PLATFORM-NOTES.md §8](PLATFORM-NOTES.md#8-ios-there-is-no-api).

**Fingerprint accuracy is not guaranteed.** BSSID similarity is a heuristic. Its
accuracy depends on AP density, environment churn, and your threshold. Do not
use it as a sole factor for anything safety-critical, access-controlling, or
legally consequential.

**Emulators cannot exercise any of this.** No WiFi radio, no meaningful results.
