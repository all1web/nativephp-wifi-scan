<?php

declare(strict_types=1);

/*
 * Pins the PHP layer to the bridge contract verified against the installed
 * NativePHP runtime (BridgeRouter.kt): success() returns the payload BARE,
 * error() wraps as {status, code, message, data: {}}. Every getter must
 * degrade to its empty value on an error envelope — no exceptions, no
 * garbage values leaking into app code.
 */

use WifiScan\Mobile\Enums\PermissionStatus;
use WifiScan\Mobile\Tests\BridgeStub;
use WifiScan\Mobile\Wifi;

beforeEach(function () {
    BridgeStub::reset();
});

$errorEnvelope = [
    'status' => 'error',
    'code' => 'PERMISSION_DENIED',
    'message' => 'Missing scan permission',
    'data' => [],
];

it('collapses an error envelope to an empty scan', function () use ($errorEnvelope) {
    BridgeStub::fake('Wifi.Scan', $errorEnvelope);

    expect((new Wifi)->scan())->toBe([]);
});

it('collapses an error envelope to a null current AP', function () use ($errorEnvelope) {
    BridgeStub::fake('Wifi.Current', $errorEnvelope);

    expect((new Wifi)->current())->toBeNull();
});

it('collapses an error envelope to Unknown permission — never the literal "error" string', function () use ($errorEnvelope) {
    // The error envelope carries status:"error"; the unwrap must prevent
    // PermissionStatus::fromNative from ever seeing it.
    BridgeStub::fake('Wifi.CheckPermission', $errorEnvelope);

    expect((new Wifi)->checkPermission())->toBe(PermissionStatus::Unknown);
});

it('collapses an error envelope to explicit unknowns in permissionDetails', function () use ($errorEnvelope) {
    BridgeStub::fake('Wifi.CheckPermission', $errorEnvelope);

    $details = (new Wifi)->permissionDetails();

    expect($details['status'])->toBe(PermissionStatus::Unknown)
        ->and($details['requiredPermission'])->toBeNull()
        ->and($details['locationServicesEnabled'])->toBeNull();
});

it('surfaces full permission details from a success payload', function () {
    BridgeStub::fake('Wifi.CheckPermission', [
        'status' => 'granted',
        'requiredPermission' => 'android.permission.NEARBY_WIFI_DEVICES',
        'locationServicesEnabled' => true,
    ]);

    $details = (new Wifi)->permissionDetails();

    expect($details['status'])->toBe(PermissionStatus::Granted)
        ->and($details['requiredPermission'])->toBe('android.permission.NEARBY_WIFI_DEVICES')
        ->and($details['locationServicesEnabled'])->toBeTrue();
});

it('survives malformed networks JSON without throwing', function () {
    BridgeStub::fake('Wifi.Scan', ['networks' => '{not json[']);

    expect((new Wifi)->scan())->toBe([]);
});

it('accepts networks as an already-decoded array', function () {
    BridgeStub::fake('Wifi.Scan', [
        'networks' => [['ssid' => 'A', 'bssid' => 'aa:aa:aa:aa:aa:aa']],
    ]);

    expect((new Wifi)->scan())->toHaveCount(1);
});

it('never unwraps a payload that legitimately lacks the data key', function () {
    // Regression guard for the unwrap rule: success payloads are bare and none
    // of ours contains a "data" key, so a bare payload must pass through intact.
    BridgeStub::fake('Wifi.Current', ['connected' => true, 'ssid' => 'X', 'bssid' => 'aa:aa:aa:aa:aa:aa']);

    expect((new Wifi)->current()?->ssid)->toBe('X');
});
