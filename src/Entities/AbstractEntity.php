<?php

namespace Antevemus\ASpecification\Entities;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use DateTimeImmutable;
use LogicException;

/**
 * AbstractEntity class.
 *
 * Classe abstrata base para todos os objetos de entidade, garantindo que as lógicas
 * de comparação sejam consistentes e inalteráveis, ou seja, nunca influenciadas
 * pelo estado mutável dos dados (apenas pela identidade).
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Entities
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractEntity implements IEntity
{
    protected readonly DateTimeImmutable $timeOfCreation;

    /**
     * Versão de controle para possível uso em locking otimista em RDBMS.
     */
    protected ?int $version = null;

    /**
     * Inicializa a entidade definindo o timestamp imutável de criação.
     */
    public function __construct()
    {
        $this->timeOfCreation = new DateTimeImmutable();
    }

    /**
     * Obtém a versão de lock otimista da entidade.
     *
     * @return int|null
     */
    public function getVersion(): ?int
    {
        return $this->version;
    }

    /**
     * Define a versão de lock otimista.
     *
     * @param int|null $version
     */
    public function setVersion(?int $version): void
    {
        $this->version = $version;
    }

    /**
     * {@inheritdoc}
     */
    public function getTimeOfCreation(): DateTimeImmutable
    {
        return $this->timeOfCreation;
    }

    /**
     * {@inheritdoc}
     *
     * @return bool Sempre true para entidades de domínio
     */
    public final function isEntity(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @return bool Sempre false para entidades de domínio
     */
    public final function isValueObject(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function equals(IEntity $other): bool
    {
        if ($this === $other) {
            return true;
        }

        $thisId = $this->getEntityId();
        $otherId = $other->getEntityId();

        if ($thisId === null || $otherId === null) {
            throw new LogicException("A entidade não possui um ID válido para ser comparada.");
        }

        // Caso as identidades sejam Value Objects com suas próprias regras
        if (is_object($thisId) && method_exists($thisId, 'equals')) {
            return $thisId->equals($otherId);
        }

        return $thisId === $otherId;
    }
}
