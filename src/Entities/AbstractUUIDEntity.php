<?php

namespace Antevemus\ASpecification\Entities;

use LogicException;

/**
 * AbstractUUIDEntity class.
 *
 * Classe abstrata que provê automaticamente uma identidade UUID versão 4
 * para a entidade a partir do momento em que é instanciada.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Entities
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractUUIDEntity extends AbstractEntity
{
    protected readonly string $entityId;

    /**
     * Construtor que inicializa a entidade e gera um UUID v4 canônico em string.
     */
    public function __construct()
    {
        parent::__construct();
        $this->entityId = $this->generateUuidV4();
    }

    /**
     * {@inheritdoc}
     *
     * @return string O UUID da entidade
     */
    public final function getEntityId(): string
    {
        return $this->entityId;
    }

    /**
     * Gera um UUID versão 4 compatível com a RFC 4122.
     * Implementação nativa limpa, evitando a obrigatoriedade de pacotes massivos como ramsey/uuid.
     *
     * @return string
     * @throws LogicException Se a geração pseudoaleatória falhar
     */
    private function generateUuidV4(): string
    {
        try {
            $data = random_bytes(16);
            
            // Define versão 4 (0100)
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
            // Define a variante RFC 4122 (10)
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
            
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        } catch (\Exception $e) {
            throw new LogicException("Falha catastrófica no gerador RNG nativo do PHP.", 0, $e);
        }
    }
}
