<?php

namespace Gallery\Core;

use PDO;

/**
 * Simple rate limiter using SQLite.
 * Tracks request counts per IP within a sliding time window.
 * Prunes all expired entries globally on each check to prevent unbounded growth.
 */
class RateLimiter
{
    public function __construct(private readonly PDO $db, private readonly int $maxRequests = 120, private readonly int $windowSeconds = 60)
    {
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS rate_limits (
                ip TEXT NOT NULL,
                requested_at INTEGER NOT NULL
            )'
        );
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS idx_rate_limits_ip_time ON rate_limits (ip, requested_at)'
        );
    }

    /**
     * Check if the given IP is within the rate limit. Records the request if allowed.
     * Uses a transaction to reduce overhead from 3 separate DB operations.
     * Prunes all expired entries globally to prevent unbounded table growth.
     *
     * @return array{allowed: bool, remaining: int, retry_after: int}
     */
    public function check(string $ip): array
    {
        $cutoff = time() - $this->windowSeconds;

        $this->db->beginTransaction();

        try {
            // Prune all expired entries globally (not just this IP) as housekeeping.
            $this->db->prepare('DELETE FROM rate_limits WHERE requested_at < :cutoff')
                ->execute([':cutoff' => $cutoff]);

            // Count this IP's requests within THIS limiter's own window. The
            // explicit time filter is essential: multiple limiters with
            // different windows (e.g. the global 60s limiter and the 300s login
            // limiter) share this table, and the global prune above would
            // otherwise wipe the longer window's rows and undercount.
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM rate_limits WHERE ip = :ip AND requested_at >= :cutoff');
            $stmt->execute([':ip' => $ip, ':cutoff' => $cutoff]);
            $count = (int) $stmt->fetchColumn();

            if ($count >= $this->maxRequests) {
                $this->db->commit();

                // Find earliest in-window entry to calculate retry_after
                $stmt = $this->db->prepare('SELECT MIN(requested_at) FROM rate_limits WHERE ip = :ip AND requested_at >= :cutoff');
                $stmt->execute([':ip' => $ip, ':cutoff' => $cutoff]);
                $earliest = (int) $stmt->fetchColumn();
                $retryAfter = max(1, ($earliest + $this->windowSeconds) - time());

                return ['allowed' => false, 'remaining' => 0, 'retry_after' => $retryAfter];
            }

            // Record this request
            $this->db->prepare('INSERT INTO rate_limits (ip, requested_at) VALUES (:ip, :time)')
                ->execute([':ip' => $ip, ':time' => time()]);

            $this->db->commit();
        } catch (\Throwable) {
            $this->db->rollBack();
            // On error, allow the request to proceed
            return ['allowed' => true, 'remaining' => $this->maxRequests, 'retry_after' => 0];
        }

        return ['allowed' => true, 'remaining' => $this->maxRequests - $count - 1, 'retry_after' => 0];
    }
}
