<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging;

use Antevemus\ASpecification\Messaging\Exceptions\InvalidMessageException;
use Antevemus\ASpecification\Messaging\Support\MessagingSupport;
use Antevemus\ASpecification\Util\UlidGenerator;
use DateTimeImmutable;
use JsonException;

/**
 * Message - A Fact of a Module, With Data, on Its Way to the Others
 *
 * The unit of the messaging hook (forward 063, D2). Unlike the DomainEvent of 1.11.0, which records
 * that an entity crossed a specification and carries no data, a message is a fact of the
 * application with a payload ("a message arrived in the live chat", "a quote was sent for
 * analysis"), published by one module and consumed by the others through a broker.
 *
 * - `name`: versioned, lowercase, `module.fact.vN` (`livechat.message_received.v1`): at least two
 *   segments of `[a-z][a-z0-9_]*` before the version, at most 120 characters (NAME_PATTERN). A
 *   breaking change of the payload is a new name (`.v2`), consumed side by side with the old one.
 * - `payload`: an array that JSON carries without loss (scalars, arrays and null; finite floats;
 *   valid UTF-8 strings and keys; at most 64 levels), at most MAX_PAYLOAD_BYTES (65 535) bytes
 *   once encoded: the size of a TEXT column of MySQL and MariaDB, the type of the payload column
 *   of the reference DDL. A larger payload is refused here, so it never reaches the outbox.
 * - `messageId`: a ULID, generated when not given; `idempotencyKey()` is `msg:` + the id, the key
 *   of the inbox of every consumer.
 * - `occurredAt`: the instant of the fact (UTC, microseconds), now when not given.
 * - `orderingKey`: the queue of order (`quote:123`): messages of one key reach a consumer in the
 *   order they were published; null = no order with any other message. At most 190 characters.
 * - `tenant`: who the fact belongs to (null = no tenant), at most 120 characters; it travels with
 *   the message and a consumer may be restricted to one (D10).
 * - `headers`: at most 32 string headers (`[A-Za-z][A-Za-z0-9._-]{0,63}` => at most 1 024 bytes of
 *   UTF-8 without control characters), for tracing and routing; never for data of the fact.
 *
 * `toArray()`/`fromArray()` and `toJson()`/`fromJson()` are the wire form, format version `v: 1`,
 * read back without loss (the same name, payload, id, instant to the microsecond, key, tenant and
 * headers); a document of another version, with a missing or an unknown key, is refused. The
 * transports of the satellites send this form.
 *
 * Secrecy (RN-05): no message of an exception of this class repeats a value of the payload or of a
 * header, nor a refused name, key or tenant; describe() is the text the hook uses to name a message
 * in a reason (its name, id and ordering key).
 *
 * <code>
 * $message = new Message('livechat.message_received.v1', ['chat_id' => 42, 'text' => 'Hello'], orderingKey: 'chat:42', tenant: 'acme');
 * $message->idempotencyKey(); // 'msg:' . $message->messageId
 * </code>
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class Message
{
    /** Versioned name: `module.fact.vN`, lowercase. */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+\.v[1-9][0-9]*$/D';

    /** Maximum length of a name (column `message_name`). */
    public const MAX_NAME_LENGTH = 120;

    /** Maximum size of the encoded payload, in bytes (a TEXT column of MySQL and MariaDB). */
    public const MAX_PAYLOAD_BYTES = 65535;

    /** Maximum nesting of the payload (the payload itself is level 1). */
    public const MAX_PAYLOAD_DEPTH = 64;

    /** Maximum length of an ordering key (column `ordering_key`). */
    public const MAX_ORDERING_KEY_LENGTH = 190;

    /** Maximum length of a tenant (column `tenant`). */
    public const MAX_TENANT_LENGTH = 120;

    /** Maximum number of headers. */
    public const MAX_HEADERS = 32;

    /** Name of a header. */
    public const HEADER_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9._-]{0,63}$/D';

    /** Maximum size of the value of a header, in bytes. */
    public const MAX_HEADER_VALUE_BYTES = 1024;

    /** Prefix of every message idempotency key. */
    public const KEY_PREFIX = 'msg:';

    /** Version of the wire form written by toArray(). */
    public const FORMAT_VERSION = 1;

    /** Keys of the wire form, in the order toArray() writes them. */
    public const WIRE_KEYS = ['v', 'id', 'name', 'occurred_at', 'ordering_key', 'tenant', 'headers', 'payload'];

    public string $name;

    /** @var array<array-key, mixed> */
    public array $payload;

    public string $messageId;

    public DateTimeImmutable $occurredAt;

    public ?string $orderingKey;

    public ?string $tenant;

    /** @var array<string, string> */
    public array $headers;

    private string $encodedPayload;

    /**
     * @param string $name Versioned name, `module.fact.vN`
     * @param array<array-key, mixed> $payload Data of the fact (JSON-safe, at most 65 535 bytes encoded)
     * @param string|null $orderingKey Queue of order; null = none
     * @param string|null $tenant Tenant of the fact; null = none
     * @param array<string, string> $headers Headers (at most 32)
     * @param string|null $messageId ULID; null = generated by the process-wide generator
     * @param DateTimeImmutable|null $occurredAt Instant of the fact; null = now (stored in UTC)
     * @throws InvalidMessageException When a value breaks a rule of the class description
     */
    public function __construct(
        string $name,
        array $payload = [],
        ?string $orderingKey = null,
        ?string $tenant = null,
        array $headers = [],
        ?string $messageId = null,
        ?DateTimeImmutable $occurredAt = null
    ) {
        if (!self::isValidName($name)) {
            throw new InvalidMessageException(sprintf(
                'The message name must be versioned and lowercase, module.fact.vN (for example crm.lead_created.v1), at most %d characters.',
                self::MAX_NAME_LENGTH
            ));
        }
        $this->name = $name;
        if ($messageId !== null && !MessagingSupport::isUlid($messageId)) {
            throw new InvalidMessageException(sprintf('The id of message "%s" must be a ULID (26 characters of Crockford base32, uppercase).', $name));
        }
        $this->messageId = $messageId ?? UlidGenerator::shared()->generate();
        self::assertJsonSafe($payload, 1, $name);
        try {
            $encoded = json_encode($payload, MessagingSupport::JSON_FLAGS);
        } catch (JsonException) {
            throw new InvalidMessageException(sprintf('The payload of message "%s" cannot be encoded as JSON.', $name));
        }
        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidMessageException(sprintf(
                'The payload of message "%s" has %d bytes once encoded, more than the %d allowed.',
                $name,
                strlen($encoded),
                self::MAX_PAYLOAD_BYTES
            ));
        }
        $this->payload = $payload;
        $this->encodedPayload = $encoded;
        $this->orderingKey = self::optionalText($orderingKey, self::MAX_ORDERING_KEY_LENGTH, 'ordering key', $name);
        $this->tenant = self::optionalText($tenant, self::MAX_TENANT_LENGTH, 'tenant', $name);
        $this->headers = self::validHeaders($headers, $name);
        $this->occurredAt = $occurredAt === null ? MessagingSupport::now() : MessagingSupport::utc($occurredAt);
    }

    /** True when the text is a valid message name (`module.fact.vN`, at most 120 characters). */
    public static function isValidName(string $name): bool
    {
        return strlen($name) <= self::MAX_NAME_LENGTH && preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /** Idempotency key of the message: `msg:` + its id (the key of the inbox). */
    public function idempotencyKey(): string
    {
        return self::KEY_PREFIX . $this->messageId;
    }

    /** The payload as the JSON text the outbox stores (no escaped slashes or unicode, zero fractions kept). */
    public function payloadJson(): string
    {
        return $this->encodedPayload;
    }

    /** Size of the encoded payload, in bytes. */
    public function payloadSize(): int
    {
        return strlen($this->encodedPayload);
    }

    /** Text that names the message in a reason or a log: its name, its id and its ordering key, never its payload. */
    public function describe(): string
    {
        return sprintf(
            'message "%s" (id %s%s)',
            $this->name,
            $this->messageId,
            $this->orderingKey === null ? '' : sprintf(', ordering key "%s"', $this->orderingKey)
        );
    }

    /**
     * The wire form, format version 1 (read back by fromArray() without loss).
     *
     * @return array{v: int, id: string, name: string, occurred_at: string, ordering_key: ?string, tenant: ?string, headers: array<string, string>, payload: array<array-key, mixed>}
     */
    public function toArray(): array
    {
        return [
            'v' => self::FORMAT_VERSION,
            'id' => $this->messageId,
            'name' => $this->name,
            'occurred_at' => $this->occurredAt->format(MessagingSupport::WIRE_FORMAT),
            'ordering_key' => $this->orderingKey,
            'tenant' => $this->tenant,
            'headers' => $this->headers,
            'payload' => $this->payload,
        ];
    }

    /** The wire form as JSON text (the body a transport sends; the headers always a JSON object). */
    public function toJson(): string
    {
        $wire = $this->toArray();
        $wire['headers'] = (object) $wire['headers'];

        return json_encode($wire, MessagingSupport::JSON_FLAGS);
    }

    /**
     * Reads the wire form back.
     *
     * `v`, `id`, `name`, `occurred_at` and `payload` are required; `ordering_key`, `tenant` and
     * `headers` may be absent (null, null, none); any other key is refused.
     *
     * @param array<mixed> $data Wire form
     * @throws InvalidMessageException When the document is not a message of format version 1
     */
    public static function fromArray(array $data): self
    {
        $unknown = array_diff(array_keys($data), self::WIRE_KEYS);
        if ($unknown !== []) {
            throw new InvalidMessageException(sprintf('A message document has %d unknown key(s); the keys of format version %d are: %s.', count($unknown), self::FORMAT_VERSION, implode(', ', self::WIRE_KEYS)));
        }
        if (($data['v'] ?? null) !== self::FORMAT_VERSION) {
            throw new InvalidMessageException(sprintf('A message document must declare "v": %d.', self::FORMAT_VERSION));
        }
        foreach (['id', 'name', 'occurred_at'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new InvalidMessageException(sprintf('A message document must have the text "%s".', $key));
            }
        }
        if (!array_key_exists('payload', $data) || !is_array($data['payload'])) {
            throw new InvalidMessageException('A message document must have the array "payload".');
        }
        foreach (['ordering_key', 'tenant'] as $key) {
            if (isset($data[$key]) && !is_string($data[$key])) {
                throw new InvalidMessageException(sprintf('The "%s" of a message document must be a text or null.', $key));
            }
        }
        $headers = $data['headers'] ?? [];
        if (!is_array($headers)) {
            throw new InvalidMessageException('The "headers" of a message document must be an object of texts.');
        }
        $occurredAt = MessagingSupport::fromText($data['occurred_at'], MessagingSupport::WIRE_FORMAT)
            ?? throw new InvalidMessageException('The "occurred_at" of a message document must be YYYY-MM-DDTHH:MM:SS.ffffffZ (UTC).');

        return new self(
            $data['name'],
            $data['payload'],
            $data['ordering_key'] ?? null,
            $data['tenant'] ?? null,
            $headers,
            $data['id'],
            $occurredAt
        );
    }

    /**
     * Reads the JSON wire form back.
     *
     * @throws InvalidMessageException When the text is not the JSON of a message of format version 1
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, self::MAX_PAYLOAD_DEPTH + 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidMessageException(sprintf('A message document of %d bytes is not valid JSON, or its payload is nested deeper than %d levels.', strlen($json), self::MAX_PAYLOAD_DEPTH));
        }
        if (!is_array($data)) {
            throw new InvalidMessageException('A message document must be a JSON object.');
        }

        return self::fromArray($data);
    }

    /**
     * @throws InvalidMessageException
     */
    private static function assertJsonSafe(array $value, int $depth, string $name): void
    {
        if ($depth > self::MAX_PAYLOAD_DEPTH) {
            throw new InvalidMessageException(sprintf('The payload of message "%s" is nested deeper than %d levels.', $name, self::MAX_PAYLOAD_DEPTH));
        }
        foreach ($value as $key => $item) {
            if (is_string($key) && !mb_check_encoding($key, 'UTF-8')) {
                throw new InvalidMessageException(sprintf('The payload of message "%s" has a key that is not valid UTF-8.', $name));
            }
            if (is_array($item)) {
                self::assertJsonSafe($item, $depth + 1, $name);
                continue;
            }
            $problem = match (true) {
                $item === null, is_bool($item), is_int($item) => null,
                is_float($item) => is_finite($item) ? null : 'a float that is not finite (INF or NAN)',
                is_string($item) => mb_check_encoding($item, 'UTF-8') ? null : 'a text that is not valid UTF-8',
                default => sprintf('a value of type %s (only scalars, arrays and null travel)', get_debug_type($item)),
            };
            if ($problem !== null) {
                throw new InvalidMessageException(sprintf('The payload of message "%s" holds %s.', $name, $problem));
            }
        }
    }

    /**
     * @throws InvalidMessageException
     */
    private static function optionalText(?string $text, int $max, string $what, string $name): ?string
    {
        if ($text === null) {
            return null;
        }
        if ($text === '' || trim($text) !== $text || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > $max || preg_match('/[\x00-\x1F\x7F]/', $text) === 1) {
            throw new InvalidMessageException(sprintf(
                'The %s of message "%s" must be null or a non-empty UTF-8 text without surrounding whitespace nor control characters, at most %d characters.',
                $what,
                $name,
                $max
            ));
        }

        return $text;
    }

    /**
     * @param array<mixed> $headers
     * @return array<string, string>
     * @throws InvalidMessageException
     */
    private static function validHeaders(array $headers, string $name): array
    {
        if (count($headers) > self::MAX_HEADERS) {
            throw new InvalidMessageException(sprintf('Message "%s" has %d headers, more than the %d allowed.', $name, count($headers), self::MAX_HEADERS));
        }
        foreach ($headers as $header => $value) {
            if (!is_string($header) || preg_match(self::HEADER_NAME_PATTERN, $header) !== 1) {
                throw new InvalidMessageException(sprintf('Every header name of message "%s" must match %s.', $name, self::HEADER_NAME_PATTERN));
            }
            if (!is_string($value) || strlen($value) > self::MAX_HEADER_VALUE_BYTES || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidMessageException(sprintf(
                    'Header "%s" of message "%s" must be a UTF-8 text without control characters, at most %d bytes.',
                    $header,
                    $name,
                    self::MAX_HEADER_VALUE_BYTES
                ));
            }
        }

        return $headers;
    }
}
