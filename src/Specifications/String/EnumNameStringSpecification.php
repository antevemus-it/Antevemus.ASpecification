<?php

namespace Antevemus\ASpecification\Specifications\String;

use Antevemus\ASpecification\AbstractSpecification;
use InvalidArgumentException;
use ReflectionEnum;

/**
 * EnumNameStringSpecification class.
 *
 * Valida se a string fornecida corresponde ao nome de um caso em um Enum (UnitEnum/BackedEnum) do PHP 8+.
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\String
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class EnumNameStringSpecification extends AbstractSpecification
{
    /**
     * Inicializa a especificação.
     *
     * @param class-string $enumClass A classe do enum nativo do PHP.
     * @throws InvalidArgumentException
     */
    public function __construct(private readonly string $enumClass)
    {
        if (!enum_exists($enumClass)) {
            throw new InvalidArgumentException("The provided class is not a valid PHP Enum.");
        }
    }

    /**
     * Verifica se o candidato é um dos cases válidos do enum.
     *
     * @param mixed $candidate A string a ser validada.
     * @return bool
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!is_string($candidate)) {
            return false;
        }
        
        return defined($this->enumClass . '::' . $candidate);
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return 'string';
    }
}
