<?php

namespace Antevemus\ASpecification\Entities;

/**
 * AbstractRandomLongEntity class.
 *
 * Classe abstrata que provê automaticamente um Integer aleatório 
 * com um limite inferior mais alto (simulando "Long" do Java).
 * 
 * ATENÇÃO: Desenhado puramente para testes de volume e stress. 
 * NÃO UTILIZE ESTA CLASSE EM AMBIENTES DE PRODUÇÃO!
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Entities
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractRandomLongEntity extends AbstractEntity
{
    protected readonly int $entityId;

    /**
     * Construtor que gera automaticamente um identificador inteiro longo (64-bit) aleatório.
     */
    public function __construct()
    {
        parent::__construct();
        // Garante que o número gerado será grande (acima de bilhões)
        $this->entityId = random_int(1000000000, PHP_INT_MAX);
    }

    /**
     * {@inheritdoc}
     *
     * @return int O número alto gerado da entidade
     */
    public final function getEntityId(): int
    {
        return $this->entityId;
    }
}
