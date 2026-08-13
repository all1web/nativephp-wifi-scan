# Store review, permissions & data safety

Unlike most plugins, this one is **not invisible to review**. It adds
location-class permissions to your app, and Google Play has policy attached to
those. This page tells you exactly what changes, what you must declare, and
what you do *not* have to do — so nothing is a surprise at submission.

> Informational, not legal advice. Your app's disclosures are your
> responsibility (see §5 of the [LICENSE](../LICENSE)).

## What the plugin adds to your manifest

| Permission | Why | Dangerous? |
|---|---|---|
| `ACCESS_WIFI_STATE` | read scan results and connection info | no |
| `CHANGE_WIFI_STATE` | required to call `startScan()` | no |
| `ACCESS_FINE_LOCATION` | gates scan results on Android ≤ 12 | **yes** |
| `ACCESS_COARSE_LOCATION` | companion to fine location | **yes** |
| `NEARBY_WIFI_DEVICES` | the dedicated scan permission on Android 13+ | **yes** |

Plus one optional hardware feature (`android.hardware.wifi`, `required=false`)
so devices without a WiFi radio can still install your app.

**What is *not* there, and it matters:** no `ACCESS_BACKGROUND_LOCATION`. That
is the permission that triggers Google Play's **Location permissions
declaration form** and the review process attached to it. This plugin is
foreground-only by design, so you skip that entirely.

## Google Play checklist

**1. Data safety form.** BSSIDs can be used to infer a user's location, so if
your app **transmits** scans, BSSID sets, or anything derived from them off the
device, disclose it under *Location → Approximate location* (and *Precise
location* if you pair it with GPS). Declare purpose, whether it's optional, and
whether it's encrypted in transit.

If everything stays on the device — you hash locally and only ever store the
digest locally — there may be nothing to declare as collection. Make that
determination for your app; the plugin itself transmits nothing and contacts no
endpoint we control.

**2. Prominent disclosure and consent.** Play policy requires an in-app
disclosure *before* you request a location-class permission, explaining what you
collect and why, separate from your privacy policy. A one-screen explanation
before you call `Wifi::requestPermission()` satisfies this and dramatically
improves grant rates. Don't request on first launch with no context.

**3. Permission rationale that matches reality.** Whatever you tell the user
must be what the app does. "To recognise the places you visit, this app reads
which WiFi networks are nearby. It never connects to them and never sees your
passwords" is accurate for this plugin, and true.

**4. Privacy policy.** Location-class permissions mean your policy must cover
the collection, its purpose, retention, and any sharing.

## Minimising your disclosure surface

Three things genuinely reduce what you have to declare:

- **Hash on the device.** `BssidFingerprint::hash()` produces an opaque digest.
  Send that instead of raw BSSIDs when you only need to *recognise* a place
  rather than analyse one.
- **Store sets, not scans.** You never need SSIDs, signal strengths, or
  timestamps for place matching. Drop them before they leave the device.
- **Ask at the moment of use.** Requesting the permission on the screen where
  the feature lives is both better UX and a cleaner story for review.

## The `neverForLocation` question

Android 13's `NEARBY_WIFI_DEVICES` can be declared with
`android:usesPermissionFlags="neverForLocation"`, which asserts you don't derive
location from WiFi and lets you drop the location permissions on API 33+.

**This plugin does not set that flag**, because place fingerprinting is a
headline feature and the flag would be a false assertion — and because the
Android ≤ 12 fallback needs `ACCESS_FINE_LOCATION` regardless.

If your app uses WiFi scanning for something genuinely non-locational (device
provisioning, network diagnostics, a signal-strength meter) and you target only
Android 13+, adding the flag in your own manifest is a legitimate way to shrink
your permission footprint. That's a licensee-level decision about your app's
actual behaviour, not something a plugin should decide for you.

## Apple App Store

Nothing. The plugin ships no Swift, adds no `NSxxxUsageDescription` keys, no
capabilities, no entitlements, and compiles into no iOS target. An iOS build of
your app is byte-identical with or without it. See
[PLATFORM-NOTES.md §8](PLATFORM-NOTES.md#8-ios-there-is-no-api) for why iOS
support doesn't exist at all.

## Bifrost / cloud builds

Cloud-build-safe by construction:

- Plain manifest merge plus Kotlin sources — identical locally and in CI.
- No Gradle dependencies added (`dependencies.implementation` is empty).
- No build-time secrets, no env vars, no signing changes, no extra targets.

If your pipeline builds the app, it builds this plugin. There is no
plugin-specific build step.

## Config experience

Zero-config by default: install → register → rebuild → ask for the permission →
scan. `vendor:publish --tag=wifi-scan-config` is optional and only needed to
change filtering behaviour.
