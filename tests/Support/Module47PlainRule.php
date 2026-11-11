<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;
use Antevemus\ASpecification\Engine\RuleAction;

/**
 * A rule of an application that implements IRuleDefinition directly, without IVersionedRuleDefinition (forward 060,
 * RN-01): the engine stamps nothing new on its failures.
 *
 * @version 2.13.0
 */
final class Module47PlainRule implements IRuleDefinition
{
    public function getCode(): string
    {
        return 'own';
    }

    public function getName(): string
    {
        return 'Own';
    }

    public function getDescription(): ?string
    {
        return null;
    }

    public function getRuleType(): string
    {
        return 'max';
    }

    public function getViolationAction(): RuleAction
    {
        return RuleAction::BLOCK;
    }

    public function getIntValue(): ?int
    {
        return 10;
    }

    public function getDecimalValue(): ?float
    {
        return null;
    }

    public function getTextValue(): ?string
    {
        return null;
    }

    public function getLegalBasis(): ?string
    {
        return null;
    }

    public function getViolationMessage(): ?string
    {
        return null;
    }

    public function getConditionExpression(): ?string
    {
        return null;
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function getScope(): ?string
    {
        return 'contract';
    }

    public function getScenario(): ?string
    {
        return null;
    }

    public function getParameters(): array
    {
        return [];
    }

    public function isActive(): bool
    {
        return true;
    }

    public function getSpecJson(): ?string
    {
        return null;
    }
}
