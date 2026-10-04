<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

/**
 * RelationalOperator - Enumeracao de operadores relacionais de comparacao
 *
 * Representa os operadores de relacao binaria (igualdade, desigualdade, magnitude)
 * utilizados em especificacoes relacionais, com suporte a inversao logica e conversao SQL.
 *
 * Funcionalidades:
 * - Mapeamento de operadores binarios relacionais padrao e estendidos
 * - Inversao logica estrita da relacao binaria (algebra relacional)
 * - Obtencao do simbolo textual canonico compativel com SQL
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum RelationalOperator: string
{
    case EQUAL = '=';
    case NOT_EQUAL = '<>';
    case LESS_THAN = '<';
    case LESS_THAN_OR_EQUAL = '<=';
    case GREATER_THAN = '>';
    case GREATER_THAN_OR_EQUAL = '>=';
    case MUCH_LESS_THAN = '<<';
    case MUCH_GREATER_THAN = '>>';

    /**
     * Retorna a relacao binaria invertida (negacao logica da operacao).
     *
     * Exemplo:
     * - EQUAL ('=') => NOT_EQUAL ('<>')
     * - LESS_THAN ('<') => GREATER_THAN_OR_EQUAL ('>=')
     *
     * @return self
     */
    public function getInvertedBinaryRelation(): self
    {
        return match ($this) {
            self::EQUAL => self::NOT_EQUAL,
            self::NOT_EQUAL => self::EQUAL,
            self::LESS_THAN => self::GREATER_THAN_OR_EQUAL,
            self::LESS_THAN_OR_EQUAL => self::GREATER_THAN,
            self::GREATER_THAN => self::LESS_THAN_OR_EQUAL,
            self::GREATER_THAN_OR_EQUAL => self::LESS_THAN,
            self::MUCH_LESS_THAN => self::MUCH_GREATER_THAN,
            self::MUCH_GREATER_THAN => self::MUCH_LESS_THAN,
        };
    }

    /**
     * Retorna a representacao simbolica compativel com padroes SQL.
     *
     * @return string
     */
    public function getSqlSymbol(): string
    {
        return $this->value;
    }
}
