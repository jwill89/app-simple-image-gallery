<?php

declare(strict_types=1);

namespace Gallery\Tests\Unit\Core;

use Gallery\Core\Configuration;
use Gallery\Core\RegionDifference;
use Gallery\Tests\Support\TestImages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The region difference against the default ceiling: what a re-saved copy
 * must stay under and what a variant must clear.
 */
#[CoversClass(RegionDifference::class)]
final class RegionDifferenceTest extends TestCase
{
    public function testIdenticalImagesDifferByNothing(): void
    {
        $image = TestImages::artwork();

        $this->assertSame(0.0, RegionDifference::between($image, $image));
    }

    public function testResavedCopiesStayUnderTheCeiling(): void
    {
        $image = TestImages::artwork();

        $this->assertLessThan(
            Configuration::DEFAULT_DUPLICATE_MAX_REGION_DIFF,
            RegionDifference::between($image, TestImages::resaved($image, 0.9, 75))
        );
        $this->assertLessThan(
            Configuration::DEFAULT_DUPLICATE_MAX_REGION_DIFF,
            RegionDifference::between($image, TestImages::resaved($image, 0.6, 50))
        );
    }

    public function testAnOverallColourShiftIsNotCountedAsADifference(): void
    {
        $image = TestImages::artwork();

        $this->assertLessThan(5.0, RegionDifference::between($image, TestImages::brightened($image, 20)));
    }

    public function testAVariantWithOneSmallAreaChangedClearsTheCeiling(): void
    {
        $image = TestImages::artwork();

        $this->assertGreaterThan(
            Configuration::DEFAULT_DUPLICATE_MAX_REGION_DIFF,
            RegionDifference::between($image, TestImages::variant($image))
        );
    }
}
