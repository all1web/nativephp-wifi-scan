<?php

declare(strict_types=1);

use WifiScan\Mobile\Data\AccessPoint;
use WifiScan\Mobile\Events\NetworksScanned;
use WifiScan\Mobile\Events\PermissionDenied;
use WifiScan\Mobile\Events\PermissionGranted;
use WifiScan\Mobile\Events\ScanFailed;

it('hydrates access points from a NetworksScanned payload', function () {
    $event = new NetworksScanned([
        ['ssid' => 'A', 'bssid' => 'AA:BB:CC:DD:EE:FF', 'rssi' => -50, 'frequency' => 2412],
    ], 1);

    $points = $event->accessPoints();

    expect($points)->toHaveCount(1)
        ->and($points[0])->toBeInstanceOf(AccessPoint::class)
        ->and($points[0]->bssid)->toBe('aa:bb:cc:dd:ee:ff');
});

it('constructs every event with the exact parameter names the native payload uses', function () {
    // The runtime binds constructor parameters BY NAME from the JSON payload
    // (NativeComponent::makeEventInstance). A renamed parameter silently
    // breaks hydration on-device, so the names are pinned here.
    $names = fn (string $class): array => array_map(
        fn (\ReflectionParameter $p): string => $p->getName(),
        (new ReflectionClass($class))->getConstructor()->getParameters(),
    );

    expect($names(NetworksScanned::class))->toBe(['networks', 'count'])
        ->and($names(ScanFailed::class))->toBe(['reason'])
        ->and($names(PermissionGranted::class))->toBe(['permission'])
        ->and($names(PermissionDenied::class))->toBe(['permission']);
});

it('defaults ScanFailed to an unknown reason', function () {
    expect((new ScanFailed)->reason)->toBe('unknown');
});

it('documents every reason the native layer dispatches', function () {
    // The Kotlin dispatches these exact strings; the docs table must cover them.
    $kotlin = file_get_contents(dirname(__DIR__, 2).'/resources/android/src/WifiFunctions.kt');
    preg_match_all('/put\("reason",\s*"([a-z_]+)"\)/', $kotlin, $m);

    $reference = file_get_contents(dirname(__DIR__, 2).'/docs/REFERENCE.md');

    expect($m[1])->not->toBeEmpty();
    foreach (array_unique($m[1]) as $reason) {
        expect($reference)->toContain("`{$reason}`");
    }
});
