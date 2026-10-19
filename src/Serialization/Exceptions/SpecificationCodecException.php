<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Serialization\Exceptions;

use RuntimeException;
use Throwable;

/**
 * SpecificationCodecException - A specification document could not be read or written.
 *
 * Raised by SpecificationCodec (2.4.0, forward 041) when a document is not a specification it can
 * read: malformed JSON, an envelope without `v`/`spec` or with an unsupported format version, a node
 * without `t`, a missing or unknown key, a parameter of the wrong type, a tree deeper than the depth
 * limit or with more nodes than the node limit (D6), a date that is not RFC 3339. The exception names
 * where it happened: `$path` is the position in the tree (`and[1].not`, `property.s.eq.v`), empty when
 * the document itself is the problem. A specification or a value that has no serialized form raises
 * the subclass NonSerializableSpecificationException.
 *
 * Decoding never lets another exception through: a constructor that refuses the parameters of a node
 * (TypeError, InvalidArgumentException) is wrapped in this one, the original kept as the previous.
 *
 * @version    2.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Serialization\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class SpecificationCodecException extends RuntimeException
{
    /**
     * @param string $reason What is wrong, without the position
     * @param string $path Position in the tree ('' = the document)
     * @param Throwable|null $previous Original failure (a constructor's refusal, a JSON error)
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $path = '',
        ?Throwable $previous = null
    ) {
        parent::__construct($path === '' ? $reason : sprintf('%s (at %s)', $reason, $path), 0, $previous);
    }
}
