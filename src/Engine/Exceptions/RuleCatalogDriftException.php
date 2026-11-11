<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine\Exceptions;

use Antevemus\ASpecification\Engine\RuleDrift;

/**
 * RuleCatalogDriftException - The Row of the Rule Was Changed Outside the Editor
 *
 * Thrown by RuleCatalogEditor (2.13.0, forward 060, D11) when a change would build on a row that is not the photo
 * of its version in the history (RuleDriftKind::Modified: an UPDATE by hand that kept or lowered the version), or
 * when a rule to create already has a history without a row (RuleDriftKind::Deleted). Writing on top of it would
 * hide the change made by hand: nothing is written. findDrift() lists such rules; raising the version of the row
 * by hand (`UPDATE business_rule SET version = version + 1 WHERE code = ...`) records the change made outside the
 * editor as a version of its own, whose baseline the next change through the editor (or adopt()) writes.
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class RuleCatalogDriftException extends RuleCatalogEditorException
{
    /**
     * @param RuleDrift $drift How the row departs from the history
     * @param string $message Human-readable message
     */
    public function __construct(private readonly RuleDrift $drift, string $message)
    {
        parent::__construct($message, $drift->code);
    }

    /** How the row departs from the history. */
    public function getDrift(): RuleDrift
    {
        return $this->drift;
    }
}
