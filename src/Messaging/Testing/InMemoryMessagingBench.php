<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\Testing;

use Antevemus\ASpecification\Messaging\Exceptions\MessagingException;
use Antevemus\ASpecification\Messaging\IMessageSource;
use Antevemus\ASpecification\Messaging\IMessageTransport;
use Antevemus\ASpecification\Messaging\InMemory\InMemoryMessageSource;
use Antevemus\ASpecification\Messaging\InMemory\InMemoryMessageTransport;
use Antevemus\ASpecification\Messaging\TransportSendResult;
use LogicException;

/**
 * InMemoryMessagingBench - The Reference of the Core Under the Contract Battery
 *
 * IMessagingBench over InMemoryMessageTransport and InMemoryMessageSource, unchanged (forward 064, D5): the
 * bench the core runs the battery against (Module 49), and the first one the author of a satellite runs, to
 * see the battery green before wiring a broker. Every instance is a broker of its own (a new log).
 *
 * - source() reads the whole log of the bench (the in-memory source does not filter by name: the battery
 *   never sends a name a source was not declared with);
 * - successor() and restarted() give the deliveries in flight back (InMemoryMessageSource::recoverInFlight(),
 *   what a dead consumer leaves) and return the same source;
 * - transportWithLostConnection() throws on every send(), as a lost connection does, and stores nothing.
 *
 * <code>
 * final class InMemoryContractTest extends MessagingContractTestCase
 * {
 *     protected function createBench(): IMessagingBench
 *     {
 *         return new InMemoryMessagingBench();
 *     }
 * }
 * </code>
 *
 * @version    2.15.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\Testing
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryMessagingBench implements IMessagingBench
{
    private readonly InMemoryMessageTransport $transport;

    /** @var array<int, InMemoryMessageSource> object id => a source of this bench */
    private array $sources = [];

    public function __construct()
    {
        $this->transport = new InMemoryMessageTransport();
    }

    /** {@inheritdoc} */
    public function transport(): IMessageTransport
    {
        return $this->transport;
    }

    /** {@inheritdoc} */
    public function transportWithLostConnection(): IMessageTransport
    {
        return new class () implements IMessageTransport {
            public function send(array $batch): TransportSendResult
            {
                throw new MessagingException(sprintf('The connection to the in-memory broker was lost: none of the %d message(s) of the batch was stored.', count($batch)));
            }
        };
    }

    /** {@inheritdoc} */
    public function source(string $consumer, array $names, string $member = 'm1'): IMessageSource
    {
        $source = new InMemoryMessageSource($this->transport);
        $this->sources[spl_object_id($source)] = $source;

        return $source;
    }

    /** {@inheritdoc} */
    public function successor(IMessageSource $source): IMessageSource
    {
        $own = $this->own($source);
        $own->recoverInFlight();

        return $own;
    }

    /** {@inheritdoc} */
    public function restarted(IMessageSource $source): IMessageSource
    {
        return $this->successor($source);
    }

    /** {@inheritdoc} */
    public function storeRaw(string $name, string $body): void
    {
        $this->transport->storeRaw($body);
    }

    /** {@inheritdoc} */
    public function deadLetters(IMessageSource $source): array
    {
        return array_map(
            static fn(array $dead): array => ['messageId' => $dead['messageId'], 'reason' => $dead['reason'], 'deliveryCount' => $dead['deliveryCount']],
            $this->own($source)->deadLetters()
        );
    }

    /** {@inheritdoc} */
    public function receiveWaitMs(): int
    {
        return 0;
    }

    /** {@inheritdoc} */
    public function close(): void
    {
        $this->sources = [];
    }

    /** @throws LogicException When the source was not created by this bench */
    private function own(IMessageSource $source): InMemoryMessageSource
    {
        return $this->sources[spl_object_id($source)] ?? throw new LogicException(sprintf('The source (%s) was not created by this bench.', $source::class));
    }
}
