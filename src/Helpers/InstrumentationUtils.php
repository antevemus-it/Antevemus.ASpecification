<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Helpers;

use Antevemus\ASpecification\Contracts\Helpers\IInstrumentationUtils;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IPartitionRepository;
use Antevemus\ASpecification\Contracts\Repositories\IRepository;
use Antevemus\ASpecification\Repositories\PartitionRepository;

/**
 * InstrumentationUtils - Utilitarios de telemetria, diagnostico e inspecao de grafos
 *
 * Fornece metodos estaticos para inspecao de consumo de memoria, contagem e navegacao
 * em arvores de particionamento DAG e geracao de dumps estruturados de especificacoes.
 *
 * Funcionalidades:
 * - Formatacao de memoria consumida e pico em unidades SI (B, KB, MB)
 * - Inspecao visual e hierarquica de repositorios e suas subparticoes
 * - Contagem precisa de nos e profundidade do DAG de particionamento
 * - Dump hierarquico de arvores sintaticas de especificacoes (conjuncoes, disjuncoes, folhas)
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Helpers
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InstrumentationUtils implements IInstrumentationUtils
{
    /**
     * Construtor privado para impedir instanciacao de classe estatica utilitaria.
     */
    private function __construct()
    {
    }

    /**
     * {@inheritdoc}
     */
    public static function formatMemoryUsage(bool $realUsage = true): string
    {
        $bytes = memory_get_usage($realUsage);
        $peak = memory_get_peak_usage($realUsage);

        return sprintf(
            'Memory: %s (Peak: %s)',
            self::formatBytes($bytes),
            self::formatBytes($peak)
        );
    }

    /**
     * {@inheritdoc}
     */
    public static function inspectRepositoryHierarchy(IRepository $repository, int $indent = 0): string
    {
        $prefix = str_repeat('  ', $indent);
        $className = (new \ReflectionClass($repository))->getShortName();

        if ($repository instanceof IPartitionRepository) {
            $spec = $repository->getSpecification();
            $specClass = $spec !== null ? (new \ReflectionClass($spec))->getShortName() : 'all';
            $output = sprintf("%s* [Partition] %s (spec: %s)
", $prefix, $className, $specClass);

            foreach ($repository->getDirectPartitions() as $child) {
                if ($child instanceof IRepository) {
                    $output .= self::inspectRepositoryHierarchy($child, $indent + 1);
                }
            }

            return $output;
        }

        return sprintf("%s- [Repository] %s
", $prefix, $className);
    }

    /**
     * {@inheritdoc}
     */
    public static function countPartitionNodes(IPartitionRepository $partition): int
    {
        $count = 1; // O proprio no

        foreach ($partition->getDirectPartitions() as $child) {
            if ($child instanceof IPartitionRepository) {
                $count += self::countPartitionNodes($child);
            }
        }

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public static function dumpSpecificationTree(ISpecification $specification, int $indent = 0): string
    {
        $prefix = str_repeat('  ', $indent);
        $className = (new \ReflectionClass($specification))->getShortName();
        $output = sprintf("%s- %s
", $prefix, $className);

        if ($specification instanceof ICompositeSpecification) {
            try {
                $ref = new \ReflectionObject($specification);
                if ($ref->hasProperty('specifications')) {
                    $prop = $ref->getProperty('specifications');
                    $prop->setAccessible(true);
                    $children = $prop->getValue($specification);
                    if ($children instanceof \SplObjectStorage || is_iterable($children)) {
                        foreach ($children as $child) {
                            if ($child instanceof ISpecification) {
                                $output .= self::dumpSpecificationTree($child, $indent + 1);
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                // Fallback silencioso
            }
        }

        return $output;
    }

    /**
     * Formata um valor numerico em bytes para representacao humana.
     *
     * @param int $bytes Quantidade de bytes
     * @return string
     */
    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return sprintf('%.2f KB', $bytes / 1024.0);
        }

        return sprintf('%.2f MB', $bytes / 1048576.0);
    }
}
