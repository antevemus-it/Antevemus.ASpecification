<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\StateMachine\Persistence\InMemory\IInMemoryTransactional;

/**
 * The in-memory effects of the handlers of Module 48 (2.14.0, forward 063): one line per effect, rolled back with
 * the inbox when the handler's transaction rolls back (the twin of the table m48_effect of the PDO runs).
 *
 * @version 2.14.0
 */
final class Module48EffectLog implements IInMemoryTransactional
{
    /** @var list<string> */
    private array $lines = [];

    public function add(string $line): void
    {
        $this->lines[] = $line;
    }

    /** @return list<string> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function exportState(): array
    {
        return $this->lines;
    }

    public function importState(array $state): void
    {
        $this->lines = array_values($state);
    }
}
