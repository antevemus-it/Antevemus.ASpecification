<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Persistence\ILeaseLock;
use Closure;
use DateTimeImmutable;

/**
 * The lease lock as one dispatcher process of Module 50 (2.16.0, forward 065) sees it: tryAcquire() calls the hook first
 * (`acquire`, a suspension point of the scheduler); release() of a dead owner does nothing: a killed process is a fiber
 * dropped while suspended, whose unwinding runs the `finally` of the dispatcher, and a dead process releases nothing, so
 * its lock stays held until its lease runs out.
 *
 * @version 2.16.0
 */
final class Module50HookedLock implements ILeaseLock
{
    /** @var list<string> Every call that reached the inner lock: "acquire:<owner>:<answer>", "release:<owner>" */
    public array $calls = [];

    /**
     * @param Closure(string, string, mixed): void $hook (operation, owner, null)
     * @param Closure(string): bool $isDead True when the owner was killed
     */
    public function __construct(private readonly ILeaseLock $inner, private readonly Closure $hook, private readonly Closure $isDead)
    {
    }

    public function tryAcquire(string $name, string $owner, DateTimeImmutable $now, int $leaseSeconds): bool
    {
        ($this->hook)('acquire', $owner, null);
        $answer = $this->inner->tryAcquire($name, $owner, $now, $leaseSeconds);
        $this->calls[] = 'acquire:' . $owner . ':' . ($answer ? 'yes' : 'no');

        return $answer;
    }

    public function release(string $name, string $owner): void
    {
        if (($this->isDead)($owner)) {
            return;
        }
        $this->inner->release($name, $owner);
        $this->calls[] = 'release:' . $owner;
    }
}
