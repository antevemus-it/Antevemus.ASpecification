<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql;

use Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause;

/**
 * SqlWhereClause - Objeto de Valor Imutável para Cláusula WHERE com Parâmetros
 *
 * Encapsula o fragmento SQL seguro e a coleção de parâmetros (bindings) gerados
 * pela visitação da árvore de especificações, pronto para consumo em PDO, Adianti ou DBAL.
 *
 * Funcionalidades:
 * - Estrutura imutável tipada (readonly)
 * - Composição booleana fluente (and, or) entre cláusulas geradas
 * - Verificação de cláusula nula/vazia
 * - Representação textual direta via Stringable
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Sql
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class SqlWhereClause implements ISqlWhereClause
{
    /**
     * @param string $sql Fragmento SQL da cláusula
     * @param array<string, mixed> $parameters Mapa associativo de parâmetros (:param => valor)
     */
    public function __construct(
        public string $sql = '',
        public array $parameters = []
    ) {
    }

    /**
     * Cria uma cláusula vazia.
     *
     * @return self
     */
    public static function empty(): self
    {
        return new self('', []);
    }

    /** {@inheritdoc} */
    public function toSql(): string
    {
        return $this->sql;
    }

    /** {@inheritdoc} */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /** {@inheritdoc} */
    public function isEmpty(): bool
    {
        return trim($this->sql) === '';
    }

    /**
     * Combina esta cláusula com outra através do operador lógico AND.
     *
     * @param self $other
     * @return self
     */
    public function and(self $other): self
    {
        if ($this->isEmpty()) {
            return $other;
        }
        if ($other->isEmpty()) {
            return $this;
        }

        $sql = "({$this->sql} AND {$other->sql})";
        $params = array_merge($this->parameters, $other->parameters);

        return new self($sql, $params);
    }

    /**
     * Combina esta cláusula com outra através do operador lógico OR.
     *
     * @param self $other
     * @return self
     */
    public function or(self $other): self
    {
        if ($this->isEmpty()) {
            return $other;
        }
        if ($other->isEmpty()) {
            return $this;
        }

        $sql = "({$this->sql} OR {$other->sql})";
        $params = array_merge($this->parameters, $other->parameters);

        return new self($sql, $params);
    }

    /** {@inheritdoc} */
    public function __toString(): string
    {
        return $this->sql;
    }
}
