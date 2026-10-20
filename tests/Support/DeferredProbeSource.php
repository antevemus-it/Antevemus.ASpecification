<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\IDeferredDataSource;
use Antevemus\ASpecification\Resolution\IStageTransport;
use Closure;
use LogicException;

/**
 * A deferred data source for the tests of the stage transport hook (forward 045, 2.6.0): its value comes
 * from a closure of the request parameters; the unitary fetch() (the path outside a resolution scope)
 * records each request it receives, so a test proves the resolver never takes it. Without a transport,
 * getTransport() throws.
 *
 * @version    2.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Support
 */
final class DeferredProbeSource implements IDeferredDataSource
{
    /** @var list<DataRequest> requests received by the unitary fetch() */
    public array $unitaryFetches = [];

    /**
     * @param IStageTransport|null $transport Transport of the source (null: getTransport() throws)
     * @param Closure(array<string, string|int|float|bool>): mixed $value Value of a request
     * @param int $cost Relative cost
     * @param int|null $ttl Cache TTL in seconds
     */
    public function __construct(
        private readonly ?IStageTransport $transport,
        private readonly Closure $value,
        private readonly int $cost = 0,
        private readonly ?int $ttl = null
    ) {
    }

    /** The value of a request, without recording it (what the fake transport answers). */
    public function valueOf(DataRequest $request): mixed
    {
        return ($this->value)($request->parameters);
    }

    /** {@inheritdoc} */
    public function fetch(DataRequest $request): mixed
    {
        $this->unitaryFetches[] = $request;

        return $this->valueOf($request);
    }

    /** {@inheritdoc} */
    public function getCost(): int
    {
        return $this->cost;
    }

    /** {@inheritdoc} */
    public function getTtl(): ?int
    {
        return $this->ttl;
    }

    /** {@inheritdoc} */
    public function getTransport(): IStageTransport
    {
        return $this->transport ?? throw new LogicException('probe without a transport');
    }
}
