<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Cache;

use InvalidArgumentException;

/**
 * CacheKeys - The Keys the Caching Decorators Write to a PSR-16 Cache
 *
 * Every key is `<prefix><body>`. The prefix is configurable (default `aspec.`) and the body is built
 * here from short names and hashes, so that a key is valid for every PSR-16 implementation: only the
 * characters PSR-16 requires every implementation to accept (`A-Z`, `a-z`, `0-9`, `_` and `.`), none
 * of the reserved ones (`{}()/\@:`), and a body of at most 64 characters. Values that come from the
 * application (a scope, a flag code, a repository id) never reach a key as text: they are hashed.
 *
 * The bodies, by decorator:
 * - `catalog.<32 hex>.s1`: one read of CachedRuleCatalog (the hash covers the generation, the catalog
 *   version, the method and its arguments); `catalog.gen.<32 hex>`: the generation of a catalog, by catalog id
 * - `flags.all.<32 hex>.s1` and `flags.one.<32 hex>.s1`: loadAll() and find() of CachedFeatureFlagStore
 * - `dag.<16 hex>.<32 hex>.s1`: the routing clusters of one node of a partitioned repository, by
 *   repository id and fingerprint of the node's partitions (CachedPartitionIndex)
 *
 * `s1` is the version of the stored format: a release that changes the format changes it, so an entry
 * written by an older release is never read as a newer one.
 *
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Cache
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class CacheKeys
{
    /** The prefix of every key when none is given. */
    public const DEFAULT_PREFIX = 'aspec.';

    /** The longest body after the prefix (PSR-16: every implementation accepts keys of up to 64 characters). */
    public const MAX_BODY_LENGTH = 64;

    /** The longest prefix accepted. */
    public const MAX_PREFIX_LENGTH = 64;

    /** The characters every PSR-16 implementation must accept in a key. */
    private const SAFE = '/^[A-Za-z0-9_.]*$/';

    private function __construct()
    {
    }

    /**
     * Checks a prefix and returns it.
     *
     * @throws InvalidArgumentException When the prefix has a character outside `A-Z a-z 0-9 _ .` or is longer than 64 characters
     */
    public static function prefix(string $prefix): string
    {
        if (strlen($prefix) > self::MAX_PREFIX_LENGTH || preg_match(self::SAFE, $prefix) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'The cache key prefix "%s" must have at most %d characters among A-Z, a-z, 0-9, "_" and ".".',
                $prefix,
                self::MAX_PREFIX_LENGTH
            ));
        }

        return $prefix;
    }

    /**
     * The key `<prefix><segment>.<segment>...`, checked.
     *
     * @throws InvalidArgumentException When a segment is empty or has an unsafe character, or the body is longer than 64 characters
     */
    public static function key(string $prefix, string ...$segments): string
    {
        foreach ($segments as $segment) {
            if ($segment === '' || preg_match('/^[A-Za-z0-9_]+$/', $segment) !== 1) {
                throw new InvalidArgumentException(sprintf('The cache key segment "%s" must be made of A-Z, a-z, 0-9 and "_".', $segment));
            }
        }
        $body = implode('.', $segments);
        if (strlen($body) > self::MAX_BODY_LENGTH) {
            throw new InvalidArgumentException(sprintf('The cache key "%s" is longer than %d characters after the prefix.', $body, self::MAX_BODY_LENGTH));
        }

        return self::prefix($prefix) . $body;
    }

    /**
     * The hash of a list of strings, integers and nulls, as 32 hexadecimal characters (xxh128 of its JSON).
     *
     * @param list<string|int|null> $parts
     */
    public static function hash(array $parts): string
    {
        return hash('xxh128', json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** The short hash of a text, as 16 hexadecimal characters (xxh64). */
    public static function shortHash(string $text): string
    {
        return hash('xxh64', $text);
    }
}
