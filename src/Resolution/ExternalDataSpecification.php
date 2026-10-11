<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Helpers\PropertyAccessor;
use Antevemus\ASpecification\Results\SpecificationResult;
use InvalidArgumentException;
use Throwable;

/**
 * ExternalDataSpecification - "The External Data X Satisfies the Specification S"
 *
 * A leaf that depends on data held outside the candidate (a bureau score, a fraud flag, a balance)
 * without calling the service itself: it **declares** the data it needs, as a source name and
 * parameters read from the candidate, and an ordinary specification that decides on the value.
 *
 * <code>
 * Spec::external('credit.score', ['document' => 'customer.cpf'], Spec::greaterThanOrEqualTo(700));
 * </code>
 *
 * Parameters are dot paths into the candidate (PropertyAccessor notation), resolved when the leaf is
 * evaluated or collected by the resolver. The inner specification is a common specification, with
 * because()/withCode(), and it is the one that decides (forward 026 D1).
 *
 * Where the data comes from (D5):
 * - inside a ResolutionScope (DataResolver::evaluate(), ResolvedData::evaluate()): from the resolved
 *   data, fetched beforehand and concurrently; a request the resolver did not fetch is fetched now;
 * - outside every scope: fetched now, synchronously, through the source of the default registry
 *   (DataSourceRegistry::default()). Everything works without the resolver, only without concurrency.
 *
 * evaluate():
 * - a candidate that is not an object or an array (when parameters are declared) does not satisfy;
 * - a parameter whose path resolves to null does not satisfy (no request is made for nobody);
 * - a parameter whose path does not exist, an unknown source and any fetch failure (HTTP status,
 *   transport, time limit, parser) are evaluation errors (the 1.8.0 rule: failure with the generic
 *   message, `evaluation_error = true`, the exception in `$result->exception`), never rule failures,
 *   never inverted by NOT;
 * - otherwise the value is evaluated by the inner specification: its failures are reported as they
 *   are; when this leaf carries because()/withCode() it reports one failure of its own with the
 *   inner failures as causes. An error of the inner specification is kept.
 *
 * Not translatable: the SQL, TCriteria and ALinq visitors refuse this leaf with their "non
 * translatable" exception (D10); the data only exists at evaluation time, in PHP.
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ILeafSpecification<T>
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class ExternalDataSpecification extends AbstractSpecification implements ILeafSpecification
{
    /**
     * @param string $source Name of the data source (registered in a DataSourceRegistry)
     * @param array<string, string> $parameters Request parameters: name => dot path into the candidate
     * @param ISpecification<mixed> $specification Specification applied to the fetched value
     * @param string $type Declared candidate type (defaults to `mixed`)
     * @throws InvalidArgumentException When the source name, a parameter name or a path is not valid
     */
    public function __construct(
        private readonly string $source,
        private readonly array $parameters,
        private readonly ISpecification $specification,
        private readonly string $type = 'mixed'
    ) {
        if (!DataSourceRegistry::isValidName($source)) {
            throw new InvalidArgumentException(sprintf('Invalid data source name "%s".', $source));
        }
        foreach ($parameters as $name => $path) {
            if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid parameter name for external data "%s": a PHP identifier is required.',
                    $source
                ));
            }
            if (!is_string($path) || trim($path) === '') {
                throw new InvalidArgumentException(sprintf(
                    'Parameter "%s" of external data "%s" must be a non-empty property path of the candidate.',
                    $name,
                    $source
                ));
            }
        }
    }

    /** Name of the data source. */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * Request parameters: name => dot path into the candidate.
     *
     * @return array<string, string>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Specification applied to the fetched value.
     *
     * @return ISpecification<mixed>
     */
    public function getSpecification(): ISpecification
    {
        return $this->specification;
    }

    /** Rule name in failures and error results: "external(<source>)". */
    public function getRuleName(): string
    {
        return 'external(' . $this->source . ')';
    }

    /**
     * Builds the request of this leaf for a candidate (what the resolver collects).
     *
     * @throws InvalidArgumentException When the candidate cannot supply the parameters (not an object
     *                                  or array, missing path, null value, value of an unsupported type)
     */
    public function createRequest(mixed $candidate): DataRequest
    {
        $prepared = $this->prepare($candidate);
        if ($prepared instanceof DataRequest) {
            return $prepared;
        }

        throw $prepared->exception ?? new InvalidArgumentException(sprintf(
            'The candidate cannot supply the parameters of external data "%s".',
            $this->source
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function evaluate(mixed $candidate): SpecificationResult
    {
        $prepared = $this->prepare($candidate);
        if ($prepared instanceof SpecificationResult) {
            return $prepared;
        }

        try {
            $value = $this->load($prepared);
        } catch (Throwable $e) {
            return SpecificationResult::error(exception: $e, ruleName: $this->getRuleName(), code: $this->customCode);
        }

        if ($value instanceof SkippedData) {
            // Tiered policy: the verdict did not depend on this data. The neutral outcome of the
            // position adds no failure to the result (an odd number of negations hides this one).
            return $value->neutral
                ? SpecificationResult::satisfied()
                : SpecificationResult::failure(
                    message: sprintf("External data '%s' was not fetched: the verdict did not depend on it.", $this->source),
                    code: $this->customCode,
                    ruleName: $this->getRuleName(),
                    metadata: ['source' => $this->source, 'skipped' => true]
                );
        }

        $inner = $this->specification->evaluate($value);
        if ($inner->isSatisfied || $inner->isError) {
            return $inner;
        }

        if ($this->customReason !== null || $this->customCode !== null) {
            return SpecificationResult::failure(
                message: $this->customReason ?? sprintf("The external data '%s' did not satisfy the expected specification.", $this->source),
                code: $this->customCode,
                ruleName: $this->getRuleName(),
                metadata: ['source' => $this->source, 'causes' => $inner->failures]
            );
        }

        return $inner;
    }

    /**
     * {@inheritdoc}
     *
     * Same decision as evaluate(); an evaluation error is thrown (its exception), as every leaf does.
     *
     * @throws Throwable The evaluation error (fetch failure, missing path, unknown source)
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        $result = $this->evaluate($candidate);
        if ($result->isError) {
            throw $result->exception ?? new \LogicException(sprintf('External data "%s" could not be evaluated.', $this->source));
        }

        return $result->isSatisfied;
    }

    /** {@inheritdoc} */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * {@inheritdoc}
     *
     * A leaf whose inner specification is a contradiction is one (no value can satisfy it).
     */
    public function isContradiction(): bool
    {
        return $this->specification->isContradiction();
    }

    /** {@inheritdoc} */
    public function __toString(): string
    {
        return sprintf("external('%s')", $this->source);
    }

    /**
     * The request, or the result that ends the evaluation before any fetch.
     */
    private function prepare(mixed $candidate): DataRequest|SpecificationResult
    {
        if ($this->parameters === []) {
            return new DataRequest($this->source, []);
        }

        if (!is_object($candidate) && !is_array($candidate)) {
            return SpecificationResult::failure(
                message: $this->customReason ?? sprintf("Invalid candidate for external data '%s'.", $this->source),
                code: $this->customCode,
                ruleName: $this->getRuleName()
            );
        }

        $values = [];
        foreach ($this->parameters as $name => $path) {
            try {
                if (!PropertyAccessor::hasProperty($candidate, $path)) {
                    throw new InvalidArgumentException(sprintf(
                        'Parameter "%s" of external data "%s": path "%s" not found or not accessible on candidate of type "%s".',
                        $name,
                        $this->source,
                        $path,
                        get_debug_type($candidate)
                    ));
                }
                $value = PropertyAccessor::getValue($candidate, $path);
            } catch (Throwable $e) {
                return SpecificationResult::error(exception: $e, ruleName: $this->getRuleName(), code: $this->customCode);
            }

            if ($value === null) {
                return SpecificationResult::failure(
                    message: $this->customReason ?? sprintf(
                        "Parameter '%s' of external data '%s' is null on candidate (path '%s').",
                        $name,
                        $this->source,
                        $path
                    ),
                    code: $this->customCode,
                    ruleName: $this->getRuleName()
                );
            }
            $values[$name] = $value;
        }

        try {
            return new DataRequest($this->source, $values);
        } catch (Throwable $e) {
            return SpecificationResult::error(exception: $e, ruleName: $this->getRuleName(), code: $this->customCode);
        }
    }

    /**
     * Data of the request: from the innermost resolution scope, or fetched now outside every scope.
     *
     * @throws Throwable The fetch failure
     */
    private function load(DataRequest $request): mixed
    {
        $scope = ResolutionScope::current();
        if ($scope !== null) {
            return $scope->valueFor($this, $request);
        }

        return DataSourceRegistry::default()->get($request->source)->fetch($request);
    }
}
