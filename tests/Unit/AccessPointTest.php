<?php

declare(strict_types=1);

use WifiScan\Mobile\Data\AccessPoint;

it('builds from a native row', function () {
    $ap = AccessPoint::fromArray([
        'ssid' => 'HomeNet',
        'bssid' => 'AA:BB:CC:DD:EE:FF',
        'rssi' => -55,
        'frequency' => 2412,
    ]);

    expect($ap->ssid)->toBe('HomeNet')
        ->and($ap->bssid)->toBe('aa:bb:cc:dd:ee:ff') // lowercased
        ->and($ap->rssi)->toBe(-55)
        ->and($ap->frequency)->toBe(2412);
});

it('degrades gracefully on a malformed row', function () {
    $ap = AccessPoint::fromArray([]);

    expect($ap->ssid)->toBe('')
        ->and($ap->bssid)->toBe('')
        ->and($ap->rssi)->toBeNull()
        ->and($ap->frequency)->toBeNull()
        ->and($ap->isHidden())->toBeTrue();
});

it('flags a broadcast SSID as not hidden', function () {
    expect(AccessPoint::fromArray(['ssid' => 'Cafe'])->isHidden())->toBeFalse();
});

it('hydrates a collection preserving order and reindexing', function () {
    $aps = AccessPoint::collection([
        ['ssid' => 'A', 'bssid' => '11:11:11:11:11:11'],
        ['ssid' => 'B', 'bssid' => '22:22:22:22:22:22'],
    ]);

    expect($aps)->toHaveCount(2)
        ->and($aps[0]->ssid)->toBe('A')
        ->and($aps[1]->ssid)->toBe('B');
});

it('round-trips through toArray', function () {
    $row = ['ssid' => 'X', 'bssid' => 'ab:cd:ef:01:23:45', 'rssi' => -40, 'frequency' => 5180];

    expect(AccessPoint::fromArray($row)->toArray())->toBe($row);
});
