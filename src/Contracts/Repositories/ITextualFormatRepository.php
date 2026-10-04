<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;

/**
 * ITextualFormatRepository - Marcador de repositório em formato textual
 *
 * Interface marcadora de classificação para repositórios persistentes que armazenam
 * entidades em formato textual (JSON, XML, CSV, YAML, etc).
 *
 * Funcionalidades:
 * - Classificação semântica de repositório com mídia textual
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
interface ITextualFormatRepository extends IPersistentRepository
{
}
