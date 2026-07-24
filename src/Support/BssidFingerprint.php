<?php

declare(strict_types=1);

namespace WifiScan\Mobile\Support;

use WifiScan\Mobile\Data\AccessPoint;

/**
 * Turns a set of visible BSSIDs into a stable, order-independent fingerprint.
 *
 * A physical location is characterised far more reliably by the *set* of MAC
 * addresses (BSSIDs) it can see than by SSIDs (which collide and change). This
 * helper normalises and hashes that set so a backend can match "am I in the
 * same place as last time?" without storing raw scans. It is deliberately pure
 * and off-device testable — no native calls.
 */
final class BssidFingerprint
{
    /**
     * Normalise a single BSSID: lowercase, strip separators, keep hex only.
     */
    public static function normalize(string $bssid): string
    {
        return preg_replace('/[^0-9a-f]/', '', strtolower($bssid)) ?? '';
    }

    /**
     * Reduce a list of AccessPoints (or raw BSSID strings) to a sorted, unique,
     * lowercase list of normalised BSSIDs. Blank/all-zero MACs are dropped.
     *
     * @param  array<int, AccessPoint|string>  $points
     * @return array<int, string>
     */
    public static function set(array $points): array
    {
        $macs = [];

        foreach ($points as $point) {
            $raw = $point instanceof AccessPoint ? $point->bssid : (string) $point;
            $normal = self::normalize($raw);

            if ($normal === '' || $normal === '000000000000' || $normal === '020000000000') {
                continue;
            }

            $macs[$normal] = true;
        }

        // array_keys() coerces all-digit hex ("111111111111") to int keys, so
        // cast back to string to keep the set uniformly typed for JSON / matching.
        $macs = array_map(strval(...), array_keys($macs));
        sort($macs);

        return $macs;
    }

    /**
     * A stable SHA-256 hash of the visible BSSID set. Identical anywhere the
     * same radios are visible, regardless of scan order or signal strength.
     *
     * @param  array<int, AccessPoint|string>  $points
     */
    public static function hash(array $points): string
    {
        return hash('sha256', implode(',', self::set($points)));
    }

    /**
     * Jaccard similarity (0.0–1.0) between two BSSID sets — a cheap "how close
     * are these two observations?" score a backend can threshold on.
     *
     * @param  array<int, AccessPoint|string>  $a
     * @param  array<int, AccessPoint|string>  $b
     */
    public static function similarity(array $a, array $b): float
    {
        $setA = self::set($a);
        $setB = self::set($b);

        if ($setA === [] && $setB === []) {
            return 1.0;
        }

        $intersection = count(array_intersect($setA, $setB));
        $union = count(array_unique(array_merge($setA, $setB)));

        return $union === 0 ? 0.0 : $intersection / $union;
    }
}
