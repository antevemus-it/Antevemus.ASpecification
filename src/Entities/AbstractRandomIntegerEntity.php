<?php

namespace Antevemus\ASpecification\Entities;

/**
 * AbstractRandomIntegerEntity class.
 *
 * Classe abstrata que provê automaticamente um Integer aleatório
 * para a entidade a partir do momento em que é instanciada.
 * 
 * ATENÇÃO: Esta classe é desenhada puramente para a construção simples e read-friendly 
 * em testes unitários. NÃO UTILIZE ESTA CLASSE EM AMBIENTES DE PRODUÇÃO!
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Entities
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractRandomIntegerEntity extends AbstractEntity
{
    protected readonly int $entityId;

    /**
     * Construtor que gera automaticamente um identificador inteiro aleatório.
     */
    public function __construct()
    {
        parent::__construct();
        $this->entityId = random_int(1, PHP_INT_MAX);
    }

    /**
     * {@inheritdoc}
     *
     * @return int O inteiro gerado da entidade
     */
    public final function getEntityId(): int
    {
        return $this->entityId;
    }
}
