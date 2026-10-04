<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories\Serialization;

use Antevemus\ASpecification\Contracts\Entities\IEntity;

/**
 * IEntitySerializer - Contrato agnóstico de serialização e desserialização de entidades
 *
 * Define a interface padronizada para conversão bidirecional entre instâncias
 * de IEntity e representações em string (JSON, binário serializado, etc.),
 * garantindo integridade de tipo, valores e identidade única.
 *
 * Funcionalidades:
 * - Serialização de entidade para string formatada
 * - Desserialização a partir de string para instância da classe de entidade alvo
 * - Fornecimento de metadados de formato (Content-Type e extensão de arquivo)
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories\Serialization
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IEntitySerializer
{
    /**
     * Serializa uma entidade para representação em string.
     *
     * @param IEntity $entity Entidade a ser serializada
     * @return string Representação serializada
     */
    public function serialize(IEntity $entity): string;

    /**
     * Desserializa uma string reconstruindo a instância da entidade.
     *
     * @template T of IEntity
     * @param string $data Dados serializados
     * @param class-string<T> $entityClass Nome da classe concreta da entidade
     * @return T
     */
    public function deserialize(string $data, string $entityClass): IEntity;

    /**
     * Retorna o tipo MIME/Content-Type da representação serializada.
     *
     * @return string
     */
    public function getContentType(): string;

    /**
     * Retorna a extensão padrão de arquivo recomendada (ex: json, bin).
     *
     * @return string
     */
    public function getFileExtension(): string;
}
