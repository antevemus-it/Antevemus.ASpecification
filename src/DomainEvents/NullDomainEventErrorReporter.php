<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

/**
 * NullDomainEventErrorReporter - Default Reporter That Discards
 *
 * Default of TriggeringRepository (forward 029, D3). Replace it with a reporter that logs.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class NullDomainEventErrorReporter implements IDomainEventErrorReporter
{
    public function report(TriggerEvaluationError $error): void
    {
    }
}
