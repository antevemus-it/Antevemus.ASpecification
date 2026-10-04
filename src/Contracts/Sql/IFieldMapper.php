<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Sql;

/**
 * IFieldMapper - Mapeador de Propriedades de Entidades para Colunas do Banco de Dados
 *
 * Resolve a impedância objeto-relacional mapeando nomes de propriedades do modelo de domínio
 * (ex: camelCase 'dataNascimento') para nomes reais de colunas físicas (ex: snake_case 'c.dt_nascimento').
 *
 * Funcionalidades:
 * - Mapeamento estático e dinâmico de atributos de domínio para colunas SQL
 * - Resolução de prefixos e aliases de tabela
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Sql
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IFieldMapper
{
    /**
     * Traduz o nome da propriedade da especificação para a expressão de coluna SQL correspondente.
     *
     * @param string $propertyName Nome da propriedade inspecionada
     * @return string Nome físico da coluna (ex: "status", "c.valor_aluguel")
     */
    public function mapField(string $propertyName): string;
}
