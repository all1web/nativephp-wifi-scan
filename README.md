# 📡 WiFi Radar

**See the WiFi around you — from PHP.**

![Every access point in range, read straight from Laravel](https://github.com/all1web/plugin-assets/raw/main/wifi-scan/hero.png)

Scan the visible access points, read the one you're connected to, and turn what
the radio can see into a stable fingerprint of *where the phone is*. All of it
from Laravel, with no Kotlin.

```php
$networks = Wifi::scan();   // every AP in range: ssid, bssid, rssi, frequency
$here     = Wifi::current(); // the AP you're actually on
```

![One call from PHP → native radio sweep → ranked network list in your code](https://github.com/all1web/plugin-assets/raw/main/wifi-scan/flow.png)

> **This is not the same as knowing you're "on WiFi."** The first-party
> [`nativephp/mobile-network`](https://nativephp.com/plugins/nativephp/mobile-network)
> plugin tells you whether the connection is wifi or cellular — four booleans
> and a type string. It cannot tell you *which* network, how strong it is, or
> what else is in the air. Android exposes all of that to native apps and it has
> never been reachable from PHP. WiFi Radar is that capability, delivered to
> your Laravel code. It is the only WiFi-scanning plugin in the NativePHP
> ecosystem.

---

## ✨ What you get

- 📶 **The full list of nearby access points** — SSID, BSSID, signal strength
  in dBm, and frequency, strongest first, as tidy PHP objects.
- 📍 **The connected AP**, including its BSSID — the identifier that actually
  stays put when SSIDs collide or get renamed.
- 🧭 **Place fingerprinting built in.** A pure, device-free helper turns a scan
  into an order-independent hash and scores two observations for similarity, so
  your backend can answer *"is this the same place as last time?"* — see below.
- ⚡ **A `NetworksScanned` event** when a fresh scan completes, plus events for
  scan failures and permission outcomes.
- 🔐 **The permission split handled for you** — `NEARBY_WIFI_DEVICES` on
  Android 13+, `ACCESS_FINE_LOCATION` below it, and the location-services check
  that trips up everyone who only asks for the permission.
- 🐘 **Honest async semantics.** Android never returns scan results
  synchronously, so `scan()` hands you the cached list instantly and the fresh
  one arrives by event. No fake blocking call, no silent empty arrays.
- 🧪 **Off-device testable.** The fingerprint math and the whole PHP layer run
  in your test suite with no phone attached.

---

## 📦 Install

After purchasing, connect Composer to the NativePHP plugin marketplace (your
credentials are on your
[Purchased Plugins](https://nativephp.com/dashboard/purchased-plugins)
dashboard), then:

```bash
composer config repositories.nativephp-plugins composer https://plugins.nativephp.com
composer config http-basic.plugins.nativephp.com your-email@example.com your-license-key
composer require all1web/nativephp-wifi-scan
```

Register the plugin (NativePHP plugins are opt-in for security) and rebuild:

```bash
php artisan native:plugin:register all1web/nativephp-wifi-scan
php artisan native:install android --force
php artisan native:run android
```

**The rebuild matters:** the WiFi permissions are merged into your app's
manifest at build time, so a re-run of an old build won't have them.

<details>
<summary>Installing from a private repo or a local checkout instead</summary>

```bash
# Early access via GitHub (licensees with repo access):
composer config repositories.wifi-scan vcs https://github.com/all1web/nativephp-wifi-scan
composer require "all1web/nativephp-wifi-scan:dev-main"

# Local checkout (plugin development):
composer config repositories.wifi-scan path ../nativephp-wifi-scan
composer require "all1web/nativephp-wifi-scan:*@dev"
```

</details>

---

## 🧑‍💻 Use it

```php
use WifiScan\Mobile\Facades\Wifi;
use WifiScan\Mobile\Enums\PermissionStatus;

// Ask once — the plugin picks the right permission for the Android version.
if (Wifi::checkPermission() !== PermissionStatus::Granted) {
    Wifi::requestPermission();
}

foreach (Wifi::scan() as $ap) {
    echo "{$ap->ssid} · {$ap->bssid} · {$ap->rssi} dBm";
}

$here = Wifi::current();   // ?AccessPoint — null when not associated
```

That's the whole integration. `scan()` returns the platform's **last cached**
list immediately so your screen has something to render right now; the fresh
scan lands a moment later as an event:

```php
use Native\Mobile\Attributes\OnNative;
use WifiScan\Mobile\Events\NetworksScanned;

#[OnNative(NetworksScanned::class)]
public function onScan(array $networks, int $count)
{
    $fresh = (new NetworksScanned($networks, $count))->accessPoints();
}
```

Why it works that way — and every event, config key, and failure mode — is in
the **[Reference](docs/REFERENCE.md)**.

### Using JavaScript instead? (Inertia / Vue / React)

The same four calls ship as a JS module — alias it in Vite and use it straight
from your components:

```js
// vite.config.js → resolve.alias:
// '@wifi': '/vendor/all1web/nativephp-wifi-scan/resources/js/wifi.js'
import wifi from '@wifi';

const { networks } = await wifi.scan();          // JSON string of AP rows
await wifi.current();
await wifi.checkPermission();
await wifi.requestPermission();

document.addEventListener('native-event', (e) => {
  if (e.detail.event.endsWith('NetworksScanned')) refreshList();
});
```

---

## 📍 Knowing *where* you are, without GPS

The set of BSSIDs a phone can see is a strong, cheap signature of a place. It
beats GPS indoors, costs no battery, and — unlike SSIDs — the identifiers don't
collide across the thousand networks called `linksys`. WiFi Radar ships that as
a first-class primitive:

```php
use WifiScan\Mobile\Support\BssidFingerprint;

// When the user says "this is the office":
$office = BssidFingerprint::hash(Wifi::scan());   // stable sha256, order-independent

// Later, anywhere:
$score = BssidFingerprint::similarity(Wifi::scan(), $knownOfficeScan);
if ($score >= 0.6) {
    // Same place. Radios come and go, so match on a threshold, not equality.
}
```

Nothing in the plugin talks to a server. The transport, the storage, the
thresholds, and the privacy posture are yours — see
[Reference → Place fingerprinting](docs/REFERENCE.md#place-fingerprinting) for
the full recipe including backend matching, and
[Store review](docs/STORE-REVIEW.md) for what you must declare when you collect
this.

---

## 🤖 Android only — and that's the honest answer

**There is no public iOS API that lists visible WiFi networks.** Not a
restricted one, not a hard one — none. `NEHotspotHelper` requires a special
Apple entitlement granted only for narrow carrier use cases, and even reading
the *connected* SSID needs the Access WiFi Information capability plus a
location grant. Any plugin claiming cross-platform WiFi scanning is claiming
something the platform does not permit.

So this plugin ships no iOS code and declares no iOS support. If Apple ever
opens the connected-network read, a `current()`-only iOS path is a clean
addition — `scan()` never will be. Details:
[docs/PLATFORM-NOTES.md](docs/PLATFORM-NOTES.md).

It is also **foreground only**, by design: Android caps background scans at one
per 30 minutes and usually refuses them outright, so a background mode would be
a footgun rather than a feature. The reasoning is written up in
[docs/DESIGN.md](docs/DESIGN.md).

---

## 📋 Requirements

- NativePHP Mobile `^3.0 || ^4.0`
- Android 6.0+ (minSdk 23)
- Runtime permission: `NEARBY_WIFI_DEVICES` (Android 13+) or
  `ACCESS_FINE_LOCATION` (older), **plus** location services switched on
- No iOS support (see above)

Version-pinning details, including the v4 RC alias:
[Reference → Version compatibility](docs/REFERENCE.md#version-compatibility).

---

## 🔬 Digging deeper

| Doc | What's in it |
|---|---|
| [Reference](docs/REFERENCE.md) | Full API, events, config, fingerprint recipes, per-stack usage |
| [Platform notes](docs/PLATFORM-NOTES.md) | Android scan throttling, OEM quirks, MAC randomization, iOS reality |
| [Store review](docs/STORE-REVIEW.md) | What Google Play review and the Data safety form need from you |
| [Design notes](docs/DESIGN.md) | Architecture, every decision and why |
| [Changelog](CHANGELOG.md) | Version history |

## 🛠️ Development

```bash
composer install
composer test
```

## 🔍 Under the hood

<sub>The fine print — everything below is why the five lines of PHP above just
work.</sub>

<sub>⚙️ **Engineering you don't have to think about:** the scan path registers a
one-shot `BroadcastReceiver` for `SCAN_RESULTS_AVAILABLE_ACTION` and
unregisters itself on delivery, so nothing leaks and nothing accumulates across
scans. `startScan()` throttling (4 calls / 2 min in the foreground) is detected
and reported back as `scanRequested: false` rather than swallowed, so your UI
can tell "no refresh is coming" from "no networks found." SSID reads take the
API-33 `wifiSsid` path with the legacy `SSID` fallback and strip the quoting
Android adds. The activity is held by a `WeakReference` and event dispatch
degrades to a no-op when the app isn't on screen instead of crashing. Results
arrive sorted strongest-first, so a `max_results` cap keeps the nearest radios.</sub>

<sub>🧪 **Verified, not vibes:** a Pest suite pins the bridge contract, the
JSON-string marshalling, hidden-network filtering, the result cap, permission
mapping, and every fingerprint property — set normalization, dedup, sentinel-MAC
rejection, order-independent hashing, and Jaccard similarity — with no device
required. The native layer's on-device behaviour is validated separately; see
[Design notes → What is verified vs. what is not](docs/DESIGN.md#what-is-verified-vs-what-is-not)
for the exact line between the two, stated plainly.</sub>

<sub>🏪 **Review-honest by design:** this plugin asks for a location-class
permission, which means your app *does* have something to declare — and
pretending otherwise is how listings get pulled. [Store
review](docs/STORE-REVIEW.md) tells you exactly what Google Play's Data safety
form expects, what to write in your permission rationale, and why the
fingerprint helper is the privacy-preserving way to ship this (hash on device,
never store raw scans). Your AI pair-programmer gets first-class knowledge too:
Laravel Boost guidelines ship in the box.</sub>

## 📜 License

**Commercial.** Distributed as a paid plugin via the
[NativePHP Plugin Marketplace](https://nativephp.com/plugins); each purchase
grants a license key used for Composer authentication. Licensed by ALL 1, a
Wyoming corporation. Source access is included for your own development;
redistribution of source is not — see [`LICENSE`](LICENSE) for the full EULA.
