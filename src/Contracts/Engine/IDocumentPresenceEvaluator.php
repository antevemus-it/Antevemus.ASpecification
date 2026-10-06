<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Engine;

/**
 * IDocumentPresenceEvaluator - Contract for Document Existence Evaluation
 *
 * Allows the document compiler to query whether a specific required document
 * is attached, approved, or present within the context of the target object.
 *
 * Features:
 * - Document presence evaluation over domain entities or data structures
 * - Support for extended context metadata (e.g., rental tenure, expiration date)
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IDocumentPresenceEvaluator
{
    /**
     * Evaluates whether the document identified by the given code is present and valid in the target.
     *
     * @param object|array $target Target object or payload under evaluation
     * @param string $codigoTipoDocumento Technical identifier code of the document type
     * @param array<string, mixed> $context Additional contextual metadata
     * @return bool
     */
    public function hasDocument(object|array $target, string $codigoTipoDocumento, array $context = []): bool;
}
