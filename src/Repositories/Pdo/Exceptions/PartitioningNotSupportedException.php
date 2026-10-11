<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Exceptions;

use Antevemus\ASpecification\Contracts\Repositories\Exceptions\PartitionCreationException;
use Throwable;

/**
 * PartitioningNotSupportedException - The PDO Repository Does Not Partition
 *
 * makePartition() of PdoRepository is refused (forward 028, D9): a table is partitioned by the
 * database, not by an in-process DAG of repositories. It is a PartitionCreationException, the
 * exception the library already raises when a persistent repository cannot create a partition, so
 * code that handles that refusal handles this one too.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class PartitioningNotSupportedException extends PartitionCreationException
{
    /**
     * @param string $repositoryClass Class of the repository that refused
     * @param string $table Table the repository is mapped to
     * @param Throwable|null $previous Previous exception, if any
     */
    public function __construct(string $repositoryClass, string $table, ?Throwable $previous = null)
    {
        parent::__construct($repositoryClass, '', $previous);
        $this->message = sprintf(
            '%s does not create partitions: table "%s" is partitioned by the database, if at all. ' .
            'Query it with a specification instead, or build an in-memory partitioned repository explicitly.',
            $repositoryClass,
            $table
        );
    }
}
