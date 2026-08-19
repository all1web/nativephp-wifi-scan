# Design notes — WiFi Radar (`all1web/nativephp-wifi-scan`)

This records the architecture decisions, the platform constraints that forced
them, and — importantly — **what cannot be verified until this plugin is built
into a host app and run on a device**. It is written to be read by whoever
integrates or extends the package next.

Related: [REFERENCE.md](REFERENCE.md) is the API contract,
[PLATFORM-NOTES.md](PLATFORM-NOTES.md) is the field guide to Android's
behaviour, and [STORE-REVIEW.md](STORE-REVIEW.md) covers the permission and
data-safety consequences for a shipping app.

## Identity

The package is `all1web/nativephp-wifi-scan` and sells as **WiFi Radar**. The
PHP namespace (`WifiScan\Mobile`), the Kotlin package
(`com.wifiscan.mobile`), and the bridge namespace (`Wifi.*`) predate the
product name and are deliberately left alone — they are compiled-in identifiers
whose only requirement is uniqueness against other plugins in the same host
app. Renaming them would be a breaking change bought for nothing.

## What it does

Four bridge functions in the `Wifi.*` namespace, all Android, all foreground:

| Bridge fn | PHP | Returns |
|-----------|-----|---------|
| `Wifi.Scan` | `Wifi::scan()` | cached AP list now; fresh list via `NetworksScanned` |
| `Wifi.Current` | `Wifi::current()` | connected AP (SSID/BSSID/RSSI) or null |
| `Wifi.CheckPermission` | `Wifi::checkPermission()` | `PermissionStatus` |
| `Wifi.RequestPermission` | `Wifi::requestPermission()` | `PermissionStatus` (+ event) |

Plus two pure PHP primitives that need no device: `Data\AccessPoint` (the value
object) and `Support\BssidFingerprint` (order-independent set/hash/similarity for
location matching).

## Decision 1 — `scan()` returns cached-now, fresh-later

Android's `WifiManager` **never** hands back scan results synchronously.
`startScan()` only *requests* a scan; results arrive later via the
`SCAN_RESULTS_AVAILABLE_ACTION` broadcast, and reading `wifiManager.scanResults`
returns the last cached batch. Worse, `startScan()` is throttled:

- Foreground app: **4 calls / 2 min** (API 28+).
- Background app: **1 call / 30 min** (API 30+); often refused outright.

So a synchronous "give me the networks now" call is a lie the platform can't
honour. The honest contract we ship:

- `scan()` returns the **cached** list immediately (fast, always available).
- It registers a one-shot `BroadcastReceiver` and calls `startScan()`.
- When (if) fresh results land, we dispatch `NetworksScanned` back into PHP.
- If `startScan()` is throttled it returns `false`; the cached list is still
  valid and `scanRequested: false` tells the caller no refresh is coming.

## Decision 2 — foreground only

Two independent reasons:

1. **Throttle** (above): background scans are largely useless.
2. **Event delivery needs an Activity.** `NativeActionCoordinator.dispatchEvent`
   drives the WebView / Livewire; with the app backgrounded or dead there is no
   `FragmentActivity` and no WebView. We keep a `WeakReference` `ActivityHolder`
   and, in the scan-results receiver, `ActivityHolder.get() ?: drop` — degrading
   gracefully rather than crashing. We deliberately did **not** add
   `android_params: ["context"]` cold-boot variants: a context-only scan could
   run from a WorkManager worker but could neither beat the throttle nor deliver
   its result, so it would be a footgun. If a future version needs background
   collection it should persist results to `EncryptedSharedPreferences` and
   flush on next foreground (the local-notifications pattern), not dispatch from
   the receiver.

## Decision 3 — permission model straddles two eras

- **API ≤ 32:** scan results are gated behind `ACCESS_FINE_LOCATION` *and*
  location services being switched on.
- **API 33+:** the dedicated `NEARBY_WIFI_DEVICES` runtime permission covers
  scanning. We request it alongside fine location and accept **either** grant
  (`hasScanPermission` = fine OR nearby).

`CheckPermission` also reports `locationServicesEnabled` so the app can prompt
the user to switch location on (a granted permission is not enough on API 28–32).

Note on `NEARBY_WIFI_DEVICES`: to use it *without* implying location you must
declare `usesPermissionFlags="neverForLocation"`. We do **not** — this plugin's
whole purpose is location fingerprinting, so associating it with location is
correct and keeps the fine-location fallback coherent.

## Decision 4 — `current()` uses `WifiInfo.connectionInfo`

Deprecated in API 31 in favour of `ConnectivityManager` + `NetworkCapabilities.
transportInfo`, but `connectionInfo` still works to our `minSdk` and is far
simpler. SSID reads as `"<unknown ssid>"` without a location grant; BSSID is the
reliable field and is what the fingerprint uses. Migrating to the
`ConnectivityManager` path is a clean future change isolated to the `Current`
class.

