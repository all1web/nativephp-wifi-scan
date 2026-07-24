<?php

declare(strict_types=1);

namespace WifiScan\Mobile;

use WifiScan\Mobile\Contracts\WifiInterface;
use WifiScan\Mobile\Data\AccessPoint;
use WifiScan\Mobile\Enums\BridgeFunction;
use WifiScan\Mobile\Enums\PermissionStatus;
use WifiScan\Mobile\Support\Config;

class Wifi implements WifiInterface
{
    /**
     * Return the last cached list of visible access points and trigger a fresh
     * foreground scan. Fresh results arrive asynchronously via NetworksScanned.
     *
     * The synchronous return is intentionally the *cached* scan: Android
     * throttles startScan() and never returns results synchronously, so the
     * honest contract is "here is the last known list, a fresh one is coming".
     *
     * @return array<int, AccessPoint>
     */
    public function scan(): array
    {
        $result = $this->call(BridgeFunction::Scan);

        $networks = $this->decodeNetworks($result['networks'] ?? []);

        if (! (bool) Config::get('include_hidden', false)) {
            $networks = array_values(array_filter(
                $networks,
                static fn (AccessPoint $ap): bool => ! $ap->isHidden(),
            ));
        }

        $max = (int) Config::get('max_results', 0);
        if ($max > 0 && count($networks) > $max) {
            $networks = array_slice($networks, 0, $max);
        }

        return $networks;
    }

    /**
     * Read the currently connected access point, or null when not associated.
     */
    public function current(): ?AccessPoint
    {
        $result = $this->call(BridgeFunction::Current);

        if (($result['connected'] ?? false) !== true) {
            return null;
        }

        return AccessPoint::fromArray([
            'ssid' => $result['ssid'] ?? '',
            'bssid' => $result['bssid'] ?? '',
            'rssi' => $result['rssi'] ?? null,
            'frequency' => $result['frequency'] ?? null,
        ]);
    }

    /**
     * Current status of the permission required to read scan results.
     */
    public function checkPermission(): PermissionStatus
    {
        $result = $this->call(BridgeFunction::CheckPermission);

        return PermissionStatus::fromNative($result['status'] ?? null);
    }

    /**
     * Request the runtime permission required to scan. The definitive result is
     * delivered later via the PermissionGranted / PermissionDenied events; this
     * return value is the immediate status (granted if already held, else
     * pending while the system dialog is shown).
     */
    public function requestPermission(): PermissionStatus
    {
        $result = $this->call(BridgeFunction::RequestPermission);

        if (($result['granted'] ?? false) === true) {
            return PermissionStatus::Granted;
        }

        return PermissionStatus::fromNative($result['status'] ?? 'pending');
    }

    /**
     * Decode the `networks` field which the native layer returns as a JSON
     * string (to survive the Map<String,Any> bridge) or, defensively, an array.
     *
     * @param  string|array<int, array<string, mixed>>  $networks
     * @return array<int, AccessPoint>
     */
    protected function decodeNetworks(string|array $networks): array
    {
        if (is_string($networks)) {
            $networks = json_decode($networks, true) ?: [];
        }

        return AccessPoint::collection($networks);
    }

    /**
     * Make a bridge call to the native layer. No-ops (returns []) on desktop /
     * web / bare PHP where nativephp_call is not registered.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function call(BridgeFunction $function, array $data = []): array
    {
        if (! function_exists('nativephp_call')) {
            return [];
        }

        $result = nativephp_call($function->value, json_encode($data));

        if (! $result) {
            return [];
        }

        return json_decode($result, true) ?? [];
    }
}
