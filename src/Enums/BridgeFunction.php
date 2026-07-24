<?php

declare(strict_types=1);

namespace WifiScan\Mobile\Enums;

/**
 * The string identifiers passed to nativephp_call(). These MUST match the
 * `bridge_functions[].name` entries in nativephp.json exactly.
 */
enum BridgeFunction: string
{
    case Scan = 'Wifi.Scan';
    case Current = 'Wifi.Current';
    case CheckPermission = 'Wifi.CheckPermission';
    case RequestPermission = 'Wifi.RequestPermission';
}
