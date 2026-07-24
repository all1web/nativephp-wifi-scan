<?php

declare(strict_types=1);

namespace WifiScan\Mobile\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use WifiScan\Mobile\Data\AccessPoint;

/**
 * Dispatched from native code when a fresh foreground scan completes.
 *
 * The native layer delivers `networks` as an array of raw rows; use
 * accessPoints() to hydrate them into value objects.
 */
class NetworksScanned
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $networks
     */
    public function __construct(
        public readonly array $networks = [],
        public readonly int $count = 0,
    ) {}

    /**
     * @return array<int, AccessPoint>
     */
    public function accessPoints(): array
    {
        return AccessPoint::collection($this->networks);
    }
}
