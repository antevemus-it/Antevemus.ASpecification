<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Engine;

/**
 * IRuleCatalog - Repository / Provider for Rule and Document Definitions
 *
 * Abstracts access to the underlying persistence source (PostgreSQL, cache, JSON files)
 * where the business rule catalog and document groups are stored.
 *
 * Features:
 * - Filtered retrieval of active business rules by scope and scenario
 * - Filtered retrieval of document requirement groups by scope and scenario
 * - Support for custom contextual filters (product code, plan code, validity dates)
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IRuleCatalog
{
    /**
     * Retrieves the collection of active business rules for the given scope and scenario.
     *
     * @param string $escopo Operational scope (e.g. 'rental_contract', 'claim')
     * @param string|null $cenario Specific scenario (e.g. 'claim:occupied')
     * @param array<string, mixed> $filters Additional filters (product_code, plan_code, etc.)
     * @return list<IRuleDefinition>
     */
    public function findRules(string $escopo, ?string $cenario = null, array $filters = []): array;

    /**
     * Retrieves the collection of document requirements for the given scope and scenario.
     *
     * @param string $escopo Operational scope (e.g. 'rental_contract', 'claim')
     * @param string|null $cenario Specific scenario (e.g. 'claim:occupied')
     * @return list<IDocumentRuleDefinition>
     */
    public function findDocumentRules(string $escopo, ?string $cenario = null): array;
}
