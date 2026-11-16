<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging;

/**
 * ConsumeReport - Outcome of One MessageConsumer::consume() Run
 *
 * One ConsumeEntry per delivery received, in the order they were received (forward 063, D8): the
 * handled ones, the duplicates, the names without a handler (warnings()), the deliveries of another
 * tenant (D10), the retried and held back ones and the dead letters, each with its reason. Never a
 * value of the payload (RN-05).
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class ConsumeReport
{
    /**
     * @param list<ConsumeEntry> $entries One per delivery, in the order received
     */
    public function __construct(private readonly array $entries = [])
    {
    }

    /**
     * Every delivery, in the order received.
     *
     * @return list<ConsumeEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /** Number of deliveries received. */
    public function receivedCount(): int
    {
        return count($this->entries);
    }

    /** Number of deliveries with an outcome. */
    public function countOf(ConsumeOutcome $outcome): int
    {
        return count($this->of($outcome));
    }

    /**
     * The deliveries with an outcome, in the order received.
     *
     * @return list<ConsumeEntry>
     */
    public function of(ConsumeOutcome $outcome): array
    {
        return array_values(array_filter($this->entries, static fn(ConsumeEntry $e): bool => $e->outcome === $outcome));
    }

    /**
     * Ids of the messages handled, in the order they were handled.
     *
     * @return list<string>
     */
    public function handledIds(): array
    {
        return array_map(static fn(ConsumeEntry $e): string => (string) $e->messageId, $this->of(ConsumeOutcome::HANDLED));
    }

    /**
     * Reason by broker id of the dead letters.
     *
     * @return array<string, string>
     */
    public function deadLetters(): array
    {
        $dead = [];
        foreach ($this->of(ConsumeOutcome::DEAD_LETTERED) as $entry) {
            $dead[$entry->brokerId] = (string) $entry->reason;
        }

        return $dead;
    }

    /**
     * The warnings of the run: one per message whose name has no handler.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return array_map(static fn(ConsumeEntry $e): string => (string) $e->reason, $this->of(ConsumeOutcome::UNKNOWN_NAME));
    }

    /**
     * JSON-friendly array form.
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn(ConsumeEntry $e): array => $e->toArray(), $this->entries);
    }
}
