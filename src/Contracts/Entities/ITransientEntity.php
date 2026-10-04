<?php

namespace Antevemus\ASpecification\Contracts\Entities;

/**
 * Interface ITransientEntity.
 *
 * Interface de marcação (Marker Interface) para todas as entidades transientes (Transient Entity Objects).
 * Entidades transientes são entidades por definição, mas efêmeras em sua natureza.
 * Exemplos típicos são "objetos de valor com timestamp" ou entidades computadas a partir de outras entidades.
 * 
 * Persistir entidades transientes deve, sempre que possível, ser evitado!
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Entities
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ITransientEntity extends IEntity
{
    // Interface de marcação puramente semântica
}
