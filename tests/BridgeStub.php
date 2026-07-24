<?php

declare(strict_types=1);

namespace WifiScan\Mobile\Tests;

/**
 * Test double for the native bridge. Tests register the JSON response a given
 * `Wifi.*` call should return; handle() replays it and records the call.
 */
final class BridgeStub
{
    /** @var array<string, string> name => JSON response */
    private static array $responses = [];

    /** @var array<int, array{name: string, payload: string}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$responses = [];
        self::$calls = [];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public static function fake(string $name, array $response): void
    {
        self::$responses[$name] = json_encode($response);
    }

    public static function handle(string $name, string $payload): string
    {
        self::$calls[] = ['name' => $name, 'payload' => $payload];

        return self::$responses[$name] ?? '';
    }
}
