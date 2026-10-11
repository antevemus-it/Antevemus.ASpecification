<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

use BackedEnum;
use InvalidArgumentException;
use Stringable;

/**
 * DataRequest - One Piece of External Data a Specification Needs
 *
 * The name of a registered data source plus the parameters already resolved from the candidate
 * (`['document' => '123.456.789-00']`). Requests are values: two requests with the same source and
 * the same parameters, in any order, have the same canonical key, and a resolution fetches each key
 * once (deduplication across the whole tree, forward 026 D2).
 *
 * Parameters: names are PHP identifiers (they are the `{name}` placeholders of an URL template);
 * values are strings, integers, finite floats or booleans. A `Stringable` becomes its string and a
 * `BackedEnum` its value. `null`, arrays and other objects are refused (the leaf reports a null
 * parameter as a rule failure before a request is built).
 *
 * Canonical key: `<source>#<sha-256 of the JSON of the parameters sorted by name>`. Deterministic
 * and stable across processes, so it is also the cache key (IDataCache); the hash keeps parameter
 * values (a CPF, an account number) out of cache keys and logs.
 *
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DataRequest
{
    /**
     * Parameters sorted by name.
     *
     * @var array<string, string|int|float|bool>
     */
    public readonly array $parameters;

    private readonly string $key;

    /**
     * @param string $source Name of the data source (see DataSourceRegistry::isValidName())
     * @param array<string, mixed> $parameters Resolved parameters, by name
     * @throws InvalidArgumentException When the source name, a parameter name or a value is not valid
     */
    public function __construct(public readonly string $source, array $parameters = [])
    {
        if (!DataSourceRegistry::isValidName($source)) {
            throw new InvalidArgumentException(sprintf('Invalid data source name "%s".', $source));
        }

        $normalized = [];
        foreach ($parameters as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid parameter name for data source "%s": a PHP identifier is required.',
                    $source
                ));
            }
            $normalized[$name] = self::normalize($value, $source, $name);
        }
        ksort($normalized, SORT_STRING);
        $this->parameters = $normalized;

        $json = json_encode(
            $normalized,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
        $this->key = $source . '#' . hash('sha256', $json);
    }

    /**
     * Canonical key: same source and same parameters (in any order) give the same key.
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Value of one parameter.
     *
     * @throws InvalidArgumentException When the request has no parameter with that name
     */
    public function get(string $name): string|int|float|bool
    {
        if (!array_key_exists($name, $this->parameters)) {
            throw new InvalidArgumentException(sprintf(
                'The request to data source "%s" has no parameter "%s".',
                $this->source,
                $name
            ));
        }

        return $this->parameters[$name];
    }

    /**
     * True when the other request has the same canonical key.
     */
    public function equals(self $other): bool
    {
        return $this->key === $other->key;
    }

    /**
     * @return string|int|float|bool
     */
    private static function normalize(mixed $value, string $source, string $name): string|int|float|bool
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof Stringable) {
            $value = (string) $value;
        }

        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException(sprintf(
                'Parameter "%s" of data source "%s" must be a finite number.',
                $name,
                $source
            ));
        }

        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        throw new InvalidArgumentException(sprintf(
            'Parameter "%s" of data source "%s" must be a string, an integer, a float or a boolean (%s given).',
            $name,
            $source,
            get_debug_type($value)
        ));
    }
}
