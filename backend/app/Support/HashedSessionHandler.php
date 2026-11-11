<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\ExistenceAwareInterface;
use Override;
use SessionHandlerInterface;

/**
 * Stores each session under the SHA-256 of its id, so the sessions table, its
 * backups and the copy of the live database a deploy pulls to a dev machine
 * hold nothing that is a session id. The id is 40 random characters, so no
 * salt is needed. The cookie carries the id itself, encrypted.
 *
 * It wraps Laravel's database handler instead of extending it: that handler's
 * write() calls its own read(), which an override would hash a second time.
 */
final readonly class HashedSessionHandler implements ExistenceAwareInterface, SessionHandlerInterface
{
    public function __construct(private DatabaseSessionHandler $inner) {}

    /** What the table's id column holds for a session id. */
    public static function key(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    #[Override]
    public function open(string $path, string $name): bool
    {
        return $this->inner->open($path, $name);
    }

    #[Override]
    public function close(): bool
    {
        return $this->inner->close();
    }

    #[Override]
    public function read(string $id): string|false
    {
        return $this->inner->read(self::key($id));
    }

    #[Override]
    public function write(string $id, string $data): bool
    {
        return $this->inner->write(self::key($id), $data);
    }

    #[Override]
    public function destroy(string $id): bool
    {
        return $this->inner->destroy(self::key($id));
    }

    #[Override]
    public function gc(int $max_lifetime): int
    {
        return $this->inner->gc($max_lifetime);
    }

    #[Override]
    public function setExists($value): static
    {
        $this->inner->setExists($value);

        return $this;
    }
}
