<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Engine\Exceptions\RuleCatalogStoreException;
use Antevemus\ASpecification\Repositories\Pdo\PdoValueConverter;
use DateTimeImmutable;
use DateTimeInterface;
use JsonException;

/**
 * RuleSnapshot - The Canonical Photo of a Catalog Rule and Its Hash
 *
 * The form in which the history of the rule catalog keeps a version of a rule (2.13.0, forward 060, D5): a JSON
 * object with every canonical column of the rule table read by the catalog, `version` and `active` included, keys
 * in alphabetical order (COLUMNS), null written explicitly, and the hash `sha256:<64 hex>` of that text. The same
 * row gives the same text and the same hash in every database and in memory (RN-06):
 * - texts as stored (a blank `spec_json` is no body, null, as RuleDefinition::fromArray() reads it)
 * - `violation_action` as its English value (`block`, `warn`, `log`), read as the catalog reads it
 * - `int_value`, `priority` and `version` as integers
 * - `decimal_value` as canonical decimal text (PdoValueConverter::canonicalDecimal(): no trailing fractional zero,
 *   a float rounded to 15 significant digits), so `12.5000` (PostgreSQL, MySQL) and `12.5` (SQLite) agree
 * - `parameters` decoded and encoded again with the keys of every object in order (lists keep theirs); no
 *   parameters (NULL, blank, `{}`) is null
 * - `valid_from` and `valid_until` as ISO dates (`YYYY-MM-DD`)
 * - `active` as a boolean
 * - `spec_json` always present: null when the store does not map the column
 * - JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION (a float of the parameters keeps
 *   its `.0`, so 1.0 and 1 stay apart)
 *
 * The form is a contract, like FeatureFlags::bucket(): a photo written by one release is read and checked by every
 * other of the 2.x line, so changing it is a MAJOR change.
 *
 * fromStored() reads the values a store holds (a database row with canonical keys, or a row given to
 * InMemoryRuleCatalogStore::put()) strictly: a value it cannot read is a RuleCatalogStoreException that names the
 * rule and the column, never the value (RN-05).
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class RuleSnapshot
{
    /** The columns of the photo, in the order of its keys (alphabetical). */
    public const COLUMNS = [
        'active', 'code', 'condition_expression', 'decimal_value', 'description', 'int_value', 'legal_basis', 'name',
        'parameters', 'plan_code', 'priority', 'product_code', 'rule_type', 'scenario', 'scope', 'spec_json',
        'text_value', 'valid_from', 'valid_until', 'version', 'violation_action', 'violation_message',
    ];

    /** Prefix of every hash. */
    public const HASH_PREFIX = 'sha256:';

    /** Flags of json_encode() for the photo. */
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /** A decimal as a database returns it in text: digits with an optional fraction (`12.5000`, `.5`, `-3`). */
    private const DECIMAL = '/^-?(?:\d+(?:\.\d*)?|\.\d+)$/D';

    /** Text columns of the photo. */
    private const TEXTS = [
        'code', 'condition_expression', 'description', 'legal_basis', 'name', 'plan_code', 'product_code', 'rule_type',
        'scenario', 'scope', 'spec_json', 'text_value', 'violation_message',
    ];

    /**
     * The canonical row of a stored rule: every column of COLUMNS, in that order, in the form of the photo.
     *
     * @param array<string, mixed> $stored Values by canonical column, as a store holds them (`active` as 'Y'/'N'
     *                                     or a boolean, `parameters` as JSON text or an array); a missing
     *                                     `spec_json` is null
     * @return array<string, mixed>
     * @throws RuleCatalogStoreException When a value cannot be read
     */
    public static function fromStored(array $stored): array
    {
        $code = self::text($stored['code'] ?? null);
        if ($code === null || $code === '') {
            throw new RuleCatalogStoreException('A stored rule has no code.');
        }
        $row = [];
        foreach (self::COLUMNS as $column) {
            $raw = $stored[$column] ?? null;
            $row[$column] = match ($column) {
                'active' => self::storedActive($code, $raw),
                'decimal_value' => self::storedDecimal($code, $raw),
                'int_value' => self::storedInteger($code, $column, $raw),
                'priority' => self::storedInteger($code, $column, $raw ?? 0),
                'version' => self::storedVersion($code, $raw),
                'parameters' => self::storedParameters($code, $raw),
                'valid_from', 'valid_until' => self::storedDate($code, $column, $raw),
                'violation_action' => self::storedAction($code, $raw),
                'spec_json' => self::blankIsNull(self::text($raw)),
                default => self::text($raw),
            };
        }
        $row['code'] = $code;

        return $row;
    }

    /**
     * The photo of a canonical row: the JSON text of the history.
     *
     * @param array<string, mixed> $row A canonical row (fromStored(), or a decoded photo)
     * @return string
     * @throws RuleCatalogStoreException When a column is missing or a value cannot be encoded
     */
    public static function encode(array $row): string
    {
        $ordered = [];
        foreach (self::COLUMNS as $column) {
            if (!array_key_exists($column, $row)) {
                throw new RuleCatalogStoreException(sprintf('The photo of a rule needs the column %s.', $column), self::text($row['code'] ?? null));
            }
            $ordered[$column] = $row[$column];
        }
        try {
            return json_encode($ordered, self::JSON_FLAGS | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuleCatalogStoreException(sprintf('The photo of rule "%s" cannot be encoded as JSON (a text that is not UTF-8).', (string) self::text($row['code'] ?? null)), self::text($row['code'] ?? null), $e);
        }
    }

    /**
     * The canonical row of a photo (the inverse of encode()).
     *
     * @return array<string, mixed>
     * @throws RuleCatalogStoreException When the text is not the photo of a rule
     */
    public static function decode(string $snapshot): array
    {
        try {
            $row = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuleCatalogStoreException('A photo of the rule history is not valid JSON.', null, $e);
        }
        if (!is_array($row) || array_keys($row) !== self::COLUMNS) {
            throw new RuleCatalogStoreException('A photo of the rule history does not have the columns of a rule, in order.');
        }

        return $row;
    }

    /** The hash of a photo: `sha256:` and 64 hexadecimal digits. */
    public static function hash(string $snapshot): string
    {
        return self::HASH_PREFIX . hash('sha256', $snapshot);
    }

    /**
     * The rule of a canonical row, as PdoRuleCatalog reads it (with COLUMNS_SINCE_2_13 and, when the photo has a
     * body, COLUMNS_SINCE_2_4): the applicability keys inside `parameters` are dropped, as the catalog drops them.
     *
     * @param array<string, mixed> $row A canonical row
     */
    public static function definition(array $row): RuleDefinition
    {
        $parameters = is_array($row['parameters'] ?? null) ? $row['parameters'] : [];
        foreach (RuleDefinition::LEGACY_APPLICABILITY_COLUMNS as $column => $legacy) {
            unset($parameters[$column], $parameters[$legacy]);
        }
        $row['parameters'] = $parameters;

        return RuleDefinition::fromArray($row);
    }

    /**
     * The canonical form of the `parameters` of a rule: every object with its keys in order, lists as they are;
     * null when there are none.
     *
     * @param array<array-key, mixed> $parameters
     * @return array<array-key, mixed>|null
     */
    public static function canonicalParameters(array $parameters): ?array
    {
        return $parameters === [] ? null : self::sortKeys($parameters);
    }

    /**
     * The canonical text of a decimal (PdoValueConverter::canonicalDecimal()), or null.
     *
     * @return string|null Null when $raw is null
     */
    public static function canonicalDecimal(int|float|string|null $raw): ?string
    {
        return $raw === null ? null : PdoValueConverter::canonicalDecimal($raw);
    }

    /**
     * The text when it is a valid ISO date (`YYYY-MM-DD`, a day that exists), or null.
     */
    public static function isoDate(string $text): ?string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        return $parsed !== false && $parsed->format('Y-m-d') === $text ? $text : null;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    private static function text(mixed $raw): ?string
    {
        if (is_resource($raw)) {
            $raw = stream_get_contents($raw);
        }

        return $raw === null ? null : (string) $raw;
    }

    private static function blankIsNull(?string $text): ?string
    {
        return $text === null || trim($text) === '' ? null : $text;
    }

    private static function storedActive(string $code, mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        $text = is_string($raw) ? rtrim($raw, ' ') : null;
        if ($text === 'Y' || $text === 'N') {
            return $text === 'Y';
        }

        throw self::unreadable($code, 'active', "'Y' or 'N'");
    }

    /** An integer column: an int, or the text of one; null stays null. */
    private static function storedInteger(string $code, string $column, mixed $raw): ?int
    {
        if ($raw === null || is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^-?\d+$/D', $raw) === 1) {
            return (int) $raw;
        }

        throw self::unreadable($code, $column, 'an integer');
    }

    private static function storedVersion(string $code, mixed $raw): int
    {
        $version = self::storedInteger($code, 'version', $raw);
        if ($version === null || $version < 1) {
            throw self::unreadable($code, 'version', 'an integer of at least 1');
        }

        return $version;
    }

    private static function storedDecimal(string $code, mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (is_int($raw) || (is_float($raw) && is_finite($raw)) || (is_string($raw) && preg_match(self::DECIMAL, $raw) === 1)) {
            return self::canonicalDecimal($raw);
        }

        throw self::unreadable($code, 'decimal_value', 'a number or NULL');
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function storedParameters(string $code, mixed $raw): ?array
    {
        if (is_resource($raw)) {
            $raw = stream_get_contents($raw);
        }
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }
        if (is_string($raw)) {
            try {
                $raw = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuleCatalogStoreException(sprintf('Rule "%s": column parameters is not valid JSON.', $code), $code, $e);
            }
        }
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw self::unreadable($code, 'parameters', 'a JSON object or NULL');
        }

        return self::canonicalParameters($raw);
    }

    private static function storedDate(string $code, string $column, mixed $raw): ?string
    {
        if ($raw instanceof DateTimeInterface) {
            return $raw->format('Y-m-d');
        }
        if ($raw === null || $raw === '') {
            return null;
        }
        $date = is_string($raw) && preg_match('/^.{10}(?:$|[ T])/D', $raw) === 1 ? self::isoDate(substr($raw, 0, 10)) : null;
        if ($date !== null) {
            return $date;
        }

        throw self::unreadable($code, $column, 'an ISO date (YYYY-MM-DD) or NULL');
    }

    /**
     * The action as the catalog reads it (RuleAction::fromOrDefault(): a NULL or blank value is `block`, case and
     * surrounding spaces ignored), except that an unknown value is refused instead of read as `block`.
     */
    private static function storedAction(string $code, mixed $raw): string
    {
        if ($raw instanceof RuleAction) {
            return $raw->value;
        }
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return RuleAction::BLOCK->value;
        }
        $action = is_string($raw) ? RuleAction::tryFrom(strtolower(trim($raw))) : null;
        if ($action === null) {
            throw self::unreadable($code, 'violation_action', 'block, warn or log');
        }

        return $action->value;
    }

    private static function unreadable(string $code, string $column, string $expected): RuleCatalogStoreException
    {
        return new RuleCatalogStoreException(sprintf('Rule "%s": column %s holds a value the editor cannot read (expected %s).', $code, $column, $expected), $code);
    }
}
