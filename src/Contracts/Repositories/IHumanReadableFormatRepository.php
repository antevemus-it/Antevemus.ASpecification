<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;

/**
 * IHumanReadableFormatRepository - Marcador de repositório legível por humanos
 *
 * Interface marcadora de classificação para repositórios persistentes textuais
 * estruturados em formatos facilmente legíveis por seres humanos (ex.: JSON formatado, YAML).
 *
 * Funcionalidades:
 * - Classificação semântica de formato textual legível por humanos
 * - Extensão hierárquica de ITextualFormatRepository e IPersistentRepository
 * - Preservação do tipo durante promoção de repositório
 *
 * @template T of IEntity
 * @extends ITextualFormatRepository<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IHumanReadableFormatRepository extends ITextualFormatRepository
{
}
