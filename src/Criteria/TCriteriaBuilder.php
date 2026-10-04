<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Criteria;

use Adianti\Database\TCriteria;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Sql\IFieldMapper;
use Antevemus\ASpecification\Criteria\Exceptions\CriteriaBuilderException;

/**
 * TCriteriaBuilder - Construtor e Compilador de TCriteria a partir de Especificações
 *
 * Provê uma API fluente e atalhos estáticos para compilar árvores de especificações de domínio
 * em objetos TCriteria do Adianti Framework, com suporte a paginação, ordenação e mapeamento de colunas.
 *
 * Funcionalidades:
 * - Conversão estática direta via fromSpecification() e create()
 * - Definição fluente de paginação (limit, offset)
 * - Definição fluente de ordenação (orderBy, direction)
 * - Definição fluente de agrupamento (groupBy)
 * - Suporte a mapeamento objeto-relacional de propriedades
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Criteria
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class TCriteriaBuilder
{
    private readonly CriteriaSpecificationVisitor $visitor;
    private array $properties = [];

    /**
     * @param ISpecification $specification Especificação de domínio a ser compilada
     * @param IFieldMapper|array<string, string>|callable(string): string|null $fieldMapper Mapeador opcional
     */
    public function __construct(
        private readonly ISpecification $specification,
        IFieldMapper|array|callable|null $fieldMapper = null
    ) {
        $this->visitor = new CriteriaSpecificationVisitor($fieldMapper);
    }

    /**
     * Fábrica estática direta para compilar uma especificação em TCriteria.
     *
     * @param ISpecification $specification
     * @param IFieldMapper|array<string, string>|callable(string): string|null $fieldMapper
     * @param array<string, mixed> $properties Propriedades como 'order', 'limit', 'offset', 'direction', 'group'
     * @return TCriteria
     */
    public static function fromSpecification(
        ISpecification $specification,
        IFieldMapper|array|callable|null $fieldMapper = null,
        array $properties = []
    ): TCriteria {
        $builder = new self($specification, $fieldMapper);
        foreach ($properties as $property => $value) {
            $builder->setProperty((string)$property, $value);
        }
        return $builder->build();
    }

    /**
     * Alias estático para fromSpecification().
     *
     * @param ISpecification $specification
     * @param IFieldMapper|array<string, string>|callable(string): string|null $fieldMapper
     * @param array<string, mixed> $properties
     * @return TCriteria
     */
    public static function create(
        ISpecification $specification,
        IFieldMapper|array|callable|null $fieldMapper = null,
        array $properties = []
    ): TCriteria {
        return self::fromSpecification($specification, $fieldMapper, $properties);
    }

    /**
     * Define uma propriedade no TCriteria resultante.
     *
     * @param string $property Nome da propriedade ('order', 'limit', 'offset', 'direction', 'group')
     * @param mixed $value
     * @return self
     */
    public function setProperty(string $property, mixed $value): self
    {
        $this->properties[$property] = $value;
        return $this;
    }

    /**
     * Define a ordenação do critério de consulta.
     *
     * @param string $column Coluna de ordenação
     * @param string $direction Direção ('asc' ou 'desc')
     * @return self
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->properties['order'] = $column;
        $this->properties['direction'] = strtolower($direction);
        return $this;
    }

    /**
     * Define limites de paginação.
     *
     * @param int $limit Quantidade máxima de registros
     * @param int $offset Deslocamento inicial
     * @return self
     */
    public function limit(int $limit, int $offset = 0): self
    {
        $this->properties['limit'] = $limit;
        $this->properties['offset'] = $offset;
        return $this;
    }

    /**
     * Define agrupamento (GROUP BY).
     *
     * @param string $column Coluna de agrupamento
     * @return self
     */
    public function groupBy(string $column): self
    {
        $this->properties['group'] = $column;
        return $this;
    }

    /**
     * Compila e retorna a instância de TCriteria configurada.
     *
     * @return TCriteria
     */
    public function build(): TCriteria
    {
        $criteria = $this->visitor->buildCriteria($this->specification);

        foreach ($this->properties as $property => $value) {
            if ($value !== null && $value !== '') {
                $criteria->setProperty($property, $value);
            }
        }

        return $criteria;
    }
}
