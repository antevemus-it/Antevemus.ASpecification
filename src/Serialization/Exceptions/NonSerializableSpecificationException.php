<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Serialization\Exceptions;

/**
 * NonSerializableSpecificationException - A specification or a value has no serialized form.
 *
 * Raised by SpecificationCodec (2.4.0, forward 041, D2/D4) when encoding meets a specification whose
 * class is not registered (a user leaf without the contract, a subclass of a serializable class, an
 * anonymous class) or one of the specifications left out of the format on purpose, each with the
 * reason: a callback (`must()`, PredicateSpecification) is code, not data; `Spec::external()` resolves
 * its data at evaluation; FeatureEnabledSpecification depends on the FeatureFlags service; the guards of
 * the state machine are referenced by name through GuardRegistry; InQuerySpecification holds a
 * repository; RuleBoundSpecification is a binding to a catalog rule. A value that is a closure, an
 * object other than a date-time or an allowed enum case, a resource, an associative array or a
 * non-finite float is refused the same way (D3). Decoding raises it for a `t` the codec does not know
 * and for an enum outside the codec's allowlist: a document never chooses a class.
 *
 * `$specificationClass` is the class refused (null when a value or a `t` is refused).
 *
 * @version    2.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Serialization\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class NonSerializableSpecificationException extends SpecificationCodecException
{
    /**
     * @param string $reason Why it has no serialized form
     * @param string $path Position in the tree
     * @param class-string|null $specificationClass Class of the refused specification (null for a value or a `t`)
     */
    public function __construct(
        string $reason,
        string $path = '',
        public readonly ?string $specificationClass = null
    ) {
        parent::__construct($reason, $path);
    }
}
