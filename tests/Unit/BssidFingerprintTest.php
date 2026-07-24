<?php

declare(strict_types=1);

use WifiScan\Mobile\Data\AccessPoint;
use WifiScan\Mobile\Support\BssidFingerprint;

it('normalizes a bssid to bare lowercase hex', function () {
    expect(BssidFingerprint::normalize('AA:BB:CC:11:22:33'))->toBe('aabbcc112233')
        ->and(BssidFingerprint::normalize('aa-bb-cc-11-22-33'))->toBe('aabbcc112233');
});

it('builds a sorted unique set dropping placeholder macs', function () {
    $set = BssidFingerprint::set([
        '22:22:22:22:22:22',
        '11:11:11:11:11:11',
        '22:22:22:22:22:22', // dup
        '00:00:00:00:00:00', // placeholder
        '02:00:00:00:00:00', // placeholder
        '',                  // empty
    ]);

    expect($set)->toBe(['111111111111', '222222222222']);
});

it('produces an order-independent hash', function () {
    $a = BssidFingerprint::hash(['aa:aa:aa:aa:aa:aa', 'bb:bb:bb:bb:bb:bb']);
    $b = BssidFingerprint::hash(['bb:bb:bb:bb:bb:bb', 'aa:aa:aa:aa:aa:aa']);

    expect($a)->toBe($b)
        ->and($a)->toHaveLength(64); // sha256 hex
});

it('produces a different hash for a different set', function () {
    $a = BssidFingerprint::hash(['aa:aa:aa:aa:aa:aa']);
    $b = BssidFingerprint::hash(['aa:aa:aa:aa:aa:aa', 'bb:bb:bb:bb:bb:bb']);

    expect($a)->not->toBe($b);
});

it('accepts AccessPoint objects', function () {
    $points = [
        new AccessPoint('A', 'aa:aa:aa:aa:aa:aa', -50),
        new AccessPoint('B', 'bb:bb:bb:bb:bb:bb', -60),
    ];

    expect(BssidFingerprint::set($points))->toBe(['aaaaaaaaaaaa', 'bbbbbbbbbbbb']);
});

it('scores jaccard similarity', function () {
    $a = ['aa:aa:aa:aa:aa:aa', 'bb:bb:bb:bb:bb:bb', 'cc:cc:cc:cc:cc:cc'];
    $b = ['bb:bb:bb:bb:bb:bb', 'cc:cc:cc:cc:cc:cc', 'dd:dd:dd:dd:dd:dd'];

    // intersection {bb, cc} = 2, union {aa, bb, cc, dd} = 4
    expect(BssidFingerprint::similarity($a, $b))->toBe(0.5)
        ->and(BssidFingerprint::similarity($a, $a))->toBe(1.0)
        ->and(BssidFingerprint::similarity([], []))->toBe(1.0)
        ->and(BssidFingerprint::similarity($a, []))->toBe(0.0);
});
