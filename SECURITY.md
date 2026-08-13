# Security policy

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Email **plugins@all1.ai** with:

- what the issue is and how to reproduce it
- the plugin version and Android version affected
- any proof-of-concept you have

We'll acknowledge within a few days and keep you updated. If you'd like credit
in the changelog, say so.

## Scope

This plugin reads WiFi radio information from the device and returns it to the
host Laravel application. It has no network client, no server, no telemetry, and
no persistence of its own.

Things that would be in scope:

- a way for a third-party app to read scan data through this plugin's bridge
  functions
- the plugin leaking scan data outside the host app's process
- privilege escalation via the bridge surface

Things that are **not** vulnerabilities in this plugin:

- the fact that WiFi scan results can be used to infer location. That is
  inherent to the capability and is the reason Android gates it behind a
  location-class permission. Your app's obligations around it are covered in
  [docs/STORE-REVIEW.md](docs/STORE-REVIEW.md).
- an application choosing to transmit scan data insecurely. The plugin
  transmits nothing; transport and storage are the host app's responsibility.

## Supported versions

The latest released version is supported. Fixes are released forward, not
backported.
