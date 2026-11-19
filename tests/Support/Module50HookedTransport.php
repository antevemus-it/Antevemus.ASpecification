<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Messaging\IMessageTransport;
use Antevemus\ASpecification\Messaging\TransportSendResult;
use Closure;

/**
 * The broker as one relay process of Module 50 (2.16.0, forward 065) reaches it: send() calls the hook before (`send`)
 * and after the broker answered (`sent`, so a process can die between the confirmation and the mark), and the decision
 * (confirmed and stored, or refused) is taken by the property for each message id.
 *
 * @version 2.16.0
 */
final class Module50HookedTransport implements IMessageTransport
{
    /**
     * @param Closure(string, string, mixed): void $hook (operation, owner, batch)
     * @param Closure(string, string): bool $broker Delivers and answers true, or answers false (owner, message id)
     */
    public function __construct(private readonly string $owner, private readonly Closure $hook, private readonly Closure $broker)
    {
    }

    public function send(array $batch): TransportSendResult
    {
        ($this->hook)('send', $this->owner, $batch);
        $result = new TransportSendResult();
        foreach ($batch as $message) {
            $result = ($this->broker)($this->owner, $message->messageId)
                ? $result->withConfirmed($message->messageId)
                : $result->withFailed($message->messageId, 'the broker refused it');
        }
        ($this->hook)('sent', $this->owner, $batch);

        return $result;
    }
}
