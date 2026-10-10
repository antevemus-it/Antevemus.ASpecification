<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\UseCase;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Results\FailureSeverity;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Spec;
use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * UseCaseRunner - Thin Executor of Use Cases
 *
 * run(useCase, request) (forward 024, D6), in this order:
 * 1. inbound part of the middlewares, in registration order (the first registered is the outermost);
 * 2. validation of the request: Spec::validateAttributes() plus every specification registered for
 *    the request's class (registered for a class, a parent class or an interface the request
 *    implements: `instanceof`, in registration order); each failure gets its effective severity
 *    (UseCaseResult::severityOf());
 * 3. any ERROR failure (or an evaluation error) → invalid() with all the validation failures, and
 *    the use case is NOT called (RN-03);
 * 4. execute();
 * 5. the WARNING/INFO failures of the validation are attached to the result's reason, whatever its
 *    status (RN-03);
 * 6. outbound part of the middlewares, in reverse order.
 *
 * Any Throwable that escapes (use case, middleware, validation configuration error such as an
 * unknown #[ValidateRule] operator) becomes failed('UNHANDLED_EXCEPTION', generic message); the
 * exception goes to the IUseCaseErrorReporter only (null implementation by default), never into
 * the result: no message, class, file, line or trace (RN-02). The exception behind an evaluation
 * error of the validation is reported as well, since the result only keeps a generic message.
 *
 * No mediator, no container, no handler registry (D9): the caller hands the use case to run().
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage UseCase
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class UseCaseRunner
{
    private readonly IUseCaseErrorReporter $reporter;

    /** @var list<IUseCaseMiddleware> */
    private array $middlewares = [];

    /** @var list<array{class-string, ISpecification}> */
    private array $requestSpecifications = [];

    /**
     * @param IUseCaseErrorReporter|null $reporter Where unhandled exceptions go (default: discarded)
     * @param iterable<IUseCaseMiddleware|callable(object, callable): UseCaseResult> $middlewares Pipeline, outermost first
     */
    public function __construct(?IUseCaseErrorReporter $reporter = null, iterable $middlewares = [])
    {
        $this->reporter = $reporter ?? new NullUseCaseErrorReporter();
        foreach ($middlewares as $middleware) {
            $this->addMiddleware($middleware);
        }
    }

    /**
     * Appends a middleware to the pipeline (it runs inside the ones registered before it).
     *
     * @param IUseCaseMiddleware|callable(object, callable): UseCaseResult $middleware Middleware or closure
     * @return self
     */
    public function addMiddleware(IUseCaseMiddleware|callable $middleware): self
    {
        $this->middlewares[] = $middleware instanceof IUseCaseMiddleware ? $middleware : new CallableMiddleware($middleware);

        return $this;
    }

    /**
     * Registers a specification evaluated against every request that is an instance of the class
     * (or interface), in addition to its attributes.
     *
     * @param class-string $requestClass Request class or interface
     * @param ISpecification $specification Specification evaluated with the request as candidate
     * @return self
     * @throws InvalidArgumentException When the class or interface does not exist
     */
    public function registerRequestSpecification(string $requestClass, ISpecification $specification): self
    {
        if (!class_exists($requestClass) && !interface_exists($requestClass)) {
            throw new InvalidArgumentException(sprintf('Request class or interface "%s" does not exist.', $requestClass));
        }
        $this->requestSpecifications[] = [$requestClass, $specification];

        return $this;
    }

    /** The error reporter. */
    public function getErrorReporter(): IUseCaseErrorReporter
    {
        return $this->reporter;
    }

    /**
     * Runs a use case. Never throws: see the class description.
     *
     * @template TPayload
     * @param IUseCase $useCase Use case
     * @param object $request Request object
     * @return UseCaseResult
     */
    public function run(IUseCase $useCase, object $request): UseCaseResult
    {
        $core = function (object $request) use ($useCase): UseCaseResult {
            $validation = $this->validate($request);
            if ($validation->isError && $validation->exception !== null) {
                $this->report($validation->exception, $useCase, $request);
            }
            $warnings = [];
            $blocking = $validation->isError;
            foreach ($validation->failures as $failure) {
                if ($failure->severity === FailureSeverity::ERROR) {
                    $blocking = true;
                } else {
                    $warnings[] = $failure;
                }
            }
            if ($blocking) {
                return UseCaseResult::invalid($validation);
            }

            $result = $useCase->execute($request);

            return $warnings === [] ? $result : $result->withWarnings($warnings);
        };

        $pipeline = array_reduce(
            array_reverse($this->middlewares),
            static fn(Closure $next, IUseCaseMiddleware $middleware): Closure
                => static fn(object $request): UseCaseResult => $middleware->process($request, $next),
            $core
        );

        try {
            return $pipeline($request);
        } catch (Throwable $e) {
            $this->report($e, $useCase, $request);

            return UseCaseResult::failed(UseCaseResult::CODE_UNHANDLED_EXCEPTION, UseCaseResult::UNHANDLED_EXCEPTION_MESSAGE);
        }
    }

    /**
     * Validates a request without running anything: attributes (Spec::validateAttributes()) and the
     * specifications registered for its class, combined, every failure with its effective severity.
     * The result may still hold the exception of an evaluation error (UseCaseResult drops it).
     *
     * @param object $request Request object
     * @return SpecificationResult
     */
    public function validate(object $request): SpecificationResult
    {
        $results = [Spec::validateAttributes($request)];
        foreach ($this->requestSpecifications as [$class, $specification]) {
            if (!$request instanceof $class) {
                continue;
            }
            $result = $specification->evaluate($request);
            if (!$result->isSatisfied && $result->failures === []) {
                // An unsatisfied verdict without a recorded failure still blocks: give it one.
                $result = SpecificationResult::failure(
                    message: 'The request does not satisfy a specification registered for its class.',
                    ruleName: get_class($specification),
                    metadata: ['request_class' => $class],
                    severity: FailureSeverity::ERROR
                );
            }
            $results[] = $result;
        }
        $combined = SpecificationResult::combine(...$results);
        if ($combined->failures === []) {
            return $combined;
        }
        $failures = array_map(
            static fn(SpecificationFailure $f): SpecificationFailure => $f->withSeverity(UseCaseResult::severityOf($f)),
            $combined->failures
        );

        return new SpecificationResult(false, $failures, $combined->isError, $combined->exception);
    }

    private function report(Throwable $error, IUseCase $useCase, object $request): void
    {
        try {
            $this->reporter->report($error, $useCase, $request);
        } catch (Throwable) {
            // A broken reporter never changes the outcome.
        }
    }
}
