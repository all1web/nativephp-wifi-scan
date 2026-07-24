<?php

declare(strict_types=1);

namespace WifiScan\Mobile\Enums;

enum PermissionStatus: string
{
    case Granted = 'granted';
    case Denied = 'denied';
    case Pending = 'pending';
    case Unknown = 'unknown';

    public static function fromNative(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Unknown;
    }

    public function isGranted(): bool
    {
        return $this === self::Granted;
    }
}
