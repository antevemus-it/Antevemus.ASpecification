<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Messaging\IMessageSource;
use Antevemus\ASpecification\Messaging\IMessageTransport;
use Antevemus\ASpecification\Messaging\InMemory\InMemoryMessageSource;
use Antevemus\ASpecification\Messaging\InMemory\InMemoryMessageTransport;
use Antevemus\ASpecification\Messaging\Message;
use Antevemus\ASpecification\Messaging\ReceivedMessage;
use Antevemus\ASpecification\Messaging\Testing\IMessagingBench;
use Antevemus\ASpecification\Messaging\TransportSendResult;
use LogicException;
use RuntimeException;

/**
 * The in-memory reference with one fault of Module49Fault planted (2.15.0, forward 064): Module 49 runs the whole
 * contract battery over it and requires the scenario of the broken item to fail on an assertion. A battery that a
 * broken broker passes proves nothing; this is how the battery itself is tested.
 *
 * @version 2.15.0
 */
final class Module49FaultyBench implements IMessagingBench
{
    private static ?InMemoryMessageTransport $shared = null;

    private readonly InMemoryMessageTransport $transport;

    /** @var array<int, Module49FaultySource> */
    private array $sources = [];

    private ?InMemoryMessageSource $common = null;

    public function __construct(public readonly Module49Fault $fault)
    {
        if ($fault === Module49Fault::SHARED_WORLD) {
            self::$shared ??= new InMemoryMessageTransport();
            $this->transport = self::$shared;
        } else {
            $this->transport = new InMemoryMessageTransport();
        }
    }

    public function transport(): IMessageTransport
    {
        $fault = $this->fault;
        $log = $this->transport;

        return match ($fault) {
            Module49Fault::CONFIRMS_WITHOUT_STORING => new class () implements IMessageTransport {
                public function send(array $batch): TransportSendResult
                {
                    return TransportSendResult::allConfirmed($batch);
                }
            },
            Module49Fault::DROPS_HEADERS => new class ($log) implements IMessageTransport {
                public function __construct(private readonly InMemoryMessageTransport $log)
                {
                }

                public function send(array $batch): TransportSendResult
                {
                    return $this->log->send(array_map(static fn(Message $m): Message => new Message($m->name, $m->payload, $m->orderingKey, $m->tenant, [], $m->messageId, $m->occurredAt), $batch));
                }
            },
            Module49Fault::DROPS_DUPLICATES => new class ($log) implements IMessageTransport {
                public function __construct(private readonly InMemoryMessageTransport $log)
                {
                }

                public function send(array $batch): TransportSendResult
                {
                    $stored = array_map(static fn(Message $m): string => $m->messageId, $this->log->stored());
                    $fresh = array_values(array_filter($batch, static fn(Message $m): bool => !in_array($m->messageId, $stored, true)));
                    if ($fresh !== []) {
                        $this->log->send($fresh);
                    }

                    return TransportSendResult::allConfirmed($batch);
                }
            },
            default => $log,
        };
    }

    public function transportWithLostConnection(): IMessageTransport
    {
        $log = $this->fault === Module49Fault::LOST_CONNECTION_STORES ? $this->transport : null;

        return new class ($log) implements IMessageTransport {
            public function __construct(private readonly ?InMemoryMessageTransport $log)
            {
            }

            public function send(array $batch): TransportSendResult
            {
                $this->log?->send($batch);

                throw new RuntimeException('The connection was lost.');
            }
        };
    }

    public function source(string $consumer, array $names, string $member = 'm1'): IMessageSource
    {
        $inner = $this->fault === Module49Fault::NO_FAN_OUT
            ? ($this->common ??= new InMemoryMessageSource($this->transport))
            : new InMemoryMessageSource($this->transport);
        $source = new Module49FaultySource($inner, $this->fault);
        $this->sources[spl_object_id($source)] = $source;

        return $source;
    }

    public function successor(IMessageSource $source): IMessageSource
    {
        $own = $this->own($source);
        $own->die();

        return $own;
    }

    public function restarted(IMessageSource $source): IMessageSource
    {
        return $this->successor($source);
    }

    public function storeRaw(string $name, string $body): void
    {
        $this->transport->storeRaw($body);
    }

    public function deadLetters(IMessageSource $source): array
    {
        return array_map(
            fn(array $dead): array => [
                'messageId' => $dead['messageId'],
                'reason' => $this->fault === Module49Fault::LOSES_THE_DEAD_LETTER_REASON ? 'rejected' : $dead['reason'],
                'deliveryCount' => $dead['deliveryCount'],
            ],
            $this->own($source)->inner->deadLetters()
        );
    }

    public function receiveWaitMs(): int
    {
        return 0;
    }

    public function close(): void
    {
        $this->sources = [];
    }

    private function own(IMessageSource $source): Module49FaultySource
    {
        return $this->sources[spl_object_id($source)] ?? throw new LogicException('not a source of this bench');
    }
}
