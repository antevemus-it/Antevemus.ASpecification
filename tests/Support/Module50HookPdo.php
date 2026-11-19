<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Closure;
use PDO;
use PDOStatement;

/**
 * A connection of Module 50 (2.16.0, forward 065) that runs a hook before preparing a statement that contains a given
 * text, once: the other connection acts between the reads of PdoLeaseLock and its INSERT (the race of the first
 * acquisition), or the statement fails the way a driver may.
 *
 * @version 2.16.0
 */
final class Module50HookPdo extends PDO
{
    private ?string $needle = null;

    private ?Closure $hook = null;

    /**
     * Runs $hook before the next statement prepared with $needle in it (once).
     *
     * @param Closure(): void $hook
     */
    public function before(string $needle, Closure $hook): void
    {
        $this->needle = $needle;
        $this->hook = $hook;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->needle !== null && $this->hook !== null && str_contains($query, $this->needle)) {
            $hook = $this->hook;
            $this->needle = null;
            $this->hook = null;
            $hook();
        }

        return parent::prepare($query, $options);
    }
}
