<?php

declare(strict_types=1);

namespace WifiScan\Mobile\Support;

/**
 * Thin wrapper around Laravel's config() helper with a graceful fallback for
 * environments (bare unit tests, desktop builds) where it is not bound.
 */
final class Config
{
    public static function get(string $key, mixed $default = null): mixed
    {
        if (function_exists('config')) {
            return config("wifi-scan.{$key}", $default);
        }

        return $default;
    }
}
