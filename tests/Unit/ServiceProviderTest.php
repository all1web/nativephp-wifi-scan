<?php

declare(strict_types=1);

use WifiScan\Mobile\Contracts\WifiInterface;
use WifiScan\Mobile\Facades\Wifi as WifiFacade;
use WifiScan\Mobile\Tests\BridgeStub;
use WifiScan\Mobile\Wifi;

it('binds the interface as a singleton', function () {
    expect(app(WifiInterface::class))->toBeInstanceOf(Wifi::class)
        ->and(app(WifiInterface::class))->toBe(app(WifiInterface::class));
});

it('aliases the concrete and the short name', function () {
    expect(app(Wifi::class))->toBeInstanceOf(Wifi::class)
        ->and(app('wifi'))->toBeInstanceOf(Wifi::class);
});

it('merges the default config', function () {
    expect(config('wifi-scan.include_hidden'))->toBeFalse()
        ->and(config('wifi-scan.max_results'))->toBe(0);
});

it('resolves through the facade', function () {
    BridgeStub::reset();
    BridgeStub::fake('Wifi.Current', ['connected' => false]);

    expect(WifiFacade::current())->toBeNull();
});