## Decision 5 — bridge data crosses as JSON strings

The bridge marshals `Map<String, Any>`. Nested arrays (a list of AP objects)
survive most reliably as a JSON **string** field rather than a nested map, so
`Wifi.Scan` returns `networks` as a `JSONArray().toString()` and PHP
`decodeNetworks()` accepts either a string or an already-decoded array. Same
shape is used for the `NetworksScanned` event payload.

## Decision 6 — Android only in this release

There is **no public iOS API to scan visible WiFi networks.** `NEHotspotHelper`
exists but requires a special Apple entitlement granted only for niche use cases,
and even the connected-SSID read (`NEHotspotNetwork.fetchCurrent`) needs the
"Access WiFi Information" capability plus location permission. Rather than ship
Swift that references a bridge protocol we could not verify against the installed
RC and that would mostly return "unsupported", we omitted iOS entirely: no `ios`
FQNs in `nativephp.json`, no Swift files. A future iOS addition would realistic­ally
cover `current()` only, never `scan()`.

## Compatibility — the `3.99.99` alias reality

Every NativePHP Mobile plugin constrains `nativephp/mobile` at `^3.0 || ^4.0`.
The v4 release currently installed in host apps is `4.0.0-rc.1`, which as a
pre-release satisfies neither a plain `^3.0` nor a stable `^4.0`. Host apps pin
`"nativephp/mobile": "4.0.0-rc.1 as 3.99.99"`, and the inline alias presents the
package to Composer as stable `3.99.99` — inside `^3.0`. Our `^3.0 || ^4.0`
constraint therefore resolves today against the RC and against real 4.x later.
Pinning `^4.0` alone would break under the alias. `require-dev` matches the
shipped plugins (`orchestra/testbench ^10.0`, `pest ^2.7|^3.0|^4.0`).

## What is verified vs. what is NOT

**Verified off-device (57 passing Pest tests):**

- The bridge contract against the shapes the *installed* NativePHP runtime
  returns — read from `BridgeRouter.kt` in vendor, not assumed: `success()`
  returns the payload bare, `error()` wraps as `{status, code, message, data}`.
  Every getter collapses an error envelope to its empty value.
- Event constructor parameter names, because the runtime binds them **by name**
  (`NativeComponent::makeEventInstance`) — a rename would silently break
  hydration on-device with no compile-time signal.
- The `wifi-scan:doctor` command registers and runs.
- Manifest ↔ `BridgeFunction` enum bijection, Kotlin FQN resolution, one JS
  export per bridge function over the runtime's real `/_native/api/call`
  transport, the permission set, and the structural absence of any iOS claim.

- `AccessPoint` construction, BSSID lowercasing, graceful degradation, toArray
  round-trip, collection reindexing.
- `BssidFingerprint` normalize / set (dedup, sort, placeholder-MAC drop,
  string-type preservation) / order-independent hash / Jaccard similarity.
- `Wifi` facade: JSON-string decode, hidden-network filter, `max_results` cap,
  connected-AP parsing, null-when-unassociated, permission-status mapping,
  correct bridge function names dispatched.
- Service provider bindings, aliases, config merge, facade resolution.

**Verified ON DEVICE (2026-08-19, Galaxy Z Fold 6 / Android 16):** the items
below were the open list at v0.2.0 and have since been validated on hardware —
full results with evidence in
[DEVICE-VALIDATION.md](DEVICE-VALIDATION.md). Kept for the record of what
needed a phone and why (emulators have no WiFi radio and return empty scans):

1. That the four bridge functions register and are callable — i.e. that the
   `com.wifiscan.mobile.WifiFunctions.*` FQNs in `nativephp.json` match the
   compiled Kotlin and that `AndroidPluginCompiler` generates the registration.
2. Real `WifiManager.scanResults` shape and the SSID read path on API 33+
   (`wifiSsid` quoting) vs. legacy (`SSID`).
3. `startScan()` throttle behaviour and whether `SCAN_RESULTS_AVAILABLE_ACTION`
   fires in practice on the target OEM.
4. `NativeActionCoordinator.dispatchEvent` actually delivering `NetworksScanned`
   / permission events into a live Livewire/SuperNative listener.
5. The permission-request round trip — specifically whether the host Activity's
   `onRequestPermissionsResult` surfaces the grant so `PermissionGranted` fires.
   Our fallback (re-dispatch on next `scan()` after a detected grant) is designed
   but unproven on-device.
6. `current()` returning a real SSID/BSSID when associated, and the all-zero
   BSSID sentinel handling when not.

**On-device validation checklist:** the full runbook, with explicit pass
conditions for all ten checks, lives in
[DEVICE-VALIDATION.md](DEVICE-VALIDATION.md). It has **not been run** as of
v0.2.0.
