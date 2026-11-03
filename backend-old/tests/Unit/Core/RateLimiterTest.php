<?php

declare(strict_types=1);

namespace Gallery\Tests\Unit\Core;

use Gallery\Core\RateLimiter;
use Gallery\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RateLimiter::class)]
final class RateLimiterTest extends DatabaseTestCase
{
    public function testAllowsRequestsUnderTheLimit(): void
    {
        $limiter = new RateLimiter(self::makeDb(), 3, 60);

        $first = $limiter->check('1.1.1.1');
        $this->assertTrue($first['allowed']);
        $this->assertSame(2, $first['remaining']);
        $this->assertSame(0, $first['retry_after']);

        $second = $limiter->check('1.1.1.1');
        $this->assertTrue($second['allowed']);
        $this->assertSame(1, $second['remaining']);
    }

    public function testBlocksOnceTheLimitIsReached(): void
    {
        $limiter = new RateLimiter(self::makeDb(), 2, 60);

        $this->assertTrue($limiter->check('2.2.2.2')['allowed']);
        $this->assertTrue($limiter->check('2.2.2.2')['allowed']);

        $blocked = $limiter->check('2.2.2.2');
        $this->assertFalse($blocked['allowed']);
        $this->assertSame(0, $blocked['remaining']);
        $this->assertGreaterThan(0, $blocked['retry_after']);
    }

    public function testDifferentIpsHaveIndependentBuckets(): void
    {
        $limiter = new RateLimiter(self::makeDb(), 1, 60);

        $this->assertTrue($limiter->check('a')['allowed']);
        $this->assertFalse($limiter->check('a')['allowed']);
        // A different IP is unaffected by the first IP hitting its limit.
        $this->assertTrue($limiter->check('b')['allowed']);
    }

    public function testExpiredEntriesArePrunedSoTheBucketRefills(): void
    {
        $db = self::makeDb();
        // Pre-seed an old request well outside a 1-second window.
        $db->prepare('INSERT INTO rate_limits (ip, requested_at) VALUES (:ip, :t)')
            ->execute([':ip' => '9.9.9.9', ':t' => time() - 3600]);

        $limiter = new RateLimiter($db, 1, 1);
        // The stale row is pruned, so this fresh request is still allowed.
        $this->assertTrue($limiter->check('9.9.9.9')['allowed']);
    }
}
