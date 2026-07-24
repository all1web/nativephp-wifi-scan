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
        if (! function_exists('config')) {
            return $default;
        }

        // config() can exist while no container/config repository is bound (bare
        // unit tests, desktop builds, queue workers). It throws in that case, so
        // fall back to the literal default rather than letting it surface.
        try {
            return config("wifi-scan.{$key}", $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
