<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;

/**
 * DefinitionSupport - Shared helpers of the machine definition model.
 *
 * Name validation, JSON-friendly parameter validation, canonical array form (recursive key sort
 * of associative arrays, lists kept in order) and deterministic transition id generation. Kept
 * in one place so that every definition class applies exactly the same rules.
 *
 * @internal
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DefinitionSupport
{
    private function __construct()
    {
    }

    /**
     * Asserts a name is a non-empty string without surrounding whitespace. When $noDots is true the
     * name must not contain a dot (region and state names: `inState('region.state')` splits on it).
     *
     * @param string $name Name to check
     * @param string $what Human description of the name (e.g. "region name")
     * @param bool $noDots Whether dots are forbidden
     * @return string The name
     * @throws InvalidMachineDefinitionException
     */
    public static function assertName(string $name, string $what, bool $noDots = false): string
    {
        if ($name === '' || trim($name) !== $name) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('The %s must be a non-empty string without surrounding whitespace (got "%s").', $what, $name)
            );
        }
        if (preg_match('/^(0|-?[1-9][0-9]*)$/', $name) === 1) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('The %s "%s" must not be an integer string: PHP would turn it into an integer array key.', $what, $name)
            );
        }
        if ($noDots && str_contains($name, '.')) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('The %s "%s" must not contain a dot (dots separate region and state in inState).', $what, $name)
            );
        }

        return $name;
    }

    /**
     * Asserts a parameter array only holds JSON-friendly values: null, bool, int, float (finite),
     * string and arrays of those. Closures, objects and resources are refused: the definition is
     * data (forward 023, D3).
     *
     * @param array<mixed> $params Parameters to check
     * @param string $where Human description of the owner (for the message)
     * @return array<mixed> The parameters
     * @throws InvalidMachineDefinitionException
     */
    public static function assertJsonFriendly(array $params, string $where): array
    {
        array_walk_recursive($params, static function (mixed $value, int|string $key) use ($where): void {
            $ok = $value === null || is_bool($value) || is_int($value) || is_string($value)
                || (is_float($value) && is_finite($value));
            if (!$ok) {
                throw InvalidMachineDefinitionException::single(
                    MachineDefinitionError::KIND_MALFORMED,
                    sprintf(
                        'Parameter "%s" of %s is not JSON-friendly (%s): only null, bool, int, finite float, string and arrays of those are allowed.',
                        (string) $key,
                        $where,
                        get_debug_type($value)
                    )
                );
            }
        });

        return $params;
    }

    /**
     * Returns the canonical form of a value: associative arrays sorted by key (recursively), lists
     * kept in their order. Used by the fingerprint, so that key order never changes it.
     *
     * @param mixed $value Value to canonicalize
     * @return mixed
     */
    public static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }
        if (!array_is_list($out)) {
            ksort($out, SORT_STRING);
        }

        return $out;
    }

    /**
     * Encodes a value as canonical JSON (canonical key order, unescaped slashes and unicode).
     *
     * @param mixed $value Value to encode
     * @return string
     */
    public static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /**
     * Generates a deterministic transition id when the author did not give one:
     * `region.state.EVENT` (or `region.*.EVENT` for region-level transitions), suffixed with
     * `#2`, `#3`... when the same base is already taken.
     *
     * @param string $region Region name
     * @param string|null $state State name, null for a region-level transition
     * @param string $event Event type
     * @param array<string, true> $taken Ids already used (updated by reference)
     * @return string
     */
    public static function generateTransitionId(string $region, ?string $state, string $event, array &$taken): string
    {
        $base = sprintf('%s.%s.%s', $region, $state ?? '*', $event);
        $id = $base;
        $n = 2;
        while (isset($taken[$id])) {
            $id = $base . '#' . $n++;
        }
        $taken[$id] = true;

        return $id;
    }

    /**
     * Reads a required string field of a definition array.
     *
     * @param array<string, mixed> $data Definition array
     * @param string $field Field name
     * @param string $where Human description of the owner
     * @return string
     * @throws InvalidMachineDefinitionException
     */
    public static function requireString(array $data, string $field, string $where): string
    {
        if (!isset($data[$field]) || !is_string($data[$field])) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('Field "%s" of %s is required and must be a string.', $field, $where)
            );
        }

        return $data[$field];
    }

    /**
     * Reads an optional list field of a definition array.
     *
     * @param array<string, mixed> $data Definition array
     * @param string $field Field name
     * @param string $where Human description of the owner
     * @return list<mixed>
     * @throws InvalidMachineDefinitionException
     */
    public static function optionalList(array $data, string $field, string $where): array
    {
        if (!array_key_exists($field, $data) || $data[$field] === null) {
            return [];
        }
        if (!is_array($data[$field]) || !array_is_list($data[$field])) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('Field "%s" of %s must be a list.', $field, $where)
            );
        }

        return $data[$field];
    }
}
