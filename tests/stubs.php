<?php

declare(strict_types=1);

/*
 * Off-device stub for the native bridge entry point.
 *
 * On-device, the NativePHP runtime registers a global `nativephp_call`. Here we
 * provide one so the PHP-side decode / filter / mapping logic in WifiScan\Mobile\Wifi
 * can be exercised without an emulator. Responses are driven per-test through
 * WifiScan\Mobile\Tests\BridgeStub.
 */
if (! function_exists('nativephp_call')) {
    function nativephp_call(string $name, string $payload = '{}'): string
    {
        return \WifiScan\Mobile\Tests\BridgeStub::handle($name, $payload);
    }
}
