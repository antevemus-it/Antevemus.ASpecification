<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents\Outbox;

use Antevemus\ASpecification\DomainEvents\Exceptions\DomainEventPersistenceException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * DomainEventSupport - Shared Conventions of the Domain Event Outbox
 *
 * The conventions of the 1.8.0 state machine persistence, applied to the domain event outbox so
 * that the InMemory and the PDO outboxes answer the same (forward 029, D5):
 * - Persisted instants are UTC, as the fixed-width text `YYYY-MM-DD HH:MM:SS.ffffff`, so that
 *   ordering and comparisons are lexicographic and identical in every database
 * - JSON columns are encoded without escaped slashes or unicode
 * - Error messages recorded by the outbox never carry a stack trace
 * - Names (trigger, event) and identifiers fit the reference DDL columns
 *
 * @internal
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents\Outbox
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DomainEventSupport
{
    /** Text form of persisted instants (UTC, fixed width, lexicographically ordered). */
    public const TEXT_FORMAT = 'Y-m-d H:i:s.u';

    /** Maximum length of a trigger or event name (columns `trigger_name`, `event_name`). */
    public const MAX_NAME_LENGTH = 120;

    /** Maximum length of an entity id (column `entity_id`). */
    public const MAX_ENTITY_ID_LENGTH = 190;

    /** Maximum length of an entity class (column `entity_class`). */
    public const MAX_ENTITY_CLASS_LENGTH = 255;

    /** Maximum length of a recorded error message (column `last_error`). */
    public const MAX_ERROR_LENGTH = 2000;

    private function __construct()
    {
    }

    /** Returns the same instant in UTC. */
    public static function utc(DateTimeImmutable $instant): DateTimeImmutable
    {
        return $instant->setTimezone(new DateTimeZone('UTC'));
    }

    /** Text form of an instant (UTC, fixed width). */
    public static function toText(DateTimeImmutable $instant): string
    {
        return self::utc($instant)->format(self::TEXT_FORMAT);
    }

    /**
     * Reads the text form of an instant back (interpreted as UTC).
     *
     * @throws DomainEventPersistenceException When the text is not a date
     */
    public static function fromText(string $text): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($text, new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new DomainEventPersistenceException(sprintf('Stored instant "%s" is not a valid date.', $text));
        }
    }

    /**
     * Encodes a value as JSON.
     *
     * @throws DomainEventPersistenceException When the value cannot be encoded
     */
    public static function encodeJson(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new DomainEventPersistenceException('Value cannot be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Error message of a failure as recorded by the outbox: `ShortClass: message`, cut before any
     * "Stack trace:" block and truncated to MAX_ERROR_LENGTH characters. Never a stack trace.
     */
    public static function errorMessage(Throwable $error): string
    {
        $message = $error->getMessage();
        $cut = stripos($message, 'Stack trace:');
        if ($cut !== false) {
            $message = rtrim(substr($message, 0, $cut));
        }
        $class = get_class($error);
        $short = ($pos = strrpos($class, '\\')) !== false ? substr($class, $pos + 1) : $class;

        return self::truncate($short . ': ' . $message);
    }

    /** Truncates a recorded error message to MAX_ERROR_LENGTH characters. */
    public static function truncate(string $text): string
    {
        return mb_strlen($text) > self::MAX_ERROR_LENGTH ? mb_substr($text, 0, self::MAX_ERROR_LENGTH) : $text;
    }

    /**
     * Asserts a name is non-empty, without surrounding whitespace and at most MAX_NAME_LENGTH characters.
     *
     * @param string $name Name to check
     * @param string $what Human description (for the message)
     * @throws InvalidArgumentException
     */
    public static function assertName(string $name, string $what): string
    {
        if ($name === '' || trim($name) !== $name || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The %s must be a non-empty string without surrounding whitespace and at most %d characters (got "%s").',
                $what,
                self::MAX_NAME_LENGTH,
                $name
            ));
        }

        return $name;
    }
}
