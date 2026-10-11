<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Results\SpecificationResult;

/**
 * DataResolver - Fetch First, Then Evaluate
 *
 * Collects the data declarations (ExternalDataSpecification) of a whole specification tree, fetches
 * the data concurrently, and only then evaluates the tree, synchronously and purely, as every
 * specification is evaluated (forward 026, roadmap milestone 15). Specifications never call a
 * service and never become asynchronous: `because()`/`withCode()`, the SQL/TCriteria compilation of
 * the other rules and the generic error message of 1.8.0 keep working unchanged.
 *
 * <code>
 * $resolver = new DataResolver($registry);
 * $result = $resolver->evaluate($creditRule, $customer);                                 // concurrent (default)
 * $result = $resolver->evaluate($creditRule, $customer, ResolutionPolicy::tiered());     // cheapest first, may skip
 * </code>
 *
 * - resolve(): collects the requests of the tree for the candidate (deduplicated: two leaves asking
 *   the same data cause one fetch), fetches them according to the policy and returns ResolvedData.
 *   The HTTP calls of a stage run together in one `curl_multi`; the other sources run one after the
 *   other in the stage. A failed fetch is not thrown: it becomes the evaluation error of the leaves
 *   that need it.
 * - evaluate(): resolve() and evaluate the tree inside the resolution scope of the data.
 *
 * Policies (ResolutionPolicy): `concurrent()` (default) fetches everything in one stage and reports
 * the same failures as an evaluation outside the resolver; `tiered()` fetches by cost, cheapest first,
 * and stops when the verdict is decided (fewer calls, possibly fewer failures: an explicit choice); `sequential()` fetches
 * nothing beforehand (each leaf fetches when the evaluation reaches it). For any tree, the verdict
 * (`isSatisfied`) is the same under every policy and outside the resolver; under `tiered()` a branch
 * that was not fetched because it could not change the verdict reports no failure (forward 026 RN-05).
 *
 * Optional cache between resolutions (IDataCache), keyed by canonical request key, with the TTL of
 * each source. No retry, no circuit breaker (outside the core, D11).
 *
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DataResolver
{
    private readonly DataSourceRegistry $registry;

    /**
     * @param DataSourceRegistry|null $registry Sources by name (default: DataSourceRegistry::default())
     * @param IDataCache|null $cache Cache between resolutions (none by default)
     */
    public function __construct(?DataSourceRegistry $registry = null, private readonly ?IDataCache $cache = null)
    {
        $this->registry = $registry ?? DataSourceRegistry::default();
    }

    /** Registry this resolver fetches through. */
    public function getRegistry(): DataSourceRegistry
    {
        return $this->registry;
    }

    /**
     * Collects and fetches the data the tree needs for the candidate.
     *
     * @param ISpecification $specification Tree to resolve
     * @param mixed $candidate Candidate the parameters are read from
     * @param ResolutionPolicy|null $policy Policy (default: ResolutionPolicy::concurrent())
     */
    public function resolve(ISpecification $specification, mixed $candidate, ?ResolutionPolicy $policy = null): ResolvedData
    {
        $policy ??= ResolutionPolicy::concurrent();
        $timeout = $policy->getTimeout();
        $data = new ResolvedData(
            $this->registry,
            $policy,
            $this->cache,
            $timeout !== null ? CurlMultiTransport::now() + $timeout : null
        );

        if ($policy->getMode() === ResolutionMode::SEQUENTIAL) {
            return $data;
        }

        [$requests, $neutral] = TreeAnalyzer::collect($specification, $candidate);
        foreach ($requests as $request) {
            $data->add($request);
        }
        $keys = array_keys($requests);

        if ($policy->getMode() === ResolutionMode::CONCURRENT) {
            $data->fetch($keys);
            return $data;
        }

        // Tiered: cheapest first; before each stage, stop when the verdict can no longer change.
        $data->loadFromCache($keys);
        foreach ($data->pendingByCost($keys) as $stageKeys) {
            if (TreeAnalyzer::isDecided($specification, $candidate, $data)) {
                $data->skip($data->pendingAmong($keys), $neutral);
                break;
            }
            $data->fetch($stageKeys);
        }

        return $data;
    }

    /**
     * Resolves the data and evaluates the tree with it (the shortcut of resolve() + evaluate()).
     *
     * @param ISpecification $specification Tree to evaluate
     * @param mixed $candidate Candidate
     * @param ResolutionPolicy|null $policy Policy (default: ResolutionPolicy::concurrent())
     */
    public function evaluate(ISpecification $specification, mixed $candidate, ?ResolutionPolicy $policy = null): SpecificationResult
    {
        return $this->resolve($specification, $candidate, $policy)->evaluate($specification, $candidate);
    }
}
