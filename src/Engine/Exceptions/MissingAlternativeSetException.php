<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine\Exceptions;

/**
 * MissingAlternativeSetException - ANY / ONE_OF_SET Document Rule Without a Set
 *
 * Raised at compilation time by the DocumentGroupSpecificationBuilder when a document
 * requirement in ANY or ONE_OF_SET mode has no `alternative_set_code`. The relational
 * catalog forbids that shape (`requirement_mode = 'all' OR alternative_set_code
 * IS NOT NULL`), so the engine refuses it too instead of guessing which documents form
 * the set.
 *
 * @version    1.7.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class MissingAlternativeSetException extends RuleEngineException
{
    /**
     * @param string $mode Requirement mode value ('any' or 'one_of_set')
     * @param string $documentType Document type code the rule belongs to
     * @param string $groupCode Document group code the rule belongs to
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $documentType,
        public readonly string $groupCode
    ) {
        parent::__construct(sprintf(
            'Document "%s" (group "%s") uses requirement mode "%s" without an alternative_set_code. ' .
            'ANY and ONE_OF_SET rules must declare the alternative set they belong to.',
            $documentType,
            $groupCode,
            $mode
        ));
    }
}
