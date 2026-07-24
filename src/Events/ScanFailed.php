<?php

declare(strict_types=1);

namespace WifiScan\Mobile\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched from native code when a scan could not be started or completed
 * (throttled by the platform, WiFi disabled, or permission revoked mid-flight).
 */
class ScanFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $reason = 'unknown',
    ) {}
}
