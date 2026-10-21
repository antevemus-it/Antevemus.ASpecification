<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use Antevemus\ASpecification\StateMachine\MachineDefinition;
use Antevemus\ASpecification\StateMachine\MachineDefinitionWarning;
use DateTimeImmutable;

/**
 * PublishedMachineDefinition - Outcome of Publishing a Draft (2.7.0)
 *
 * The definition published (key, the version given by the repository, fingerprint), the warnings of
 * the validator (a publication with errors never happens), and the trail written with it: the reason,
 * who published it and when (UTC; the instant comes from the caller).
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PublishedMachineDefinition
{
    public readonly DateTimeImmutable $publishedAt;

    /**
     * @param MachineDefinition $definition Definition published
     * @param list<MachineDefinitionWarning> $warnings Warnings of the validator
     * @param string $reason Why it was published
     * @param string|null $publishedBy Who published it
     * @param DateTimeImmutable $publishedAt Instant of the publication (stored in UTC)
     */
    public function __construct(
        public readonly MachineDefinition $definition,
        public readonly array $warnings,
        public readonly string $reason,
        public readonly ?string $publishedBy,
        DateTimeImmutable $publishedAt
    ) {
        $this->publishedAt = PersistenceSupport::utc($publishedAt);
    }

    /** Version given to the publication. */
    public function version(): int
    {
        return $this->definition->version;
    }
}
