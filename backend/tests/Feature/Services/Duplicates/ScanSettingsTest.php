<?php

declare(strict_types=1);

use App\Services\Duplicates\ScanSettings;

/** ScanSettings::fromConfig() with these as the .env values. */
function scanSettings(string $maxDistance, string $maxRegionDiff): ScanSettings
{
    config(['gallery.duplicates' => ['max_distance' => $maxDistance, 'max_region_diff' => $maxRegionDiff]]);

    return ScanSettings::fromConfig();
}

test('duplicate settings default to the calibrated values', function (): void {
    $settings = scanSettings('', '');

    expect($settings->maxDistance)->toBe(6)
        ->and($settings->maxRegionDiff)->toBe(36.0)
        ->and($settings->tuning)->toBeFalse();
});

test('duplicate settings read configured values', function (): void {
    $settings = scanSettings(' 7 ', '30.5');

    expect($settings->maxDistance)->toBe(7)
        ->and($settings->maxRegionDiff)->toBe(30.5);
});

test('region check can be turned off', function (): void {
    expect(scanSettings('', 'OFF')->maxRegionDiff)->toBeNull();
});

test('invalid duplicate settings fall back to defaults', function (): void {
    $settings = scanSettings('17', '300');

    expect($settings->maxDistance)->toBe(6)
        ->and($settings->maxRegionDiff)->toBe(36.0);
});

test('duplicate setting parsers reject anything out of range', function (): void {
    expect(ScanSettings::parseMaxDistance('0'))->toBe(0)
        ->and(ScanSettings::parseMaxDistance('16'))->toBe(16)
        ->and(ScanSettings::parseMaxDistance('17'))->toBeNull()
        ->and(ScanSettings::parseMaxDistance('-1'))->toBeNull()
        ->and(ScanSettings::parseMaxDistance('2.5'))->toBeNull()
        ->and(ScanSettings::parseMaxDistance(''))->toBeNull()
        ->and(ScanSettings::parseMaxRegionDiff('0'))->toBe(0.0)
        ->and(ScanSettings::parseMaxRegionDiff('255'))->toBe(255.0)
        ->and(ScanSettings::parseMaxRegionDiff('255.5'))->toBeNull()
        ->and(ScanSettings::parseMaxRegionDiff('-1'))->toBeNull()
        ->and(ScanSettings::parseMaxRegionDiff('off'))->toBeNull()
        ->and(ScanSettings::parseMaxRegionDiff(''))->toBeNull();
});
