<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

use Antevemus\ASpecification\Resolution\Exceptions\DataResolutionException;
use Antevemus\ASpecification\Resolution\Exceptions\DataSourceNotFoundException;
use InvalidArgumentException;

/**
 * DataSourceRegistry - Data Sources by Name
 *
 * External data leaves name their source (`Spec::external('credit.score', ...)`); the registry maps
 * the name to the IDataSource. A DataResolver works with the registry given to its constructor (the
 * default registry when none is given). Outside a resolution scope, a leaf fetches its own data
 * through the default registry (`DataSourceRegistry::default()`), so a specification keeps working
 * without the resolver, only without concurrency (forward 026 D5).
 *
 * Names: letters, digits, `_`, `.`, `-` and `:`, starting with a letter or digit
 * (`credit.score`, `fraud-check`). A name is registered once: registering it again is refused, so a
 * source cannot be swapped by accident. An HTTP source is refused when `ext-curl` is not loaded
 * (forward 026 D12); callable sources work without it, and so do deferred sources
 * (IDeferredDataSource, 2.6.0): their transport is not the registry's concern.
 *
 * @version    2.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DataSourceRegistry
{
    private static ?self $default = null;

    /** @var array<string, IDataSource> */
    private array $sources = [];

    /**
     * The process-wide registry used by the leaves outside a resolution scope and by a DataResolver
     * built without a registry.
     */
    public static function default(): self
    {
        return self::$default ??= new self();
    }

    /**
     * Replaces the default registry (null resets it to a new, empty one on next use).
     */
    public static function setDefault(?self $registry): void
    {
        self::$default = $registry;
    }

    /**
     * True when the name is a valid source name.
     */
    public static function isValidName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\-]*$/', $name) === 1;
    }

    /**
     * Registers a source under a name.
     *
     * @return $this
     * @throws InvalidArgumentException When the name is not valid
     * @throws DataResolutionException When the name is already registered, or the source is an HTTP
     *                                 source and `ext-curl` is not loaded
     */
    public function register(string $name, IDataSource $source): self
    {
        if (!self::isValidName($name)) {
            throw new InvalidArgumentException(sprintf('Invalid data source name "%s".', $name));
        }
        if (isset($this->sources[$name])) {
            throw new DataResolutionException(sprintf('A data source is already registered under the name "%s".', $name));
        }
        if ($source instanceof IHttpDataSource && !CurlMultiTransport::isAvailable()) {
            throw new DataResolutionException(sprintf(
                'Data source "%s" is an HTTP source and needs the curl extension (ext-curl), which is not loaded.',
                $name
            ));
        }
        $this->sources[$name] = $source;

        return $this;
    }

    /**
     * True when a source is registered under the name.
     */
    public function has(string $name): bool
    {
        return isset($this->sources[$name]);
    }

    /**
     * The source registered under the name.
     *
     * @throws DataSourceNotFoundException When no source is registered under the name
     */
    public function get(string $name): IDataSource
    {
        return $this->sources[$name] ?? throw new DataSourceNotFoundException($name);
    }

    /**
     * Registered names, in registration order.
     *
     * @return list<string>
     */
    public function getNames(): array
    {
        return array_keys($this->sources);
    }
}
