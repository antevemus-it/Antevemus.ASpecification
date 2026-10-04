<?php

namespace Antevemus\ASpecification\Specifications\Logical;

use Antevemus\ASpecification\AbstractSpecification;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * DefaultValueSpecification class.
 *
 * Implementação folha que valida se o candidato equivale ao 'default' do seu tipo
 * (null, false, 0, string vazia/em branco).
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Logical
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DefaultValueSpecification extends AbstractSpecification
{
    /**
     * Verifica se o candidato é considerado um valor default (vazio).
     *
     * @param mixed $candidate O valor ou objeto a ser validado.
     * @return bool
     * @throws InvalidArgumentException
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if ($candidate === null) {
            return true;
        }
        
        if (is_string($candidate)) {
            return trim($candidate) === '';
        }
        
        if (is_numeric($candidate)) {
            return $candidate == 0;
        }
        
        if (is_bool($candidate)) {
            return $candidate === false;
        }
        
        if ($candidate instanceof DateTimeInterface) {
            throw new InvalidArgumentException("Cannot check for default value of a DateTime object as it is a timestamp");
        }
        
        return empty($candidate);
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return 'mixed';
    }
}
