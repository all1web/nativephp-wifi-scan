# FAQ

Questions we can predict. If yours isn't here, check
[TROUBLESHOOTING.md](TROUBLESHOOTING.md) — between them they cover essentially
everything this plugin generates.

## Platform & scope

**Does this work on iOS?**
No, and it never will for `scan()`. There is no public iOS API that enumerates
visible WiFi networks — not a restricted one, none at all. Your iOS build
compiles and every call returns its empty value. [Full explanation](PLATFORM-NOTES.md#8-ios-there-is-no-api).

**Will you add iOS later?**
If Apple ever opens the *connected-network* read further, a `current()`-only iOS
path is a clean addition. `scan()` on iOS is not a roadmap item; it's a platform
impossibility.

**Can I test on an emulator?**
No. Emulators have no WiFi radio. You need a physical Android device to see a
single result. The PHP layer and fingerprint helper *are* fully testable without
a device.

**What's the minimum Android version?**
minSdk 23 (Android 6.0). The permission model differs above and below Android 13
and the plugin handles both.

**Does it work in the background?**
No, by design. Android caps background scans at roughly one per 30 minutes and
often refuses them outright, and event delivery needs a live Activity. A
background mode would be a footgun rather than a feature. [Reasoning](DESIGN.md).

**Does it work with SuperNative / Livewire / Inertia / Blade?**
All of them. Use the PHP facade from Livewire/Blade/SuperNative; a JS module
ships for Inertia and other web-view front-ends.

## Behaviour

**Why does `scan()` return the same list every time?**
Because Android never returns scan results synchronously — `scan()` hands you
the platform's cached list, which is the only thing available immediately. A
fresh scan is requested in the background and arrives via the `NetworksScanned`
event. [Details](TROUBLESHOOTING.md#2-scan-returns-results-but-they-never-change).

**How often can I scan?**
4 times per 2 minutes in the foreground. Past that, `startScan()` silently
refuses and no event fires. Don't poll — scan on user action or screen resume.

**Why do I need location permission for WiFi?**
Because the set of visible access points reveals location, Android treats scan
results as location data. On Android 13+ there's a dedicated
`NEARBY_WIFI_DEVICES` permission; below that it's `ACCESS_FINE_LOCATION`. The
plugin picks the right one automatically.

**I granted the permission and still get nothing.**
Device Location services must *also* be switched on — a separate condition from
the permission on Android 9+. Check
`Wifi::permissionDetails()['locationServicesEnabled']`. This is the single most
common cause of "it doesn't work."

**Does MAC randomization break this?**
No. Android randomizes *your device's* MAC address; a BSSID is the *access
point's* address, and routers don't randomize. [Details](PLATFORM-NOTES.md#3-mac-randomization-does-not-break-fingerprinting).

**Can I connect to a network with this plugin?**
No. This is a read-only visibility plugin: it scans and reports. Joining a
network programmatically is a different, much more restricted Android API and is
out of scope.

**Can I get the WiFi password / see traffic?**
No. Absolutely not, and no Android API allows it. This plugin reads only what
the radio broadcasts publicly: network names, hardware addresses, signal
strength, channel.

**Does it drain the battery?**
Scanning is cheap compared to GPS, and the plugin never scans on its own — it
scans only when you call it, foreground only. The platform's throttle also caps
your worst case.

## Place fingerprinting

**What is the fingerprint actually for?**
Recognising *where* a device is without GPS. The set of BSSIDs a phone can see
is a stable signature of a place — better than GPS indoors and far more stable
than SSIDs, which collide constantly.

**Why doesn't my fingerprint match the same room twice?**
You're probably comparing hashes. One AP appearing or disappearing changes the
hash completely. Use `similarity()` with a threshold (~0.6 to start) and keep
the hash as a fast exact-match index only. [More](TROUBLESHOOTING.md#13-fingerprint-never-matches-the-same-place-twice).

**What threshold should I use?**
Start at `0.6`. Go higher (`0.75`+) in dense apartment blocks where neighbours
share most radios; lower (`0.4`) in sparse areas with only two or three APs.
This is a product decision — tune against real data.

**Does the plugin send my scans anywhere?**
No. Nothing in this package contacts any server. There is no telemetry, no
analytics, no phone-home. The transport, storage, and retention are entirely
yours.

## Store review & privacy

**Will this get my app rejected from Google Play?**
Not by itself — but it adds location-class permissions, so you have real
obligations: a Data safety declaration if you transmit scan-derived data, a
prominent in-app disclosure before requesting the permission, and privacy-policy
coverage. [Full checklist](STORE-REVIEW.md).

**Do I need Play's location permissions declaration form?**
No. That form is triggered by `ACCESS_BACKGROUND_LOCATION`, which this plugin
does not request.

**What do I declare on the Data safety form?**
If scans (or BSSID sets, or anything derived) leave the device, declare Location
collection. If everything stays on-device — you hash locally and store only the
digest locally — there may be nothing to declare. That determination is yours to
make for your app.

**Can I avoid the location permissions entirely?**
Only if your use case is genuinely non-locational and you target Android 13+
exclusively, using `neverForLocation` in your own manifest. The plugin doesn't
set that flag because place fingerprinting is a headline feature and the flag
would be a false assertion. [Details](STORE-REVIEW.md#the-neverforlocation-question).

## Project

**Is it really free?**
Yes — MIT licensed, free for commercial use, no attribution required in your
app, no license key, no seat limits.

**Will it stay free?**
The MIT licence on every released version is irrevocable. Nothing published can
be un-freed.

**Who maintains it?**
[ALL 1](https://github.com/all1web). It's maintained because we use it, and
support is best-effort — please run `php artisan wifi-scan:doctor` and read
[TROUBLESHOOTING.md](TROUBLESHOOTING.md) before opening an issue.

**Can I contribute?**
Yes. Run `composer test` before opening a PR. Native changes need on-device
verification described in the PR — we can't validate Kotlin from a test suite.

**How do I report a security issue?**
See [SECURITY.md](../SECURITY.md) — please don't open a public issue for it.
