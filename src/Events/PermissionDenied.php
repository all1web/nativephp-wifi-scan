<?php

declare(strict_types=1);

namespace WifiScan\Mobile\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched from native code when the user denies the scan permission.
 */
class PermissionDenied
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ?string $permission = null,
    ) {}
}
