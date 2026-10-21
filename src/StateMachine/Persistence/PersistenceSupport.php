<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * PersistenceSupport - Shared Conventions of the Machine Persistence Ports
 *
 * One place for the conventions every store follows, so that the InMemory and the PDO stores
 * answer byte for byte the same (forward 023, §4 item 4):
 * - Persisted instants (history, outbox, deadlines, instance timestamps) are UTC. Their text form
 *   is the fixed-width `YYYY-MM-DD HH:MM:SS.ffffff`, so that ordering and `<=` comparisons are
 *   lexicographic and identical in every database. The snapshot keeps its own lossless form
 *   (offsets included) in its JSON.
 * - JSON columns are encoded without escaped slashes or unicode and keep zero fractions.
 * - Error messages recorded by the outbox never carry a stack trace.
 * - A tenant (2.7.0) is a non-empty text without surrounding whitespace, at most 120 characters.
 *
 * @internal
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PersistenceSupport
{
    /** Text form of persisted instants (UTC, fixed width, lexicographically ordered). */
    public const TEXT_FORMAT = 'Y-m-d H:i:s.u';

    /** Maximum length of an instance id (column `instance_id`). */
    public const MAX_INSTANCE_ID_LENGTH = 120;

    /** Maximum length of a tenant (column `tenant`, 2.7.0). */
    public const MAX_TENANT_LENGTH = 120;

    /** Maximum length of a recorded error message (column `last_error`). */
    public const MAX_ERROR_LENGTH = 2000;

    private function __construct()
    {
    }

    /**
     * Returns the same instant in UTC.
     */
    public static function utc(DateTimeImmutable $instant): DateTimeImmutable
    {
        return $instant->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Text form of an instant (UTC, fixed width).
     */
    public static function toText(DateTimeImmutable $instant): string
    {
        return self::utc($instant)->format(self::TEXT_FORMAT);
    }

    /**
     * Reads the text form of an instant back (interpreted as UTC).
     *
     * @throws MachinePersistenceException When the text is not a date
     */
    public static function fromText(string $text): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($text, new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new MachinePersistenceException(sprintf('Stored instant "%s" is not a valid date.', $text));
        }
    }

    /**
     * Encodes a value as JSON.
     *
     * @throws MachinePersistenceException When the value cannot be encoded
     */
    public static function encodeJson(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (JsonException $e) {
            throw new MachinePersistenceException('Value cannot be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Decodes a JSON array or object stored by encodeJson().
     *
     * @param string $json Stored JSON
     * @param string $what Human description of the column (for the message)
     * @return array<mixed>
     * @throws MachinePersistenceException When the text is not a JSON array/object
     */
    public static function decodeJson(string $json, string $what): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MachinePersistenceException(sprintf('Stored %s is not valid JSON: %s', $what, $e->getMessage()), 0, $e);
        }
        if (!is_array($value)) {
            throw new MachinePersistenceException(sprintf('Stored %s must be a JSON array or object.', $what));
        }

        return $value;
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
        $text = $short . ': ' . $message;

        return mb_strlen($text) > self::MAX_ERROR_LENGTH ? mb_substr($text, 0, self::MAX_ERROR_LENGTH) : $text;
    }

    /**
     * Asserts an instance id is non-empty, without surrounding whitespace and at most
     * MAX_INSTANCE_ID_LENGTH characters.
     *
     * @throws InvalidArgumentException
     */
    public static function assertInstanceId(string $instanceId): string
    {
        if ($instanceId === '' || trim($instanceId) !== $instanceId || mb_strlen($instanceId) > self::MAX_INSTANCE_ID_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The machine instance id must be a non-empty string without surrounding whitespace and at most %d characters (got "%s").',
                self::MAX_INSTANCE_ID_LENGTH,
                $instanceId
            ));
        }

        return $instanceId;
    }

    /**
     * Asserts a tenant (2.7.0) is non-empty, without surrounding whitespace and at most
     * MAX_TENANT_LENGTH characters.
     *
     * @throws InvalidArgumentException
     */
    public static function assertTenant(string $tenant): string
    {
        if ($tenant === '' || trim($tenant) !== $tenant || mb_strlen($tenant) > self::MAX_TENANT_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The tenant of a machine instance must be a non-empty string without surrounding whitespace and at most %d characters (got "%s").',
                self::MAX_TENANT_LENGTH,
                $tenant
            ));
        }

        return $tenant;
    }
}
