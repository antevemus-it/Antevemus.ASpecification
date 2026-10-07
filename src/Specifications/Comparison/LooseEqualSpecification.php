<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

/**
 * LooseEqualSpecification - Opt-in leaf specification for loose equality (`==`).
 *
 * Applies PHP's loose comparison semantics: `5 == "5"`, `true == 1`, `5 == 5.0` are satisfied.
 * Use it deliberately when candidates arrive as strings from forms, CSV or database drivers
 * (e.g. Adianti returns "5", "1", "t") and the business rule tolerates coercion.
 *
 * The default EqualSpecification is strict (`===`) and refuses incompatible types with
 * IncompatibleTypeException; this class never raises that exception.
 *
 * Extends EqualSpecification so that the SQL, TCriteria and ALinq visitors translate it as a
 * plain equality predicate, where the target engine applies its own coercion rules.
 *
 * @template T
 * @extends EqualSpecification<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class LooseEqualSpecification extends EqualSpecification
{
    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if ($candidate === null) {
            return $this->getValue() === null;
        }
        return $candidate == $this->getValue();
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultFailureMessage(mixed $candidate): string
    {
        return sprintf(
            'Value is not loosely equal to the expected value (%s).',
            is_scalar($this->getValue()) ? var_export($this->getValue(), true) : get_debug_type($this->getValue())
        );
    }
}
