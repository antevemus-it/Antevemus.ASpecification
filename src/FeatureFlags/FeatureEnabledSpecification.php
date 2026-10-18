<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Results\SpecificationResult;

/**
 * FeatureEnabledSpecification - "The Feature Flag Is On for This Candidate"
 *
 * The specification of a flag over its subject (forward 034, D7), built by FeatureFlags::spec(): it
 * composes with any other specification (`$flags->spec('renewal_notice')->and($dueSoon)`) and is
 * satisfied exactly when FeatureFlags::isEnabled($code, $candidate) is true.
 *
 * evaluate(): when the flag is off, one failure whose metadata is FeatureFlagEvaluation::toMetadata()
 * (flag, value, reason, OpenFeature reason and error code, subject key, rollout bucket and percentage,
 * error class; the reason `outside_rollout` since 2.3.0, forward 040, D8), with
 * because()/withCode() honoured. A flag that could not be read is a failure with the reason `error`,
 * not an evaluation error: the flag is off (fail closed, D9), so NOT of it is satisfied, exactly as
 * `!isEnabled()`; the failure was already handed to the error reporter of the service.
 *
 * Not translatable: the SQL, TCriteria and ALinq visitors refuse this leaf, as any leaf they do not
 * know; the state of the flag only exists at evaluation time, in PHP.
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ILeafSpecification<T>
 * @version    2.3.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class FeatureEnabledSpecification extends AbstractSpecification implements ILeafSpecification
{
    /**
     * @param FeatureFlags $flags The service that evaluates the flag
     * @param string $code Flag code
     */
    public function __construct(
        private readonly FeatureFlags $flags,
        private readonly string $code
    ) {
    }

    /** Flag code. */
    public function getCode(): string
    {
        return $this->code;
    }

    /** Rule name in failures: "featureEnabled(<code>)". */
    public function getRuleName(): string
    {
        return 'featureEnabled(' . $this->code . ')';
    }

    /** {@inheritdoc} */
    public function evaluate(mixed $candidate): SpecificationResult
    {
        $evaluation = $this->flags->evaluate($this->code, $candidate);
        if ($evaluation->value) {
            return SpecificationResult::satisfied();
        }

        return SpecificationResult::failure(
            message: $this->customReason ?? sprintf("Feature flag '%s' is off for this candidate (%s).", $this->code, $evaluation->reason->value),
            code: $this->customCode,
            ruleName: $this->getRuleName(),
            metadata: $evaluation->toMetadata()
        );
    }

    /** {@inheritdoc} */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $this->flags->isEnabled($this->code, $candidate);
    }

    /** {@inheritdoc} */
    public function getType(): string
    {
        return 'mixed';
    }

    /**
     * {@inheritdoc}
     *
     * Equal when it reads the same flag through the same service instance.
     */
    public function equals(mixed $other): bool
    {
        return $other instanceof self
            && $other->code === $this->code
            && $other->flags === $this->flags
            && $other->customReason === $this->customReason
            && $other->customCode === $this->customCode;
    }

    /** {@inheritdoc} */
    public function __toString(): string
    {
        return sprintf("featureEnabled('%s')", $this->code);
    }
}
