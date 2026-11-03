<?php

declare(strict_types=1);

use App\Services\Duplicates\RegionDifference;
use App\Services\Duplicates\ScanSettings;
use Tests\Support\TestImages;

// The region difference against the default ceiling: what a re-saved copy
// must stay under and what a variant must clear.

test('identical images differ by nothing', function (): void {
    $image = TestImages::artwork();

    expect(RegionDifference::between($image, $image))->toBe(0.0);
});

test('resaved copies stay under the ceiling', function (): void {
    $image = TestImages::artwork();

    expect(RegionDifference::between($image, TestImages::resaved($image, 0.9, 75)))->toBeLessThan(ScanSettings::DEFAULT_MAX_REGION_DIFF)
        ->and(RegionDifference::between($image, TestImages::resaved($image, 0.6, 50)))->toBeLessThan(ScanSettings::DEFAULT_MAX_REGION_DIFF);
});

test('an overall colour shift is not counted as a difference', function (): void {
    $image = TestImages::artwork();

    expect(RegionDifference::between($image, TestImages::brightened($image, 20)))->toBeLessThan(5.0);
});

test('a variant with one small area changed clears the ceiling', function (): void {
    $image = TestImages::artwork();

    expect(RegionDifference::between($image, TestImages::variant($image)))->toBeGreaterThan(ScanSettings::DEFAULT_MAX_REGION_DIFF);
});
