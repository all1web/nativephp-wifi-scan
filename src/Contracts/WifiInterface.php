<?php

declare(strict_types=1);

namespace WifiScan\Mobile\Contracts;

use WifiScan\Mobile\Data\AccessPoint;
use WifiScan\Mobile\Enums\PermissionStatus;

interface WifiInterface
{
    /**
     * Return the last cached list of visible access points and trigger a fresh
     * foreground scan. Fresh results arrive asynchronously via NetworksScanned.
     *
     * @return array<int, AccessPoint>
     */
    public function scan(): array;

    /**
     * Read the currently connected access point, or null when not associated.
     */
    public function current(): ?AccessPoint;

    /**
     * Current status of the permission required to read scan results.
     */
    public function checkPermission(): PermissionStatus;

    /**
     * Permission status plus the required-permission name and whether device
     * location services are enabled (null off-device).
     *
     * @return array{status: PermissionStatus, requiredPermission: ?string, locationServicesEnabled: ?bool}
     */
    public function permissionDetails(): array;

    /**
     * Request the runtime permission required to scan. The definitive result is
     * delivered later via the PermissionGranted / PermissionDenied events.
     */
    public function requestPermission(): PermissionStatus;
}
