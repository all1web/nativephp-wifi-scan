# Platform notes

Behaviour in the underlying platform that will surprise you, with the
workaround for each. These are **not bugs in this plugin** — they are how
Android (and Apple) decided WiFi visibility works. We document them so you
don't spend a weekend rediscovering them.

## 1. Scan throttling is real, and silent

`WifiManager.startScan()` is rate-limited by the OS:

| Situation | Limit |
|---|---|
| App in the foreground (Android 9+) | **4 scans per 2 minutes** |
| App in the background | **1 scan per 30 minutes**, often refused outright |

Past the limit, `startScan()` simply returns `false`. Nothing throws, nothing
logs, and — importantly — **the cached results you already have stay valid**.

**How the plugin surfaces it:** `scan()` still returns the cached list, and the
bridge response carries `scanRequested: false`. No `NetworksScanned` event will
follow, because no scan ran. This is deliberately *not* reported as
`ScanFailed`: nothing failed, you just won't get fresher data this minute.

**What to do:** don't poll. Scan on a user action or a screen entering the
foreground, and treat the cached list as the normal case rather than the
degraded one. If you need continuous updates, drive the UI from the cached list
and let `NetworksScanned` refresh it opportunistically.

**Testing tip:** on a physical device, Developer options → **Wi-Fi scan
throttling** can be turned off. Never assume your users have done that.

## 2. The permission is not enough — location services must also be on

On Android 9+ (API 28+), scan results are gated behind *both* a granted
permission *and* the device's location toggle being switched on. A user who
granted your permission but has location off gets an **empty list, with no
error** — the single most common "your plugin is broken" report in this space.

The plugin reads this for you: the `Wifi.CheckPermission` bridge response
includes `locationServicesEnabled`, available from the JS wrapper via
`await wifi.checkPermission()`. Check it before you conclude there are no
networks, and send the user to location settings if it's `false`.

## 3. MAC randomization does *not* break fingerprinting

Android 10+ randomizes the MAC address **your device** presents to each
network. This is often assumed to break BSSID fingerprinting. It does not:
randomization applies to the client MAC, while a BSSID is the **access point's**
address. Routers don't randomize.

What *does* shift a fingerprint over time is churn in the environment — a
neighbour replacing a router, a mesh node added, a hotspot that comes and goes.
That is exactly why
[`BssidFingerprint::similarity()`](REFERENCE.md#place-fingerprinting) exists and
why matching on an exact hash alone is fragile.

Phone hotspots and vehicle WiFi are mobile APs that will follow the user
between places and pollute a fingerprint. If accuracy matters, consider
excluding BSSIDs you observe at more than one known place.

## 4. `<unknown ssid>` and empty SSIDs

Two different things produce a nameless network, and they need different
responses:

- **Empty string `""`** — a hidden network (broadcasts no SSID). Filtered out of
  `scan()` unless `include_hidden` is on. Its BSSID is perfectly good for
  fingerprinting, so turn the flag on when doing place detection.
- **`<unknown ssid>`** — Android's placeholder from `WifiInfo` when the caller
  lacks a location grant. If you see this from `current()`, the fix is a
  permission, not a config key. `current()`'s BSSID remains the reliable field
  either way, which is why the fingerprint uses it.

## 5. Emulators can't test this

The Android emulator has no WiFi radio. It presents at most a single synthetic
network and returns scan results that look nothing like a real environment.
Every meaningful test of this plugin — throttling, event delivery, the
permission round-trip, real `ScanResult` shapes — requires a **physical
device**. The PHP layer and the fingerprint math are fully testable without
one, which is why they're separated from the native layer.

## 6. OEM power management

Aggressive vendor battery managers (Xiaomi, Huawei, Samsung's adaptive
battery) can suppress broadcasts to apps they consider idle, which means a
requested scan may never deliver `SCAN_RESULTS_AVAILABLE_ACTION`. The plugin
degrades correctly — you keep the cached list and simply never get the event —
but it's worth knowing why a scan "hangs" on one handset and not another.
Foreground use is far more reliable, which is one of two reasons this plugin is
foreground-only (the other is the background throttle above).

## 7. Deprecated APIs we use on purpose

`WifiManager.getConnectionInfo()` (the `current()` path) is deprecated as of
Android 12 in favour of `ConnectivityManager` + `NetworkCapabilities.transportInfo`.
We use it anyway: it works down to our `minSdk 23` floor, it is far simpler, and
the replacement's behaviour differs across the range we support. Migration is a
clean, isolated change to one class when the floor rises. Deprecated is not
removed.

## 8. iOS: there is no API

Not "restricted", not "requires an entitlement most apps can get" — **there is
no public iOS API to enumerate visible WiFi networks.**

- `NEHotspotHelper` can see networks, but requires a special Apple entitlement
  granted only for narrow carrier/hotspot use cases. Applying for it to build a
  general-purpose app is not a path.
- Even reading the *connected* network (`NEHotspotNetwork.fetchCurrent`) needs
  the Access WiFi Information capability **plus** a location permission — and
  returns one network, never a scan.

So this plugin ships no Swift and declares no iOS support. If Apple opens the
connected-network read further, a `current()`-only iOS path is a clean addition
that wouldn't change your PHP. `scan()` on iOS is not a roadmap item; it's a
platform impossibility.

---

*Why this page exists: we'd rather tell you where the floor is than let you
find it in a bug report from a user.*
