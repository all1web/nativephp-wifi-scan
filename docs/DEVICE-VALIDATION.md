# On-device validation runbook

Everything in this plugin that a test suite can verify, it verifies. What
remains needs a **physical Android device** — emulators have no WiFi radio and
return nothing useful.

This is the checklist we run before claiming the native layer works, written so
anyone (including a contributor sending a native PR) can run it identically.

> ✅ **Current status: VALIDATED on hardware (2026-08-19).**
> Device: Samsung Galaxy Z Fold 6 (SM-F956U), Android 16 (API 36) — i.e. the
> modern `NEARBY_WIFI_DEVICES` permission path. Host: a production NativePHP
> v4 (RC) Livewire app.
>
> | # | Check | Result | Evidence |
> |---|---|---|---|
> | 1 | Bridge registration | ✅ | all four `Wifi.*` callable; generated registration compiles clean |
> | 2 | Permission round trip | ✅ | revoked → page shows denied → system dialog → Allow → `granted=true` and a working scan 8 s later; already-held fast path dispatches `PermissionGranted` immediately |
> | 3 | Location-services gate | ✅ read path | `locationServicesEnabled` reported correctly on every call; the toggle-off→empty-list behaviour is Android-documented but was not toggled in this pass |
> | 4 | Cached scan | ✅ | real AP lists (12 → 18 → 23 entries) with correct SSID/BSSID/RSSI fields, strongest-first |
> | 5 | Fresh scan event | ✅ | `NetworksScanned` delivered and cache repopulated; **a 4-tap burst produced exactly ONE event** — the v0.2.0 single-slot receiver verified under fire |
> | 6 | Throttle behaviour | ✅ | screen-off / background scans refused with `scanRequested:false` and no phantom event; exactly 4 in-window foreground requests allowed |
> | 7 | `current()` | ✅ visual | Connected card shows the associated SSID/BSSID on-screen |
> | 8 | WiFi disabled | ⚪ skipped | toggling WiFi severs a wireless-adb rig; path is unit-covered (`ScanFailed('wifi_disabled')` + error envelope) — trivial to eyeball on any phone |
> | 9 | Backgrounding | ✅ | dozens of doze/force-stop/relaunch cycles across the pass, zero crashes; screen-off scans degrade silently as designed |
> | 10 | Release build (R8) | ⚪ not run | debug builds only so far; the keep-rule guidance in TROUBLESHOOTING stands until a minified pass is done |
>
> Denied-state behaviour got validated for free: a scan without the permission
> dispatched `ScanFailed('permission')` and the PHP layer collapsed the error
> envelope to an empty list — the page rendered fine, nothing threw.
>
> The checklist below remains the template for re-validation on other devices
> and OEMs (behaviour varies — see PLATFORM-NOTES).

## Setup

A host Laravel app with NativePHP Mobile, the plugin installed and allow-listed,
built to a real phone:

```bash
composer require all1web/nativephp-wifi-scan
php artisan native:plugin:register all1web/nativephp-wifi-scan
php artisan native:install android --force
php artisan native:run android
```

If `native:run` exits 0 without gradle actually running, build and install
manually from the generated project:

```bash
cd nativephp/android
./gradlew.bat assembleDebug
adb install -r app/build/outputs/apk/debug/app-debug.apk
```

Keep a log window open throughout:

```bash
adb logcat -s WifiScan
```

## The checks

Each has an explicit pass condition. Record the device model and Android
version alongside the results — OEM behaviour varies.

### 1. Bridge registration

The four functions must be callable at all. If the FQNs in `nativephp.json`
don't match the compiled Kotlin, every call silently returns empty.

```bash
php artisan wifi-scan:doctor
```

**Pass:** doctor reports the native bridge as available. **Fail:** "not
available" on a device means the plugin wasn't compiled in — check the allowlist
and rebuild.

### 2. Permission round trip

- Fresh install, permission not yet granted.
- `Wifi::checkPermission()` → `Denied`.
- Call `Wifi::requestPermission()` → returns `Pending`, system dialog appears.
- Tap **Allow**.
- `Wifi::checkPermission()` on resume → `Granted`.

**Pass:** the resume re-check reports `Granted`.
**Note:** whether `PermissionGranted` *fires as an event* is the uncertain part —
record it either way, but the re-check path is what the docs tell people to use.

Also verify the API split: on an **Android 13+** device
`permissionDetails()['requiredPermission']` should be `NEARBY_WIFI_DEVICES`; on
**Android 12 or lower**, `ACCESS_FINE_LOCATION`.

### 3. Location-services gate

With the permission granted, switch **Location OFF** in system settings.

- `permissionDetails()['locationServicesEnabled']` → `false`
- `scan()` → empty list

Switch Location back on; `scan()` returns results.

**Pass:** the flag tracks the system toggle. This is the check that makes the
most common support question self-service.

### 4. Cached scan

- `Wifi::scan()` returns a non-empty array on a device with WiFi in range.
- Entries have a real `ssid`, a lowercase `bssid`, a negative `rssi`, and a
  plausible `frequency` (2400–2500 or 5100–5900).
- Results are ordered strongest-first (descending `rssi`).

**Pass:** all four. Watch specifically for the **API 33 SSID quoting** path —
if SSIDs come back wrapped in `"`, the `wifiSsid` branch isn't stripping them.

### 5. Fresh scan event

- Register an `#[OnNative(NetworksScanned::class)]` listener.
- Call `scan()` once with the app in the foreground.
- Within a few seconds the listener fires with a non-empty `networks` array.

**Pass:** exactly **one** event per successful scan. Two events means the
single-slot receiver regressed.

### 6. Throttle behaviour

Call `scan()` five times in under two minutes.

- The first calls report `scanRequested: true`.
- Later calls report `scanRequested: false`.
- No `ScanFailed` is dispatched for the throttled calls (throttling is not a
  failure).
- No duplicate `NetworksScanned` arrives when a later broadcast lands.

**Pass:** all four. Check the logcat line "startScan() returned false".

### 7. `current()`

- Connected to WiFi: returns an `AccessPoint` whose `ssid` and `bssid` match the
  connected network.
- WiFi off or not associated: returns `null` — and specifically *not* an
  AccessPoint with an all-zero or `02:00:00:00:00:00` BSSID.

### 8. WiFi disabled

Switch WiFi off entirely.

- `scan()` returns empty.
- `ScanFailed` fires with reason `wifi_disabled`.

### 9. Backgrounding

Call `scan()`, then immediately background the app.

**Pass:** no crash. The result is dropped and logged ("No live activity") — this
is the intended degradation, not a bug.

### 10. Release build (R8)

Build with `minifyEnabled true` and repeat checks 1 and 4.

**Pass:** scans still work. **Fail:** add
`-keep class com.wifiscan.mobile.** { *; }` to `proguard-rules.pro`, and we
should ship consumer ProGuard rules in the plugin.

## Recording results

Update this file's status block and
[DESIGN.md](DESIGN.md#what-is-verified-vs-what-is-not) with what passed, on
which device and Android version. Anything that fails becomes an issue before
release — a free plugin with mass installs cannot carry unverified native paths
quietly.
