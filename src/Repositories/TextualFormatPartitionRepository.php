<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\ITextualFormatRepository;

/**
 * TextualFormatPartitionRepository - Repositório particionado com formato textual
 *
 * @template T of IEntity
 * @extends PersistentPartitionRepository<T>
 * @implements ITextualFormatRepository<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class TextualFormatPartitionRepository extends PersistentPartitionRepository implements ITextualFormatRepository
{
}
