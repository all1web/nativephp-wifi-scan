# Changelog

All notable changes to `all1web/nativephp-wifi-scan` ("WiFi Radar") are
documented here. The format follows
[Keep a Changelog](https://keepachangelog.com); versions follow
[semver](https://semver.org).

## v0.1.0 — 2026-08-13

> The PHP layer and the fingerprint primitives are covered by the test suite.
> The native Android layer is structurally pinned but **on-device validation is
> outstanding** — the checklist is in
> [docs/DESIGN.md](docs/DESIGN.md#what-is-verified-vs-what-is-not).

Initial release.

### Added

- **WiFi scanning from PHP** — `Wifi::scan()` returns the visible access points
  as `AccessPoint` value objects (SSID, BSSID, RSSI in dBm, frequency in MHz),
  sorted strongest-first by the native layer.
- **Connected access point** — `Wifi::current()` returns the associated AP or
  `null`, with sentinel-MAC handling for the unassociated case.
- **Cached-now, fresh-later scan semantics.** Android never returns scan results
  synchronously and throttles `startScan()` (4 calls / 2 min in the
  foreground), so `scan()` returns the platform's cached list immediately and a
  fresh scan is delivered through the `NetworksScanned` event. Throttling is
  reported as `scanRequested: false` rather than as a failure.
- **Version-aware permission handling** — `NEARBY_WIFI_DEVICES` on Android 13+,
  `ACCESS_FINE_LOCATION` below it, either grant accepted, plus a
  `locationServicesEnabled` read because on API 28+ a granted permission alone
  still returns an empty list.
- **Events** — `NetworksScanned`, `ScanFailed` (`permission` / `wifi_disabled` /
  `unknown`), `PermissionGranted`, `PermissionDenied`.
- **`BssidFingerprint`** — pure-PHP place fingerprinting: `normalize()`,
  `set()` (dedup, sort, sentinel-MAC rejection), `hash()` (order-independent
  SHA-256), and `similarity()` (Jaccard). No device required, fully unit-tested.
- **Runtime config** (`config/wifi-scan.php`): `include_hidden`, `max_results`.
- **JavaScript module** (`resources/js/wifi.js`) — one function per bridge
  function for Inertia/Vue/React front-ends, with the same names as the PHP
  facade.
- **Laravel Boost guidelines** so AI pair-programmers get the API right.
- Docs: [README](README.md), [Reference](docs/REFERENCE.md),
  [Platform notes](docs/PLATFORM-NOTES.md),
  [Store review & data safety](docs/STORE-REVIEW.md),
  [Design notes](docs/DESIGN.md).
- Commercial EULA — single seat per developer, unlimited applications, with an
  explicit licensee data-protection clause covering location-class collection.

### Platform floors

Android 6.0+ (minSdk 23), `nativephp/mobile ^3.0 || ^4.0`. **No iOS support** —
there is no public iOS API to enumerate visible WiFi networks, and the plugin
ships no Swift rather than shipping a stub that always reports unsupported.
