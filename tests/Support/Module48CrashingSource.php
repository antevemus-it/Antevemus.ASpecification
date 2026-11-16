<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Messaging\IMessageSource;
use Antevemus\ASpecification\Messaging\ReceivedMessage;
use Closure;

/**
 * A source of Module 48 (2.14.0, forward 063) whose consumer dies, when told, after the commit of the handler and
 * before the acknowledgement: ack() throws Module48Crash and the delivery stays in flight. Every answer is recorded.
 *
 * @version 2.14.0
 */
final class Module48CrashingSource implements IMessageSource
{
    /** @var (Closure(ReceivedMessage): bool)|null */
    public ?Closure $crashOnAck = null;

    /** @var list<string> */
    public array $answers = [];

    public function __construct(private readonly IMessageSource $inner)
    {
    }

    public function receive(int $max, int $waitMs): array
    {
        return $this->inner->receive($max, $waitMs);
    }

    public function ack(ReceivedMessage $message): void
    {
        if ($this->crashOnAck !== null && ($this->crashOnAck)($message)) {
            throw new Module48Crash('consumer died before acknowledging delivery ' . $message->brokerId);
        }
        $this->answers[] = 'ack ' . $message->brokerId;
        $this->inner->ack($message);
    }

    public function reject(ReceivedMessage $message, bool $requeue, string $reason): void
    {
        $this->answers[] = ($requeue ? 'requeue ' : 'dead ') . $message->brokerId;
        $this->inner->reject($message, $requeue, $reason);
    }
}
