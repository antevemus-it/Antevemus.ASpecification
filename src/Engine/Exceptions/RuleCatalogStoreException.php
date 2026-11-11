<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine\Exceptions;

use Throwable;

/**
 * RuleCatalogStoreException - Storage Failure of a Rule Catalog Store
 *
 * Wraps a failure of an IRuleCatalogStore (2.13.0, forward 060): a statement that fails, a stored value the editor
 * cannot read, a table without the columns of the audited path. Unlike FeatureFlagStoreException, the message does
 * not carry the driver message, which may quote the values of the row (a CHECK failure on PostgreSQL prints the
 * whole row): it names the operation and the SQLSTATE, and the driver exception is the previous one (RN-05).
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class RuleCatalogStoreException extends RuleCatalogEditorException
{
    /**
     * @param string $message Human-readable message (no column value)
     * @param string|null $ruleCode Code of the rule concerned
     * @param Throwable|null $previous Driver exception, when any
     * @param string|null $sqlState SQLSTATE reported by the driver, when any
     */
    public function __construct(string $message, ?string $ruleCode = null, ?Throwable $previous = null, private readonly ?string $sqlState = null)
    {
        parent::__construct($message, $ruleCode, $previous);
    }

    /** SQLSTATE reported by the driver, when any. */
    public function getSqlState(): ?string
    {
        return $this->sqlState;
    }

    /** True when the driver reported an integrity constraint violation (SQLSTATE class 23). */
    public function isIntegrityViolation(): bool
    {
        return $this->sqlState !== null && str_starts_with($this->sqlState, '23');
    }
}
