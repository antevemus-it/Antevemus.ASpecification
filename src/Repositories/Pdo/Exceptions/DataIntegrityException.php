<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Exceptions;

use InvalidArgumentException;

/**
 * DataIntegrityException - A Stored Value the Mapping Does Not Allow
 *
 * Thrown when a column holds a value that its mapping declares impossible, instead of reading it as
 * something it is not (since 1.14.0, forward 032, D4): a Boolean column stored as
 * BooleanStorage::Char that holds neither its true nor its false text (nor NULL) is an integrity error,
 * never a silent false. The message names the column, the property and the value (shortened to 64
 * characters). It extends InvalidArgumentException, as the other conversion errors of a read do.
 *
 * @version    1.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DataIntegrityException extends InvalidArgumentException
{
    /**
     * @param string $column Column holding the value
     * @param string $property Property the column is mapped to
     * @param mixed $storedValue The value read
     * @param string $expected What the mapping allows, for the message
     */
    public function __construct(
        private readonly string $column,
        private readonly string $property,
        private readonly mixed $storedValue,
        string $expected
    ) {
        $text = is_scalar($storedValue) ? var_export($storedValue, true) : get_debug_type($storedValue);
        if (strlen($text) > 64) {
            $text = substr($text, 0, 61) . '...';
        }
        parent::__construct(sprintf(
            'Data integrity error: column "%s" (property "%s") holds %s, which is not %s.',
            $column,
            $property,
            $text,
            $expected
        ));
    }

    /** The column holding the value. */
    public function getColumn(): string
    {
        return $this->column;
    }

    /** The property the column is mapped to. */
    public function getProperty(): string
    {
        return $this->property;
    }

    /** The value read from the column. */
    public function getStoredValue(): mixed
    {
        return $this->storedValue;
    }
}
