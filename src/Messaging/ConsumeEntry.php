<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging;

/**
 * ConsumeEntry - One Delivery in a ConsumeReport
 *
 * The broker id of the delivery, the id, name and ordering key of its message (null for an invalid
 * delivery), its delivery count, what the consumer did with it and why (a reason for every outcome
 * but `handled` and `duplicate`; never a value of the payload, RN-05) (forward 063, D8).
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class ConsumeEntry
{
    public function __construct(
        public string $brokerId,
        public ?string $messageId,
        public ?string $name,
        public ?string $orderingKey,
        public int $deliveryCount,
        public ConsumeOutcome $outcome,
        public ?string $reason = null
    ) {
    }

    /**
     * JSON-friendly array form.
     *
     * @return array{broker_id: string, message_id: ?string, name: ?string, ordering_key: ?string, delivery_count: int, outcome: string, reason: ?string}
     */
    public function toArray(): array
    {
        return [
            'broker_id' => $this->brokerId,
            'message_id' => $this->messageId,
            'name' => $this->name,
            'ordering_key' => $this->orderingKey,
            'delivery_count' => $this->deliveryCount,
            'outcome' => $this->outcome->value,
            'reason' => $this->reason,
        ];
    }
}
