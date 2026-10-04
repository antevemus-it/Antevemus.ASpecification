<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Sql;

use Stringable;

/**
 * ISqlWhereClause - Contrato para Cláusula WHERE Parametrizada Agnóstica de Framework
 *
 * Encapsula o fragmento SQL seguro e os parâmetros associados (bindings) gerados
 * pela visitação da árvore de especificações.
 *
 * Funcionalidades:
 * - Acesso ao SQL textual da cláusula WHERE
 * - Acesso aos parâmetros nomeados associados (:p1 => valor)
 * - Verificação de cláusula vazia
 * - Implementação de Stringable para interpolação limpa
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Sql
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISqlWhereClause extends Stringable
{
    /**
     * Retorna a expressão SQL da cláusula WHERE (sem a palavra-chave WHERE).
     *
     * @return string
     */
    public function toSql(): string;

    /**
     * Retorna o mapa associativo de parâmetros nomeados e seus valores (:param => valor).
     *
     * @return array<string, mixed>
     */
    public function getParameters(): array;

    /**
     * Informa se a cláusula gerada é vazia (sem restrições).
     *
     * @return bool
     */
    public function isEmpty(): bool;
}
