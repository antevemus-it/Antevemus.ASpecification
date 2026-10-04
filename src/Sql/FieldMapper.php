<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql;

use Closure;
use Antevemus\ASpecification\Contracts\Sql\IFieldMapper;

/**
 * FieldMapper - Mapeador Canônico de Nomes de Propriedades para Colunas SQL
 *
 * Mapeia propriedades do modelo de domínio para nomes físicos de colunas no banco de dados,
 * suportando mapas associativos estáticos, prefixos de tabela padrão ou Closures dinâmicas.
 *
 * Funcionalidades:
 * - Mapeamento direto de dicionário (array associativo)
 * - Adição automática de alias/prefixo de tabela configurável (ex: 'c.')
 * - Conversão automática de camelCase para snake_case como fallback
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Sql
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class FieldMapper implements IFieldMapper
{
    /**
     * @param array<string, string>|Closure(string): string|null $mapping Mapa ou callback
     * @param string|null $tableAlias Alias padrão opcional (ex: 'c')
     */
    public function __construct(
        private readonly array|Closure|null $mapping = null,
        private readonly ?string $tableAlias = null
    ) {
    }

    /**
     * Resolve uma instância de IFieldMapper a partir de array, closure ou instância existente.
     *
     * @param IFieldMapper|array<string, string>|Closure(string): string|null $mapper
     * @param string|null $tableAlias
     * @return IFieldMapper
     */
    public static function from(IFieldMapper|array|Closure|null $mapper, ?string $tableAlias = null): IFieldMapper
    {
        if ($mapper instanceof IFieldMapper) {
            return $mapper;
        }
        return new self($mapper, $tableAlias);
    }

    /**
     * Alias conciso para mapField().
     *
     * @param string $propertyName
     * @return string
     */
    public function map(string $propertyName): string
    {
        return $this->mapField($propertyName);
    }

    /** {@inheritdoc} */
    public function mapField(string $propertyName): string
    {
        $target = $propertyName;

        if ($this->mapping instanceof Closure) {
            $closure = $this->mapping;
            $target = $closure($propertyName);
        } elseif (is_array($this->mapping) && isset($this->mapping[$propertyName])) {
            $target = $this->mapping[$propertyName];
        } else {
            // Fallback padrão: converte camelCase para snake_case
            $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $propertyName));
            $target = $snake;
        }

        // Se já tiver qualificador de tabela ou parênteses, não adiciona alias
        if (str_contains($target, '.') || str_contains($target, '(')) {
            return $target;
        }

        if ($this->tableAlias !== null && trim($this->tableAlias) !== '') {
            return rtrim($this->tableAlias, '.') . '.' . $target;
        }

        return $target;
    }
}
