<?php

namespace Antevemus\ASpecification\Specifications\String;

use Antevemus\ASpecification\AbstractSpecification;
use DateTimeImmutable;

/**
 * DateStringSpecification class.
 *
 * Valida se uma string é uma data válida num formato específico usando PHP DateTimeImmutable.
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
class DateStringSpecification extends AbstractSpecification
{
    /**
     * Inicializa a especificação com um padrão de data.
     *
     * @param string $format O formato esperado (ex: Y-m-d).
     */
    public function __construct(private readonly string $format)
    {
    }

    /**
     * Verifica se a string repassada é uma data válida no formato.
     *
     * @param mixed $candidate A string a ser validada.
     * @return bool
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!is_string($candidate) || trim($candidate) === '') {
            return false;
        }
        
        $date = DateTimeImmutable::createFromFormat($this->format, $candidate);
        // O PHP retorna a data se fez parse com sucesso e nós comparamos se o output volta pra string original (validação estrita)
        return $date !== false && $date->format($this->format) === $candidate;
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return 'string';
    }
}
