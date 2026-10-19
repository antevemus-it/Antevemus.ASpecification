<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts;

use Antevemus\ASpecification\Serialization\SpecificationCodec;

/**
 * ISerializableSpecification - A specification that can be written as data and read back (2.4.0).
 *
 * Opt-in contract of the specification serialization (forward 041, D2): the class gives its parameters
 * as an array and builds itself from them. It never chooses its own name in the document: the name
 * (`t`) comes from the registry of SpecificationCodec, so renaming a class does not break a stored
 * document, and a document never names a class. The leaves and the composites of the library implement
 * it (see SpecificationCodec for the names); a user leaf implements it and is registered with
 * `SpecificationCodec::register('myLeaf', MyLeaf::class)`.
 *
 * - toArray(): the parameters, in a fixed key order (the document is canonical), without `t`,
 *   `because` and `code` (the codec writes the name and the because()/withCode() annotations). A value
 *   may be a scalar, null, a DateTimeInterface, an enum case, a list of those, or a specification (a
 *   child, or a list of children): the codec writes it.
 * - fromArray(): builds the specification from the parameters as the codec read them: children are
 *   already specifications, dates DateTimeImmutable, enum cases the cases. The helpers of the codec
 *   (expectKeys(), specification(), string(), value(), ...) check the keys and the types and report the
 *   position in the tree; a constructor that refuses a parameter is reported by the codec too.
 *
 * Being serializable is not inherited by meaning: a subclass that redefines the predicate is another
 * specification, so the codec refuses an instance of a class that is not registered under its own name
 * even when its parent is.
 *
 * @template T
 * @extends ISpecification<T>
 * @version    2.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISerializableSpecification extends ISpecification
{
    /**
     * The parameters of the specification, in a fixed key order.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * Builds the specification from its parameters as read by the codec.
     *
     * @param array<string, mixed> $data Parameters (children decoded, dates and enum cases restored)
     * @param SpecificationCodec $codec The codec reading the document (helpers and position)
     * @return static
     * @throws \Antevemus\ASpecification\Serialization\Exceptions\SpecificationCodecException When the parameters are not valid
     */
    public static function fromArray(array $data, SpecificationCodec $codec): static;
}
