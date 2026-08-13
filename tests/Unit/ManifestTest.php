<?php

declare(strict_types=1);

/*
 * Structural guarantees the NativePHP compiler and the marketplace rely on.
 * A malformed manifest makes the whole plugin silently vanish at build time,
 * and a bridge FQN that drifts from the Kotlin is only discoverable on a
 * device — so these contracts are pinned here instead.
 */

use WifiScan\Mobile\Enums\BridgeFunction;

beforeEach(function () {
    $this->root = dirname(__DIR__, 2);
    $this->manifest = json_decode(file_get_contents($this->root.'/nativephp.json'), true);
    $this->composer = json_decode(file_get_contents($this->root.'/composer.json'), true);
});

it('has a syntactically valid manifest and composer file', function () {
    expect($this->manifest)->toBeArray()
        ->and($this->composer)->toBeArray();
});

it('declares a valid namespace identifier', function () {
    expect($this->manifest['namespace'])->toBe('Wifi')
        ->and($this->manifest['namespace'])->toMatch('/^[A-Za-z_][A-Za-z0-9_]*$/');
});

it('is published under the vendor identity', function () {
    expect($this->composer['name'])->toBe('all1web/nativephp-wifi-scan')
        ->and($this->composer['type'])->toBe('nativephp-plugin')
        ->and($this->composer['license'])->toBe('MIT');
});

it('carries the marketplace support block', function () {
    expect($this->composer['support']['email'])->not->toBeEmpty()
        ->and($this->composer['support']['issues'])->toStartWith('https://github.com/all1web/');
});

it('ships the MIT licence naming the owner', function () {
    $license = file_get_contents($this->root.'/LICENSE');

    expect($license)->toContain('MIT License')
        ->and($license)->toContain('ALL 1');
});

it('constrains nativephp/mobile with the alias-safe range', function () {
    expect($this->composer['require']['nativephp/mobile'])->toBe('^3.0 || ^4.0');
});

it('declares exactly one provider that is the allowlist identity', function () {
    $providers = $this->composer['extra']['laravel']['providers'];

    expect($providers)->toHaveCount(1)
        ->and($providers[0])->toBe('WifiScan\\Mobile\\WifiScanServiceProvider');
});

it('points at the manifest file that exists', function () {
    $path = $this->composer['extra']['nativephp']['manifest'];

    expect($path)->toBe('nativephp.json')
        ->and(file_exists($this->root.'/'.$path))->toBeTrue();
});

it('maps every manifest bridge function onto the PHP enum, and back', function () {
    $declared = array_column($this->manifest['bridge_functions'], 'name');
    $enum = array_column(BridgeFunction::cases(), 'value');

    sort($declared);
    sort($enum);

    expect($declared)->toBe($enum);
});

it('namespaces every bridge function under the manifest namespace', function () {
    foreach ($this->manifest['bridge_functions'] as $fn) {
        expect($fn['name'])->toStartWith($this->manifest['namespace'].'.')
            ->and($fn['description'] ?? '')->not->toBeEmpty();
    }
});

it('resolves every android FQN against the shipped Kotlin', function () {
    $kotlin = file_get_contents($this->root.'/resources/android/src/WifiFunctions.kt');

    foreach ($this->manifest['bridge_functions'] as $fn) {
        $parts = explode('.', $fn['android']);
        $class = array_pop($parts);
        $object = array_pop($parts);
        $package = implode('.', $parts);

        expect($kotlin)->toContain("package {$package}")
            ->and($kotlin)->toContain("object {$object}")
            ->and($kotlin)->toContain("class {$class}(");
    }
});

it('passes an activity to every bridge function', function () {
    // Event dispatch needs a FragmentActivity; a context-only variant could not
    // deliver NetworksScanned. See docs/DESIGN.md decision 2.
    foreach ($this->manifest['bridge_functions'] as $fn) {
        expect($fn['android_params'])->toBe(['activity']);
    }
});

it('declares only event classes that exist', function () {
    foreach ($this->manifest['events'] as $event) {
        expect(class_exists($event))->toBeTrue("Missing event class: {$event}");
    }
});

it('declares the permissions that gate scanning', function () {
    expect($this->manifest['android']['permissions'])
        ->toContain('android.permission.ACCESS_WIFI_STATE')      // read results
        ->toContain('android.permission.CHANGE_WIFI_STATE')      // call startScan()
        ->toContain('android.permission.ACCESS_FINE_LOCATION')   // API <= 32 gate
        ->toContain('android.permission.NEARBY_WIFI_DEVICES');   // API 33+ gate
});

it('never requests background location', function () {
    // Requesting it would trigger Google Play's location declaration form.
    // This plugin is foreground-only; see docs/STORE-REVIEW.md.
    expect($this->manifest['android']['permissions'])
        ->not->toContain('android.permission.ACCESS_BACKGROUND_LOCATION');
});

it('declares an android floor and claims no ios support', function () {
    expect($this->manifest['android']['min_version'])->toBe(23)
        ->and($this->manifest)->not->toHaveKey('ios');

    foreach ($this->manifest['bridge_functions'] as $fn) {
        expect($fn)->not->toHaveKey('ios');
    }

    expect(is_dir($this->root.'/resources/ios'))->toBeFalse();
});

it('exports one JS function per bridge function, over the HTTP bridge', function () {
    $js = file_get_contents($this->root.'/resources/js/wifi.js');

    foreach ($this->manifest['bridge_functions'] as $fn) {
        [, $short] = explode('.', $fn['name']);
        $method = lcfirst($short);

        expect($js)->toContain("export async function {$method}(")
            ->and($js)->toContain("'{$fn['name']}'");
    }

    // The transport the installed runtime actually serves. window.nativephp
    // does not exist — it shipped in v0.1.0 and never worked.
    expect($js)->toContain("'/_native/api/call'")
        ->and($js)->not->toContain('window.nativephp');
});

it('ships boost guidelines naming the current package', function () {
    $guidelines = file_get_contents($this->root.'/resources/boost/guidelines/core.blade.php');

    expect($guidelines)->toContain('all1web/nativephp-wifi-scan');
});

it('publishes the config file it merges', function () {
    expect(file_exists($this->root.'/config/wifi-scan.php'))->toBeTrue();
});
