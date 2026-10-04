<?php

namespace Antevemus\ASpecification\Specifications\String;

use Antevemus\ASpecification\AbstractSpecification;

/**
 * WildcardExpressionMatcherIgnoreCaseStringSpecification class.
 *
 * Valida se o candidato atende a um padrão de curinga simples (ex: *.txt) ignorando caixa (Case Insensitive).
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
class WildcardExpressionMatcherIgnoreCaseStringSpecification extends AbstractSpecification
{
    /**
     * Inicializa a especificação com um padrão.
     *
     * @param string $pattern O padrão esperado (ex: *.txt).
     */
    public function __construct(private readonly string $pattern)
    {
    }

    /**
     * Retorna o padrão de curinga configurado.
     *
     * @return string
     */
    public function getPattern(): string
    {
        return $this->pattern;
    }

    /**
     * Verifica se a string atende ao padrão de curinga (case insensitive).
     *
     * @param mixed $candidate A string a ser validada.
     * @return bool
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!is_string($candidate)) {
            return false;
        }
        
        return fnmatch(strtolower($this->pattern), strtolower($candidate));
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return 'string';
    }
}
