<?php

declare(strict_types=1);

use WifiScan\Mobile\Data\AccessPoint;
use WifiScan\Mobile\Enums\PermissionStatus;
use WifiScan\Mobile\Tests\BridgeStub;
use WifiScan\Mobile\Wifi;

beforeEach(function () {
    BridgeStub::reset();
});

it('decodes a scan payload delivered as a json string', function () {
    BridgeStub::fake('Wifi.Scan', [
        'networks' => json_encode([
            ['ssid' => 'A', 'bssid' => 'aa:aa:aa:aa:aa:aa', 'rssi' => -40, 'frequency' => 2412],
            ['ssid' => 'B', 'bssid' => 'bb:bb:bb:bb:bb:bb', 'rssi' => -70, 'frequency' => 5180],
        ]),
        'count' => 2,
    ]);

    $networks = (new Wifi)->scan();

    expect($networks)->toHaveCount(2)
        ->and($networks[0])->toBeInstanceOf(AccessPoint::class)
        ->and($networks[0]->ssid)->toBe('A')
        ->and($networks[1]->bssid)->toBe('bb:bb:bb:bb:bb:bb');
});

it('filters hidden networks by default', function () {
    BridgeStub::fake('Wifi.Scan', [
        'networks' => json_encode([
            ['ssid' => 'Visible', 'bssid' => 'aa:aa:aa:aa:aa:aa'],
            ['ssid' => '', 'bssid' => 'bb:bb:bb:bb:bb:bb'],
        ]),
    ]);

    $networks = (new Wifi)->scan();

    expect($networks)->toHaveCount(1)
        ->and($networks[0]->ssid)->toBe('Visible');
});

it('keeps hidden networks when configured', function () {
    config()->set('wifi-scan.include_hidden', true);

    BridgeStub::fake('Wifi.Scan', [
        'networks' => json_encode([
            ['ssid' => 'Visible', 'bssid' => 'aa:aa:aa:aa:aa:aa'],
            ['ssid' => '', 'bssid' => 'bb:bb:bb:bb:bb:bb'],
        ]),
    ]);

    expect((new Wifi)->scan())->toHaveCount(2);
});

it('caps results when max_results is set', function () {
    config()->set('wifi-scan.max_results', 1);

    BridgeStub::fake('Wifi.Scan', [
        'networks' => json_encode([
            ['ssid' => 'A', 'bssid' => 'aa:aa:aa:aa:aa:aa'],
            ['ssid' => 'B', 'bssid' => 'bb:bb:bb:bb:bb:bb'],
        ]),
    ]);

    expect((new Wifi)->scan())->toHaveCount(1);
});

it('returns the connected access point', function () {
    BridgeStub::fake('Wifi.Current', [
        'connected' => true,
        'ssid' => 'HomeNet',
        'bssid' => 'AA:BB:CC:DD:EE:FF',
        'rssi' => -48,
        'frequency' => 2412,
    ]);

    $ap = (new Wifi)->current();

    expect($ap)->toBeInstanceOf(AccessPoint::class)
        ->and($ap->ssid)->toBe('HomeNet')
        ->and($ap->bssid)->toBe('aa:bb:cc:dd:ee:ff')
        ->and($ap->rssi)->toBe(-48);
});

it('returns null when not associated', function () {
    BridgeStub::fake('Wifi.Current', ['connected' => false]);

    expect((new Wifi)->current())->toBeNull();
});

it('maps permission status', function () {
    BridgeStub::fake('Wifi.CheckPermission', ['status' => 'granted']);
    expect((new Wifi)->checkPermission())->toBe(PermissionStatus::Granted);

    BridgeStub::fake('Wifi.CheckPermission', ['status' => 'denied']);
    expect((new Wifi)->checkPermission())->toBe(PermissionStatus::Denied);

    BridgeStub::fake('Wifi.CheckPermission', ['status' => 'garbage']);
    expect((new Wifi)->checkPermission())->toBe(PermissionStatus::Unknown);
});

it('reports granted immediately or pending from requestPermission', function () {
    BridgeStub::fake('Wifi.RequestPermission', ['granted' => true]);
    expect((new Wifi)->requestPermission())->toBe(PermissionStatus::Granted);

    BridgeStub::fake('Wifi.RequestPermission', ['granted' => false, 'status' => 'pending']);
    expect((new Wifi)->requestPermission())->toBe(PermissionStatus::Pending);
});

it('sends the correct bridge function names', function () {
    BridgeStub::fake('Wifi.Scan', ['networks' => '[]']);
    (new Wifi)->scan();

    expect(BridgeStub::$calls[0]['name'])->toBe('Wifi.Scan');
});
