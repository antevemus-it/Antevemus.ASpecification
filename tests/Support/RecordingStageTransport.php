<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Resolution\CurlMultiTransport;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\IStageTransport;
use ArrayObject;
use Throwable;

/**
 * A fake stage transport (forward 045, 2.6.0): answers each request it is handed with the value of its
 * DeferredProbeSource, unless the script says otherwise for that request (by request key or by source
 * name): a value, a Throwable (returned as the result of the request) or OMIT (no result for the key).
 * It can sleep before answering, throw from the whole call, and answer an extra key nobody asked. Every
 * call is recorded with its keys, the source names, the deadline received and the monotonic instant of
 * the call; when an event log is shared, the call appends "transport:<name>" to it.
 *
 * @version    2.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Support
 */
final class RecordingStageTransport implements IStageTransport
{
    /** Script outcome: answer nothing for the request. */
    public const OMIT = "\0omit";

    /** @var list<array{keys: list<string>, sources: list<string>, deadline: ?float, at: float}> */
    public array $calls = [];

    /** @var array<string, mixed> outcome by request key or source name */
    private array $script = [];

    /**
     * @param string $name Name written to the event log
     * @param ArrayObject<int, string>|null $log Event log shared with other sources of a scenario
     * @param int $sleepMs Milliseconds slept before answering
     * @param Throwable|null $crash Thrown by fetchAll() itself, after recording the call
     * @param bool $extraKey Also answers a key that was not asked
     */
    public function __construct(
        public readonly string $name = 't',
        private readonly ?ArrayObject $log = null,
        private readonly int $sleepMs = 0,
        private readonly ?Throwable $crash = null,
        private readonly bool $extraKey = false
    ) {
    }

    /**
     * Scripts the outcome of a request, by its key or by the name of its source: a value, a Throwable or OMIT.
     *
     * @return $this
     */
    public function script(string $keyOrSource, mixed $outcome): self
    {
        $this->script[$keyOrSource] = $outcome;

        return $this;
    }

    /** {@inheritdoc} */
    public function fetchAll(array $pending, ?float $deadline): array
    {
        $this->calls[] = [
            'keys' => array_map('strval', array_keys($pending)),
            'sources' => array_values(array_map(static fn(array $p): string => $p[1]->source, $pending)),
            'deadline' => $deadline,
            'at' => CurlMultiTransport::now(),
        ];
        $this->log?->append('transport:' . $this->name);
        if ($this->sleepMs > 0) {
            usleep($this->sleepMs * 1000);
        }
        if ($this->crash !== null) {
            throw $this->crash;
        }
        $results = [];
        foreach ($pending as $key => [$source, $request]) {
            $outcome = $this->outcome((string) $key, $source, $request);
            if ($outcome !== self::OMIT) {
                $results[$key] = $outcome;
            }
        }
        if ($this->extraKey) {
            $results['not.asked#0'] = 'ignored';
        }

        return $results;
    }

    /** Number of fetchAll() calls. */
    public function callCount(): int
    {
        return count($this->calls);
    }

    private function outcome(string $key, mixed $source, DataRequest $request): mixed
    {
        if (array_key_exists($key, $this->script)) {
            return $this->script[$key];
        }
        if (array_key_exists($request->source, $this->script)) {
            return $this->script[$request->source];
        }

        return $source instanceof DeferredProbeSource ? $source->valueOf($request) : $source->fetch($request);
    }
}
