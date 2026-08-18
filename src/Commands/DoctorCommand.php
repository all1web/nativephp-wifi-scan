<?php

declare(strict_types=1);

namespace WifiScan\Mobile\Commands;

use Illuminate\Console\Command;
use WifiScan\Mobile\Enums\PermissionStatus;
use WifiScan\Mobile\Facades\Wifi;

/**
 * `php artisan wifi-scan:doctor` — one-command setup diagnosis.
 *
 * Walks the full chain a scan travels (registration → native build →
 * runtime bridge → permission → location services → config) and reports
 * every broken link with the exact command or action that fixes it.
 */
class DoctorCommand extends Command
{
    protected $signature = 'wifi-scan:doctor';

    protected $description = 'Diagnose the WiFi Radar plugin setup (registration, native build, bridge, permission, config)';

    public function handle(): int
    {
        $this->info('WiFi Radar — setup diagnosis');
        $this->newLine();

        $problems = 0;

        // 1. Registration in the NativePHP plugin allowlist.
        $provider = base_path('app/Providers/NativeServiceProvider.php');
        if (! file_exists($provider)) {
            $this->warnLine('Plugin allowlist', 'app/Providers/NativeServiceProvider.php not found', 'php artisan vendor:publish --tag=nativephp-plugins-provider');
            $problems++;
        } elseif (! str_contains((string) file_get_contents($provider), 'WifiScanServiceProvider')) {
            $this->warnLine('Plugin allowlist', 'WifiScanServiceProvider is not registered — the plugin will be SKIPPED at build', 'php artisan native:plugin:register all1web/nativephp-wifi-scan');
            $problems++;
        } else {
            $this->passLine('Plugin allowlist', 'registered');
        }

        // 2. Generated Android project carries the WiFi permissions.
        $androidManifest = base_path('nativephp/android/app/src/main/AndroidManifest.xml');
        if (! file_exists($androidManifest)) {
            $this->warnLine('Android build', 'no generated Android project yet', 'php artisan native:install android --force');
            $problems++;
        } else {
            $manifest = (string) file_get_contents($androidManifest);
            $missing = array_values(array_filter([
                'android.permission.ACCESS_WIFI_STATE',
                'android.permission.CHANGE_WIFI_STATE',
                'android.permission.ACCESS_FINE_LOCATION',
                'android.permission.NEARBY_WIFI_DEVICES',
            ], static fn (string $p): bool => ! str_contains($manifest, $p)));

            if ($missing !== []) {
                $this->warnLine('Android build', 'generated manifest is missing: '.implode(', ', $missing).' — scans will return nothing', 'php artisan native:install android --force (then rebuild/run)');
                $problems++;
            } else {
                $this->passLine('Android build', 'all WiFi permissions present in the generated manifest');
            }
        }

        // 3. Runtime bridge + the two-switch permission model.
        if (function_exists('nativephp_call')) {
            $details = Wifi::permissionDetails();

            if ($details['status'] === PermissionStatus::Granted) {
                $this->passLine('Scan permission', 'granted ('.($details['requiredPermission'] ?? 'n/a').')');
            } elseif ($details['status'] === PermissionStatus::Unknown && $details['requiredPermission'] === null) {
                // The bridge function exists but returned nothing — host apps
                // define nativephp_call() even off-device (Jump/desktop), where
                // it no-ops. Not a failure; the real answer needs a phone.
                $this->line('  <fg=gray>○ Scan permission</>    cannot be determined here (off-device / simulated bridge) — check on a real device');
            } else {
                $this->warnLine('Scan permission', 'not granted — scan() returns an empty list without it', 'call Wifi::requestPermission() from a screen that explains why (see docs/STORE-REVIEW.md on prominent disclosure)');
                $problems++;
            }

            if ($details['locationServicesEnabled'] === false) {
                $this->warnLine('Location services', 'OFF — Android also gates scan results behind the device location toggle (separate from the permission)', 'ask the user to enable Location in system settings; check Wifi::permissionDetails()[\'locationServicesEnabled\'] before scanning');
                $problems++;
            } elseif ($details['locationServicesEnabled'] === true) {
                $this->passLine('Location services', 'enabled');
            }

            $networks = Wifi::scan();
            $this->passLine('Native bridge', 'available — cached scan returned '.count($networks).' network(s)');
            if (count($networks) === 0) {
                $this->line('  <fg=gray>○ Note</>               an empty list on a real phone usually means permission/location above; on an EMULATOR it is expected (no WiFi radio).');
            }
        } else {
            $this->line('  <fg=gray>○ Native bridge</>      not available here (normal off-device: browser dev, tests, CI)');
            $this->line('  <fg=gray>○ Reminder</>           emulators have no WiFi radio — real scans need a physical Android device.');
        }

        // 4. Config.
        $config = config('wifi-scan');
        if ($config === null) {
            $this->warnLine('Config', 'wifi-scan config not loaded', 'composer dump-autoload, then re-run');
            $problems++;
        } else {
            $hidden = ($config['include_hidden'] ?? false) ? 'included' : 'filtered out';
            $max = (int) ($config['max_results'] ?? 0);
            $this->passLine('Config', "hidden networks {$hidden}, max_results ".($max > 0 ? $max : 'uncapped'));
        }

        $this->newLine();
        $problems === 0
            ? $this->info('All checks passed. Scan away!')
            : $this->warn("{$problems} issue(s) found — fixes listed above.");

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function passLine(string $check, string $detail): void
    {
        $this->line(sprintf('  <fg=green>✓ %s</>%s%s', $check, str_repeat(' ', max(1, 20 - strlen($check))), $detail));
    }

    protected function warnLine(string $check, string $problem, string $fix): void
    {
        $this->line(sprintf('  <fg=red>✗ %s</>%s%s', $check, str_repeat(' ', max(1, 20 - strlen($check))), $problem));
        $this->line(sprintf('    <fg=yellow>fix:</> %s', $fix));
    }
}
