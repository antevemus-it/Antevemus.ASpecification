<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\Support;

use Antevemus\ASpecification\Util\UlidGenerator;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * MessagingSupport - Shared Conventions of the Messaging Hook
 *
 * The conventions of the domain event outbox (1.11.0), applied to the messages (forward 063):
 * - Persisted instants are UTC, as the fixed-width text `YYYY-MM-DD HH:MM:SS.ffffff`, so that
 *   ordering and comparisons are lexicographic and identical in every database; on the wire
 *   (`Message::toArray()`) the same instant is `YYYY-MM-DDTHH:MM:SS.ffffffZ`
 * - JSON is encoded without escaped slashes or unicode and keeps the zero fraction of a float, so
 *   that a payload reads back exactly as it was written
 * - A recorded reason is at most MAX_REASON_LENGTH characters and never a stack trace; a failure of
 *   a handler is recorded by the short class name of its exception only, never by its message, which
 *   may quote the payload (RN-05); a failure of a transport keeps its message, which the contract of
 *   IMessageTransport forbids to carry the payload
 *
 * @internal
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\Support
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MessagingSupport
{
    /** Text form of persisted instants (UTC, fixed width, lexicographically ordered). */
    public const TEXT_FORMAT = 'Y-m-d H:i:s.u';

    /** Text form of an instant on the wire (UTC, ISO 8601 with microseconds and `Z`). */
    public const WIRE_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /** Flags of every JSON written by the hook. */
    public const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /** Maximum length of a recorded reason (column `last_error` of the outbox). */
    public const MAX_REASON_LENGTH = 2000;

    private function __construct()
    {
    }

    /** Returns the same instant in UTC. */
    public static function utc(DateTimeImmutable $instant): DateTimeImmutable
    {
        return $instant->setTimezone(new DateTimeZone('UTC'));
    }

    /** The current instant, in UTC (the default clock of the hook). */
    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** Text form of an instant (UTC, fixed width). */
    public static function toText(DateTimeImmutable $instant): string
    {
        return self::utc($instant)->format(self::TEXT_FORMAT);
    }

    /**
     * Reads the text form of an instant back, strictly (the exact fixed-width form, as UTC).
     *
     * @return DateTimeImmutable|null Null when the text is not in that form
     */
    public static function fromText(string $text, string $format = self::TEXT_FORMAT): ?DateTimeImmutable
    {
        $instant = DateTimeImmutable::createFromFormat('!' . $format, $text, new DateTimeZone('UTC'));
        if ($instant === false || $instant->format($format) !== $text) {
            return null;
        }

        return $instant;
    }

    /** True when the text is exactly a canonical ULID (26 characters, nothing around them). */
    public static function isUlid(string $text): bool
    {
        return strlen($text) === 26 && UlidGenerator::isValid($text);
    }

    /** Truncates a recorded reason to MAX_REASON_LENGTH characters. */
    public static function truncate(string $text): string
    {
        return mb_strlen($text) > self::MAX_REASON_LENGTH ? mb_substr($text, 0, self::MAX_REASON_LENGTH) : $text;
    }

    /** The class of a failure without its namespace (`RuntimeException`, `PDOException`...). */
    public static function shortClass(Throwable $error): string
    {
        $class = get_class($error);
        $anonymous = strpos($class, '@anonymous');
        if ($anonymous !== false) {
            $class = substr($class, 0, $anonymous) . '@anonymous';
        }
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
