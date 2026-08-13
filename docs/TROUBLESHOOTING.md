# Troubleshooting

Every failure we know about, as **symptom → cause → fix**. Run the doctor first;
it detects most of these automatically and prints the fix:

```bash
php artisan wifi-scan:doctor
```

**Jump to your symptom**

- [`scan()` returns an empty array](#1-scan-returns-an-empty-array)
- [`scan()` returns results, but they never change](#2-scan-returns-results-but-they-never-change)
- [`NetworksScanned` never fires](#3-networksscanned-never-fires)
- [I get `ScanFailed` with `results_not_updated`](#4-scanfailed-with-results_not_updated)
- [Everything returns empty / null, no errors at all](#5-everything-returns-empty-or-null-with-no-errors)
- [`current()` returns `<unknown ssid>`](#6-current-returns-unknown-ssid)
- [`current()` returns null while clearly connected](#7-current-returns-null-while-connected)
- [The permission dialog never appears](#8-the-permission-dialog-never-appears)
- [`PermissionGranted` never fires after the user taps Allow](#9-permissiongranted-never-fires)
- [Duplicate `NetworksScanned` events](#10-duplicate-networksscanned-events)
- [It works in debug, breaks in release](#11-works-in-debug-breaks-in-release)
- [Nothing works on iOS](#12-nothing-works-on-ios)
- [Fingerprint never matches the same place twice](#13-fingerprint-never-matches-the-same-place-twice)
- [Class not found / facade unresolved](#14-class-not-found-or-facade-unresolved)
- [Play Console rejected my app over permissions](#15-play-console-rejected-my-app-over-permissions)

---

## 1. `scan()` returns an empty array

By far the most common report. Work down this list in order — it is ordered by
how often each cause is the real one.

**Cause A — you're on an emulator.** Emulators have no WiFi radio. They return
an empty list or, at best, one synthetic network. *Fix: use a physical device.*
There is no workaround; nothing about this plugin can be meaningfully tested on
an emulator.

**Cause B — location services are OFF.** On Android 9+ (API 28+) a granted
permission is **not sufficient**. The device's Location toggle must also be on
or the platform returns an empty list with no error and no exception.

```php
$d = Wifi::permissionDetails();
if ($d['locationServicesEnabled'] === false) {
    // Send the user to Settings → Location. Nothing will work until this is on.
}
```

**Cause C — the permission isn't granted.** `Wifi::checkPermission()` must
return `PermissionStatus::Granted`. Note that `requestPermission()` returns
`Pending` immediately while the dialog is up — it is not a result. See
[#9](#9-permissiongranted-never-fires).

**Cause D — you re-ran an old build.** The permissions are merged into your
manifest at *build* time. If you installed the plugin and only ran
`php artisan native:run`, your APK has no WiFi permissions. *Fix:*

```bash
php artisan native:install android --force
php artisan native:run android
```

**Cause E — the plugin isn't allow-listed.** NativePHP plugins are opt-in; an
unregistered plugin is silently skipped at build and every bridge call returns
empty. Check `app/Providers/NativeServiceProvider.php` contains
`WifiScanServiceProvider::class`, or run
`php artisan native:plugin:register all1web/nativephp-wifi-scan`.

**Cause F — WiFi is switched off** on the device. `scan()` returns empty and
dispatches `ScanFailed` with reason `wifi_disabled`.

**Cause G — you filtered everything out.** If `include_hidden` is `false`
(default) and every AP nearby is hidden, or `max_results` is misconfigured,
you'll see fewer results than the radio found. Check `config/wifi-scan.php`.

---

## 2. `scan()` returns results, but they never change

**Cause: this is the designed behaviour.** `scan()` returns the platform's
**cached** list synchronously — Android never returns scan results
synchronously, so there is nothing else it *could* return. Fresh results arrive
asynchronously via the `NetworksScanned` event.

If you only ever call `scan()` in a loop and never listen for the event, you are
looking at the same cache every time.

*Fix:* listen for `NetworksScanned` (see [REFERENCE.md](REFERENCE.md#events)),
or accept the cached list — for place fingerprinting the cache is usually fine.

Related: if `scanRequested` came back `false`, the platform throttled your
refresh and no event is coming this time. See [#3](#3-networksscanned-never-fires).

---

## 3. `NetworksScanned` never fires

**Cause A — throttling.** Android limits `startScan()` to **4 calls per 2
minutes** in the foreground (and ~1 per 30 min in the background). Past the
limit `startScan()` returns `false`, no scan runs, and no event can fire. This
is not an error and is not reported as `ScanFailed` — nothing failed, you just
asked too often.

Detect it: the JS wrapper exposes `scanRequested`; when it's `false`, no event
will follow.

*Fix:* don't poll. Scan on a user action or on screen-resume. Treat the cached
list as the normal case.

**Cause B — the app isn't in the foreground.** Event dispatch needs a live
`FragmentActivity` to drive the WebView. Backgrounded or dead, the result is
dropped by design. This plugin is foreground-only; see [DESIGN.md](DESIGN.md).

**Cause C — OEM power management.** Aggressive vendor battery managers (Xiaomi,
Huawei, Samsung adaptive battery) can suppress the scan-results broadcast to
apps they consider idle. The cached list still works; the event simply never
arrives. See [PLATFORM-NOTES.md §6](PLATFORM-NOTES.md#6-oem-power-management).

> ⚠️ **Disclaimer.** Broadcast delivery is entirely at the OS and OEM's
> discretion. On some devices and battery modes `NetworksScanned` may never fire
> even with everything configured correctly. Design your UI so the cached list
> from `scan()` is sufficient, and treat the event as an enhancement.

---

## 4. `ScanFailed` with `results_not_updated`

**Cause: the platform ran the scan and reported that it failed.** Android's
scan-complete broadcast carries an `EXTRA_RESULTS_UPDATED` flag; when it is
`false`, `scanResults` still holds the *previous* batch. Rather than present
stale data as fresh, the plugin dispatches `ScanFailed`.

*Fix:* nothing to fix — keep displaying your cached list. Common on devices in
battery-saver mode or with WiFi scanning throttled in Developer options.

---

## 5. Everything returns empty or null with no errors

**Cause A — you're off-device.** In a browser, in `php artisan test`, in CI, or
on iOS, the native bridge doesn't exist. Every call degrades to a safe empty
value on purpose so your Blade/Livewire code renders everywhere:

| Call | Off-device result |
|---|---|
| `scan()` | `[]` |
| `current()` | `null` |
| `checkPermission()` | `PermissionStatus::Unknown` |
| `permissionDetails()` | status `Unknown`, both details `null` |

This is not a failure mode; it's the design. Check
`function_exists('nativephp_call')` if you need to branch.

**Cause B — a bridge error envelope.** If the native side returns an error, the
PHP layer collapses it to the same empty values rather than throwing. Check
`adb logcat -s WifiScan` for the real reason.

---

## 6. `current()` returns `<unknown ssid>`

**Cause: Android's placeholder when the caller lacks a location grant.** This is
the OS telling you the permission is missing, not a plugin bug.

*Fix:* grant the scan permission ([#1 Cause C](#1-scan-returns-an-empty-array)).
Note the **BSSID is still reliable** in this state — which is exactly why the
fingerprint helper uses BSSIDs and not SSIDs.

---

## 7. `current()` returns null while connected

**Cause A — the sentinel BSSID.** When not associated (or when the OS withholds
it) Android reports `02:00:00:00:00:00` or `00:00:00:00:00:00`. The plugin
treats both as "not connected" and returns `null`, because a sentinel MAC in
your data is worse than a null.

**Cause B — you're on cellular/ethernet**, not WiFi. `current()` reports the
WiFi association only.

---

## 8. The permission dialog never appears

**Cause A — already permanently denied.** Android stops showing the dialog after
the user selects "Don't allow" twice. `requestPermission()` returns without a
prompt.

*Fix:* you cannot re-prompt. Detect the state and send the user to app settings
with an explanation. This is standard Android behaviour for every app.

**Cause B — the permission isn't in the built manifest** — see
[#1 Cause D/E](#1-scan-returns-an-empty-array). Android silently ignores a
runtime request for a permission the manifest doesn't declare.

---

## 9. `PermissionGranted` never fires

**Cause: the definitive grant is delivered by Android to the host Activity's
`onRequestPermissionsResult`,** not to the plugin directly.
`requestPermission()` returns `Pending` immediately — that is the dialog being
shown, not a decision.

*Fix:* the reliable pattern is to re-check on resume rather than wait for the
event:

```php
// on app resume / screen mount
if (Wifi::checkPermission() === PermissionStatus::Granted) {
    // proceed
}
```

> ⚠️ **Disclaimer.** Whether the permission-result events fire depends on how
> the host app routes `onRequestPermissionsResult`, which varies by NativePHP
> version and app template. **Treat `PermissionGranted`/`PermissionDenied` as
> best-effort and always re-check with `checkPermission()` on resume.** The
> re-check path is the supported one.

---

## 10. Duplicate `NetworksScanned` events

**Cause: fixed in v0.2.0.** Before v0.2.0 each `scan()` registered its own
receiver; throttled calls left receivers armed, and one later broadcast fired
all of them.

*Fix:* upgrade to v0.2.0+, which uses a single receiver slot.

If you still see duplicates on v0.2.0+, you likely have two listeners registered
in your own app (e.g. an `#[OnNative]` attribute plus a manual `Event::listen`).

---

## 11. Works in debug, breaks in release

**Cause: R8/ProGuard.** Release builds shrink and obfuscate. The bridge resolves
your plugin's Kotlin classes by their fully-qualified names from `nativephp.json`
via reflection, which static analysis cannot see.

*Fix:* if the plugin's functions are missing in a minified release build, add to
your app's `proguard-rules.pro`:

```proguard
-keep class com.wifiscan.mobile.** { *; }
```

> ⚠️ **Disclaimer.** As of this release the plugin has not been verified against
> a minified release build. If you ship with `minifyEnabled true`, test a
> release APK before publishing and add the keep rule above if scans stop
> working.

---

## 12. Nothing works on iOS

**Cause: there is no public iOS API to enumerate WiFi networks.** Not
restricted, not entitlement-gated for normal apps — none. `NEHotspotHelper`
needs an Apple entitlement granted only for narrow carrier use cases.

The plugin ships no iOS code. Your iOS build compiles fine and every call
returns its empty value. This is permanent, not a roadmap item — see
[PLATFORM-NOTES.md §8](PLATFORM-NOTES.md#8-ios-there-is-no-api).

---

## 13. Fingerprint never matches the same place twice

**Cause A — you're comparing hashes.** `BssidFingerprint::hash()` changes
completely if a single AP appears or disappears — one neighbour rebooting a
router is enough.

*Fix:* use `similarity()` with a threshold, and keep the hash only as a fast
exact-match index:

```php
$score = BssidFingerprint::similarity($now, $known);
if ($score >= 0.6) { /* same place */ }
```

**Cause B — you're throwing away the distinctive APs.** For fingerprinting set
`include_hidden => true` and leave `max_results` at `0`. Weak and hidden APs are
what make a place distinctive.

**Cause C — mobile APs.** A phone hotspot or vehicle WiFi follows the user
between places and pollutes the set. Consider excluding BSSIDs you observe at
more than one known place.

**Not the cause: MAC randomization.** Android randomizes *your device's* MAC,
not the access point's BSSID. Fingerprinting is unaffected — see
[PLATFORM-NOTES.md §3](PLATFORM-NOTES.md#3-mac-randomization-does-not-break-fingerprinting).

---

## 14. Class not found or facade unresolved

**Cause A — stale autoloader.** Run `composer dump-autoload`.

**Cause B — config cached from before install.** Run `php artisan config:clear`.

**Cause C — you're importing the wrong namespace.** It is
`WifiScan\Mobile\Facades\Wifi` (the package vendor is `all1web/`, the PHP
namespace is `WifiScan\Mobile` — they intentionally differ; see
[DESIGN.md](DESIGN.md#identity)).

---

## 15. Play Console rejected my app over permissions

**Cause: this plugin adds location-class permissions** and Google Play has
policy attached to those. The plugin is not invisible to review, and
[STORE-REVIEW.md](STORE-REVIEW.md) exists specifically for this.

The short version:

- Declare location collection on the **Data safety form** if you transmit scans
  or anything derived from them off-device.
- Add a **prominent in-app disclosure** *before* requesting the permission.
- Your privacy policy must cover it.

Good news: the plugin requests **no** `ACCESS_BACKGROUND_LOCATION`, so you do
**not** need Play's location-permissions declaration form.

If your use case genuinely isn't locational, you can add
`android:usesPermissionFlags="neverForLocation"` in your own manifest when
targeting Android 13+ — see
[STORE-REVIEW.md](STORE-REVIEW.md#the-neverforlocation-question).

---

## Still stuck?

Open an issue with:

1. The full output of `php artisan wifi-scan:doctor`
2. Android version and device model (OEM matters — see PLATFORM-NOTES)
3. `adb logcat -s WifiScan` output around the failure
4. Whether it reproduces on a second physical device

Without 1 and 2 we can only guess, and the answer is almost always one of the
items above.
