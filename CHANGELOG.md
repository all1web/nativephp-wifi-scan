# Changelog

All notable changes to `all1web/nativephp-wifi-scan` ("WiFi Radar") are
documented here. The format follows
[Keep a Changelog](https://keepachangelog.com); versions follow
[semver](https://semver.org).

## v0.2.2 — 2026-08-19

**`native:plugin:validate` now passes.** v0.2.1 failed the marketplace
validator with `Missing ios.min_version in nativephp.json` — the validator
requires an `ios.min_version` for every plugin, Android-only ones included.
The manifest now declares `"ios": {"min_version": "13.0"}`. Purely
declarative: this plugin ships no iOS bridge targets and no `resources/ios`
directory, so the iOS compiler continues to skip it entirely and no host
app's deployment floor changes. Verified: the validator reports OK with zero
warnings.

## v0.2.1 — 2026-08-19

**On-device validation pass.** The native layer ran on hardware — Samsung
Galaxy Z Fold 6, Android 16 (API 36), inside a production NativePHP v4 host
app. Results, per check with evidence:
[docs/DEVICE-VALIDATION.md](docs/DEVICE-VALIDATION.md). Highlights: the full
permission-dialog round trip, real scans (12–23 APs), fresh-scan events
delivering, and the single-slot receiver holding **one** event under a 4-tap
burst. Two items remain open and documented: the WiFi-off toggle (severs a
wireless-adb rig; unit-covered) and a minified release build.

### Added

- Success-path debug logs on `Scan` / `CheckPermission` (`adb logcat -s
  WifiScan`) — silence was previously indistinguishable from never-ran, which
  is the wrong property for a support tool.

### Fixed

- `wifi-scan:doctor`: an undeterminable permission state off-device (host apps
  define `nativephp_call()` even on desktop, where it no-ops) is now an
  informational line instead of a failure.

## v0.2.0 — 2026-08-13

**Now free and MIT-licensed.** No license key, no seat limits, no purchase step.

> The PHP layer, bridge contract, and fingerprint primitives are covered by 57
> passing tests. The native Android layer is structurally pinned but
> **on-device validation is still outstanding** — see
> [docs/DEVICE-VALIDATION.md](docs/DEVICE-VALIDATION.md) for the runbook and
> [docs/DESIGN.md](docs/DESIGN.md#what-is-verified-vs-what-is-not) for the exact
> line between verified and unverified.

### Changed

- **Licence: proprietary EULA → MIT.** `composer.json`, `package.json`, and
  `LICENSE` updated; install is now a plain `composer require` with no
  marketplace credentials.
- **JS wrapper rewritten against the transport the runtime actually serves.**
  v0.1.0 called a `window.nativephp.call()` global that does not exist — the
  JS path could never have worked. It now POSTs to `/_native/api/call` with the
  CSRF header, matching the shipped share-target/widgets plugins, decodes the
  `networks` JSON string for you, and degrades to safe empty values instead of
  throwing when the bridge is absent.

### Fixed

- **Duplicate `NetworksScanned` events.** Each `scan()` used to register its own
  one-shot `BroadcastReceiver`. Throttled calls left receivers armed, so one
  later broadcast fired all of them. Replaced with a synchronized single-slot
  receiver: one registration ever, re-armed after each delivery.
- **Stale results presented as fresh.** The scan-complete broadcast carries
  `EXTRA_RESULTS_UPDATED`; when `false`, the scan failed and `scanResults` still
  holds the previous batch. The plugin now dispatches
  `ScanFailed('results_not_updated')` instead of a `NetworksScanned` carrying
  the old cache. A missing flag is treated as `true` (deliver rather than drop).
- **Error envelopes could leak into app code.** `BridgeResponse.error()` returns
  `{status: "error", code, message, data}`; the PHP `call()` did not unwrap it,
  so `checkPermission()` could see `status: "error"`. Now unwrapped defensively,
  matching the device-proven share-target pattern — every error collapses to the
  same empty value as being off-device.
- The scan receiver is registered against the **application context** rather
  than the Activity, so it cannot pin an Activity or leak across rotation.

### Added

- **`php artisan wifi-scan:doctor`** — diagnoses allowlist registration,
  generated-manifest permissions, runtime bridge, scan permission, location
  services, and config, printing the exact fix for each broken link. Exit code
  1 on any problem, so it works in CI.
- **`Wifi::permissionDetails()`** — status plus `requiredPermission` and
  `locationServicesEnabled` in one call. Location services being off is the most
  common cause of an empty scan and was previously only reachable from JS.
- **[docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)** — 15 failure modes as
  symptom → cause → fix.
- **[docs/FAQ.md](docs/FAQ.md)** — every predictable question, answered.
- **[docs/DEVICE-VALIDATION.md](docs/DEVICE-VALIDATION.md)** — the on-device
  test runbook.
- Explicit **limits and disclaimers** section in the reference: OS-controlled
  timing, best-effort events, OEM variance, unverified R8 behaviour, fingerprint
  accuracy caveats.
- GitHub Actions test matrix (PHP 8.2/8.3/8.4 × lowest/stable), issue templates
  that require doctor output and route common questions to the docs, and
  `SECURITY.md`.
- Tests: bridge-contract error-envelope collapse, malformed JSON, event
  constructor-parameter names (the runtime binds by name), doctor registration,
  documented-reason coverage. 43 → 57.

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
- Isometric-diorama listing art (`art/hero.svg`, `art/flow.svg` + rendered
  PNGs), mirrored to the public asset host the README links against.
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
