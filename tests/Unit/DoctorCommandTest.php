<?php

declare(strict_types=1);

/*
 * The doctor is the first line of support for a free plugin — it must be
 * registered, runnable, and honest about a broken setup.
 */

it('registers the doctor command', function () {
    expect(collect(\Illuminate\Support\Facades\Artisan::all())->keys())
        ->toContain('wifi-scan:doctor');
});

it('runs and reports the unregistered allowlist in a bare host', function () {
    // Testbench base_path has no NativeServiceProvider and no generated
    // Android project — the doctor must fail loudly, not crash.
    $this->artisan('wifi-scan:doctor')
        ->expectsOutputToContain('WiFi Radar — setup diagnosis')
        ->assertExitCode(1);
});
