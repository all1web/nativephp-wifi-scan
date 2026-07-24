<?php

declare(strict_types=1);

namespace WifiScan\Mobile\Data;

/**
 * An immutable value object describing a single WiFi access point as seen by
 * the device radio. This is the shape both scan() entries and current() return.
 */
final class AccessPoint
{
    public function __construct(
        public readonly string $ssid,
        public readonly string $bssid,
        public readonly ?int $rssi = null,
        public readonly ?int $frequency = null,
    ) {}

    /**
     * Build from a native bridge row. Missing keys degrade gracefully so a
     * malformed native payload never throws inside a Livewire render.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            ssid: (string) ($row['ssid'] ?? ''),
            bssid: strtolower((string) ($row['bssid'] ?? '')),
            rssi: isset($row['rssi']) ? (int) $row['rssi'] : null,
            frequency: isset($row['frequency']) ? (int) $row['frequency'] : null,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, self>
     */
    public static function collection(array $rows): array
    {
        return array_values(array_map(self::fromArray(...), $rows));
    }

    public function isHidden(): bool
    {
        return $this->ssid === '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ssid' => $this->ssid,
            'bssid' => $this->bssid,
            'rssi' => $this->rssi,
            'frequency' => $this->frequency,
        ];
    }
}
