<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;

/**
 * IBinaryFormatRepository - Marcador de repositório em formato binário
 *
 * Interface marcadora de classificação para repositórios persistentes que armazenam
 * entidades em formato binário (serialização nativa, protobuf, bson, etc).
 *
 * Funcionalidades:
 * - Classificação semântica de repositório com mídia binária
 * - Preservação do tipo durante promoção de repositório
 *
 * @template T of IEntity
 * @extends IPersistentRepository<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IBinaryFormatRepository extends IPersistentRepository
{
}
