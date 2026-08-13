<?php

declare(strict_types=1);

namespace WifiScan\Mobile\Facades;

use Illuminate\Support\Facades\Facade;
use WifiScan\Mobile\Contracts\WifiInterface;

/**
 * @method static array<int, \WifiScan\Mobile\Data\AccessPoint> scan()
 * @method static \WifiScan\Mobile\Data\AccessPoint|null current()
 * @method static \WifiScan\Mobile\Enums\PermissionStatus checkPermission()
 * @method static array{status: \WifiScan\Mobile\Enums\PermissionStatus, requiredPermission: ?string, locationServicesEnabled: ?bool} permissionDetails()
 * @method static \WifiScan\Mobile\Enums\PermissionStatus requestPermission()
 *
 * @see \WifiScan\Mobile\Wifi
 */
class Wifi extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WifiInterface::class;
    }
}
