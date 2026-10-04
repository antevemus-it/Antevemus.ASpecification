<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Helpers;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IPartitionRepository;
use Antevemus\ASpecification\Contracts\Repositories\IRepository;

/**
 * IInstrumentationUtils - Contrato para utilitarios de instrumentacao e diagnostico
 *
 * Provê recursos de telemetria, medicao de consumo de recursos e inspecao estrutural
 * de arvores de particionamento DAG e arvores sintaticas de especificacoes.
 *
 * Funcionalidades:
 * - Diagnostico de consumo e pico de memoria alocada
 * - Inspecao visual e representacao em arvore de repositorios particionados
 * - Contagem e navegacao de nos de particionamento
 * - Dump hierarquico formatado de arvores de especificacoes
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Helpers
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IInstrumentationUtils
{
    /**
     * Retorna a memoria alocada formatada em unidades legiveis (B, KB, MB).
     *
     * @param bool $realUsage Se true, retorna a memoria real alocada pelo sistema
     * @return string
     */
    public static function formatMemoryUsage(bool $realUsage = true): string;

    /**
     * Gera uma representacao textual em arvore da hierarquia de um repositorio particionado.
     *
     * @param IRepository $repository Repositorio a inspecionar
     * @param int $indent Nivel de indentacao inicial
     * @return string
     */
    public static function inspectRepositoryHierarchy(IRepository $repository, int $indent = 0): string;

    /**
     * Calcula o numero total de nos (particoes filhas e recursivas) no grafo da particao.
     *
     * @param IPartitionRepository $partition Particao raiz ou intermediaria
     * @return int
     */
    public static function countPartitionNodes(IPartitionRepository $partition): int;

    /**
     * Gera uma representacao textual em arvore da estrutura de uma especificacao.
     *
     * @param ISpecification $specification Especificacao a inspecionar
     * @param int $indent Nivel de indentacao inicial
     * @return string
     */
    public static function dumpSpecificationTree(ISpecification $specification, int $indent = 0): string;
}
